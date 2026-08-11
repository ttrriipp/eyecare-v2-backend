<?php

namespace App\Actions\PatientAccounts;

use App\Actions\Conversations\AssociateAccountConversation;
use App\Models\Patient;
use App\Models\PatientLinkRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewPatientLinkRequest
{
    public function approve(
        PatientLinkRequest $linkRequest,
        Patient $patient,
        User $reviewer,
        ?string $note = null,
    ): PatientLinkRequest {
        if (! $linkRequest->isPending()) {
            throw ValidationException::withMessages([
                'request' => ['Only pending link requests can be approved.'],
            ]);
        }

        if ($patient->user_id !== null) {
            throw ValidationException::withMessages([
                'patient' => ['This patient is already linked to another account.'],
            ]);
        }

        $account = $linkRequest->user;

        if ($account->patient !== null) {
            throw ValidationException::withMessages([
                'account' => ['This account is already linked to a patient.'],
            ]);
        }

        return DB::transaction(function () use ($linkRequest, $patient, $reviewer, $note, $account) {
            // Re-check under lock
            $patient = Patient::query()->lockForUpdate()->findOrFail($patient->id);

            if ($patient->user_id !== null) {
                throw ValidationException::withMessages([
                    'patient' => ['This patient was linked by another operation.'],
                ]);
            }

            // Activate the link
            $patient->update(['user_id' => $linkRequest->user_id]);

            // Update the request
            $linkRequest->update([
                'status' => 'approved',
                'reviewed_patient_id' => $patient->id,
                'reviewer_id' => $reviewer->id,
                'decision_note' => $note,
                'reviewed_at' => now(),
            ]);

            // Associate the account's conversation with the Patient
            app(AssociateAccountConversation::class)->handle($account, $patient);

            return $linkRequest->fresh(['user', 'reviewedPatient', 'reviewer']);
        });
    }

    public function reject(
        PatientLinkRequest $linkRequest,
        User $reviewer,
        ?string $note = null,
    ): PatientLinkRequest {
        if (! $linkRequest->isPending()) {
            throw ValidationException::withMessages([
                'request' => ['Only pending link requests can be rejected.'],
            ]);
        }

        $linkRequest->update([
            'status' => 'rejected',
            'reviewer_id' => $reviewer->id,
            'decision_note' => $note,
            'reviewed_at' => now(),
        ]);

        return $linkRequest->fresh();
    }
}
