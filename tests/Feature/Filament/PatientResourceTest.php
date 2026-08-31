<?php

use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('patient records table displays patient gender', function (): void {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create(['gender' => 'female']);

    $this->actingAs($staff);

    Livewire::test(ListPatients::class)
        ->assertCanSeeTableRecords([$patient])
        ->assertTableColumnExists('gender')
        ->assertTableColumnFormattedStateSet('gender', 'Female', record: $patient);
});
