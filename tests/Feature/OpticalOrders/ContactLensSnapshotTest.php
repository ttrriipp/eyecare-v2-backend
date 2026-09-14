<?php

/**
 * Tests for contact-lens parameter snapshots.
 */

use App\Actions\OpticalOrders\BuildOpticalItemSnapshot;
use App\Enums\CommercialItemKind;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('contact-lens snapshots contain only canonical applicable parameters', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'attributes' => [
            'power' => '-3.00',
            'base_curve' => 8.6,
            'diameter' => 14.0,
            'color' => 'Blue',
            'pack_size' => 6,
            'non_canonical_key' => 'should be excluded',
        ],
    ]);

    $result = app(BuildOpticalItemSnapshot::class)->handle(
        productVariantId: $variant->id,
    );

    expect($result['item_kind'])->toBe(CommercialItemKind::ContactLens)
        ->and($result['item_snapshot']['attributes'])->toHaveKeys(['power', 'base_curve', 'diameter', 'color', 'pack_size'])
        ->and($result['item_snapshot']['attributes'])->not->toHaveKey('non_canonical_key');
});

test('contact-lens snapshot includes product and variant identity', function () {
    $product = Product::factory()->create([
        'product_type' => 'contact_lens',
        'name' => 'Acuvue Oasys',
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'sku' => 'CL-001',
        'name' => '8.6 BC / -3.00',
        'attributes' => ['power' => '-3.00', 'base_curve' => 8.6],
    ]);

    $result = app(BuildOpticalItemSnapshot::class)->handle(
        productVariantId: $variant->id,
    );

    expect($result['item_snapshot']['product_name'])->toBe('Acuvue Oasys')
        ->and($result['item_snapshot']['sku'])->toBe('CL-001')
        ->and($result['item_snapshot']['variant_name'])->toBe('8.6 BC / -3.00')
        ->and($result['item_snapshot']['product_type'])->toBe('contact_lens');
});

test('confirmation copies same parameters to job order item', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'attributes' => [
            'power' => '-2.50',
            'base_curve' => 8.5,
            'diameter' => 14.2,
        ],
    ]);

    $result = app(BuildOpticalItemSnapshot::class)->handle(
        productVariantId: $variant->id,
    );

    expect($result['item_snapshot']['attributes']['power'])->toBe('-2.50')
        ->and($result['item_snapshot']['attributes']['base_curve'])->toBe(8.5)
        ->and($result['item_snapshot']['attributes']['diameter'])->toBe(14.2);
});

test('later product variant edits do not change snapshot', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'attributes' => ['power' => '-3.00', 'base_curve' => 8.6],
    ]);

    $result = app(BuildOpticalItemSnapshot::class)->handle(
        productVariantId: $variant->id,
    );

    $variant->update(['attributes' => ['power' => '-4.00', 'base_curve' => 9.0]]);

    expect($result['item_snapshot']['attributes']['power'])->toBe('-3.00')
        ->and($result['item_snapshot']['attributes']['base_curve'])->toBe(8.6);
});

test('frame snapshot includes all attributes', function () {
    $product = Product::factory()->create(['product_type' => 'frame']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'attributes' => ['temple' => 140, 'color' => 'Black'],
    ]);

    $result = app(BuildOpticalItemSnapshot::class)->handle(
        productVariantId: $variant->id,
    );

    expect($result['item_kind'])->toBe(CommercialItemKind::Frame)
        ->and($result['item_snapshot']['attributes'])->toHaveKeys(['temple', 'color'])
        ->and($result['item_snapshot']['attributes']['temple'])->toBe(140)
        ->and($result['item_snapshot']['attributes']['color'])->toBe('Black');
});
