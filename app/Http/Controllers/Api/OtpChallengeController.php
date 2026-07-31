<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\IssueOtpChallenge;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IssueOtpRequest;
use Illuminate\Http\JsonResponse;

class OtpChallengeController extends Controller
{
    public function issue(IssueOtpRequest $request, IssueOtpChallenge $issueOtp): JsonResponse
    {
        $challenge = $issueOtp->handle(
            contactType: $request->validated('contact_type'),
            contactValue: $request->validated('contact_value'),
            purpose: OtpPurpose::Registration,
        );

        return response()->json([
            'data' => [
                'challenge_id' => $challenge->public_id,
                'expires_at' => $challenge->expires_at->toISOString(),
            ],
        ], 200);
    }
}
