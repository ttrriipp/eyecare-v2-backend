<?php

use App\Enums\EncounterStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\Encounter;
use App\Models\FrameReservation;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\TextSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
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
                    ->and($component->getSize($component->getState()))->toBe(TextSize::Large)
                    ->and($component->getExtraAttributeBag()->get('class'))->toContain('appointment-status-entry');

                return true;
            },
        )
        ->assertSee('Scheduled');
});

test('appointment details includes the visit reason', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'reason_for_visit' => 'Blurred vision in the left eye',
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertSchemaComponentExists(
            'appointment-details',
            checkComponentUsing: function (Section $component): bool {
                $reason = collect($component->getChildSchema()->getComponents())
                    ->first(fn ($childComponent): bool => $childComponent instanceof Textarea
                        && $childComponent->getName() === 'reason_for_visit');

                expect($component->getHeading())
                    ->toBe('Appointment Details')
                    ->and($reason)
                    ->toBeInstanceOf(Textarea::class)
                    ->and($reason->getLabel())
                    ->toBe('Reason for Visit');

                return true;
            },
        );
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

test('cancellation cleans up active reservations', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    $reservation = FrameReservation::factory()->forAppointment($appointment)->create();

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->callTableAction('cancel', $appointment, [
            'reason_category' => 'patient_request',
            'cancellation_details' => null,
        ]);

    expect($reservation->exists())->toBeFalse();
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
        ->assertActionHidden('viewEncounter')
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
