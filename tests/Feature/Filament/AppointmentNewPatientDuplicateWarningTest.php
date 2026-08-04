<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('create appointment form warns about a matching phone number for a new patient', function () {
    $staff = User::factory()->staff()->create();
    $lookupHash = app(CreateContactLookupHash::class);

    $existing = Patient::factory()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'phone' => '+639171234567',
        'phone_lookup_hash' => $lookupHash->forPhone('+639171234567'),
    ]);

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'patient_mode' => 'new',
            'new_patient_phone' => '9171234567',
        ])
        ->assertSee($existing->patient_number);
});

test('create appointment form shows no matches for a new patient with no overlap', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'patient_mode' => 'new',
            'new_patient_phone' => '9998887777',
        ])
        ->assertSee('No matching records yet');
});

test('duplicate warning is hidden in existing patient mode', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm(['patient_mode' => 'existing'])
        ->assertDontSee('No matching records yet');
});
