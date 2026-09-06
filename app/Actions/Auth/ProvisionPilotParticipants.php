<?php

namespace App\Actions\Auth;

use App\Models\PilotParticipantAccount;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProvisionPilotParticipants
{
    private const ManifestDirectory = 'pilot/credentials';

    private const PasswordLength = 32;

    /**
     * Provision the configured pilot participant set and its one-time manifest.
     *
     * @return array{participant_count: int, manifest_path: string}
     */
    public function handle(): array
    {
        $this->ensurePilotIsAvailable();

        $participantLimit = (int) config('capstone_pilot.participant_limit', 75);
        if ($participantLimit < 1) {
            throw new RuntimeException('The pilot participant limit must be positive.');
        }

        $diskName = (string) config('capstone_pilot.credential_disk', 'local');
        $disk = Storage::disk($diskName);
        $this->ensurePrivateDisk($diskName);

        if (PilotParticipantAccount::query()->exists()) {
            throw new RuntimeException('Pilot participant accounts already exist; refusing an unsafe rerun.');
        }

        if ($disk->allFiles(self::ManifestDirectory) !== []) {
            throw new RuntimeException('A pilot credential manifest already exists; refusing to overwrite it.');
        }

        $manifestPath = self::ManifestDirectory.'/manifest-'.now()->format('YmdHis').'-'.Str::uuid().'.json';
        $pilotExpiresAt = $this->pilotExpiresAt();
        $credentials = [];
        $passwords = [];

        try {
            return DB::transaction(function () use (
                $disk,
                $manifestPath,
                $participantLimit,
                $pilotExpiresAt,
                &$credentials,
                &$passwords,
            ): array {
                $patientRole = Role::query()->where('name', Role::Patient)->first();
                if ($patientRole === null) {
                    throw new RuntimeException('The patient role is required before provisioning participants.');
                }

                for ($number = 1; $number <= $participantLimit; $number++) {
                    $participantCode = sprintf('PILOT-%04d', $number);
                    $password = $this->uniquePassword($passwords);

                    $user = User::query()->create([
                        'first_name' => null,
                        'middle_name' => null,
                        'last_name' => null,
                        'email' => null,
                        'phone' => null,
                        'address' => null,
                        'date_of_birth' => null,
                        'password' => $password,
                        'role_id' => $patientRole->id,
                        'is_active' => true,
                        'must_change_password' => false,
                        'password_changed_at' => null,
                        'is_optometrist' => false,
                        'privacy_notice_version' => null,
                        'privacy_acknowledged_at' => null,
                    ]);

                    $user->roles()->sync([$patientRole->id]);

                    PilotParticipantAccount::query()->create([
                        'user_id' => $user->id,
                        'participant_code' => $participantCode,
                        'expires_at' => $pilotExpiresAt,
                        'revoked_at' => null,
                    ]);

                    $credentials[] = [
                        'participant_code' => $participantCode,
                        'password' => $password,
                    ];
                }

                $manifest = [
                    'version' => 1,
                    'generated_at' => now()->toISOString(),
                    'pilot_expires_at' => $pilotExpiresAt->toISOString(),
                    'participants' => $credentials,
                ];
                $contents = json_encode(
                    $manifest,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                )."\n";

                if (! $disk->put($manifestPath, $contents, ['visibility' => 'private'])) {
                    throw new RuntimeException('Unable to write the private credential manifest.');
                }

                return [
                    'participant_count' => $participantLimit,
                    'manifest_path' => $manifestPath,
                ];
            });
        } catch (Throwable $exception) {
            if ($disk->exists($manifestPath)) {
                $disk->delete($manifestPath);
            }

            throw $exception;
        }
    }

    private function ensurePilotIsAvailable(): void
    {
        if (config('capstone_pilot.enabled') !== true) {
            throw new RuntimeException('Pilot mode must be enabled before provisioning participants.');
        }

        $this->pilotExpiresAt();
    }

    private function pilotExpiresAt(): CarbonInterface
    {
        $configuredExpiry = config('capstone_pilot.expires_at');
        $expiresAt = $configuredExpiry instanceof CarbonInterface
            ? $configuredExpiry
            : $this->parseExpiry($configuredExpiry);

        if ($expiresAt === null || ! $expiresAt->isAfter(now())) {
            throw new RuntimeException('Pilot mode requires a parseable future expiry before provisioning participants.');
        }

        return $expiresAt->toImmutable();
    }

    private function parseExpiry(mixed $configuredExpiry): ?CarbonInterface
    {
        if (! is_string($configuredExpiry) || trim($configuredExpiry) === '') {
            return null;
        }

        try {
            return Carbon::parse($configuredExpiry, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
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

    /**
     * @param  array<string, true>  $passwords
     */
    private function uniquePassword(array &$passwords): string
    {
        do {
            $password = Str::password(self::PasswordLength);
        } while (isset($passwords[$password]));

        $passwords[$password] = true;

        return $password;
    }
}
