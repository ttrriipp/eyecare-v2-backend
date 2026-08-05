<?php

use App\Actions\Appointments\UpdateClinicHours;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('clinic hours can be saved', function () {
    $hours = app(UpdateClinicHours::class)->handle(
        weekday: 1,
        enabled: true,
        openTime: '08:00',
        closeTime: '18:00',
    );

    expect($hours->open_time->format('H:i'))->toBe('08:00')
        ->and($hours->close_time->format('H:i'))->toBe('18:00');
});

test('opening time must be before closing time', function () {
    app(UpdateClinicHours::class)->handle(
        weekday: 1,
        enabled: true,
        openTime: '18:00',
        closeTime: '08:00',
    );
})->throws(ValidationException::class);

test('saving clinic hours writes an audit log entry', function () {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $hours = app(UpdateClinicHours::class)->handle(
        weekday: 1,
        enabled: true,
        openTime: '08:00',
        closeTime: '18:00',
    );

    expect(AuditLog::query()
        ->where('subject_type', $hours->getMorphClass())
        ->where('subject_id', $hours->id)
        ->where('action', AuditEvent::ClinicHoursUpdated->value)
        ->where('actor_id', $actor->id)
        ->exists())->toBeTrue();
});
