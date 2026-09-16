<?php

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->user = User::factory()->staff()->create();
});

test('new generic product details start with one empty row', function (string $productType) {
    Livewire::actingAs($this->user)
        ->test(CreateProduct::class)
        ->set('data.product_type', $productType)
        ->assertSee('Default Details')
        ->assertSet('data.generic_default_details', [
            ['key' => '', 'value' => ''],
        ]);
})->with([
    'contact lens' => 'contact_lens',
    'accessory' => 'accessory',
]);

test('new frame product details start with one empty row', function () {
    Livewire::actingAs($this->user)
        ->test(CreateProduct::class)
        ->assertSet('data.frame_other_details.0.key', '')
        ->assertSet('data.frame_other_details.0.value', '');
});

test('frame default details are prefilled when creating a variant', function () {
    $product = Product::factory()->create([
        'product_type' => 'frame',
        'default_variant_attributes' => [
            'lens_width' => 52,
            'bridge' => 18,
            'temple' => 140,
            'lens_height' => 40,
            'color' => 'Black',
            'material' => 'Acetate',
        ],
    ]);

    Livewire::actingAs($this->user)
        ->test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
        ->mountTableAction('create')
        ->assertTableActionDataSet([
            'attributes.lens_width' => 52,
            'attributes.bridge' => 18,
            'attributes.temple' => 140,
            'attributes.lens_height' => 40,
            'attributes.color' => 'Black',
            'attributes.material' => 'Acetate',
        ]);
});

test('generic default details are prefilled when creating a variant', function () {
    $product = Product::factory()->create([
        'product_type' => 'contact_lens',
        'default_variant_attributes' => [
            'power' => '-2.00',
            'base_curve' => '8.6',
            'diameter' => '14.0',
        ],
    ]);

    Livewire::actingAs($this->user)
        ->test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
        ->mountTableAction('create')
        ->assertTableActionDataSet([
            'attributes' => [
                'power' => '-2.00',
                'base_curve' => '8.6',
                'diameter' => '14.0',
            ],
        ]);
});

test('accessory variant stock is managed through expiry-tracked receiving', function () {
    $product = Product::factory()->accessory()->create();

    Livewire::actingAs($this->user)
        ->test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
        ->mountTableAction('create')
        ->assertMountedActionModalDontSee('Opening Stock')
        ->assertMountedActionModalDontSee('Physical quantity on hand at creation.');
});

test('default variant details sections are collapsible', function () {
    $schema = ProductForm::configure(Schema::make());
    $defaultDetailsSections = collect($schema->getComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Section
            && $component->getHeading() === 'Default Variant Details')
        ->values();

    expect($defaultDetailsSections)->toHaveCount(2);

    $defaultDetailsSections->each(
        fn (Section $section) => expect($section->isCollapsible())->toBeTrue(),
    );
});

test('switching generic product types keeps one empty details row', function () {
    Livewire::actingAs($this->user)
        ->test(CreateProduct::class)
        ->set('data.product_type', 'contact_lens')
        ->set('data.product_type', 'accessory')
        ->assertSet('data.generic_default_details', [
            ['key' => '', 'value' => ''],
        ]);
});

