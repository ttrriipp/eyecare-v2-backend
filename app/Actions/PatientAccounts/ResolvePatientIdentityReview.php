<?php

namespace App\Actions\PatientAccounts;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResolvePatientIdentityReview
{
    public function __construct(
        private readonly PatientAccountIdentityMatcher $identityMatcher,
        private readonly CreateAuditLog $createAuditLog,
    ) {}

    public function handle(Patient $patient, User $reviewer): Patient
    {
        return DB::transaction(function () use ($patient, $reviewer): Patient {
            $userId = Patient::query()->whereKey($patient->id)->value('user_id');

            if ($userId === null) {
                throw ValidationException::withMessages([
                    'patient' => ['Only linked patient records can be resolved.'],
                ]);
            }

            $account = User::query()->lockForUpdate()->findOrFail($userId);
            $lockedPatient = Patient::query()->lockForUpdate()->findOrFail($patient->id);

            if ($lockedPatient->user_id !== $account->id) {
                throw ValidationException::withMessages([
                    'patient' => ['The patient link changed before identity review could be resolved.'],
                ]);
            }

            if (! $lockedPatient->identity_review_required) {
                throw ValidationException::withMessages([
                    'patient' => ['This patient record does not require identity review.'],
                ]);
            }

            $match = $this->identityMatcher->handle($account, $lockedPatient);

            if (! $match->isEligible()) {
                throw ValidationException::withMessages([
                    'patient' => ['The account and patient details must match before review can be resolved.'],
                ]);
            }

            $lockedPatient->forceFill([
                'identity_review_required' => false,
                'identity_review_required_at' => null,
            ])->saveQuietly();

            $this->createAuditLog->handle(
                subject: $lockedPatient,
                action: AuditEvent::PatientIdentityReviewResolved,
                metadata: [
                    'account_id' => $account->id,
                    'matched_fields' => $match->matchedFields,
                ],
                actorId: $reviewer->id,
            );

            return $lockedPatient->fresh(['account']);
        });
    }
}
