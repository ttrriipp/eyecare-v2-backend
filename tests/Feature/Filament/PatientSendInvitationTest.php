<?php

use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Models\Patient;
use App\Models\PatientInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff sends a phone invitation to an unlinked patient', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create(['user_id' => null, 'phone' => '09171234567']);

    $this->actingAs($staff);

    Livewire::test(EditPatient::class, ['record' => $patient->getRouteKey()])
        ->assertActionVisible('sendInvitation')
        ->callAction('sendInvitation')
        ->assertNotified();

    $invitation = PatientInvitation::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($invitation->channel)->toBe('phone');
});

test('send invitation fails gracefully without a phone on record', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create(['user_id' => null, 'phone' => null]);

    $this->actingAs($staff);

    Livewire::test(EditPatient::class, ['record' => $patient->getRouteKey()])
        ->callAction('sendInvitation')
        ->assertNotified();

    expect(PatientInvitation::query()->where('patient_id', $patient->id)->exists())->toBeFalse();
});

test('send invitation action is hidden once linked', function () {
    $staff = User::factory()->staff()->create();
    $user = User::factory()->patient()->create();
    $patient = $user->patient;

    $this->actingAs($staff);

    Livewire::test(EditPatient::class, ['record' => $patient->getRouteKey()])
        ->assertActionHidden('sendInvitation');
});
