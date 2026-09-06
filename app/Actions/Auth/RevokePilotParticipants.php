<?php

namespace App\Actions\Auth;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\PilotParticipantAccount;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RevokePilotParticipants
{
    public function __construct(
        protected CreateAuditLog $createAuditLog,
    ) {}

    /**
     * Revoke one participant or all pilot accounts and delete their tokens.
     *
     * @return array{targeted_count: int, revoked_count: int, tokens_deleted: int}
     */
    public function handle(?string $participantCode = null, bool $all = false): array
    {
        if (($participantCode === null && ! $all) || ($participantCode !== null && $all)) {
            throw new RuntimeException('Specify one participant code or use --all, but not both.');
        }

        $normalizedCode = $participantCode === null
            ? null
            : strtoupper(trim($participantCode));

        if ($normalizedCode === '') {
            throw new RuntimeException('A participant code is required for single-account revocation.');
        }

        return DB::transaction(function () use ($all, $normalizedCode): array {
            $query = PilotParticipantAccount::query()
                ->with('user')
                ->lockForUpdate();

            if (! $all) {
                $query->where('participant_code', $normalizedCode);
            }

            $accounts = $query->get();
            if (! $all && $accounts->isEmpty()) {
                throw new RuntimeException('Pilot participant account not found.');
            }

            $revokedCount = 0;
            $tokensDeleted = 0;

            foreach ($accounts as $account) {
                $user = $account->user;
                if ($user === null) {
                    throw new RuntimeException('Pilot participant account has no user.');
                }

                $wasRevoked = $account->revoked_at !== null;
                if (! $wasRevoked) {
                    $account->forceFill(['revoked_at' => now()])->save();
                    $revokedCount++;
                }

                $deletedForAccount = $user->tokens()->delete();
                $tokensDeleted += $deletedForAccount;

                if (! $wasRevoked || $deletedForAccount > 0) {
                    $this->createAuditLog->handle(
                        subject: $account,
                        action: AuditEvent::ParticipantAccessRevoked,
                        metadata: [
                            'tokens_deleted' => $deletedForAccount,
                            'bulk' => $all,
                        ],
                    );
                }
            }

            return [
                'targeted_count' => $accounts->count(),
                'revoked_count' => $revokedCount,
                'tokens_deleted' => $tokensDeleted,
            ];
        });
    }
}
