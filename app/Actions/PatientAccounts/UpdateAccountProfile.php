<?php

namespace App\Actions\PatientAccounts;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\PatientLinkRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateAccountProfile
{
    /**
     * @var list<string>
     */
    private const PROFILE_FIELDS = [
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
    ];

    public function __construct(
        private readonly ExpirePendingPatientLinkRequest $expirePendingLinkRequest,
        private readonly PatientAccountIdentityMatcher $identityMatcher,
        private readonly CreateAuditLog $createAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $account, array $attributes): User
    {
        return DB::transaction(function () use ($account, $attributes): User {
            $lockedAccount = User::query()->lockForUpdate()->findOrFail($account->id);
            $profileAttributes = array_intersect_key($attributes, array_flip(self::PROFILE_FIELDS));

            $lockedAccount->fill($profileAttributes);
            $changedFields = array_values(array_intersect(
                self::PROFILE_FIELDS,
                array_keys($lockedAccount->getDirty()),
            ));

            if ($changedFields === []) {
                return $lockedAccount;
            }

            $lockedAccount->save();
            $expiredLinkRequest = $this->expirePendingLinkRequest->handle(
                account: $lockedAccount,
                reason: 'account_identity_changed',
            );

            // Check for identity drift if linked
            $linkedPatient = $lockedAccount->patient;
            if ($linkedPatient !== null && $linkedPatient->user_id === $lockedAccount->id) {
                $match = $this->identityMatcher->handle($lockedAccount, $linkedPatient);
                if (! $match->isEligible()) {
                    $linkedPatient->markForIdentityReview();
                    $this->createAuditLog->handle(
                        subject: $linkedPatient,
                        action: AuditEvent::PatientIdentityReviewRequired,
                        metadata: [
                            'reason' => 'account_profile_changed',
                            'changed_fields' => $changedFields,
                            'mismatched_fields' => $match->mismatchedFields,
                            'missing_fields' => $match->missingFields,
                        ],
                        actorId: $lockedAccount->id,
                    );
                }
            }

            $metadata = [
                'changed_fields' => $changedFields,
                'pending_link_request_expired' => $expiredLinkRequest !== null,
            ];

            if ($expiredLinkRequest instanceof PatientLinkRequest) {
                $metadata['pending_link_request_id'] = $expiredLinkRequest->id;
            }

            $this->createAuditLog->handle(
                subject: $lockedAccount,
                action: AuditEvent::UserProfileUpdated,
                metadata: $metadata,
                actorId: $lockedAccount->id,
            );

            return $lockedAccount;
        });
    }
}
