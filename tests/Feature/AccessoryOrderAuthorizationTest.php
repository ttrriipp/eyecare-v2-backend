<?php

use App\Actions\AccessoryOrderRequests\AcceptAccessoryOrderRequest;
use App\Actions\AccessoryOrderRequests\RejectAccessoryOrderRequest;
use App\Enums\CommercialItemKind;
use App\Enums\JobOrderStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\AccessoryOrderRequestItem;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SmsNotification;
use App\Models\User;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    $this->account = User::factory()->patient()->create();
    $this->variant = ProductVariant::factory()->create([
        'product_id' => Product::factory()->accessory(),
        'is_active' => true,
        'price' => 100,
        'stock_quantity' => 10,
    ]);
    InventoryLot::factory()->create([
        'product_variant_id' => $this->variant->id,
        'received_by' => $this->account->id,
        'quantity_on_hand' => 10,
        'expires_on' => now()->addMonths(6)->toDateString(),
    ]);
    $this->request = AccessoryOrderRequest::factory()->create([
        'user_id' => $this->account->id,
        'patient_id' => $this->account->patient->id,
        'subtotal_amount' => 100,
    ]);
    AccessoryOrderRequestItem::factory()->create([
        'accessory_order_request_id' => $this->request->id,
        'product_variant_id' => $this->variant->id,
        'description' => 'Care Kit',
        'quantity' => 1,
        'unit_price' => 100,
        'amount' => 100,
        'item_kind' => CommercialItemKind::Accessory,
    ]);
});

test('optometrist-only accounts cannot accept or reject accessory requests', function (): void {
    $reviewer = User::factory()->optometrist()->create();

    expect(fn () => app(AcceptAccessoryOrderRequest::class)->handle(
        orderRequest: $this->request,
        reviewer: $reviewer,
    ))->toThrow(ValidationException::class);

    expect(fn () => app(RejectAccessoryOrderRequest::class)->handle(
        orderRequest: $this->request,
        reviewer: $reviewer,
        reason: 'Not available',
    ))->toThrow(ValidationException::class);

    expect($this->request->fresh()->status->value)->toBe('pending');
});

test('staff cannot apply a positive discount during acceptance', function (): void {
    $reviewer = User::factory()->staff()->create();

    expect(fn () => app(AcceptAccessoryOrderRequest::class)->handle(
        orderRequest: $this->request,
        reviewer: $reviewer,
        discountAmount: 10,
    ))->toThrow(ValidationException::class);

    expect($this->request->fresh()->status->value)->toBe('pending');
});

test('an administrator accepts the complete request into a pending-payment order', function (): void {
    $reviewer = User::factory()->admin()->create();

    $result = app(AcceptAccessoryOrderRequest::class)->handle(
        orderRequest: $this->request,
        reviewer: $reviewer,
    );

    $order = $result['order']->fresh(['billingRecord']);
    $request = $this->request->fresh();
    $acceptanceSms = SmsNotification::query()
        ->where('job_order_id', $order->id)
        ->where('event', 'accessory_order_request_accepted')
        ->sole();

    expect($request->status->value)->toBe('accepted')
        ->and($request->job_order_id)->toBe($order->id)
        ->and($order->status)->toBe(JobOrderStatus::PendingPayment)
        ->and($order->payment_expires_at)->not->toBeNull()
        ->and($order->payment_expires_at->greaterThanOrEqualTo(now()->addMinutes(29)))->toBeTrue()
        ->and($order->payment_expires_at->lessThanOrEqualTo(now()->addMinutes(31)))->toBeTrue()
        ->and((float) $order->billingRecord->total_amount)->toBe(100.0)
        ->and((int) $this->variant->fresh()->stock_quantity)->toBe(9)
        ->and((int) $this->variant->inventoryLots()->sum('quantity_on_hand'))->toBe(9)
        ->and($acceptanceSms->message)->toContain($order->job_order_number);
});

test('staff rejection preserves the patient reason but redacts it from the notification', function (): void {
    $reviewer = User::factory()->staff()->create();

    app(RejectAccessoryOrderRequest::class)->handle(
        orderRequest: $this->request,
        reviewer: $reviewer,
        reason: 'The requested item is temporarily unavailable.',
    );

    $request = $this->request->fresh();
    $notification = $this->account->fresh()->unreadNotifications->sole();
    $rejectionSms = SmsNotification::query()
        ->where('event', 'accessory_order_request_declined')
        ->sole();

    expect($request->status->value)->toBe('rejected')
        ->and($request->rejection_reason)->toBe('The requested item is temporarily unavailable.')
        ->and($notification->data['body'])->not->toContain('temporarily unavailable')
        ->and($notification->data['kind'])->toBe('accessory_order_request_declined')
        ->and($rejectionSms->message)->toContain($request->request_number)
        ->not->toContain('temporarily unavailable');
});
