<?php

use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\User;
use App\Models\VisitReason;
use Database\Seeders\AppointmentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
});

// ─── Model audit ─────────────────────────────────────────────────────────────

test('appointment has nullable booking and check-in audit relationships', function () {
    $appointment = Appointment::factory()->create([
        'created_by' => null,
        'checked_in_by' => null,
    ]);

    expect($appointment->createdBy)->toBeNull()
        ->and($appointment->checkedInBy)->toBeNull();
});

test('appointment records the authenticated user who created it', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    $appointment = Appointment::factory()->create();

    expect($appointment->fresh()->created_by)->toBe($staff->id)
        ->and($appointment->fresh()->createdBy->name)->toBe($staff->name);
});

test('appointment records the staff member who checks in the patient', function () {
    $staff = User::factory()->staff()->create();
    $confirmedStatus = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $confirmedStatus->id,
        'checked_in_by' => null,
    ]);

    $this->actingAs($staff);

    app(UpdateAppointmentStatus::class)->handle($appointment, 'arrived');

    expect($appointment->fresh()->checked_in_by)->toBe($staff->id);
});

// ─── API Resource ─────────────────────────────────────────────────────────────

test('appointment API response does not expose manual staff assignment', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $customer->patient->id,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/appointments/{$appointment->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.assigned_staff');
});

test('appointment API response includes source and assigned optometrist', function () {
    $customer = User::factory()->customer()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $customer->patient->id,
        'source' => 'phone_call',
        'optometrist_id' => $optometrist->id,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/appointments/{$appointment->id}")
        ->assertOk()
        ->assertJsonPath('data.source', 'phone_call')
        ->assertJsonPath('data.assigned_optometrist.id', $optometrist->id)
        ->assertJsonPath('data.assigned_optometrist.name', $optometrist->name);
});

// ─── Filament form ────────────────────────────────────────────────────────────

test('staff-created appointments automatically record the booking user', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create();

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'patient_id' => $customer->patient->id,
            'visit_reason_id' => $visitReason->id,
            'source' => 'staff_created',
            'scheduled_at' => now()->next('Monday')->setTime(10, 0)->toDateTimeString(),
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $appointment = Appointment::query()->where('patient_id', $customer->id)->firstOrFail();

    expect($appointment->created_by)->toBe($staff->id);
});
