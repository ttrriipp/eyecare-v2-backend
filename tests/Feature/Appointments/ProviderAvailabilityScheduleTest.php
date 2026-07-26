<?php

use App\Actions\Appointments\EvaluateAppointmentAvailability;
use App\Models\ProviderHour;
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

    $date = Carbon::now()->next('monday');

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
        'end_time' => '12:00',
        'enabled' => true,
    ]);

    $evaluator = app(EvaluateAppointmentAvailability::class);
    $capacity = $evaluator->eligibleOptometristCapacity($date);

    expect($capacity)->toBe(2);
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
