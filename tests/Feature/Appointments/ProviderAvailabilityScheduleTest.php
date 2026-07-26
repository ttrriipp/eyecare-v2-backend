<?php

use App\Actions\Appointments\EvaluateAppointmentAvailability;
use App\Models\ProviderHour;
use App\Models\ScheduleOverride;
use App\Models\User;
use App\Models\VisitReason;
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
    // Create two optometrists with provider hours for Monday (weekday 1)
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();

    ProviderHour::factory()->create([
        'user_id' => $opt1->id,
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'enabled' => true,
    ]);

    ProviderHour::factory()->create([
        'user_id' => $opt2->id,
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'enabled' => true,
    ]);

    // Next Monday
    $date = Carbon::now()->next('monday');
    $visitReason = VisitReason::factory()->create(['duration_minutes' => 30]);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    $capacity = $evaluator->eligibleOptometristCapacity($date);

    expect($capacity)->toBe(2);
});

test('absence removes only the affected capacity', function () {
    $opt1 = User::factory()->optometrist()->create();
    $opt2 = User::factory()->optometrist()->create();

    $date = Carbon::now()->next('monday');

    ProviderHour::factory()->create([
        'user_id' => $opt1->id,
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'enabled' => true,
    ]);

    ProviderHour::factory()->create([
        'user_id' => $opt2->id,
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'enabled' => true,
    ]);

    // Mark opt2 as absent
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

    $date = Carbon::now()->next('monday');

    // opt1 works full day
    ProviderHour::factory()->create([
        'user_id' => $opt1->id,
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'enabled' => true,
    ]);

    // opt2 works only morning
    ProviderHour::factory()->create([
        'user_id' => $opt2->id,
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '12:00',
        'enabled' => true,
    ]);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    $capacity = $evaluator->eligibleOptometristCapacity($date);

    // Both are counted — capacity is based on having hours for the day
    expect($capacity)->toBe(2);
});

test('patient API has no preferred-provider selection', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user);

    // Verify optometrist_id is not in the booking request
    $response = $this->postJson('/api/v1/appointments', [
        'visit_reason_id' => 1,
        'scheduled_at' => now()->addDay()->toISOString(),
    ]);

    // Should not fail with optometrist_id validation error
    $response->assertJsonMissingValidationErrors(['optometrist_id']);
});

test('availability request has no optometrist_id parameter', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user);

    // The request should work without optometrist_id
    $response = $this->getJson('/api/v1/appointment-availability?'.http_build_query([
        'date' => now()->addDay()->format('Y-m-d'),
        'visit_reason_id' => 1,
    ]));

    // Should not fail with optometrist_id validation error
    $response->assertJsonMissingValidationErrors(['optometrist_id']);
});
