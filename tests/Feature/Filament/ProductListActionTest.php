<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Widgets\ProductStatsWidget;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Enums\FontWeight;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the product list exposes new product in the page header', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(ListProducts::class)
        ->assertActionVisible('create')
        ->assertActionHasIcon('create', 'heroicon-o-plus-circle')
        ->assertActionExists('create', function (Action $action): bool {
            return $action->isButton()
                && $action->getLabel() === 'New product'
                && $action->getTooltip() === 'New product';
        })
        ->assertTableActionDoesNotExist('create');
});

test('the product list sorts newest products first by default', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    $component = Livewire::test(ListProducts::class);
    $table = $component->instance()->getTable();

    expect($table->getDefaultSortColumn())->toBe('created_at')
        ->and($table->getDefaultSortDirection())->toBe('desc');
});

test('the product list keeps created and updated timestamps optional', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    $table = Livewire::test(ListProducts::class)->instance()->getTable();

    expect($table->getColumn('created_at')->isToggleable())->toBeTrue()
        ->and($table->getColumn('created_at')->isToggledHiddenByDefault())->toBeTrue()
        ->and($table->getColumn('updated_at')->isToggleable())->toBeTrue()
        ->and($table->getColumn('updated_at')->isToggledHiddenByDefault())->toBeTrue();
});

test('the product list only emphasizes the product name', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    $table = Livewire::test(ListProducts::class)->instance()->getTable();

    expect($table->getColumn('name')->getWeight())->toBe(FontWeight::Bold)
        ->and($table->getColumn('brand.name')->getWeight())->toBeNull()
        ->and($table->getColumn('category.name')->getWeight())->toBeNull();
});

test('product stock KPIs use active inventory semantics', function () {
    $activeProduct = Product::factory()->create();

    ProductVariant::factory()->for($activeProduct)->create([
        'stock_quantity' => 2,
        'low_stock_threshold' => 5,
    ]);
    ProductVariant::factory()->for($activeProduct)->create([
        'stock_quantity' => 0,
        'low_stock_threshold' => 5,
    ]);
    ProductVariant::factory()->for($activeProduct)->create([
        'is_active' => false,
        'stock_quantity' => 1,
        'low_stock_threshold' => 5,
    ]);

    $inactiveProduct = Product::factory()->inactive()->create();

    ProductVariant::factory()->for($inactiveProduct)->create([
        'stock_quantity' => 1,
        'low_stock_threshold' => 5,
    ]);
    ProductVariant::factory()->for($inactiveProduct)->create([
        'stock_quantity' => 0,
        'low_stock_threshold' => 5,
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    $widget = Livewire::test(ProductStatsWidget::class)->instance();
    $stats = collect((fn (): array => $this->getStats())->call($widget))->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats->get('Low Stock Variants')?->getValue())->toBe('2')
        ->and($stats->get('Out of Stock')?->getValue())->toBe('1');
});
