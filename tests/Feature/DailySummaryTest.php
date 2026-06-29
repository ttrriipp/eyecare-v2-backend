<?php

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentStatus;
use App\Models\User;
use App\Notifications\DailySummaryNotification;
use Database\Seeders\AppointmentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
});

test('daily summary command sends notification to admins with correct stats', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create();
    User::factory()->staff()->create();

    // Completed appointment today
    $completed = AppointmentStatus::query()->where('name', 'completed')->firstOrFail();
    Appointment::factory()->create([
        'appointment_status_id' => $completed->id,
        'updated_at' => now(),
    ]);

    // Payment posted today
    Payment::factory()->posted()->create([
        'amount' => 500,
        'created_at' => now(),
    ]);

    // New order today
    Order::factory()->create(['created_at' => now()]);

    $this->artisan('clinic:daily-summary')->assertSuccessful();

    Notification::assertSentTo($admin, DailySummaryNotification::class);
});

test('daily summary command succeeds with no data', function () {
    Notification::fake();

    User::factory()->admin()->create();

    $this->artisan('clinic:daily-summary')->assertSuccessful();

    Notification::assertSentTo(
        User::query()->whereHas('role', fn ($q) => $q->where('name', 'admin'))->first(),
        DailySummaryNotification::class,
        fn ($n) => $n->completedAppointments === 0 && (float) $n->revenue === 0.0,
    );
});
