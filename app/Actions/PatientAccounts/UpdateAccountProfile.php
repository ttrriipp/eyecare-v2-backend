<?php

namespace App\Actions\PatientAccounts;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\PatientLinkRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        private readonly FlagPatientIdentityReview $flagPatientIdentityReview,
        private readonly CreateAuditLog $createAuditLog,
        private readonly NormalizeContact $normalizeContact,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $account, array $attributes, bool $stepUpVerified = false): User
    {
        return DB::transaction(function () use ($account, $attributes, $stepUpVerified): User {
            $lockedAccount = User::query()->lockForUpdate()->findOrFail($account->id);
            $profileAttributes = array_intersect_key($attributes, array_flip(self::PROFILE_FIELDS));

            foreach (['first_name', 'middle_name', 'last_name'] as $field) {
                if (! array_key_exists($field, $profileAttributes)) {
                    continue;
                }

                $submitted = $profileAttributes[$field];
                $current = $lockedAccount->{$field};

                if ($this->normalizeName($submitted) === $this->normalizeName($current)) {
                    unset($profileAttributes[$field]);
                }
            }

            $lockedAccount->fill($profileAttributes);
            $changedFields = array_values(array_intersect(
                self::PROFILE_FIELDS,
                array_keys($lockedAccount->getDirty()),
            ));

            if ($changedFields === []) {
                return $lockedAccount;
            }

            $hasLinkedNameChange = $lockedAccount->patient()->exists()
                && array_intersect(['first_name', 'last_name'], $changedFields) !== [];
            $hasSubmittedDateOfBirth = array_key_exists('date_of_birth', $profileAttributes);

            if (($hasLinkedNameChange || $hasSubmittedDateOfBirth) && ! $stepUpVerified) {
                throw ValidationException::withMessages([
                    'step_up_token' => ['A step-up verification token is required for this action.'],
                ]);
            }

            $lockedAccount->save();
            $expiredLinkRequest = $this->expirePendingLinkRequest->handle(
                account: $lockedAccount,
                reason: 'account_identity_changed',
            );

            $this->flagPatientIdentityReview->handle(
                account: $lockedAccount,
                reason: 'account_profile_changed',
                changedFields: $changedFields,
                actorId: $lockedAccount->id,
            );

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

    private function normalizeName(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->normalizeContact->name($value);
    }
}
