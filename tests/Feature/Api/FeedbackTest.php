<?php

use App\Models\Appointment;
use App\Models\Feedback;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(OrderStatusSeeder::class);
});

test('customers can submit feedback for a completed appointment', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->completed()->create(['customer_id' => $customer->id]);

    $response = $this->actingAs($customer, 'sanctum')
        ->postJson('/api/feedback', [
            'appointment_id' => $appointment->id,
            'rating' => 5,
            'comment' => 'Great service!',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.comment', 'Great service!');

    $this->assertDatabaseHas(Feedback::class, [
        'customer_id' => $customer->id,
        'appointment_id' => $appointment->id,
        'rating' => 5,
    ]);
});

test('customers can submit feedback for a completed order', function () {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->completed()->create(['customer_id' => $customer->id]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/feedback', [
            'order_id' => $order->id,
            'rating' => 4,
        ])
        ->assertCreated()
        ->assertJsonPath('data.rating', 4);
});

test('feedback is rejected for a non-completed appointment', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/feedback', [
            'appointment_id' => $appointment->id,
            'rating' => 3,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['appointment_id']);
});

test('feedback is rejected for a non-completed order', function () {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/feedback', [
            'order_id' => $order->id,
            'rating' => 3,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['order_id']);
});

test('customers cannot submit feedback for another customers appointment', function () {
    $customer = User::factory()->customer()->create();
    $otherAppointment = Appointment::factory()->completed()->create();

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/feedback', [
            'appointment_id' => $otherAppointment->id,
            'rating' => 5,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['appointment_id']);
});

test('feedback requires a rating between 1 and 5', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->completed()->create(['customer_id' => $customer->id]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/feedback', [
            'appointment_id' => $appointment->id,
            'rating' => 6,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rating']);
});

test('feedback requires either an appointment or order', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/feedback', ['rating' => 4])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['appointment_id']);
});

test('unauthenticated users cannot submit feedback', function () {
    $this->postJson('/api/feedback', [])->assertUnauthorized();
});
