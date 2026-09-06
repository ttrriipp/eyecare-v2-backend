<?php

namespace App\Actions\Auth;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\PilotParticipantAccount;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ResetPilotParticipantCredential
{
    private const ManifestDirectory = 'pilot/credentials';

    private const PasswordLength = 32;

    private const PilotUnavailableMessage = 'Pilot mode must be enabled with a future expiry before resetting credentials.';

    public function __construct(
        protected AuthenticatePilotParticipant $authenticatePilotParticipant,
        protected CreateAuditLog $createAuditLog,
    ) {}

    /**
     * Replace one active participant credential and issue a private one-time file.
     *
     * @return array{manifest_path: string}
     */
    public function handle(string $participantCode): array
    {
        if (! $this->authenticatePilotParticipant->isAvailableAt()) {
            throw new RuntimeException(self::PilotUnavailableMessage);
        }

        $normalizedCode = $this->normalizeCode($participantCode);
        if ($normalizedCode === '') {
            throw new RuntimeException('A participant code is required to reset a credential.');
        }

        $diskName = (string) config('capstone_pilot.credential_disk', 'local');
        $this->ensurePrivateDisk($diskName);
        $disk = Storage::disk($diskName);
        $manifestPath = self::ManifestDirectory.'/reset-'.now()->format('YmdHis').'-'.Str::uuid().'.json';

        try {
            return DB::transaction(function () use ($disk, $manifestPath, $normalizedCode): array {
                $account = PilotParticipantAccount::query()
                    ->where('participant_code', $normalizedCode)
                    ->lockForUpdate()
                    ->with('user')
                    ->first();

                if ($account === null) {
                    throw new RuntimeException('Pilot participant account not found.');
                }

                if ($account->revoked_at !== null) {
                    throw new RuntimeException('Pilot participant account is revoked.');
                }

                if (! $account->expires_at->isAfter(now())) {
                    throw new RuntimeException('Pilot participant account has expired.');
                }

                $user = $account->user;
                if ($user === null || ! $user->is_active || ! $user->roles()->where('name', Role::Patient)->exists()) {
                    throw new RuntimeException('Pilot participant account is not eligible.');
                }

                $password = $this->newPassword($user);
                $user->update(['password' => $password]);
                $user->updateQuietly(['must_change_password' => false]);
                $tokensDeleted = $user->tokens()->delete();

                $contents = json_encode([
                    'version' => 1,
                    'generated_at' => now()->toISOString(),
                    'expires_at' => $account->expires_at->toISOString(),
                    'participants' => [[
                        'participant_code' => $normalizedCode,
                        'password' => $password,
                    ]],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

                if (! $disk->put($manifestPath, $contents, ['visibility' => 'private'])) {
                    throw new RuntimeException('Unable to write the private credential manifest.');
                }

                $this->createAuditLog->handle(
                    subject: $account,
                    action: AuditEvent::ParticipantCredentialReset,
                    metadata: ['tokens_deleted' => $tokensDeleted],
                );

                return ['manifest_path' => $manifestPath];
            });
        } catch (Throwable $exception) {
            $this->deleteManifestIfPresent($disk, $manifestPath);

            throw $exception;
        }
    }

    private function normalizeCode(string $participantCode): string
    {
        return strtoupper(trim($participantCode));
    }

    private function newPassword(User $user): string
    {
        do {
            $password = Str::password(self::PasswordLength);
            try {
                $matchesCurrentPassword = Hash::check($password, (string) $user->password);
            } catch (Throwable) {
                $matchesCurrentPassword = false;
            }
        } while ($matchesCurrentPassword);

        return $password;
    }

    private function ensurePrivateDisk(string $diskName): void
    {
        $diskConfig = config('filesystems.disks.'.$diskName);

        if (! is_array($diskConfig)) {
            throw new RuntimeException("Credential disk [{$diskName}] is not configured.");
        }

        if (($diskConfig['visibility'] ?? 'private') !== 'private') {
            throw new RuntimeException('The credential disk must be configured as private.');
        }
    }

    private function deleteManifestIfPresent(object $disk, string $manifestPath): void
    {
        try {
            if ($disk->exists($manifestPath)) {
                $disk->delete($manifestPath);
            }
        } catch (Throwable) {
            // Preserve the original transaction or storage exception.
        }
    }
}
