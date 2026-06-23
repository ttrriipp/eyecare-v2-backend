<?php

use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can list patients', function () {
    $staff = User::factory()->staff()->create();
    $patients = User::factory()->customer()->count(3)->create();

    $this->actingAs($staff);

    Livewire::test(ListPatients::class)
        ->assertCanSeeTableRecords($patients);
});

test('patient list only shows customer-role users', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $anotherStaff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(ListPatients::class)
        ->assertCanSeeTableRecords([$customer])
        ->assertCanNotSeeTableRecords([$anotherStaff]);
});

test('customers cannot access the patients resource', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer);

    $this->get('/admin/patients')->assertForbidden();
});

test('staff can view a patient profile', function () {
    $staff = User::factory()->staff()->create();
    $patient = User::factory()->customer()->create();

    $this->actingAs($staff);

    $this->get("/admin/patients/{$patient->id}/edit")
        ->assertSuccessful();
});

test('staff can update patient name and phone', function () {
    $staff = User::factory()->staff()->create();
    $patient = User::factory()->customer()->create();

    $this->actingAs($staff);

    Livewire::test(EditPatient::class, ['record' => $patient->getRouteKey()])
        ->fillForm(['name' => 'Updated Name', 'phone' => '09171234567'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($patient->fresh()->name)->toBe('Updated Name');
});
