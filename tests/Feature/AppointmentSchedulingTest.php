<?php

use App\Actions\Appointments\ScheduleAppointment;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(AppointmentStatusSeeder::class);
    $this->optometrist = User::factory()->optometrist()->create();
});

afterEach(fn () => Carbon::setTestNow());

test('appointments must start and finish within clinic hours', function (string $scheduledAt) {
    expect(fn () => app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse($scheduledAt),
        durationMinutes: 30,
        optometrist: $this->optometrist,
    ))->toThrow(ValidationException::class);
})->with([
    'before opening' => '2026-07-13 08:45:00',
    'finishes after closing' => '2026-07-13 16:45:00',
]);

test('appointments cannot be scheduled on a closed day', function () {
    expect(fn () => app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-12 10:00:00'),
        durationMinutes: 30,
        optometrist: $this->optometrist,
    ))->toThrow(ValidationException::class);
});

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

test('different optometrists cannot have appointments at the same time', function () {
    $otherOptometrist = User::factory()->optometrist()->create();
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    expect(fn () => app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        durationMinutes: 30,
        optometrist: $otherOptometrist,
    ))->toThrow(ValidationException::class);
});

test('unassigned appointments cannot overlap an existing appointment', function () {
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    expect(fn () => app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        durationMinutes: 30,
    ))->toThrow(ValidationException::class);
});

test('partially overlapping appointments are rejected', function () {
    User::factory()->optometrist()->create();

    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'duration_minutes' => 15,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    expect(fn () => app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:10:00'),
        durationMinutes: 30,
    ))->toThrow(ValidationException::class);
});

test('any interval overlap is rejected', function (string $existingStart, int $existingDuration, string $candidateStart, int $candidateDuration) {
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'duration_minutes' => $existingDuration,
        'scheduled_at' => "2026-07-13 {$existingStart}:00",
    ]);

    expect(fn () => app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse("2026-07-13 {$candidateStart}:00"),
        durationMinutes: $candidateDuration,
    ))->toThrow(ValidationException::class);
})->with([
    'leading overlap' => ['10:15', 30, '10:00', 30],
    'trailing overlap' => ['10:00', 30, '10:15', 30],
    'candidate contains existing' => ['10:15', 15, '10:00', 45],
    'existing contains candidate' => ['10:00', 45, '10:15', 15],
]);

test('back-to-back appointments are allowed', function () {
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'duration_minutes' => 15,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:15:00'),
        durationMinutes: 30,
    );

    expect(true)->toBeTrue();
});

test('terminal appointments do not block availability', function (string $statusName) {
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'duration_minutes' => 30,
        'appointment_status_id' => AppointmentStatus::query()->where('name', $statusName)->value('id'),
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        durationMinutes: 30,
        optometrist: $this->optometrist,
    );

    expect(true)->toBeTrue();
})->with(['cancelled', 'no_show', 'fulfilled']);

test('an appointment can ignore its own slot while rescheduling', function () {
    $appointment = Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:15:00'),
        durationMinutes: 30,
        optometrist: $this->optometrist,
        ignoreAppointment: $appointment,
    );

    expect(true)->toBeTrue();
});
