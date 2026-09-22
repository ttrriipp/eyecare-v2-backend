<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AccessoryOrderRequests\SubmitPaymentProof;
use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderPaymentProofController extends Controller
{
    public function store(Request $request, JobOrder $jobOrder, SubmitPaymentProof $submit): JsonResponse
    {
        if ($jobOrder->patient_id !== $request->user()->patient?->id) {
            abort(404);
        }

        $validated = $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240', 'dimensions:max_width=8000,max_height=8000'],
            'sender_name' => ['required', 'string', 'max:100'],
            'reference_number' => ['required', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'in:gcash,bank_transfer'],
        ]);

        try {
            $result = $submit->handle(
                account: $request->user(),
                order: $jobOrder,
                file: $validated['proof'],
                senderName: $validated['sender_name'],
                referenceNumber: $validated['reference_number'],
                paymentMethod: $validated['payment_method'] ?? 'gcash',
            );
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();

            if (! is_string($message)) {
                throw $exception;
            }

            $code = match (true) {
                str_contains($message, 'expired') => 'PAYMENT_WINDOW_EXPIRED',
                str_contains($message, 'not awaiting payment') => 'ORDER_NOT_AWAITING_PAYMENT',
                str_contains($message, 'not available for this order') => 'PAYMENT_METHOD_NOT_AVAILABLE',
                default => null,
            };

            if ($code === null) {
                throw $exception;
            }

            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ], 422);
        }

        $proof = $result['proof'];
        $status = $result['created'] ? 201 : 200;

        return response()->json([
            'data' => [
                'id' => $proof->id,
                'status' => $proof->status->value,
                'payment_method' => $proof->payment_method->value,
                'sender_name' => $proof->sender_name,
                'reference_number' => $proof->reference_number,
                'created_at' => $proof->created_at->toISOString(),
            ],
        ], $status);
    }
}
