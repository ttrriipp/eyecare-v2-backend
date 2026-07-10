<?php

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\BillingStatus;
use App\Models\NotificationStatus;
use App\Models\OrderStatus;
use App\Models\PaymentStatus;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Database\Seeders\PaymentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('appointment statuses are seeded idempotently with approved names', function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);

    expect(AppointmentStatus::query()->pluck('name')->all())
        ->toEqualCanonicalizing([
            'pending',
            'confirmed',
            'arrived',
            'completed',
            'no_show',
            'cancelled',
        ])
        ->and(AppointmentStatus::query()->count())->toBe(6);
});

test('appointment status seeding migrates legacy rescheduled appointments to pending', function () {
    $rescheduledStatus = AppointmentStatus::query()->create([
        'name' => 'rescheduled',
    ]);
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $rescheduledStatus->id,
    ]);

    $this->seed(AppointmentStatusSeeder::class);

    expect($appointment->refresh()->status->name)->toBe('pending')
        ->and(AppointmentStatus::query()->where('name', 'rescheduled')->exists())->toBeFalse();
});

test('sms notification statuses are seeded idempotently with approved names', function () {
    $this->seed(NotificationStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);

    expect(NotificationStatus::query()->pluck('name')->all())
        ->toEqualCanonicalizing([
            'queued',
            'sent',
            'failed',
            'cancelled',
        ])
        ->and(NotificationStatus::query()->count())->toBe(4);
});

test('order statuses are seeded idempotently with approved names', function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(OrderStatusSeeder::class);

    expect(OrderStatus::query()->pluck('name')->all())
        ->toEqualCanonicalizing([
            'requested',
            'confirmed',
            'processing',
            'ready_for_pickup',
            'completed',
            'cancelled',
        ])
        ->and(OrderStatus::query()->count())->toBe(6);
});

test('billing statuses are seeded idempotently with approved names', function () {
    $this->seed(BillingStatusSeeder::class);
    $this->seed(BillingStatusSeeder::class);

    expect(BillingStatus::query()->pluck('name')->all())
        ->toEqualCanonicalizing([
            'issued',
            'partially_paid',
            'paid',
            'voided',
        ])
        ->and(BillingStatus::query()->count())->toBe(4);
});

test('payment statuses are seeded idempotently with approved names', function () {
    $this->seed(PaymentStatusSeeder::class);
    $this->seed(PaymentStatusSeeder::class);

    expect(PaymentStatus::query()->pluck('name')->all())
        ->toEqualCanonicalizing([
            'posted',
            'voided',
        ])
        ->and(PaymentStatus::query()->count())->toBe(2);
});
