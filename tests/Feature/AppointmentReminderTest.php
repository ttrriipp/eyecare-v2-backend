<?php

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\SmsNotification;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

test('it creates reminders for tomorrow confirmed appointments', function () {
    $confirmedId = AppointmentStatus::query()->where('name', 'confirmed')->value('id');
    $customer = User::factory()->customer()->create(['phone' => '09171234567']);

    Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $confirmedId,
        'scheduled_at' => today()->addDay()->setTime(10, 0),
    ]);

    $this->artisan('appointments:send-reminders')
        ->assertSuccessful()
        ->expectsOutputToContain('Created 1 appointment reminder');

    expect(SmsNotification::query()->where('event', 'appointment_reminder')->count())->toBe(1);
    expect(SmsNotification::query()->first()->recipient)->toBe('09171234567');
});

test('it skips appointments that are not confirmed', function () {
    $pendingId = AppointmentStatus::query()->where('name', 'pending')->value('id');
    $customer = User::factory()->customer()->create(['phone' => '09170000000']);

    Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $pendingId,
        'scheduled_at' => today()->addDay()->setTime(14, 0),
    ]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    expect(SmsNotification::query()->where('event', 'appointment_reminder')->count())->toBe(0);
});

test('it skips appointments not scheduled for tomorrow', function () {
    $confirmedId = AppointmentStatus::query()->where('name', 'confirmed')->value('id');
    $customer = User::factory()->customer()->create(['phone' => '09170000001']);

    // Today's appointment — should not get a reminder
    Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $confirmedId,
        'scheduled_at' => today()->setTime(15, 0),
    ]);

    // Day-after-tomorrow — should not get a reminder
    Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $confirmedId,
        'scheduled_at' => today()->addDays(2)->setTime(9, 0),
    ]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    expect(SmsNotification::query()->where('event', 'appointment_reminder')->count())->toBe(0);
});

test('it is idempotent — does not create duplicate reminders', function () {
    $confirmedId = AppointmentStatus::query()->where('name', 'confirmed')->value('id');
    $customer = User::factory()->customer()->create(['phone' => '09171112222']);

    Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $confirmedId,
        'scheduled_at' => today()->addDay()->setTime(11, 30),
    ]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();
    $this->artisan('appointments:send-reminders')->assertSuccessful();

    expect(SmsNotification::query()->where('event', 'appointment_reminder')->count())->toBe(1);
});

test('it skips customers without a phone number', function () {
    $confirmedId = AppointmentStatus::query()->where('name', 'confirmed')->value('id');
    $customer = User::factory()->customer()->create(['phone' => null]);

    Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $confirmedId,
        'scheduled_at' => today()->addDay()->setTime(9, 0),
    ]);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    expect(SmsNotification::query()->where('event', 'appointment_reminder')->count())->toBe(0);
});
