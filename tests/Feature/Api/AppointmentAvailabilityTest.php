<?php

use App\Models\Appointment;
use App\Models\User;
use App\Models\VisitReason;
use Database\Seeders\AppointmentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(AppointmentStatusSeeder::class);
    $this->customer = User::factory()->customer()->create();
    $this->visitReason = VisitReason::factory()->create(['duration_minutes' => 30]);
    $this->optometrist = User::factory()->optometrist()->create();
});

afterEach(fn () => Carbon::setTestNow());

test('availability returns 15 minute slots that fit within clinic hours', function () {
    $response = $this->actingAs($this->customer, 'sanctum')->getJson('/api/appointments/availability?'.http_build_query([
        'date' => '2026-07-13',
        'visit_reason_id' => $this->visitReason->id,
        'optometrist_id' => $this->optometrist->id,
    ]));

    $response->assertOk();

    $slots = collect($response->json('data'));

    expect($slots)->toHaveCount(31)
        ->and(Carbon::parse($slots->first())->format('H:i'))->toBe('09:00')
        ->and(Carbon::parse($slots->last())->format('H:i'))->toBe('16:30');
});

test('availability excludes slots that overlap the selected optometrist', function () {
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'visit_reason_id' => $this->visitReason->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $response = $this->actingAs($this->customer, 'sanctum')->getJson('/api/appointments/availability?'.http_build_query([
        'date' => '2026-07-13',
        'visit_reason_id' => $this->visitReason->id,
        'optometrist_id' => $this->optometrist->id,
    ]));

    $times = collect($response->json('data'))->map(fn (string $slot): string => Carbon::parse($slot)->format('H:i'));

    expect($times)->not->toContain('09:45', '10:00', '10:15')
        ->toContain('10:30');
});

test('availability without an optometrist uses clinic provider capacity', function () {
    User::factory()->optometrist()->create();
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'visit_reason_id' => $this->visitReason->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $response = $this->actingAs($this->customer, 'sanctum')->getJson('/api/appointments/availability?'.http_build_query([
        'date' => '2026-07-13',
        'visit_reason_id' => $this->visitReason->id,
    ]));

    $times = collect($response->json('data'))->map(fn (string $slot): string => Carbon::parse($slot)->format('H:i'));

    expect($times)->toContain('10:00');
});

test('availability requires authentication and valid query input', function () {
    $this->getJson('/api/appointments/availability')->assertUnauthorized();

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/appointments/availability')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date', 'visit_reason_id']);
});
