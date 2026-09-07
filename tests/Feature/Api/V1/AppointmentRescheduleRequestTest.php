<?php

use App\Enums\AppointmentStatusName;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleRequest;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-07 08:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(ClinicHoursSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{account: User, appointment: Appointment}
 */
function createApiRescheduleRequestContext(): array
{
    $account = User::factory()->patient()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointmentType = AppointmentType::factory()->create(['duration_minutes' => 30]);
    $appointment = Appointment::factory()->create([
        'patient_id' => $account->patient->id,
        'appointment_type_id' => $appointmentType->id,
        'optometrist_id' => $optometrist->id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-09-10 10:00:00',
        'appointment_status_id' => AppointmentStatus::query()
            ->where('name', AppointmentStatusName::Scheduled->value)
            ->value('id'),
    ]);

    return [
        'account' => $account,
        'appointment' => $appointment,
    ];
}

function apiRescheduleRequestPayload(array $overrides = []): array
{
    return array_merge([
        'requested_scheduled_at' => '2026-09-09T10:00:00+08:00',
        'alternative_scheduled_times' => [
            '2026-09-11T10:00:00+08:00',
        ],
        'reason_details' => 'Work schedule changed.',
    ], $overrides);
}

test('patient can create a reschedule request without changing the appointment', function (): void {
    ['account' => $account, 'appointment' => $appointment] = createApiRescheduleRequestContext();

    $this->actingAs($account)
        ->postJson("/api/v1/appointments/{$appointment->id}/reschedule-requests", apiRescheduleRequestPayload())
        ->assertCreated()
        ->assertJsonPath('data.appointment_id', $appointment->id)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.current_scheduled_at', '2026-09-10T10:00:00+08:00')
        ->assertJsonPath('data.requested_scheduled_at', '2026-09-09T10:00:00+08:00')
        ->assertJsonMissingPath('data.reason_details');

    expect($appointment->fresh()->scheduled_at->toDateTimeString())->toBe('2026-09-10 10:00:00')
        ->and(AppointmentRescheduleRequest::query()->count())->toBe(1);
});

test('patient can list and view only their own patient-safe request history', function (): void {
    ['account' => $account, 'appointment' => $appointment] = createApiRescheduleRequestContext();

    $this->actingAs($account)
        ->postJson("/api/v1/appointments/{$appointment->id}/reschedule-requests", apiRescheduleRequestPayload())
        ->assertCreated();

    $rescheduleRequest = AppointmentRescheduleRequest::query()->sole();

    $this->actingAs($account)
        ->getJson('/api/v1/appointment-reschedule-requests')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $rescheduleRequest->id)
        ->assertJsonMissingPath('data.0.reason_details')
        ->assertJsonStructure(['links', 'meta']);

    $this->actingAs($account)
        ->getJson("/api/v1/appointment-reschedule-requests/{$rescheduleRequest->id}")
        ->assertOk()
        ->assertJsonPath('data.reason_details', 'Work schedule changed.');

    $otherAccount = User::factory()->patient()->create();

    $this->actingAs($otherAccount)
        ->getJson("/api/v1/appointment-reschedule-requests/{$rescheduleRequest->id}")
        ->assertNotFound();
});

test('request validation rejects unknown, duplicate, and past preferences', function (): void {
    ['account' => $account, 'appointment' => $appointment] = createApiRescheduleRequestContext();

    $this->actingAs($account)
        ->postJson(
            "/api/v1/appointments/{$appointment->id}/reschedule-requests",
            apiRescheduleRequestPayload(['unexpected' => true]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['unexpected']);

    $this->actingAs($account)
        ->postJson(
            "/api/v1/appointments/{$appointment->id}/reschedule-requests",
            apiRescheduleRequestPayload([
                'requested_scheduled_at' => '2026-09-09T10:00:00+08:00',
                'alternative_scheduled_times' => ['2026-09-09T10:00:00+08:00'],
            ]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alternative_scheduled_times.0']);

    $this->actingAs($account)
        ->postJson(
            "/api/v1/appointments/{$appointment->id}/reschedule-requests",
            apiRescheduleRequestPayload([
                'requested_scheduled_at' => '2026-09-06T10:00:00+08:00',
                'alternative_scheduled_times' => [],
            ]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['requested_scheduled_at']);

    expect(AppointmentRescheduleRequest::query()->count())->toBe(0);
});

test('appointment ownership and active patient linking are enforced', function (): void {
    ['appointment' => $appointment] = createApiRescheduleRequestContext();
    $otherAccount = User::factory()->patient()->create();

    $this->actingAs($otherAccount)
        ->postJson("/api/v1/appointments/{$appointment->id}/reschedule-requests", apiRescheduleRequestPayload())
        ->assertNotFound();

    $unlinkedAccount = User::factory()->create();

    $this->actingAs($unlinkedAccount)
        ->getJson('/api/v1/appointment-reschedule-requests')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ACTIVE_PATIENT_LINK_REQUIRED');
});

test('submission state conflicts return stable API error codes', function (): void {
    ['account' => $account, 'appointment' => $appointment] = createApiRescheduleRequestContext();
    $endpoint = "/api/v1/appointments/{$appointment->id}/reschedule-requests";

    $this->actingAs($account)
        ->postJson($endpoint, apiRescheduleRequestPayload())
        ->assertCreated();

    $this->actingAs($account)
        ->postJson($endpoint, apiRescheduleRequestPayload([
            'requested_scheduled_at' => '2026-09-12T10:00:00+08:00',
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'RESCHEDULE_REQUEST_ALREADY_PENDING');

    $this->assertDatabaseCount('appointment_reschedule_requests', 1);
});
