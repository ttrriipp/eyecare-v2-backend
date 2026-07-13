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

test('customers can update contact notes on their own pending appointment', function () {
    $customer = User::factory()->customer()->create();
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $pending->id,
        'contact_notes' => 'Original note',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->patchJson("/api/appointments/{$appointment->id}/contact-note", [
            'contact_notes' => 'Please call before arriving.',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $appointment->id)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.contact_notes', 'Please call before arriving.');

    $this->assertDatabaseHas(Appointment::class, [
        'id' => $appointment->id,
        'appointment_status_id' => $pending->id,
        'contact_notes' => 'Please call before arriving.',
    ]);
});

test('customers can update contact notes on their own confirmed appointment', function () {
    $customer = User::factory()->customer()->create();
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $confirmed->id,
        'contact_notes' => 'Original note',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->patchJson("/api/appointments/{$appointment->id}/contact-note", [
            'contact_notes' => 'Updated confirmed appointment note',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.contact_notes', 'Updated confirmed appointment note');

    expect($appointment->refresh()->contact_notes)->toBe('Updated confirmed appointment note')
        ->and($appointment->status->name)->toBe('confirmed');
});

test('customers can clear contact notes', function (?string $contactNotes) {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'contact_notes' => 'Please call me.',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->patchJson("/api/appointments/{$appointment->id}/contact-note", [
            'contact_notes' => $contactNotes,
        ])
        ->assertOk()
        ->assertJsonPath('data.contact_notes', null);

    expect($appointment->refresh()->contact_notes)->toBeNull();
})->with([
    'null' => null,
    'empty' => '',
    'whitespace' => '    ',
]);

test('customers cannot update contact notes on ineligible appointment statuses', function (string $status) {
    $customer = User::factory()->customer()->create();
    $appointmentStatus = AppointmentStatus::query()->where('name', $status)->firstOrFail();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $appointmentStatus->id,
        'contact_notes' => 'Original note',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->patchJson("/api/appointments/{$appointment->id}/contact-note", [
            'contact_notes' => 'Attempted update',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('appointment');

    expect($appointment->refresh()->contact_notes)->toBe('Original note');
})->with([
    'arrived',
    'completed',
    'cancelled',
    'no_show',
]);

test('customers cannot update contact notes on another customers appointment', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create([
        'customer_id' => $otherCustomer->id,
        'contact_notes' => 'Other customer note',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->patchJson("/api/appointments/{$appointment->id}/contact-note", [
            'contact_notes' => 'Attempted update',
        ])
        ->assertForbidden();

    expect($appointment->refresh()->contact_notes)->toBe('Other customer note');
});

test('contact notes must not exceed the appointment column limit', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'contact_notes' => 'Original note',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->patchJson("/api/appointments/{$appointment->id}/contact-note", [
            'contact_notes' => str_repeat('a', 1001),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('contact_notes');

    expect($appointment->refresh()->contact_notes)->toBe('Original note');
});

test('contact note update ignores unrelated and staff only appointment fields', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $originalOptometrist = User::factory()->optometrist()->create();
    $otherOptometrist = User::factory()->optometrist()->create();
    $originalVisitReason = VisitReason::factory()->create();
    $otherVisitReason = VisitReason::factory()->create();
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $scheduledAt = now()->next('Monday')->setTime(10, 0, 0);

    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'optometrist_id' => $originalOptometrist->id,
        'source' => 'mobile_app',
        'visit_reason_id' => $originalVisitReason->id,
        'appointment_status_id' => $pending->id,
        'scheduled_at' => $scheduledAt,
        'contact_notes' => 'Original note',
        'staff_notes' => 'Staff only note',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->patchJson("/api/appointments/{$appointment->id}/contact-note", [
            'contact_notes' => 'Customer visible note',
            'staff_notes' => 'Tampered staff note',
            'appointment_status_id' => $confirmed->id,
            'status' => 'confirmed',
            'scheduled_at' => now()->next('Tuesday')->setTime(11, 0, 0)->toDateTimeString(),
            'visit_reason_id' => $otherVisitReason->id,
            'customer_id' => $otherCustomer->id,
            'source' => 'walk_in',
            'optometrist_id' => $otherOptometrist->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.contact_notes', 'Customer visible note')
        ->assertJsonPath('data.staff_notes', 'Staff only note')
        ->assertJsonPath('data.status', 'pending');

    $appointment->refresh();

    expect($appointment->contact_notes)->toBe('Customer visible note')
        ->and($appointment->staff_notes)->toBe('Staff only note')
        ->and($appointment->appointment_status_id)->toBe($pending->id)
        ->and($appointment->scheduled_at->format('Y-m-d H:i:s'))->toBe($scheduledAt->format('Y-m-d H:i:s'))
        ->and($appointment->visit_reason_id)->toBe($originalVisitReason->id)
        ->and($appointment->customer_id)->toBe($customer->id)
        ->and($appointment->source)->toBe('mobile_app')
        ->and($appointment->optometrist_id)->toBe($originalOptometrist->id);
});

test('unauthenticated users cannot update appointment contact notes', function () {
    $appointment = Appointment::factory()->create();

    $this->patchJson("/api/appointments/{$appointment->id}/contact-note", [
        'contact_notes' => 'Attempted update',
    ])->assertUnauthorized();
});
