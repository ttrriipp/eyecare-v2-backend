<?php

use App\Filament\Resources\Prescriptions\Pages\CreatePrescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('prescription form omits unsupported prism and base fields', function () {
    $optometrist = User::factory()->admin()->optometrist()->create();

    $this->actingAs($optometrist);

    Livewire::test(CreatePrescription::class)
        ->assertFormFieldExists('od_sphere')
        ->assertFormFieldExists('os_sphere')
        ->assertFormFieldDoesNotExist('show_prism_base')
        ->assertFormFieldDoesNotExist('od_prism')
        ->assertFormFieldDoesNotExist('od_base')
        ->assertFormFieldDoesNotExist('os_prism')
        ->assertFormFieldDoesNotExist('os_base');
});
