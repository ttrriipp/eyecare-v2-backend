<?php

use App\Enums\AppointmentRequestStatus;
use App\Enums\EncounterStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Schemas\AppointmentForm;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\AppointmentTypeVisitReasonPreset;
use App\Models\ClinicHour;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\TextSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
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

test('appointment table keeps desktop rows and scopes its responsive toolbar styles', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    $table = Livewire::test(ListAppointments::class)->instance()->getTable();

    expect($table->isStackedOnMobile())->toBeFalse()
        ->and($table->getExtraAttributes()['class'])->toBe('appointments-table');
});

test('appointment table prioritizes active appointments and sorts them by earliest time', function () {
    $staff = User::factory()->staff()->create();
    $checkedInEarlier = Appointment::factory()->checkedIn()->create([
        'scheduled_at' => now()->addHours(2),
    ]);
    $checkedInLater = Appointment::factory()->checkedIn()->create([
        'scheduled_at' => now()->addHours(4),
    ]);
    $scheduledEarlier = Appointment::factory()->create([
        'scheduled_at' => now()->addHour(),
    ]);
    $scheduledLater = Appointment::factory()->create([
        'scheduled_at' => now()->addHours(3),
    ]);
    $fulfilled = Appointment::factory()->fulfilled()->create([
        'scheduled_at' => now()->addMinutes(30),
    ]);
    $noShow = Appointment::factory()->noShow()->create([
        'scheduled_at' => now()->addMinutes(45),
    ]);
    $cancelled = Appointment::factory()->cancelled()->create([
        'scheduled_at' => now()->addMinutes(15),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertCanSeeTableRecords([
            $checkedInEarlier,
            $checkedInLater,
            $scheduledEarlier,
            $scheduledLater,
            $fulfilled,
            $noShow,
            $cancelled,
        ], inOrder: true);
});

test('appointment resource has no billing relation manager', function () {
    expect(AppointmentResource::getRelations())->toBeEmpty();
});

test('clinic hours validation highlights the appointment time field', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $appointmentType = AppointmentType::factory()->create();

    $this->seed(ClinicHoursSeeder::class);
    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'patient_mode' => 'existing',
            'patient_id' => $patient->id,
            'is_walk_in' => 'scheduled',
            'appointment_type_id' => $appointmentType->id,
            'duration_minutes' => $appointmentType->duration_minutes,
            'scheduled_at' => today()->addDay()->toDateString(),
            'appointment_time' => '20:00',
        ])
        ->call('create')
        ->assertHasFormErrors(['appointment_time']);
});

test('closed clinic day validation remains attached to the appointment date', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $appointmentType = AppointmentType::factory()->create();
    $closedDate = today()->addDay();

    $this->seed(ClinicHoursSeeder::class);
    ClinicHour::query()
        ->where('weekday', $closedDate->dayOfWeek)
        ->update(['enabled' => false]);
    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'patient_mode' => 'existing',
            'patient_id' => $patient->id,
            'is_walk_in' => 'scheduled',
            'appointment_type_id' => $appointmentType->id,
            'duration_minutes' => $appointmentType->duration_minutes,
            'scheduled_at' => $closedDate->toDateString(),
            'appointment_time' => '10:00',
        ])
        ->call('create')
        ->assertHasFormErrors(['scheduled_at']);
});

test('appointment status is read only on the edit form', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertFormFieldDoesNotExist('appointment_status_id')
        ->assertSchemaComponentExists(
            'appointment-details',
            checkComponentUsing: function (Section $section): bool {
                $component = collect($section->getChildSchema()->getComponents())
                    ->first(fn ($childComponent): bool => $childComponent instanceof TextEntry
                        && $childComponent->getName() === 'current_status');

                expect($component)
                    ->toBeInstanceOf(TextEntry::class)
                    ->and($component->isBadge())->toBeTrue()
                    ->and($component->getSize($component->getState()))->toBe(TextSize::Small)
                    ->and($component->getExtraAttributeBag()->get('class'))->toContain('appointment-status-entry');

                return true;
            },
        )
        ->assertSee('Scheduled');
});

