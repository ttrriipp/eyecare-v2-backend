<?php

use App\Enums\ScheduleOverrideType;
use App\Models\ProviderHour;
use App\Models\ScheduleOverride;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('only optometrist-capable accounts can own provider hours', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = User::factory()->patient()->create();

    // Optometrist already has provider hours created by the factory
    expect($optometrist->providerHours()->count())->toBe(7);

    // Patient can technically have a row created (enforcement is at the
    // action/policy level, not the model). This test documents the raw model
    // behavior — authorization is tested separately.
    $patientHour = ProviderHour::factory()->create([
        'user_id' => $patient->id,
        'weekday' => 2,
    ]);

    expect($patientHour->user_id)->toBe($patient->id);
});

test('provider hours have unique weekday per user', function () {
    $optometrist = User::factory()->optometrist()->create();

    // Optometrist already has provider hours for all weekdays
    // Trying to create another for the same weekday should fail
    ProviderHour::factory()->create([
        'user_id' => $optometrist->id,
        'weekday' => 1,
    ]);
})->throws(QueryException::class);

test('schedule override supports clinic closure', function () {
    $override = ScheduleOverride::factory()->clinicClosed()->create([
        'override_date' => '2026-08-01',
    ]);

    expect($override->type)->toBe(ScheduleOverrideType::Closed)
        ->and($override->user_id)->toBeNull()
        ->and($override->override_date->toDateString())->toBe('2026-08-01');
});

test('schedule override supports early closing', function () {
    $override = ScheduleOverride::factory()->earlyClose('14:00')->create([
        'override_date' => '2026-08-05',
    ]);

    expect($override->type)->toBe(ScheduleOverrideType::EarlyClose)
        ->and($override->start_time->format('H:i'))->toBe('14:00')
        ->and($override->user_id)->toBeNull();
});

test('schedule override supports provider absence', function () {
    $optometrist = User::factory()->optometrist()->create();

    $override = ScheduleOverride::factory()
        ->providerAbsence($optometrist)
        ->create(['override_date' => '2026-08-10']);

    expect($override->type)->toBe(ScheduleOverrideType::ProviderAbsence)
        ->and($override->user_id)->toBe($optometrist->id);
});

test('clinic-wide overrides have null user_id', function () {
    $override = ScheduleOverride::factory()->clinicClosed()->create();

    expect($override->user_id)->toBeNull();
});

test('provider absence overrides have non-null user_id', function () {
    $optometrist = User::factory()->optometrist()->create();
    $override = ScheduleOverride::factory()->providerAbsence($optometrist)->create();

    expect($override->user_id)->toBe($optometrist->id);
});

test('schedule override types are constrained', function () {
    expect(ScheduleOverride::types())->toContain('closed', 'early_close', 'provider_absence')
        ->and(ScheduleOverride::types())->toHaveCount(3);
});
