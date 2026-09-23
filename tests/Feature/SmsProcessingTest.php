<?php

use App\Actions\Sms\ProcessSmsNotification;
use App\Exceptions\SmsDeliveryException;
use App\Jobs\SendSmsJob;
use App\Models\JobOrder;
use App\Models\NotificationStatus;
use App\Models\Patient;
use App\Models\SmsNotification;
use App\Services\SemaphoreService;
use App\Services\TextBeeService;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);

    // Don't depend on whichever driver a developer's local .env happens to
    // have set — each test declares the driver it actually exercises.
    config(['services.sms.driver' => 'semaphore']);
});

test('ProcessSmsNotification marks sms as sent when service succeeds', function () {
    Http::fake(['https://api.semaphore.co/*' => Http::response([[
        'message_id' => 'semaphore-message-1',
        'status' => 'Queued',
    ]], 200)]);
    config(['services.semaphore.enabled' => true]);

    $sms = SmsNotification::factory()->create();

    app(ProcessSmsNotification::class)->handle($sms);

    expect($sms->fresh()->status->name)->toBe('sent')
        ->and($sms->fresh()->failure_reason)->toBeNull()
        ->and($sms->fresh()->provider_name)->toBe('semaphore')
        ->and($sms->fresh()->provider_reference)->toBe('semaphore-message-1')
        ->and($sms->fresh()->provider_status)->toBe('queued')
        ->and($sms->fresh()->provider_accepted_at)->not->toBeNull();
});

test('ProcessSmsNotification marks sms as failed when service fails', function () {
    Http::fake(['https://api.semaphore.co/*' => Http::response([], 500)]);
    config(['services.semaphore.enabled' => true]);

    $sms = SmsNotification::factory()->create();

    app(ProcessSmsNotification::class)->handle($sms);

    expect($sms->fresh()->status->name)->toBe('failed')
        ->and($sms->fresh()->failure_reason)->not->toBeNull();
});

test('ProcessSmsNotification does not resend a completed notification', function (): void {
    Http::fake();
    $sentStatus = NotificationStatus::query()->where('name', 'sent')->firstOrFail();
    $sms = SmsNotification::factory()->create(['notification_status_id' => $sentStatus->id]);

    app(ProcessSmsNotification::class)->handle($sms);

    Http::assertNothingSent();
    expect($sms->fresh()->status->name)->toBe('sent');
});

test('Semaphore retries only explicit rate limits through the queue', function (): void {
    Http::fake(['https://sms.example.test/*' => Http::response([], 429)]);
    config([
        'services.semaphore.enabled' => true,
        'services.semaphore.api_key' => str_repeat('semaphore-key', 3),
        'services.semaphore.endpoint' => 'https://sms.example.test/messages',
        'services.semaphore.timeout' => 3,
    ]);

    $service = app(SemaphoreService::class);

    expect($service->send('+639171234567', 'Test message'))->toBeFalse()
        ->and($service->isRetryableFailure())->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://sms.example.test/messages');
});

test('Semaphore rejects a failed message in a successful HTTP response', function (): void {
    Http::fake([
        'https://api.semaphore.co/*' => Http::response([[
            'message_id' => 'semaphore-message-failed',
            'status' => 'Failed',
        ]], 200),
    ]);
    config(['services.semaphore.enabled' => true]);

    $service = app(SemaphoreService::class);

    expect($service->send('+639171234567', 'Test message'))->toBeFalse()
        ->and($service->failureReason())->toContain('failed')
        ->and($service->providerReference())->toBe('semaphore-message-failed')
        ->and($service->providerStatus())->toBe('failed');
});

test('disabled Semaphore reports unsuccessful delivery', function (): void {
    Http::fake();
    config(['services.semaphore.enabled' => false]);

    expect(app(SemaphoreService::class)->send('+639171234567', 'Test message'))->toBeFalse();

    Http::assertNothingSent();
});

test('ProcessSmsNotification marks sms as failed without HTTP call when disabled', function () {
    Http::fake();
    Log::spy();
    config(['services.semaphore.enabled' => false]);

    $sms = SmsNotification::factory()->create();

    app(ProcessSmsNotification::class)->handle($sms);

    expect($sms->fresh()->status->name)->toBe('failed')
        ->and($sms->fresh()->failure_reason)->toBe('SMS provider is disabled.');
    Http::assertNothingSent();
    Log::shouldHaveReceived('info')
        ->once()
        ->with('SMS delivery skipped (Semaphore disabled)', ['driver' => 'semaphore']);
});