test('appointment details includes the visit reason', function () {
    $staff = User::factory()->staff()->create();
    $appointmentType = AppointmentType::factory()->create();
    AppointmentTypeVisitReasonPreset::factory()->for($appointmentType)->create([
        'label' => 'Routine eye examination',
    ]);
    $appointment = Appointment::factory()->create([
        'appointment_type_id' => $appointmentType->id,
        'reason_for_visit' => 'Blurred vision in the left eye',
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertFormSet([
            'reason_for_visit' => AppointmentForm::CUSTOM_REASON_VALUE,
            'custom_reason_for_visit' => 'Blurred vision in the left eye',
        ])
        ->assertSchemaComponentExists(
            'appointment-details',
            checkComponentUsing: function (Section $component): bool {
                $reason = collect($component->getChildSchema()->getComponents())
                    ->first(fn ($childComponent): bool => $childComponent instanceof Select
                        && $childComponent->getName() === 'reason_for_visit');

                expect($component->getHeading())
                    ->toBe('Appointment Details')
                    ->and($reason)
                    ->toBeInstanceOf(Select::class)
                    ->and($reason->getLabel())
                    ->toBe('Reason for Visit')
                    ->and($reason->getOptions())
                    ->toMatchArray([
                        'Routine eye examination' => 'Routine eye examination',
                        '__custom__' => 'Other',
                    ]);

                return true;
            },
        );
});

test('appointment creation saves a custom reason selected as other', function (): void {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $appointmentType = AppointmentType::factory()->create();
    AppointmentTypeVisitReasonPreset::factory()->for($appointmentType)->create([
        'label' => 'Routine eye examination',
    ]);

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'patient_mode' => 'existing',
            'patient_id' => $patient->id,
            'is_walk_in' => 'walk_in',
            'appointment_type_id' => $appointmentType->id,
            'duration_minutes' => $appointmentType->duration_minutes,
            'reason_for_visit' => AppointmentForm::CUSTOM_REASON_VALUE,
            'custom_reason_for_visit' => 'Eye irritation after extended screen use',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Appointment::query()->latest('id')->value('reason_for_visit'))
        ->toBe('Eye irritation after extended screen use');
});

test('directly creating an appointment rejects a fully conflicting pending request', function (): void {
    $staff = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $requester = User::factory()->patient()->create();
    $appointmentType = AppointmentType::factory()->create([
        'duration_minutes' => 30,
    ]);
    $scheduledAt = today()->addDay()->setTime(10, 0);

    AppointmentRequest::factory()->create([
        'user_id' => $requester->id,
        'patient_id' => $requester->patient->id,
        'appointment_type_id' => $appointmentType->id,
        'provisional_duration_minutes' => 30,
        'scheduled_at' => $scheduledAt,
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => $scheduledAt->copy()->addDay(),
    ]);

    $this->actingAs($staff);

    Livewire::test(CreateAppointment::class)
        ->fillForm([
            'patient_mode' => 'existing',
            'patient_id' => Patient::factory()->create()->id,
            'is_walk_in' => 'scheduled',
            'appointment_type_id' => $appointmentType->id,
            'duration_minutes' => 30,
            'scheduled_at' => $scheduledAt->toDateString(),
            'appointment_time' => '10:00',
            'optometrist_id' => $optometrist->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $request = AppointmentRequest::query()->latest('id')->firstOrFail();

    expect($request->status)->toBe(AppointmentRequestStatus::Rejected)
        ->and($request->rejection_reason)
        ->toBe('This time is no longer available because another appointment was scheduled for this time.');
});

test('appointment table has no generic lifecycle advance actions', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertActionDoesNotExist(TestAction::make('advance')->table($appointment))
        ->assertActionDoesNotExist(TestAction::make('bulk_confirm')->table()->bulk());
});

test('checked in appointment exposes start consultation for its planned encounter', function () {
    $optometrist = User::factory()->optometrist()->create();
    $checkedIn = AppointmentStatus::query()->firstOrCreate(['name' => 'checked_in']);
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $checkedIn->id,
        'checked_in_at' => now(),
    ]);
    $encounter = Encounter::factory()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(ListAppointments::class)
        ->assertActionVisible(TestAction::make('startConsultation')->table($appointment))
        ->assertActionHidden(TestAction::make('viewEncounter')->table($appointment))
        ->assertActionHidden(TestAction::make('assign')->table($appointment))
        ->callTableAction('startConsultation', $appointment)
        ->assertRedirect(route('filament.admin.resources.encounters.edit', ['record' => $encounter]));

    expect($encounter->fresh()->status)->toBe(EncounterStatus::InProgress)
        ->and($encounter->fresh()->optometrist_id)->toBe($optometrist->id);
});

