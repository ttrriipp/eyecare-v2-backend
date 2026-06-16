<?php

use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\SmsNotification;
use App\Models\User;
use App\Models\VisitReason;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

test('staff and admin users can list appointments', function (string $factoryState) {
    $user = User::factory()->{$factoryState}()->create();
    $appointments = Appointment::factory()->count(2)->create();

    $this->actingAs($user);

    Livewire::test(ListAppointments::class)
        ->assertCanSeeTableRecords($appointments);
})->with([
    'admin' => ['admin'],
    'staff' => ['staff'],
]);

test('appointment table can filter by status and scheduled date', function () {
    $staff = User::factory()->staff()->create();

    $pendingStatus = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $confirmedStatus = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();

    $pendingAppointment = Appointment::factory()->create([
        'appointment_status_id' => $pendingStatus->id,
        'scheduled_at' => '2026-06-10 10:00:00',
    ]);

    $confirmedAppointment = Appointment::factory()->create([
        'appointment_status_id' => $confirmedStatus->id,
        'scheduled_at' => '2026-06-20 10:00:00',
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->filterTable('status', $pendingStatus->id)
        ->assertCanSeeTableRecords([$pendingAppointment])
        ->assertCanNotSeeTableRecords([$confirmedAppointment]);

    Livewire::test(ListAppointments::class)
        ->filterTable('scheduled_date', [
            'scheduled_on' => '2026-06-10',
        ])
        ->assertCanSeeTableRecords([$pendingAppointment])
        ->assertCanNotSeeTableRecords([$confirmedAppointment]);
});

test('staff can edit appointments and status changes use the workflow action', function () {
    Http::fake();

    $staff = User::factory()->staff()->create();
    $pendingStatus = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $confirmedStatus = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();

    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $pendingStatus->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->fillForm([
            'appointment_status_id' => $confirmedStatus->id,
            'staff_notes' => 'Confirmed for exam.',
        ])
        ->assertSchemaStateSet([
            'appointment_status_id' => $confirmedStatus->id,
            'staff_notes' => 'Confirmed for exam.',
        ])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $appointment->refresh();

    expect($appointment->status->name)->toBe('confirmed')
        ->and($appointment->staff_notes)->toBe('Confirmed for exam.');

    $this->assertDatabaseHas(SmsNotification::class, [
        'appointment_id' => $appointment->id,
        'event' => 'appointment_confirmed',
    ]);

    Http::assertNothingSent();
});

test('staff can create an appointment for a customer', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create();
    $pendingStatus = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'visit_reason_id' => $visitReason->id,
            'appointment_status_id' => $pendingStatus->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Appointment::class, [
        'customer_id' => $customer->id,
        'visit_reason_id' => $visitReason->id,
        'appointment_status_id' => $pendingStatus->id,
    ]);
});
