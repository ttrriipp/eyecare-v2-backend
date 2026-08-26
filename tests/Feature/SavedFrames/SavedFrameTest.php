<?php

use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\SavedFrame;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('saved frame belongs to an account', function () {
    $account = User::factory()->create();
    $savedFrame = SavedFrame::factory()->forAccount($account)->create();

    expect($savedFrame->account->id)->toBe($account->id);
});

test('saved frame belongs to a product variant', function () {
    $account = User::factory()->create();
    $variant = ProductVariant::factory()->create();

    expect(ProductVariant::find($variant->id))->not->toBeNull();

    $savedFrame = SavedFrame::create([
        'user_id' => $account->id,
        'product_variant_id' => $variant->id,
    ]);

    expect($savedFrame->product_variant_id)->toBe($variant->id);

    $loaded = SavedFrame::with('variant')->find($savedFrame->id);
    expect($loaded->variant)->not->toBeNull()
        ->and($loaded->variant->id)->toBe($variant->id);
});

test('user can have multiple saved frames', function () {
    $account = User::factory()->create();
    $variants = ProductVariant::factory()->count(3)->create();

    foreach ($variants as $variant) {
        SavedFrame::factory()->forAccount($account)->forVariant($variant)->create();
    }

    expect($account->savedFrames()->count())->toBe(3);
});

test('user cannot save the same variant twice', function () {
    $account = User::factory()->create();
    $variant = ProductVariant::factory()->create();

    SavedFrame::factory()->forAccount($account)->forVariant($variant)->create();

    SavedFrame::query()->insertOrIgnore([
        'user_id' => $account->id,
        'product_variant_id' => $variant->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(SavedFrame::query()->where('user_id', $account->id)->count())->toBe(1);
});

test('saved frames are ordered by created_at descending', function () {
    $account = User::factory()->create();
    $variant1 = ProductVariant::factory()->create();
    $variant2 = ProductVariant::factory()->create();

    SavedFrame::factory()->forAccount($account)->forVariant($variant1)->create([
        'created_at' => now()->subDay(),
    ]);
    SavedFrame::factory()->forAccount($account)->forVariant($variant2)->create([
        'created_at' => now(),
    ]);

    $frames = $account->savedFrames()->get();

    expect($frames->first()->product_variant_id)->toBe($variant2->id)
        ->and($frames->last()->product_variant_id)->toBe($variant1->id);
});

test('deleting user cascades saved frames', function () {
    $account = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    SavedFrame::factory()->forAccount($account)->forVariant($variant)->create();

    $account->delete();

    expect(SavedFrame::query()->where('user_id', $account->id)->count())->toBe(0);
});

test('soft-deleting product variant preserves saved frames', function () {
    $account = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    SavedFrame::factory()->forAccount($account)->forVariant($variant)->create();

    $variant->delete();

    expect(SavedFrame::query()->where('product_variant_id', $variant->id)->count())->toBe(1);
});

test('force-deleting product variant cascades saved frames', function () {
    $account = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    SavedFrame::factory()->forAccount($account)->forVariant($variant)->create();

    $variant->forceDelete();

    expect(SavedFrame::query()->where('product_variant_id', $variant->id)->count())->toBe(0);
});

test('saving a frame creates no inventory movement', function () {
    $account = User::factory()->create();
    $variant = ProductVariant::factory()->create();

    $movementCountBefore = InventoryMovement::query()->count();

    SavedFrame::factory()->forAccount($account)->forVariant($variant)->create();

    expect(InventoryMovement::query()->count())->toBe($movementCountBefore);
});

test('saved frame timestamps are set', function () {
    $savedFrame = SavedFrame::factory()->create();

    expect($savedFrame->created_at)->not->toBeNull()
        ->and($savedFrame->updated_at)->not->toBeNull();
});
