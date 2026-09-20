<?php

use App\Actions\Appointments\BuildAppointmentRequestIdentitySnapshot;
use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Filament\Resources\AppointmentRequests\Pages\ReviewAppointmentRequestSchedule;
use App\Filament\Resources\AppointmentRequests\Pages\ViewAppointmentRequest;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\PatientAccountContact;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('link-to-patient new patient phone uses the standard Philippine phone input', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->create(['patient_id' => null]);

    $this->actingAs($staff);

    $component = Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->mountAction('linkToPatient');
    $schema = $component->instance()->getSchema('mountedActionSchema0');
    $phone = $schema?->getComponent('new_patient_phone', withHidden: true);

    expect($phone)
        ->toBeInstanceOf(TextInput::class)
        ->and($phone->isTel())->toBeTrue()
        ->and($phone->getPrefixLabel())->toBe('+63');
});

test('staff can link an unlinked request to a patient from the detail page', function () {
    $staff = User::factory()->staff()->create();
    $account = User::factory()->create([
        'first_name' => 'Ana',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '+639171234567',
        'role_id' => Role::firstOrCreate(['name' => 'patient'])->id,
    ]);
    PatientAccountContact::factory()->phone('+639171234567')->verified()->primary()->create(['user_id' => $account->id]);
    $request = AppointmentRequest::factory()->create([
        'patient_id' => null,
        'user_id' => $account->id,
        'encrypted_identity_snapshot' => app(BuildAppointmentRequestIdentitySnapshot::class)->handle($account, null),
    ]);
    $patient = Patient::factory()->create([
        'first_name' => 'Ana',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '+639171234567',
        'user_id' => null,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertActionVisible('linkToPatient')
        ->assertActionDoesNotExist('accept')
        ->callAction('linkToPatient', ['patient_mode' => 'existing', 'patient_id' => $patient->id])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($request->fresh()->patient_id)->toBe($patient->id)
        ->and($patient->fresh()->user_id)->toBe($account->id);
});

test('staff can link a request to a brand-new patient from the detail page', function () {
    $staff = User::factory()->staff()->create();
    $account = User::factory()->create([
        'first_name' => 'Maria',
        'middle_name' => null,
        'last_name' => 'Santos',
        'date_of_birth' => '1990-05-15',
        'phone' => '+639171234567',
        'role_id' => Role::firstOrCreate(['name' => 'patient'])->id,
    ]);
    PatientAccountContact::factory()->phone('+639171234567')->verified()->primary()->create(['user_id' => $account->id]);
    $request = AppointmentRequest::factory()->create([
        'patient_id' => null,
        'user_id' => $account->id,
        'encrypted_identity_snapshot' => [
            'phone' => '+639171234567',
            'email' => null,
            'first_name' => 'Maria',
            'middle_name' => null,
            'last_name' => 'Santos',
            'date_of_birth' => '1990-05-15',
            'gender' => null,
            'occupation' => null,
            'address' => null,
            'verified_contact_type' => 'phone',
            'verified_contact_masked' => '091***4567',
            'verified_contact_hash' => app(CreateContactLookupHash::class)->forPhone('+639171234567'),
            'submitted_at' => now()->toIso8601String(),
        ],
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('linkToPatient', [
            'patient_mode' => 'new',
            'new_patient_first_name' => 'Maria',
            'new_patient_last_name' => 'Santos',
            'new_patient_phone' => '9171234567',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $newPatient = Patient::query()->where('first_name', 'Maria')->where('last_name', 'Santos')->firstOrFail();

    expect($request->fresh()->patient_id)->toBe($newPatient->id)
        ->and($newPatient->user_id)->toBe($account->id);
});

test('accepting a linked request from the detail page does not error and creates an appointment', function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $scheduledAt = now()->next(Carbon::MONDAY)->setTime(10, 0);
    $request = AppointmentRequest::factory()->create([
        'patient_id' => $patient->id,
        'scheduled_at' => $scheduledAt,
    ]);
    $appointmentType = AppointmentType::factory()->create(['duration_minutes' => 30]);
    // AcceptAppointmentRequest re-checks availability against the chosen
    // type's real duration, which needs an active optometrist
    // covering the slot (auto-created for every weekday).
    $optometrist = User::factory()->optometrist()->create();

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertActionVisible('reviewSchedule')
        ->assertActionDoesNotExist('accept')
        ->assertActionHidden('linkToPatient');

    Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])
        ->set('appointmentTypeId', $appointmentType->id)
        ->set('durationMinutes', 30)
        ->set('optometristId', $optometrist->id)
        ->set('scheduledDate', $request->scheduled_at->toDateString())
        ->set('scheduledTime', $request->scheduled_at->format('H:i'))
        ->call('accept')
        ->assertHasNoErrors()
        ->assertNotified();

    expect($request->fresh()->status->value)->toBe('accepted');
});

test('rejecting a request from the detail page does not error', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('reject', [
            'reason_category' => 'patient_request',
            'rejection_details' => null,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($request->fresh()->status->value)->toBe('rejected');
});
