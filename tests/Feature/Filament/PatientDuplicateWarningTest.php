<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Filament\Resources\Patients\Pages\CreatePatient;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('create patient form warns about a matching phone number', function () {
    $staff = User::factory()->staff()->create();
    $lookupHash = app(CreateContactLookupHash::class);

    $existing = Patient::factory()->create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'phone' => '+639171234567',
        'phone_lookup_hash' => $lookupHash->forPhone('+639171234567'),
    ]);

    $this->actingAs($staff);

    Livewire::test(CreatePatient::class)
        ->fillForm(['phone' => '9171234567'])
        ->assertSee($existing->patient_number);
});

test('a patient created without an explicit lookup hash is still found by phone and email', function () {
    $staff = User::factory()->staff()->create();

    // No lookup hash passed — matches how the admin Create Patient form actually saves records.
    $existing = Patient::create([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '9171234567',
        'contact_email' => 'ana@example.com',
    ]);

    expect($existing->phone_lookup_hash)->not->toBeNull()
        ->and($existing->contact_email_lookup_hash)->not->toBeNull();

    $this->actingAs($staff);

    Livewire::test(CreatePatient::class)
        ->fillForm(['phone' => '9171234567'])
        ->assertSee($existing->patient_number);

    Livewire::test(CreatePatient::class)
        ->fillForm(['contact_email' => 'ana@example.com'])
        ->assertSee($existing->patient_number);
});

test('create patient form shows no matches for a new phone number', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreatePatient::class)
        ->fillForm(['phone' => '9998887777'])
        ->assertSee('No matches yet');
});
