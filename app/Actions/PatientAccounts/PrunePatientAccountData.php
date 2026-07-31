<?php

namespace App\Actions\PatientAccounts;

use App\Models\AppointmentRequest;
use App\Models\OtpChallenge;
use App\Models\PatientInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class PrunePatientAccountData
{
    public function handle(): array
    {
        $lockKey = 'prune_patient_account_data';
        $lockDuration = 120; // seconds

        return Cache::lock($lockKey, $lockDuration)->block(5, function () {
            $otpPruned = $this->pruneExpiredOtps();
            $tokenPruned = $this->pruneExpiredTokens();
            $invitationPruned = $this->pruneExpiredInvitations();
            $requestPruned = $this->pruneTerminalRequests();

            return [
                'otp_challenges' => $otpPruned,
                'tokens' => $tokenPruned,
                'invitations' => $invitationPruned,
                'terminal_requests' => $requestPruned,
            ];
        });
    }

    protected function pruneExpiredOtps(): int
    {
        $retentionDays = config('patient_accounts.otp.prune_after_days', 30);

        return OtpChallenge::query()
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->where(function ($query) {
                $query->whereNotNull('consumed_at')
                    ->orWhereNotNull('invalidated_at')
                    ->orWhere('expires_at', '<', now());
            })
            ->delete();
    }

    protected function pruneExpiredTokens(): int
    {
        $graceHours = config('patient_accounts.tokens.prune_grace_hours', 24);

        return User::query()
            ->whereHas('tokens', function ($query) use ($graceHours) {
                $query->where('expires_at', '<', now()->subHours($graceHours));
            })
            ->get()
            ->flatMap(fn ($user) => $user->tokens()->where('expires_at', '<', now()->subHours($graceHours))->get())
            ->each->delete()
            ->count();
    }

    protected function pruneExpiredInvitations(): int
    {
        $retentionYears = config('patient_accounts.invitations.retention_years', 2);

        return PatientInvitation::query()
            ->where('created_at', '<', now()->subYears($retentionYears))
            ->where('status', '!=', 'pending')
            ->delete();
    }

    protected function pruneTerminalRequests(): int
    {
        $retentionYears = config('patient_accounts.appointment_requests.retention_years', 2);

        return AppointmentRequest::query()
            ->where('created_at', '<', now()->subYears($retentionYears))
            ->where('status', '!=', 'pending')
            ->delete();
    }
}
