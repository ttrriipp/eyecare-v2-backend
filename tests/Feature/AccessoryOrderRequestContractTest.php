<?php

use App\Models\AccessoryOrderRequest;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

test('owner can cancel a pending request idempotently and other accounts cannot see it', function (): void {
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
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $this->actingAs($this->account)
        ->postJson("/api/v1/accessory-order-requests/{$request['id']}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $otherAccount = User::factory()->patient()->create();

    $this->actingAs($otherAccount)
        ->getJson("/api/v1/accessory-order-requests/{$request['id']}")
        ->assertNotFound();
});
