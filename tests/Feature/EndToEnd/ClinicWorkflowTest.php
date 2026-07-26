<?php

use App\Actions\Encounters\CheckInAppointment;
use App\Actions\Invoices\DispenseJobOrder;
use App\Actions\JobOrders\CreateJobOrder;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Actions\Prescriptions\FinalizePrescription;
use App\Actions\Quotations\PresentQuotation;
use App\Actions\Quotations\RecordQuotationDecision;
use App\Enums\EncounterStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationRevision;
use App\Models\User;
use App\Models\VisitReason;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->seed(ClinicHoursSeeder::class);
});

test('scheduled patient journey: appointment through dispensing', function () {
    // Setup
    $patient = Patient::factory()->create();
    $optometrist = User::factory()->optometrist()->create();
    $staff = User::factory()->staff()->create();
    $visitReason = VisitReason::factory()->create(['duration_minutes' => 30]);

    // 1. Book appointment
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'visit_reason_id' => $visitReason->id,
        'scheduled_at' => now()->addDay(),
    ]);

    // 2. Check-in creates encounter
    $this->actingAs($staff);
    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->status)->toBe(EncounterStatus::Waiting)
        ->and($encounter->patient_id)->toBe($patient->id)
        ->and($appointment->fresh()->status->name)->toBe('arrived');

    // 3. Optometrist finalizes prescription
    $prescription = app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: [
            'od_sphere' => '-2.00',
            'os_sphere' => '-1.50',
            'pd' => '62.0',
        ],
    );

    expect($prescription->patient_id)->toBe($patient->id)
        ->and($prescription->encounter_id)->toBe($encounter->id);

    // 4. Create and present quotation
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'prescription_id' => $prescription->id,
        'status' => QuotationStatus::Draft,
    ]);
    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'subtotal' => 5000,
        'total' => 5000,
    ]);
    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Frame + Lens',
        'amount' => 5000,
    ]);

    app(PresentQuotation::class)->handle($quotation, $staff);
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Presented);

    // 5. Accept quotation
    app(RecordQuotationDecision::class)->handle($quotation, 'accepted', $staff);
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Accepted);

    // 6. Create job order
    $jobOrder = app(CreateJobOrder::class)->handle($quotation, $staff);
    expect($jobOrder->status)->toBe(JobOrderStatus::Queued)
        ->and($jobOrder->patient_id)->toBe($patient->id);

    // 7. Advance to ready
    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'in_progress');
    app(UpdateJobOrderStatus::class)->handle($jobOrder->fresh(), 'ready_for_dispensing');
    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::ReadyForDispensing);

    // 8. Dispense
    $dispensingEvent = app(DispenseJobOrder::class)->handle(
        jobOrder: $jobOrder->fresh(),
        dispenser: $staff,
        officialNumber: 'SI-2026-001',
    );

    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::Dispensed)
        ->and($dispensingEvent->invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($dispensingEvent->invoice->official_number)->toBe('SI-2026-001');
});

test('walk-in patient journey: registration through encounter', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $visitReason = VisitReason::factory()->create(['duration_minutes' => 30]);

    // Walk-in creates same-day appointment
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'visit_reason_id' => $visitReason->id,
        'source' => 'walk_in',
        'scheduled_at' => now(),
    ]);

    // Check-in
    $this->actingAs($staff);
    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->patient_id)->toBe($patient->id)
        ->and($encounter->status)->toBe(EncounterStatus::Waiting);
});

test('patient isolation: cannot access another patients records', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();

    // Create records for patient B
    $appointmentB = Appointment::factory()->create(['patient_id' => $userB->patient->id]);
    $prescriptionB = Prescription::factory()->create(['patient_id' => $userB->patient->id]);
    $quotationB = Quotation::factory()->create(['patient_id' => $userB->patient->id]);
    $jobOrderB = JobOrder::factory()->create(['patient_id' => $userB->patient->id]);
    $invoiceB = Invoice::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA);

    // All should return not-found or forbidden
    $this->getJson("/api/v1/appointments/{$appointmentB->id}")->assertNotFound();
    $this->getJson("/api/v1/prescriptions/{$prescriptionB->id}")->assertNotFound();
    $this->getJson("/api/v1/quotations/{$quotationB->id}")->assertNotFound();
    $this->getJson("/api/v1/job-orders/{$jobOrderB->id}")->assertNotFound();
    $this->getJson("/api/v1/invoices/{$invoiceB->id}")->assertNotFound();
});

test('receptionist capability boundary', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Waiting]);

    // Receptionist cannot start encounter
    expect($staff->hasOptometristCapability())->toBeFalse();
});
