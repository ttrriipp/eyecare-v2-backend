<?php

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\SmsNotification;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    Http::fake();
});

test('customer can cancel their own pending appointment', function () {
    $customer = User::factory()->customer()->create();
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $appointment = Appointment::factory()->create(['customer_id' => $customer->id, 'appointment_status_id' => $pending->id]);

    $response = $this->actingAs($customer)->postJson("/api/appointments/{$appointment->id}/cancel");

    $response->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($appointment->fresh()->status->name)->toBe('cancelled');
});

test('customer can cancel their own confirmed appointment', function () {
    $customer = User::factory()->customer()->create();
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $appointment = Appointment::factory()->create(['customer_id' => $customer->id, 'appointment_status_id' => $confirmed->id]);

    $this->actingAs($customer)
        ->postJson("/api/appointments/{$appointment->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('cancellation creates an sms notification record', function () {
    $customer = User::factory()->customer()->create(['phone' => '09171234567']);
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $appointment = Appointment::factory()->create(['customer_id' => $customer->id, 'appointment_status_id' => $pending->id]);

    $this->actingAs($customer)->postJson("/api/appointments/{$appointment->id}/cancel");

    $this->assertDatabaseHas(SmsNotification::class, [
        'appointment_id' => $appointment->id,
        'event' => 'appointment_cancelled',
    ]);
});

test('customer cannot cancel another customers appointment', function () {
    $customer = User::factory()->customer()->create();
    $other = User::factory()->customer()->create();
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $appointment = Appointment::factory()->create(['customer_id' => $other->id, 'appointment_status_id' => $pending->id]);

    $this->actingAs($customer)
        ->postJson("/api/appointments/{$appointment->id}/cancel")
        ->assertForbidden();
});

test('customer cannot cancel a completed appointment', function () {
    $customer = User::factory()->customer()->create();
    $completed = AppointmentStatus::query()->where('name', 'completed')->firstOrFail();
    $appointment = Appointment::factory()->create(['customer_id' => $customer->id, 'appointment_status_id' => $completed->id]);

    $this->actingAs($customer)
        ->postJson("/api/appointments/{$appointment->id}/cancel")
        ->assertUnprocessable();
});
