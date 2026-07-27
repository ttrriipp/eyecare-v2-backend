<?php

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('appointment table shows the populated appointment type', function () {
    $staff = User::factory()->staff()->create();
    $appointmentType = AppointmentType::factory()->create([
        'name' => 'Routine Vision Review',
    ]);
    $appointment = Appointment::factory()->create([
        'appointment_type_id' => $appointmentType->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertCanSeeTableRecords([$appointment])
        ->assertSee('Routine Vision Review')
        ->assertSee('Appointment Type')
        ->assertDontSee('Visit reason');
});

test('appointment resource has no billing relation manager', function () {
    expect(AppointmentResource::getRelations())->toBeEmpty();
});
