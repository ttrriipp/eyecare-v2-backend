<?php

namespace Database\Seeders;

use App\Enums\BillingRecordStatus;
use App\Enums\EncounterAddendumType;
use App\Enums\EncounterStatus;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\EncounterAddendum;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\PatientLinkRequest;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Supplementary records that round out status coverage beyond the single
 * flagship path in ClinicWorkflowSeeder — one representative record for
 * every remaining status, so each has something to look at in the admin
 * panel demo.
 *
 * Several models (Quotation, JobOrder, BillingRecord, Prescription,
 * AppointmentRequest, PatientLinkRequest) assign their reference number in a
 * `creating` model event that DatabaseSeeder's WithoutModelEvents silences,
 * so those numbers are assigned by hand here — same as ClinicWorkflowSeeder.
 */
class ScenarioCoverageSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAppointmentStatuses();
        $this->seedAppointmentRequests();
        $this->seedFrameReservations();
        $this->seedPatientLinkRequests();
        $this->seedEncounterStatuses();
        $this->seedQuotationStatuses();
        $this->seedJobOrderStatuses();
        $this->seedBillingRecordStatuses();
    }

    private function flagshipPatient(): Patient
    {
        $user = User::query()->where('email', 'customer@eyecare.test')->firstOrFail();

        return Patient::query()->where('user_id', $user->id)->firstOrFail();
    }

    private function walkInPatient(): Patient
    {
        return Patient::query()->where('first_name', 'Pedro')->where('last_name', 'Cruz')->firstOrFail();
    }

    private function staff(): User
    {
        return User::query()->where('email', 'staff@eyecare.test')->firstOrFail();
    }

    private function optometrist(): User
    {
        return User::query()->where('email', 'owner@eyecare.test')->firstOrFail();
    }

    private function seedAppointmentStatuses(): void
    {
        $staff = $this->staff();
        $walkIn = $this->walkInPatient();
        // Spread across appointment types not already exercised by the
        // flagship path (which only uses Routine Check-up), so the demo
        // shows New Patient, Referral, and Follow-up too.
        $newPatientType = AppointmentType::query()->where('name', 'New Patient')->firstOrFail();
        $referralType = AppointmentType::query()->where('name', 'Referral')->firstOrFail();
        $followUpType = AppointmentType::query()->where('name', 'Follow-up')->firstOrFail();

        $checkedIn = AppointmentStatus::query()->where('name', 'checked_in')->firstOrFail();
        Appointment::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'appointment_status_id' => $checkedIn->id],
            [
                'appointment_number' => Appointment::generateAppointmentNumber(),
                'created_by' => $staff->id,
                'appointment_type_id' => $newPatientType->id,
                'duration_minutes' => $newPatientType->duration_minutes,
                'scheduled_at' => now()->setTime(9, 0),
                'checked_in_at' => now(),
            ],
        );

        $cancelled = AppointmentStatus::query()->where('name', 'cancelled')->firstOrFail();
        Appointment::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'appointment_status_id' => $cancelled->id],
            [
                'appointment_number' => Appointment::generateAppointmentNumber(),
                'created_by' => $staff->id,
                'appointment_type_id' => $referralType->id,
                'duration_minutes' => $referralType->duration_minutes,
                'scheduled_at' => now()->addDays(5)->setTime(11, 0),
                'cancelled_by' => 'patient',
                'cancelled_at' => now(),
                'cancellation_reason_category' => 'schedule_conflict',
            ],
        );

        $noShow = AppointmentStatus::query()->where('name', 'no_show')->firstOrFail();
        Appointment::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'appointment_status_id' => $noShow->id],
            [
                'appointment_number' => Appointment::generateAppointmentNumber(),
                'created_by' => $staff->id,
                'appointment_type_id' => $followUpType->id,
                'duration_minutes' => $followUpType->duration_minutes,
                'scheduled_at' => now()->subDays(2)->setTime(13, 0),
                'no_show_at' => now()->subDays(2)->setTime(13, 15),
            ],
        );
    }

    private function seedAppointmentRequests(): void
    {
        if (AppointmentRequest::query()->count() > 0) {
            return;
        }

        // Reuses the flagship portal account rather than the factory's default
        // nested User::factory()->patient(), whose auto-created Patient relies
        // on a `creating` event that WithoutModelEvents silences here.
        $portalUserId = User::query()->where('email', 'customer@eyecare.test')->value('id');

        // Also override appointment_type_id — left to its own default, the
        // factory spawns a brand-new random-word AppointmentType per call.
        $checkUpTypeId = AppointmentType::query()->where('name', 'Routine Check-up')->value('id');

        AppointmentRequest::factory()->create(['request_number' => 'APR-2026-000001', 'user_id' => $portalUserId, 'appointment_type_id' => $checkUpTypeId]);
        AppointmentRequest::factory()->accepted()->create(['request_number' => 'APR-2026-000002', 'user_id' => $portalUserId, 'appointment_type_id' => $checkUpTypeId]);
        AppointmentRequest::factory()->rejected()->create(['request_number' => 'APR-2026-000003', 'user_id' => $portalUserId, 'appointment_type_id' => $checkUpTypeId]);
        AppointmentRequest::factory()->cancelled()->create(['request_number' => 'APR-2026-000004', 'user_id' => $portalUserId, 'appointment_type_id' => $checkUpTypeId]);
        AppointmentRequest::factory()->expired()->create(['request_number' => 'APR-2026-000005', 'user_id' => $portalUserId, 'appointment_type_id' => $checkUpTypeId]);
    }

    private function seedFrameReservations(): void
    {
        if (FrameReservation::query()->count() > 0) {
            return;
        }

        $scheduled = AppointmentStatus::query()->where('name', 'scheduled')->firstOrFail();
        $pendingAppointment = Appointment::query()
            ->where('patient_id', $this->flagshipPatient()->id)
            ->where('appointment_status_id', $scheduled->id)
            ->firstOrFail();

        $checkedIn = AppointmentStatus::query()->where('name', 'checked_in')->firstOrFail();
        $acceptedAppointment = Appointment::query()
            ->where('patient_id', $this->walkInPatient()->id)
            ->where('appointment_status_id', $checkedIn->id)
            ->firstOrFail();

        $matteBlack = ProductVariant::query()->where('sku', 'CRF-BLK-001')->firstOrFail();
        $pending = FrameReservation::query()->create([
            'patient_id' => $pendingAppointment->patient_id,
            'appointment_id' => $pendingAppointment->id,
            'staff_notes' => 'Patient wants to try the matte black frame before deciding.',
        ]);
        FrameReservationItem::query()->create([
            'frame_reservation_id' => $pending->id,
            'product_variant_id' => $matteBlack->id,
        ]);

        $gold = ProductVariant::query()->where('sku', 'RMF-GLD-001')->firstOrFail();
        $accepted = FrameReservation::query()->create([
            'patient_id' => $acceptedAppointment->patient_id,
            'appointment_id' => $acceptedAppointment->id,
            'accepted_at' => now(),
            'staff_notes' => 'Confirmed fit during check-in.',
        ]);
        FrameReservationItem::query()->create([
            'frame_reservation_id' => $accepted->id,
            'product_variant_id' => $gold->id,
        ]);
    }

    private function seedPatientLinkRequests(): void
    {
        if (PatientLinkRequest::query()->count() > 0) {
            return;
        }

        $staff = $this->staff();
        $patientRoleId = Role::query()->where('name', Role::Patient)->value('id');

        $pendingUser = User::factory()->create(['role_id' => $patientRoleId]);
        PatientLinkRequest::factory()->pending()->create([
            'request_number' => 'PLR-2026-000001',
            'user_id' => $pendingUser->id,
        ]);

        $rejectedUser = User::factory()->create(['role_id' => $patientRoleId]);
        PatientLinkRequest::factory()->rejected()->create([
            'request_number' => 'PLR-2026-000002',
            'user_id' => $rejectedUser->id,
            'reviewer_id' => $staff->id,
        ]);

        // A dedicated walk-in match — not the canonical Pedro Cruz, who other
        // tests expect to remain unlinked.
        $approvedUser = User::factory()->create(['role_id' => $patientRoleId]);
        $approvedMatch = Patient::factory()->walkIn()->create(['patient_number' => 'PAT-2026-000003']);
        PatientLinkRequest::factory()->approved()->create([
            'request_number' => 'PLR-2026-000003',
            'user_id' => $approvedUser->id,
            'reviewed_patient_id' => $approvedMatch->id,
            'reviewer_id' => $staff->id,
        ]);
        $approvedMatch->update(['user_id' => $approvedUser->id]);
    }

    private function seedEncounterStatuses(): void
    {
        $walkIn = $this->walkInPatient();
        $optometrist = $this->optometrist();

        Encounter::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'status' => EncounterStatus::Planned],
            ['encounter_number' => 'ENC-000002', 'optometrist_id' => $optometrist->id],
        );

        Encounter::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'status' => EncounterStatus::InProgress],
            ['encounter_number' => 'ENC-000003', 'optometrist_id' => $optometrist->id, 'started_at' => now()->subMinutes(20)],
        );

        Encounter::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'status' => EncounterStatus::Cancelled],
            ['encounter_number' => 'ENC-000004', 'optometrist_id' => $optometrist->id, 'started_at' => now()->subDays(3)],
        );

        Encounter::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'status' => EncounterStatus::Voided],
            [
                'encounter_number' => 'ENC-000005',
                'optometrist_id' => $optometrist->id,
                'started_at' => now()->subDays(10),
                'completed_at' => now()->subDays(10)->addHour(),
                'void_reason' => 'Duplicate entry — merged with the correct encounter.',
            ],
        );

        // Addendum on the flagship completed encounter, to demonstrate the
        // amended-record print flow.
        $flagshipEncounter = Encounter::query()
            ->where('patient_id', $this->flagshipPatient()->id)
            ->where('status', EncounterStatus::Completed)
            ->firstOrFail();

        EncounterAddendum::query()->firstOrCreate(
            ['encounter_id' => $flagshipEncounter->id, 'sequence_number' => 1],
            [
                'type' => EncounterAddendumType::Correction,
                'reason' => 'Cylinder value transcribed incorrectly during the visit.',
                'content' => 'OS cylinder corrected from -0.75 to -1.00 based on re-verification of the phoropter reading.',
                'authored_by' => $optometrist->id,
                'authored_at' => now(),
            ],
        );
    }

    private function seedQuotationStatuses(): void
    {
        $patient = $this->walkInPatient();

        Quotation::query()->firstOrCreate(
            ['quotation_number' => 'QUO-000002'],
            [
                'patient_id' => $patient->id,
                'status' => QuotationStatus::Draft,
                'valid_until' => now()->addDays(14),
                'subtotal' => 4200,
                'discount_amount' => 0,
                'total' => 4200,
                'notes' => 'Awaiting patient decision on lens upgrade.',
            ],
        );

        Quotation::query()->firstOrCreate(
            ['quotation_number' => 'QUO-000003'],
            [
                'patient_id' => $patient->id,
                'status' => QuotationStatus::Declined,
                'valid_until' => now()->subDays(2),
                'subtotal' => 5800,
                'discount_amount' => 0,
                'total' => 5800,
                'notes' => 'Patient opted for a different provider.',
            ],
        );
    }

    private function seedJobOrderStatuses(): void
    {
        $patient = $this->walkInPatient();

        JobOrder::query()->firstOrCreate(
            ['job_order_number' => 'ORD-2026-000002'],
            [
                'patient_id' => $patient->id,
                'status' => JobOrderStatus::Queued,
                'total_amount' => 3200,
            ],
        );

        JobOrder::query()->firstOrCreate(
            ['job_order_number' => 'ORD-2026-000003'],
            [
                'patient_id' => $patient->id,
                'status' => JobOrderStatus::InProgress,
                'total_amount' => 4200,
                'started_at' => now()->subDay(),
            ],
        );

        JobOrder::query()->firstOrCreate(
            ['job_order_number' => 'ORD-2026-000004'],
            [
                'patient_id' => $patient->id,
                'status' => JobOrderStatus::Dispensed,
                'total_amount' => 2800,
                'started_at' => now()->subDays(5),
                'ready_at' => now()->subDays(3),
            ],
        );

        JobOrder::query()->firstOrCreate(
            ['job_order_number' => 'ORD-2026-000005'],
            [
                'patient_id' => $patient->id,
                'status' => JobOrderStatus::Cancelled,
                'total_amount' => 1800,
                'notes' => 'Patient cancelled after the frame went out of stock.',
            ],
        );
    }

    private function seedBillingRecordStatuses(): void
    {
        $staff = $this->staff();
        $patient = $this->walkInPatient();

        $queued = JobOrder::query()->where('job_order_number', 'ORD-2026-000002')->firstOrFail();
        BillingRecord::query()->firstOrCreate(
            ['billing_record_number' => 'BR-2026-000002'],
            [
                'patient_id' => $patient->id,
                'job_order_id' => $queued->id,
                'status' => BillingRecordStatus::Unpaid,
                'subtotal_amount' => 3200,
                'discount_amount' => 0,
                'total_amount' => 3200,
                'amount_paid' => 0,
                'balance_due' => 3200,
                'recorded_by' => $staff->id,
                'recorded_at' => now(),
                'payment_due_date' => now()->addDays(14),
            ],
        );

        $dispensed = JobOrder::query()->where('job_order_number', 'ORD-2026-000004')->firstOrFail();
        $paid = BillingRecord::query()->firstOrCreate(
            ['billing_record_number' => 'BR-2026-000003'],
            [
                'patient_id' => $patient->id,
                'job_order_id' => $dispensed->id,
                'status' => BillingRecordStatus::Paid,
                'subtotal_amount' => 2800,
                'discount_amount' => 0,
                'total_amount' => 2800,
                'amount_paid' => 2800,
                'balance_due' => 0,
                'recorded_by' => $staff->id,
                'recorded_at' => now()->subDays(3),
            ],
        );
        BillingPayment::query()->firstOrCreate(
            ['billing_record_id' => $paid->id, 'amount' => 2800],
            [
                'payment_method' => 'cash',
                'status' => 'posted',
                'recorded_by' => $staff->id,
                'recorded_at' => now()->subDays(3),
            ],
        );

        $cancelled = JobOrder::query()->where('job_order_number', 'ORD-2026-000005')->firstOrFail();
        BillingRecord::query()->firstOrCreate(
            ['billing_record_number' => 'BR-2026-000004'],
            [
                'patient_id' => $patient->id,
                'job_order_id' => $cancelled->id,
                'status' => BillingRecordStatus::Voided,
                'subtotal_amount' => 1800,
                'discount_amount' => 0,
                'total_amount' => 1800,
                'amount_paid' => 0,
                'balance_due' => 0,
                'recorded_by' => $staff->id,
                'recorded_at' => now()->subDays(1),
                'voided_by' => $staff->id,
                'voided_at' => now(),
                'void_reason' => 'Order cancelled before dispensing.',
            ],
        );
    }
}
