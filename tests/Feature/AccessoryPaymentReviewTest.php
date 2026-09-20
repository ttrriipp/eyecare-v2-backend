<?php

use App\Actions\AccessoryOrderRequests\AcceptAccessoryOrderRequest;
use App\Actions\AccessoryOrderRequests\RejectPaymentProof;
use App\Actions\AccessoryOrderRequests\SubmitPaymentProof;
use App\Enums\BillingRecordStatus;
use App\Enums\CommercialItemKind;
use App\Enums\JobOrderStatus;
use App\Enums\OrderPaymentProofStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\AccessoryOrderRequestItem;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('rejecting payment proof cancels the order, voids the bill, and restores committed lots', function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    Storage::fake('payment_proofs');
    config(['filesystems.payment_proof_disk' => 'payment_proofs']);

    $account = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();
    $variant = ProductVariant::factory()->create([
        'product_id' => Product::factory()->accessory(),
        'is_active' => true,
        'price' => 100,
        'stock_quantity' => 2,
    ]);
    InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'received_by' => $account->id,
        'quantity_on_hand' => 2,
        'expires_on' => now()->addMonths(6)->toDateString(),
    ]);
    $request = AccessoryOrderRequest::factory()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'subtotal_amount' => 100,
    ]);
    AccessoryOrderRequestItem::factory()->create([
        'accessory_order_request_id' => $request->id,
        'product_variant_id' => $variant->id,
        'description' => 'Care Kit',
        'quantity' => 1,
        'unit_price' => 100,
        'amount' => 100,
        'item_kind' => CommercialItemKind::Accessory,
    ]);

    $order = app(AcceptAccessoryOrderRequest::class)->handle(
        orderRequest: $request,
        reviewer: $reviewer,
    )['order'];
    $proof = app(SubmitPaymentProof::class)->handle(
        account: $account,
        order: $order,
        file: UploadedFile::fake()->image('receipt.png', 100, 100),
        senderName: 'Patient',
        referenceNumber: 'GCASH-REJECT-1',
    )['proof'];

    $rejected = app(RejectPaymentProof::class)->handle(
        proof: $proof,
        reviewer: $reviewer,
        reason: 'The transfer could not be matched in the clinic ledger.',
    );

    $order = $order->fresh(['billingRecord']);
    $cancellationNotification = $account->fresh()->unreadNotifications
        ->firstWhere('data.kind', 'optical_order_cancelled');

    expect($rejected->status)->toBe(OrderPaymentProofStatus::Rejected)
        ->and($order->status)->toBe(JobOrderStatus::Cancelled)
        ->and($order->billingRecord->status)->toBe(BillingRecordStatus::Voided)
        ->and((int) $variant->fresh()->stock_quantity)->toBe(2)
        ->and((int) $variant->inventoryLots()->sum('quantity_on_hand'))->toBe(2)
        ->and($cancellationNotification?->data['body'])
        ->not->toContain('could not be matched');
});
