<?php

namespace App\Actions\PatientAccounts;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Conversations\DetachAccountConversation;
use App\Enums\AppointmentRequestStatus;
use App\Enums\AuditEvent;
use App\Models\AppointmentRequest;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnlinkPatientAccount
{
    public function handle(Patient $patient, User $admin, string $reason): void
    {
        $userId = Patient::query()->whereKey($patient->id)->value('user_id');

        if ($userId === null) {
            throw ValidationException::withMessages([
                'patient' => ['This patient is not linked to any account.'],
            ]);
        }

        DB::transaction(function () use ($patient, $admin, $reason, $userId): void {
            $user = User::query()->lockForUpdate()->find($userId);
            $patient = Patient::query()->lockForUpdate()->findOrFail($patient->id);

            if ($patient->user_id !== $userId) {
                throw ValidationException::withMessages([
                    'patient' => ['This patient link changed before it could be removed.'],
                ]);
            }

            // Revoke all patient tokens
            if ($user !== null) {
                $user->tokens()->delete();

                // Detach conversation ownership
                app(DetachAccountConversation::class)->handle($user);
            }

            $this->unlinkPendingAppointmentRequests($userId, $patient);

            // Unlink and clear any pending identity review state.
            $patient->forceFill([
                'user_id' => null,
                'identity_review_required' => false,
                'identity_review_required_at' => null,
            ])->save();

            // Audit
            app(CreateAuditLog::class)->handle(
                subject: $patient,
                action: AuditEvent::PatientAccountUnlinked,
                metadata: [
                    'unlinked_user_id' => $userId,
                    'reason_provided' => filled($reason),
                ],
                actorId: $admin->id,
            );
        });
    }

    /**
     * Pending requests must return to identity resolution after an account
     * unlink. Accepted requests retain their historical clinical patient.
     */
    private function unlinkPendingAppointmentRequests(int $userId, Patient $patient): void
    {
        AppointmentRequest::query()
            ->where('user_id', $userId)
            ->where('patient_id', $patient->id)
            ->where('status', AppointmentRequestStatus::Pending)
            ->lockForUpdate()
            ->get()
            ->each(function (AppointmentRequest $request): void {
                $request->update(['patient_id' => null]);
            });
    }
}
