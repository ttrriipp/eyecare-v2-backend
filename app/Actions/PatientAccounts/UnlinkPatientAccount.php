<?php

namespace App\Actions\PatientAccounts;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnlinkPatientAccount
{
    public function handle(Patient $patient, User $admin, string $reason): void
    {
        if ($patient->user_id === null) {
            throw ValidationException::withMessages([
                'patient' => ['This patient is not linked to any account.'],
            ]);
        }

        DB::transaction(function () use ($patient, $admin, $reason) {
            $patient->lockForUpdate();

            $userId = $patient->user_id;

            // Revoke all patient tokens
            $user = User::find($userId);
            if ($user !== null) {
                $user->tokens()->delete();
            }

            // Unlink
            $patient->update(['user_id' => null]);

            // Audit
            app(CreateAuditLog::class)->handle(
                subject: $patient,
                action: 'patient_account_unlinked',
                metadata: [
                    'unlinked_user_id' => $userId,
                    'admin_id' => $admin->id,
                    'reason' => $reason,
                ]
            );
        });
    }
}
