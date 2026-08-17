<?php

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->user = User::factory()->staff()->create();
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
        ->assertSee('Dimensions');
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
