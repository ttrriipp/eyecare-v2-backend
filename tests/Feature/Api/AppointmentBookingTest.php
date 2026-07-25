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
    $optometrist = User::factory()->optometrist()->create();

    $response = $this->actingAs($customer, 'sanctum')
        ->postJson('/api/appointments', [
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => now()->next('Monday')->setTime(10, 0)->toISOString(),
            'optometrist_id' => $optometrist->id,
            'contact_notes' => 'Please call before arrival.',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.source', 'mobile_app')
        ->assertJsonPath('data.assigned_optometrist.id', $optometrist->id)
        ->assertJsonPath('data.visit_reason', $visitReason->name)
        ->assertJsonPath('data.contact_notes', 'Please call before arrival.');

    expect(str_starts_with($response->json('data.appointment_number'), 'APT-'))->toBeTrue();

    $this->assertDatabaseHas(Appointment::class, [
        'patient_id' => $customer->patient->id,
        'visit_reason_id' => $visitReason->id,
        'source' => 'mobile_app',
        'optometrist_id' => $optometrist->id,
        'appointment_status_id' => AppointmentStatus::query()->where('name', 'pending')->value('id'),
        'contact_notes' => 'Please call before arrival.',
    ]);

    expect(Appointment::query()->first()?->appointment_number)->toBe('APT-'.now()->format('Y').'-000001');
});

test('booking rejects times outside clinic hours and closed days', function (string $scheduledAt) {
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create(['duration_minutes' => 30]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/appointments', [
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => $scheduledAt,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scheduled_at');
})->with([
    'before opening' => '2026-07-13 08:30:00',
    'closed Sunday' => '2026-07-12 10:00:00',
]);

test('customers can list only their own appointments', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();

    $ownAppointments = Appointment::factory()->count(2)->create([
        'patient_id' => $customer->patient->id,
    ]);

    Appointment::factory()->create([
        'patient_id' => $otherCustomer->patient->id,
    ]);

    $response = $this->actingAs($customer, 'sanctum')
        ->getJson('/api/appointments');

    $response->assertSuccessful();

    $appointmentIds = collect($response->json('data'))->pluck('id')->all();
    $appointmentNumbers = collect($response->json('data'))->pluck('appointment_number')->all();

    expect($appointmentIds)
        ->toEqualCanonicalizing($ownAppointments->pluck('id')->all())
        ->and($appointmentIds)->toHaveCount(2)
        ->and($appointmentNumbers)->toEqualCanonicalizing($ownAppointments->pluck('appointment_number')->all());
});

test('customers can view only their own appointment', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $customer->patient->id,
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
    $visitReason = VisitReason::factory()->create(['duration_minutes' => 30]);
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();

    $appointmentDate = now()->next('Monday');

    // Existing appointment at 10:00 with 30-min duration (ends 10:30)
    Appointment::factory()->create([
        'appointment_status_id' => $confirmed->id,
        'visit_reason_id' => $visitReason->id,
        'scheduled_at' => $appointmentDate->copy()->setTime(10, 0),
    ]);

    // New booking at 10:20 — overlaps the 10:00–10:30 window
    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/appointments', [
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => $appointmentDate->copy()->setTime(10, 20)->toDateTimeString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scheduled_at');
});

test('booking stale availability returns a structured slot unavailable response', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create(['duration_minutes' => 30]);
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $appointmentDate = now()->next('Monday')->setTime(10, 0);

    Appointment::factory()->create([
        'patient_id' => $otherCustomer->patient->id,
        'appointment_status_id' => $confirmed->id,
        'visit_reason_id' => $visitReason->id,
        'scheduled_at' => $appointmentDate,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/appointments', [
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => $appointmentDate->toIso8601String(),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'SLOT_UNAVAILABLE')
        ->assertJsonPath('availability.date', $appointmentDate->toDateString())
        ->assertJsonPath('availability.visit_reason_id', $visitReason->id)
        ->assertJsonPath('availability.optometrist_id', null)
        ->assertJsonValidationErrors('scheduled_at');

    expect(Appointment::query()->where('patient_id', $customer->id)->count())->toBe(0);
});

test('booking is allowed when slot is outside 30 minute window', function () {
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create();
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();

    $appointmentDate = now()->next('Monday');

    Appointment::factory()->create([
        'appointment_status_id' => $confirmed->id,
        'scheduled_at' => $appointmentDate->copy()->setTime(10, 0),
    ]);

    // New booking at 11:00 — outside 30 min window
    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/appointments', [
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => $appointmentDate->copy()->setTime(11, 0)->toDateTimeString(),
        ])
        ->assertCreated();
});

test('cancelled appointments do not block new bookings at same time', function () {
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create();
    $cancelled = AppointmentStatus::query()->where('name', 'cancelled')->firstOrFail();

    $appointmentDate = now()->next('Monday');

    Appointment::factory()->create([
        'appointment_status_id' => $cancelled->id,
        'scheduled_at' => $appointmentDate->copy()->setTime(10, 0),
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/appointments', [
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => $appointmentDate->copy()->setTime(10, 0)->toDateTimeString(),
        ])
        ->assertCreated();
});
