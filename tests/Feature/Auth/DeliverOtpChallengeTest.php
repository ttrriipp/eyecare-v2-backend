<?php

use App\Actions\Auth\DispatchOtpChallenge;
use App\Actions\Auth\IssueOtpChallenge;
use App\Enums\OtpPurpose;
use App\Jobs\DeliverOtpChallenge;
use App\Models\OtpChallenge;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Queue::fake();
});

// --- Job Dispatch ---

test('dispatching OTP queues the delivery job after commit', function () {
    $challenge = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    app(DispatchOtpChallenge::class)->handle($challenge);

    Queue::assertPushed(DeliverOtpChallenge::class, function ($job) use ($challenge) {
        return $job->challengeId === $challenge->public_id;
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
    $job = new DeliverOtpChallenge('test-id');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(30);
});

// --- Job Execution ---

test('delivery job marks challenge as sent on success', function () {
    Queue::fake();

    $challenge = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    // Execute the job directly
    $job = new DeliverOtpChallenge($challenge->public_id);
    $job->handle();

    expect($challenge->fresh()->delivery_status)->toBe('sent')
        ->and($challenge->fresh()->last_sent_at)->not->toBeNull();
});

test('delivery job handles missing challenge gracefully', function () {
    $job = new DeliverOtpChallenge('non-existent-id');

    // Should not throw
    $job->handle();

    expect(true)->toBeTrue();
});

// --- Masking ---

test('delivery logs mask the destination', function () {
    $challenge = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    // The job should log masked info, not the raw destination
    $job = new DeliverOtpChallenge($challenge->public_id);

    // Verify the maskEmail method works via reflection
    $method = new ReflectionMethod($job, 'maskEmail');
    $method->setAccessible(true);

    expect($method->invoke($job, 'test@example.com'))->toBe('t***@example.com')
        ->and($method->invoke($job, 'ana.reyes@gmail.com'))->toBe('a***@gmail.com');
});

test('delivery logs mask phone numbers', function () {
    $job = new DeliverOtpChallenge('test-id');

    $method = new ReflectionMethod($job, 'maskPhone');
    $method->setAccessible(true);

    expect($method->invoke($job, '+639171234567'))->toBe('+63***4567');
});
