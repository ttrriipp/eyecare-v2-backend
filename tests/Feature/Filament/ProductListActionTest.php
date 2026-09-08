<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Enums\FontWeight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the product list exposes new product in the table toolbar', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(ListProducts::class)
        ->assertTableActionVisible('create')
        ->assertTableActionHasIcon('create', 'heroicon-o-plus-circle')
        ->assertTableActionExists('create', function (Action $action): bool {
            return $action->isButton()
                && $action->getLabel() === 'New product'
                && $action->getTooltip() === 'New product'
                && in_array($action, $action->getTable()?->getToolbarActions() ?? [], true);
        });
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
