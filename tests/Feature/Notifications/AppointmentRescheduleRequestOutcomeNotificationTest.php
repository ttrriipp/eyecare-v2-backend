<?php

use App\Actions\Appointments\ApproveAppointmentRescheduleRequest;
use App\Actions\Appointments\ExpireAppointmentRescheduleRequests;
use App\Actions\Appointments\RejectAppointmentRescheduleRequest;
use App\Enums\AppointmentRescheduleRequestStatus;
use App\Exceptions\AppointmentRescheduleRequestStateException;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleRequest;
use App\Models\SmsNotification;
use App\Models\User;
use App\Notifications\AppointmentRescheduled;
use App\Notifications\AppointmentRescheduleRequestStatusChanged;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->seed(ClinicHoursSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

afterEach(fn (): mixed => Carbon::setTestNow());

/**
 * @return array{account: User, appointment: Appointment, request: AppointmentRescheduleRequest}
 */
function createOutcomeRequestContext(array $overrides = []): array
{
    $account = User::factory()->patient()->create([
        'first_name' => 'Maria',
        'last_name' => 'Santos',
    ]);
    $optometrist = User::factory()->optometrist()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $account->patient->id,
        'optometrist_id' => $optometrist->id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);
    $request = AppointmentRescheduleRequest::factory()->create(array_merge([
        'appointment_id' => $appointment->id,
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'current_scheduled_at' => $appointment->scheduled_at,
        'requested_scheduled_at' => '2026-07-14 10:00:00',
        'alternative_scheduled_times' => ['2026-07-15 10:00:00'],
        'encrypted_reason_details' => 'I have a private work conflict.',
        'expires_at' => '2026-07-15 12:00:00',
    ], $overrides));

    return compact('account', 'appointment', 'request');
}

function outcomeReviewer(): User
{
    return User::factory()->staff()->create();
}

test('approved requests notify the patient with the final time and one SMS after commit', function (): void {
    ['account' => $account, 'appointment' => $appointment, 'request' => $request] = createOutcomeRequestContext();
    $reviewer = outcomeReviewer();
    $selectedScheduledAt = Carbon::parse('2026-07-14 10:00:00', config('app.timezone'));

    $approvedRequest = app(ApproveAppointmentRescheduleRequest::class)->handle(
        request: $request,
        selectedScheduledAt: $selectedScheduledAt,
        reviewer: $reviewer,
    );

    $notification = $account->fresh()->unreadNotifications->sole();
    $sms = SmsNotification::query()->sole();

    expect($approvedRequest->status)->toBe(AppointmentRescheduleRequestStatus::Approved)
        ->and($notification->type)->toBe(AppointmentRescheduled::class)
        ->and($notification->data['title'])->toBe('Appointment Rescheduled')
        ->and($notification->data['body'])
        ->toContain($appointment->appointment_number)
        ->toContain('Jul 14, 2026 10:00 AM')
        ->not->toContain('private work conflict')
        ->and($sms->event)->toBe('appointment_rescheduled')
        ->and($sms->recipient)->toBe($appointment->patient->phone)
        ->and($sms->message)
        ->toContain('2026-07-14 10:00:00')
        ->not->toContain('private work conflict');

    $replayedRequest = app(ApproveAppointmentRescheduleRequest::class)->handle(
        request: $approvedRequest,
        selectedScheduledAt: $selectedScheduledAt,
        reviewer: $reviewer,
    );

    expect($replayedRequest->appointment_reschedule_id)->toBe($approvedRequest->appointment_reschedule_id)
        ->and($account->fresh()->unreadNotifications)->toHaveCount(1)
        ->and(SmsNotification::query()->count())->toBe(1)
        ->and($appointment->fresh()->reschedules)->toHaveCount(1);
});

