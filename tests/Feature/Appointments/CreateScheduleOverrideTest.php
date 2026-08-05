<?php

use App\Actions\Appointments\CreateScheduleOverride;
use App\Actions\Appointments\DeleteScheduleOverride;
use App\Enums\AuditEvent;
use App\Enums\ScheduleOverrideType;
use App\Models\AuditLog;
use App\Models\ScheduleOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('a clinic closure can be created', function () {
    $override = app(CreateScheduleOverride::class)->handle(
        type: ScheduleOverrideType::Closed,
        overrideDate: today()->addDays(5)->toDateString(),
        reason: 'Christmas Day',
    );

    expect($override->type)->toBe(ScheduleOverrideType::Closed)
        ->and($override->user_id)->toBeNull()
        ->and($override->reason)->toBe('Christmas Day');
});

test('a clinic closure cannot carry a provider or time range', function () {
    $optometrist = User::factory()->optometrist()->create();

    app(CreateScheduleOverride::class)->handle(
        type: ScheduleOverrideType::Closed,
        overrideDate: today()->addDay()->toDateString(),
        userId: $optometrist->id,
    );
})->throws(ValidationException::class);

test('an early close override requires a closing time', function () {
    app(CreateScheduleOverride::class)->handle(
        type: ScheduleOverrideType::EarlyClose,
        overrideDate: today()->addDay()->toDateString(),
    );
})->throws(ValidationException::class);

test('an early close override is created with a closing time', function () {
    $override = app(CreateScheduleOverride::class)->handle(
        type: ScheduleOverrideType::EarlyClose,
        overrideDate: today()->addDay()->toDateString(),
        startTime: '14:00',
    );

    expect($override->start_time->format('H:i'))->toBe('14:00')
        ->and($override->end_time)->toBeNull();
});

test('a provider absence requires an optometrist', function () {
    app(CreateScheduleOverride::class)->handle(
        type: ScheduleOverrideType::ProviderAbsence,
        overrideDate: today()->addDay()->toDateString(),
    );
})->throws(ValidationException::class);

test('a provider absence rejects a non-optometrist user', function () {
    $staff = User::factory()->staff()->create();

    app(CreateScheduleOverride::class)->handle(
        type: ScheduleOverrideType::ProviderAbsence,
        overrideDate: today()->addDay()->toDateString(),
        userId: $staff->id,
    );
})->throws(ValidationException::class);

test('a full-day provider absence can be created with no times', function () {
    $optometrist = User::factory()->optometrist()->create();

    $override = app(CreateScheduleOverride::class)->handle(
        type: ScheduleOverrideType::ProviderAbsence,
        overrideDate: today()->addDay()->toDateString(),
        userId: $optometrist->id,
    );

    expect($override->start_time)->toBeNull()
        ->and($override->end_time)->toBeNull();
});

test('a partial-day provider absence requires both a start and end time', function () {
    $optometrist = User::factory()->optometrist()->create();

    app(CreateScheduleOverride::class)->handle(
        type: ScheduleOverrideType::ProviderAbsence,
        overrideDate: today()->addDay()->toDateString(),
        userId: $optometrist->id,
        startTime: '09:00',
    );
})->throws(ValidationException::class);

test('a partial-day provider absence can be created with both times', function () {
    $optometrist = User::factory()->optometrist()->create();

    $override = app(CreateScheduleOverride::class)->handle(
        type: ScheduleOverrideType::ProviderAbsence,
        overrideDate: today()->addDay()->toDateString(),
        userId: $optometrist->id,
        startTime: '09:00',
        endTime: '12:00',
    );

    expect($override->start_time->format('H:i'))->toBe('09:00')
        ->and($override->end_time->format('H:i'))->toBe('12:00');
});

test('an override cannot be created for a past date', function () {
    app(CreateScheduleOverride::class)->handle(
        type: ScheduleOverrideType::Closed,
        overrideDate: today()->subDay()->toDateString(),
    );
})->throws(ValidationException::class);

test('an override can be created for today', function () {
    $override = app(CreateScheduleOverride::class)->handle(
        type: ScheduleOverrideType::Closed,
        overrideDate: today()->toDateString(),
    );

    expect($override->override_date->isToday())->toBeTrue();
});

test('a duplicate override for the same date type and user is rejected', function () {
    $optometrist = User::factory()->optometrist()->create();

    ScheduleOverride::factory()->create([
        'user_id' => $optometrist->id,
        'override_date' => today()->addDay()->toDateString(),
        'type' => ScheduleOverrideType::ProviderAbsence->value,
    ]);

    app(CreateScheduleOverride::class)->handle(
        type: ScheduleOverrideType::ProviderAbsence,
        overrideDate: today()->addDay()->toDateString(),
        userId: $optometrist->id,
    );
})->throws(ValidationException::class);

test('creating an override writes an audit log entry', function () {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $override = app(CreateScheduleOverride::class)->handle(
        type: ScheduleOverrideType::Closed,
        overrideDate: today()->addDays(5)->toDateString(),
        reason: 'Christmas Day',
    );

    expect(AuditLog::query()
        ->where('subject_type', $override->getMorphClass())
        ->where('subject_id', $override->id)
        ->where('action', AuditEvent::ScheduleOverrideCreated->value)
        ->where('actor_id', $actor->id)
        ->exists())->toBeTrue();
});

test('removing an override writes an audit log entry and deletes the row', function () {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $override = app(CreateScheduleOverride::class)->handle(
        type: ScheduleOverrideType::Closed,
        overrideDate: today()->addDays(5)->toDateString(),
        reason: 'Christmas Day',
    );
    $overrideId = $override->id;

    app(DeleteScheduleOverride::class)->handle($override);

    expect(ScheduleOverride::query()->find($overrideId))->toBeNull()
        ->and(AuditLog::query()
            ->where('subject_type', $override->getMorphClass())
            ->where('subject_id', $overrideId)
            ->where('action', AuditEvent::ScheduleOverrideRemoved->value)
            ->where('actor_id', $actor->id)
            ->exists())->toBeTrue();
});
