<?php

use App\Filament\Resources\AppointmentRequests\Pages\ReviewAppointmentRequestSchedule;
use App\Filament\Resources\AppointmentRequests\Pages\ViewAppointmentRequest;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can link an unlinked request to a patient from the detail page', function () {
    $staff = User::factory()->staff()->create();
    $account = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'patient'])->id]);
    $request = AppointmentRequest::factory()->withSnapshot()->create(['patient_id' => null, 'user_id' => $account->id]);
    $patient = Patient::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertActionVisible('linkToPatient')
        ->assertActionDoesNotExist('accept')
        ->callAction('linkToPatient', ['patient_mode' => 'existing', 'patient_id' => $patient->id])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($request->fresh()->patient_id)->toBe($patient->id)
        ->and($patient->fresh()->user_id)->toBe($account->id);
});

test('staff can link a request to a brand-new patient from the detail page', function () {
    $staff = User::factory()->staff()->create();
    $account = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'patient'])->id]);
    $request = AppointmentRequest::factory()->withSnapshot()->create(['patient_id' => null, 'user_id' => $account->id]);

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('linkToPatient', [
            'patient_mode' => 'new',
            'new_patient_first_name' => 'Maria',
            'new_patient_last_name' => 'Santos',
            'new_patient_phone' => '9171234567',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $newPatient = Patient::query()->where('first_name', 'Maria')->where('last_name', 'Santos')->firstOrFail();

    expect($request->fresh()->patient_id)->toBe($newPatient->id)
        ->and($newPatient->user_id)->toBe($account->id);
});

test('accepting a linked request from the detail page does not error and creates an appointment', function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $request = AppointmentRequest::factory()->create([
        'patient_id' => $patient->id,
        'scheduled_at' => now()->addDay()->setTime(10, 0),
    ]);
    $appointmentType = AppointmentType::factory()->create(['duration_minutes' => 30]);
    // AcceptAppointmentRequest re-checks availability against the chosen
    // type's real duration, which needs an optometrist with provider hours
    // covering the slot (auto-created for every weekday).
    $optometrist = User::factory()->optometrist()->create();

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertActionVisible('reviewSchedule')
        ->assertActionDoesNotExist('accept')
        ->assertActionHidden('linkToPatient');

    Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])
        ->set('appointmentTypeId', $appointmentType->id)
        ->set('durationMinutes', 30)
        ->set('optometristId', $optometrist->id)
        ->set('scheduledDate', $request->scheduled_at->toDateString())
        ->set('scheduledTime', $request->scheduled_at->format('H:i'))
        ->call('accept')
        ->assertHasNoErrors()
        ->assertNotified();

    expect($request->fresh()->status->value)->toBe('accepted');
});

test('rejecting a request from the detail page does not error', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('reject', ['reason' => 'Patient no longer needs this slot.'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($request->fresh()->status->value)->toBe('rejected');
});
