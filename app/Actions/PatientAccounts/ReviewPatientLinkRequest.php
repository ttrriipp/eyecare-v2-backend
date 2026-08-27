<?php

namespace App\Actions\PatientAccounts;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Conversations\AssociateAccountConversation;
use App\Enums\AuditEvent;
use App\Models\AppointmentRequest;
use App\Models\Patient;
use App\Models\PatientLinkRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewPatientLinkRequest
{
    public function __construct(
        private readonly CreateAuditLog $createAuditLog,
        private readonly ExpirePendingPatientLinkRequest $expirePendingLinkRequest,
        private readonly PatientLinkIdentitySnapshot $identitySnapshot,
        private readonly AssociateAccountConversation $associateAccountConversation,
    ) {}

    public function approve(
        PatientLinkRequest $linkRequest,
        Patient $patient,
        User $reviewer,
        ?string $note = null,
    ): PatientLinkRequest {
        $result = DB::transaction(function () use ($linkRequest, $patient, $reviewer, $note): array {
            // Keep account, request, and patient locks in this order so a
            // profile change cannot race a staff approval decision.
            $account = User::query()->lockForUpdate()->findOrFail($linkRequest->user_id);
            $lockedRequest = PatientLinkRequest::query()
                ->whereKey($linkRequest->id)
                ->lockForUpdate()
                ->firstOrFail();
            $patient = Patient::query()->lockForUpdate()->findOrFail($patient->id);

            if (! $lockedRequest->isPending()) {
                throw ValidationException::withMessages([
                    'request' => ['Only pending link requests can be approved.'],
                ]);
            }

            if ($patient->user_id !== null) {
                throw ValidationException::withMessages([
                    'patient' => ['This patient is already linked to another account.'],
                ]);
            }

            if ($account->patient()->exists()) {
                throw ValidationException::withMessages([
                    'account' => ['This account is already linked to a patient.'],
                ]);
            }

            if (! $this->identitySnapshot->matchesAccount($lockedRequest->encrypted_identity_snapshot, $account)) {
                $this->expirePendingLinkRequest->handle(
                    account: $account,
                    reason: 'stale_identity_snapshot',
                    linkRequest: $lockedRequest,
                );

                return [
                    'stale' => true,
                    'request' => null,
                ];
            }

            $patient->update(['user_id' => $account->id]);

            $lockedRequest->update([
                'status' => 'approved',
                'reviewed_patient_id' => $patient->id,
                'reviewer_id' => $reviewer->id,
                'decision_note' => $note,
                'reviewed_at' => now(),
            ]);

            $linkedAppointmentRequestCount = $this->linkUnlinkedAppointmentRequests($account, $patient);

            $this->associateAccountConversation->handle($account, $patient);

            $this->createAuditLog->handle(
                subject: $lockedRequest,
                action: AuditEvent::PatientLinkApproved,
                metadata: [
                    'patient_id' => $patient->id,
                    'account_id' => $account->id,
                    'linked_appointment_request_count' => $linkedAppointmentRequestCount,
                    'note_provided' => filled($note),
                ],
                actorId: $reviewer->id,
            );

            $this->createAuditLog->handle(
                subject: $patient,
                action: AuditEvent::PatientAccountLinked,
                metadata: [
                    'account_id' => $account->id,
                    'link_request_id' => $lockedRequest->id,
                ],
                actorId: $reviewer->id,
            );

            return [
                'stale' => false,
                'request' => $lockedRequest->fresh(['user', 'reviewedPatient', 'reviewer']),
            ];
        });

        if ($result['stale']) {
            throw ValidationException::withMessages([
                'request' => ['This link request is no longer current. Submit a new request before approving it.'],
            ]);
        }

        if (! $result['request'] instanceof PatientLinkRequest) {
            throw new \LogicException('Approved link request was not returned.');
        }

        return $result['request'];
    }

    /**
     * Attach appointment requests submitted before the account was linked.
     *
     * The encrypted identity snapshot remains unchanged as an immutable
     * record of what the account submitted; patient_id is the authoritative
     * link after staff approval.
     */
    private function linkUnlinkedAppointmentRequests(User $account, Patient $patient): int
    {
        $requests = AppointmentRequest::query()
            ->where('user_id', $account->id)
            ->whereNull('patient_id')
            ->lockForUpdate()
            ->get();

        $requests->each(function (AppointmentRequest $request) use ($patient): void {
            $request->update(['patient_id' => $patient->id]);
        });

        return $requests->count();
    }

    public function reject(
        PatientLinkRequest $linkRequest,
        User $reviewer,
        ?string $note = null,
    ): PatientLinkRequest {
        return DB::transaction(function () use ($linkRequest, $reviewer, $note): PatientLinkRequest {
            User::query()->lockForUpdate()->findOrFail($linkRequest->user_id);
            $lockedRequest = PatientLinkRequest::query()
                ->whereKey($linkRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRequest->isPending()) {
                throw ValidationException::withMessages([
                    'request' => ['Only pending link requests can be rejected.'],
                ]);
            }

            $lockedRequest->update([
                'status' => 'rejected',
                'reviewer_id' => $reviewer->id,
                'decision_note' => $note,
                'reviewed_at' => now(),
            ]);

            $this->createAuditLog->handle(
                subject: $lockedRequest,
                action: AuditEvent::PatientLinkRejected,
                metadata: [
                    'account_id' => $lockedRequest->user_id,
                    'note_provided' => filled($note),
                ],
                actorId: $reviewer->id,
            );

            return $lockedRequest->fresh();
        });
    }
}
