<?php

use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Models\Appointment;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
});

// ─── Model ───────────────────────────────────────────────────────────────────

test('appointment has nullable staff relationship', function () {
    $appointment = Appointment::factory()->create(['staff_id' => null]);

    expect($appointment->staff)->toBeNull();
});

test('appointment belongs to an assigned staff member', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create(['staff_id' => $staff->id]);

    expect($appointment->staff->id)->toBe($staff->id)
        ->and($appointment->staff->name)->toBe($staff->name);
});

// ─── API Resource ─────────────────────────────────────────────────────────────

test('appointment API response includes null assigned_staff when unassigned', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'staff_id' => null,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/appointments/{$appointment->id}")
        ->assertOk()
        ->assertJsonPath('data.assigned_staff', null);
});

test('appointment API response includes assigned staff id and name', function () {
    $customer = User::factory()->customer()->create();
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'staff_id' => $staff->id,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/appointments/{$appointment->id}")
        ->assertOk()
        ->assertJsonPath('data.assigned_staff.id', $staff->id)
        ->assertJsonPath('data.assigned_staff.name', $staff->name);
});

// ─── Filament table ───────────────────────────────────────────────────────────

test('appointment table shows assigned staff column', function () {
    $staff = User::factory()->staff()->create(['name' => 'Jane Staff']);
    $appointment = Appointment::factory()->create(['staff_id' => $staff->id]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertCanSeeTableRecords([$appointment]);
});

test('assigned-to-me filter shows only appointments assigned to the current user', function () {
    $staffA = User::factory()->staff()->create();
    $staffB = User::factory()->staff()->create();

    $mine = Appointment::factory()->create(['staff_id' => $staffA->id]);
    $theirs = Appointment::factory()->create(['staff_id' => $staffB->id]);

    $this->actingAs($staffA);

    Livewire::test(ListAppointments::class)
        ->filterTable('assigned_to_me', true)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

// ─── Filament form ────────────────────────────────────────────────────────────

test('staff can assign a staff member when editing an appointment', function () {
    $staff = User::factory()->staff()->create();
    $assignee = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create(['staff_id' => null]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->fillForm(['staff_id' => $assignee->id])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($appointment->fresh()->staff_id)->toBe($assignee->id);
});

test('staff can clear an assigned staff member', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create(['staff_id' => $staff->id]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->fillForm(['staff_id' => null])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($appointment->fresh()->staff_id)->toBeNull();
});
