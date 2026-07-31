<?php

namespace App\Http\Controllers\Api;

use App\Actions\PatientAccounts\SubmitPatientLinkRequest;
use App\Http\Controllers\Controller;
use App\Models\PatientLinkRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientLinkRequestController extends Controller
{
    public function store(Request $request, SubmitPatientLinkRequest $submit): JsonResponse
    {
        $linkRequest = $submit->handle($request->user());

        return response()->json([
            'data' => [
                'request_number' => $linkRequest->request_number,
                'status' => $linkRequest->status,
                'submitted_at' => $linkRequest->created_at->toISOString(),
            ],
        ], 201);
    }

    public function current(Request $request): JsonResponse
    {
        $linkRequest = PatientLinkRequest::where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->first();

        if ($linkRequest === null) {
            return response()->json(['data' => null], 204);
        }

        return response()->json([
            'data' => [
                'request_number' => $linkRequest->request_number,
                'status' => $linkRequest->status,
                'submitted_at' => $linkRequest->created_at->toISOString(),
                'reviewed_at' => $linkRequest->reviewed_at?->toISOString(),
            ],
        ]);
    }
}
