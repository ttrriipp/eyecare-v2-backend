<?php

use App\Actions\Appointments\ApproveAppointmentRescheduleRequest;
use App\Enums\AppointmentRescheduleRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Enums\AuditEvent;
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
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
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
 * @return array{request: AppointmentRescheduleRequest, appointment: Appointment, reviewer: User}
 */
function createReviewAppointmentRescheduleContext(): array
{
    $patientAccount = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointmentType = AppointmentType::factory()->create(['duration_minutes' => 30]);
    $scheduledAt = Carbon::parse('2026-09-10 10:00:00');
    $appointment = Appointment::factory()->create([
        'patient_id' => $patientAccount->patient->id,
        'appointment_type_id' => $appointmentType->id,
        'optometrist_id' => $optometrist->id,
        'duration_minutes' => 30,
        'scheduled_at' => $scheduledAt,
        'appointment_status_id' => AppointmentStatus::query()
            ->where('name', AppointmentStatusName::Scheduled->value)
            ->value('id'),
    ]);
    $request = AppointmentRescheduleRequest::factory()->create([
        'appointment_id' => $appointment->id,
        'user_id' => $patientAccount->id,
        'patient_id' => $patientAccount->patient->id,
        'current_scheduled_at' => $scheduledAt,
        'requested_scheduled_at' => '2026-09-09 10:00:00',
        'alternative_scheduled_times' => ['2026-09-11T10:00:00+08:00'],
        'expires_at' => $scheduledAt,
    ]);

    return [
        'request' => $request,
        'appointment' => $appointment,
        'reviewer' => $reviewer,
    ];
}

test('approve moves the appointment once and links one immutable history row', function (): void {
    ['request' => $request, 'appointment' => $appointment, 'reviewer' => $reviewer] = createReviewAppointmentRescheduleContext();

    $approvedRequest = app(ApproveAppointmentRescheduleRequest::class)->handle(
        request: $request,
        selectedScheduledAt: Carbon::parse('2026-09-09 10:00:00'),
        reviewer: $reviewer,
    );

    expect($approvedRequest->status)->toBe(AppointmentRescheduleRequestStatus::Approved)
        ->and($approvedRequest->selected_scheduled_at?->toDateTimeString())->toBe('2026-09-09 10:00:00')
        ->and($approvedRequest->resolved_by_user_id)->toBe($reviewer->id)
        ->and($approvedRequest->appointment_reschedule_id)->not->toBeNull()
        ->and($appointment->fresh()->scheduled_at->toDateTimeString())->toBe('2026-09-09 10:00:00')
        ->and($appointment->reschedules()->count())->toBe(1);

    $history = $approvedRequest->appointmentReschedule()->firstOrFail();

    expect($history->initiated_by)->toBe('patient')
        ->and($history->actor_id)->toBe($reviewer->id)
        ->and($history->new_scheduled_at->toDateTimeString())->toBe('2026-09-09 10:00:00');
});

test('approve rejects a time that was not submitted without writing', function (): void {
    ['request' => $request, 'appointment' => $appointment, 'reviewer' => $reviewer] = createReviewAppointmentRescheduleContext();

    expect(fn () => app(ApproveAppointmentRescheduleRequest::class)->handle(
        request: $request,
        selectedScheduledAt: Carbon::parse('2026-09-12 10:00:00'),
        reviewer: $reviewer,
    ))->toThrow(AppointmentRescheduleRequestStateException::class);

    expect($request->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Pending)
        ->and($appointment->fresh()->scheduled_at->toDateTimeString())->toBe('2026-09-10 10:00:00')
        ->and($appointment->reschedules()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditEvent::AppointmentRescheduleRequestApproved->value)->count())->toBe(0);
});

test('approve rejects a stale appointment snapshot without writing', function (): void {
    ['request' => $request, 'appointment' => $appointment, 'reviewer' => $reviewer] = createReviewAppointmentRescheduleContext();
    $appointment->update(['scheduled_at' => '2026-09-12 10:00:00']);

    expect(fn () => app(ApproveAppointmentRescheduleRequest::class)->handle(
        request: $request,
        selectedScheduledAt: Carbon::parse('2026-09-09 10:00:00'),
        reviewer: $reviewer,
    ))->toThrow(AppointmentRescheduleRequestStateException::class);

    expect($request->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Pending)
        ->and($appointment->fresh()->scheduled_at->toDateTimeString())->toBe('2026-09-12 10:00:00')
        ->and($appointment->reschedules()->count())->toBe(0);
});

test('approve leaves the request unchanged when the selected slot is unavailable', function (): void {
    ['request' => $request, 'appointment' => $appointment, 'reviewer' => $reviewer] = createReviewAppointmentRescheduleContext();
    Appointment::factory()->create([
        'optometrist_id' => $appointment->optometrist_id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-09-09 10:00:00',
    ]);

    expect(fn () => app(ApproveAppointmentRescheduleRequest::class)->handle(
        request: $request,
        selectedScheduledAt: Carbon::parse('2026-09-09 10:00:00'),
        reviewer: $reviewer,
    ))->toThrow(HttpResponseException::class);

    expect($request->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Pending)
        ->and($appointment->fresh()->scheduled_at->toDateTimeString())->toBe('2026-09-10 10:00:00')
        ->and($appointment->reschedules()->count())->toBe(0);
});

test('approve replay returns the same result without duplicate effects', function (): void {
    ['request' => $request, 'appointment' => $appointment, 'reviewer' => $reviewer] = createReviewAppointmentRescheduleContext();
    $action = app(ApproveAppointmentRescheduleRequest::class);

    $approvedRequest = $action->handle(
        request: $request,
        selectedScheduledAt: Carbon::parse('2026-09-09 10:00:00'),
        reviewer: $reviewer,
    );
    $replayedRequest = $action->handle(
        request: $approvedRequest,
        selectedScheduledAt: Carbon::parse('2026-09-09 10:00:00'),
        reviewer: $reviewer,
    );

    expect($replayedRequest->status)->toBe(AppointmentRescheduleRequestStatus::Approved)
        ->and($replayedRequest->appointment_reschedule_id)->toBe($approvedRequest->appointment_reschedule_id)
        ->and($appointment->fresh()->scheduled_at->toDateTimeString())->toBe('2026-09-09 10:00:00')
        ->and($appointment->reschedules()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditEvent::AppointmentRescheduleRequestApproved->value)->count())->toBe(1);
});

test('inactive or non-operational reviewers cannot approve', function (): void {
    ['request' => $request] = createReviewAppointmentRescheduleContext();
    $patient = User::factory()->patient()->create();

    expect(fn () => app(ApproveAppointmentRescheduleRequest::class)->handle(
        request: $request,
        selectedScheduledAt: Carbon::parse('2026-09-09 10:00:00'),
        reviewer: $patient,
    ))->toThrow(ValidationException::class);
});
