<?php

use App\Enums\IntakeStatus;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Appointments\Pages\IntakeForm;
use App\Models\Appointment;
use App\Models\PatientIntake;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
});

test('intake form page is accessible', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(IntakeForm::class, ['record' => $appointment->getRouteKey()])
        ->assertSuccessful();
});

test('intake form pre-fills demographics from patient', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    $component = Livewire::test(IntakeForm::class, ['record' => $appointment->getRouteKey()]);

    expect($component->get('formData.full_name'))->toBe($appointment->patient->full_name);
});

test('staff can save intake draft', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(IntakeForm::class, ['record' => $appointment->getRouteKey()])
        ->set('formData.full_name', 'Juan dela Cruz')
        ->set('formData.chief_complaint', 'Blurred vision')
        ->call('saveDraft');

    $this->assertDatabaseHas('patient_intakes', [
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
        'status' => 'draft',
    ]);

    $intake = PatientIntake::query()
        ->where('appointment_id', $appointment->id)
        ->first();

    expect($intake->full_name)->toBe('Juan dela Cruz')
        ->and($intake->chief_complaint)->toBe('Blurred vision');
});

test('staff can submit intake', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(IntakeForm::class, ['record' => $appointment->getRouteKey()])
        ->set('formData.full_name', 'Juan dela Cruz')
        ->set('formData.chief_complaint', 'Blurred vision')
        ->call('submit');

    $intake = PatientIntake::query()
        ->where('appointment_id', $appointment->id)
        ->first();

    expect($intake->status)->toBe(IntakeStatus::Submitted)
        ->and($intake->submitted_by)->toBe($staff->id)
        ->and($intake->submitted_at)->not->toBeNull();
});

test('submitted intake is read-only on form page', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    PatientIntake::factory()->submitted()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
    ]);

    $this->actingAs($staff);

    Livewire::test(IntakeForm::class, ['record' => $appointment->getRouteKey()])
        ->assertDontSee('Save Draft')
        ->assertDontSee('Submit Intake');
});

test('verified intake is read-only on form page', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    PatientIntake::factory()->verified()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
    ]);

    $this->actingAs($staff);

    Livewire::test(IntakeForm::class, ['record' => $appointment->getRouteKey()])
        ->assertDontSee('Save Draft')
        ->assertDontSee('Submit Intake')
        ->assertSee('Verified');
});

test('optometrist can verify submitted intake', function () {
    $optometrist = User::factory()->optometrist()->create();
    $appointment = Appointment::factory()->create();
    PatientIntake::factory()->submitted()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(IntakeForm::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('Verify Intake')
        ->call('verify');

    $intake = PatientIntake::query()
        ->where('appointment_id', $appointment->id)
        ->first();

    expect($intake->status)->toBe(IntakeStatus::Verified)
        ->and($intake->verified_by)->toBe($optometrist->id);
});

test('non-optometrist does not see verify button', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $appointment = Appointment::factory()->create();
    PatientIntake::factory()->submitted()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
    ]);

    $this->actingAs($staff);

    Livewire::test(IntakeForm::class, ['record' => $appointment->getRouteKey()])
        ->assertDontSee('Verify Intake');
});

test('edit appointment shows intake status card', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('Patient Intake')
        ->assertSee('Not Started');
});

test('edit appointment shows complete intake action for absent intake', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionVisible('completeIntake');
});

test('edit appointment shows complete intake action for draft', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    PatientIntake::factory()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionVisible('completeIntake');
});

test('edit appointment shows review intake action for submitted', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    PatientIntake::factory()->submitted()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionVisible('reviewIntake')
        ->assertActionHidden('completeIntake');
});

test('edit appointment shows view intake action for verified', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    PatientIntake::factory()->verified()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionVisible('viewIntake')
        ->assertActionHidden('completeIntake')
        ->assertActionHidden('reviewIntake');
});

test('check-in shows warning when no verified intake exists', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('Check In');
});

test('intake status shows on edit page sidebar', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('Patient Intake');
});

test('intake form loads existing draft data', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    PatientIntake::factory()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
        'chief_complaint' => 'Existing complaint',
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(IntakeForm::class, ['record' => $appointment->getRouteKey()]);

    expect($component->get('formData.chief_complaint'))->toBe('Existing complaint');
});

test('intake form updates existing draft on save', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    PatientIntake::factory()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
        'chief_complaint' => 'Old complaint',
    ]);

    $this->actingAs($staff);

    Livewire::test(IntakeForm::class, ['record' => $appointment->getRouteKey()])
        ->set('formData.chief_complaint', 'Updated complaint')
        ->call('saveDraft');

    $intake = PatientIntake::query()
        ->where('appointment_id', $appointment->id)
        ->first();

    expect($intake->chief_complaint)->toBe('Updated complaint');
});

test('intake form validates required fields on submit', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(IntakeForm::class, ['record' => $appointment->getRouteKey()])
        ->set('formData.full_name', '')
        ->call('submit')
        ->assertHasErrors(['formData.full_name']);
});
