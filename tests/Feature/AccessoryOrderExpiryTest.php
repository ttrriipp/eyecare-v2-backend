<?php

use App\Actions\AccessoryOrderRequests\AcceptAccessoryOrderRequest;
use App\Enums\BillingRecordStatus;
use App\Enums\CommercialItemKind;
use App\Enums\JobOrderStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\AccessoryOrderRequestItem;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    Notification::fake();

    $this->account = User::factory()->patient()->create();
    $this->reviewer = User::factory()->admin()->create();
    $this->variant = ProductVariant::factory()->create([
        'product_id' => Product::factory()->accessory(),
        'is_active' => true,
        'price' => 100,
        'stock_quantity' => 2,
    ]);
    InventoryLot::factory()->create([
        'product_variant_id' => $this->variant->id,
        'received_by' => $this->account->id,
        'quantity_on_hand' => 2,
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

test('the scheduled command expires only pending-payment orders and reverses their unpaid commerce', function (): void {
    $result = app(AcceptAccessoryOrderRequest::class)->handle(
        orderRequest: $this->request,
        reviewer: $this->reviewer,
    );

    $order = $result['order']->fresh();
    $order->update(['payment_expires_at' => now()->subMinute()]);

    $this->artisan('accessory-orders:expire-unpaid')->assertSuccessful();

    $order = $order->fresh(['billingRecord']);

    expect($order->status)->toBe(JobOrderStatus::Cancelled)
        ->and($order->billingRecord->status)->toBe(BillingRecordStatus::Cancelled)
        ->and((int) $this->variant->fresh()->stock_quantity)->toBe(2)
        ->and((int) $this->variant->inventoryLots()->sum('quantity_on_hand'))->toBe(2);
});

test('the scheduled command never expires an order that already has a payment proof under review', function (): void {
    $result = app(AcceptAccessoryOrderRequest::class)->handle(
        orderRequest: $this->request,
        reviewer: $this->reviewer,
    );

    $order = $result['order']->fresh();
    $order->update([
        'status' => JobOrderStatus::PaymentReview,
        'payment_expires_at' => now()->subMinute(),
    ]);

    $this->artisan('accessory-orders:expire-unpaid')->assertSuccessful();

    expect($order->fresh()->status)->toBe(JobOrderStatus::PaymentReview);
});