test('frame product defaults save dimensions and additional details together', function () {
    $brand = Brand::factory()->create();

    Livewire::actingAs($this->user)
        ->test(CreateProduct::class)
        ->fillForm([
            'product_type' => 'frame',
            'name' => 'Test Frame',
            'slug' => 'test-frame',
            'brand_id' => $brand->id,
            'frame_default_attributes' => [
                'lens_width' => 52,
                'bridge' => 18,
                'color' => 'Black',
            ],
            'frame_other_details' => [
                ['key' => 'finish', 'value' => 'Matte'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::query()->where('slug', 'test-frame')->firstOrFail()->default_variant_attributes)
        ->toMatchArray([
            'lens_width' => 52,
            'bridge' => 18,
            'color' => 'Black',
            'finish' => 'Matte',
        ]);
});

test('existing frame product defaults open in their corresponding fields', function () {
    $product = Product::factory()->create([
        'product_type' => 'frame',
        'default_variant_attributes' => [
            'lens_width' => 52,
            'bridge' => 18,
            'finish' => 'Matte',
        ],
    ]);

    Livewire::actingAs($this->user)
        ->test(EditProduct::class, ['record' => $product->id])
        ->assertSet('data.frame_default_attributes.lens_width', 52)
        ->assertSet('data.frame_default_attributes.bridge', 18)
        ->assertSet('data.frame_other_details.0.key', 'finish')
        ->assertSet('data.frame_other_details.0.value', 'Matte');
});

test('frame dimensions table column shows for frame products', function () {
    $product = Product::factory()->create(['product_type' => 'frame']);
    ProductVariant::factory()->for($product)->create([
        'attributes' => ['bridge' => 18, 'temple' => 140, 'lens_width' => 52],
    ]);

    Livewire::actingAs($this->user)
        ->test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
        ->assertSee('Dimensions')
        ->assertTableColumnDoesNotExist('ar_validation_error');
});

test('frame variant edit form saves frame dimension fields', function () {
    $product = Product::factory()->create(['product_type' => 'frame']);
    $variant = ProductVariant::factory()->for($product)->create([
        'attributes' => ['bridge' => 18, 'temple' => 140, 'lens_width' => 52, 'color' => 'Black', 'material' => 'Acetate'],
    ]);

    Livewire::actingAs($this->user)
        ->test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
        ->callAction(TestAction::make('edit')->table($variant), [
            'name' => $variant->name,
            'price' => $variant->price,
            'attributes' => [
                'bridge' => 20,
                'temple' => 145,
                'lens_width' => 55,
                'color' => 'Tortoise',
                'material' => 'Metal',
            ],
        ])
        ->assertHasNoActionErrors();

    $variant->refresh();
    expect($variant->attributes['bridge'])->toBe(20);
    expect($variant->attributes['temple'])->toBe(145);
    expect($variant->attributes['lens_width'])->toBe(55);
    expect($variant->attributes['color'])->toBe('Tortoise');
    expect($variant->attributes['material'])->toBe('Metal');
});

test('contact lens variant edit form saves contact lens parameter fields', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->for($product)->create([
        'attributes' => ['power' => '-2.00', 'base_curve' => 8.4, 'diameter' => 14, 'pack_size' => 6],
    ]);

    Livewire::actingAs($this->user)
        ->test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
        ->callAction(TestAction::make('edit')->table($variant), [
            'name' => $variant->name,
            'price' => $variant->price,
            'attributes' => [
                'power' => '-3.00',
                'base_curve' => 8.6,
                'diameter' => 14.5,
                'pack_size' => 12,
            ],
        ])
        ->assertHasNoActionErrors();

    $variant->refresh();
    expect($variant->attributes['power'])->toBe('-3.00');
    expect($variant->attributes['base_curve'])->toBe(8.6);
    expect($variant->attributes['diameter'])->toBe(14.5);
    expect($variant->attributes['pack_size'])->toBe(12);
});

test('variant price inputs reject negative values and render decimal constraints without spinner controls', function () {
    $product = Product::factory()->create(['product_type' => 'frame']);
    $variant = ProductVariant::factory()->for($product)->create();

    $component = Livewire::actingAs($this->user)
        ->test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
        ->mountTableAction('edit', $variant);

    expect($component->html())
        ->toContain('price-input')
        ->toContain('min="0"')
        ->toContain('step="0.01"')
        ->toContain('type="number"');

    Livewire::actingAs($this->user)
        ->test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
        ->callTableAction('adjustPrice', $variant, [
            'price' => -0.01,
            'compare_at_price' => null,
            'cost_price' => null,
        ])
        ->assertHasTableActionErrors(['price' => 'min']);

    Livewire::actingAs($this->user)
        ->test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
        ->callTableAction('adjustPrice', $variant, [
            'price' => 500.25,
            'compare_at_price' => null,
            'cost_price' => null,
        ])
        ->assertHasNoTableActionErrors();

    expect((float) $variant->fresh()->price)->toBe(500.25);
});

test('inline product variant price inputs use non-negative decimal constraints', function () {
    $component = Livewire::actingAs($this->user)
        ->test(CreateProduct::class);

    $variantsSection = $component->instance()->form->getComponents()[1];
    $repeater = $variantsSection->getDefaultChildComponents()[0];
    $priceInputs = collect($repeater->getDefaultChildComponents())
        ->filter(fn ($field): bool => in_array($field->getName(), [
            'price',
            'compare_at_price',
            'cost_price',
        ], true));

    expect($repeater)
        ->toBeInstanceOf(Repeater::class)
        ->and($priceInputs)
        ->toHaveCount(3);

    foreach ($priceInputs as $priceInput) {
        expect($priceInput)
            ->toBeInstanceOf(TextInput::class)
            ->and($priceInput->getMinValue())
            ->toBe(0)
            ->and($priceInput->getStep())
            ->toBe(0.01)
            ->and($priceInput->getExtraInputAttributes())
            ->toMatchArray(['class' => 'price-input']);
    }
});
