<?php

use App\Actions\Appointments\SubmitAppointmentRescheduleRequest;
use App\Enums\AppointmentRescheduleRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Exceptions\AppointmentRescheduleRequestStateException;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleRequest;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

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
 * @return array{account: User, appointment: Appointment, optometrist: User}
 */
function createRescheduleSubmissionContext(): array
{
    $account = User::factory()->patient()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointmentType = AppointmentType::factory()->create([
        'duration_minutes' => 30,
    ]);
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
        'optometrist' => $optometrist,
    ];
}

function submitRescheduleRequest(
    User $account,
    Appointment $appointment,
    string $requestedAt = '2026-09-09 10:00:00',
    array $alternatives = [],
): AppointmentRescheduleRequest {
    return app(SubmitAppointmentRescheduleRequest::class)->handle(
        account: $account,
        appointment: $appointment,
        requestedScheduledAt: Carbon::parse($requestedAt, config('app.timezone')),
        alternativeScheduledTimes: array_map(
            fn (string $alternative): Carbon => Carbon::parse($alternative, config('app.timezone')),
            $alternatives,
        ),
        reasonDetails: 'I have a scheduling conflict.',
    );
}

test('submitting a valid request snapshots the appointment without moving it', function (): void {
    ['account' => $account, 'appointment' => $appointment] = createRescheduleSubmissionContext();

    $request = submitRescheduleRequest(
        account: $account,
        appointment: $appointment,
        alternatives: ['2026-09-11 10:00:00', '2026-09-12 10:00:00'],
    );
    $audit = AuditLog::query()
        ->where('action', 'appointment_reschedule_request.submitted')
        ->sole();

    expect($request->status)->toBe(AppointmentRescheduleRequestStatus::Pending)
        ->and($request->appointment_id)->toBe($appointment->id)
        ->and($request->patient_id)->toBe($account->patient->id)
        ->and($request->alternative_scheduled_times)->toBe([
            '2026-09-11T10:00:00+08:00',
            '2026-09-12T10:00:00+08:00',
        ])
        ->and($request->expires_at->toDateTimeString())->toBe('2026-09-10 10:00:00')
        ->and($appointment->fresh()->scheduled_at->toDateTimeString())->toBe('2026-09-10 10:00:00')
        ->and(AuditLog::query()->where('action', 'appointment_reschedule_request.submitted')->count())->toBe(1)
        ->and($audit->metadata)->not->toHaveKey('reason_details')
        ->and($audit->metadata)->not->toHaveKey('reason');
});

test('a second effective pending request is rejected without additional writes', function (): void {
    ['account' => $account, 'appointment' => $appointment] = createRescheduleSubmissionContext();

    submitRescheduleRequest($account, $appointment);

    $exception = null;

    try {
        submitRescheduleRequest($account, $appointment, '2026-09-11 10:00:00');
    } catch (AppointmentRescheduleRequestStateException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(AppointmentRescheduleRequestStateException::class)
        ->and($exception?->errorCode)->toBe('RESCHEDULE_REQUEST_ALREADY_PENDING')
        ->and(AppointmentRescheduleRequest::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'appointment_reschedule_request.submitted')->count())->toBe(1);
});

test('an expired pending request no longer blocks a new submission', function (): void {
    ['account' => $account, 'appointment' => $appointment] = createRescheduleSubmissionContext();

    $expired = AppointmentRescheduleRequest::factory()->create([
        'appointment_id' => $appointment->id,
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'current_scheduled_at' => $appointment->scheduled_at,
        'expires_at' => now()->subMinute(),
    ]);

    $request = submitRescheduleRequest($account, $appointment, '2026-09-11 10:00:00');

    expect($request->exists)->toBeTrue()
        ->and($expired->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Expired)
        ->and(AppointmentRescheduleRequest::query()->count())->toBe(2);
});

test('ownership and appointment state are validated before creating a request', function (): void {
    ['account' => $account, 'appointment' => $appointment] = createRescheduleSubmissionContext();
    $otherAccount = User::factory()->patient()->create();

    expect(fn (): AppointmentRescheduleRequest => submitRescheduleRequest($otherAccount, $appointment))
        ->toThrow(ValidationException::class);

    $appointment->update([
        'appointment_status_id' => AppointmentStatus::query()
            ->where('name', AppointmentStatusName::Cancelled->value)
            ->firstOrFail()
            ->id,
    ]);

    expect(fn (): AppointmentRescheduleRequest => submitRescheduleRequest($account, $appointment))
        ->toThrow(AppointmentRescheduleRequestStateException::class);

    expect(AppointmentRescheduleRequest::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'appointment_reschedule_request.submitted')->count())->toBe(0);
});

test('duplicate or unavailable preferences fail without creating a request', function (): void {
    ['account' => $account, 'appointment' => $appointment, 'optometrist' => $optometrist] = createRescheduleSubmissionContext();

    expect(fn (): AppointmentRescheduleRequest => submitRescheduleRequest(
        $account,
        $appointment,
        alternatives: ['2026-09-09 10:00:00'],
    ))->toThrow(ValidationException::class);

    Appointment::factory()->create([
        'optometrist_id' => $optometrist->id,
        'scheduled_at' => '2026-09-09 10:00:00',
        'duration_minutes' => 30,
    ]);

    $exception = null;

    try {
        submitRescheduleRequest($account, $appointment);
    } catch (AppointmentRescheduleRequestStateException $caught) {
        $exception = $caught;
    }

    expect($exception?->errorCode)->toBe('SLOT_UNAVAILABLE')
        ->and(AppointmentRescheduleRequest::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'appointment_reschedule_request.submitted')->count())->toBe(0);
});

test('reschedule state exceptions render the stable API error envelope', function (): void {
    Route::post('/api/v1/test-reschedule-request-state', function (): never {
        throw AppointmentRescheduleRequestStateException::alreadyPending();
    });

    $this->postJson('/api/v1/test-reschedule-request-state')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'RESCHEDULE_REQUEST_ALREADY_PENDING')
        ->assertJsonPath('error.message', 'This appointment already has a pending reschedule request.');
});
