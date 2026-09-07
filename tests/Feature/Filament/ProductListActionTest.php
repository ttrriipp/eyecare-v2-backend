<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\User;
use Filament\Actions\Action;
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
