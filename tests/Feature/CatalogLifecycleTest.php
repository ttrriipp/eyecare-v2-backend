<?php

use App\Models\Brand;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Service;
use App\Models\User;
use App\Services\CatalogLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('catalog lifecycle can deactivate and reactivate every catalog kind', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $records = [
        Brand::factory()->create(),
        ProductCategory::factory()->create(),
        LensCategory::factory()->withPrice()->create(),
        Product::factory()->create(),
        ProductVariant::factory()->create(),
        Service::factory()->create(),
    ];

    foreach ($records as $record) {
        CatalogLifecycle::deactivate($record);
        expect($record->fresh()->is_active)->toBeFalse();

        CatalogLifecycle::activate($record->fresh());
        expect($record->fresh()->is_active)->toBeTrue();
    }
});

test('active product and variant scopes exclude records under inactive catalog parents', function () {
    $activeProduct = Product::factory()->create();
    $inactiveBrand = Brand::factory()->create(['is_active' => false]);
    $inactiveCategory = ProductCategory::factory()->create(['is_active' => false]);
    $productWithInactiveBrand = Product::factory()->create(['brand_id' => $inactiveBrand->id]);
    $productWithInactiveCategory = Product::factory()->create(['category_id' => $inactiveCategory->id]);
    $activeVariant = ProductVariant::factory()->create(['product_id' => $activeProduct->id]);

    expect(Product::query()->active()->pluck('id')->all())
        ->toContain($activeProduct->id)
        ->not->toContain($productWithInactiveBrand->id)
        ->not->toContain($productWithInactiveCategory->id)
        ->and(ProductVariant::query()->active()->pluck('id')->all())
        ->toContain($activeVariant->id);
});

test('referenced catalog records cannot be permanently deleted', function () {
    $admin = User::factory()->admin()->create();
    $brand = Brand::factory()->create();
    Product::factory()->create(['brand_id' => $brand->id]);

    $this->actingAs($admin);

    expect(fn () => CatalogLifecycle::delete($brand))
        ->toThrow(ValidationException::class);

    expect(Brand::withTrashed()->find($brand->id))->not->toBeNull();
});

test('unreferenced service can be permanently deleted', function () {
    $admin = User::factory()->admin()->create();
    $service = Service::factory()->create();

    $this->actingAs($admin);

    CatalogLifecycle::delete($service);

    expect(Service::query()->find($service->id))->toBeNull();
});

test('unreferenced catalog records can be permanently deleted', function () {
    $admin = User::factory()->admin()->create();
    $brand = Brand::factory()->create();
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->create([
        'brand_id' => $brand->id,
        'category_id' => $category->id,
    ]);
    $variant = ProductVariant::factory()->create();
    $lensCategory = LensCategory::factory()->withPrice()->create();
    $lensOption = LensOption::factory()->create();
    $service = Service::factory()->create();

    $this->actingAs($admin);

    foreach ([$variant, $product, $brand, $category, $lensCategory, $lensOption, $service] as $record) {
        CatalogLifecycle::delete($record);
    }

    expect(ProductVariant::query()->withTrashed()->find($variant->id))->toBeNull()
        ->and(Product::query()->withTrashed()->find($product->id))->toBeNull()
        ->and(Brand::query()->withTrashed()->find($brand->id))->toBeNull()
        ->and(ProductCategory::query()->withTrashed()->find($category->id))->toBeNull()
        ->and(LensCategory::query()->withTrashed()->find($lensCategory->id))->toBeNull()
        ->and(LensOption::query()->find($lensOption->id))->toBeNull()
        ->and(Service::query()->find($service->id))->toBeNull();
});
