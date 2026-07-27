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
use App\Models\AppointmentType;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationRevision;
use App\Models\User;
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
    $patient = Patient::factory()->create();
    $optometrist = User::factory()->optometrist()->create();
    $staff = User::factory()->staff()->create();
    $appointmentType = AppointmentType::query()->where('name', 'Routine Check-up')->first();

    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'appointment_type_id' => $appointmentType->id,
        'duration_minutes' => $appointmentType->duration_minutes,
        'scheduled_at' => now()->addDay(),
    ]);

    $this->actingAs($staff);
    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->status)->toBe(EncounterStatus::Planned)
        ->and($encounter->patient_id)->toBe($patient->id)
        ->and($appointment->fresh()->status->name)->toBe('checked_in');

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

    app(RecordQuotationDecision::class)->handle($quotation, 'accepted', $staff);
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Accepted);

    $jobOrder = app(CreateJobOrder::class)->handle($quotation, $staff);
    expect($jobOrder->status)->toBe(JobOrderStatus::Queued)
        ->and($jobOrder->patient_id)->toBe($patient->id);

    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'in_progress');
    app(UpdateJobOrderStatus::class)->handle($jobOrder->fresh(), 'ready_for_dispensing');
    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::ReadyForDispensing);

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
    $appointmentType = AppointmentType::query()->where('name', 'Routine Check-up')->first();

    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'appointment_type_id' => $appointmentType->id,
        'duration_minutes' => $appointmentType->duration_minutes,
        'source' => 'walk_in',
        'scheduled_at' => now(),
    ]);

    $this->actingAs($staff);
    $encounter = app(CheckInAppointment::class)->handle($appointment);

    expect($encounter->patient_id)->toBe($patient->id)
        ->and($encounter->status)->toBe(EncounterStatus::Planned);
});

test('patient isolation: cannot access another patients records', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();

    $appointmentB = Appointment::factory()->create(['patient_id' => $userB->patient->id]);
    $prescriptionB = Prescription::factory()->create(['patient_id' => $userB->patient->id]);
    $quotationB = Quotation::factory()->create(['patient_id' => $userB->patient->id]);
    $jobOrderB = JobOrder::factory()->create(['patient_id' => $userB->patient->id]);
    $invoiceB = Invoice::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA);

    $this->getJson("/api/v1/appointments/{$appointmentB->id}")->assertNotFound();
    $this->getJson("/api/v1/prescriptions/{$prescriptionB->id}")->assertNotFound();
    $this->getJson("/api/v1/quotations/{$quotationB->id}")->assertNotFound();
    $this->getJson("/api/v1/job-orders/{$jobOrderB->id}")->assertNotFound();
    $this->getJson("/api/v1/invoices/{$invoiceB->id}")->assertNotFound();
});

test('receptionist capability boundary', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Planned]);

    expect($staff->hasOptometristCapability())->toBeFalse();
});
