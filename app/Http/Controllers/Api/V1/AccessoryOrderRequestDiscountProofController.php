<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AccessoryOrderRequests\SubmitDiscountProof;
use App\Http\Controllers\Controller;
use App\Models\AccessoryOrderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccessoryOrderRequestDiscountProofController extends Controller
{
    public function store(
        Request $request,
        AccessoryOrderRequest $accessoryOrderRequest,
        SubmitDiscountProof $submit,
    ): JsonResponse {
        if (
            $accessoryOrderRequest->user_id !== $request->user()->id
            || $accessoryOrderRequest->patient_id !== $request->user()->patient?->id
        ) {
            abort(404);
        }

        $validated = $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240', 'dimensions:max_width=8000,max_height=8000'],
        ]);

        try {
            $result = $submit->handle(
                account: $request->user(),
                orderRequest: $accessoryOrderRequest,
                file: $validated['proof'],
            );
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();

            if (! is_string($message)) {
                throw $exception;
            }

            $code = str_contains($message, 'does not include a discount')
                ? 'DISCOUNT_PROOF_NOT_REQUESTED'
                : (str_contains($message, 'Only pending order requests')
                    ? 'ORDER_REQUEST_NOT_ACTIONABLE'
                    : null);

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

        return response()->json([
            'data' => [
                'id' => $proof->id,
                'status' => $proof->status->value,
                'created_at' => $proof->created_at->toISOString(),
            ],
        ], $result['created'] ? 201 : 200);
    }
}