test('started appointment exposes a dedicated view encounter action', function () {
    $optometrist = User::factory()->optometrist()->create();
    $checkedIn = AppointmentStatus::query()->firstOrCreate(['name' => 'checked_in']);
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $checkedIn->id,
        'checked_in_at' => now(),
    ]);
    $encounter = Encounter::factory()->inProgress()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
        'optometrist_id' => $optometrist->id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(ListAppointments::class)
        ->assertActionHidden(TestAction::make('startConsultation')->table($appointment))
        ->assertActionVisible(TestAction::make('viewEncounter')->table($appointment))
        ->assertActionHasLabel(TestAction::make('viewEncounter')->table($appointment), 'View Consultation')
        ->assertActionHasUrl(
            TestAction::make('viewEncounter')->table($appointment),
            route('filament.admin.resources.encounters.edit', ['record' => $encounter]),
        );
});

test('check in creates a planned encounter', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->callTableAction('checkIn', $appointment);

    $appointment->refresh();
    expect($appointment->status->name)->toBe('checked_in')
        ->and($appointment->checked_in_at)->not->toBeNull()
        ->and($appointment->encounter)->not->toBeNull()
        ->and($appointment->encounter->status->value)->toBe('planned');
});

test('cancellation records actor reason and time', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->callTableAction('cancel', $appointment, [
            'reason_category' => 'patient_request',
            'cancellation_details' => null,
        ]);

    $appointment->refresh();
    expect($appointment->status->name)->toBe('cancelled')
        ->and($appointment->cancelled_by)->toBe('clinic')
        ->and($appointment->cancelled_by_user_id)->toBe($staff->id)
        ->and($appointment->cancellation_reason_category)->toBe('patient_request')
        ->and($appointment->cancelled_at)->not->toBeNull();
});

test('cancelling from the edit page immediately shows the cancelled state', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionVisible('cancel')
        ->assertSee('Scheduled')
        ->callAction('cancel', [
            'reason_category' => 'patient_request',
            'cancellation_details' => null,
        ])
        ->assertNotified('Appointment cancelled')
        ->assertSee('Cancelled')
        ->assertActionHidden('cancel')
        ->assertActionDoesNotExist('save');

    expect($appointment->fresh()->status->name)->toBe('cancelled');
});

test('appointment cancellation reason is visible to staff on the edit page', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->cancelled()->create([
        'cancellation_reason_category' => 'patient_request',
        'cancellation_reason_details' => 'I need to choose a different date.',
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('Cancellation Details')
        ->assertSee('I need to choose a different date.');
});

test('terminal appointments cannot be edited', function (string $factoryState) {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->{$factoryState}()->create([
        'staff_notes' => 'Original appointment notes',
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionDoesNotExist('save')
        ->assertSee('Back')
        ->assertSchemaComponentExists(
            'appointment-details',
            checkComponentUsing: function (Section $section): bool {
                $fields = $section->getChildSchema()->getFlatFields(withHidden: true);

                foreach (['appointment_type_id', 'duration_minutes', 'reason_for_visit', 'staff_notes'] as $fieldName) {
                    expect($fields[$fieldName]?->isDisabled())->toBeTrue();
                }

                return true;
            },
        )
        ->fillForm(['staff_notes' => 'Changed after appointment became terminal'])
        ->call('save')
        ->assertHasErrors(['appointment']);

    expect($appointment->fresh()->staff_notes)->toBe('Original appointment notes');
})->with([
    'cancelled' => 'cancelled',
    'no-show' => 'noShow',
    'fulfilled' => 'fulfilled',
]);

test('terminal appointments are labelled as view actions in the table', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->cancelled()->create();

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertActionHasLabel(TestAction::make('edit')->table($appointment), 'View');
});

test('no show records actor and time', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'scheduled_at' => now()->subHour(),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->callTableAction('noShow', $appointment);

    $appointment->refresh();
    expect($appointment->status->name)->toBe('no_show')
        ->and($appointment->no_show_by)->toBe($staff->id)
        ->and($appointment->no_show_at)->not->toBeNull();
});

test('no show action is hidden for future appointments', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'scheduled_at' => now()->addDay(),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertActionHidden(TestAction::make('noShow')->table($appointment));
});

test('unavailable optometrist assignment is rejected', function () {
    $staff = User::factory()->staff()->create();
    $nonOptometrist = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->callTableAction('assign', $appointment, [
            'optometrist_id' => $nonOptometrist->id,
        ]);

    $appointment->refresh();
    expect($appointment->optometrist_id)->not->toBe($nonOptometrist->id);
});

