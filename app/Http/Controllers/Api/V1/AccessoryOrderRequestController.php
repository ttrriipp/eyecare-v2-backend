<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AccessoryOrderRequests\CancelAccessoryOrderRequest;
use App\Actions\AccessoryOrderRequests\SubmitAccessoryOrderRequest;
use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\DiscountProofStatus;
use App\Http\Controllers\Controller;
use App\Models\AccessoryOrderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccessoryOrderRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filter' => ['nullable', 'string', 'in:current,history'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $account = $request->user();
        $filter = $validated['filter'] ?? 'current';

        $query = AccessoryOrderRequest::query()
            ->where('user_id', $account->id)
            ->where('patient_id', $account->patient?->id)
            ->with(['items', 'jobOrder.billingRecord', 'discountProof'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($filter === 'current') {
            $query->where(function ($q) {
                $q->where('status', AccessoryOrderRequestStatus::Pending)
                    ->orWhere(function ($q2) {
                        $q2->where('status', AccessoryOrderRequestStatus::Accepted)
                            ->whereHas('jobOrder', function ($q3) {
                                $q3->whereNotIn('status', ['dispensed', 'cancelled']);
                            });
                    });
            });
        } elseif ($filter === 'history') {
            $query->where(function ($q) {
                $q->whereIn('status', [AccessoryOrderRequestStatus::Rejected, AccessoryOrderRequestStatus::Cancelled])
                    ->orWhere(function ($q2) {
                        $q2->where('status', AccessoryOrderRequestStatus::Accepted)
                            ->whereHas('jobOrder', function ($q3) {
                                $q3->whereIn('status', ['dispensed', 'cancelled']);
                            });
                    });
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        $requests = $query->paginate($perPage);

        return response()->json([
            'data' => collect($requests->items())
                ->map(fn (AccessoryOrderRequest $r): array => $this->formatRequest($r))
                ->values()
                ->all(),
            'links' => [
                'first' => $requests->url(1),
                'last' => $requests->url($requests->lastPage()),
                'prev' => $requests->previousPageUrl(),
                'next' => $requests->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function store(Request $request, SubmitAccessoryOrderRequest $submit): JsonResponse
    {
        $validated = $request->validate([
            'requested_discount_type' => ['nullable', 'string', 'in:none,senior_citizen,pwd'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $request = $submit->handle(
            account: $request->user(),
            items: $validated['items'],
            requestedDiscountType: $validated['requested_discount_type'] ?? 'none',
        );

        return response()->json([
            'data' => $this->formatRequest($request->fresh(['items', 'discountProof'])),
        ], 201);
    }

    public function show(Request $request, AccessoryOrderRequest $accessoryOrderRequest): JsonResponse
    {
        if (
            $accessoryOrderRequest->user_id !== $request->user()->id
            || $accessoryOrderRequest->patient_id !== $request->user()->patient?->id
        ) {
            abort(404);
        }

        $accessoryOrderRequest->load(['items', 'jobOrder.billingRecord', 'discountProof']);

        return response()->json([
            'data' => $this->formatRequest($accessoryOrderRequest),
        ]);
    }

    public function cancel(Request $request, AccessoryOrderRequest $accessoryOrderRequest): JsonResponse
    {
        if (
            $accessoryOrderRequest->user_id !== $request->user()->id
            || $accessoryOrderRequest->patient_id !== $request->user()->patient?->id
        ) {
            abort(404);
        }

        try {
            $accessoryOrderRequest = app(CancelAccessoryOrderRequest::class)->handle(
                orderRequest: $accessoryOrderRequest,
                account: $request->user(),
            );
        } catch (ValidationException) {
            return response()->json([
                'error' => [
                    'code' => 'ORDER_REQUEST_NOT_ACTIONABLE',
                    'message' => 'Only pending requests can be cancelled.',
                ],
            ], 422);
        }

        return response()->json([
            'data' => $this->formatRequest($accessoryOrderRequest->fresh([
                'items',
                'jobOrder.billingRecord',
                'discountProof',
            ])),
        ]);
    }

    private function formatRequest(AccessoryOrderRequest $request): array
    {
        $discountProof = $request->discountProof;
        $discountProofStatus = $request->requested_discount_type === 'none'
            ? 'not_required'
            : ($discountProof?->status?->value ?? 'not_submitted');

        return [
            'id' => $request->id,
            'request_number' => $request->request_number,
            'status' => $request->status->value,
            'subtotal_amount' => number_format((float) $request->subtotal_amount, 2, '.', ''),
            'requested_discount_type' => $request->requested_discount_type,
            'discount_proof_status' => $discountProofStatus,
            'discount_proof_rejection_reason' => $discountProof?->status === DiscountProofStatus::Rejected
                ? $discountProof->rejection_reason
                : null,
            'resolved_by' => $request->resolved_by,
            'resolved_at' => $request->resolved_at?->toISOString(),
            'items' => $request->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                'amount' => number_format((float) $item->amount, 2, '.', ''),
                'product_variant_id' => $item->product_variant_id,
                'item_kind' => $item->item_kind?->value,
                'item_snapshot' => $item->item_snapshot,
            ]),
            'rejection_reason' => $request->rejection_reason,
            'cancelled_at' => $request->cancelled_at?->toISOString(),
            'created_at' => $request->created_at->toISOString(),
            'order' => $request->jobOrder ? [
                'id' => $request->jobOrder->id,
                'order_number' => $request->jobOrder->job_order_number,
                'status' => $request->jobOrder->status->value,
                'discount_amount' => $request->jobOrder->billingRecord
                    ? number_format((float) $request->jobOrder->billingRecord->discount_amount, 2, '.', '')
                    : null,
                'total_amount' => $request->jobOrder->billingRecord
                    ? number_format((float) $request->jobOrder->billingRecord->total_amount, 2, '.', '')
                    : null,
                'payment_expires_at' => $request->jobOrder->payment_expires_at?->toISOString(),
            ] : null,
        ];
    }
}
