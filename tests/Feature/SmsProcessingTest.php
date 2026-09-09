<?php

use App\Actions\Sms\ProcessSmsNotification;
use App\Jobs\SendSmsJob;
use App\Models\JobOrder;
use App\Models\NotificationStatus;
use App\Models\Patient;
use App\Models\SmsNotification;
use App\Services\SemaphoreService;
use App\Services\TextBeeService;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    Http::fake(['https://api.semaphore.co/*' => Http::response(['status' => 'Queued'], 200)]);
    config(['services.semaphore.enabled' => true]);

    $sms = SmsNotification::factory()->create();

    app(ProcessSmsNotification::class)->handle($sms);

    expect($sms->fresh()->status->name)->toBe('sent')
        ->and($sms->fresh()->failure_reason)->toBeNull();
});

test('ProcessSmsNotification marks sms as failed when service fails', function () {
    Http::fake(['https://api.semaphore.co/*' => Http::response([], 500)]);
    config(['services.semaphore.enabled' => true]);

    $sms = SmsNotification::factory()->create();

    app(ProcessSmsNotification::class)->handle($sms);

    expect($sms->fresh()->status->name)->toBe('failed')
        ->and($sms->fresh()->failure_reason)->not->toBeNull();
});

test('Semaphore uses the configured endpoint and retry policy', function (): void {
    Http::fake([
        'https://sms.example.test/*' => Http::sequence()
            ->push([], 500)
            ->push(['status' => 'Queued'], 200),
    ]);
    config([
        'services.semaphore.enabled' => true,
        'services.semaphore.api_key' => str_repeat('semaphore-key', 3),
        'services.semaphore.endpoint' => 'https://sms.example.test/messages',
        'services.semaphore.timeout' => 3,
        'services.semaphore.retries' => 2,
    ]);

    expect(app(SemaphoreService::class)->send('+639171234567', 'Test message'))->toBeTrue();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://sms.example.test/messages');
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
    Http::fake(['https://api.textbee.dev/*' => Http::response(['success' => true], 200)]);
    config([
        'services.sms.driver' => 'textbee',
        'services.textbee.enabled' => true,
        'services.textbee.api_key' => 'test-key',
    ]);

    $sms = SmsNotification::factory()->create(['recipient' => '+639171234567']);

    app(ProcessSmsNotification::class)->handle($sms);

    expect($sms->fresh()->status->name)->toBe('sent');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.textbee.dev/api/v1/gateway/send-sms'
            && $request->hasHeader('x-api-key', 'test-key')
            && $request['recipients'] === ['+639171234567'];
    });
});

test('TextBee uses the configured endpoint and timeout', function (): void {
    Http::fake(['https://sms.example.test/*' => Http::response(['success' => true], 200)]);
    config([
        'services.textbee.enabled' => true,
        'services.textbee.api_key' => str_repeat('textbee-key', 3),
        'services.textbee.endpoint' => 'https://sms.example.test/send',
        'services.textbee.timeout' => 4,
        'services.textbee.retries' => 0,
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
        ->and($sms->fresh()->failure_reason)->toBe('SMS delivery job failed.');
});

test('SendSmsJob failure callback does not overwrite a completed notification', function (): void {
    $sentStatus = NotificationStatus::query()->where('name', 'sent')->firstOrFail();
    $sms = SmsNotification::factory()->create(['notification_status_id' => $sentStatus->id]);

    (new SendSmsJob($sms))->failed(new RuntimeException('late failure'));

    expect($sms->fresh()->status->name)->toBe('sent')
        ->and($sms->fresh()->failure_reason)->toBeNull();
});
