<?php

use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\RelationManagers\BillingsRelationManager;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\SmsNotification;
use App\Models\User;
use App\Models\VisitReason;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TimePicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

test('appointment table displays and searches appointment numbers', function () {
    $staff = User::factory()->staff()->create();
    $matchingAppointment = Appointment::factory()->create([
        'appointment_number' => 'APT-2026-SEARCH',
    ]);
    $otherAppointment = Appointment::factory()->create([
        'appointment_number' => 'APT-2026-OTHER',
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertTableColumnStateSet('appointment_number', 'APT-2026-SEARCH', record: $matchingAppointment)
        ->searchTable('SEARCH')
        ->assertCanSeeTableRecords([$matchingAppointment])
        ->assertCanNotSeeTableRecords([$otherAppointment]);
});

test('appointment table displays scheduled time in 12-hour format', function () {
    $staff = User::factory()->staff()->create();
    Appointment::factory()->create([
        'scheduled_at' => '2026-07-13 14:30:00',
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertSee('Jul 13, 2026 2:30 PM');
});

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

test('appointment table displays no-show as a readable status label', function () {
    $staff = User::factory()->staff()->create();
    $noShow = Appointment::factory()->create([
        'appointment_status_id' => AppointmentStatus::query()->where('name', 'no_show')->value('id'),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertTableColumnFormattedStateSet('status.name', 'No Show', record: $noShow);
});

test('appointment edit status buttons display no-show as a readable status label', function () {
    $staff = User::factory()->staff()->create();
    $confirmedStatus = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $confirmedStatus->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('No Show')
        ->assertDontSee('No_show');
});

test('appointment row actions use semantic colors', function () {
    $admin = User::factory()->admin()->create();
    $pendingStatus = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $confirmedStatus = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $arrivedStatus = AppointmentStatus::query()->where('name', 'arrived')->firstOrFail();

    $pending = Appointment::factory()->create(['appointment_status_id' => $pendingStatus->id]);
    $confirmed = Appointment::factory()->create(['appointment_status_id' => $confirmedStatus->id]);
    $arrived = Appointment::factory()->create(['appointment_status_id' => $arrivedStatus->id]);
    $archived = Appointment::factory()->create(['appointment_status_id' => $pendingStatus->id]);
    $archived->delete();

    $this->actingAs($admin);

    Livewire::test(ListAppointments::class)
        ->assertTableActionHasColor('edit', 'gray', $pending)
        ->assertTableActionHasColor('advance', 'success', $pending)
        ->assertTableActionHasColor('advance', 'warning', $confirmed)
        ->assertTableActionHasColor('advance', 'success', $arrived)
        ->assertTableActionHasColor('reschedule', 'info', $pending)
        ->assertTableActionHasColor('noShow', 'warning', $confirmed)
        ->assertTableActionHasColor('cancel', 'danger', $pending)
        ->assertTableActionHasColor('delete', 'gray', $pending)
        ->assertTableActionHasColor('restore', 'success', $archived);
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

test('reschedule header action is visible for pending and confirmed appointments', function (string $statusName) {
    $staff = User::factory()->staff()->create();
    $status = AppointmentStatus::query()->where('name', $statusName)->firstOrFail();
    $appointment = Appointment::factory()->create(['appointment_status_id' => $status->id]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionVisible('reschedule');
})->with(['pending', 'confirmed']);

test('reschedule header action is hidden for terminal appointments', function (string $statusName) {
    $staff = User::factory()->staff()->create();
    $status = AppointmentStatus::query()->where('name', $statusName)->firstOrFail();
    $appointment = Appointment::factory()->create(['appointment_status_id' => $status->id]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionHidden('reschedule');
})->with(['cancelled', 'completed', 'no_show']);

test('reschedule action keeps status and changes date while creating SMS', function () {
    Http::fake();

    $staff = User::factory()->staff()->create();
    $confirmedStatus = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $appointment = Appointment::factory()->create(['appointment_status_id' => $confirmedStatus->id]);
    $newDate = now()->next('Monday')->setTime(10, 0)->toDateTimeString();

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->callAction('reschedule', [
            'scheduled_at' => Carbon::parse($newDate)->toDateString(),
            'appointment_time' => '10:00',
        ])
        ->assertNotified();

    $fresh = $appointment->fresh();
    expect($fresh->status->name)->toBe('confirmed')
        ->and($fresh->scheduled_at->format('Y-m-d H:i'))->toBe(
            Carbon::parse($newDate)->format('Y-m-d H:i')
        );

    $this->assertDatabaseHas(SmsNotification::class, [
        'appointment_id' => $appointment->id,
        'event' => 'appointment_rescheduled',
    ]);
});

test('scheduled_at cannot be changed via a plain edit form save', function () {
    $staff = User::factory()->staff()->create();
    $confirmedStatus = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $originalDate = now()->addWeek();
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $confirmedStatus->id,
        'scheduled_at' => $originalDate,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->fillForm(['scheduled_at' => now()->addMonth()->toDateTimeString()])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $appointment->fresh();
    expect($fresh->scheduled_at->format('Y-m-d H:i'))->toBe($originalDate->format('Y-m-d H:i'))
        ->and($fresh->status->name)->toBe('confirmed');
});

test('appointment edit form shows the scheduled time with an explicit meridiem', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'scheduled_at' => now()->addWeek()->setTime(14, 30),
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertSet('data.appointment_time', '14:30')
        ->assertFormFieldExists(
            'appointment_time',
            checkFieldUsing: fn (TimePicker $field): bool => $field->isDisabled()
                && $field->isNative(),
        );
});

test('complete action transitions arrived appointment to completed', function () {
    $staff = User::factory()->staff()->create();
    $arrivedStatus = AppointmentStatus::query()->where('name', 'arrived')->firstOrFail();
    $completedStatus = AppointmentStatus::query()->where('name', 'completed')->firstOrFail();

    $appointment = Appointment::factory()->create(['appointment_status_id' => $arrivedStatus->id]);

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

test('appointment table supports arrival completion and no-show actions', function () {
    $staff = User::factory()->staff()->create();
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $confirmedAppointment = Appointment::factory()->create(['appointment_status_id' => $confirmed->id]);
    $noShowAppointment = Appointment::factory()->create(['appointment_status_id' => $confirmed->id]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->callTableAction('advance', $confirmedAppointment)
        ->assertNotified();

    expect($confirmedAppointment->refresh()->status->name)->toBe('arrived')
        ->and($confirmedAppointment->checked_in_at)->not->toBeNull();

    Livewire::test(ListAppointments::class)
        ->callTableAction('advance', $confirmedAppointment)
        ->assertNotified();

    expect($confirmedAppointment->refresh()->status->name)->toBe('completed')
        ->and($confirmedAppointment->completed_at)->not->toBeNull();

    Livewire::test(ListAppointments::class)
        ->callTableAction('noShow', $noShowAppointment)
        ->assertNotified();

    expect($noShowAppointment->refresh()->status->name)->toBe('no_show');
});

test('appointment table shows and filters assigned optometrist', function () {
    $staff = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $otherOptometrist = User::factory()->optometrist()->create();
    $assigned = Appointment::factory()->create(['optometrist_id' => $optometrist->id]);
    $other = Appointment::factory()->create(['optometrist_id' => $otherOptometrist->id]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertTableColumnStateSet('optometrist.name', $optometrist->name, record: $assigned)
        ->filterTable('optometrist', $optometrist->id)
        ->assertCanSeeTableRecords([$assigned])
        ->assertCanNotSeeTableRecords([$other]);
});

test('staff can create an appointment for a customer', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create();
    $optometrist = User::factory()->optometrist()->create();
    $scheduledDate = now()->next('Monday');

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => $scheduledDate->toDateString(),
            'appointment_time' => '10:17',
            'source' => 'phone_call',
            'optometrist_id' => $optometrist->id,
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Appointment::class, [
        'customer_id' => $customer->id,
        'visit_reason_id' => $visitReason->id,
        'source' => 'phone_call',
        'optometrist_id' => $optometrist->id,
        'appointment_status_id' => AppointmentStatus::query()->where('name', 'confirmed')->value('id'),
    ]);

    expect(Appointment::query()->latest('id')->firstOrFail()->scheduled_at->format('Y-m-d g:i A'))
        ->toBe($scheduledDate->format('Y-m-d').' 10:17 AM');
});

test('appointment create form uses separate date and explicit meridiem time fields', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->assertFormFieldExists(
            'scheduled_at',
            checkFieldUsing: fn (DatePicker $field): bool => $field->getLabel() === 'Appointment date'
                && $field->getPlaceholder() === 'Choose an appointment date'
                && $field->getSuffixIcon() === 'heroicon-o-calendar-days'
                && ! $field->isNative(),
        )
        ->assertFormFieldExists(
            'appointment_time',
            checkFieldUsing: fn (TimePicker $field): bool => $field->getLabel() === 'Appointment time'
                && $field->isNative()
                && $field->getMinutesStep() === 1
                && ! $field->hasSeconds()
                && $field->getFormat() === 'H:i',
        );
});

test('appointment create form rejects staff and admin accounts as patients', function (string $factoryState) {
    $staff = User::factory()->staff()->create();
    $nonPatient = User::factory()->{$factoryState}()->create();
    $visitReason = VisitReason::factory()->create();

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'customer_id' => $nonPatient->id,
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => now()->next('Monday')->toDateString(),
            'appointment_time' => '10:00',
            'source' => 'phone_call',
        ])
        ->call('create')
        ->assertHasFormErrors(['customer_id']);
})->with([
    'admin' => ['admin'],
    'staff' => ['staff'],
]);

test('appointment create form rejects a customer as assigned optometrist', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create();

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => now()->next('Monday')->toDateString(),
            'appointment_time' => '10:00',
            'optometrist_id' => $customer->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['optometrist_id']);
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
            'scheduled_at' => now()->next('Monday')->toDateString(),
            'appointment_time' => '10:00',
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

test('WalkIn staff can add a patient to the queue', function () {
    $staff = User::factory()->staff()->create();
    $patient = User::factory()->walkIn()->create(['phone' => '09171234569']);
    $visitReason = VisitReason::factory()->create();
    $optometrist = User::factory()->optometrist()->create();

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->callAction('addWalkIn', [
            'customer_id' => $patient->id,
            'visit_reason_id' => $visitReason->id,
            'optometrist_id' => $optometrist->id,
        ])
        ->assertNotified();

    $this->assertDatabaseHas(Appointment::class, [
        'customer_id' => $patient->id,
        'visit_reason_id' => $visitReason->id,
        'optometrist_id' => $optometrist->id,
        'created_by' => $staff->id,
        'checked_in_by' => $staff->id,
        'source' => 'walk_in',
        'appointment_status_id' => AppointmentStatus::query()->where('name', 'arrived')->value('id'),
    ]);
});

test('WalkIn queue filter shows only todays waiting patients', function () {
    $staff = User::factory()->staff()->create();
    $arrived = AppointmentStatus::query()->where('name', 'arrived')->firstOrFail();
    $completed = AppointmentStatus::query()->where('name', 'completed')->firstOrFail();
    $waiting = Appointment::factory()->create([
        'source' => 'walk_in',
        'appointment_status_id' => $arrived->id,
        'scheduled_at' => now(),
    ]);
    $scheduled = Appointment::factory()->create([
        'source' => 'staff_created',
        'appointment_status_id' => $arrived->id,
        'scheduled_at' => now(),
    ]);
    $finished = Appointment::factory()->create([
        'source' => 'walk_in',
        'appointment_status_id' => $completed->id,
        'scheduled_at' => now(),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->filterTable('walk_in_queue', true)
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$scheduled, $finished]);
});

test('reschedule row action changes appointment date without changing status', function () {
    Http::fake();

    $staff = User::factory()->staff()->create();
    $confirmedStatus = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $appointment = Appointment::factory()->create(['appointment_status_id' => $confirmedStatus->id]);
    $newDate = now()->next('Monday')->setTime(10, 0)->toDateTimeString();

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->callTableAction('reschedule', $appointment, [
            'scheduled_at' => Carbon::parse($newDate)->toDateString(),
            'appointment_time' => '10:00',
        ])
        ->assertNotified();

    $fresh = $appointment->fresh();
    expect($fresh->status->name)->toBe('confirmed')
        ->and($fresh->scheduled_at->format('Y-m-d H:i'))->toBe(
            Carbon::parse($newDate)->format('Y-m-d H:i')
        );

    $this->assertDatabaseHas(SmsNotification::class, [
        'appointment_id' => $appointment->id,
        'event' => 'appointment_rescheduled',
    ]);
});

test('reschedule row action is hidden for cancelled and completed appointments', function (string $statusName) {
    $staff = User::factory()->staff()->create();
    $status = AppointmentStatus::query()->where('name', $statusName)->firstOrFail();
    $appointment = Appointment::factory()->create(['appointment_status_id' => $status->id]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertTableActionHidden('reschedule', $appointment);
})->with(['cancelled', 'completed', 'no_show']);

test('appointment create form rejects past scheduled_at', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create();

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => now()->subDay()->toDateString(),
            'appointment_time' => '10:00',
        ])
        ->call('create')
        ->assertHasFormErrors(['scheduled_at']);
});

test('appointment edit page shows linked billings via relation manager', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $issuedStatus = BillingStatus::query()->firstOrCreate(['name' => 'issued']);
    $billing = Billing::factory()->create([
        'customer_id' => $appointment->customer_id,
        'appointment_id' => $appointment->id,
        'billing_status_id' => $issuedStatus->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(BillingsRelationManager::class, [
        'ownerRecord' => $appointment,
        'pageClass' => EditAppointment::class,
    ])->assertCanSeeTableRecords([$billing]);
});

test('edit appointment page no longer exposes a bill service action', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionDoesNotExist('bill_service');
});
