<?php

use App\Models\AccessoryOrderRequest;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->account = User::factory()->patient()->create();
    $this->variant = ProductVariant::factory()->create([
        'product_id' => Product::factory()->accessory(),
        'is_active' => true,
        'price' => 100,
    ]);
    InventoryLot::factory()->create([
        'product_variant_id' => $this->variant->id,
        'received_by' => $this->account->id,
        'quantity_on_hand' => 10,
        'expires_on' => now()->addMonths(6)->toDateString(),
    ]);
});

test('request submission rejects duplicate variants', function (): void {
    $response = $this->actingAs($this->account)
        ->postJson('/api/v1/accessory-order-requests', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'quantity' => 1],
                ['product_variant_id' => $this->variant->id, 'quantity' => 1],
            ],
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('items');

    expect(AccessoryOrderRequest::query()->where('user_id', $this->account->id)->count())->toBe(0);
});

test('request submission returns a stable conflict when a selected variant is not an accessory', function (): void {
    $frameVariant = ProductVariant::factory()->create([
        'product_id' => Product::factory()->create(['product_type' => 'frame'])->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->account)
        ->postJson('/api/v1/accessory-order-requests', [
            'items' => [
                ['product_variant_id' => $frameVariant->id, 'quantity' => 1],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'ACCESSORY_NOT_ORDERABLE');

    expect(AccessoryOrderRequest::query()->where('user_id', $this->account->id)->count())->toBe(0);
});

test('request response includes resolution fields and immutable item snapshot', function (): void {
    $response = $this->actingAs($this->account)
        ->postJson('/api/v1/accessory-order-requests', [
            'requested_discount_type' => 'pwd',
            'items' => [
                ['product_variant_id' => $this->variant->id, 'quantity' => 2],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.requested_discount_type', 'pwd')
        ->assertJsonPath('data.resolved_by', null)
        ->assertJsonPath('data.resolved_at', null)
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.unit_price', '100.00')
        ->assertJsonPath('data.items.0.product_variant_id', $this->variant->id)
        ->assertJsonPath('data.items.0.item_snapshot.product_variant_id', $this->variant->id);

    expect($response->json('data.subtotal_amount'))->toBe('200.00');
});

test('request items expose sanitized snapshot images and a primary image URL', function (): void {
    $this->variant->product->update([
        'images' => [
            'products/fallback.jpg',
            '../private-product.png',
        ],
    ]);
    $this->variant->update([
        'images' => [
            'variants/primary.jpg',
            '/private-variant.jpg',
            'https://admin.example.test/private.jpg',
        ],
    ]);

    $request = $this->actingAs($this->account)
        ->postJson('/api/v1/accessory-order-requests', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    $request
        ->assertJsonPath('data.items.0.item_snapshot.images', ['variants/primary.jpg'])
        ->assertJsonPath('data.items.0.image_url', 'variants/primary.jpg');

    $requestId = $request->json('data.id');

    $this->actingAs($this->account)
        ->getJson("/api/v1/accessory-order-requests/{$requestId}")
        ->assertOk()
        ->assertJsonPath('data.items.0.item_snapshot.images', ['variants/primary.jpg'])
        ->assertJsonPath('data.items.0.image_url', 'variants/primary.jpg');

    $this->actingAs($this->account)
        ->getJson('/api/v1/accessory-order-requests?filter=current')
        ->assertOk()
        ->assertJsonPath('data.0.items.0.image_url', 'variants/primary.jpg');
});

test('request item images fall back to the product image', function (): void {
    $this->variant->product->update(['images' => ['products/fallback.jpg']]);
    $this->variant->update(['images' => []]);

    $this->actingAs($this->account)
        ->postJson('/api/v1/accessory-order-requests', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.items.0.item_snapshot.images', ['products/fallback.jpg'])
        ->assertJsonPath('data.items.0.image_url', 'products/fallback.jpg');
});

test('legacy request items expose a read-time catalog image fallback', function (): void {
    $this->variant->update(['images' => ['variants/live-fallback.jpg']]);
    $orderRequest = AccessoryOrderRequest::factory()->create([
        'user_id' => $this->account->id,
        'patient_id' => $this->account->patient->id,
    ]);
    $orderRequest->items()->create([
        'product_variant_id' => $this->variant->id,
        'description' => 'Legacy Care Kit',
        'quantity' => 1,
        'unit_price' => 100,
        'amount' => 100,
        'item_kind' => 'accessory',
        'item_snapshot' => [
            'product_variant_id' => $this->variant->id,
            'sku' => $this->variant->sku,
            'variant_name' => $this->variant->name,
            'product_name' => $this->variant->product->name,
            'price' => '100.00',
            'attributes' => $this->variant->attributes,
        ],
    ]);

    $this->actingAs($this->account)
        ->getJson("/api/v1/accessory-order-requests/{$orderRequest->id}")
        ->assertOk()
        ->assertJsonPath('data.items.0.item_snapshot.images', null)
        ->assertJsonPath('data.items.0.image_url', 'variants/live-fallback.jpg');
});

test('request item image URL is null when catalog images are unsafe or absent', function (): void {
    $this->variant->product->update(['images' => ['../private-product.png']]);
    $this->variant->update(['images' => ['/private-variant.jpg', 'https://admin.example.test/private.jpg']]);

    $this->actingAs($this->account)
        ->postJson('/api/v1/accessory-order-requests', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.items.0.item_snapshot.images', [])
        ->assertJsonPath('data.items.0.image_url', null);
});

test('current request list includes catalog references and item snapshots', function (): void {
    $this->actingAs($this->account)
        ->postJson('/api/v1/accessory-order-requests', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    $this->actingAs($this->account)
        ->getJson('/api/v1/accessory-order-requests?filter=current&page=1&per_page=15')
        ->assertOk()
        ->assertJsonPath('data.0.items.0.product_variant_id', $this->variant->id)
        ->assertJsonPath('data.0.items.0.item_snapshot.product_variant_id', $this->variant->id);
});

test('owner can cancel a pending request with an encrypted reason idempotently', function (): void {
    $request = $this->actingAs($this->account)
        ->postJson('/api/v1/accessory-order-requests', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated()
        ->json('data');
    $reason = 'I selected the wrong accessories.';

    $this->actingAs($this->account)
        ->postJson("/api/v1/accessory-order-requests/{$request['id']}/cancel", [
            'reason_details' => $reason,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation_reason', $reason);

    $storedReason = DB::table('accessory_order_requests')
        ->where('id', $request['id'])
        ->value('encrypted_cancellation_reason');

    expect($storedReason)->not->toContain($reason);

    $this->actingAs($this->account)
        ->postJson("/api/v1/accessory-order-requests/{$request['id']}/cancel", [
            'reason_details' => 'This retry must not replace the original reason.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation_reason', $reason);

    $this->actingAs($this->account)
        ->getJson("/api/v1/accessory-order-requests/{$request['id']}")
        ->assertOk()
        ->assertJsonPath('data.cancellation_reason', $reason);

    $this->actingAs($this->account)
        ->getJson('/api/v1/accessory-order-requests?filter=history')
        ->assertOk()
        ->assertJsonPath('data.0.cancellation_reason', $reason);

    $otherAccount = User::factory()->patient()->create();

    $this->actingAs($otherAccount)
        ->getJson("/api/v1/accessory-order-requests/{$request['id']}")
        ->assertNotFound();
});

test('patient must provide a cancellation reason of at most 1000 characters', function (): void {
    $request = $this->actingAs($this->account)
        ->postJson('/api/v1/accessory-order-requests', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($this->account)
        ->postJson("/api/v1/accessory-order-requests/{$request['id']}/cancel")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason_details']);

    $this->actingAs($this->account)
        ->postJson("/api/v1/accessory-order-requests/{$request['id']}/cancel", [
            'reason_details' => str_repeat('x', 1001),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason_details']);

    expect(AccessoryOrderRequest::query()->findOrFail($request['id'])->status->value)->toBe('pending');
});
