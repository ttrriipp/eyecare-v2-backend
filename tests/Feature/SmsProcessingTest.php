<?php

use App\Actions\Sms\ProcessSmsNotification;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
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
    config(['services.semaphore.enabled' => false]);

    $sms = SmsNotification::factory()->create();

    app(ProcessSmsNotification::class)->handle($sms);

    expect($sms->fresh()->status->name)->toBe('sent');
    Http::assertNothingSent();
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
