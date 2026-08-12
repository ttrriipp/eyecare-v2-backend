<?php

use App\Actions\BillingRecords\DispenseJobOrder;
use App\Actions\Encounters\CheckInAppointment;
use App\Actions\Encounters\StartEncounter;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Actions\OpticalOrders\CreateOpticalOrderFromQuotation;
use App\Actions\Prescriptions\FinalizePrescription;
use App\Enums\BillingRecordStatus;
use App\Enums\EncounterStatus;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Quotation;
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
    $encounter->update(['optometrist_id' => $optometrist->id]);

    expect($encounter->status)->toBe(EncounterStatus::Planned)
        ->and($encounter->patient_id)->toBe($patient->id)
        ->and($appointment->fresh()->status->name)->toBe('checked_in');

    $encounter = app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $optometrist,
    );

    $prescription = app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: [
            'main_od_sphere' => '-2.00',
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
        'subtotal' => 5000,
        'total' => 5000,
    ]);
    $quotation->items()->create([
        'description' => 'Frame + Lens',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $staff,
    );
    expect($result['quotation']->status)->toBe(QuotationStatus::Accepted);

    $jobOrder = $result['optical_order'];
    expect($jobOrder->status)->toBe(JobOrderStatus::Queued)
        ->and($jobOrder->patient_id)->toBe($patient->id);

    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'in_progress');
    $jobOrder->update(['supplier_invoice_number' => 'SUP-INV-E2E-001']);
    app(UpdateJobOrderStatus::class)->handle($jobOrder->fresh(), 'ready_for_dispensing');
    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::ReadyForDispensing);

    $dispensingEvent = app(DispenseJobOrder::class)->handle(
        jobOrder: $jobOrder->fresh(),
        dispenser: $staff,
        pickupPaymentAmount: 5000,
        pickupPaymentMethod: 'cash',
    );

    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::Dispensed)
        ->and($dispensingEvent->billingRecord->status)->toBe(BillingRecordStatus::Paid)
        ->and($dispensingEvent->billingRecord->billing_record_number)->toStartWith('BR-');
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
    $jobOrderB = JobOrder::factory()->create(['patient_id' => $userB->patient->id]);
    $billingRecordB = BillingRecord::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA);

    $this->getJson("/api/v1/appointments/{$appointmentB->id}")->assertNotFound();
    $this->getJson("/api/v1/prescriptions/{$prescriptionB->id}")->assertNotFound();
    $this->getJson("/api/v1/job-orders/{$jobOrderB->id}")->assertNotFound();
    $this->getJson("/api/v1/billing-records/{$billingRecordB->id}")->assertNotFound();
});

test('receptionist capability boundary', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Planned]);

    expect($staff->hasOptometristCapability())->toBeFalse();
});
