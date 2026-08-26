<?php

use App\Models\Brand;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SavedFrame;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->brand = Brand::factory()->create();
    $this->frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    $this->variant = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'is_active' => true,
        'stock_quantity' => 5,
    ]);
});

// --- Save (PUT) tests ---

test('authenticated account can save a frame variant', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertOk()
        ->assertJsonPath('data.product_variant_id', $this->variant->id)
        ->assertJsonPath('data.availability', 'available')
        ->assertJsonStructure([
            'data' => [
                'product_variant_id',
                'saved_at',
                'availability',
                'variant',
            ],
        ]);

    $this->assertDatabaseHas('saved_frames', [
        'user_id' => $user->id,
        'product_variant_id' => $this->variant->id,
    ]);
});

test('linked patient account can save a frame variant', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->putJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertOk()
        ->assertJsonPath('data.product_variant_id', $this->variant->id);
});

test('unlinked account can save a frame variant', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertOk();
});

test('repeated PUT is idempotent and preserves saved_at', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertOk();

    $firstSavedAt = SavedFrame::query()
        ->where('user_id', $user->id)
        ->where('product_variant_id', $this->variant->id)
        ->first()->created_at;

    // Small delay to ensure timestamp would differ if changed
    $this->travel(2)->seconds();

    $this->actingAs($user)
        ->putJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertOk();

    $secondSavedAt = SavedFrame::query()
        ->where('user_id', $user->id)
        ->where('product_variant_id', $this->variant->id)
        ->first()->created_at;

    expect($firstSavedAt->equalTo($secondSavedAt))->toBeTrue();
    expect(SavedFrame::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('saving an inactive variant returns 422', function () {
    $user = User::factory()->create();
    $this->variant->update(['is_active' => false]);

    $this->actingAs($user)
        ->putJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertUnprocessable();
});

test('saving a soft-deleted variant returns 422', function () {
    $user = User::factory()->create();
    $this->variant->delete();

    $this->actingAs($user)
        ->putJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertUnprocessable();
});

test('saving a non-frame variant returns 422', function () {
    $user = User::factory()->create();
    $accessory = Product::factory()->create([
        'product_type' => 'accessory',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $accessory->id,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->putJson("/api/v1/saved-frames/{$variant->id}")
        ->assertUnprocessable();
});

test('saving a nonexistent variant returns 422', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/v1/saved-frames/99999')
        ->assertUnprocessable();
});

test('saving a variant with inactive product returns 422', function () {
    $user = User::factory()->create();
    $this->frame->update(['is_active' => false]);

    $this->actingAs($user)
        ->putJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertUnprocessable();
});

test('saving requires authentication', function () {
    $this->putJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertUnauthorized();
});

test('save response does not expose internal fields', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->putJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertOk();

    $response->assertJsonMissing(['cost_price']);
    $response->assertJsonMissing(['stock_quantity']);
    $response->assertJsonMissing(['low_stock_threshold']);
    $response->assertJsonMissing(['user_id']);
});

test('saving creates no inventory movement', function () {
    $user = User::factory()->create();
    $movementCountBefore = InventoryMovement::query()->count();

    $this->actingAs($user)
        ->putJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertOk();

    expect(InventoryMovement::query()->count())->toBe($movementCountBefore);
});

// --- Remove (DELETE) tests ---

test('authenticated account can remove a saved frame', function () {
    $user = User::factory()->create();
    SavedFrame::factory()->forAccount($user)->forVariant($this->variant)->create();

    $this->actingAs($user)
        ->deleteJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('saved_frames', [
        'user_id' => $user->id,
        'product_variant_id' => $this->variant->id,
    ]);
});

test('repeated DELETE returns 204', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertNoContent();

    $this->actingAs($user)
        ->deleteJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertNoContent();
});

test('one account cannot remove another account preference', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    SavedFrame::factory()->forAccount($userA)->forVariant($this->variant)->create();

    $this->actingAs($userB)
        ->deleteJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertNoContent();

    $this->assertDatabaseHas('saved_frames', [
        'user_id' => $userA->id,
        'product_variant_id' => $this->variant->id,
    ]);
});

test('removing a saved frame creates no inventory movement', function () {
    $user = User::factory()->create();
    SavedFrame::factory()->forAccount($user)->forVariant($this->variant)->create();
    $movementCountBefore = InventoryMovement::query()->count();

    $this->actingAs($user)
        ->deleteJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertNoContent();

    expect(InventoryMovement::query()->count())->toBe($movementCountBefore);
});

test('removing an inactive preference returns 204', function () {
    $user = User::factory()->create();
    SavedFrame::factory()->forAccount($user)->forVariant($this->variant)->create();
    $this->variant->update(['is_active' => false]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertNoContent();
});

test('removing a nonexistent variant returns 204', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/api/v1/saved-frames/99999')
        ->assertNoContent();
});

test('delete requires authentication', function () {
    $this->deleteJson("/api/v1/saved-frames/{$this->variant->id}")
        ->assertUnauthorized();
});