test('rescheduling requires clinic reason category', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->callTableAction('reschedule', $appointment, [
            'scheduled_at' => now()->addDays(3)->format('Y-m-d'),
            'appointment_time' => '10:00',
            'reason_category' => null,
            'reschedule_reason' => null,
        ])
        ->assertHasTableActionErrors(['reason_category']);
});

test('rescheduling reloads the edit page with the new appointment time', function (): void {
    $staff = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $this->seed(ClinicHoursSeeder::class);

    $scheduledAt = now()->next('Wednesday')->setTime(10, 0);
    $newScheduledAt = $scheduledAt->copy()->addWeek()->setTime(11, 0);
    $appointment = Appointment::factory()->create([
        'scheduled_at' => $scheduledAt,
        'duration_minutes' => 30,
        'optometrist_id' => $optometrist->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->mountAction('reschedule')
        ->setActionData([
            'scheduled_at' => $newScheduledAt->toDateString(),
            'appointment_time' => $newScheduledAt->format('H:i'),
            'reason_category' => 'patient_request',
            'reschedule_reason' => null,
        ])
        ->callMountedAction()
        ->assertRedirect(EditAppointment::getUrl([
            'record' => $appointment->getRouteKey(),
        ]));

    expect($appointment->fresh()->scheduled_at->toDateTimeString())
        ->toBe($newScheduledAt->toDateTimeString());
});

test('directly rescheduling confirms and rejects a fully conflicting pending request', function (): void {
    $staff = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $requester = User::factory()->patient()->create();
    $appointmentType = AppointmentType::factory()->create([
        'duration_minutes' => 30,
    ]);
    $scheduledAt = now()->next('Wednesday')->setTime(10, 0);
    $newScheduledAt = $scheduledAt->copy()->addWeek()->setTime(11, 0);
    $appointment = Appointment::factory()->create([
        'appointment_type_id' => $appointmentType->id,
        'scheduled_at' => $scheduledAt,
        'duration_minutes' => 30,
        'optometrist_id' => $optometrist->id,
    ]);

    AppointmentRequest::factory()->create([
        'user_id' => $requester->id,
        'patient_id' => $requester->patient->id,
        'appointment_type_id' => $appointmentType->id,
        'provisional_duration_minutes' => 30,
        'scheduled_at' => $newScheduledAt,
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => $newScheduledAt->copy()->addDay(),
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->mountAction('reschedule')
        ->setActionData([
            'scheduled_at' => $newScheduledAt->toDateString(),
            'appointment_time' => $newScheduledAt->format('H:i'),
            'reason_category' => 'patient_request',
            'reschedule_reason' => null,
        ])
        ->callMountedAction()
        ->assertMountedActionModalSee('1 pending appointment request includes this time.');

    expect($component->instance()->mountedActions)->toHaveCount(2);

    $component->callMountedAction()
        ->assertRedirect(EditAppointment::getUrl([
            'record' => $appointment->getRouteKey(),
        ]));

    $request = AppointmentRequest::query()->latest('id')->firstOrFail();

    expect($request->status)->toBe(AppointmentRequestStatus::Rejected)
        ->and($request->rejection_reason)
        ->toBe('This time is no longer available because another appointment was scheduled for this time.');
});

test('edit page has no editable status field', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertFormFieldDoesNotExist('appointment_status_id');
});

test('edit page starts a planned consultation and views a started encounter', function () {
    $optometrist = User::factory()->optometrist()->create();
    $checkedIn = AppointmentStatus::query()->firstOrCreate(['name' => 'checked_in']);
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $checkedIn->id,
        'checked_in_at' => now(),
    ]);
    $encounter = Encounter::factory()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionVisible('startConsultation')
        ->assertActionVisible('viewEncounter')
        ->assertActionHasLabel('viewEncounter', 'View Consultation')
        ->assertActionHasUrl(
            'viewEncounter',
            route('filament.admin.resources.encounters.edit', ['record' => $encounter]),
        )
        ->callAction('startConsultation')
        ->assertRedirect(route('filament.admin.resources.encounters.edit', ['record' => $encounter]));

    expect($encounter->fresh()->status)->toBe(EncounterStatus::InProgress);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertActionHidden('startConsultation')
        ->assertActionVisible('viewEncounter')
        ->assertActionHasLabel('viewEncounter', 'View Consultation')
        ->assertActionHasUrl(
            'viewEncounter',
            route('filament.admin.resources.encounters.edit', ['record' => $encounter]),
        );
});
