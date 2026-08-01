<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\IssueOtpChallenge;
use App\Actions\Auth\VerifyOtpChallenge;
use App\Actions\PatientAccounts\AcceptPatientInvitation;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IssueOtpRequest;
use App\Http\Requests\Api\VerifyOtpRequest;
use App\Http\Resources\PatientAccountResource;
use App\Models\PatientInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OtpChallengeController extends Controller
{
    public function issue(IssueOtpRequest $request, IssueOtpChallenge $issueOtp): JsonResponse
    {
        $result = $issueOtp->handle(
            contactType: $request->validated('contact_type'),
            contactValue: $request->validated('contact_value'),
            purpose: OtpPurpose::Registration,
        );

        $challenge = $result['challenge'];

        return response()->json([
            'data' => [
                'challenge_id' => $challenge->public_id,
                'expires_at' => $challenge->expires_at->toISOString(),
            ],
        ], 200);
    }

    public function verify(VerifyOtpRequest $request, VerifyOtpChallenge $verifyOtp): JsonResponse
    {
        $challenge = $verifyOtp->handle(
            challengeId: $request->validated('challenge_id'),
            code: $request->validated('code'),
            expectedPurpose: OtpPurpose::Registration,
            ip: $request->ip(),
        );

        return response()->json([
            'data' => [
                'challenge_id' => $challenge->public_id,
                'verified' => true,
            ],
        ], 200);
    }

    public function requestOtp(Request $request, IssueOtpChallenge $issueOtp): JsonResponse
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

        $challenge = $result['challenge'];

        return response()->json([
            'data' => [
                'challenge_id' => $challenge->public_id,
                'expires_at' => $challenge->expires_at->toISOString(),
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
