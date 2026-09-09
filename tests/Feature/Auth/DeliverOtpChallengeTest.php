<?php

use App\Actions\Auth\DispatchOtpChallenge;
use App\Actions\Auth\IssueOtpChallenge;
use App\Enums\OtpPurpose;
use App\Jobs\DeliverOtpChallenge;
use App\Mail\OtpMail;
use App\Models\OtpChallenge;
use App\Services\SmsGateway;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Queue::fake();
    config([
        'services.sms.driver' => 'semaphore',
        'services.semaphore.enabled' => false,
        'services.textbee.enabled' => false,
    ]);
});

// --- Job Dispatch ---

test('dispatching OTP queues the delivery job after commit', function () {
    $result = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );
    $challenge = $result['challenge'];

    app(DispatchOtpChallenge::class)->handle($challenge);

    Queue::assertPushed(DeliverOtpChallenge::class, function ($job) use ($challenge, $result) {
        return $job->challengeId === $challenge->public_id
            && $job->code === $result['code'];
    });
});

test('dispatching skips consumed challenges', function () {
    $challenge = OtpChallenge::factory()->consumed()->create();

    app(DispatchOtpChallenge::class)->handle($challenge);

    Queue::assertNothingPushed();
});

test('dispatching skips invalidated challenges', function () {
    $challenge = OtpChallenge::factory()->invalidated()->create();

    app(DispatchOtpChallenge::class)->handle($challenge);

    Queue::assertNothingPushed();
});

// --- Job Properties ---

test('delivery job has retry configuration', function () {
    $job = new DeliverOtpChallenge('test-id', 'test-code');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(30)
        ->and($job)->toBeInstanceOf(ShouldBeEncrypted::class);
});

// --- Job Execution ---

test('delivery job marks challenge as sent on success', function () {
    Queue::fake();

    $result = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );
    $challenge = $result['challenge'];

    // Execute the job directly
    $job = new DeliverOtpChallenge($challenge->public_id, $result['code']);
    $job->handle(app(SmsGateway::class));

    expect($challenge->fresh()->delivery_status)->toBe('sent')
        ->and($challenge->fresh()->last_sent_at)->not->toBeNull();
});

test('delivery job handles missing challenge gracefully', function () {
    $job = new DeliverOtpChallenge('non-existent-id', 'test-code');

    // Should not throw
    $job->handle(app(SmsGateway::class));

    expect(true)->toBeTrue();
});

test('local SMS delivery logs the OTP code for development testing', function () {
    $challenge = app(IssueOtpChallenge::class)->handle(
        contactType: 'phone',
        contactValue: '09171234567',
        purpose: OtpPurpose::Registration,
    )['challenge'];

    Log::spy();

    (new DeliverOtpChallenge($challenge->public_id, '123456'))->handle(app(SmsGateway::class));

    Log::shouldHaveReceived('info')
        ->once()
        ->with('SMS OTP delivery (development only)', [
            'challenge_id' => $challenge->public_id,
            'masked' => '+63***4567',
            'code' => '123456',
        ]);
});

test('production SMS delivery never logs the OTP code', function () {
    $challenge = app(IssueOtpChallenge::class)->handle(
        contactType: 'phone',
        contactValue: '09171234567',
        purpose: OtpPurpose::Registration,
    )['challenge'];

    Log::spy();
    app()->instance('env', 'production');

    try {
        (new DeliverOtpChallenge($challenge->public_id, '123456'))->handle(app(SmsGateway::class));
    } finally {
        app()->instance('env', 'testing');
    }

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('SMS OTP delivery skipped (provider disabled)', [
            'challenge_id' => $challenge->public_id,
            'masked' => '+63***4567',
        ]);
});

// --- Email and phone delivery ---

test('delivery job sends email OTP', function () {
    $result = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );
    $challenge = $result['challenge'];
    Mail::fake();

    (new DeliverOtpChallenge($challenge->public_id, $result['code']))->handle(app(SmsGateway::class));

    Mail::assertSent(OtpMail::class, function (OtpMail $mail) use ($result): bool {
        return $mail->code === $result['code']
            && $mail->purpose === OtpPurpose::Registration->value;
    });
});

test('production phone OTP sends through the configured SMS gateway', function () {
    Http::fake(['https://api.textbee.dev/*' => Http::response(['success' => true], 200)]);
    config([
        'services.sms.driver' => 'textbee',
        'services.textbee.enabled' => true,
        'services.textbee.api_key' => 'test-key',
    ]);

    $result = app(IssueOtpChallenge::class)->handle(
        contactType: 'phone',
        contactValue: '09171234567',
        purpose: OtpPurpose::Registration,
    );
    $challenge = $result['challenge'];

    app()->instance('env', 'production');

    try {
        (new DeliverOtpChallenge($challenge->public_id, $result['code']))->handle(app(SmsGateway::class));
    } finally {
        app()->instance('env', 'testing');
    }

    expect($challenge->fresh()->delivery_status)->toBe('sent');

    Http::assertSent(function ($request) use ($result): bool {
        return $request->url() === 'https://api.textbee.dev/api/v1/gateway/send-sms'
            && $request->hasHeader('x-api-key', 'test-key')
            && $request['recipients'] === ['+639171234567']
            && str_contains($request['message'], $result['code']);
    });
});

test('production phone OTP marks delivery failed when the SMS gateway rejects it', function () {
    Http::fake(['https://api.textbee.dev/*' => Http::response([], 500)]);
    config([
        'services.sms.driver' => 'textbee',
        'services.textbee.enabled' => true,
    ]);

    $result = app(IssueOtpChallenge::class)->handle(
        contactType: 'phone',
        contactValue: '09171234567',
        purpose: OtpPurpose::Registration,
    );
    $challenge = $result['challenge'];

    app()->instance('env', 'production');

    try {
        (new DeliverOtpChallenge($challenge->public_id, $result['code']))->handle(app(SmsGateway::class));
    } finally {
        app()->instance('env', 'testing');
    }

    expect($challenge->fresh()->delivery_status)->toBe('failed');
});

test('production phone OTP marks delivery failed when the SMS gateway is disabled', function () {
    Http::fake();
    config([
        'services.sms.driver' => 'textbee',
        'services.textbee.enabled' => false,
    ]);

    $result = app(IssueOtpChallenge::class)->handle(
        contactType: 'phone',
        contactValue: '09171234567',
        purpose: OtpPurpose::Registration,
    );
    $challenge = $result['challenge'];

    app()->instance('env', 'production');

    try {
        (new DeliverOtpChallenge($challenge->public_id, $result['code']))->handle(app(SmsGateway::class));
    } finally {
        app()->instance('env', 'testing');
    }

    expect($challenge->fresh()->delivery_status)->toBe('failed');
    Http::assertNothingSent();
});

test('delivery logs mask phone numbers', function () {
    $job = new DeliverOtpChallenge('test-id', 'test-code');

    $method = new ReflectionMethod($job, 'maskPhone');
    $method->setAccessible(true);

    expect($method->invoke($job, '+639171234567'))->toBe('+63***4567');
});
