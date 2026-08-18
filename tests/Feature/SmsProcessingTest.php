<?php

use App\Actions\Sms\ProcessSmsNotification;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
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

test('ProcessSmsNotification marks sms as sent without HTTP call when disabled', function () {
    Http::fake();
    Log::spy();
    config(['services.semaphore.enabled' => false]);

    $sms = SmsNotification::factory()->create();

    app(ProcessSmsNotification::class)->handle($sms);

    expect($sms->fresh()->status->name)->toBe('sent');
    Http::assertNothingSent();
    Log::shouldHaveReceived('info')
        ->once()
        ->with('SMS delivery skipped (Semaphore disabled)', [
            'recipient' => $sms->recipient,
            'message' => $sms->message,
        ]);
});

test('sms:process command processes queued notifications', function () {
    Http::fake();
    config(['services.semaphore.enabled' => false]);

    SmsNotification::factory()->count(3)->create();

    $this->artisan('sms:process')->assertSuccessful();

    $sentStatus = NotificationStatus::query()->where('name', 'sent')->firstOrFail();
    expect(SmsNotification::query()->where('notification_status_id', $sentStatus->id)->count())->toBe(3);
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

test('ProcessSmsNotification marks sms as sent without HTTP call when TextBee is disabled', function () {
    Http::fake();
    Log::spy();
    config([
        'services.sms.driver' => 'textbee',
        'services.textbee.enabled' => false,
    ]);

    $sms = SmsNotification::factory()->create();

    app(ProcessSmsNotification::class)->handle($sms);

    expect($sms->fresh()->status->name)->toBe('sent');
    Http::assertNothingSent();
    Log::shouldHaveReceived('info')
        ->once()
        ->with('SMS delivery skipped (TextBee disabled)', [
            'recipient' => $sms->recipient,
            'message' => $sms->message,
        ]);
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

test('sms notification does not have an order relationship', function () {
    $sms = new SmsNotification;

    expect(method_exists($sms, 'order'))->toBeFalse();
});

test('sms notification only references appointment', function () {
    $sms = SmsNotification::factory()->create();

    expect($sms->appointment)->not->toBeNull()
        ->and($sms->getAttributes())->not->toHaveKey('order_id');
});
