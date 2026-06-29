<?php

use App\Models\Prescription;
use App\Models\User;
use App\Notifications\PrescriptionsExpiringNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('command notifies staff about prescriptions expiring within 30 days', function () {
    Notification::fake();

    $staff = User::factory()->staff()->create();
    $admin = User::factory()->admin()->create();
    $customer = User::factory()->customer()->create();

    // Expiring in 10 days — should trigger
    Prescription::factory()->create([
        'customer_id' => $customer->id,
        'expires_at' => now()->addDays(10),
    ]);

    // Expiring in 60 days — should NOT trigger
    Prescription::factory()->create([
        'customer_id' => $customer->id,
        'expires_at' => now()->addDays(60),
    ]);

    $this->artisan('prescriptions:check-expiry')->assertSuccessful();

    Notification::assertSentTo($staff, PrescriptionsExpiringNotification::class);
    Notification::assertSentTo($admin, PrescriptionsExpiringNotification::class);
    Notification::assertNotSentTo($customer, PrescriptionsExpiringNotification::class);
});

test('command is idempotent — does not re-notify within 30 days', function () {
    Notification::fake();

    User::factory()->staff()->create();

    Prescription::factory()->create([
        'expires_at' => now()->addDays(10),
        'last_expiry_notified_at' => now()->subDay(),
    ]);

    $this->artisan('prescriptions:check-expiry')->assertSuccessful();

    Notification::assertNothingSent();
});

test('command updates last_expiry_notified_at after notifying', function () {
    Notification::fake();

    User::factory()->staff()->create();

    $prescription = Prescription::factory()->create([
        'expires_at' => now()->addDays(10),
    ]);

    $this->artisan('prescriptions:check-expiry')->assertSuccessful();

    expect($prescription->fresh()->last_expiry_notified_at)->not->toBeNull();
});
