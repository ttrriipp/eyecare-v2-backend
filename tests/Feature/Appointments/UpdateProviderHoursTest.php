<?php

use App\Actions\Appointments\UpdateProviderHours;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\ClinicHour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('provider hours can be saved within clinic hours', function () {
    ClinicHour::factory()->create(['weekday' => 1, 'enabled' => true, 'open_time' => '09:00', 'close_time' => '17:00']);
    $optometrist = User::factory()->optometrist()->create();

    $hours = app(UpdateProviderHours::class)->handle(
        userId: $optometrist->id,
        weekday: 1,
        enabled: true,
        startTime: '10:00',
        endTime: '15:00',
    );

    expect($hours->start_time->format('H:i'))->toBe('10:00')
        ->and($hours->end_time->format('H:i'))->toBe('15:00');
});

test('saving provider hours writes an audit log entry', function () {
    ClinicHour::factory()->create(['weekday' => 1, 'enabled' => true, 'open_time' => '09:00', 'close_time' => '17:00']);
    $optometrist = User::factory()->optometrist()->create();
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $hours = app(UpdateProviderHours::class)->handle(
        userId: $optometrist->id,
        weekday: 1,
        enabled: true,
        startTime: '10:00',
        endTime: '15:00',
    );

    expect(AuditLog::query()
        ->where('subject_type', $hours->getMorphClass())
        ->where('subject_id', $hours->id)
        ->where('action', AuditEvent::ProviderHoursUpdated->value)
        ->where('actor_id', $actor->id)
        ->exists())->toBeTrue();
});

test('provider hours starting before clinic opens are rejected', function () {
    ClinicHour::factory()->create(['weekday' => 1, 'enabled' => true, 'open_time' => '09:00', 'close_time' => '17:00']);
    $optometrist = User::factory()->optometrist()->create();

    app(UpdateProviderHours::class)->handle(
        userId: $optometrist->id,
        weekday: 1,
        enabled: true,
        startTime: '08:00',
        endTime: '12:00',
    );
})->throws(ValidationException::class);

test('provider hours ending after clinic closes are rejected', function () {
    ClinicHour::factory()->create(['weekday' => 1, 'enabled' => true, 'open_time' => '09:00', 'close_time' => '17:00']);
    $optometrist = User::factory()->optometrist()->create();

    app(UpdateProviderHours::class)->handle(
        userId: $optometrist->id,
        weekday: 1,
        enabled: true,
        startTime: '12:00',
        endTime: '18:00',
    );
})->throws(ValidationException::class);

test('provider hours are rejected on a day the clinic is closed', function () {
    ClinicHour::factory()->create(['weekday' => 0, 'enabled' => false]);
    $optometrist = User::factory()->optometrist()->create();

    app(UpdateProviderHours::class)->handle(
        userId: $optometrist->id,
        weekday: 0,
        enabled: true,
        startTime: '09:00',
        endTime: '12:00',
    );
})->throws(ValidationException::class);

test('disabling provider hours does not require them to fit clinic hours', function () {
    ClinicHour::factory()->create(['weekday' => 0, 'enabled' => false]);
    $optometrist = User::factory()->optometrist()->create();

    $hours = app(UpdateProviderHours::class)->handle(
        userId: $optometrist->id,
        weekday: 0,
        enabled: false,
        startTime: '09:00',
        endTime: '12:00',
    );

    expect($hours->enabled)->toBeFalse();
});

test('provider hours fall back to config defaults when no clinic hour row exists', function () {
    // No ClinicHour row for this weekday at all.
    $optometrist = User::factory()->optometrist()->create();

    $hours = app(UpdateProviderHours::class)->handle(
        userId: $optometrist->id,
        weekday: 3,
        enabled: true,
        startTime: '09:00',
        endTime: '17:00',
    );

    expect($hours->enabled)->toBeTrue();
});
