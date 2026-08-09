<?php

/**
 * Tests for BuildQuotationItemSnapshot action.
 *
 * @see tasks/todo.md Task 6
 */

use App\Actions\Quotations\BuildQuotationItemSnapshot;
use App\Enums\CommercialItemKind;
use App\Models\LensCategory;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('frame variant snapshots SKU, names, product type, and attributes', function () {
    $variant = ProductVariant::factory()->create([
        'sku' => 'FRM-001',
        'name' => 'Black',
        'attributes' => ['temple' => 140],
    ]);

    $result = app(BuildQuotationItemSnapshot::class)->handle(
        productVariantId: $variant->id,
    );

    expect($result['item_kind'])->toBe(CommercialItemKind::Frame)
        ->and($result['item_snapshot']['sku'])->toBe('FRM-001')
        ->and($result['item_snapshot']['variant_name'])->toBe('Black')
        ->and($result['item_snapshot']['product_name'])->toBe($variant->product->name)
        ->and($result['item_snapshot']['product_type'])->toBe('frame')
        ->and($result['item_snapshot']['attributes'])->toBe(['temple' => 140]);
});

test('accessory variant snapshots as accessory kind', function () {
    $variant = ProductVariant::factory()->create();
    $variant->product->update(['product_type' => 'accessory']);

    $result = app(BuildQuotationItemSnapshot::class)->handle(
        productVariantId: $variant->id,
    );

    expect($result['item_kind'])->toBe(CommercialItemKind::Accessory)
        ->and($result['item_snapshot']['product_type'])->toBe('accessory');
});

test('contact lens variant snapshots as contact_lens kind', function () {
    $variant = ProductVariant::factory()->create();
    $variant->product->update(['product_type' => 'contact_lens']);

    $result = app(BuildQuotationItemSnapshot::class)->handle(
        productVariantId: $variant->id,
    );

    expect($result['item_kind'])->toBe(CommercialItemKind::ContactLens)
        ->and($result['item_snapshot']['product_type'])->toBe('contact_lens');
});

test('lens category snapshots package identity and name', function () {
    $lensCategory = LensCategory::factory()->withPrice(3000)->create([
        'name' => 'Single Vision',
    ]);

    $result = app(BuildQuotationItemSnapshot::class)->handle(
        lensCategoryId: $lensCategory->id,
    );

    expect($result['item_kind'])->toBe(CommercialItemKind::LensPackage)
        ->and($result['item_snapshot']['lens_category_id'])->toBe($lensCategory->id)
        ->and($result['item_snapshot']['lens_category_name'])->toBe('Single Vision');
});

test('service reference returns service kind with null snapshot', function () {
    $result = app(BuildQuotationItemSnapshot::class)->handle(
        serviceId: 1,
    );

    expect($result['item_kind'])->toBe(CommercialItemKind::Service)
        ->and($result['item_snapshot'])->toBeNull();
});

test('custom product line with explicit kind returns custom_product', function () {
    $result = app(BuildQuotationItemSnapshot::class)->handle(
        explicitKind: 'custom_product',
    );

    expect($result['item_kind'])->toBe(CommercialItemKind::CustomProduct)
        ->and($result['item_snapshot'])->toBeNull();
});

test('custom lens option line with explicit kind returns lens_option', function () {
    $result = app(BuildQuotationItemSnapshot::class)->handle(
        explicitKind: 'lens_option',
    );

    expect($result['item_kind'])->toBe(CommercialItemKind::LensOption);
});

test('custom line without explicit kind defaults to custom_product', function () {
    $result = app(BuildQuotationItemSnapshot::class)->handle();

    expect($result['item_kind'])->toBe(CommercialItemKind::CustomProduct)
        ->and($result['item_snapshot'])->toBeNull();
});

test('invalid explicit kind is rejected', function () {
    app(BuildQuotationItemSnapshot::class)->handle(
        explicitKind: 'invalid_kind',
    );
})->throws(ValidationException::class, 'Invalid item kind');

test('catalog-requiring kind without reference is rejected', function () {
    app(BuildQuotationItemSnapshot::class)->handle(
        explicitKind: 'frame',
    );
})->throws(ValidationException::class, 'requires a catalog reference');

test('product variant overrides explicit kind', function () {
    $variant = ProductVariant::factory()->create();

    $result = app(BuildQuotationItemSnapshot::class)->handle(
        productVariantId: $variant->id,
        explicitKind: 'custom_product', // should be overridden by variant
    );

    // Product variant takes precedence over explicit kind
    expect($result['item_kind'])->not->toBe(CommercialItemKind::CustomProduct);
});

test('lens category overrides explicit kind', function () {
    $lensCategory = LensCategory::factory()->withPrice()->create();

    $result = app(BuildQuotationItemSnapshot::class)->handle(
        lensCategoryId: $lensCategory->id,
        explicitKind: 'custom_product',
    );

    expect($result['item_kind'])->toBe(CommercialItemKind::LensPackage);
});
