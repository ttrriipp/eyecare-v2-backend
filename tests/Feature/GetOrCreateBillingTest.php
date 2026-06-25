<?php

use App\Actions\Billing\GetOrCreateBilling;
use App\Models\Appointment;
use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\BillingStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(BillingStatusSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
});

it('creates a new billing when none exists for the customer and appointment', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create(['customer_id' => $customer->id]);

    $billing = app(GetOrCreateBilling::class)->handle($customer->id, $appointment->id);

    expect($billing)->toBeInstanceOf(Billing::class)
        ->and($billing->customer_id)->toBe($customer->id)
        ->and($billing->appointment_id)->toBe($appointment->id)
        ->and($billing->status->name)->toBe('issued')
        ->and($billing->wasRecentlyCreated)->toBeTrue();
});

it('reuses existing billing for same customer + appointment', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create(['customer_id' => $customer->id]);

    $first = app(GetOrCreateBilling::class)->handle($customer->id, $appointment->id);
    $second = app(GetOrCreateBilling::class)->handle($customer->id, $appointment->id);

    expect($second->id)->toBe($first->id)
        ->and(Billing::query()->where('appointment_id', $appointment->id)->count())->toBe(1);
});

it('always creates a new billing when appointment_id is null', function () {
    $customer = User::factory()->customer()->create();

    $first = app(GetOrCreateBilling::class)->handle($customer->id, null);
    $second = app(GetOrCreateBilling::class)->handle($customer->id, null);

    expect($second->id)->not->toBe($first->id)
        ->and(Billing::query()->where('customer_id', $customer->id)->count())->toBe(2);
});

it('does not reuse a voided billing', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create(['customer_id' => $customer->id]);

    $voidedStatus = BillingStatus::query()->where('name', 'voided')->firstOrFail();
    $voided = Billing::factory()->create([
        'customer_id' => $customer->id,
        'appointment_id' => $appointment->id,
        'billing_status_id' => $voidedStatus->id,
    ]);

    $new = app(GetOrCreateBilling::class)->handle($customer->id, $appointment->id);

    expect($new->id)->not->toBe($voided->id)
        ->and($new->status->name)->toBe('issued');
});
