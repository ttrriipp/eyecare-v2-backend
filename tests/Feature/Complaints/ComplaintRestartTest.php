<?php

use App\Actions\Complaints\RestartComplaintWorkflow;
use App\Enums\ComplaintStatus;
use App\Enums\JobOrderStatus;
use App\Models\Appointment;
use App\Models\Complaint;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('complaint belongs to patient and original job order', function () {
    $patient = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create(['patient_id' => $patient->id]);

    $complaint = Complaint::factory()->create([
        'patient_id' => $patient->id,
        'original_job_order_id' => $jobOrder->id,
    ]);

    expect($complaint->patient->id)->toBe($patient->id)
        ->and($complaint->originalJobOrder->id)->toBe($jobOrder->id);
});

test('staff can link complaint to new appointment and encounter', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $complaint = Complaint::factory()->create(['patient_id' => $patient->id]);
    $newAppointment = Appointment::factory()->create(['patient_id' => $patient->id]);

    $result = app(RestartComplaintWorkflow::class)->handle($complaint, $newAppointment, $staff);

    expect($result->new_appointment_id)->toBe($newAppointment->id)
        ->and($result->new_encounter_id)->not->toBeNull()
        ->and($result->status)->toBe(ComplaintStatus::UnderReview);
});

test('original encounter prescription job order and invoice stay unchanged', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create(['patient_id' => $patient->id, 'total_amount' => 5000]);

    $complaint = Complaint::factory()->create([
        'patient_id' => $patient->id,
        'original_job_order_id' => $jobOrder->id,
    ]);

    $newAppointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    app(RestartComplaintWorkflow::class)->handle($complaint, $newAppointment, $staff);

    // Original job order unchanged
    expect((float) $jobOrder->fresh()->total_amount)->toBe(5000.0)
        ->and($jobOrder->fresh()->status)->toBe(JobOrderStatus::Queued);
});

test('cannot restart workflow for resolved complaint', function () {
    $staff = User::factory()->staff()->create();
    $complaint = Complaint::factory()->create(['status' => ComplaintStatus::Resolved]);
    $newAppointment = Appointment::factory()->create();

    app(RestartComplaintWorkflow::class)->handle($complaint, $newAppointment, $staff);
})->throws(ValidationException::class);

test('cannot restart workflow for closed complaint', function () {
    $staff = User::factory()->staff()->create();
    $complaint = Complaint::factory()->create(['status' => ComplaintStatus::Closed]);
    $newAppointment = Appointment::factory()->create();

    app(RestartComplaintWorkflow::class)->handle($complaint, $newAppointment, $staff);
})->throws(ValidationException::class);

test('complaint status types are constrained', function () {
    expect(ComplaintStatus::cases())->toContain(
        ComplaintStatus::Open,
        ComplaintStatus::UnderReview,
        ComplaintStatus::Resolved,
        ComplaintStatus::Closed,
    );
});
