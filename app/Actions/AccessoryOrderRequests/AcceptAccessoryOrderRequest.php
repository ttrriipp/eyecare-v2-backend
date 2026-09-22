<?php

namespace App\Actions\AccessoryOrderRequests;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\BillingRecords\AddChargesToBilling;
use App\Actions\BillingRecords\RecalculateBillingRecordTotals;
use App\Actions\BillingRecords\ResolveOpenCheckoutBillingRecord;
use App\Actions\Notifications\NotifyPatientAccount;
use App\Actions\OpticalOrders\BuildOpticalOrder;
use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\AuditEvent;
use App\Enums\BillingItemSourceKind;
use App\Enums\DiscountProofStatus;
use App\Enums\JobOrderStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\JobOrder;
use App\Models\User;
use App\Services\Payments\PaymentInstructionCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptAccessoryOrderRequest
{
    public function __construct(
        private readonly BuildOpticalOrder $buildOrder,
        private readonly ResolveOpenCheckoutBillingRecord $resolveBilling,
        private readonly AddChargesToBilling $addCharges,
        private readonly CreateAuditLog $auditLog,
        private readonly NotifyPatientAccount $notifyPatientAccount,
        private readonly PaymentInstructionCatalog $paymentInstructions,
    ) {}

    /**
     * Accept an accessory order request, creating a pending-payment Optical Order.
     *
     * @return array{request: AccessoryOrderRequest, order: JobOrder}
     */
    public function handle(
        AccessoryOrderRequest $orderRequest,
        User $reviewer,
        ?float $discountAmount = null,
    ): array {
        $this->assertReviewer($reviewer);

        return DB::transaction(function () use ($orderRequest, $reviewer, $discountAmount): array {
            // Lock and recheck
            $lockedRequest = AccessoryOrderRequest::query()
                ->lockForUpdate()
                ->findOrFail($orderRequest->id);

            if ($discountAmount !== null && $discountAmount < 0) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Discount cannot be negative.'],
                ]);
            }

            // Idempotent: return existing order if already accepted
            if ($lockedRequest->status === AccessoryOrderRequestStatus::Accepted && $lockedRequest->jobOrder !== null) {
                return [
                    'request' => $lockedRequest,
                    'order' => $lockedRequest->jobOrder,
                ];
            }

            if (! $lockedRequest->isPending()) {
                throw ValidationException::withMessages([
                    'request' => ['Only pending requests can be accepted.'],
                ]);
            }

            $account = User::query()
                ->lockForUpdate()
                ->with('patient')
                ->findOrFail($lockedRequest->user_id);

            if ($account->patient?->id !== $lockedRequest->patient_id) {
                throw ValidationException::withMessages([
                    'request' => ['The request is no longer linked to the same patient account.'],
                ]);
            }

            if ($lockedRequest->requested_discount_type !== 'none') {
                $discountProof = $lockedRequest->discountProof()
                    ->lockForUpdate()
                    ->first();

                if ($discountProof === null || $discountProof->status !== DiscountProofStatus::Accepted) {
                    throw ValidationException::withMessages([
                        'discount_proof' => ['A verified discount proof is required before accepting this request.'],
                    ]);
                }
            }

            if ($discountAmount !== null
                && $discountAmount > 0
                && ! $reviewer->isAdmin()) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Only administrators can apply a positive discount.'],
                ]);
            }

            if ($discountAmount !== null && $discountAmount > (float) $lockedRequest->subtotal_amount) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Discount cannot exceed the request subtotal.'],
                ]);
            }

            // Revalidate items: active accessory with sufficient usable stock
            $items = $lockedRequest->items()->lockForUpdate()->get();
            $itemSnapshots = collect();

            foreach ($items as $item) {
                $variant = $item->productVariant;

                if ($variant === null || ! $variant->is_active) {
                    throw ValidationException::withMessages([
                        'items' => ["Variant for {$item->description} is no longer available."],
                    ]);
                }

                if (
                    $variant->product === null
                    || ! $variant->product->is_active
                    || $variant->product->product_type !== 'accessory'
                    || ! $variant->product->brand?->is_active
                    || ($variant->product->category !== null && ! $variant->product->category->is_active)
                ) {
                    throw ValidationException::withMessages([
                        'items' => ["{$item->description} is no longer an active accessory."],
                    ]);
                }

                if (($variant->usableStockQuantity() ?? 0) < $item->quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for {$item->description}."],
                    ]);
                }

                $itemSnapshots->push([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                    'product_variant_id' => $item->product_variant_id,
                    'item_kind' => $item->item_kind,
                    'item_snapshot' => $item->item_snapshot,
                ]);
            }

            // Create pending-payment Optical Order
            $jobOrder = $this->buildOrder->handle(
                patientId: $lockedRequest->patient_id,
                encounterId: null,
                prescriptionId: null,
                fulfillmentMode: 'prepared',
                usesExternalSupplier: false,
                items: $itemSnapshots,
                actorId: $reviewer->id,
                notifyPatient: false,
            );

            // Set to pending_payment with 30-minute deadline
            $jobOrder->update([
                'status' => JobOrderStatus::PendingPayment,
                'payment_expires_at' => Carbon::now(config('app.timezone'))->addMinutes(30),
            ]);

            // Resolve Billing Record
            $billingRecord = $this->resolveBilling->handle(
                patient: $lockedRequest->patient,
                jobOrder: $jobOrder,
                actor: $reviewer,
            );

            $jobOrderItemsByVariant = $jobOrder->items()
                ->orderBy('id')
                ->get()
                ->keyBy('product_variant_id');

            // Add charges
            $this->addCharges->handle(
                billingRecord: $billingRecord,
                sourceKind: BillingItemSourceKind::OpticalOrder,
                items: $itemSnapshots->map(function (array $item) use ($jobOrderItemsByVariant): array {
                    $jobOrderItem = $jobOrderItemsByVariant->get($item['product_variant_id']);

                    if ($jobOrderItem === null) {
                        throw new \LogicException('The created order item could not be linked to its billing charge.');
                    }

                    return [
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'amount' => $item['amount'],
                        'job_order_item_id' => $jobOrderItem->id,
                    ];
                }),
                actor: $reviewer,
            );

            // Apply discount if provided
            if ($discountAmount !== null && $discountAmount > 0) {
                $billingRecord->update(['discount_amount' => $discountAmount]);
                app(RecalculateBillingRecordTotals::class)->handle(
                    $billingRecord,
                    discountAmount: $discountAmount,
                );
            }

            $jobOrder->update([
                'payment_instructions' => $this->paymentInstructions->snapshot(
                    orderReference: $jobOrder->job_order_number,
                    amount: (float) $billingRecord->fresh()->balance_due,
                    expiresAt: $jobOrder->payment_expires_at,
                ),
            ]);

            // Mark request accepted
            $lockedRequest->update([
                'status' => AccessoryOrderRequestStatus::Accepted,
                'job_order_id' => $jobOrder->id,
                'resolved_by' => $reviewer->id,
                'resolved_at' => now(),
            ]);

            $this->auditLog->handle(
                subject: $lockedRequest,
                action: AuditEvent::AccessoryOrderRequestAccepted,
                metadata: [
                    'patient_id' => $lockedRequest->patient_id,
                    'job_order_id' => $jobOrder->id,
                    'billing_record_id' => $billingRecord->id,
                    'item_count' => $items->count(),
                    'subtotal' => $lockedRequest->subtotal_amount,
                    'discount_amount' => $discountAmount ?? 0,
                ],
                actorId: $reviewer->id,
            );

            $this->notifyPatientAccount->accessoryOrderRequestAccepted(
                request: $lockedRequest->fresh(['patient']),
                order: $jobOrder->fresh(['patient']),
            );

            return [
                'request' => $lockedRequest->fresh(['items', 'jobOrder']),
                'order' => $jobOrder,
            ];
        });
    }

    private function assertReviewer(User $reviewer): void
    {
        if (! $reviewer->is_active || (! $reviewer->isAdmin() && ! $reviewer->isStaff())) {
            throw ValidationException::withMessages([
                'reviewer' => ['Only active staff or administrators can accept requests.'],
            ]);
        }
    }
}
