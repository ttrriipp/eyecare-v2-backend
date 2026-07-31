<?php

use App\Filament\Resources\Appointments\Widgets\AppointmentCalendarWidget;
use App\Filament\Widgets\AppointmentsChartWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Filament\Widgets\TodaysScheduleWidget;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('dashboard widgets are accessible to staff and admin', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user);

    Livewire::test(StatsOverviewWidget::class)->assertSuccessful();
    Livewire::test(TodaysScheduleWidget::class)->assertSuccessful();
    Livewire::test(AppointmentsChartWidget::class)->assertSuccessful();
})->with(['staff', 'admin']);

test('dashboard prioritizes clinical workflow stats', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(StatsOverviewWidget::class)
        ->assertSee("Today's Appointments")
        ->assertSee('Waiting Today')
        ->assertSee('Active Encounters')
        ->assertSee('Quotations Pending')
        ->assertSee('Ready for Dispensing')
        ->assertSee('Low Stock Variants');
});

test('today schedule widget shows patient and appointment type', function () {
    $admin = User::factory()->admin()->create();
    $appointmentType = AppointmentType::factory()->create([
        'name' => 'Comprehensive Eye Examination',
    ]);
    $appointment = Appointment::factory()->create([
        'appointment_type_id' => $appointmentType->id,
        'scheduled_at' => now()->addHour(),
    ]);

    $this->actingAs($admin);

    Livewire::test(TodaysScheduleWidget::class)
        ->assertCanSeeTableRecords([$appointment])
        ->assertSee($appointment->patient->first_name)
        ->assertSee('Comprehensive Eye Examination')
        ->assertDontSee('Visit Reason');
});

test('appointment calendar modal shows the populated appointment type', function () {
    $admin = User::factory()->admin()->create();
    $appointmentType = AppointmentType::factory()->create([
        'name' => 'Calendar Eye Assessment',
    ]);
    $appointment = Appointment::factory()->create([
        'appointment_type_id' => $appointmentType->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(AppointmentCalendarWidget::class)
        ->mountAction('viewAppointment', [
            'appointmentId' => $appointment->id,
        ])
        ->assertMountedActionModalSee('Appointment Type')
        ->assertMountedActionModalSee('Calendar Eye Assessment');
});
