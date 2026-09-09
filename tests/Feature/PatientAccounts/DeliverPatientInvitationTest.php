<?php

use App\Actions\PatientAccounts\IssuePatientInvitation;
use App\Enums\PatientInvitationStatus;
use App\Jobs\DeliverPatientInvitation;
use App\Models\Patient;
use App\Models\PatientInvitation;
use App\Models\User;
use App\Services\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config([
        'services.sms.driver' => 'semaphore',
        'services.semaphore.enabled' => false,
        'services.textbee.enabled' => false,
    ]);
});

test('local SMS delivery logs the invitation code for development testing', function () {
    $patient = Patient::factory()->create(['phone' => '09171234567']);
    $staff = User::factory()->staff()->create();

    $invitation = app(IssuePatientInvitation::class)->handle(
        patient: $patient,
        channel: 'phone',
        sender: $staff,
    );

    Log::spy();

    (new DeliverPatientInvitation($invitation->id))->handle(app(SmsGateway::class));

    Log::shouldHaveReceived('info')
        ->with('SMS invitation delivery (development only)', [
            'invitation_id' => $invitation->id,
            'masked_phone' => '+63***4567',
            'invitation_code' => $invitation->invitation_code,
        ]);
});

test('production SMS delivery never logs the invitation code', function () {
    $patient = Patient::factory()->create(['phone' => '09171234567']);
    $staff = User::factory()->staff()->create();

    $invitation = app(IssuePatientInvitation::class)->handle(
        patient: $patient,
        channel: 'phone',
        sender: $staff,
    );

    Log::spy();
    app()->instance('env', 'production');

    try {
        (new DeliverPatientInvitation($invitation->id))->handle(app(SmsGateway::class));
    } finally {
        app()->instance('env', 'testing');
    }

    Log::shouldHaveReceived('warning')
        ->with('SMS invitation delivery skipped (provider disabled)', [
            'invitation_id' => $invitation->id,
            'masked_phone' => '+63***4567',
        ]);
});

test('production phone invitations send through the configured SMS gateway', function () {
    Http::fake(['https://api.textbee.dev/*' => Http::response(['success' => true], 200)]);
    config([
        'services.sms.driver' => 'textbee',
        'services.textbee.enabled' => true,
        'services.textbee.api_key' => 'test-key',
    ]);

    $patient = Patient::factory()->create(['phone' => '09171234567']);
    $staff = User::factory()->staff()->create();
    $invitation = app(IssuePatientInvitation::class)->handle(
        patient: $patient,
        channel: 'phone',
        sender: $staff,
    );

    app()->instance('env', 'production');

    try {
        (new DeliverPatientInvitation($invitation->id))->handle(app(SmsGateway::class));
    } finally {
        app()->instance('env', 'testing');
    }

    expect($invitation->fresh()->failed_at)->toBeNull()
        ->and($invitation->fresh()->sent_at)->not->toBeNull();

    Http::assertSent(function ($request) use ($invitation): bool {
        return $request->url() === 'https://api.textbee.dev/api/v1/gateway/send-sms'
            && $request->hasHeader('x-api-key', 'test-key')
            && $request['recipients'] === ['+639171234567']
            && str_contains($request['message'], $invitation->invitation_code);
    });
});

test('production phone invitations record a failure when the SMS gateway rejects them', function () {
    Http::fake(['https://api.textbee.dev/*' => Http::response([], 500)]);
    config([
        'services.sms.driver' => 'textbee',
        'services.textbee.enabled' => true,
    ]);

    $patient = Patient::factory()->create(['phone' => '09171234567']);
    $staff = User::factory()->staff()->create();
    $invitation = app(IssuePatientInvitation::class)->handle(
        patient: $patient,
        channel: 'phone',
        sender: $staff,
    );

    app()->instance('env', 'production');

    $job = new DeliverPatientInvitation($invitation->id);

    try {
        expect(fn () => $job->handle(app(SmsGateway::class)))
            ->toThrow(RuntimeException::class);
        $job->failed(new RuntimeException('SMS provider returned a failure response.'));
    } finally {
        app()->instance('env', 'testing');
    }

    expect($invitation->fresh()->failed_at)->not->toBeNull()
        ->and($invitation->fresh()->status->value)->toBe('failed')
        ->and($invitation->fresh()->sent_at)->toBeNull();
});

test('production phone invitations record a failure when the SMS gateway is disabled', function () {
    Http::fake();
    config([
        'services.sms.driver' => 'textbee',
        'services.textbee.enabled' => false,
    ]);

    $patient = Patient::factory()->create(['phone' => '09171234567']);
    $staff = User::factory()->staff()->create();
    $invitation = app(IssuePatientInvitation::class)->handle(
        patient: $patient,
        channel: 'phone',
        sender: $staff,
    );

    app()->instance('env', 'production');

    try {
        (new DeliverPatientInvitation($invitation->id))->handle(app(SmsGateway::class));
    } finally {
        app()->instance('env', 'testing');
    }

    expect($invitation->fresh()->failed_at)->not->toBeNull()
        ->and($invitation->fresh()->status->value)->toBe('failed')
        ->and($invitation->fresh()->sent_at)->toBeNull();
    Http::assertNothingSent();
});

test('failed invitation transitions do not overwrite an accepted invitation', function (): void {
    $invitation = PatientInvitation::factory()->accepted()->create();

    expect($invitation->markFailed())->toBeFalse()
        ->and($invitation->fresh()->status)->toBe(PatientInvitationStatus::Accepted)
        ->and($invitation->recordDeliveryAttemptFailure())->toBeFalse()
        ->and($invitation->fresh()->failed_at)->toBeNull();
});
