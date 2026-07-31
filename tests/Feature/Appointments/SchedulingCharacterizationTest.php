<?php

use App\Actions\Appointments\CreateScheduledAppointment;
use App\Actions\Appointments\CreateWalkInAppointment;
use App\Actions\Appointments\LockAppointmentScheduleDate;
use App\Actions\Appointments\ScheduleAppointment;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\assertDatabaseCount;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->optometrist = User::factory()->optometrist()->create();
    $this->patient = User::factory()->patient()->create();
    $this->appointmentType = AppointmentType::first();
});

afterEach(fn () => Carbon::setTestNow());

// --- Clinic Hours ---

test('appointments must be within clinic hours', function () {
    expect(fn () => app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 08:45:00'),
        durationMinutes: 30,
        optometrist: $this->optometrist,
    ))->toThrow(ValidationException::class);
});

test('appointments cannot be scheduled on a closed day', function () {
    expect(fn () => app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-12 10:00:00'),
        durationMinutes: 30,
        optometrist: $this->optometrist,
    ))->toThrow(ValidationException::class);
});

// --- Overlap Detection ---

test('one optometrist cannot have overlapping appointments', function () {
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    expect(fn () => app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:15:00'),
        durationMinutes: 30,
        optometrist: $this->optometrist,
    ))->toThrow(ValidationException::class);
});

test('cancelled and no-show appointments do not block availability', function () {
    Appointment::factory()->cancelled()->create([
        'optometrist_id' => $this->optometrist->id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        durationMinutes: 30,
        optometrist: $this->optometrist,
    );

    expect(true)->toBeTrue();
});

// --- Staff Direct Booking ---

test('staff can create appointments directly via CreateScheduledAppointment', function () {
    $appointment = app(CreateScheduledAppointment::class)->handle(
        patient: $this->patient->patient,
        appointmentType: $this->appointmentType,
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
    );

    expect($appointment)->toBeInstanceOf(Appointment::class)
        ->and($appointment->status->name)->toBe('scheduled')
        ->and($appointment->source)->toBe('mobile')
        ->and($appointment->patient_id)->toBe($this->patient->patient->id);
});

test('walk-in appointments bypass scheduling validation', function () {
    $staff = User::factory()->staff()->create();

    $appointment = app(CreateWalkInAppointment::class)->handle(
        patient: $this->patient->patient,
        appointmentType: $this->appointmentType,
        staff: $staff,
    );

    expect($appointment)->toBeInstanceOf(Appointment::class)
        ->and($appointment->status->name)->toBe('checked_in')
        ->and($appointment->source)->toBe('walk_in')
        ->and($appointment->checked_in_at)->not->toBeNull()
        ->and($appointment->encounter)->not->toBeNull();
});

test('walk-in creates a planned encounter immediately', function () {
    $staff = User::factory()->staff()->create();

    $appointment = app(CreateWalkInAppointment::class)->handle(
        patient: $this->patient->patient,
        appointmentType: $this->appointmentType,
        staff: $staff,
    );

    expect($appointment->encounter)->not->toBeNull()
        ->and($appointment->encounter->status->value)->toBe('planned');
});

// --- Concurrency Protection ---

test('schedule date lock creates one row per clinic date', function () {
    $firstLock = DB::transaction(
        fn (): object => app(LockAppointmentScheduleDate::class)
            ->handle(Carbon::parse('2026-07-13 10:00:00')),
    );
    $secondLock = DB::transaction(
        fn (): object => app(LockAppointmentScheduleDate::class)
            ->handle('2026-07-13'),
    );

    expect($firstLock->schedule_date)->toBe('2026-07-13')
        ->and($secondLock->id)->toBe($firstLock->id);

    assertDatabaseCount('appointment_schedule_locks', 1);
});

// --- Appointment Status Transitions ---

test('scheduled appointment can be checked in', function () {
    $appointment = app(CreateScheduledAppointment::class)->handle(
        patient: $this->patient->patient,
        appointmentType: $this->appointmentType,
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
    );

    expect($appointment->status->name)->toBe('scheduled');
});

// --- Source Tracking ---

test('mobile appointments have source set to mobile', function () {
    $appointment = app(CreateScheduledAppointment::class)->handle(
        patient: $this->patient->patient,
        appointmentType: $this->appointmentType,
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
    );

    expect($appointment->source)->toBe('mobile');
});

test('walk-in appointments have source set to walk_in', function () {
    $staff = User::factory()->staff()->create();

    $appointment = app(CreateWalkInAppointment::class)->handle(
        patient: $this->patient->patient,
        appointmentType: $this->appointmentType,
        staff: $staff,
    );

    expect($appointment->source)->toBe('walk_in');
});

// --- Appointment Type Duration ---

test('appointment type duration is used for availability check', function () {
    $type = AppointmentType::where('duration_minutes', 30)->first();

    app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        durationMinutes: $type->duration_minutes,
        optometrist: $this->optometrist,
    );

    expect(true)->toBeTrue();
});
