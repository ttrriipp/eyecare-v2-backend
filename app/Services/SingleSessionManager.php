<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;

class SingleSessionManager
{
    /**
     * Claim the user's single active panel session.
     *
     * The conditional database update makes simultaneous login attempts race
     * safely: only one request can claim an unclaimed account.
     */
    public function claim(User $user, string $sessionId): bool
    {
        if (! $user->requiresSingleSession()) {
            return true;
        }

        if ($sessionId === '') {
            return false;
        }

        $sessionHash = $this->hashSessionId($sessionId);
        $activeSession = $user->newQuery()
            ->select(['id', 'active_session_hash', 'active_session_last_seen_at'])
            ->findOrFail($user->getKey());

        if ($activeSession->active_session_hash === $sessionHash) {
            $this->touch($user, $sessionId);

            return true;
        }

        if (
            $activeSession->active_session_hash !== null
            && $this->isFresh($activeSession->active_session_last_seen_at)
        ) {
            return false;
        }

        if ($activeSession->active_session_hash !== null) {
            $user->newQuery()
                ->whereKey($user->getKey())
                ->where('active_session_hash', $activeSession->active_session_hash)
                ->toBase()
                ->update([
                    'active_session_hash' => null,
                    'active_session_last_seen_at' => null,
                ]);
        }

        return $user->newQuery()
            ->whereKey($user->getKey())
            ->whereNull('active_session_hash')
            ->toBase()
            ->update([
                'active_session_hash' => $sessionHash,
                'active_session_last_seen_at' => now(),
            ]) === 1;
    }

    /**
     * Release the active claim only when it belongs to the logging-out session.
     */
    public function release(User $user, string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        $user->newQuery()
            ->whereKey($user->getKey())
            ->where('active_session_hash', $this->hashSessionId($sessionId))
            ->toBase()
            ->update([
                'active_session_hash' => null,
                'active_session_last_seen_at' => null,
            ]);
    }

    private function touch(User $user, string $sessionId): void
    {
        $user->newQuery()
            ->whereKey($user->getKey())
            ->where('active_session_hash', $this->hashSessionId($sessionId))
            ->toBase()
            ->update([
                'active_session_last_seen_at' => now(),
            ]);
    }

    private function hashSessionId(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    private function isFresh(?Carbon $lastSeenAt): bool
    {
        if ($lastSeenAt === null) {
            return true;
        }

        return $lastSeenAt->greaterThanOrEqualTo(
            now()->subMinutes(max(1, (int) config('session.lifetime', 120))),
        );
    }
}
