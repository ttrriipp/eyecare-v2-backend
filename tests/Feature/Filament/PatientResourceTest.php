<?php

use App\Filament\Resources\Patients\Pages\CreatePatient;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can list patients', function () {
    $staff = User::factory()->staff()->create();
    $patients = Patient::factory()->count(3)->create();

    $this->actingAs($staff);

    Livewire::test(ListPatients::class)
        ->assertCanSeeTableRecords($patients);
});

test('patient list only shows patient records', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $anotherStaff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(ListPatients::class)
        ->assertCanSeeTableRecords([$patient])
        ->assertCanNotSeeTableRecords([$anotherStaff->patient]);
});

test('patients cannot access the patients resource', function () {
    $patient = User::factory()->patient()->create();

    $this->actingAs($patient);

    $this->get('/admin/patients')->assertForbidden();
});

test('staff can view a patient profile', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();

    $this->actingAs($staff);

    $this->get("/admin/patients/{$patient->id}/edit")
        ->assertSuccessful();
});

test('staff can create an account-less patient', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreatePatient::class)
        ->fillForm([
            'full_name' => 'New Walk-in Patient',
            'phone' => '09171234567',
            'gender' => 'female',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('patients', [
        'full_name' => 'New Walk-in Patient',
        'phone' => '09171234567',
        'gender' => 'female',
        'user_id' => null,
    ]);
});

test('staff can update patient name and phone', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditPatient::class, ['record' => $patient->getRouteKey()])
        ->fillForm(['full_name' => 'Updated Name', 'phone' => '09171234567'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($patient->fresh()->full_name)->toBe('Updated Name');
});

test('staff can update patient address', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditPatient::class, ['record' => $patient->getRouteKey()])
        ->fillForm(['address' => '123 Rizal St, Quezon City'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($patient->fresh()->address)->toBe('123 Rizal St, Quezon City');
});

test('no visits yet filter shows only patients without appointments', function () {
    $staff = User::factory()->staff()->create();
    $patientWithVisit = Patient::factory()->linked()->create();
    $patientWithoutVisit = Patient::factory()->create();

    Appointment::factory()->create(['customer_id' => $patientWithVisit->user_id]);

    $this->actingAs($staff);

    Livewire::test(ListPatients::class)
        ->filterTable('no_visits')
        ->assertCanSeeTableRecords([$patientWithoutVisit])
        ->assertCanNotSeeTableRecords([$patientWithVisit]);
});

test('patient table shows correct orders count and last visit date', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->linked()->create();

    Order::factory()->count(2)->create(['customer_id' => $patient->user_id]);
    Appointment::factory()->create([
        'customer_id' => $patient->user_id,
        'scheduled_at' => now()->subDays(10),
    ]);
    $latestAppointment = Appointment::factory()->create([
        'customer_id' => $patient->user_id,
        'scheduled_at' => now()->subDay(),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListPatients::class)
        ->assertTableColumnStateSet('orders_count', 2, $patient)
        ->assertTableColumnStateSet('last_visit', $latestAppointment->scheduled_at, $patient);
});
