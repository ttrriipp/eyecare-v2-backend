<?php

use App\Filament\Resources\SmsNotifications\Pages\ListSmsNotifications;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

test('admin can view SMS log', function () {
    $admin = User::factory()->admin()->create();
    SmsNotification::factory()->count(3)->create();

    $this->actingAs($admin);

    Livewire::test(ListSmsNotifications::class)
        ->assertSuccessful();
});

test('staff cannot access SMS log', function () {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    Livewire::test(ListSmsNotifications::class)
        ->assertForbidden();
});

test('retry action resets failed sms to queued', function () {
    $admin = User::factory()->admin()->create();
    $failedStatus = NotificationStatus::query()->where('name', 'failed')->firstOrFail();
    $sms = SmsNotification::factory()->create([
        'notification_status_id' => $failedStatus->id,
        'failure_reason' => 'Provider error',
    ]);

    $this->actingAs($admin);

    Livewire::test(ListSmsNotifications::class)
        ->callTableAction('retry', $sms)
        ->assertNotified();

    expect($sms->fresh()->status->name)->toBe('queued')
        ->and($sms->fresh()->failure_reason)->toBeNull();
});
