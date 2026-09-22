<?php

use App\Filament\Resources\PatientAccounts\Pages\ViewPatientAccount;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Models\Conversation;
use App\Models\Patient;
use App\Models\PatientAccountContact;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('linking an account to a patient record does not revoke its existing tokens', function () {
    $admin = User::factory()->admin()->create();
    // Create a user with patient role but without an auto-linked Patient record.
    $patientAccount = User::factory()->create();
    $patientAccount->update([
        'first_name' => 'Ana',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '+639171234567',
    ]);
    $patientAccount->roles()->sync(
        Role::query()->where('name', Role::Patient)->pluck('id'),
    );
    $token = $patientAccount->createToken('mobile');
    PatientAccountContact::factory()->phone('+639171234567')->verified()->primary()->create(['user_id' => $patientAccount->id]);
    $patient = Patient::factory()->create([
        'user_id' => null,
        'first_name' => 'Ana',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '+639171234567',
    ]);

    $this->actingAs($admin);

    Livewire::test(EditPatient::class, ['record' => $patient->getRouteKey()])
        ->callAction('linkAccount', ['user_id' => $patientAccount->id])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($patient->fresh()->user_id)->toBe($patientAccount->id)
        ->and($patientAccount->tokens()->whereKey($token->accessToken->id)->exists())->toBeTrue();
});

test('linking an account associates its existing conversation with the patient', function () {
    $admin = User::factory()->admin()->create();
    $patientAccount = User::factory()->create();
    $patientAccount->update([
        'first_name' => 'Ana',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '+639171234567',
    ]);
    $patientAccount->roles()->sync(
        Role::query()->where('name', Role::Patient)->pluck('id'),
    );
    PatientAccountContact::factory()->phone('+639171234567')->verified()->primary()->create(['user_id' => $patientAccount->id]);
    $patient = Patient::factory()->create([
        'user_id' => null,
        'first_name' => 'Ana',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '+639171234567',
    ]);
    $conversation = Conversation::query()->create([
        'account_user_id' => $patientAccount->id,
        'patient_id' => null,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditPatient::class, ['record' => $patient->getRouteKey()])
        ->callAction('linkAccount', ['user_id' => $patientAccount->id])
        ->assertHasNoActionErrors();

    expect($conversation->fresh()->patient_id)->toBe($patient->id);
});

test('linking a patient record from the account page associates its existing conversation', function () {
    $admin = User::factory()->admin()->create();
    $patientAccount = User::factory()->create();
    $patientAccount->update([
        'first_name' => 'Ana',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '+639171234567',
    ]);
    $patientAccount->roles()->sync(
        Role::query()->where('name', Role::Patient)->pluck('id'),
    );
    PatientAccountContact::factory()->phone('+639171234567')->verified()->primary()->create(['user_id' => $patientAccount->id]);
    $patient = Patient::factory()->create([
        'user_id' => null,
        'first_name' => 'Ana',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '+639171234567',
    ]);
    $conversation = Conversation::query()->create([
        'account_user_id' => $patientAccount->id,
        'patient_id' => null,
    ]);

    $this->actingAs($admin);

    Livewire::test(ViewPatientAccount::class, ['record' => $patientAccount->getRouteKey()])
        ->callAction('linkPatientRecord', ['patient_id' => $patient->id])
        ->assertHasNoActionErrors();

    expect($conversation->fresh()->patient_id)->toBe($patient->id);
});

test('unlinking from the account page accepts a preset reason', function (): void {
    $admin = User::factory()->admin()->create();
    $patientAccount = User::factory()->patient()->create();
    $patient = $patientAccount->patient;

    $this->actingAs($admin);

    Livewire::test(ViewPatientAccount::class, ['record' => $patientAccount->getRouteKey()])
        ->callAction('unlinkAccount', [
            'reason_category' => 'patient_request',
            'reason_details' => null,
        ])
        ->assertNotified('Account unlinked successfully');

    expect($patient->fresh()->user_id)->toBeNull();
});
