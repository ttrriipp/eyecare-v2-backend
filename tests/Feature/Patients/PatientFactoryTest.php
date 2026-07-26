<?php

use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('patient factory never creates duplicate clinical identities', function () {
    $user = User::factory()->patient()->create();

    // The factory should have created exactly one patient
    expect($user->patient)->not->toBeNull();

    $patientCount = Patient::query()->where('user_id', $user->id)->count();
    expect($patientCount)->toBe(1);
});

test('calling patient factory twice does not duplicate', function () {
    $user = User::factory()->patient()->create();

    // Call patient() again — should not create another Patient
    $user->load('patient');
    $patientCount = Patient::query()->where('user_id', $user->id)->count();
    expect($patientCount)->toBe(1);
});

test('patient without account can exist', function () {
    $patient = Patient::factory()->create(['user_id' => null]);

    expect($patient->user_id)->toBeNull()
        ->and($patient->account)->toBeNull();
});
