<?php

use App\Actions\Appointments\LinkAppointmentRequestToPatient;
use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('staff links a pending request to a patient and links the requesting account too', function () {
    $account = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'patient'])->id]);
    $request = AppointmentRequest::factory()->withSnapshot()->create(['patient_id' => null, 'user_id' => $account->id]);
    $patient = Patient::factory()->create();
    $conversation = Conversation::query()->create([
        'account_user_id' => $account->id,
        'patient_id' => null,
    ]);

    $updated = app(LinkAppointmentRequestToPatient::class)->handle($request, $patient);

    expect($updated->patient_id)->toBe($patient->id)
        ->and($updated->status)->toBe(AppointmentRequestStatus::Pending)
        ->and($patient->fresh()->user_id)->toBe($account->id)
        ->and($conversation->fresh()->patient_id)->toBe($patient->id);
});

test('cannot link an already-linked request', function () {
    $patient = Patient::factory()->create();
    $request = AppointmentRequest::factory()->create(['patient_id' => $patient->id]);

    app(LinkAppointmentRequestToPatient::class)->handle($request, Patient::factory()->create());
})->throws(ValidationException::class, 'already linked');

test('cannot link a non-pending request', function () {
    $request = AppointmentRequest::factory()->rejected()->create(['patient_id' => null]);

    app(LinkAppointmentRequestToPatient::class)->handle($request, Patient::factory()->create());
})->throws(ValidationException::class, 'Only pending');

test('linking a request for an already-linked account to the same patient is a no-op success', function () {
    $account = User::factory()->patient()->create();
    $patient = $account->patient;
    $request = AppointmentRequest::factory()->create(['patient_id' => null, 'user_id' => $account->id]);

    $updated = app(LinkAppointmentRequestToPatient::class)->handle($request, $patient);

    expect($updated->patient_id)->toBe($patient->id)
        ->and($patient->fresh()->user_id)->toBe($account->id);
});

test('cannot link a request when the account is already linked to a different patient', function () {
    $account = User::factory()->patient()->create();
    $request = AppointmentRequest::factory()->create(['patient_id' => null, 'user_id' => $account->id]);
    $otherPatient = Patient::factory()->create();

    app(LinkAppointmentRequestToPatient::class)->handle($request, $otherPatient);
})->throws(ValidationException::class, 'already linked to a different patient');

test('cannot link a request when the target patient is already linked to a different account', function () {
    $requestingAccount = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'patient'])->id]);
    $request = AppointmentRequest::factory()->create(['patient_id' => null, 'user_id' => $requestingAccount->id]);

    $otherAccount = User::factory()->patient()->create();
    $alreadyLinkedPatient = $otherAccount->patient;

    app(LinkAppointmentRequestToPatient::class)->handle($request, $alreadyLinkedPatient);
})->throws(ValidationException::class, 'already linked to a different account');
