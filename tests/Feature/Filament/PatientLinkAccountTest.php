<?php

use App\Filament\Resources\PatientAccounts\Pages\ViewPatientAccount;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Models\Conversation;
use App\Models\Patient;
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
    $patientAccount->roles()->sync(
        Role::query()->where('name', Role::Patient)->pluck('id'),
    );
    $token = $patientAccount->createToken('mobile');
    $patient = Patient::factory()->create(['user_id' => null]);

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
    $patientAccount->roles()->sync(
        Role::query()->where('name', Role::Patient)->pluck('id'),
    );
    $patient = Patient::factory()->create(['user_id' => null]);
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
    $patientAccount->roles()->sync(
        Role::query()->where('name', Role::Patient)->pluck('id'),
    );
    $patient = Patient::factory()->create(['user_id' => null]);
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
