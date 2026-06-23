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
        ->set('activeTab', 'pending')
        ->assertCanSeeTableRecords([$pendingAppointment])
        ->assertCanNotSeeTableRecords([$confirmedAppointment]);

    Livewire::test(ListAppointments::class)
        ->filterTable('scheduled_date', [
            'scheduled_on' => '2026-06-10',
        ])
        ->assertCanSeeTableRecords([$pendingAppointment])
        ->assertCanNotSeeTableRecords([$confirmedAppointment]);
});

test('staff can edit appointment staff notes', function () {
    Http::fake();

    $staff = User::factory()->staff()->create();
    $pendingStatus = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();

    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $pendingStatus->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->fillForm([
            'staff_notes' => 'Updated notes.',
        ])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($appointment->fresh()->staff_notes)->toBe('Updated notes.');
});

test('confirm action transitions pending appointment to confirmed and creates SMS notification', function () {
    $staff = User::factory()->staff()->create();
    $pendingStatus = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $confirmedStatus = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();

    $appointment = Appointment::factory()->create(['appointment_status_id' => $pendingStatus->id]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->fillForm(['appointment_status_id' => $confirmedStatus->id])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($appointment->fresh()->status->name)->toBe('confirmed');

    $this->assertDatabaseHas(SmsNotification::class, [
        'appointment_id' => $appointment->id,
        'event' => 'appointment_confirmed',
    ]);
});

test('cancel action transitions pending appointment to cancelled and creates SMS notification', function () {
    $staff = User::factory()->staff()->create();
    $pendingStatus = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $cancelledStatus = AppointmentStatus::query()->where('name', 'cancelled')->firstOrFail();

    $appointment = Appointment::factory()->create(['appointment_status_id' => $pendingStatus->id]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->fillForm(['appointment_status_id' => $cancelledStatus->id])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($appointment->fresh()->status->name)->toBe('cancelled');

    $this->assertDatabaseHas(SmsNotification::class, [
        'appointment_id' => $appointment->id,
        'event' => 'appointment_cancelled',
    ]);
});

test('reschedule action transitions confirmed appointment to rescheduled with SMS notification', function () {
    $staff = User::factory()->staff()->create();
    $confirmedStatus = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $rescheduledStatus = AppointmentStatus::query()->where('name', 'rescheduled')->firstOrFail();

    $appointment = Appointment::factory()->create(['appointment_status_id' => $confirmedStatus->id]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->fillForm([
            'appointment_status_id' => $rescheduledStatus->id,
            'staff_notes' => 'Patient requested reschedule',
        ])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($appointment->fresh()->status->name)->toBe('rescheduled');

    $this->assertDatabaseHas(SmsNotification::class, [
        'appointment_id' => $appointment->id,
        'event' => 'appointment_rescheduled',
    ]);
});

test('complete action transitions confirmed appointment to completed', function () {
    $staff = User::factory()->staff()->create();
    $confirmedStatus = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $completedStatus = AppointmentStatus::query()->where('name', 'completed')->firstOrFail();

    $appointment = Appointment::factory()->create(['appointment_status_id' => $confirmedStatus->id]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->fillForm(['appointment_status_id' => $completedStatus->id])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($appointment->fresh()->status->name)->toBe('completed');
});

test('status dropdown does not include skipped statuses for pending appointment', function () {
    $staff = User::factory()->staff()->create();
    $pendingStatus = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $completedStatus = AppointmentStatus::query()->where('name', 'completed')->firstOrFail();

    $appointment = Appointment::factory()->create(['appointment_status_id' => $pendingStatus->id]);

    $this->actingAs($staff);

    // Attempting to jump directly to completed from pending should fail validation
    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->fillForm(['appointment_status_id' => $completedStatus->id])
        ->call('save')
        ->assertHasFormErrors(['appointment_status_id']);

    expect($appointment->fresh()->status->name)->toBe('pending');
});

test('staff can create an appointment for a customer', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create();

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Appointment::class, [
        'customer_id' => $customer->id,
        'visit_reason_id' => $visitReason->id,
    ]);
});

test('staff can create an appointment for a walk-in customer (no email or password)', function () {
    $staff = User::factory()->staff()->create();
    $walkIn = User::factory()->walkIn()->create(['phone' => '09171234567']);
    $visitReason = VisitReason::factory()->create();

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'customer_id' => $walkIn->id,
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Appointment::class, [
        'customer_id' => $walkIn->id,
    ]);

    expect($walkIn->email)->toBeNull()
        ->and($walkIn->password)->toBeNull();
});

test('appointment create form rejects past scheduled_at', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create();

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => now()->subDay()->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasFormErrors(['scheduled_at']);
});
