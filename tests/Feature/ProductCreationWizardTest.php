<?php

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->user = User::factory()->staff()->create();
});

test('product creation is presented as a two-step wizard', function (): void {
    Livewire::actingAs($this->user)
        ->test(CreateProduct::class)
        ->assertWizardStepExists(1)
        ->assertWizardStepExists(2)
        ->assertSee('Product')
        ->assertSee('Variants');
});

test('the wizard creates the product and its variants together', function (): void {
    $brand = Brand::factory()->create();

    Livewire::actingAs($this->user)
        ->test(CreateProduct::class)
        ->fillForm([
            'product_type' => 'frame',
            'name' => 'Wizard Frame',
            'slug' => 'wizard-frame',
            'brand_id' => $brand->id,
            'is_active' => true,
        ])
        ->goToNextWizardStep()
        ->fillForm([
            'variants' => [
                [
                    'name' => 'Black Acetate',
                    'price' => 1999.5,
                    'attributes' => [
                        'lens_width' => 52,
                        'bridge' => 18,
                        'color' => 'Black',
                        'material' => 'Acetate',
                    ],
                    'frame_other_details' => [],
                    'stock_quantity' => 0,
                    'low_stock_threshold' => 0,
                    'target_stock_level' => null,
                    'is_active' => true,
                ],
            ],
        ])
        ->assertSet('data.variants', function (array $variants): bool {
            expect(array_values($variants)[0]['attributes'] ?? null)->toMatchArray([
                'lens_width' => 52,
                'bridge' => 18,
                'color' => 'Black',
                'material' => 'Acetate',
            ]);

            return true;
        })
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $product = Product::query()->where('slug', 'wizard-frame')->firstOrFail();
    $variant = $product->variants()->firstOrFail();

    expect($variant->name)->toBe('Black Acetate')
        ->and((float) $variant->price)->toBe(1999.5)
        ->and($variant->attributes)->toMatchArray([
            'lens_width' => 52,
            'bridge' => 18,
            'color' => 'Black',
            'material' => 'Acetate',
        ]);
});

test('the wizard rejects an active product without an active variant', function (): void {
    $brand = Brand::factory()->create();

    Livewire::actingAs($this->user)
        ->test(CreateProduct::class)
        ->fillForm([
            'product_type' => 'frame',
            'name' => 'Active Frame Without Variant',
            'slug' => 'active-frame-without-variant',
            'brand_id' => $brand->id,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['is_active']);

    expect(Product::query()->where('slug', 'active-frame-without-variant')->exists())->toBeFalse();
});

test('the wizard creates a generic product variant with its attributes', function (): void {
    $brand = Brand::factory()->create();

    Livewire::actingAs($this->user)
        ->test(CreateProduct::class)
        ->fillForm([
            'product_type' => 'accessory',
            'name' => 'Wizard Accessory',
            'slug' => 'wizard-accessory',
            'brand_id' => $brand->id,
            'usage' => '1_month',
            'is_active' => true,
        ])
        ->goToNextWizardStep()
        ->fillForm([
            'variants' => [[
                'name' => 'Black Travel Case',
                'price' => 249.99,
                'attributes' => [
                    'color' => 'Black',
                    'material' => 'Leather',
                ],
                'low_stock_threshold' => 0,
                'target_stock_level' => null,
                'is_active' => true,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $product = Product::query()->where('slug', 'wizard-accessory')->firstOrFail();
    $variant = $product->variants()->firstOrFail();

    expect($variant->attributes)->toMatchArray([
        'color' => 'Black',
        'material' => 'Leather',
    ]);
});

test('frame defaults prefill a new wizard variant', function (): void {
    $brand = Brand::factory()->create();

    Livewire::actingAs($this->user)
        ->test(CreateProduct::class)
        ->fillForm([
            'product_type' => 'frame',
            'name' => 'Defaulted Wizard Frame',
            'slug' => 'defaulted-wizard-frame',
            'brand_id' => $brand->id,
            'frame_default_attributes' => [
                'lens_width' => 52,
                'bridge' => 18,
                'color' => 'Black',
                'material' => 'Acetate',
            ],
            'frame_other_details' => [
                ['key' => 'model_code', 'value' => 'F-001'],
            ],
        ])
        ->assertSet('data.frame_default_attributes.lens_width', 52)
        ->assertSet('data.frame_other_details.0.key', 'model_code')
        ->goToNextWizardStep()
        ->assertSet('data.variants', function (array $variants): bool {
            expect($variants)->toHaveCount(1);

            $variant = array_values($variants)[0] ?? [];

            expect($variant['attributes'] ?? null)->toMatchArray([
                'lens_width' => 52,
                'bridge' => 18,
                'color' => 'Black',
                'material' => 'Acetate',
            ])
                ->and($variant['frame_other_details'] ?? null)->toContain([
                    'key' => 'model_code',
                    'value' => 'F-001',
                ]);

            return true;
        });
});

test('generic defaults prefill a new wizard variant', function (): void {
    $brand = Brand::factory()->create();

    Livewire::actingAs($this->user)
        ->test(CreateProduct::class)
        ->fillForm([
            'product_type' => 'accessory',
            'name' => 'Defaulted Wizard Accessory',
            'slug' => 'defaulted-wizard-accessory',
            'brand_id' => $brand->id,
            'usage' => '1_month',
            'generic_default_details' => [
                ['key' => 'color', 'value' => 'Black'],
                ['key' => 'material', 'value' => 'Leather'],
            ],
        ])
        ->goToNextWizardStep()
        ->assertSet('data.variants', function (array $variants): bool {
            $variant = array_values($variants)[0] ?? [];
            expect($variant['attributes'] ?? null)->toContain(
                ['key' => 'color', 'value' => 'Black'],
                ['key' => 'material', 'value' => 'Leather'],
            );

            return true;
        });
});
