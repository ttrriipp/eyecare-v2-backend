<?php

use App\Actions\AccessoryOrderRequests\AcceptPaymentProof;
use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Enums\OrderPaymentMethod;
use App\Filament\Resources\ClinicPaymentMethods\ClinicPaymentMethodResource;
use App\Models\AccessoryOrderRequest;
use App\Models\BillingRecord;
use App\Models\ClinicPaymentMethod;
use App\Models\JobOrder;
use App\Models\User;
use App\Services\Payments\PaymentInstructionCatalog;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('pending orders expose configured methods and a private QR URL', function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('payment_instructions');
    config(['filesystems.payment_instructions_disk' => 'payment_instructions']);

    Storage::disk('payment_instructions')->put('payment-methods/gcash.png', 'qr');

    ClinicPaymentMethod::factory()->create([
        'method' => OrderPaymentMethod::GCash,
        'label' => 'GCash',
        'qr_image_path' => 'payment-methods/gcash.png',
    ]);
    ClinicPaymentMethod::factory()->bankTransfer()->create();

    $account = User::factory()->patient()->create();
    $order = JobOrder::factory()->create([
        'patient_id' => $account->patient->id,
        'status' => JobOrderStatus::PendingPayment,
        'payment_expires_at' => now()->addMinutes(30),
        'payment_instructions' => app(PaymentInstructionCatalog::class)->snapshot(
            orderReference: 'ORD-TEST-1',
            amount: 1500,
            expiresAt: now()->addMinutes(30),
        ),
    ]);

    $response = $this->actingAs($account)
        ->getJson("/api/v1/optical-orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.payment_instructions.method', 'gcash')
        ->assertJsonPath('data.payment_instructions.available_methods.1.method', 'bank_transfer')
        ->assertJsonPath('data.payment_instructions.available_methods.1.qr_image_url', null);

    $qrUrl = $response->json('data.payment_instructions.qr_image_url');

    expect($qrUrl)->toBeString()->not->toBe('');

    $this->actingAs($account)
        ->get($qrUrl)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('a bank transfer proof records the selected online method', function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    Notification::fake();
    Storage::fake('payment_proofs');
    config(['filesystems.payment_proof_disk' => 'payment_proofs']);

    $account = User::factory()->patient()->create();
    $order = JobOrder::factory()->create([
        'patient_id' => $account->patient->id,
        'status' => JobOrderStatus::PendingPayment,
        'payment_expires_at' => now()->addMinutes(30),
        'payment_instructions' => [
            'methods' => [[
                'method' => OrderPaymentMethod::BankTransfer->value,
                'label' => OrderPaymentMethod::BankTransfer->label(),
                'clinic_account_name' => 'EyeCare Clinic',
                'clinic_account_number' => '1234567890',
                'bank_name' => 'Demo Bank',
                'amount' => '100.00',
                'order_reference' => 'ORD-BANK-1',
                'payment_expires_at' => now()->addMinutes(30)->toIso8601String(),
            ]],
        ],
    ]);
    BillingRecord::factory()->create([
        'patient_id' => $account->patient->id,
        'job_order_id' => $order->id,
        'status' => BillingRecordStatus::Unpaid,
        'subtotal_amount' => 100,
        'total_amount' => 100,
        'balance_due' => 100,
    ]);
    AccessoryOrderRequest::factory()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'job_order_id' => $order->id,
        'status' => AccessoryOrderRequestStatus::Accepted,
    ]);

    $this->actingAs($account)
        ->post("/api/v1/optical-orders/{$order->id}/payment-proof", [
            'proof' => UploadedFile::fake()->image('bank-proof.png', 100, 100),
            'sender_name' => 'Patient',
            'reference_number' => 'BANK-VALID-1',
            'payment_method' => OrderPaymentMethod::BankTransfer->value,
        ])
        ->assertCreated()
        ->assertJsonPath('data.payment_method', OrderPaymentMethod::BankTransfer->value);

    $reviewer = User::factory()->staff()->create();
    app(AcceptPaymentProof::class)->handle(
        proof: $order->fresh()->paymentProof,
        reviewer: $reviewer,
    );

    expect($order->fresh()->billingRecord->payments()->value('payment_method'))
        ->toBe(OrderPaymentMethod::BankTransfer->value);
});

test('a proof cannot select a method absent from the order snapshot', function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('payment_proofs');
    config(['filesystems.payment_proof_disk' => 'payment_proofs']);

    $account = User::factory()->patient()->create();
    $order = JobOrder::factory()->create([
        'patient_id' => $account->patient->id,
        'status' => JobOrderStatus::PendingPayment,
        'payment_expires_at' => now()->addMinutes(30),
        'payment_instructions' => [
            'methods' => [[
                'method' => OrderPaymentMethod::GCash->value,
                'label' => OrderPaymentMethod::GCash->label(),
                'clinic_account_name' => 'EyeCare Clinic',
                'clinic_account_number' => '09171234567',
                'bank_name' => null,
                'amount' => '100.00',
                'order_reference' => 'ORD-GCASH-1',
                'payment_expires_at' => now()->addMinutes(30)->toIso8601String(),
            ]],
        ],
    ]);
    AccessoryOrderRequest::factory()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'job_order_id' => $order->id,
        'status' => AccessoryOrderRequestStatus::Accepted,
    ]);

    $this->actingAs($account)
        ->post("/api/v1/optical-orders/{$order->id}/payment-proof", [
            'proof' => UploadedFile::fake()->image('bank-proof.png', 100, 100),
            'sender_name' => 'Patient',
            'reference_number' => 'BANK-UNAVAILABLE-1',
            'payment_method' => OrderPaymentMethod::BankTransfer->value,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PAYMENT_METHOD_NOT_AVAILABLE');
});

test('clinic payment method settings are admin-only', function (): void {
    $this->seed(RoleSeeder::class);

    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    expect(ClinicPaymentMethodResource::canViewAny())->toBeFalse();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    expect(ClinicPaymentMethodResource::canViewAny())->toBeTrue();
});

test('deactivated database methods do not re-enable legacy environment fallback', function (): void {
    $this->seed(RoleSeeder::class);
    config([
        'payments.gcash_account_name' => 'Legacy Clinic',
        'payments.gcash_account_number' => '09170000000',
    ]);
    ClinicPaymentMethod::factory()->create([
        'method' => OrderPaymentMethod::GCash,
        'is_active' => false,
    ]);

    expect(app(PaymentInstructionCatalog::class)->activeMethods())->toBe([]);
});
