<?php

use App\Enums\AppointmentRequestKind;
use App\Enums\AppointmentRequestStatus;
use App\Enums\AuditEvent;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(ClinicHoursSeeder::class);
});

afterEach(fn (): mixed => Carbon::setTestNow());

/**
 * @param  array<string, mixed>  $overrides
 */
function appointmentRequestForScheduleUpdate(User $user, array $overrides = []): AppointmentRequest
{
    $appointmentType = AppointmentType::factory()->create([
        'duration_minutes' => 45,
    ]);

    return AppointmentRequest::factory()->create(array_merge([
        'user_id' => $user->id,
        'patient_id' => $user->patient?->id,
        'appointment_type_id' => $appointmentType->id,
        'request_type' => AppointmentRequestKind::New,
        'scheduled_at' => '2026-07-13 10:00:00',
        'alternative_scheduled_times' => ['2026-07-13T11:00:00+08:00'],
        'provisional_duration_minutes' => 45,
        'encrypted_reason_for_visit' => 'Blurred vision',
        'encrypted_referring_source' => 'Clinic referral desk',
        'encrypted_identity_snapshot' => [
            'first_name' => 'Liza',
            'last_name' => 'Mendoza',
        ],
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => '2026-07-13 11:00:00',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scheduleUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'scheduled_at' => '2026-07-14T10:30:00+08:00',
        'alternative_scheduled_times' => [
            '2026-07-14T11:30:00+08:00',
        ],
    ], $overrides);
}

test('an owner can update a pending request without changing its identity or booking context', function (): void {
    $user = User::factory()->patient()->create();
    $request = appointmentRequestForScheduleUpdate($user);
    $requestNumber = $request->request_number;
    $createdAt = $request->created_at;

    $this->actingAs($user)
        ->patchJson("/api/v1/appointment-requests/{$request->id}", scheduleUpdatePayload())
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'request_number', 'request_type', 'status', 'scheduled_at', 'alternative_scheduled_times', 'expires_at']])
        ->assertJsonPath('data.id', $request->id)
        ->assertJsonPath('data.request_number', $requestNumber)
        ->assertJsonPath('data.request_type', AppointmentRequestKind::New->value)
        ->assertJsonPath('data.status', AppointmentRequestStatus::Pending->value);

    $updated = $request->fresh();

    expect($updated->id)->toBe($request->id)
        ->and($updated->request_number)->toBe($requestNumber)
        ->and($updated->appointment_type_id)->toBe($request->appointment_type_id)
        ->and($updated->provisional_duration_minutes)->toBe(45)
        ->and($updated->patient_id)->toBe($request->patient_id)
        ->and($updated->encrypted_reason_for_visit)->toBe('Blurred vision')
        ->and($updated->encrypted_referring_source)->toBe('Clinic referral desk')
        ->and($updated->encrypted_identity_snapshot)->toBe([
            'first_name' => 'Liza',
            'last_name' => 'Mendoza',
        ])
        ->and($updated->scheduled_at->equalTo(Carbon::parse('2026-07-14T10:30:00+08:00')))->toBeTrue()
        ->and($updated->alternative_scheduled_times)->toBe(['2026-07-14T11:30:00+08:00'])
        ->and($updated->expires_at->equalTo(Carbon::parse('2026-07-14T11:30:00+08:00')))->toBeTrue()
        ->and($updated->created_at->equalTo($createdAt))->toBeTrue();

    $audit = DB::table('audit_logs')
        ->where('subject_type', $request->getMorphClass())
        ->where('subject_id', $request->id)
        ->where('action', AuditEvent::AppointmentRequestScheduleUpdated->value)
        ->first();

    expect($audit)->not->toBeNull();
});

