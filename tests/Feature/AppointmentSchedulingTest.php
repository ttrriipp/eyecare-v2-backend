<?php

use App\Actions\Appointments\ScheduleAppointment;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\User;
use App\Models\VisitReason;
use Database\Seeders\AppointmentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(AppointmentStatusSeeder::class);
    $this->visitReason = VisitReason::factory()->create(['duration_minutes' => 30]);
    $this->optometrist = User::factory()->optometrist()->create();
});

afterEach(fn () => Carbon::setTestNow());

test('appointments must start and finish within clinic hours', function (string $scheduledAt) {
    expect(fn () => app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse($scheduledAt),
        visitReason: $this->visitReason,
        optometrist: $this->optometrist,
    ))->toThrow(ValidationException::class);
})->with([
    'before opening' => '2026-07-13 08:45:00',
    'finishes after closing' => '2026-07-13 16:45:00',
]);

test('appointments cannot be scheduled on a closed day', function () {
    expect(fn () => app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-12 10:00:00'),
        visitReason: $this->visitReason,
        optometrist: $this->optometrist,
    ))->toThrow(ValidationException::class);
});

test('one optometrist cannot have overlapping appointments', function () {
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'visit_reason_id' => $this->visitReason->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    expect(fn () => app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:15:00'),
        visitReason: $this->visitReason,
        optometrist: $this->optometrist,
    ))->toThrow(ValidationException::class);
});

test('different optometrists can have appointments at the same time', function () {
    $otherOptometrist = User::factory()->optometrist()->create();
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'visit_reason_id' => $this->visitReason->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        visitReason: $this->visitReason,
        optometrist: $otherOptometrist,
    );

    expect(true)->toBeTrue();
});

test('unassigned appointments use the number of available optometrists as clinic capacity', function () {
    $otherOptometrist = User::factory()->optometrist()->create();
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'visit_reason_id' => $this->visitReason->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        visitReason: $this->visitReason,
    );

    Appointment::factory()->create([
        'optometrist_id' => $otherOptometrist->id,
        'visit_reason_id' => $this->visitReason->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    expect(fn () => app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        visitReason: $this->visitReason,
    ))->toThrow(ValidationException::class);
});

test('clinic capacity uses peak concurrent appointments within the proposed interval', function () {
    User::factory()->optometrist()->create();
    $fifteenMinuteReason = VisitReason::factory()->create(['duration_minutes' => 15]);
    $thirtyMinuteReason = VisitReason::factory()->create(['duration_minutes' => 30]);

    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'visit_reason_id' => $fifteenMinuteReason->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    Appointment::factory()->create([
        'optometrist_id' => null,
        'visit_reason_id' => $fifteenMinuteReason->id,
        'scheduled_at' => '2026-07-13 10:15:00',
    ]);

    app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        visitReason: $thirtyMinuteReason,
    );

    expect(true)->toBeTrue();
});

test('cancelled and no-show appointments do not block availability', function (string $statusName) {
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'visit_reason_id' => $this->visitReason->id,
        'appointment_status_id' => AppointmentStatus::query()->where('name', $statusName)->value('id'),
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:00:00'),
        visitReason: $this->visitReason,
        optometrist: $this->optometrist,
    );

    expect(true)->toBeTrue();
})->with(['cancelled', 'no_show']);

test('an appointment can ignore its own slot while rescheduling', function () {
    $appointment = Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'visit_reason_id' => $this->visitReason->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    app(ScheduleAppointment::class)->handle(
        scheduledAt: Carbon::parse('2026-07-13 10:15:00'),
        visitReason: $this->visitReason,
        optometrist: $this->optometrist,
        ignoreAppointment: $appointment,
    );

    expect(true)->toBeTrue();
});
