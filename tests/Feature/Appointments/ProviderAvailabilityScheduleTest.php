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
    User::factory()->optometrist()->create();
    User::factory()->optometrist()->create();

    $date = Carbon::now()->next('monday');
    $startsAt = $date->copy()->setTime(10, 0);
    $endsAt = $date->copy()->setTime(10, 30);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    $providerAvailable = $evaluator->hasEligibleOptometrist($startsAt, $endsAt);

    expect($providerAvailable)->toBeTrue();
});

test('deactivated optometrist contributes no provider availability', function () {
    User::factory()->optometrist()->create();
    User::factory()->optometrist()->create(['is_active' => false]);

    $date = Carbon::now()->next('monday');
    $startsAt = $date->copy()->setTime(10, 0);
    $endsAt = $date->copy()->setTime(10, 30);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    expect($evaluator->hasEligibleOptometrist($startsAt, $endsAt))->toBeTrue();
});

test('non-optometrist user contributes no provider availability', function () {
    User::factory()->optometrist()->create();
    User::factory()->staff()->create();

    $date = Carbon::now()->next('monday');
    $startsAt = $date->copy()->setTime(10, 0);
    $endsAt = $date->copy()->setTime(10, 30);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    expect($evaluator->hasEligibleOptometrist($startsAt, $endsAt))->toBeTrue();
});

test('zero active optometrists yields no provider availability', function () {
    $date = Carbon::now()->next('monday');
    $startsAt = $date->copy()->setTime(10, 0);
    $endsAt = $date->copy()->setTime(10, 30);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    expect($evaluator->hasEligibleOptometrist($startsAt, $endsAt))->toBeFalse();
});

test('full-day absence removes that optometrist for the date', function () {
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();

    $date = Carbon::now()->next('monday');

    ScheduleOverride::factory()->create([
        'user_id' => $opt2->id,
        'override_date' => $date->toDateString(),
        'type' => 'provider_absence',
    ]);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    $startsAt = $date->copy()->setTime(10, 0);
    $endsAt = $date->copy()->setTime(10, 30);

    expect($evaluator->hasEligibleOptometrist($startsAt, $endsAt))->toBeTrue();
});

test('partial absence removes that optometrist only from overlapping slots', function () {
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();

    $date = Carbon::now()->next('monday');

    ScheduleOverride::factory()->create([
        'user_id' => $opt2->id,
        'override_date' => $date->toDateString(),
        'type' => 'provider_absence',
        'start_time' => '10:00',
        'end_time' => '12:00',
    ]);

    $evaluator = app(EvaluateAppointmentAvailability::class);

    // Before absence: a provider is available
    $beforeStart = $date->copy()->setTime(9, 0);
    $beforeEnd = $date->copy()->setTime(9, 30);
    expect($evaluator->hasEligibleOptometrist($beforeStart, $beforeEnd))->toBeTrue();

    // During absence: opt1 remains available
    $duringStart = $date->copy()->setTime(10, 0);
    $duringEnd = $date->copy()->setTime(10, 30);
    expect($evaluator->hasEligibleOptometrist($duringStart, $duringEnd))->toBeTrue();

    // After absence: a provider is available
    $afterStart = $date->copy()->setTime(12, 0);
    $afterEnd = $date->copy()->setTime(12, 30);
    expect($evaluator->hasEligibleOptometrist($afterStart, $afterEnd))->toBeTrue();
});

test('an existing appointment blocks the whole clinic interval', function () {
    $opt1 = User::factory()->optometrist()->create();
    User::factory()->optometrist()->create();

    $date = Carbon::now()->next('monday');
    $startsAt = $date->copy()->setTime(10, 0);

    Appointment::factory()->create([
        'optometrist_id' => $opt1->id,
        'scheduled_at' => $startsAt,
        'duration_minutes' => 30,
    ]);

    $result = app(EvaluateAppointmentAvailability::class)->handle(
        startsAt: $startsAt,
        durationMinutes: 30,
    );

    expect($result->available)->toBeFalse()
        ->and($result->reason)->toBe('capacity_reached');
});

test('same assigned optometrist cannot overlap another appointment', function () {
    $opt1 = User::factory()->optometrist()->create();

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

test('provider availability is interval-aware with partial absences', function () {
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();

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

    // Morning: a provider is available
    $morning = $date->copy()->setTime(9, 0);
    expect($evaluator->hasEligibleOptometrist($morning, $morning->copy()->addMinutes(30)))->toBeTrue();

    // During absence: opt1 remains available
    $during = $date->copy()->setTime(10, 30);
    expect($evaluator->hasEligibleOptometrist($during, $during->copy()->addMinutes(30)))->toBeTrue();

    // Afternoon: a provider is available
    $afternoon = $date->copy()->setTime(13, 0);
    expect($evaluator->hasEligibleOptometrist($afternoon, $afternoon->copy()->addMinutes(30)))->toBeTrue();
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
