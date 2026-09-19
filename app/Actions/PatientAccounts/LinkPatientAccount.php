<?php

namespace App\Actions\PatientAccounts;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Conversations\AssociateAccountConversation;
use App\Enums\AuditEvent;
use App\Exceptions\PatientIdentityMismatchException;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only normal application writer that sets a non-null patients.user_id.
 *
 * Locks User then Patient, evaluates identity compatibility, and either
 * links atomically or throws with no mutation.
 */
class LinkPatientAccount
{
    public function __construct(
        private readonly PatientAccountIdentityMatcher $matcher,
        private readonly AssociateAccountConversation $associateConversation,
        private readonly CreateAuditLog $auditLog,
    ) {}

    /**
     * @return array{patient: Patient, match: PatientAccountIdentityMatch}
     *
     * @throws PatientIdentityMismatchException
     */
    public function handle(
        User $account,
        Patient $patient,
        string $source,
        ?int $sourceId = null,
        ?int $actorId = null,
    ): array {
        return DB::transaction(function () use ($account, $patient, $source, $sourceId, $actorId): array {
            // Lock order: User -> Patient
            $account = User::query()->lockForUpdate()->findOrFail($account->id);
            $patient = Patient::query()->lockForUpdate()->findOrFail($patient->id);

            // Recheck link states under lock
            if ($account->patient()->exists()) {
                throw ValidationException::withMessages([
                    'account' => ['This account is already linked to a patient.'],
                ]);
            }

            if ($patient->user_id !== null) {
                throw ValidationException::withMessages([
                    'patient' => ['This patient is already linked to an account.'],
                ]);
            }

            // Evaluate compatibility on locked current state
            $match = $this->matcher->handle($account, $patient);

            if (! $match->isEligible()) {
                throw new PatientIdentityMismatchException;
            }

            // Assign link ownership explicitly; these server-controlled
            // fields are intentionally not mass assignable on Patient.
            $patient->forceFill([
                'user_id' => $account->id,
                'identity_review_required' => false,
                'identity_review_required_at' => null,
            ])->save();

            $this->associateConversation->handle($account, $patient);

            // PII-safe audit
            $this->auditLog->handle(
                subject: $patient,
                action: AuditEvent::PatientAccountLinked,
                metadata: [
                    'account_id' => $account->id,
                    'source' => $source,
                    'source_id' => $sourceId,
                    'matched_fields' => $match->matchedFields,
                ],
                actorId: $actorId ?? $account->id,
            );

            return [
                'patient' => $patient->fresh(),
                'match' => $match,
            ];
        });
    }
}
