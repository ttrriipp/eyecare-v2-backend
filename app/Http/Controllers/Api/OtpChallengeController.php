<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\DispatchOtpChallenge;
use App\Actions\Auth\IssueOtpChallenge;
use App\Actions\Auth\VerifyOtpChallenge;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IssueOtpRequest;
use App\Http\Requests\Api\VerifyOtpRequest;
use Illuminate\Http\JsonResponse;

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
}
