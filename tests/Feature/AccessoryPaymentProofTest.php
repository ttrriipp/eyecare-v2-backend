<?php

use App\Actions\AccessoryOrderRequests\AcceptPaymentProof;
use App\Actions\AccessoryOrderRequests\SubmitPaymentProof;
use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Enums\OrderPaymentProofStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\AuditLog;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\OrderPaymentProof;
use App\Models\User;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('payment proof uses the configured private disk and is idempotent', function (): void {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    Storage::fake('payment_proofs');
    config(['filesystems.payment_proof_disk' => 'payment_proofs']);

    $account = User::factory()->patient()->create();
    $order = JobOrder::factory()->create([
        'patient_id' => $account->patient->id,
        'status' => JobOrderStatus::PendingPayment,
        'payment_expires_at' => now()->addMinutes(30),
    ]);
    AccessoryOrderRequest::factory()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'job_order_id' => $order->id,
        'status' => AccessoryOrderRequestStatus::Accepted,
    ]);

    $action = app(SubmitPaymentProof::class);
    $first = $action->handle(
        account: $account,
        order: $order,
        file: UploadedFile::fake()->image('receipt.png', 100, 100),
        senderName: 'Patient',
        referenceNumber: 'GCASH-123',
    );

    expect($first['created'])->toBeTrue()
        ->and($first['proof']->status->value)->toBe('pending');

    Storage::disk('payment_proofs')->assertExists($first['proof']->file_path);
    expect($order->fresh()->status)->toBe(JobOrderStatus::PaymentReview);

    $second = $action->handle(
        account: $account,
        order: $order,
        file: UploadedFile::fake()->image('replacement.png', 100, 100),
        senderName: 'Different sender',
        referenceNumber: 'GCASH-456',
    );

    expect($second['created'])->toBeFalse()
        ->and($second['proof']->id)->toBe($first['proof']->id);
});

test('expired order cannot receive a first payment proof', function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('payment_proofs');
    config(['filesystems.payment_proof_disk' => 'payment_proofs']);

    $account = User::factory()->patient()->create();
    $order = JobOrder::factory()->create([
        'patient_id' => $account->patient->id,
        'status' => JobOrderStatus::PendingPayment,
        'payment_expires_at' => now()->subMinute(),
    ]);
    AccessoryOrderRequest::factory()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'job_order_id' => $order->id,
        'status' => AccessoryOrderRequestStatus::Accepted,
    ]);

    expect(fn () => app(SubmitPaymentProof::class)->handle(
        account: $account,
        order: $order,
        file: UploadedFile::fake()->image('receipt.png', 100, 100),
        senderName: 'Patient',
        referenceNumber: 'GCASH-123',
    ))->toThrow(ValidationException::class);
});

test('staff acceptance records one full payment and redacts payment identifiers from audits', function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    Notification::fake();

    $account = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();
    $order = JobOrder::factory()->create([
        'patient_id' => $account->patient->id,
        'status' => JobOrderStatus::PaymentReview,
        'total_amount' => 100,
    ]);
    AccessoryOrderRequest::factory()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'job_order_id' => $order->id,
        'status' => AccessoryOrderRequestStatus::Accepted,
    ]);
    $billing = BillingRecord::factory()->create([
        'patient_id' => $account->patient->id,
        'job_order_id' => $order->id,
        'status' => BillingRecordStatus::Unpaid,
        'subtotal_amount' => 100,
        'total_amount' => 100,
        'balance_due' => 100,
    ]);
    $proof = OrderPaymentProof::factory()->create([
        'job_order_id' => $order->id,
        'user_id' => $account->id,
        'status' => OrderPaymentProofStatus::Pending,
        'reference_number' => 'GCASH-SECURE-123',
        'sender_name' => 'Private Sender',
    ]);

    app(AcceptPaymentProof::class)->handle(
        proof: $proof,
        reviewer: $reviewer,
    );

    expect($order->fresh()->status)->toBe(JobOrderStatus::Queued)
        ->and($proof->fresh()->status)->toBe(OrderPaymentProofStatus::Accepted)
        ->and($billing->fresh()->status)->toBe(BillingRecordStatus::Paid)
        ->and($billing->payments()->where('status', 'posted')->count())->toBe(1);

    $auditData = AuditLog::query()
        ->where('subject_type', (new OrderPaymentProof)->getMorphClass())
        ->latest('id')
        ->value('metadata');

    expect(json_encode($auditData))->not->toContain('GCASH-SECURE-123')
        ->and(json_encode($auditData))->not->toContain('Private Sender');
});
