<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\IssueOtpChallenge;
use App\Actions\PatientAccounts\AcceptPatientInvitation;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\PatientAccountResource;
use App\Models\PatientInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientInvitationController extends Controller
{
    public function requestOtp(Request $request, IssueOtpChallenge $issueOtp, DispatchOtpChallenge $dispatch): JsonResponse
    {
        $request->validate([
            'invitation_code' => ['required', 'string'],
        ]);

        $invitation = PatientInvitation::where('invitation_code', $request->input('invitation_code'))->first();

        if ($invitation === null || ! $invitation->isPending()) {
            return response()->json([
                'error' => [
                    'code' => 'INVITATION_INVALID',
                    'message' => 'The invitation is invalid, expired, or has been revoked.',
                ],
            ], 422);
        }

        $destination = $invitation->encrypted_destination;

        $result = $issueOtp->handle(
            contactType: $invitation->channel,
            contactValue: $destination,
            purpose: OtpPurpose::InvitationAcceptance,
            userId: $request->user()?->id,
        );

        return response()->json([
            'data' => [
                'challenge_id' => $result['challenge']->public_id,
                'expires_at' => $result['challenge']->expires_at->toISOString(),
            ],
        ]);
    }

    public function accept(Request $request, AcceptPatientInvitation $accept): JsonResponse
    {
        $request->validate([
            'invitation_code' => ['required', 'string'],
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $result = $accept->handle(
            invitationCode: $request->input('invitation_code'),
            challengeId: $request->input('challenge_id'),
            code: $request->input('code'),
        );

        $user = $result['user'];
        $user->load('role', 'contacts');

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'user' => PatientAccountResource::make($user),
                'status' => 'linked',
            ],
        ]);
    }
}