test('sms:process command processes queued notifications', function () {
    Http::fake();
    config(['services.semaphore.enabled' => false]);

    SmsNotification::factory()->count(3)->create();

    $this->artisan('sms:process')->assertSuccessful();

    $failedStatus = NotificationStatus::query()->where('name', 'failed')->firstOrFail();
    expect(SmsNotification::query()->where('notification_status_id', $failedStatus->id)->count())->toBe(3);
});

test('sms:process command reports no pending when queue is empty', function () {
    $this->artisan('sms:process')
        ->expectsOutput('No queued SMS notifications.')
        ->assertSuccessful();
});

test('ProcessSmsNotification uses TextBee when the sms driver is textbee', function () {
    Http::fake(['https://api.textbee.dev/*' => Http::response([
        'data' => [
            'success' => true,
            'smsBatchId' => 'textbee-batch-1',
            'recipientCount' => 1,
        ],
    ], 200)]);
    config([
        'services.sms.driver' => 'textbee',
        'services.textbee.enabled' => true,
        'services.textbee.api_key' => 'test-key',
    ]);

    $sms = SmsNotification::factory()->create(['recipient' => '+639171234567']);

    app(ProcessSmsNotification::class)->handle($sms);

    expect($sms->fresh()->status->name)->toBe('sent')
        ->and($sms->fresh()->provider_name)->toBe('textbee')
        ->and($sms->fresh()->provider_reference)->toBe('textbee-batch-1')
        ->and($sms->fresh()->provider_status)->toBe('queued')
        ->and($sms->fresh()->delivery_state)->toBe('accepted')
        ->and($sms->fresh()->send_attempt_count)->toBe(1)
        ->and($sms->fresh()->provider_accepted_at)->not->toBeNull();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.textbee.dev/api/v1/gateway/send-sms'
            && $request->hasHeader('x-api-key', 'test-key')
            && $request['recipients'] === ['+639171234567']
            && str_starts_with($request['message'], 'EyeCare: ');
    });
});

test('TextBee uses the configured endpoint and timeout', function (): void {
    Http::fake(['https://sms.example.test/*' => Http::response(['success' => true], 200)]);
    config([
        'services.textbee.enabled' => true,
        'services.textbee.api_key' => str_repeat('textbee-key', 3),
        'services.textbee.endpoint' => 'https://sms.example.test/send',
        'services.textbee.timeout' => 4,
    ]);

    app(TextBeeService::class)->send('+639171234567', 'Test message');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sms.example.test/send');
});

test('disabled TextBee reports unsuccessful delivery', function (): void {
    Http::fake();
    config(['services.textbee.enabled' => false]);

    expect(app(TextBeeService::class)->send('+639171234567', 'Test message'))->toBeFalse();

    Http::assertNothingSent();
});

test('ProcessSmsNotification marks sms as failed when TextBee returns an error', function () {
    Http::fake(['https://api.textbee.dev/*' => Http::response([], 500)]);
    config([
        'services.sms.driver' => 'textbee',
        'services.textbee.enabled' => true,
    ]);

    $sms = SmsNotification::factory()->create();

    app(ProcessSmsNotification::class)->handle($sms);

    expect($sms->fresh()->status->name)->toBe('failed')
        ->and($sms->fresh()->failure_reason)->not->toBeNull();
});

test('ProcessSmsNotification treats an explicit TextBee rejection as a failure', function (): void {
    Http::fake(['https://api.textbee.dev/*' => Http::response(['data' => ['success' => false]], 200)]);
    config([
        'services.sms.driver' => 'textbee',
        'services.textbee.enabled' => true,
    ]);

    $sms = SmsNotification::factory()->create();

    app(ProcessSmsNotification::class)->handle($sms);

    expect($sms->fresh()->status->name)->toBe('failed')
        ->and($sms->fresh()->failure_reason)->toBe('TextBee rejected the SMS request.');
});

