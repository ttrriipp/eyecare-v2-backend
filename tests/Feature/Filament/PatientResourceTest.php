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

    $component = Livewire::test(ListPatients::class)
        ->assertCanSeeTableRecords([$patient])
        ->assertTableColumnExists('gender')
        ->assertTableColumnDoesNotExist('identity_review_required')
        ->assertTableColumnFormattedStateSet('gender', 'Female', record: $patient);

    expect($component->instance()->getTable()->getFilter('identity_review_required'))->toBeNull();
});
