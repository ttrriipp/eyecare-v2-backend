<?php

use App\Actions\Appointments\LinkAppointmentRequestToPatient;
use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('staff links a pending request to a patient', function () {
    $request = AppointmentRequest::factory()->withSnapshot()->create(['patient_id' => null]);
    $patient = Patient::factory()->create();

    $updated = app(LinkAppointmentRequestToPatient::class)->handle($request, $patient);

    expect($updated->patient_id)->toBe($patient->id)
        ->and($updated->status)->toBe(AppointmentRequestStatus::Pending);
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
