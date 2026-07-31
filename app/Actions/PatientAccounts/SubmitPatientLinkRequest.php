<?php

namespace App\Actions\PatientAccounts;

use App\Models\PatientLinkCandidate;
use App\Models\PatientLinkRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitPatientLinkRequest
{
    public function __construct(
        protected RankPatientCandidates $rankCandidates,
    ) {}

    public function handle(User $account): PatientLinkRequest
    {
        // Check for existing active request
        $existingRequest = PatientLinkRequest::where('user_id', $account->id)
            ->where('status', 'pending')
            ->first();

        if ($existingRequest !== null) {
            return $existingRequest;
        }

        // Check if already linked
        if ($account->patient !== null) {
            throw ValidationException::withMessages([
                'account' => ['This account is already linked to a patient.'],
            ]);
        }

        return DB::transaction(function () use ($account) {
            $request = PatientLinkRequest::create([
                'user_id' => $account->id,
                'encrypted_identity_snapshot' => [
                    'first_name' => $account->first_name,
                    'last_name' => $account->last_name,
                    'date_of_birth' => $account->date_of_birth?->format('Y-m-d'),
                ],
                'status' => 'pending',
            ]);

            // Rank and store candidates
            $candidates = $this->rankCandidates->handle($account);

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
    }
}
