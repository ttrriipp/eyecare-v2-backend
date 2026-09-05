<?php

use App\Actions\Appointments\EvaluateAppointmentAvailability;
use App\Models\Appointment;
use App\Models\ScheduleOverride;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(ClinicHoursSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
});

test('slot exists only when at least one optometrist covers its duration', function () {
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();
    // Provider hours are automatically created by the optometrist factory state

    $date = Carbon::now()->next('monday');

    $evaluator = app(EvaluateAppointmentAvailability::class);
    $capacity = $evaluator->eligibleOptometristCapacity($date);

    expect($capacity)->toBe(2);
});

test('absence removes only the affected capacity', function () {
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();
    // Provider hours are automatically created by the optometrist factory state

    $date = Carbon::now()->next('monday');

    ScheduleOverride::factory()->create([
        'user_id' => $opt2->id,
        'override_date' => $date->toDateString(),
        'type' => 'provider_absence',
    ]);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    $capacity = $evaluator->eligibleOptometristCapacity($date);

    expect($capacity)->toBe(1);
});

test('shortened provider availability removes only affected capacity', function () {
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();
    // Provider hours are automatically created by the optometrist factory state

    $date = Carbon::now()->next('monday');

    // Override opt2's provider hours to end at 12:00
    $opt2->providerHours()->where('weekday', 1)->update(['end_time' => '12:00']);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    $capacity = $evaluator->eligibleOptometristCapacity($date);

    expect($capacity)->toBe(2);
});

test('per-interval capacity excludes optometrists whose hours do not cover the interval', function () {
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();

    $date = Carbon::now()->next('monday');

    // opt2 works only until 12:00
    $opt2->providerHours()->where('weekday', 1)->update(['end_time' => '12:00']);

    $evaluator = app(EvaluateAppointmentAvailability::class);

    // Morning interval: both optometrists available
    $morningStart = $date->copy()->setTime(10, 0);
    $morningEnd = $date->copy()->setTime(10, 30);
    expect($evaluator->eligibleOptometristCapacity($morningStart, $morningEnd))->toBe(2);

    // Afternoon interval: only opt1 available
    $afternoonStart = $date->copy()->setTime(13, 0);
    $afternoonEnd = $date->copy()->setTime(13, 30);
    expect($evaluator->eligibleOptometristCapacity($afternoonStart, $afternoonEnd))->toBe(1);
});

test('clinic capacity reports remaining slots for a candidate interval', function () {
    $firstOptometrist = User::factory()->optometrist()->create();
    User::factory()->optometrist()->create();
    $date = Carbon::now()->next('monday');
    $startsAt = $date->copy()->setTime(10, 0);

    Appointment::factory()->create([
        'optometrist_id' => $firstOptometrist->id,
        'scheduled_at' => $startsAt,
        'duration_minutes' => 30,
    ]);

    $capacity = app(EvaluateAppointmentAvailability::class)->clinicCapacityForInterval(
        startsAt: $startsAt,
        endsAt: $startsAt->copy()->addMinutes(30),
    );

    expect($capacity)->toBe([
        'available' => 1,
        'total' => 2,
    ]);
});

test('partial absence affects only overlapping intervals', function () {
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();

    $date = Carbon::now()->next('monday');

    // opt2 has a partial absence from 10:00 to 12:00
    ScheduleOverride::factory()->create([
        'user_id' => $opt2->id,
        'override_date' => $date->toDateString(),
        'type' => 'provider_absence',
        'start_time' => '10:00',
        'end_time' => '12:00',
    ]);

    $evaluator = app(EvaluateAppointmentAvailability::class);

    // Before absence: both available
    $beforeStart = $date->copy()->setTime(9, 0);
    $beforeEnd = $date->copy()->setTime(9, 30);
    expect($evaluator->eligibleOptometristCapacity($beforeStart, $beforeEnd))->toBe(2);

    // During absence: only opt1 available
    $duringStart = $date->copy()->setTime(10, 0);
    $duringEnd = $date->copy()->setTime(10, 30);
    expect($evaluator->eligibleOptometristCapacity($duringStart, $duringEnd))->toBe(1);

    // After absence: both available
    $afterStart = $date->copy()->setTime(12, 0);
    $afterEnd = $date->copy()->setTime(12, 30);
    expect($evaluator->eligibleOptometristCapacity($afterStart, $afterEnd))->toBe(2);
});

test('patient API has no preferred-provider selection', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user);

    $response = $this->postJson('/api/v1/appointments', [
        'appointment_type_id' => 1,
        'scheduled_at' => now()->addDay()->toISOString(),
    ]);

    $response->assertJsonMissingValidationErrors(['optometrist_id']);
});

test('availability request has no optometrist_id parameter', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user);

    $response = $this->getJson('/api/v1/appointment-availability?'.http_build_query([
        'date' => now()->addDay()->format('Y-m-d'),
        'appointment_type_id' => 1,
    ]));

    $response->assertJsonMissingValidationErrors(['optometrist_id']);
});
