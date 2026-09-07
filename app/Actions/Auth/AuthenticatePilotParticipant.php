<?php

namespace App\Actions\Auth;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\PilotParticipantAccount;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthenticatePilotParticipant
{
    /**
     * A fixed hash keeps unknown-code failures comparable to password failures.
     */
    private const DummyPasswordHash = '$2y$12$rLo7ctqP0U14d9/bdoZp8u6S1pIQjfgHnX5ryMeV7PTN2JDb1o70i';

    public function __construct(
        protected IssuePatientDeviceToken $issueToken,
        protected CreateAuditLog $createAuditLog,
    ) {}

    /**
     * Determine whether the pilot is explicitly enabled and not expired.
     */
    public function isAvailableAt(?CarbonInterface $at = null): bool
    {
        if (config('capstone_pilot.enabled') !== true
            || config('deployment.mode') === 'demo') {
            return false;
        }

        $expiresAt = $this->pilotExpiresAt();

        return $expiresAt?->isAfter($at ?? now()) ?? false;
    }

    /**
     * Authenticate an eligible participant and issue a pilot-bounded token.
     *
     * @return array{step_up_required: false, token: string, user: User}
     */
    public function handle(
        string $participantCode,
        string $password,
        ?string $deviceName = null,
        ?string $installationId = null,
    ): array {
        $now = now();

        if (! $this->isAvailableAt($now)) {
            $this->throwGenericFailure();
        }

        $account = PilotParticipantAccount::query()
            ->eligibleAt($now)
            ->where('participant_code', $this->normalizeCode($participantCode))
            ->with('user')
            ->first();

        if (! $this->passwordMatches($account?->user, $password)) {
            if ($account?->user !== null) {
                $this->createAuditLog->handle(
                    subject: $account->user,
                    action: AuditEvent::ParticipantLoginFailed,
                    metadata: ['auth_method' => 'participant_code'],
                    actorId: $account->user_id,
                );
            }

            $this->throwGenericFailure();
        }

        $pilotExpiresAt = $this->pilotExpiresAt();
        if ($pilotExpiresAt === null) {
            $this->throwGenericFailure();
        }

        $tokenExpiresAt = $account->expires_at->isBefore($pilotExpiresAt)
            ? $account->expires_at
            : $pilotExpiresAt;

        $tokenResult = $this->issueToken->issueForUser(
            user: $account->user,
            deviceName: $deviceName,
            installationId: $installationId,
            expiresAt: $tokenExpiresAt,
        );

        $this->createAuditLog->handle(
            subject: $account->user,
            action: AuditEvent::ParticipantLoggedIn,
            metadata: [
                'auth_method' => 'participant_code',
                'installation_bound' => $installationId !== null,
            ],
            actorId: $account->user_id,
        );

        return [
            'step_up_required' => false,
            'token' => $tokenResult['token'],
            'user' => $tokenResult['user'],
        ];
    }

    public function normalizeCode(string $participantCode): string
    {
        return strtoupper(trim($participantCode));
    }

    private function passwordMatches(?User $user, string $password): bool
    {
        $passwordHash = $user?->password;
        $hasPassword = is_string($passwordHash) && $passwordHash !== '';

        if (! $hasPassword) {
            $passwordHash = self::DummyPasswordHash;
        }

        try {
            return $hasPassword && Hash::check($password, $passwordHash);
        } catch (Throwable) {
            return false;
        }
    }

    private function pilotExpiresAt(): ?CarbonInterface
    {
        $configuredExpiry = config('capstone_pilot.expires_at');

        if ($configuredExpiry instanceof CarbonInterface) {
            return $configuredExpiry;
        }

        if (! is_string($configuredExpiry) || trim($configuredExpiry) === '') {
            return null;
        }

        try {
            return Carbon::parse($configuredExpiry, config('app.timezone'))->toImmutable();
        } catch (Throwable) {
            return null;
        }
    }

    private function throwGenericFailure(): never
    {
        throw ValidationException::withMessages([
            'participant_code' => ['The provided credentials are incorrect.'],
        ]);
    }
}
