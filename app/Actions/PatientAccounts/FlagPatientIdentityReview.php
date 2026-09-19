<?php

namespace App\Actions\PatientAccounts;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\Patient;
use App\Models\User;

final class FlagPatientIdentityReview
{
    public function __construct(
        private readonly PatientAccountIdentityMatcher $identityMatcher,
        private readonly CreateAuditLog $createAuditLog,
    ) {}

    /**
     * Re-evaluate a locked account's linked Patient and mark a new review
     * transition. The caller must hold the account lock first.
     *
     * @param  list<string>  $changedFields
     */
    public function handle(
        User $account,
        string $reason,
        array $changedFields = [],
        ?int $actorId = null,
    ): ?Patient {
        $patient = Patient::query()
            ->where('user_id', $account->id)
            ->lockForUpdate()
            ->first();

        if ($patient === null) {
            return null;
        }

        return $this->markIfIncompatible(
            account: $account,
            patient: $patient,
            reason: $reason,
            changedFields: $changedFields,
            actorId: $actorId,
        );
    }

    /**
     * Re-evaluate a Patient after its own record has been saved. Patient
     * updates already own the Patient write; no reverse lock is acquired.
     *
     * @param  list<string>  $changedFields
     */
    public function handlePatient(
        Patient $patient,
        string $reason,
        array $changedFields = [],
        ?int $actorId = null,
    ): ?Patient {
        if ($patient->user_id === null) {
            return null;
        }

        $account = $patient->account;

        if (! $account instanceof User) {
            return null;
        }

        return $this->markIfIncompatible(
            account: $account,
            patient: $patient,
            reason: $reason,
            changedFields: $changedFields,
            actorId: $actorId,
        );
    }

    /**
     * @param  list<string>  $changedFields
     */
    private function markIfIncompatible(
        User $account,
        Patient $patient,
        string $reason,
        array $changedFields,
        ?int $actorId,
    ): Patient {
        if ($patient->identity_review_required) {
            return $patient;
        }

        $match = $this->identityMatcher->handle($account, $patient);

        if ($match->isEligible()) {
            return $patient;
        }

        $patient->forceFill([
            'identity_review_required' => true,
            'identity_review_required_at' => now(),
        ])->saveQuietly();

        $this->createAuditLog->handle(
            subject: $patient,
            action: AuditEvent::PatientIdentityReviewRequired,
            metadata: [
                'reason' => $reason,
                'changed_fields' => $changedFields,
                'mismatched_fields' => $match->mismatchedFields,
                'missing_fields' => $match->missingFields,
            ],
            actorId: $actorId,
        );

        return $patient;
    }
}