test('TextBee quota limits fail without queue retries', function (): void {
    Http::fake(['https://api.textbee.dev/*' => Http::response(['message' => 'daily quota reached'], 429)]);
    config([
        'services.sms.driver' => 'textbee',
        'services.textbee.enabled' => true,
    ]);

    $sms = SmsNotification::factory()->create();

    app(ProcessSmsNotification::class)->handle($sms, throwOnProviderFailure: true);

    expect($sms->fresh()->status->name)->toBe('failed')
        ->and($sms->fresh()->delivery_state)->toBe('failed')
        ->and($sms->fresh()->provider_status)->toBe('quota_exceeded')
        ->and($sms->fresh()->failure_reason)->toContain('plan limit');
    Http::assertSentCount(1);
});

test('TextBee uncertain sends become unknown and are not automatically resent', function (): void {
    Http::fake(['https://api.textbee.dev/*' => Http::response([], 500)]);
    config([
        'services.sms.driver' => 'textbee',
        'services.textbee.enabled' => true,
    ]);

    $sms = SmsNotification::factory()->create();

    app(ProcessSmsNotification::class)->handle($sms, throwOnProviderFailure: true);
    app(ProcessSmsNotification::class)->handle($sms->fresh(), throwOnProviderFailure: true);

    expect($sms->fresh()->status->name)->toBe('failed')
        ->and($sms->fresh()->delivery_state)->toBe('unknown')
        ->and($sms->fresh()->send_attempt_count)->toBe(1);
    Http::assertSentCount(1);
});