test('rejected requests notify the patient with the safe reason and original time once', function (): void {
    ['account' => $account, 'appointment' => $appointment, 'request' => $request] = createOutcomeRequestContext();
    $reviewer = outcomeReviewer();
    $safeReason = 'That time is unavailable because the clinic schedule is full.';

    $rejectedRequest = app(RejectAppointmentRescheduleRequest::class)->handle(
        request: $request,
        rejectionReason: $safeReason,
        reviewer: $reviewer,
    );

    $notification = $account->fresh()->unreadNotifications->sole();
    $sms = SmsNotification::query()->sole();

    expect($rejectedRequest->status)->toBe(AppointmentRescheduleRequestStatus::Rejected)
        ->and($notification->type)->toBe(AppointmentRescheduleRequestStatusChanged::class)
        ->and($notification->data['title'])->toBe('Appointment Reschedule Request Rejected')
        ->and($notification->data['body'])
        ->toContain($safeReason)
        ->toContain('Jul 13, 2026 10:00 AM')
        ->not->toContain('private work conflict')
        ->and($sms->event)->toBe('appointment_reschedule_request_rejected')
        ->and($sms->message)
        ->toContain($safeReason)
        ->toContain('2026-07-13 10:00:00')
        ->not->toContain('private work conflict');

    expect(fn () => app(RejectAppointmentRescheduleRequest::class)->handle(
        request: $rejectedRequest,
        rejectionReason: $safeReason,
        reviewer: $reviewer,
    ))->toThrow(AppointmentRescheduleRequestStateException::class);

    expect($account->fresh()->unreadNotifications)->toHaveCount(1)
        ->and(SmsNotification::query()->count())->toBe(1)
        ->and($appointment->fresh()->scheduled_at->format('Y-m-d H:i:s'))->toBe('2026-07-13 10:00:00');
});

test('expired requests notify the patient in app without creating SMS', function (): void {
    ['account' => $account, 'appointment' => $appointment, 'request' => $request] = createOutcomeRequestContext([
        'expires_at' => '2026-07-10 07:59:59',
    ]);

    expect(app(ExpireAppointmentRescheduleRequests::class)->handle())->toBe(1);

    $notification = $account->fresh()->unreadNotifications->sole();

    expect($request->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Expired)
        ->and($notification->type)->toBe(AppointmentRescheduleRequestStatusChanged::class)
        ->and($notification->data['title'])->toBe('Appointment Reschedule Request Expired')
        ->and($notification->data['body'])
        ->toContain($appointment->appointment_number)
        ->toContain('Jul 13, 2026 10:00 AM')
        ->not->toContain('private work conflict')
        ->and(SmsNotification::query()->count())->toBe(0)
        ->and(app(ExpireAppointmentRescheduleRequests::class)->handle())->toBe(0)
        ->and($account->fresh()->unreadNotifications)->toHaveCount(1);
});

test('failed and stale approval transitions do not emit patient outcome messages and rollback defers delivery', function (): void {
    ['account' => $account, 'appointment' => $appointment, 'request' => $request] = createOutcomeRequestContext();
    $reviewer = outcomeReviewer();

    expect(fn () => app(ApproveAppointmentRescheduleRequest::class)->handle(
        request: $request,
        selectedScheduledAt: Carbon::parse('2026-07-16 10:00:00', config('app.timezone')),
        reviewer: $reviewer,
    ))->toThrow(AppointmentRescheduleRequestStateException::class);

    expect($account->fresh()->notifications)->toBeEmpty()
        ->and(SmsNotification::query()->count())->toBe(0)
        ->and($request->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Pending);

    try {
        DB::transaction(function () use ($request, $reviewer): void {
            app(ApproveAppointmentRescheduleRequest::class)->handle(
                request: $request,
                selectedScheduledAt: Carbon::parse('2026-07-14 10:00:00', config('app.timezone')),
                reviewer: $reviewer,
            );

            throw new RuntimeException('rollback the surrounding transaction');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('rollback the surrounding transaction');
    }

    expect($account->fresh()->notifications)->toBeEmpty()
        ->and(SmsNotification::query()->count())->toBe(0)
        ->and($request->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Pending);

    $appointment->update(['scheduled_at' => '2026-07-13 11:00:00']);

    expect(fn () => app(ApproveAppointmentRescheduleRequest::class)->handle(
        request: $request,
        selectedScheduledAt: Carbon::parse('2026-07-14 10:00:00', config('app.timezone')),
        reviewer: $reviewer,
    ))->toThrow(AppointmentRescheduleRequestStateException::class);

    expect($account->fresh()->notifications)->toBeEmpty()
        ->and(SmsNotification::query()->count())->toBe(0)
        ->and($request->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Pending);
});
