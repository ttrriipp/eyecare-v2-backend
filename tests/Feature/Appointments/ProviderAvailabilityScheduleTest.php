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

test('active optometrists cover all clinic hours without provider hour rows', function () {
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();

    // Remove all provider hour rows to verify the new rule
    $opt1->providerHours()->delete();
    $opt2->providerHours()->delete();

    $date = Carbon::now()->next('monday');
    $startsAt = $date->copy()->setTime(10, 0);
    $endsAt = $date->copy()->setTime(10, 30);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    $capacity = $evaluator->eligibleOptometristCapacity($startsAt, $endsAt);

    expect($capacity)->toBe(2);
});

test('deactivated optometrist contributes no capacity', function () {
    $active = User::factory()->optometrist()->create();
    $inactive = User::factory()->optometrist()->create(['is_active' => false]);

    // Remove provider hour rows to test the new rule
    $active->providerHours()->delete();
    $inactive->providerHours()->delete();

    $date = Carbon::now()->next('monday');
    $startsAt = $date->copy()->setTime(10, 0);
    $endsAt = $date->copy()->setTime(10, 30);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    expect($evaluator->eligibleOptometristCapacity($startsAt, $endsAt))->toBe(1);
});

test('non-optometrist user contributes no capacity', function () {
    $opt = User::factory()->optometrist()->create();
    User::factory()->staff()->create();

    // Remove provider hour rows to test the new rule
    $opt->providerHours()->delete();

    $date = Carbon::now()->next('monday');
    $startsAt = $date->copy()->setTime(10, 0);
    $endsAt = $date->copy()->setTime(10, 30);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    expect($evaluator->eligibleOptometristCapacity($startsAt, $endsAt))->toBe(1);
});

test('zero active optometrists yields zero capacity', function () {
    $date = Carbon::now()->next('monday');
    $startsAt = $date->copy()->setTime(10, 0);
    $endsAt = $date->copy()->setTime(10, 30);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    expect($evaluator->eligibleOptometristCapacity($startsAt, $endsAt))->toBe(0);
});

test('full-day absence removes that optometrist for the date', function () {
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();

    // Remove provider hour rows to test the new rule
    $opt1->providerHours()->delete();
    $opt2->providerHours()->delete();

    $date = Carbon::now()->next('monday');

    ScheduleOverride::factory()->create([
        'user_id' => $opt2->id,
        'override_date' => $date->toDateString(),
        'type' => 'provider_absence',
    ]);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    $startsAt = $date->copy()->setTime(10, 0);
    $endsAt = $date->copy()->setTime(10, 30);

    expect($evaluator->eligibleOptometristCapacity($startsAt, $endsAt))->toBe(1);
});

test('partial absence removes that optometrist only from overlapping slots', function () {
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();

    // Remove provider hour rows to test the new rule
    $opt1->providerHours()->delete();
    $opt2->providerHours()->delete();

    $date = Carbon::now()->next('monday');

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

test('one assigned appointment consumes one unit of clinic capacity', function () {
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();

    // Remove provider hour rows to test the new rule
    $opt1->providerHours()->delete();
    $opt2->providerHours()->delete();

    $date = Carbon::now()->next('monday');
    $startsAt = $date->copy()->setTime(10, 0);

    Appointment::factory()->create([
        'optometrist_id' => $opt1->id,
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

test('same assigned optometrist cannot overlap another appointment', function () {
    $opt1 = User::factory()->optometrist()->create();

    // Remove provider hour rows to test the new rule
    $opt1->providerHours()->delete();

    $date = Carbon::now()->next('monday');
    $startsAt = $date->copy()->setTime(10, 0);

    Appointment::factory()->create([
        'optometrist_id' => $opt1->id,
        'scheduled_at' => $startsAt,
        'duration_minutes' => 30,
    ]);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    $result = $evaluator->handle(
        startsAt: $startsAt->copy()->addMinutes(15),
        durationMinutes: 30,
        optometrist: $opt1,
    );

    expect($result->available)->toBeFalse()
        ->and($result->reason)->toBe('capacity_reached');
});

test('capacity is interval-aware with partial absences', function () {
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();

    // Remove provider hour rows to test the new rule
    $opt1->providerHours()->delete();
    $opt2->providerHours()->delete();

    $date = Carbon::now()->next('monday');

    // opt2 absent 10:00-12:00
    ScheduleOverride::factory()->create([
        'user_id' => $opt2->id,
        'override_date' => $date->toDateString(),
        'type' => 'provider_absence',
        'start_time' => '10:00',
        'end_time' => '12:00',
    ]);

    $evaluator = app(EvaluateAppointmentAvailability::class);

    // Morning: both available
    $morning = $date->copy()->setTime(9, 0);
    expect($evaluator->eligibleOptometristCapacity($morning, $morning->copy()->addMinutes(30)))->toBe(2);

    // During absence: one available
    $during = $date->copy()->setTime(10, 30);
    expect($evaluator->eligibleOptometristCapacity($during, $during->copy()->addMinutes(30)))->toBe(1);

    // Afternoon: both available
    $afternoon = $date->copy()->setTime(13, 0);
    expect($evaluator->eligibleOptometristCapacity($afternoon, $afternoon->copy()->addMinutes(30)))->toBe(2);
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
