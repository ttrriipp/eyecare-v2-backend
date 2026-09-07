<?php

use App\Actions\Appointments\CommitAppointmentReschedule;
use App\Actions\Appointments\ExpireAppointmentRescheduleRequests;
use App\Enums\AppointmentRescheduleRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Enums\AuditEvent;
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
 * @return array{request: AppointmentRescheduleRequest, appointment: Appointment}
 */
function createExpireAppointmentRescheduleContext(): array
{
    $account = User::factory()->patient()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointmentType = AppointmentType::factory()->create(['duration_minutes' => 30]);
    $scheduledAt = Carbon::parse('2026-09-10 10:00:00');
    $appointment = Appointment::factory()->create([
        'patient_id' => $account->patient->id,
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
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'current_scheduled_at' => $scheduledAt,
        'requested_scheduled_at' => '2026-09-09 10:00:00',
        'alternative_scheduled_times' => ['2026-09-11T10:00:00+08:00'],
        'expires_at' => '2026-09-08 10:00:00',
    ]);

    return [
        'request' => $request,
        'appointment' => $appointment,
    ];
}

test('expiry persists a stale request once without changing the appointment', function (): void {
    ['request' => $request, 'appointment' => $appointment] = createExpireAppointmentRescheduleContext();
    $scheduledAt = $appointment->fresh()->scheduled_at->toDateTimeString();
    $request->update(['expires_at' => now()->subMinute()]);

    $expired = app(ExpireAppointmentRescheduleRequests::class)->handle();
    $expiredAgain = app(ExpireAppointmentRescheduleRequests::class)->handle();

    expect($expired)->toBe(1)
        ->and($expiredAgain)->toBe(0)
        ->and($request->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Expired)
        ->and($appointment->fresh()->scheduled_at->toDateTimeString())->toBe($scheduledAt)
        ->and($appointment->reschedules()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditEvent::AppointmentRescheduleRequestExpired->value)->count())->toBe(1);
});

test('nonexpired requests remain pending', function (): void {
    ['request' => $request, 'appointment' => $appointment] = createExpireAppointmentRescheduleContext();

    expect(app(ExpireAppointmentRescheduleRequests::class)->handle())->toBe(0)
        ->and($request->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Pending)
        ->and($appointment->reschedules()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditEvent::AppointmentRescheduleRequestExpired->value)->count())->toBe(0);
});

test('terminal appointments expire pending requests without changing history', function (): void {
    ['request' => $request, 'appointment' => $appointment] = createExpireAppointmentRescheduleContext();
    $scheduledAt = $appointment->fresh()->scheduled_at->toDateTimeString();
    $appointment->update([
        'appointment_status_id' => AppointmentStatus::query()
            ->where('name', AppointmentStatusName::Cancelled->value)
            ->value('id'),
    ]);

    expect(app(ExpireAppointmentRescheduleRequests::class)->handle())->toBe(1)
        ->and($request->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Expired)
        ->and($appointment->fresh()->scheduled_at->toDateTimeString())->toBe($scheduledAt)
        ->and($appointment->reschedules()->count())->toBe(0);
});

test('direct staff rescheduling invalidates and expires the old request snapshot', function (): void {
    ['request' => $request, 'appointment' => $appointment] = createExpireAppointmentRescheduleContext();
    $reviewer = User::factory()->staff()->create();

    app(CommitAppointmentReschedule::class)->handle(
        appointment: $appointment,
        scheduledAt: Carbon::parse('2026-09-12 10:00:00'),
        initiator: 'clinic',
        actor: $reviewer,
        reasonCategory: 'schedule_conflict',
    );

    expect(app(ExpireAppointmentRescheduleRequests::class)->handle())->toBe(1)
        ->and($request->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Expired)
        ->and($appointment->fresh()->scheduled_at->toDateTimeString())->toBe('2026-09-12 10:00:00')
        ->and($appointment->reschedules()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditEvent::AppointmentRescheduleRequestExpired->value)->count())->toBe(1);
});

test('scheduled expiry command includes reschedule requests', function (): void {
    ['request' => $request] = createExpireAppointmentRescheduleContext();
    $request->update(['expires_at' => now()->subMinute()]);

    $this->artisan('appointments:expire-requests')
        ->assertSuccessful()
        ->expectsOutputToContain('reschedule request(s)');

    expect($request->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Expired);
});
