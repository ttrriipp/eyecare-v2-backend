<?php

namespace Database\Seeders;

use App\Enums\AppointmentRequestStatus;
use App\Enums\BillingItemSourceKind;
use App\Enums\BillingRecordStatus;
use App\Enums\CommercialItemKind;
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
use App\Models\BillingRecordItem;
use App\Models\Encounter;
use App\Models\EncounterAddendum;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Patient;
use App\Models\PatientLinkRequest;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationItem;
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
        // Reuses the flagship portal account rather than the factory's default
        // nested User::factory()->patient(), whose auto-created Patient relies
        // on a `creating` event that WithoutModelEvents silences here.
        $patient = $this->flagshipPatient();
        $portalUserId = $patient->user_id;
        $staffId = $this->staff()->id;

        // Also override appointment_type_id — left to its own default, the
        // factory spawns a brand-new random-word AppointmentType per call.
        $checkUpTypeId = AppointmentType::query()->where('name', 'Routine Check-up')->value('id');

        $sharedAttributes = [
            'user_id' => $portalUserId,
            'patient_id' => $patient->id,
            'appointment_type_id' => $checkUpTypeId,
            'provisional_duration_minutes' => 30,
            'alternative_scheduled_times' => null,
            'encrypted_identity_snapshot' => null,
            'encrypted_referring_source' => null,
            'rejection_reason' => null,
        ];

        $requestedTime = now()->addDays(2)->setTime(10, 0);
        $requestExpiry = $requestedTime->copy()->addDay();

        // A real submission from a linked portal account always resolves
        // patient_id (see SubmitAppointmentRequest), so every seeded row
        // here should carry it too — leaving it null misrepresents the
        // account as unlinked in the admin panel. Keep the records keyed by
        // their demo request numbers so rerunning this seeder repairs stale
        // or previously random fixture values instead of leaving them behind.
        AppointmentRequest::query()->updateOrCreate(
            ['request_number' => 'APR-2026-000001'],
            [
                ...$sharedAttributes,
                'appointment_id' => null,
                'scheduled_at' => $requestedTime,
                'expires_at' => $requestExpiry,
                'encrypted_reason_for_visit' => 'Routine eye exam and prescription update.',
                'status' => AppointmentRequestStatus::Pending,
                'resolved_by_user_id' => null,
                'resolved_at' => null,
            ],
        );

        // AcceptAppointmentRequest always produces a linked Appointment —
        // an "accepted" request with no resulting appointment can't happen
        // in the real flow. Resolve it into the flagship patient's
        // already-seeded scheduled appointment (ClinicWorkflowSeeder)
        // rather than fabricating an orphan second one.
        $resultingAppointment = Appointment::query()->where('appointment_number', 'APT-2026-000001')->firstOrFail();
        AppointmentRequest::query()->updateOrCreate(
            ['request_number' => 'APR-2026-000002'],
            [
                ...$sharedAttributes,
                'appointment_id' => $resultingAppointment->id,
                'scheduled_at' => $resultingAppointment->scheduled_at,
                'expires_at' => $resultingAppointment->scheduled_at->copy()->addDay(),
                'encrypted_reason_for_visit' => 'Blurred vision and eye strain while working on a computer.',
                'status' => AppointmentRequestStatus::Accepted,
                'resolved_by_user_id' => $staffId,
                'resolved_at' => now()->subDays(2),
            ],
        );

        AppointmentRequest::query()->updateOrCreate(
            ['request_number' => 'APR-2026-000003'],
            [
                ...$sharedAttributes,
                'appointment_id' => null,
                'scheduled_at' => $requestedTime,
                'expires_at' => $requestExpiry,
                'encrypted_reason_for_visit' => 'Eye pain and redness needing urgent assessment.',
                'status' => AppointmentRequestStatus::Rejected,
                'resolved_by_user_id' => $staffId,
                'resolved_at' => now()->subDay(),
                'rejection_reason' => 'Requested time is outside clinic hours for this appointment type.',
            ],
        );

        AppointmentRequest::query()->updateOrCreate(
            ['request_number' => 'APR-2026-000004'],
            [
                ...$sharedAttributes,
                'appointment_id' => null,
                'scheduled_at' => $requestedTime,
                'expires_at' => $requestExpiry,
                'encrypted_reason_for_visit' => 'Patient requested cancellation due to a schedule conflict.',
                'status' => AppointmentRequestStatus::Cancelled,
                'resolved_by_user_id' => null,
                'resolved_at' => null,
            ],
        );

        AppointmentRequest::query()->updateOrCreate(
            ['request_number' => 'APR-2026-000005'],
            [
                ...$sharedAttributes,
                'appointment_id' => null,
                'scheduled_at' => now()->subHours(2),
                'expires_at' => now()->subHour(),
                'encrypted_reason_for_visit' => 'Follow-up after a recent prescription change.',
                'status' => AppointmentRequestStatus::Expired,
                'resolved_by_user_id' => null,
                'resolved_at' => null,
            ],
        );
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
            ['encounter_number' => 'CON-2026-000002', 'optometrist_id' => $optometrist->id],
        );

        Encounter::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'status' => EncounterStatus::InProgress],
            ['encounter_number' => 'CON-2026-000003', 'optometrist_id' => $optometrist->id, 'started_at' => now()->subMinutes(20)],
        );

        Encounter::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'status' => EncounterStatus::Cancelled],
            ['encounter_number' => 'CON-2026-000004', 'optometrist_id' => $optometrist->id, 'started_at' => now()->subDays(3)],
        );

        Encounter::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'status' => EncounterStatus::Voided],
            [
                'encounter_number' => 'CON-2026-000005',
                'optometrist_id' => $optometrist->id,
                'started_at' => now()->subDays(10),
                'completed_at' => now()->subDays(10)->addHour(),
                'void_reason' => 'Duplicate entry — merged with the correct encounter.',
            ],
        );

        // A second completed encounter (this time for the walk-in patient,
        // not just the linked-account flagship), with its own prescription,
        // so the prescription-aware retail flow isn't only demonstrated
        // once. Feeds seedQuotationStatuses()/seedJobOrderStatuses() below.
        $secondCompletedEncounter = Encounter::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'status' => EncounterStatus::Completed],
            [
                'encounter_number' => 'CON-2026-000006',
                'optometrist_id' => $optometrist->id,
                'started_at' => now()->subDays(4),
                'completed_at' => now()->subDays(4)->addHour(),
            ],
        );

        Prescription::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'encounter_id' => $secondCompletedEncounter->id],
            [
                'prescription_number' => 'RX-2026-000002',
                'main_od_sphere' => -1.25,
                'main_od_cylinder' => -0.25,
                'main_os_sphere' => -1.50,
                'main_os_cylinder' => -0.50,
                'remarks' => 'Mild myopia with slight astigmatism. Single-vision distance lenses recommended.',
                'prescribed_at' => now()->subDays(4)->addHour(),
                'created_by' => $optometrist->id,
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
        $tortoiseFrameVariant = $this->catalogFrameVariant('FRM-ANTHOS-MB1399A-C4');
        $classicFrameVariant = $this->catalogFrameVariant('FRM-SOFIA-2860-GRY');

        $draft = Quotation::query()->firstOrCreate(
            ['quotation_number' => 'QUO-2026-000002'],
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

        QuotationItem::query()->updateOrCreate(
            ['quotation_id' => $draft->id, 'description' => 'Everyday Frame — Tortoise'],
            [
                'quantity' => 1,
                'unit_price' => 1700,
                'amount' => 1700,
                'product_variant_id' => $tortoiseFrameVariant->id,
                'item_kind' => CommercialItemKind::Frame,
            ],
        );

        QuotationItem::query()->firstOrCreate(
            ['quotation_id' => $draft->id, 'description' => 'Single Vision Lens'],
            ['quantity' => 1, 'unit_price' => 2500, 'amount' => 2500, 'item_kind' => CommercialItemKind::LensPackage],
        );

        $declined = Quotation::query()->firstOrCreate(
            ['quotation_number' => 'QUO-2026-000003'],
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

        QuotationItem::query()->updateOrCreate(
            ['quotation_id' => $declined->id, 'description' => 'Classic Frame — Matte Black'],
            [
                'quantity' => 1,
                'unit_price' => 3000,
                'amount' => 3000,
                'product_variant_id' => $classicFrameVariant->id,
                'item_kind' => CommercialItemKind::Frame,
            ],
        );

        QuotationItem::query()->firstOrCreate(
            ['quotation_id' => $declined->id, 'description' => 'Single Vision Lens with AR Coating'],
            ['quantity' => 1, 'unit_price' => 2800, 'amount' => 2800, 'item_kind' => CommercialItemKind::LensPackage],
        );

        // Accepted quotation tied to the walk-in patient's own completed
        // encounter/prescription (see seedEncounterStatuses()) — feeds the
        // in-progress job order below, so that order shows the full
        // prescription-aware retail chain, not just a walk-in optical sale.
        $encounter = Encounter::query()
            ->where('patient_id', $patient->id)
            ->where('status', EncounterStatus::Completed)
            ->where('encounter_number', 'CON-2026-000006')
            ->firstOrFail();
        $prescription = Prescription::query()->where('encounter_id', $encounter->id)->firstOrFail();

        $linkedQuotation = Quotation::query()->firstOrCreate(
            ['quotation_number' => 'QUO-2026-000004'],
            [
                'patient_id' => $patient->id,
                'encounter_id' => $encounter->id,
                'prescription_id' => $prescription->id,
                'status' => QuotationStatus::Accepted,
                'valid_until' => now()->addDays(14),
                'subtotal' => 4200,
                'discount_amount' => 0,
                'total' => 4200,
                'confirmed_by' => $this->staff()->id,
                'confirmed_at' => now()->subDays(3),
                'notes' => 'Standard frame with single-vision lenses.',
            ],
        );

        QuotationItem::query()->updateOrCreate(
            ['quotation_id' => $linkedQuotation->id, 'description' => 'Everyday Frame — Tortoise'],
            [
                'quantity' => 1,
                'unit_price' => 1700,
                'amount' => 1700,
                'product_variant_id' => $tortoiseFrameVariant->id,
                'item_kind' => CommercialItemKind::Frame,
            ],
        );

        QuotationItem::query()->firstOrCreate(
            ['quotation_id' => $linkedQuotation->id, 'description' => 'Single Vision Lens'],
            ['quantity' => 1, 'unit_price' => 2500, 'amount' => 2500, 'item_kind' => CommercialItemKind::LensPackage],
        );
    }

    private function seedJobOrderStatuses(): void
    {
        $patient = $this->walkInPatient();
        $tortoiseFrameVariant = $this->catalogFrameVariant('FRM-ANTHOS-MB1399A-C4');
        $classicFrameVariant = $this->catalogFrameVariant('FRM-SOFIA-2860-GRY');
        $blackFrameVariant = $this->catalogFrameVariant('FRM-SPORT-BLKRED-001');

        $queued = JobOrder::query()->firstOrCreate(
            ['job_order_number' => 'ORD-2026-000002'],
            [
                'patient_id' => $patient->id,
                'status' => JobOrderStatus::Queued,
                'total_amount' => 3200,
            ],
        );

        JobOrderItem::query()->updateOrCreate(
            ['job_order_id' => $queued->id, 'description' => 'Everyday Frame — Tortoise'],
            [
                'quantity' => 1,
                'unit_price' => 3200,
                'amount' => 3200,
                'product_variant_id' => $tortoiseFrameVariant->id,
                'item_kind' => CommercialItemKind::Frame,
            ],
        );

        // Linked to the accepted quotation/encounter/prescription seeded in
        // seedQuotationStatuses(), so at least one non-flagship job order
        // demonstrates the full clinical-to-retail chain too.
        $linkedQuotation = Quotation::query()->where('quotation_number', 'QUO-2026-000004')->firstOrFail();

        $inProgress = JobOrder::query()->firstOrCreate(
            ['job_order_number' => 'ORD-2026-000003'],
            [
                'patient_id' => $patient->id,
                'encounter_id' => $linkedQuotation->encounter_id,
                'prescription_id' => $linkedQuotation->prescription_id,
                'quotation_id' => $linkedQuotation->id,
                'status' => JobOrderStatus::InProgress,
                'total_amount' => 4200,
                'started_at' => now()->subDay(),
            ],
        );

        JobOrderItem::query()->updateOrCreate(
            ['job_order_id' => $inProgress->id, 'description' => 'Everyday Frame — Tortoise'],
            [
                'quantity' => 1,
                'unit_price' => 1700,
                'amount' => 1700,
                'product_variant_id' => $tortoiseFrameVariant->id,
                'item_kind' => CommercialItemKind::Frame,
            ],
        );

        JobOrderItem::query()->firstOrCreate(
            ['job_order_id' => $inProgress->id, 'description' => 'Single Vision Lens'],
            ['quantity' => 1, 'unit_price' => 2500, 'amount' => 2500, 'item_kind' => CommercialItemKind::LensPackage],
        );

        $dispensed = JobOrder::query()->firstOrCreate(
            ['job_order_number' => 'ORD-2026-000004'],
            [
                'patient_id' => $patient->id,
                'status' => JobOrderStatus::Dispensed,
                'total_amount' => 2800,
                'started_at' => now()->subDays(5),
                'ready_at' => now()->subDays(3),
                'dispensed_at' => now()->subDay(),
            ],
        );

        JobOrderItem::query()->updateOrCreate(
            ['job_order_id' => $dispensed->id, 'description' => 'Classic Frame — Matte Black'],
            [
                'quantity' => 1,
                'unit_price' => 2800,
                'amount' => 2800,
                'product_variant_id' => $classicFrameVariant->id,
                'item_kind' => CommercialItemKind::Frame,
            ],
        );

        $cancelled = JobOrder::query()->firstOrCreate(
            ['job_order_number' => 'ORD-2026-000005'],
            [
                'patient_id' => $patient->id,
                'status' => JobOrderStatus::Cancelled,
                'total_amount' => 1800,
                'cancelled_at' => now(),
                'notes' => 'Patient cancelled after the frame went out of stock.',
            ],
        );

        JobOrderItem::query()->updateOrCreate(
            ['job_order_id' => $cancelled->id, 'description' => 'Classic Frame — Black'],
            [
                'quantity' => 1,
                'unit_price' => 1800,
                'amount' => 1800,
                'product_variant_id' => $blackFrameVariant->id,
                'item_kind' => CommercialItemKind::Frame,
            ],
        );
    }

    private function catalogFrameVariant(string $sku): ProductVariant
    {
        return ProductVariant::query()
            ->where('sku', $sku)
            ->whereHas('product', fn ($query) => $query->where('product_type', 'frame'))
            ->firstOrFail();
    }

    private function seedBillingRecordStatuses(): void
    {
        $staff = $this->staff();
        $patient = $this->walkInPatient();

        $queued = JobOrder::query()->where('job_order_number', 'ORD-2026-000002')->firstOrFail();
        $queuedBilling = BillingRecord::query()->firstOrCreate(
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
        $this->seedBillingRecordItems($queuedBilling, $queued);

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
        $this->seedBillingRecordItems($paid, $dispensed);

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
        $cancelledBilling = BillingRecord::query()->firstOrCreate(
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
        $this->seedBillingRecordItems($cancelledBilling, $cancelled);
    }

    private function seedBillingRecordItems(BillingRecord $billingRecord, JobOrder $jobOrder): void
    {
        foreach ($jobOrder->items as $jobOrderItem) {
            BillingRecordItem::query()->updateOrCreate(
                [
                    'billing_record_id' => $billingRecord->id,
                    'job_order_item_id' => $jobOrderItem->id,
                ],
                [
                    'source_kind' => BillingItemSourceKind::OpticalOrder,
                    'description' => $jobOrderItem->description,
                    'quantity' => $jobOrderItem->quantity,
                    'unit_price' => $jobOrderItem->unit_price,
                    'amount' => $jobOrderItem->amount,
                ],
            );
        }
    }
}