test('a new request uses its stored duration when validating the replacement schedule', function (): void {
    $user = User::factory()->patient()->create();
    $appointmentType = AppointmentType::factory()->create(['duration_minutes' => 45]);
    $request = appointmentRequestForScheduleUpdate($user, [
        'appointment_type_id' => $appointmentType->id,
        'provisional_duration_minutes' => 45,
    ]);

    Appointment::factory()->create([
        'appointment_type_id' => AppointmentType::factory()->create(['duration_minutes' => 30])->id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-14 11:00:00',
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/appointment-requests/{$request->id}", scheduleUpdatePayload([
            'alternative_scheduled_times' => [],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'SLOT_UNAVAILABLE')
        ->assertJsonPath('errors.scheduled_at.0', 'This time slot is no longer available. Please choose another time.');

    expect($request->fresh()->scheduled_at->equalTo(Carbon::parse('2026-07-13 10:00:00')))->toBeTrue();
});

test('a linked rebooking derives duration from its appointment and excludes that appointment', function (): void {
    $user = User::factory()->patient()->create();
    $appointmentType = AppointmentType::factory()->create(['duration_minutes' => 45]);
    $appointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'appointment_type_id' => $appointmentType->id,
        'duration_minutes' => 45,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);
    $request = appointmentRequestForScheduleUpdate($user, [
        'request_type' => AppointmentRequestKind::Reschedule,
        'appointment_type_id' => $appointmentType->id,
        'appointment_id' => $appointment->id,
        'original_scheduled_at' => '2026-07-13 10:00:00',
        'scheduled_at' => '2026-07-14 10:00:00',
        'alternative_scheduled_times' => null,
        'provisional_duration_minutes' => 45,
        'encrypted_reason_for_visit' => null,
        'encrypted_referring_source' => null,
        'encrypted_identity_snapshot' => null,
        'expires_at' => '2026-07-14 10:00:00',
    ]);
    $originalAppointmentTime = $appointment->scheduled_at;

    $this->actingAs($user)
        ->patchJson("/api/v1/appointment-requests/{$request->id}", scheduleUpdatePayload([
            'scheduled_at' => '2026-07-13T10:15:00+08:00',
            'alternative_scheduled_times' => ['2026-07-13T11:30:00+08:00'],
        ]))
        ->assertOk()
        ->assertJsonPath('data.request_type', AppointmentRequestKind::Reschedule->value)
        ->assertJsonPath('data.appointment.id', $appointment->id);

    $updated = $request->fresh();

    expect($updated->appointment_id)->toBe($appointment->id)
        ->and($updated->appointment_type_id)->toBe($appointmentType->id)
        ->and($updated->provisional_duration_minutes)->toBe(45)
        ->and($updated->patient_id)->toBe($user->patient->id)
        ->and($appointment->fresh()->scheduled_at->equalTo($originalAppointmentTime))->toBeTrue();
});

