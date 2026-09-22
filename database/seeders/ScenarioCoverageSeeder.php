<?php

namespace Database\Seeders;

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\LinkPatientAccount;
use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\AppointmentRequestStatus;
use App\Enums\BillingItemSourceKind;
use App\Enums\BillingRecordStatus;
use App\Enums\CommercialItemKind;
use App\Enums\EncounterAddendumType;
use App\Enums\EncounterStatus;
use App\Enums\JobOrderStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\AccessoryOrderRequestItem;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use App\Models\Encounter;
use App\Models\EncounterAddendum;
use App\Models\FrameRating;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Patient;
use App\Models\PatientAccountContact;
use App\Models\PatientLinkRequest;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Models\VisitRating;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Supplementary demo records that round out appointment and workflow status
 * coverage beyond the flagship path in ClinicWorkflowSeeder.
 *
 * Several models (JobOrder, BillingRecord, Prescription,
 * AppointmentRequest, PatientLinkRequest) assign their reference number in a
 * `creating` model event that DatabaseSeeder's WithoutModelEvents silences,
 * so those numbers are assigned by hand here — same as ClinicWorkflowSeeder.
 */
class ScenarioCoverageSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAppointmentStatuses();
        $this->seedPatientLinkRequests();
        $this->seedAppointmentRequests();
        $this->seedEncounterStatuses();
        $this->seedJobOrderStatuses();
        $this->seedBillingRecordStatuses();
        $this->seedAccessoryOrderRequest();
        $this->seedProductRatings();
        $this->seedVisitFeedback();
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
        // Use appointment types beyond the flagship Routine Check-up for the
        // cancelled and no-show examples.
        $referralType = AppointmentType::query()->where('name', 'Referral')->firstOrFail();
        $followUpType = AppointmentType::query()->where('name', 'Follow-up')->firstOrFail();

        $cancelled = AppointmentStatus::query()->where('name', 'cancelled')->firstOrFail();
        Appointment::query()->updateOrCreate(
            ['appointment_number' => 'APT-2026-000004'],
            [
                'patient_id' => $walkIn->id,
                'created_by' => $staff->id,
                'appointment_type_id' => $referralType->id,
                'duration_minutes' => $referralType->duration_minutes,
                'referring_source' => 'Dr. Garcia - City Hospital',
                'source' => 'manual',
                'appointment_status_id' => $cancelled->id,
                'scheduled_at' => now()->addDays(5)->setTime(11, 0),
                'reason_for_visit' => 'Referral for persistent blurred vision and eye strain.',
                'contact_notes' => 'Patient could not attend the original appointment slot.',
                'cancelled_by' => 'clinic',
                'cancelled_by_user_id' => $staff->id,
                'cancelled_at' => now(),
                'cancellation_reason_category' => 'schedule_conflict',
                'cancellation_reason_details' => 'The clinic could not keep the original appointment slot.',
            ],
        );

        $noShow = AppointmentStatus::query()->where('name', 'no_show')->firstOrFail();
        Appointment::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'appointment_status_id' => $noShow->id],
            [
                'appointment_number' => 'APT-2026-000005',
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

        // The flagship patient already has the scheduled appointment seeded by
        // ClinicWorkflowSeeder. Keep the pending request on the separately
        // linked Rosa Santos account so each patient has only one active
        // request or scheduled appointment in the canonical demo data.
        $pendingPatient = Patient::query()->where('patient_number', 'PAT-2026-000003')->firstOrFail();
        if ($pendingPatient->user_id === null) {
            throw new RuntimeException('The seeded pending appointment request patient must be linked to a portal account.');
        }

        $pendingAttributes = [
            ...$sharedAttributes,
            'user_id' => $pendingPatient->user_id,
            'patient_id' => $pendingPatient->id,
        ];

        // A real submission from a linked portal account always resolves
        // patient_id (see SubmitAppointmentRequest), so every seeded row
        // here should carry it too — leaving it null misrepresents the
        // account as unlinked in the admin panel. Keep the records keyed by
        // their demo request numbers so rerunning this seeder repairs stale
        // or previously random fixture values instead of leaving them behind.
        AppointmentRequest::query()->updateOrCreate(
            ['request_number' => 'APR-2026-000001'],
            [
                ...$pendingAttributes,
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
        $approvedUser = User::factory()->create([
            'first_name' => 'Rosa',
            'middle_name' => null,
            'last_name' => 'Santos',
            'date_of_birth' => '1988-04-12',
            'phone' => '09170000005',
            'role_id' => $patientRoleId,
        ]);
        $approvedContactHash = app(CreateContactLookupHash::class)->forPhone('09170000005');
        PatientAccountContact::query()->create([
            'user_id' => $approvedUser->id,
            'type' => 'phone',
            'encrypted_value' => '09170000005',
            'lookup_hash' => $approvedContactHash,
            'verified_at' => now(),
            'is_primary' => true,
        ]);
        $approvedMatch = Patient::factory()->create([
            'patient_number' => 'PAT-2026-000003',
            'first_name' => 'Rosa',
            'middle_name' => null,
            'last_name' => 'Santos',
            'date_of_birth' => '1988-04-12',
            'phone' => '09170000005',
            'user_id' => null,
        ]);
        $approvedMatch->forceFill(['phone_lookup_hash' => $approvedContactHash])->saveQuietly();
        PatientLinkRequest::factory()->approved()->create([
            'request_number' => 'PLR-2026-000003',
            'user_id' => $approvedUser->id,
            'reviewed_patient_id' => $approvedMatch->id,
            'reviewer_id' => $staff->id,
        ]);
        app(LinkPatientAccount::class)->handle(
            account: $approvedUser,
            patient: $approvedMatch,
            source: 'scenario_coverage_seeder',
            sourceId: $approvedMatch->id,
            actorId: $staff->id,
        );
    }

    private function seedEncounterStatuses(): void
    {
        $walkIn = $this->walkInPatient();
        $staff = $this->staff();
        $optometrist = $this->optometrist();

        Encounter::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'status' => EncounterStatus::Planned],
            ['encounter_number' => 'CON-2026-000002', 'optometrist_id' => $optometrist->id],
        );

        Encounter::query()->firstOrCreate(
            ['patient_id' => $walkIn->id, 'status' => EncounterStatus::InProgress],
            ['encounter_number' => 'CON-2026-000003', 'optometrist_id' => $optometrist->id, 'started_at' => now()->subMinutes(20)],
        );

        Encounter::query()->updateOrCreate(
            ['encounter_number' => 'CON-2026-000004'],
            [
                'patient_id' => $walkIn->id,
                'status' => EncounterStatus::Cancelled,
                'optometrist_id' => $optometrist->id,
                'started_at' => now()->subDays(3),
            ],
        );

        Encounter::query()->updateOrCreate(
            ['encounter_number' => 'CON-2026-000005'],
            [
                'patient_id' => $walkIn->id,
                'status' => EncounterStatus::Cancelled,
                'optometrist_id' => $optometrist->id,
                'started_at' => now()->subDays(10),
                'completed_at' => now()->subDays(10)->addHour(),
                'cancellation_reason' => 'Duplicate entry — merged with the correct encounter.',
            ],
        );

        // A second completed encounter (this time for the walk-in patient,
        // not just the linked-account flagship), with its own prescription,
        // so the prescription-aware retail flow isn't only demonstrated
        // once. Feeds seedJobOrderStatuses() below.
        $appointmentType = AppointmentType::query()->where('name', 'Routine Check-up')->firstOrFail();
        $fulfilled = AppointmentStatus::query()->where('name', 'fulfilled')->firstOrFail();
        $scheduledAt = now()->subDays(4)->setTime(15, 0);
        $checkedInAt = $scheduledAt->copy()->addMinutes(5);
        $startedAt = $checkedInAt->copy()->addMinutes(10);
        $completedAt = $startedAt->copy()->addMinutes(45);

        $completedAppointment = Appointment::query()->updateOrCreate(
            ['appointment_number' => 'APT-2026-000006'],
            [
                'patient_id' => $walkIn->id,
                'appointment_type_id' => $appointmentType->id,
                'duration_minutes' => $appointmentType->duration_minutes,
                'created_by' => $staff->id,
                'optometrist_id' => $optometrist->id,
                'source' => 'walk_in',
                'appointment_status_id' => $fulfilled->id,
                'scheduled_at' => $scheduledAt,
                'checked_in_at' => $checkedInAt,
                'checked_in_by' => $staff->id,
                'fulfilled_at' => $completedAt,
                'reason_for_visit' => 'Blurred distance vision during evening driving and prolonged screen use.',
                'contact_notes' => 'Walk-in patient requested an updated distance prescription.',
                'staff_notes' => 'Review refraction results and provide updated single-vision prescription.',
            ],
        );

        $secondCompletedEncounter = Encounter::query()->updateOrCreate(
            ['encounter_number' => 'CON-2026-000006'],
            [
                'patient_id' => $walkIn->id,
                'appointment_id' => $completedAppointment->id,
                'optometrist_id' => $optometrist->id,
                'status' => EncounterStatus::Completed,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'findings' => 'Unaided distance vision is reduced and improves with refraction. Anterior and posterior segment examinations are unremarkable in both eyes. Intraocular pressures are within normal limits.',
                'remarks' => 'Patient advised to use the updated prescription, take regular visual breaks, and return for sudden vision changes, pain, flashes, or floaters.',
                'chief_complaint' => 'Blurred distance vision during evening driving and prolonged screen use.',
                'past_ocular_history' => 'Wears distance glasses; last eye examination was approximately two years ago. No previous ocular trauma reported.',
                'past_surgical_history' => 'No previous ocular surgery reported.',
                'past_medical_history' => 'No known systemic medical conditions reported.',
                'allergies' => 'No known drug allergies.',
                'medications' => 'None reported.',
                'last_wizard_step' => 3,
                'draft_saved_at' => $completedAt,
                'prescription_draft' => null,
                'completed_by' => $optometrist->id,
            ],
        );

        Prescription::query()->updateOrCreate(
            ['patient_id' => $walkIn->id, 'encounter_id' => $secondCompletedEncounter->id],
            [
                'prescription_number' => 'RX-2026-000002',
                'appointment_id' => $completedAppointment->id,
                'main_od_value' => '1.00',
                'main_od_sphere' => '-1.25',
                'main_od_cylinder' => '-0.25',
                'main_os_value' => '1.00',
                'main_os_sphere' => '-1.50',
                'main_os_cylinder' => '-0.50',
                'remarks' => 'Mild myopia with slight astigmatism. Single-vision distance lenses recommended.',
                'prescribed_at' => $completedAt,
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

        // In-progress order linked to the walk-in patient's completed
        // encounter/prescription (see seedEncounterStatuses()).
        $encounter = Encounter::query()
            ->where('patient_id', $patient->id)
            ->where('status', EncounterStatus::Completed)
            ->where('encounter_number', 'CON-2026-000006')
            ->firstOrFail();
        $prescription = Prescription::query()->where('encounter_id', $encounter->id)->firstOrFail();

        $inProgress = JobOrder::query()->firstOrCreate(
            ['job_order_number' => 'ORD-2026-000003'],
            [
                'patient_id' => $patient->id,
                'encounter_id' => $encounter->id,
                'prescription_id' => $prescription->id,
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

        $inProgress = JobOrder::query()->where('job_order_number', 'ORD-2026-000003')->firstOrFail();
        $inProgressBilling = BillingRecord::query()->firstOrCreate(
            ['billing_record_number' => 'BR-2026-000004'],
            [
                'patient_id' => $patient->id,
                'job_order_id' => $inProgress->id,
                'status' => BillingRecordStatus::Unpaid,
                'subtotal_amount' => 4200,
                'discount_amount' => 0,
                'total_amount' => 4200,
                'amount_paid' => 0,
                'balance_due' => 4200,
                'recorded_by' => $staff->id,
                'recorded_at' => now()->subDay(),
                'payment_due_date' => now()->addDays(14),
            ],
        );
        $this->seedBillingRecordItems($inProgressBilling, $inProgress);

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

    private function seedAccessoryOrderRequest(): void
    {
        $patient = $this->flagshipPatient();
        $variants = ProductVariant::query()
            ->with('product')
            ->whereIn('sku', [
                'ACC-SYSTANE-COMPLETE-PF-10ML',
                'ACC-LACRYL-HYDRATE-10ML',
            ])
            ->get()
            ->keyBy('sku');

        $request = AccessoryOrderRequest::query()->updateOrCreate(
            ['request_number' => 'ORQ-2026-000001'],
            [
                'user_id' => $patient->user_id,
                'patient_id' => $patient->id,
                'status' => AccessoryOrderRequestStatus::Pending,
                'subtotal_amount' => 1300,
                'requested_discount_type' => 'none',
                'job_order_id' => null,
                'resolved_by' => null,
                'resolved_at' => null,
                'rejection_reason' => null,
                'cancelled_at' => null,
            ],
        );

        $items = [
            ['sku' => 'ACC-SYSTANE-COMPLETE-PF-10ML', 'quantity' => 1],
            ['sku' => 'ACC-LACRYL-HYDRATE-10ML', 'quantity' => 1],
        ];
        $variantIds = [];

        foreach ($items as $item) {
            $variant = $variants->get($item['sku']);

            if ($variant === null) {
                throw new RuntimeException("Missing seeded accessory variant [{$item['sku']}].");
            }

            $variantIds[] = $variant->id;
            $unitPrice = (float) $variant->price;

            AccessoryOrderRequestItem::query()->updateOrCreate(
                [
                    'accessory_order_request_id' => $request->id,
                    'product_variant_id' => $variant->id,
                ],
                [
                    'description' => $variant->product->name.' — '.$variant->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'amount' => $unitPrice * $item['quantity'],
                    'item_kind' => CommercialItemKind::Accessory,
                    'item_snapshot' => [
                        'product_variant_id' => $variant->id,
                        'sku' => $variant->sku,
                        'variant_name' => $variant->name,
                        'product_name' => $variant->product->name,
                        'price' => $variant->price,
                        'attributes' => $variant->attributes,
                    ],
                ],
            );
        }

        AccessoryOrderRequestItem::query()
            ->where('accessory_order_request_id', $request->id)
            ->whereNotIn('product_variant_id', $variantIds)
            ->delete();
    }

    private function seedVisitFeedback(): void
    {
        $patient = $this->flagshipPatient();
        $appointment = Appointment::query()
            ->where('appointment_number', 'APT-2026-000002')
            ->firstOrFail();
        $encounter = Encounter::query()
            ->where('appointment_id', $appointment->id)
            ->firstOrFail();

        VisitRating::query()->updateOrCreate(
            ['appointment_id' => $appointment->id],
            [
                'patient_id' => $patient->id,
                'encounter_id' => $encounter->id,
                'optometrist_id' => $encounter->optometrist_id,
                'rating' => 5,
                'comment' => 'Friendly and thorough consultation. The prescription explanation was clear.',
                'service_ids' => null,
                'is_hidden' => false,
                'moderation_reason' => null,
                'moderated_by' => null,
                'moderated_at' => null,
            ],
        );
    }

    private function seedProductRatings(): void
    {
        $this->upsertProductRating(
            patient: $this->flagshipPatient(),
            sku: 'FRM-SOFIA-2860-GRY',
            rating: 5,
            comment: 'Comfortable fit and a clear, lightweight frame.',
        );

        $this->upsertProductRating(
            patient: $this->walkInPatient(),
            sku: 'FRM-ANTHOS-MB1399A-C4',
            rating: 4,
            comment: 'The frame feels sturdy and fits well for everyday wear.',
        );

        $this->upsertProductRating(
            patient: $this->flagshipPatient(),
            sku: 'ACC-SYSTANE-COMPLETE-PF-10ML',
            rating: 5,
            comment: 'Convenient to use and soothing after long screen sessions.',
        );
    }

    private function upsertProductRating(
        Patient $patient,
        string $sku,
        int $rating,
        string $comment,
    ): void {
        $variant = ProductVariant::query()->where('sku', $sku)->firstOrFail();

        FrameRating::query()->updateOrCreate(
            [
                'patient_id' => $patient->id,
                'product_variant_id' => $variant->id,
            ],
            [
                'dispensing_event_id' => null,
                'rating' => $rating,
                'comment' => $comment,
                'is_hidden' => false,
                'moderation_reason' => null,
                'moderated_by' => null,
                'moderated_at' => null,
            ],
        );
    }
}
