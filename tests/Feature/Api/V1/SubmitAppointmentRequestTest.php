<?php

use App\Models\Appointment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

test('linked account can submit an appointment request', function () {
    $user = User::factory()->patient()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
            'reason_for_visit' => 'Blurred vision in left eye',
        ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'request_number', 'status', 'scheduled_at', 'reason_for_visit']]);
});

test('unlinked account can submit an appointment request', function () {
    $user = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
            'reason_for_visit' => 'Eye exam',
        ]);

    $response->assertCreated();

    // patient_id should be null for unlinked accounts
    $response->assertJsonPath('data.patient_id', null);
});

test('request requires reason for visit', function () {
    $user = User::factory()->patient()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['reason_for_visit']);
});

test('request rejects unavailable slot', function () {
    $user = User::factory()->patient()->create();
    $optometrist = User::factory()->optometrist()->create();

    // Create an existing appointment at 10:00
    Appointment::factory()->create([
        'optometrist_id' => $optometrist->id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
            'reason_for_visit' => 'Eye exam',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_at']);
});

test('linked request copies patient_id', function () {
    $user = User::factory()->patient()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
            'reason_for_visit' => 'Eye exam',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.patient_id', $user->patient->id);
});
