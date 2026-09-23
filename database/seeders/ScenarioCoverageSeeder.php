<?php

namespace Database\Seeders;

use App\Actions\OpticalOrders\BuildOpticalItemSnapshot;
use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\LinkPatientAccount;
use App\Actions\PatientAccounts\PatientLinkIdentitySnapshot;
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
use App\Models\DispensingEvent;
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
use Illuminate\Support\Facades\Hash;
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

        $duplicateAppointmentScheduledAt = now()->subDays(10)->setTime(13, 0);
        Appointment::query()->updateOrCreate(
            ['appointment_number' => 'APT-2026-000008'],
            [
                'patient_id' => $walkIn->id,
                'created_by' => $staff->id,
                'appointment_type_id' => $followUpType->id,
                'duration_minutes' => $followUpType->duration_minutes,
                'referring_source' => null,
                'source' => 'manual',
                'appointment_status_id' => $cancelled->id,
                'scheduled_at' => $duplicateAppointmentScheduledAt,
                'checked_in_at' => null,
                'checked_in_by' => null,
                'fulfilled_at' => null,
                'cancelled_by' => 'clinic',
                'cancelled_by_user_id' => $staff->id,
                'cancellation_reason_category' => 'duplicate',
                'cancellation_reason_details' => 'The duplicate consultation entry was merged with the correct consultation.',
                'cancelled_at' => $duplicateAppointmentScheduledAt->copy()->addMinutes(15),
                'no_show_by' => null,
                'no_show_at' => null,
                'reason_for_visit' => 'Follow-up consultation after a recent prescription change.',
                'contact_notes' => 'Duplicate consultation entry was identified during record review.',
                'staff_notes' => 'Use the retained consultation record for the patient history.',
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
        $outsideClinicHoursTime = now()->addDays(2)->setTime(18, 0);
        $outsideClinicHoursExpiry = $outsideClinicHoursTime->copy()->addDay();
        $expiredRequestTime = now()->subDay()->setTime(10, 0);
        $expiredRequestExpiry = $expiredRequestTime->copy()->addHour();

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
                'scheduled_at' => $outsideClinicHoursTime,
                'expires_at' => $outsideClinicHoursExpiry,
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
                'scheduled_at' => $expiredRequestTime,
                'expires_at' => $expiredRequestExpiry,
                'encrypted_reason_for_visit' => 'Follow-up after a recent prescription change.',
                'status' => AppointmentRequestStatus::Expired,
                'resolved_by_user_id' => null,
                'resolved_at' => null,
            ],
        );

        $additionalPatients = $this->seedAdditionalAppointmentRequestPatients();
        $this->seedAdditionalPendingAppointmentRequests(
            appointmentTypeId: $checkUpTypeId,
            patients: $additionalPatients,
        );
    }

    /**
     * Create dedicated linked accounts for the additional pending requests so
     * each account still follows the one-active-booking rule.
     *
     * @return array<int, Patient>
     */
    private function seedAdditionalAppointmentRequestPatients(): array
    {
        $patientRoleId = Role::query()->where('name', Role::Patient)->value('id');

        if ($patientRoleId === null) {
            throw new RuntimeException('The patient role must be seeded before appointment request scenarios.');
        }

        $lookupHash = app(CreateContactLookupHash::class);
        $profiles = [
            [
                'email' => 'appointment.request.one@eyecare.test',
                'first_name' => 'Amelia',
                'last_name' => 'Alvarez',
                'phone' => '09170000007',
                'patient_number' => 'PAT-2026-000004',
                'date_of_birth' => '1994-03-11',
                'gender' => 'female',
                'occupation' => 'Teacher',
                'address' => 'Makati City, Metro Manila',
            ],
            [
                'email' => 'appointment.request.two@eyecare.test',
                'first_name' => 'Noel',
                'last_name' => 'Villanueva',
                'phone' => '09170000008',
                'patient_number' => 'PAT-2026-000005',
                'date_of_birth' => '1989-07-24',
                'gender' => 'male',
                'occupation' => 'Architect',
                'address' => 'Pasig City, Metro Manila',
            ],
            [
                'email' => 'appointment.request.three@eyecare.test',
                'first_name' => 'Sofia',
                'last_name' => 'Dizon',
                'phone' => '09170000009',
                'patient_number' => 'PAT-2026-000006',
                'date_of_birth' => '1998-11-02',
                'gender' => 'female',
                'occupation' => 'Accountant',
                'address' => 'Quezon City, Metro Manila',
            ],
        ];

        $patients = [];

        foreach ($profiles as $profile) {
            $account = User::query()->firstOrCreate(
                ['email' => $profile['email']],
                [
                    'first_name' => $profile['first_name'],
                    'middle_name' => null,
                    'last_name' => $profile['last_name'],
                    'phone' => $profile['phone'],
                    'password' => Hash::make('password'),
                    'role_id' => $patientRoleId,
                    'is_optometrist' => false,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );
            $account->update([
                'first_name' => $profile['first_name'],
                'middle_name' => null,
                'last_name' => $profile['last_name'],
                'phone' => $profile['phone'],
                'role_id' => $patientRoleId,
                'is_optometrist' => false,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $account->roles()->syncWithoutDetaching([$patientRoleId]);

            $patient = Patient::query()->updateOrCreate(
                ['patient_number' => $profile['patient_number']],
                [
                    'first_name' => $profile['first_name'],
                    'middle_name' => null,
                    'last_name' => $profile['last_name'],
                    'date_of_birth' => $profile['date_of_birth'],
                    'gender' => $profile['gender'],
                    'occupation' => $profile['occupation'],
                    'address' => $profile['address'],
                    'contact_email' => $profile['email'],
                    'contact_email_lookup_hash' => $lookupHash->forEmail($profile['email']),
                    'phone' => $profile['phone'],
                    'phone_lookup_hash' => $lookupHash->forPhone($profile['phone']),
                ],
            );
            $patient->forceFill(['user_id' => $account->id])->saveQuietly();

            $patients[] = $patient->fresh();
        }

        return $patients;
    }

    /**
     * Seed pending requests with two shared primary preferences and ordered
     * alternatives for staff conflict-resolution scenarios.
     *
     * @param  array<int, Patient>  $patients
     */
    private function seedAdditionalPendingAppointmentRequests(int $appointmentTypeId, array $patients): void
    {
        $conflictingTime = now()->addDays(7)->setTime(10, 0);
        $firstAlternative = $conflictingTime->copy()->addDay()->setTime(10, 0);
        $secondAlternative = $conflictingTime->copy()->addDays(2)->setTime(10, 0);
        $thirdPrimary = $conflictingTime->copy()->addDays(3)->setTime(14, 0);
        $thirdFirstAlternative = $conflictingTime->copy()->addDays(4)->setTime(14, 0);
        $thirdSecondAlternative = $conflictingTime->copy()->addDays(5)->setTime(14, 0);

        $requests = [
            [
                'request_number' => 'APR-2026-000006',
                'patient' => $patients[0],
                'scheduled_at' => $conflictingTime,
                'alternative_scheduled_times' => [
                    $firstAlternative->toISOString(),
                    $secondAlternative->toISOString(),
                ],
                'expires_at' => $secondAlternative,
                'reason' => 'New prescription for headaches after prolonged screen use.',
            ],
            [
                'request_number' => 'APR-2026-000007',
                'patient' => $patients[1],
                'scheduled_at' => $conflictingTime,
                'alternative_scheduled_times' => [
                    $firstAlternative->copy()->setTime(14, 0)->toISOString(),
                    $secondAlternative->copy()->setTime(14, 0)->toISOString(),
                ],
                'expires_at' => $secondAlternative->copy()->setTime(14, 0),
                'reason' => 'Routine eye examination and updated distance prescription.',
            ],
            [
                'request_number' => 'APR-2026-000008',
                'patient' => $patients[2],
                'scheduled_at' => $thirdPrimary,
                'alternative_scheduled_times' => [
                    $thirdFirstAlternative->toISOString(),
                    $thirdSecondAlternative->toISOString(),
                ],
                'expires_at' => $thirdSecondAlternative,
                'reason' => 'Eye strain assessment before starting a new contact lens prescription.',
            ],
        ];

        foreach ($requests as $request) {
            /** @var Patient $patient */
            $patient = $request['patient'];

            AppointmentRequest::query()->updateOrCreate(
                ['request_number' => $request['request_number']],
                [
                    'request_type' => 'new',
                    'user_id' => $patient->user_id,
                    'patient_id' => $patient->id,
                    'appointment_type_id' => $appointmentTypeId,
                    'appointment_id' => null,
                    'original_scheduled_at' => null,
                    'selected_scheduled_at' => null,
                    'scheduled_at' => $request['scheduled_at'],
                    'alternative_scheduled_times' => $request['alternative_scheduled_times'],
                    'provisional_duration_minutes' => 30,
                    'encrypted_reason_for_visit' => $request['reason'],
                    'encrypted_referring_source' => null,
                    'encrypted_identity_snapshot' => null,
                    'status' => AppointmentRequestStatus::Pending,
                    'expires_at' => $request['expires_at'],
                    'resolved_by_user_id' => null,
                    'resolved_at' => null,
                    'rejection_reason' => null,
                    'encrypted_cancellation_reason' => null,
                ],
            );
        }
    }

    private function seedPatientLinkRequests(): void
    {
        if (PatientLinkRequest::query()->count() > 0) {
            return;
        }

        $staff = $this->staff();
        $patientRoleId = (int) Role::query()->where('name', Role::Patient)->value('id');

        $pendingUser = $this->createPatientLinkAccount(
            email: 'link.pending@eyecare.test',
            firstName: 'Daniel',
            lastName: 'Reyes',
            phone: '09170000011',
            roleId: $patientRoleId,
        );
        PatientLinkRequest::query()->updateOrCreate(
            ['request_number' => 'PLR-2026-000001'],
            [
                'user_id' => $pendingUser->id,
                'encrypted_identity_snapshot' => app(PatientLinkIdentitySnapshot::class)->fromAccount($pendingUser),
                'status' => 'pending',
                'reviewed_patient_id' => null,
                'reviewer_id' => null,
                'decision_note' => null,
                'reviewed_at' => null,
            ],
        );

        $rejectedUser = $this->createPatientLinkAccount(
            email: 'link.rejected@eyecare.test',
            firstName: 'Maya',
            lastName: 'Lopez',
            phone: '09170000012',
            roleId: $patientRoleId,
        );
        PatientLinkRequest::query()->updateOrCreate(
            ['request_number' => 'PLR-2026-000002'],
            [
                'user_id' => $rejectedUser->id,
                'encrypted_identity_snapshot' => app(PatientLinkIdentitySnapshot::class)->fromAccount($rejectedUser),
                'status' => 'rejected',
                'reviewed_patient_id' => null,
                'reviewer_id' => $staff->id,
                'decision_note' => 'No matching patient found',
                'reviewed_at' => now(),
            ],
        );

        // A dedicated walk-in match — not the canonical Pedro Cruz, who other
        // tests expect to remain unlinked.
        $approvedUser = $this->createPatientLinkAccount(
            email: 'link.approved@eyecare.test',
            firstName: 'Rosa',
            lastName: 'Santos',
            phone: '09170000005',
            roleId: $patientRoleId,
            dateOfBirth: '1988-04-12',
        );
        $approvedContactHash = app(CreateContactLookupHash::class)->forPhone('09170000005');
        PatientAccountContact::query()->updateOrCreate(
            [
                'user_id' => $approvedUser->id,
                'type' => 'phone',
                'lookup_hash' => $approvedContactHash,
            ],
            [
                'encrypted_value' => '09170000005',
                'verified_at' => now(),
                'is_primary' => true,
            ],
        );
        $approvedMatch = Patient::query()->firstOrNew(['patient_number' => 'PAT-2026-000003']);
        $approvedMatch->forceFill([
            'patient_number' => 'PAT-2026-000003',
            'user_id' => null,
            'first_name' => 'Rosa',
            'middle_name' => null,
            'last_name' => 'Santos',
            'date_of_birth' => '1988-04-12',
            'occupation' => 'Teacher',
            'address' => 'Pasig City, Metro Manila',
            'gender' => 'female',
            'contact_email' => null,
            'phone' => '09170000005',
            'phone_lookup_hash' => $approvedContactHash,
        ])->saveQuietly();
        PatientLinkRequest::query()->updateOrCreate(
            ['request_number' => 'PLR-2026-000003'],
            [
                'user_id' => $approvedUser->id,
                'encrypted_identity_snapshot' => app(PatientLinkIdentitySnapshot::class)->fromAccount($approvedUser),
                'status' => 'approved',
                'reviewed_patient_id' => $approvedMatch->id,
                'reviewer_id' => $staff->id,
                'decision_note' => null,
                'reviewed_at' => now(),
            ],
        );
        app(LinkPatientAccount::class)->handle(
            account: $approvedUser,
            patient: $approvedMatch,
            source: 'scenario_coverage_seeder',
            sourceId: $approvedMatch->id,
            actorId: $staff->id,
        );
    }

    private function createPatientLinkAccount(
        string $email,
        string $firstName,
        string $lastName,
        string $phone,
        int $roleId,
        ?string $dateOfBirth = null,
    ): User {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'first_name' => $firstName,
                'middle_name' => null,
                'last_name' => $lastName,
                'phone' => $phone,
                'date_of_birth' => $dateOfBirth,
                'password' => Hash::make('password'),
                'role_id' => $roleId,
                'is_optometrist' => false,
                'is_active' => true,
                'must_change_password' => false,
            ],
        );
        $user->forceFill(['email_verified_at' => now()])->saveQuietly();
        $user->roles()->syncWithoutDetaching([$roleId]);

        return $user;
    }

    private function seedEncounterStatuses(): void
    {
        $walkIn = $this->walkInPatient();
        $staff = $this->staff();
        $optometrist = $this->optometrist();
        $appointmentType = AppointmentType::query()->where('name', 'Routine Check-up')->firstOrFail();
        $checkedIn = AppointmentStatus::query()->where('name', 'checked_in')->firstOrFail();
        $cancelledAppointment = Appointment::query()
            ->where('appointment_number', 'APT-2026-000004')
            ->firstOrFail();
        $duplicateCancelledAppointment = Appointment::query()
            ->where('appointment_number', 'APT-2026-000008')
            ->firstOrFail();
        $plannedReason = 'Eye strain and intermittent blurred vision after long screen use.';
        $scheduledAt = now()->subMinutes(45)->setSeconds(0);
        $inProgressScheduledAt = now()->subMinutes(30)->setSeconds(0);

        $inProgressAppointment = Appointment::query()->updateOrCreate(
            ['appointment_number' => 'APT-2026-000009'],
            [
                'patient_id' => $walkIn->id,
                'appointment_type_id' => $appointmentType->id,
                'duration_minutes' => $appointmentType->duration_minutes,
                'created_by' => $staff->id,
                'optometrist_id' => $optometrist->id,
                'source' => 'walk_in',
                'referring_source' => null,
                'appointment_status_id' => $checkedIn->id,
                'scheduled_at' => $inProgressScheduledAt,
                'checked_in_at' => $inProgressScheduledAt->copy()->addMinutes(5),
                'checked_in_by' => $staff->id,
                'fulfilled_at' => null,
                'cancelled_by' => null,
                'cancelled_by_user_id' => null,
                'cancellation_reason_category' => null,
                'cancellation_reason_details' => null,
                'cancelled_at' => null,
                'no_show_by' => null,
                'no_show_at' => null,
                'reason_for_visit' => 'Walk-in consultation for intermittent blurred vision after extended screen use.',
                'contact_notes' => 'Patient was checked in for the active walk-in consultation.',
                'staff_notes' => 'Complete the clinical assessment and record findings before completion.',
            ],
        );

        $plannedAppointment = Appointment::query()->updateOrCreate(
            ['appointment_number' => 'APT-2026-000007'],
            [
                'patient_id' => $walkIn->id,
                'appointment_type_id' => $appointmentType->id,
                'duration_minutes' => $appointmentType->duration_minutes,
                'created_by' => $staff->id,
                'optometrist_id' => $optometrist->id,
                'source' => 'walk_in',
                'referring_source' => null,
                'appointment_status_id' => $checkedIn->id,
                'scheduled_at' => $scheduledAt,
                'checked_in_at' => $scheduledAt->copy()->addMinutes(5),
                'checked_in_by' => $staff->id,
                'fulfilled_at' => null,
                'cancelled_by' => null,
                'cancelled_by_user_id' => null,
                'cancellation_reason_category' => null,
                'cancellation_reason_details' => null,
                'cancelled_at' => null,
                'no_show_by' => null,
                'no_show_at' => null,
                'reason_for_visit' => $plannedReason,
                'contact_notes' => 'Walk-in patient reports intermittent blurred vision after extended screen use.',
                'staff_notes' => 'Confirm current prescription and review visual ergonomics during the consultation.',
            ],
        );

        Encounter::query()->updateOrCreate(
            ['encounter_number' => 'CON-2026-000002'],
            [
                'patient_id' => $walkIn->id,
                'appointment_id' => $plannedAppointment->id,
                'optometrist_id' => $optometrist->id,
                'status' => EncounterStatus::Planned,
                'started_at' => null,
                'completed_at' => null,
                'chief_complaint' => $plannedReason,
                'findings' => null,
                'remarks' => null,
                'past_ocular_history' => null,
                'past_surgical_history' => null,
                'past_medical_history' => null,
                'allergies' => null,
                'medications' => null,
                'last_wizard_step' => null,
                'draft_saved_at' => null,
                'prescription_draft' => null,
                'completed_by' => null,
                'cancelled_by' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ],
        );

        Encounter::query()->updateOrCreate(
            ['encounter_number' => 'CON-2026-000003'],
            [
                'patient_id' => $walkIn->id,
                'appointment_id' => $inProgressAppointment->id,
                'status' => EncounterStatus::InProgress,
                'optometrist_id' => $optometrist->id,
                'started_at' => now()->subMinutes(20),
                'completed_at' => null,
            ],
        );

        Encounter::query()->updateOrCreate(
            ['encounter_number' => 'CON-2026-000004'],
            [
                'patient_id' => $walkIn->id,
                'appointment_id' => $cancelledAppointment->id,
                'status' => EncounterStatus::Cancelled,
                'optometrist_id' => $optometrist->id,
                'started_at' => now()->subDays(3),
                'completed_at' => null,
            ],
        );

        Encounter::query()->updateOrCreate(
            ['encounter_number' => 'CON-2026-000005'],
            [
                'patient_id' => $walkIn->id,
                'appointment_id' => $duplicateCancelledAppointment->id,
                'status' => EncounterStatus::Cancelled,
                'optometrist_id' => $optometrist->id,
                'started_at' => now()->subDays(10),
                'completed_at' => null,
                'cancellation_reason' => 'Duplicate entry — merged with the correct encounter.',
            ],
        );

        // A second completed encounter (this time for the walk-in patient,
        // not just the linked-account flagship), with its own prescription,
        // so the prescription-aware retail flow isn't only demonstrated
        // once. Feeds seedJobOrderStatuses() below.
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

        $originalPrescription = Prescription::query()->updateOrCreate(
            ['prescription_number' => 'RX-2026-000002'],
            [
                'patient_id' => $walkIn->id,
                'encounter_id' => $secondCompletedEncounter->id,
                'appointment_id' => $completedAppointment->id,
                'main_od_value' => '1.00',
                'main_od_sphere' => '-1.25',
                'main_od_cylinder' => '-0.25',
                'main_os_value' => '1.00',
                'main_os_sphere' => '-1.50',
                'main_os_cylinder' => '-0.50',
                'remarks' => 'Mild myopia with slight astigmatism. Single-vision distance lenses recommended.',
                'prescribed_at' => $completedAt,
                'expires_at' => $completedAt->copy()->addMonthsNoOverflow(6),
                'created_by' => $optometrist->id,
            ],
        );

        Prescription::query()->updateOrCreate(
            ['prescription_number' => 'RX-2026-000003'],
            [
                'patient_id' => $walkIn->id,
                'encounter_id' => $secondCompletedEncounter->id,
                'appointment_id' => $completedAppointment->id,
                'previous_prescription_id' => $originalPrescription->id,
                'main_od_value' => '1.00',
                'main_od_sphere' => '-1.50',
                'main_od_cylinder' => '-0.25',
                'main_os_value' => '1.00',
                'main_os_sphere' => '-1.75',
                'main_os_cylinder' => '-0.50',
                'add_od_value' => null,
                'add_od_sphere' => null,
                'add_od_cylinder' => null,
                'add_os_value' => null,
                'add_os_sphere' => null,
                'add_os_cylinder' => null,
                'remarks' => 'Updated single-vision distance prescription after verifying the recorded refraction values.',
                'amendment_reason' => 'Corrected refraction values after verification of the original measurements.',
                'prescribed_at' => $completedAt->copy()->addDay(),
                'expires_at' => $completedAt->copy()->addDay()->addMonthsNoOverflow(6),
                'created_by' => $optometrist->id,
                'cancelled_by' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
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
            [
                'job_order_id' => $dispensed->id,
                'product_variant_id' => $classicFrameVariant->id,
            ],
            [
                'description' => 'Classic Frame — Matte Black',
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

        $walkInPatient = $this->walkInPatient();
        $walkInAppointment = Appointment::query()
            ->where('appointment_number', 'APT-2026-000006')
            ->firstOrFail();
        $walkInEncounter = Encounter::query()
            ->where('appointment_id', $walkInAppointment->id)
            ->firstOrFail();

        VisitRating::query()->updateOrCreate(
            ['appointment_id' => $walkInAppointment->id],
            [
                'patient_id' => $walkInPatient->id,
                'encounter_id' => $walkInEncounter->id,
                'optometrist_id' => $walkInEncounter->optometrist_id,
                'rating' => 4,
                'comment' => 'Clear advice and a patient explanation. The updated prescription feels right for driving.',
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
        $flagshipPatient = $this->flagshipPatient();
        $walkInPatient = $this->walkInPatient();
        $this->removeUnlinkedSeedProductRatings($flagshipPatient, $walkInPatient);

        $walkInOrderReviews = [
            [
                'sku' => 'FRM-SOFIA-2860-GRY',
                'rating' => 4,
                'comment' => 'The frame feels light and sits comfortably through a full workday.',
            ],
            [
                'sku' => 'ACC-SYSTANE-COMPLETE-PF-10ML',
                'rating' => 4,
                'comment' => 'Convenient drops to keep by my desk after a long day.',
            ],
            [
                'sku' => 'ACC-LACRYL-HYDRATE-10ML',
                'rating' => 4,
                'comment' => 'Simple to use and fits easily in my bag.',
            ],
            [
                'sku' => 'ACC-NEWLOOK-MPS-90ML',
                'rating' => 5,
                'comment' => 'The bottle is easy to handle and the solution feels comfortable.',
            ],
        ];
        $walkInDispensingEvent = $this->seedDispensedProductOrder(
            jobOrderNumber: 'ORD-2026-000004',
            billingRecordNumber: 'BR-2026-000003',
            patient: $walkInPatient,
            reviews: $walkInOrderReviews,
        );

        $flagshipOrderReviews = [
            [
                'sku' => 'FRM-SPORT-BLKRED-001',
                'rating' => 5,
                'comment' => 'Secure fit and a sporty shape that works well outdoors.',
            ],
            [
                'sku' => 'ACC-SYSTANE-COMPLETE-PF-10ML',
                'rating' => 5,
                'comment' => 'Convenient to use and soothing after long screen sessions.',
            ],
            [
                'sku' => 'ACC-LACRYL-HYDRATE-10ML',
                'rating' => 5,
                'comment' => 'Easy-to-carry bottle, and the drops feel gentle.',
            ],
            [
                'sku' => 'ACC-NEWLOOK-MPS-90ML',
                'rating' => 5,
                'comment' => 'The bottle is easy to handle and the solution feels comfortable.',
            ],
        ];
        $flagshipDispensingEvent = $this->seedDispensedProductOrder(
            jobOrderNumber: 'ORD-2026-000006',
            billingRecordNumber: 'BR-2026-000005',
            patient: $flagshipPatient,
            reviews: $flagshipOrderReviews,
        );

        foreach ($walkInOrderReviews as $review) {
            $this->upsertProductRating(
                patient: $walkInPatient,
                dispensingEvent: $walkInDispensingEvent,
                sku: $review['sku'],
                rating: $review['rating'],
                comment: $review['comment'],
            );
        }

        foreach ($flagshipOrderReviews as $review) {
            $this->upsertProductRating(
                patient: $flagshipPatient,
                dispensingEvent: $flagshipDispensingEvent,
                sku: $review['sku'],
                rating: $review['rating'],
                comment: $review['comment'],
            );
        }
    }

    /**
     * Remove earlier synthetic reviews that were seeded without a completed order.
     */
    private function removeUnlinkedSeedProductRatings(Patient $flagshipPatient, Patient $walkInPatient): void
    {
        FrameRating::withTrashed()
            ->whereIn('patient_id', [$flagshipPatient->id, $walkInPatient->id])
            ->whereNull('dispensing_event_id')
            ->whereIn('comment', [
                'Comfortable fit and a clear, lightweight frame.',
                'The frame feels sturdy and fits well for everyday wear.',
                'Convenient to use and soothing after long screen sessions.',
                'The frame feels light and sits comfortably through a full workday.',
                'Secure fit and a sporty shape that works well outdoors.',
                'Comfortable in bright daylight and light enough for long drives.',
                'Convenient drops to keep by my desk after a long day.',
                'Easy-to-carry bottle, and the drops feel gentle.',
                'Simple to use and fits easily in my bag.',
                'The bottle is easy to handle and the solution feels comfortable.',
            ])
            ->forceDelete();
    }

    /**
     * @param  list<array{sku: string, rating: int, comment: string}>  $reviews
     */
    private function seedDispensedProductOrder(
        string $jobOrderNumber,
        string $billingRecordNumber,
        Patient $patient,
        array $reviews,
    ): DispensingEvent {
        $staff = $this->staff();
        $items = collect($reviews)->map(function (array $review): array {
            $variant = ProductVariant::query()
                ->with('product')
                ->where('sku', $review['sku'])
                ->firstOrFail();
            $snapshot = app(BuildOpticalItemSnapshot::class)->handle(productVariantId: $variant->id);
            $unitPriceInCents = (int) round((float) $variant->price * 100);
            $unitPrice = number_format($unitPriceInCents / 100, 2, '.', '');

            return [
                ...$review,
                'variant' => $variant,
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'amount' => $unitPrice,
                'item_kind' => $snapshot['item_kind'],
                'item_snapshot' => $snapshot['item_snapshot'],
                'amount_in_cents' => $unitPriceInCents,
            ];
        });
        $totalAmount = number_format($items->sum('amount_in_cents') / 100, 2, '.', '');
        $startedAt = now()->subDays(3);
        $dispensedAt = now()->subDays(2);

        $jobOrder = JobOrder::query()->updateOrCreate(
            ['job_order_number' => $jobOrderNumber],
            [
                'patient_id' => $patient->id,
                'encounter_id' => null,
                'prescription_id' => null,
                'status' => JobOrderStatus::Dispensed,
                'fulfillment_mode' => 'immediate',
                'uses_external_supplier' => false,
                'total_amount' => $totalAmount,
                'notes' => 'Settled demo purchase with sample product feedback.',
                'started_at' => $startedAt,
                'dispensed_at' => $dispensedAt,
                'cancelled_at' => null,
            ],
        );

        $productVariantIds = [];

        foreach ($items as $item) {
            $variant = $item['variant'];
            $productVariantIds[] = $variant->id;

            $jobOrder->items()->updateOrCreate(
                ['product_variant_id' => $variant->id],
                [
                    'description' => $variant->product->name.' — '.$variant->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'amount' => $item['amount'],
                    'product_variant_id' => $variant->id,
                    'lens_category_id' => null,
                    'lens_option_id' => null,
                    'item_kind' => $item['item_kind'],
                    'item_snapshot' => $item['item_snapshot'],
                ],
            );
        }

        $jobOrder->items()
            ->where(function ($query) use ($productVariantIds): void {
                $query->whereNull('product_variant_id')
                    ->orWhereNotIn('product_variant_id', $productVariantIds);
            })
            ->delete();

        $billingRecord = BillingRecord::query()->updateOrCreate(
            ['billing_record_number' => $billingRecordNumber],
            [
                'patient_id' => $patient->id,
                'job_order_id' => $jobOrder->id,
                'encounter_id' => null,
                'status' => BillingRecordStatus::Paid,
                'subtotal_amount' => $totalAmount,
                'discount_amount' => 0,
                'total_amount' => $totalAmount,
                'amount_paid' => $totalAmount,
                'balance_due' => 0,
                'recorded_by' => $staff->id,
                'recorded_at' => $dispensedAt,
                'cancelled_by' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ],
        );

        $this->seedBillingRecordItems($billingRecord, $jobOrder);

        $jobOrderItemIds = $jobOrder->items()->pluck('id')->all();
        $billingRecord->items()
            ->where(function ($query) use ($jobOrderItemIds): void {
                $query->whereNull('job_order_item_id')
                    ->orWhereNotIn('job_order_item_id', $jobOrderItemIds);
            })
            ->delete();

        $payment = $billingRecord->payments()->where('status', 'posted')->orderBy('id')->first();
        $paymentAttributes = [
            'billing_record_id' => $billingRecord->id,
            'amount' => $totalAmount,
            'payment_method' => 'cash',
            'reference_number' => 'DEMO-'.$jobOrderNumber,
            'status' => 'posted',
            'recorded_by' => $staff->id,
            'recorded_at' => $dispensedAt,
            'notes' => 'Full payment for seeded product-review order.',
        ];

        if ($payment === null) {
            BillingPayment::query()->create($paymentAttributes);
        } else {
            $payment->update($paymentAttributes);
            $billingRecord->payments()
                ->where('status', 'posted')
                ->where('id', '!=', $payment->id)
                ->delete();
        }

        return DispensingEvent::query()->updateOrCreate(
            ['job_order_id' => $jobOrder->id],
            [
                'billing_record_id' => $billingRecord->id,
                'dispensed_by' => $staff->id,
                'recipient_name' => $patient->full_name,
                'notes' => 'Seeded settled product purchase.',
                'dispensed_at' => $dispensedAt,
            ],
        );
    }

    private function upsertProductRating(
        Patient $patient,
        DispensingEvent $dispensingEvent,
        string $sku,
        int $rating,
        string $comment,
    ): void {
        $variant = ProductVariant::query()->where('sku', $sku)->firstOrFail();

        // These synthetic demo comments opt in so product-review screens have sample content.
        FrameRating::query()->updateOrCreate(
            [
                'patient_id' => $patient->id,
                'product_variant_id' => $variant->id,
            ],
            [
                'dispensing_event_id' => $dispensingEvent->id,
                'rating' => $rating,
                'comment' => $comment,
                'public_display_consent_at' => now(),
                'is_hidden' => false,
                'moderation_reason' => null,
                'moderated_by' => null,
                'moderated_at' => null,
            ],
        );
    }
}