test('TextBee webhook verifies signatures and processes duplicate events once', function (): void {
    $sentStatus = NotificationStatus::query()->where('name', 'sent')->firstOrFail();
    $sms = SmsNotification::factory()->create([
        'notification_status_id' => $sentStatus->id,
        'provider_name' => 'textbee',
        'provider_reference' => 'textbee-batch-webhook',
        'provider_message_id' => 'textbee-message-webhook',
        'provider_status' => 'queued',
        'delivery_state' => 'accepted',
    ]);
    $secret = str_repeat('webhook-secret-', 2);
    config(['services.textbee.webhook_secret' => $secret]);

    $payload = [
        'smsId' => 'textbee-message-webhook',
        'smsBatchId' => 'textbee-batch-webhook',
        'webhookEvent' => 'MESSAGE_DELIVERED',
        'idempotencyKey' => 'textbee-event-delivered-1',
        'status' => 'delivered',
        'deliveredAt' => now()->subMinute()->toIso8601String(),
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $body, $secret);

    $this->call('POST', '/api/webhooks/textbee', [], [], [], [
        'HTTP_X_SIGNATURE' => 'invalid-signature',
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertUnauthorized();

    $this->call('POST', '/api/webhooks/textbee', [], [], [], [
        'HTTP_X_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertNoContent();

    $this->call('POST', '/api/webhooks/textbee', [], [], [], [
        'HTTP_X_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertNoContent();

    expect($sms->fresh()->delivery_state)->toBe('delivered')
        ->and($sms->fresh()->provider_last_event_id)->toBe('textbee-event-delivered-1')
        ->and(DB::table('sms_provider_webhook_events')->count())->toBe(1);
});

test('TextBee status polling updates accepted messages from the batch endpoint', function (): void {
    Http::fake([
        'https://api.textbee.dev/api/v1/gateway/messages*' => Http::response([
            'data' => [[
                '_id' => 'textbee-message-polled',
                'smsBatch' => 'textbee-batch-polled',
                'status' => 'delivered',
                'deliveredAt' => now()->subMinute()->toIso8601String(),
            ]],
        ], 200),
    ]);
    config([
        'services.textbee.enabled' => true,
        'services.textbee.api_key' => 'test-key',
    ]);

    $sentStatus = NotificationStatus::query()->where('name', 'sent')->firstOrFail();
    $sms = SmsNotification::factory()->create([
        'notification_status_id' => $sentStatus->id,
        'provider_name' => 'textbee',
        'provider_reference' => 'textbee-batch-polled',
        'provider_message_id' => 'textbee-message-polled',
        'provider_status' => 'queued',
        'delivery_state' => 'accepted',
    ]);

    $this->artisan('sms:textbee:sync')->assertSuccessful();

    expect($sms->fresh()->delivery_state)->toBe('delivered')
        ->and($sms->fresh()->provider_status)->toBe('delivered')
        ->and($sms->fresh()->provider_status_checked_at)->not->toBeNull();
    Http::assertSentCount(1);
});

test('ProcessSmsNotification marks sms as failed without HTTP call when TextBee is disabled', function () {
    Http::fake();
    Log::spy();
    config([
        'services.sms.driver' => 'textbee',
        'services.textbee.enabled' => false,
    ]);

    $sms = SmsNotification::factory()->create();

    app(ProcessSmsNotification::class)->handle($sms);

    expect($sms->fresh()->status->name)->toBe('failed')
        ->and($sms->fresh()->failure_reason)->toBe('SMS provider is disabled.');
    Http::assertNothingSent();
    Log::shouldHaveReceived('info')
        ->once()
        ->with('SMS delivery skipped (TextBee disabled)', ['driver' => 'textbee']);
});

test('TextBee gateway includes deviceId only when configured', function () {
    Http::fake(['https://api.textbee.dev/*' => Http::response(['success' => true], 200)]);
    config([
        'services.textbee.enabled' => true,
        'services.textbee.device_id' => 'device-123',
    ]);

    app(TextBeeService::class)->send('+639171234567', 'Test message');

    Http::assertSent(fn ($request) => $request['deviceId'] === 'device-123');
});

test('sms notification does not have a legacy order relationship', function () {
    $sms = new SmsNotification;

    expect(method_exists($sms, 'order'))->toBeFalse();
});

test('sms notification can reference an appointment or canonical job order', function () {
    $appointmentSms = SmsNotification::factory()->create();
    $patient = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create(['patient_id' => $patient->id]);
    $jobOrderSms = SmsNotification::factory()->create([
        'appointment_id' => null,
        'job_order_id' => $jobOrder->id,
    ]);

    expect($appointmentSms->appointment)->not->toBeNull()
        ->and($appointmentSms->jobOrder)->toBeNull()
        ->and($jobOrderSms->appointment)->toBeNull()
        ->and($jobOrderSms->jobOrder->is($jobOrder))->toBeTrue()
        ->and($jobOrderSms->getAttributes())->not->toHaveKey('order_id');
});

test('SendSmsJob marks a queued notification failed after final job failure', function (): void {
    $sms = SmsNotification::factory()->create();
    $exception = new RuntimeException('provider timeout');

    $this->mock(ProcessSmsNotification::class, function ($mock) use ($exception): void {
        $mock->shouldReceive('handle')->once()->andThrow($exception);
    });

    $job = new SendSmsJob($sms);

    expect(fn () => $job->handle(app(ProcessSmsNotification::class)))
        ->toThrow(RuntimeException::class);

    $job->failed($exception);

    expect($sms->fresh()->status->name)->toBe('failed')
        ->and($sms->fresh()->failure_reason)->toBe('provider timeout');
});

test('SendSmsJob retries enabled provider failures instead of finalizing immediately', function (): void {
    Http::fake(['https://api.semaphore.co/*' => Http::response([], 429)]);
    config(['services.semaphore.enabled' => true]);

    $sms = SmsNotification::factory()->create();
    $job = new SendSmsJob($sms);

    expect(fn () => $job->handle(app(ProcessSmsNotification::class)))
        ->toThrow(SmsDeliveryException::class);

    expect($sms->fresh()->status->name)->toBe('queued');

    $job->failed(new SmsDeliveryException('SMS provider returned a failure response.'));

    expect($sms->fresh()->status->name)->toBe('failed')
        ->and($sms->fresh()->failure_reason)->toBe('SMS provider returned a failure response.');
});

test('SendSmsJob is unique by SMS notification id', function (): void {
    $sms = SmsNotification::factory()->create();
    $job = new SendSmsJob($sms);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe((string) $sms->id)
        ->and($job->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class);
});

test('SendSmsJob failure callback does not overwrite a completed notification', function (): void {
    $sentStatus = NotificationStatus::query()->where('name', 'sent')->firstOrFail();
    $sms = SmsNotification::factory()->create(['notification_status_id' => $sentStatus->id]);

    (new SendSmsJob($sms))->failed(new RuntimeException('late failure'));

    expect($sms->fresh()->status->name)->toBe('sent')
        ->and($sms->fresh()->failure_reason)->toBeNull();
});