test('a linked rebooking does not use a stale duration snapshot for availability', function (): void {
    $user = User::factory()->patient()->create();
    $appointmentType = AppointmentType::factory()->create(['duration_minutes' => 45]);
    $appointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'appointment_type_id' => $appointmentType->id,
        'duration_minutes' => 45,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);
    $request = appointmentRequestForScheduleUpdate($user, [
        'request_type' => AppointmentRequestKind::Reschedule,
        'appointment_type_id' => $appointmentType->id,
        'appointment_id' => $appointment->id,
        'original_scheduled_at' => '2026-07-13 10:00:00',
        'scheduled_at' => '2026-07-14 10:00:00',
        'alternative_scheduled_times' => null,
        'provisional_duration_minutes' => 30,
        'encrypted_reason_for_visit' => null,
        'encrypted_referring_source' => null,
        'encrypted_identity_snapshot' => null,
        'expires_at' => '2026-07-14 10:00:00',
    ]);

    Appointment::factory()->create([
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-13 12:00:00',
    ]);

    $response = $this->actingAs($user)
        ->patchJson("/api/v1/appointment-requests/{$request->id}", scheduleUpdatePayload([
            'scheduled_at' => '2026-07-13T10:15:00+08:00',
            'alternative_scheduled_times' => ['2026-07-13T11:30:00+08:00'],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'SLOT_UNAVAILABLE');

    expect($response->json('errors'))->toHaveKey('alternative_scheduled_times.0')
        ->and($request->fresh()->scheduled_at->equalTo(Carbon::parse('2026-07-14 10:00:00')))->toBeTrue();
});

test('every submitted replacement time must be available and grid aligned', function (): void {
    $user = User::factory()->patient()->create();
    $request = appointmentRequestForScheduleUpdate($user);

    Appointment::factory()->create([
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-14 11:30:00',
    ]);

    $response = $this->actingAs($user)
        ->patchJson("/api/v1/appointment-requests/{$request->id}", scheduleUpdatePayload())
        ->assertStatus(422)
        ->assertJsonPath('code', 'SLOT_UNAVAILABLE');

    expect($response->json('errors')['alternative_scheduled_times.0'][0])
        ->toBe('This time slot is no longer available. Please choose another time.');

    $offGridRequest = appointmentRequestForScheduleUpdate($user);

    $this->patchJson("/api/v1/appointment-requests/{$offGridRequest->id}", scheduleUpdatePayload([
        'scheduled_at' => '2026-07-14T10:10:00+08:00',
        'alternative_scheduled_times' => [],
    ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'SLOT_UNAVAILABLE');
});

test('only an unexpired pending request can be updated', function (): void {
    $user = User::factory()->patient()->create();

    foreach ([
        AppointmentRequestStatus::Accepted,
        AppointmentRequestStatus::Rejected,
        AppointmentRequestStatus::Cancelled,
        AppointmentRequestStatus::Expired,
    ] as $status) {
        $request = appointmentRequestForScheduleUpdate($user, [
            'status' => $status,
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($user)
            ->patchJson("/api/v1/appointment-requests/{$request->id}", scheduleUpdatePayload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'REQUEST_NOT_RESCHEDULABLE')
            ->assertJsonPath('errors.request.0', 'Only pending appointment requests can be rescheduled.');
    }

    $expiredPending = appointmentRequestForScheduleUpdate($user, [
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->subMinute(),
    ]);

    $this->patchJson("/api/v1/appointment-requests/{$expiredPending->id}", scheduleUpdatePayload())
        ->assertStatus(422)
        ->assertJsonPath('code', 'REQUEST_NOT_RESCHEDULABLE');
});

test('a pending rebooking with a stale linked appointment cannot be updated', function (): void {
    $user = User::factory()->patient()->create();
    $appointmentType = AppointmentType::factory()->create(['duration_minutes' => 45]);
    $appointment = Appointment::factory()->cancelled()->create([
        'patient_id' => $user->patient->id,
        'appointment_type_id' => $appointmentType->id,
        'duration_minutes' => 45,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);
    $request = appointmentRequestForScheduleUpdate($user, [
        'request_type' => AppointmentRequestKind::Reschedule,
        'appointment_type_id' => $appointmentType->id,
        'appointment_id' => $appointment->id,
        'original_scheduled_at' => '2026-07-13 10:00:00',
        'scheduled_at' => '2026-07-14 10:00:00',
        'alternative_scheduled_times' => null,
        'provisional_duration_minutes' => 45,
        'encrypted_reason_for_visit' => null,
        'encrypted_referring_source' => null,
        'encrypted_identity_snapshot' => null,
        'expires_at' => '2026-07-14 10:00:00',
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/appointment-requests/{$request->id}", scheduleUpdatePayload())
        ->assertStatus(422)
        ->assertJsonPath('code', 'REQUEST_NOT_RESCHEDULABLE');
});

test('schedule updates require authentication and ownership', function (): void {
    $owner = User::factory()->patient()->create();
    $request = appointmentRequestForScheduleUpdate($owner);
    $otherUser = User::factory()->patient()->create();

    $this->patchJson("/api/v1/appointment-requests/{$request->id}", scheduleUpdatePayload())
        ->assertUnauthorized();

    $this->actingAs($otherUser)
        ->patchJson("/api/v1/appointment-requests/{$request->id}", scheduleUpdatePayload())
        ->assertNotFound();

    $this->actingAs($owner)
        ->patchJson('/api/v1/appointment-requests/999999', scheduleUpdatePayload())
        ->assertNotFound();
});

test('schedule updates allow no more than two alternatives', function (): void {
    $user = User::factory()->patient()->create();
    $request = appointmentRequestForScheduleUpdate($user);

    $this->actingAs($user)
        ->patchJson("/api/v1/appointment-requests/{$request->id}", scheduleUpdatePayload([
            'alternative_scheduled_times' => [
                '2026-07-14T11:00:00+08:00',
                '2026-07-14T11:30:00+08:00',
                '2026-07-14T12:00:00+08:00',
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alternative_scheduled_times']);
});
