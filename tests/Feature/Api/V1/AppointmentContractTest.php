<?php

use App\Enums\AppointmentRequestKind;
use App\Enums\AppointmentRequestStatus;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
});

test('appointments list is paginated', function () {
    $user = User::factory()->patient()->create();
    Appointment::factory()->count(20)->create(['patient_id' => $user->patient->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

test('current appointment journey returns a pending request with alternatives', function () {
    $user = User::factory()->create();
    $appointmentRequest = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'scheduled_at' => now()->addDays(2),
        'alternative_scheduled_times' => [
            now()->addDays(3)->setTime(10, 0)->toISOString(),
            now()->addDays(4)->setTime(14, 0)->toISOString(),
        ],
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointment-requests/current')
        ->assertOk()
        ->assertJsonPath('data.kind', 'pending_request')
        ->assertJsonPath('data.request.id', $appointmentRequest->id)
        ->assertJsonPath('data.request.status', AppointmentRequestStatus::Pending->value)
        ->assertJsonPath('data.request.alternative_scheduled_times.0', $appointmentRequest->alternative_scheduled_times[0])
        ->assertJsonPath('data.request.alternative_scheduled_times.1', $appointmentRequest->alternative_scheduled_times[1]);
});

test('current appointment journey returns a confirmed appointment and its original request', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'scheduled_at' => now()->addDays(2),
    ]);
    $originalRequest = AppointmentRequest::factory()->accepted()->create([
        'user_id' => $user->id,
        'patient_id' => $user->patient->id,
        'request_type' => AppointmentRequestKind::New,
        'appointment_type_id' => $appointment->appointment_type_id,
        'appointment_id' => $appointment->id,
        'scheduled_at' => now()->addDays(2),
        'alternative_scheduled_times' => [
            now()->addDays(3)->setTime(10, 0)->toISOString(),
        ],
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointment-requests/current')
        ->assertOk()
        ->assertJsonPath('data.kind', 'appointment')
        ->assertJsonPath('data.appointment.id', $appointment->id)
        ->assertJsonPath('data.appointment.status', 'scheduled')
        ->assertJsonPath('data.original_request.id', $originalRequest->id)
        ->assertJsonPath('data.original_request.request_type', AppointmentRequestKind::New->value)
        ->assertJsonPath('data.original_request.alternative_scheduled_times.0', $originalRequest->alternative_scheduled_times[0])
        ->assertJsonPath('data.pending_reschedule', null);
});

test('current appointment journey keeps the appointment primary while a time change is pending', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'scheduled_at' => now()->addDays(2),
    ]);
    $originalRequest = AppointmentRequest::factory()->accepted()->create([
        'user_id' => $user->id,
        'patient_id' => $user->patient->id,
        'request_type' => AppointmentRequestKind::New,
        'appointment_type_id' => $appointment->appointment_type_id,
        'appointment_id' => $appointment->id,
        'scheduled_at' => $appointment->scheduled_at,
    ]);
    $rescheduleRequest = AppointmentRequest::factory()
        ->rebookingFor($appointment)
        ->create([
            'user_id' => $user->id,
            'alternative_scheduled_times' => [
                now()->addDays(4)->setTime(11, 0)->toISOString(),
            ],
        ]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointment-requests/current')
        ->assertOk()
        ->assertJsonPath('data.kind', 'appointment')
        ->assertJsonPath('data.appointment.id', $appointment->id)
        ->assertJsonPath('data.appointment.scheduled_at', $appointment->scheduled_at->toISOString())
        ->assertJsonPath('data.original_request.id', $originalRequest->id)
        ->assertJsonPath('data.pending_reschedule.id', $rescheduleRequest->id)
        ->assertJsonPath('data.pending_reschedule.request_type', AppointmentRequestKind::Reschedule->value)
        ->assertJsonPath('data.pending_reschedule.alternative_scheduled_times.0', $rescheduleRequest->alternative_scheduled_times[0]);
});

test('current appointment journey returns none when no active booking exists', function () {
    $user = User::factory()->patient()->create();
    Appointment::factory()->fulfilled()->create([
        'patient_id' => $user->patient->id,
        'scheduled_at' => now()->subDays(2),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointment-requests/current')
        ->assertOk()
        ->assertJsonPath('data.kind', 'none');
});

test('appointments can be filtered to history', function () {
    $user = User::factory()->patient()->create();
    $currentAppointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'scheduled_at' => now()->addDays(2),
    ]);
    $fulfilledAppointment = Appointment::factory()->fulfilled()->create([
        'patient_id' => $user->patient->id,
        'scheduled_at' => now()->subDays(2),
    ]);
    $cancelledAppointment = Appointment::factory()->cancelled()->create([
        'patient_id' => $user->patient->id,
        'scheduled_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/appointments?filter=history')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toEqualCanonicalizing([$fulfilledAppointment->id, $cancelledAppointment->id])
        ->not->toContain($currentAppointment->id);
});

test('appointment resources serialize for patient accounts', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/appointments/{$appointment->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $appointment->id);
});

test('every appointment is linked-patient scoped', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    Appointment::factory()->count(3)->create(['patient_id' => $userA->patient->id]);
    Appointment::factory()->count(2)->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA)
        ->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonPath('meta.total', 3);
});

test('patient resource excludes staff notes', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'staff_notes' => 'Internal note',
        'contact_notes' => 'Patient note',
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/appointments/{$appointment->id}")
        ->assertOk()
        ->assertJsonMissing(['staff_notes' => 'Internal note'])
        ->assertJsonPath('data.contact_notes', 'Patient note');
});

test('patient resource includes reason for visit', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'reason_for_visit' => 'Blurry vision when reading.',
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/appointments/{$appointment->id}")
        ->assertOk()
        ->assertJsonPath('data.reason_for_visit', 'Blurry vision when reading.');
});

test('patient resource excludes optometrist id', function () {
    $user = User::factory()->patient()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'optometrist_id' => $optometrist->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/appointments/{$appointment->id}")
        ->assertOk();

    $optometristData = $response->json('data.assigned_optometrist');
    expect($optometristData)->not->toHaveKey('id')
        ->and($optometristData)->toHaveKey('name');
});

test('cross-patient substitution returns error', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    $appointmentB = Appointment::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA)
        ->getJson("/api/v1/appointments/{$appointmentB->id}")
        ->assertNotFound();
});
