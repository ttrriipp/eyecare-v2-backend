<?php

use App\Actions\Appointments\BuildScheduleBlocks;
use App\Actions\Appointments\ScheduleBlock;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
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

// --- ScheduleBlock Value Object ---

test('schedule block detects overlap', function () {
    $block = new ScheduleBlock(
        startsAt: Carbon::parse('2026-07-13 10:00:00'),
        endsAt: Carbon::parse('2026-07-13 10:30:00'),
        source: 'appointment',
    );

    expect($block->overlaps(
        Carbon::parse('2026-07-13 09:45:00'),
        Carbon::parse('2026-07-13 10:15:00'),
    ))->toBeTrue();

    expect($block->overlaps(
        Carbon::parse('2026-07-13 10:30:00'),
        Carbon::parse('2026-07-13 11:00:00'),
    ))->toBeFalse();
});

test('schedule block can exclude itself', function () {
    $block = new ScheduleBlock(
        startsAt: Carbon::parse('2026-07-13 10:00:00'),
        endsAt: Carbon::parse('2026-07-13 10:30:00'),
        source: 'appointment',
        sourceId: 42,
    );

    expect($block->excludes(42, 'appointment'))->toBeTrue()
        ->and($block->excludes(99, 'appointment'))->toBeFalse()
        ->and($block->excludes(42, 'request'))->toBeFalse();
});

// --- BuildScheduleBlocks ---

test('blocks include active appointments', function () {
    $optometrist = User::factory()->optometrist()->create();

    Appointment::factory()->create([
        'optometrist_id' => $optometrist->id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $blocks = app(BuildScheduleBlocks::class)->forDate(Carbon::parse('2026-07-13'));

    expect($blocks)->toHaveCount(1)
        ->and($blocks->first()->source)->toBe('appointment');
});

test('blocks include unexpired pending request holds', function () {
    $user = User::factory()->patient()->create();

    AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'scheduled_at' => '2026-07-13 10:00:00',
        'provisional_duration_minutes' => 30,
        'status' => 'pending',
        'expires_at' => now()->addHours(24),
    ]);

    $blocks = app(BuildScheduleBlocks::class)->forDate(Carbon::parse('2026-07-13'));

    expect($blocks)->toHaveCount(1)
        ->and($blocks->first()->source)->toBe('request');
});

test('blocks exclude expired request holds', function () {
    $user = User::factory()->patient()->create();

    AppointmentRequest::factory()->expired()->create([
        'user_id' => $user->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $blocks = app(BuildScheduleBlocks::class)->forDate(Carbon::parse('2026-07-13'));

    expect($blocks)->toHaveCount(0);
});

test('blocks can exclude a specific appointment', function () {
    $optometrist = User::factory()->optometrist()->create();

    $appointment = Appointment::factory()->create([
        'optometrist_id' => $optometrist->id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $blocks = app(BuildScheduleBlocks::class)->forDate(
        Carbon::parse('2026-07-13'),
        excludeAppointmentId: $appointment->id,
    );

    expect($blocks)->toHaveCount(0);
});

test('blocks can exclude a specific request', function () {
    $user = User::factory()->patient()->create();

    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'scheduled_at' => '2026-07-13 10:00:00',
        'provisional_duration_minutes' => 30,
        'status' => 'pending',
        'expires_at' => now()->addHours(24),
    ]);

    $blocks = app(BuildScheduleBlocks::class)->forDate(
        Carbon::parse('2026-07-13'),
        excludeRequestId: $request->id,
    );

    expect($blocks)->toHaveCount(0);
});

test('cancelled and no-show appointments are excluded from blocks', function () {
    $optometrist = User::factory()->optometrist()->create();

    Appointment::factory()->cancelled()->create([
        'optometrist_id' => $optometrist->id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $blocks = app(BuildScheduleBlocks::class)->forDate(Carbon::parse('2026-07-13'));

    expect($blocks)->toHaveCount(0);
});

test('existing scheduling tests still pass with blocks', function () {
    // Verify the existing scheduling behavior is preserved
    $optometrist = User::factory()->optometrist()->create();

    Appointment::factory()->create([
        'optometrist_id' => $optometrist->id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $blocks = app(BuildScheduleBlocks::class)->forDate(Carbon::parse('2026-07-13'));

    // The block should overlap 10:00-10:30
    $overlaps = $blocks->filter(fn ($b) => $b->overlaps(
        Carbon::parse('2026-07-13 10:00:00'),
        Carbon::parse('2026-07-13 10:30:00'),
    ));

    expect($overlaps)->toHaveCount(1);

    // But not overlap 10:30-11:00
    $overlaps2 = $blocks->filter(fn ($b) => $b->overlaps(
        Carbon::parse('2026-07-13 10:30:00'),
        Carbon::parse('2026-07-13 11:00:00'),
    ));

    expect($overlaps2)->toHaveCount(0);
});
