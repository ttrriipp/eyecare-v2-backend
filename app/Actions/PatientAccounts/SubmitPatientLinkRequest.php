<?php

namespace App\Actions\PatientAccounts;

use App\Actions\Notifications\NotifyAdminUsers;
use App\Models\PatientLinkCandidate;
use App\Models\PatientLinkRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitPatientLinkRequest
{
    public function __construct(
        protected RankPatientCandidates $rankCandidates,
        protected PatientLinkIdentitySnapshot $identitySnapshot,
        protected NotifyAdminUsers $notifyAdminUsers,
    ) {}

    public function handle(User $account): PatientLinkRequest
    {
        $created = false;

        $request = DB::transaction(function () use ($account, &$created): PatientLinkRequest {
            $lockedAccount = User::query()->lockForUpdate()->findOrFail($account->id);
            $existingRequest = PatientLinkRequest::query()
                ->where('user_id', $lockedAccount->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($existingRequest !== null) {
                return $existingRequest;
            }

            if ($lockedAccount->patient()->exists()) {
                throw ValidationException::withMessages([
                    'account' => ['This account is already linked to a patient.'],
                ]);
            }

            $request = PatientLinkRequest::create([
                'user_id' => $lockedAccount->id,
                'encrypted_identity_snapshot' => $this->identitySnapshot->fromAccount($lockedAccount),
                'status' => 'pending',
            ]);
            $created = true;

            // Rank and store candidates
            $candidates = $this->rankCandidates->handle($lockedAccount);

            foreach ($candidates as $rank => $candidate) {
                PatientLinkCandidate::create([
                    'link_request_id' => $request->id,
                    'patient_id' => $candidate['patient']->id,
                    'match_strength' => $candidate['strength'],
                    'reason_codes' => $candidate['reasons'],
                    'rank' => $rank + 1,
                ]);
            }

            return $request->load('candidates.patient');
        });

        if ($created) {
            $this->notifyAdminUsers->patientLinkRequestSubmitted($request);
        }

        return $request;
    }
}
