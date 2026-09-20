<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AccessoryOrderRequests\SubmitPaymentProof;
use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderPaymentProofController extends Controller
{
    public function store(Request $request, JobOrder $jobOrder, SubmitPaymentProof $submit): JsonResponse
    {
        if ($jobOrder->patient_id !== $request->user()->patient?->id) {
            abort(404);
        }

        $validated = $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'sender_name' => ['required', 'string', 'max:100'],
            'reference_number' => ['required', 'string', 'max:100'],
        ]);

        $result = $submit->handle(
            account: $request->user(),
            order: $jobOrder,
            file: $validated['proof'],
            senderName: $validated['sender_name'],
            referenceNumber: $validated['reference_number'],
        );

        $proof = $result['proof'];
        $status = $result['created'] ? 201 : 200;

        return response()->json([
            'data' => [
                'id' => $proof->id,
                'status' => $proof->status->value,
                'sender_name' => $proof->sender_name,
                'reference_number' => $proof->reference_number,
                'created_at' => $proof->created_at->toISOString(),
            ],
        ], $status);
    }
}
