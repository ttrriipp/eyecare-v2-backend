<?php

use App\Actions\Appointments\EvaluateAppointmentRequestPreferences;
use App\Models\AppointmentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('preference decisions retain primary and alternative ordering', function () {
    $optometrist = User::factory()->optometrist()->create();
    $request = AppointmentRequest::factory()->linked()->withAlternatives()->create();

    $decisions = app(EvaluateAppointmentRequestPreferences::class)->handle(
        request: $request,
        durationMinutes: 30,
        optometrist: $optometrist,
    );

    expect($decisions)->toHaveCount(3)
        ->and($decisions[0]['preference'])->toBe('Primary preference')
        ->and($decisions[1]['preference'])->toBe('Alternative 1')
        ->and($decisions[2]['preference'])->toBe('Alternative 2')
        ->and($decisions[0])->toHaveKeys(['starts_at', 'ends_at', 'available', 'reason']);
});
