<?php

use App\Filament\Resources\BillingRecords\Pages\ListBillingRecords;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('billing list uses the unified bill creation flow without a standalone service charge action', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(ListBillingRecords::class)
        ->assertActionExists('createBill')
        ->assertActionDoesNotExist('newServiceCharge');
});
