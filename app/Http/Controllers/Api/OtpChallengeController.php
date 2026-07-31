<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\DispatchOtpChallenge;
use App\Actions\Auth\IssueOtpChallenge;
use App\Actions\Auth\RecoverPatientPassword;
use App\Actions\Auth\VerifyOtpChallenge;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IssueOtpRequest;
use App\Http\Requests\Api\VerifyOtpRequest;
use App\Http\Resources\PatientAccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OtpChallengeController extends Controller
{
    public function issue(IssueOtpRequest $request, IssueOtpChallenge $issueOtp, DispatchOtpChallenge $dispatch): JsonResponse
    {
        $challenge = $issueOtp->handle(
            contactType: $request->validated('contact_type'),
            contactValue: $request->validated('contact_value'),
            purpose: OtpPurpose::Registration,
        );

        $dispatch->handle($challenge);

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

    public function recoveryOtp(Request $request, RecoverPatientPassword $recover, IssueOtpChallenge $issueOtp, DispatchOtpChallenge $dispatch): JsonResponse
    {
        $validated = $request->validate(['contact_value' => ['required', 'string']]);

        // Always return a generic response to prevent enumeration
        $user = $recover->findUserByContact($validated['contact_value']);

        if ($user !== null) {
            $challenge = $issueOtp->handle(
                contactType: 'email',
                contactValue: $validated['contact_value'],
                purpose: OtpPurpose::PasswordRecovery,
                userId: $user->id,
            );

            $dispatch->handle($challenge);
        }

        return response()->json([
            'data' => [
                'message' => 'If the contact is associated with an account, a recovery code has been sent.',
            ],
        ]);
    }

    public function recoveryVerify(Request $request, RecoverPatientPassword $recover): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $result = $recover->handle(
            challengeId: $validated['challenge_id'],
            code: $validated['code'],
            newPassword: $validated['password'],
        );

        $user = $result['user'];
        $user->load('role', 'contacts');

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'user' => PatientAccountResource::make($user),
            ],
        ]);
    }
}
