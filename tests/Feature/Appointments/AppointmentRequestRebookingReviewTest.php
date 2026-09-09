<?php

use App\Actions\Appointments\AcceptAppointmentRequest;
use App\Actions\Appointments\ExpireAppointmentRequests;
use App\Actions\Appointments\RejectAppointmentRequest;
use App\Enums\AppointmentRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentReschedule;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\SmsNotification;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->seed(ClinicHoursSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

function reviewRebookingAppointment(User $user, User $optometrist): Appointment
{
    $type = AppointmentType::query()->where('name', 'New Patient')->firstOrFail();

    return Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'appointment_type_id' => $type->id,
        'duration_minutes' => $type->duration_minutes,
        'optometrist_id' => $optometrist->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);
}

function reviewRebookingRequest(User $user, Appointment $appointment, array $overrides = []): AppointmentRequest
{
    return AppointmentRequest::factory()
        ->rebookingFor($appointment)
        ->create(array_merge([
            'user_id' => $user->id,
            'patient_id' => $user->patient->id,
            'scheduled_at' => '2026-07-14 11:00:00',
            'expires_at' => now()->addDay(),
        ], $overrides));
}

function acceptReviewRebooking(
    AppointmentRequest $request,
    User $reviewer,
    AppointmentType $type,
    User $optometrist,
): Appointment {
    return app(AcceptAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
        appointmentType: $type,
        durationMinutes: $type->duration_minutes,
        scheduledAt: Carbon::parse('2026-07-14 11:00:00'),
        optometrist: $optometrist,
    );
}

test('accepting a rebooking moves the same appointment and records immutable history', function (): void {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $type = AppointmentType::query()->where('name', 'New Patient')->firstOrFail();
    $appointment = reviewRebookingAppointment($user, $optometrist);
    $request = reviewRebookingRequest($user, $appointment);

    $result = acceptReviewRebooking($request, $reviewer, $type, $optometrist);

    expect($result->id)->toBe($appointment->id)
        ->and($result->scheduled_at->toISOString())->toBe('2026-07-14T03:00:00.000000Z')
        ->and($request->fresh()->status)->toBe(AppointmentRequestStatus::Accepted)
        ->and($request->fresh()->selected_scheduled_at->toISOString())->toBe('2026-07-14T03:00:00.000000Z')
        ->and($request->fresh()->original_scheduled_at->toISOString())->toBe('2026-07-13T02:00:00.000000Z');

    expect(Appointment::query()->count())->toBe(1)
        ->and(AppointmentReschedule::query()->count())->toBe(1);

    $history = AppointmentReschedule::query()->firstOrFail();

    expect($history->appointment_id)->toBe($appointment->id)
        ->and($history->previous_scheduled_at->toISOString())->toBe('2026-07-13T02:00:00.000000Z')
        ->and($history->new_scheduled_at->toISOString())->toBe('2026-07-14T03:00:00.000000Z')
        ->and($history->initiated_by)->toBe('patient')
        ->and($history->actor_id)->toBe($reviewer->id)
        ->and($history->reason_details)->toBeNull();
});

test('accepting a rebooking notifies the patient without copying the request note', function (): void {
    $user = User::factory()->patient()->create(['phone' => '+639171234567']);
    $user->patient->update(['phone' => '+639171234567']);
    $reviewer = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $type = AppointmentType::query()->where('name', 'New Patient')->firstOrFail();
    $appointment = reviewRebookingAppointment($user, $optometrist);
    $request = reviewRebookingRequest($user, $appointment, [
        'encrypted_reason_for_visit' => 'Private clinical note that must not be copied',
    ]);

    acceptReviewRebooking($request, $reviewer, $type, $optometrist);

    $notification = $user->fresh()->unreadNotifications->sole();

    expect($notification->data['title'])->toBe('Appointment Rescheduled')
        ->and($notification->data['body'])->not->toContain('Private clinical note')
        ->and($notification->data['kind'])->toBe('appointment_rescheduled')
        ->and($notification->data['mobile_action'])->toBe([
            'type' => 'appointment',
            'id' => $appointment->id,
        ])
        ->and($notification->data['action_url'])->toBe("/appointments/{$appointment->id}")
        ->and($notification->data['related_type'])->toBe('appointment')
        ->and($notification->data['related_id'])->toBe($appointment->id);

    $sms = SmsNotification::query()->where('event', 'appointment_rescheduled')->firstOrFail();

    expect($sms->appointment_id)->toBe($appointment->id)
        ->and($sms->recipient)->toBe('+639171234567')
        ->and($sms->message)->toStartWith('EyeCare: ')
        ->and($sms->message)->not->toContain('Private clinical note');
});

