<?php

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\User;
use App\Models\VisitReason;
use Database\Seeders\AppointmentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
});

test('authenticated customers can create pending appointments', function () {
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create();

    $response = $this->actingAs($customer, 'sanctum')
        ->postJson('/api/appointments', [
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => now()->addDay()->toISOString(),
            'contact_notes' => 'Please call before arrival.',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.visit_reason', $visitReason->name)
        ->assertJsonPath('data.contact_notes', 'Please call before arrival.');

    $this->assertDatabaseHas(Appointment::class, [
        'customer_id' => $customer->id,
        'visit_reason_id' => $visitReason->id,
        'appointment_status_id' => AppointmentStatus::query()->where('name', 'pending')->value('id'),
        'contact_notes' => 'Please call before arrival.',
    ]);
});

test('customers can list only their own appointments', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();

    $ownAppointments = Appointment::factory()->count(2)->create([
        'customer_id' => $customer->id,
    ]);

    Appointment::factory()->create([
        'customer_id' => $otherCustomer->id,
    ]);

    $response = $this->actingAs($customer, 'sanctum')
        ->getJson('/api/appointments');

    $response->assertSuccessful();

    $appointmentIds = collect($response->json('data'))->pluck('id')->all();

    expect($appointmentIds)
        ->toEqualCanonicalizing($ownAppointments->pluck('id')->all())
        ->and($appointmentIds)->toHaveCount(2);
});

test('customers can view only their own appointment', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/appointments/{$appointment->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $appointment->id);
});

test('customers cannot view another customers appointment', function () {
    $customer = User::factory()->customer()->create();
    $otherAppointment = Appointment::factory()->create();

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/appointments/{$otherAppointment->id}")
        ->assertNotFound();
});

test('appointment booking rejects invalid schedule visit reason and contact data', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/appointments', [
            'visit_reason_id' => 99999,
            'scheduled_at' => now()->subDay()->toISOString(),
            'contact_notes' => str_repeat('a', 1001),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['visit_reason_id', 'scheduled_at', 'contact_notes']);
});

test('unauthenticated users cannot access appointment endpoints', function () {
    $appointment = Appointment::factory()->create();

    $this->postJson('/api/appointments', [])->assertUnauthorized();
    $this->getJson('/api/appointments')->assertUnauthorized();
    $this->getJson("/api/appointments/{$appointment->id}")->assertUnauthorized();
});

test('booking is rejected when slot conflicts within 30 minutes', function () {
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create();
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();

    // Existing appointment at 10:00
    Appointment::factory()->create([
        'appointment_status_id' => $confirmed->id,
        'scheduled_at' => now()->addDay()->setHour(10)->setMinute(0)->setSecond(0),
    ]);

    // New booking at 10:20 — within 30 min window
    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/appointments', [
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => now()->addDay()->setHour(10)->setMinute(20)->setSecond(0)->toDateTimeString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scheduled_at');
});

test('booking is allowed when slot is outside 30 minute window', function () {
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create();
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();

    Appointment::factory()->create([
        'appointment_status_id' => $confirmed->id,
        'scheduled_at' => now()->addDay()->setHour(10)->setMinute(0)->setSecond(0),
    ]);

    // New booking at 11:00 — outside 30 min window
    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/appointments', [
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => now()->addDay()->setHour(11)->setMinute(0)->setSecond(0)->toDateTimeString(),
        ])
        ->assertCreated();
});

test('cancelled appointments do not block new bookings at same time', function () {
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create();
    $cancelled = AppointmentStatus::query()->where('name', 'cancelled')->firstOrFail();

    Appointment::factory()->create([
        'appointment_status_id' => $cancelled->id,
        'scheduled_at' => now()->addDay()->setHour(10)->setMinute(0)->setSecond(0),
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/appointments', [
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => now()->addDay()->setHour(10)->setMinute(0)->setSecond(0)->toDateTimeString(),
        ])
        ->assertCreated();
});
