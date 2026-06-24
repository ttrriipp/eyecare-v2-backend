<?php

use App\Models\Appointment;
use App\Models\Billing;
use App\Models\Conversation;
use App\Models\Feedback;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\User;
use Database\Seeders\ClinicWorkflowSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('demo customer account exists', function () {
    expect(User::query()->where('email', 'customer@eyecare.test')->exists())->toBeTrue();
});

test('demo workflow seeds a confirmed appointment for the customer', function () {
    $customer = User::query()->where('email', 'customer@eyecare.test')->firstOrFail();

    $appointment = Appointment::query()
        ->where('customer_id', $customer->id)
        ->whereHas('status', fn ($q) => $q->where('name', 'confirmed'))
        ->first();

    expect($appointment)->not->toBeNull();
});

test('demo workflow seeds a prescription linked to the appointment', function () {
    $customer = User::query()->where('email', 'customer@eyecare.test')->firstOrFail();

    expect(
        Prescription::query()->where('customer_id', $customer->id)->exists()
    )->toBeTrue();
});

test('demo workflow seeds a prescription order with billing and partial payment', function () {
    $order = Order::query()->where('order_number', 'ORD-DEMO-0001')->first();

    expect($order)->not->toBeNull()
        ->and($order->is_non_prescription)->toBeFalse();

    $billing = Billing::query()->where('billable_type', Order::class)->where('billable_id', $order->id)->first();
    expect($billing)->not->toBeNull()
        ->and((float) $billing->balance_due)->toBeGreaterThan(0);

    expect(Payment::query()->where('billing_id', $billing->id)->exists())->toBeTrue();
});

test('demo workflow seeds a non-prescription completed order with paid billing', function () {
    $order = Order::query()->where('order_number', 'ORD-DEMO-0002')->first();

    expect($order)->not->toBeNull()
        ->and($order->is_non_prescription)->toBeTrue();

    $billing = Billing::query()->where('billable_type', Order::class)->where('billable_id', $order->id)->first();
    expect($billing)->not->toBeNull()
        ->and((float) $billing->balance_due)->toBe(0.0);
});

test('demo workflow seeds a conversation with a staff reply', function () {
    $customer = User::query()->where('email', 'customer@eyecare.test')->firstOrFail();

    $conversation = Conversation::query()->where('customer_id', $customer->id)->first();

    expect($conversation)->not->toBeNull()
        ->and($conversation->messages()->count())->toBe(2);
});

test('demo workflow seeds feedback on a completed appointment', function () {
    $customer = User::query()->where('email', 'customer@eyecare.test')->firstOrFail();

    $feedback = Feedback::query()
        ->where('customer_id', $customer->id)
        ->whereNotNull('staff_reply')
        ->first();

    expect($feedback)->not->toBeNull()
        ->and($feedback->rating)->toBe(5);
});

test('clinic workflow seeder is idempotent', function () {
    $this->seed(ClinicWorkflowSeeder::class);

    expect(Order::query()->where('order_number', 'ORD-DEMO-0001')->count())->toBe(1)
        ->and(Order::query()->where('order_number', 'ORD-DEMO-0002')->count())->toBe(1);
});