test('rebooking acceptance preserves the staff contact note on the appointment', function (): void {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $type = AppointmentType::query()->where('name', 'New Patient')->firstOrFail();
    $appointment = reviewRebookingAppointment($user, $optometrist);
    $request = reviewRebookingRequest($user, $appointment, [
        'scheduled_at' => '2026-07-14 10:00:00',
    ]);

    app(AcceptAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
        appointmentType: $type,
        durationMinutes: $type->duration_minutes,
        scheduledAt: Carbon::parse('2026-07-14 11:00:00'),
        optometrist: $optometrist,
        contactNote: 'Patient confirmed the alternate time by phone.',
    );

    expect($appointment->fresh()->contact_notes)->toBe('Patient confirmed the alternate time by phone.');
});

test('rebooking acceptance is idempotent and creates no duplicate effects', function (): void {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $type = AppointmentType::query()->where('name', 'New Patient')->firstOrFail();
    $appointment = reviewRebookingAppointment($user, $optometrist);
    $request = reviewRebookingRequest($user, $appointment);

    $first = acceptReviewRebooking($request, $reviewer, $type, $optometrist);
    $second = acceptReviewRebooking($request->fresh(), $reviewer, $type, $optometrist);

    expect($second->id)->toBe($first->id)
        ->and(AppointmentReschedule::query()->count())->toBe(1)
        ->and(SmsNotification::query()->where('event', 'appointment_rescheduled')->count())->toBe(1)
        ->and($user->fresh()->notifications)->toHaveCount(1);
});

test('a stale rebooking snapshot cannot move the appointment', function (): void {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $type = AppointmentType::query()->where('name', 'New Patient')->firstOrFail();
    $appointment = reviewRebookingAppointment($user, $optometrist);
    $request = reviewRebookingRequest($user, $appointment, [
        'original_scheduled_at' => '2026-07-13 09:00:00',
    ]);

    expect(fn (): Appointment => acceptReviewRebooking($request, $reviewer, $type, $optometrist))
        ->toThrow(ValidationException::class);

    expect($appointment->fresh()->scheduled_at->toISOString())->toBe('2026-07-13T02:00:00.000000Z')
        ->and($request->fresh()->status)->toBe(AppointmentRequestStatus::Pending)
        ->and(AppointmentReschedule::query()->count())->toBe(0);
});

test('a conflicting rebooking choice leaves the appointment and request unchanged', function (): void {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $type = AppointmentType::query()->where('name', 'New Patient')->firstOrFail();
    $appointment = reviewRebookingAppointment($user, $optometrist);
    $request = reviewRebookingRequest($user, $appointment);
    Appointment::factory()->create([
        'optometrist_id' => $optometrist->id,
        'scheduled_at' => '2026-07-14 11:00:00',
        'duration_minutes' => $type->duration_minutes,
    ]);

    expect(fn (): Appointment => acceptReviewRebooking($request, $reviewer, $type, $optometrist))
        ->toThrow(ValidationException::class);

    expect($appointment->fresh()->scheduled_at->toISOString())->toBe('2026-07-13T02:00:00.000000Z')
        ->and($request->fresh()->status)->toBe(AppointmentRequestStatus::Pending)
        ->and(AppointmentReschedule::query()->count())->toBe(0);
});

test('rejecting a rebooking request never changes its appointment', function (): void {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointment = reviewRebookingAppointment($user, $optometrist);
    $request = reviewRebookingRequest($user, $appointment);

    $result = app(RejectAppointmentRequest::class)->handle(
        request: $request,
        reviewer: $reviewer,
        reason: 'The requested time is unavailable.',
    );

    expect($result->status)->toBe(AppointmentRequestStatus::Rejected)
        ->and($appointment->fresh()->scheduled_at->toISOString())->toBe('2026-07-13T02:00:00.000000Z')
        ->and(AppointmentReschedule::query()->count())->toBe(0);
});

test('a rebooking for a no-longer-scheduled appointment is effectively expired', function (): void {
    $user = User::factory()->patient()->create();
    $reviewer = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointment = reviewRebookingAppointment($user, $optometrist);
    $request = reviewRebookingRequest($user, $appointment);
    $appointment->update([
        'appointment_status_id' => AppointmentStatus::query()
            ->where('name', AppointmentStatusName::Cancelled->value)
            ->value('id'),
    ]);

    expect($request->fresh()->effectiveStatus())->toBe(AppointmentRequestStatus::Expired);

    expect(fn (): Appointment => acceptReviewRebooking($request->fresh(), $reviewer, $appointment->appointmentType, $optometrist))
        ->toThrow(ValidationException::class);

    expect(AppointmentReschedule::query()->count())->toBe(0);
});

test('the expiry action closes stale rebooking requests', function (): void {
    $user = User::factory()->patient()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointment = reviewRebookingAppointment($user, $optometrist);
    $request = reviewRebookingRequest($user, $appointment);
    $appointment->update([
        'appointment_status_id' => AppointmentStatus::query()
            ->where('name', AppointmentStatusName::Cancelled->value)
            ->value('id'),
    ]);

    expect(app(ExpireAppointmentRequests::class)->handle())->toBe(1)
        ->and($request->fresh()->status)->toBe(AppointmentRequestStatus::Expired);
});
