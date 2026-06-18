<?php

use App\Filament\Resources\LensTypes\Pages\CreateLensType;
use App\Filament\Resources\LensTypes\Pages\ListLensTypes;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Brand;
use App\Models\Category;
use App\Models\LensType;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff and admin users can list products', function (string $factoryState) {
    $user = User::factory()->{$factoryState}()->create();
    $products = Product::factory()->count(2)->create();

    $this->actingAs($user);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords($products);
})->with([
    'admin' => ['admin'],
    'staff' => ['staff'],
]);

test('staff can create and edit products with variants', function () {
    $staff = User::factory()->staff()->create();
    $brand = Brand::factory()->create();
    $category = Category::factory()->create();

    $this->actingAs($staff);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Aviator Frame',
            'slug' => 'aviator-frame',
            'is_active' => true,
            'variants' => [
                [
                    'name' => 'Silver',
                    'sku' => 'AVF-SLV-001',
                    'price' => 189.99,
                    'stock_quantity' => 4,
                    'low_stock_threshold' => 5,
                    'is_active' => true,
                    'ar_eligible' => true,
                    'ar_asset_reference' => 'frames/aviator-silver.glb',
                ],
            ],
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $product = Product::query()->where('slug', 'aviator-frame')->first();

    expect($product)->not->toBeNull()
        ->and($product->variants)->toHaveCount(1);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'name' => 'Aviator Frame Updated',
        ])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($product->fresh()->name)->toBe('Aviator Frame Updated');
});

test('product table shows low stock state', function () {
    $staff = User::factory()->staff()->create();

    $lowStockProduct = Product::factory()->create(['name' => 'Low Stock Frame']);
    ProductVariant::factory()->for($lowStockProduct)->create([
        'stock_quantity' => 1,
        'low_stock_threshold' => 3,
    ]);

    $healthyProduct = Product::factory()->create(['name' => 'Healthy Stock Frame']);
    ProductVariant::factory()->for($healthyProduct)->create([
        'stock_quantity' => 10,
        'low_stock_threshold' => 3,
    ]);

    $this->actingAs($staff);

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet('low_stock_state', 'Low stock', $lowStockProduct)
        ->assertTableColumnStateSet('low_stock_state', 'OK', $healthyProduct);
});

test('staff can create lens types', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateLensType::class)
        ->fillForm([
            'name' => 'photochromic',
            'description' => 'Light-responsive lenses.',
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(LensType::class, [
        'name' => 'photochromic',
    ]);

    Livewire::test(ListLensTypes::class)
        ->assertCanSeeTableRecords(LensType::query()->where('name', 'photochromic')->get());
});

test('product image uploads use public visibility and validation', function () {
    Storage::fake('public');

    $staff = User::factory()->staff()->create();
    $product = Product::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'images' => [
                [
                    'path' => [UploadedFile::fake()->image('frame.jpg')],
                    'is_primary' => true,
                    'sort_order' => 0,
                ],
            ],
        ])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $image = ProductImage::query()->where('product_id', $product->id)->first();

    expect($image)->not->toBeNull();
    Storage::disk('public')->assertExists($image->path);
});

test('product edit page loads without error when product has an existing image', function () {
    Storage::fake('public');

    $staff = User::factory()->staff()->create();
    $product = Product::factory()->create();
    ProductImage::factory()->create([
        'product_id' => $product->id,
        'path' => 'products/existing-frame.jpg',
        'is_primary' => true,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSuccessful()
        ->assertHasNoFormErrors();
});
