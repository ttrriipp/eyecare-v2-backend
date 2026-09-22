<?php

namespace App\Actions\AccessoryOrderRequests;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Actions\Notifications\NotifyPatientAccount;
use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\AuditEvent;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Enums\OrderPaymentMethod;
use App\Enums\OrderPaymentProofStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\OrderPaymentProof;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptPaymentProof
{
    public function __construct(
        private readonly CreateAuditLog $auditLog,
        private readonly NotifyPatientAccount $notifyPatientAccount,
    ) {}

    public function handle(
        OrderPaymentProof $proof,
        User $reviewer,
    ): OrderPaymentProof {
        $this->assertReviewer($reviewer);

        $paymentMethod = $proof->payment_method instanceof OrderPaymentMethod
            ? $proof->payment_method
            : OrderPaymentMethod::tryFrom((string) $proof->payment_method) ?? OrderPaymentMethod::GCash;
        $lockKey = 'payment-reference:'.$paymentMethod->value.':'.hash('sha256', mb_strtolower(trim((string) $proof->reference_number)));

        return Cache::lock($lockKey, 15)->block(5, function () use ($proof, $reviewer): OrderPaymentProof {
            return DB::transaction(function () use ($proof, $reviewer): OrderPaymentProof {
                $lockedProof = OrderPaymentProof::query()->lockForUpdate()->findOrFail($proof->id);

                if ($lockedProof->status !== OrderPaymentProofStatus::Pending) {
                    throw ValidationException::withMessages([
                        'proof' => ['Only pending proofs can be accepted.'],
                    ]);
                }

                $order = JobOrder::query()->lockForUpdate()->findOrFail($lockedProof->job_order_id);

                $orderRequest = AccessoryOrderRequest::query()
                    ->where('job_order_id', $order->id)
                    ->lockForUpdate()
                    ->first();

                if ($orderRequest === null || $orderRequest->status !== AccessoryOrderRequestStatus::Accepted) {
                    throw ValidationException::withMessages([
                        'order' => ['This order is not an accepted accessory order.'],
                    ]);
                }

                if ($order->status !== JobOrderStatus::PaymentReview) {
                    throw ValidationException::withMessages([
                        'order' => ['This order is not in payment review.'],
                    ]);
                }

                $billingRecord = BillingRecord::query()
                    ->where('job_order_id', $order->id)
                    ->lockForUpdate()
                    ->first();

                if ($billingRecord === null || $billingRecord->status === BillingRecordStatus::Cancelled) {
                    throw ValidationException::withMessages([
                        'order' => ['No active billing record found.'],
                    ]);
                }

                if ((float) $billingRecord->balance_due <= 0) {
                    throw ValidationException::withMessages([
                        'order' => ['This order has no outstanding balance.'],
                    ]);
                }

                $paymentMethod = $lockedProof->payment_method instanceof OrderPaymentMethod
                    ? $lockedProof->payment_method
                    : OrderPaymentMethod::tryFrom((string) $lockedProof->payment_method) ?? OrderPaymentMethod::GCash;

                // Payment references are scoped to their payment method.
                $duplicateRef = OrderPaymentProof::query()
                    ->where('reference_number', $lockedProof->reference_number)
                    ->where('payment_method', $paymentMethod->value)
                    ->where('status', OrderPaymentProofStatus::Accepted)
                    ->where('id', '!=', $lockedProof->id)
                    ->exists();

                $duplicatePostedPayment = BillingPayment::query()
                    ->where('reference_number', $lockedProof->reference_number)
                    ->where('payment_method', $paymentMethod->value)
                    ->where('status', 'posted')
                    ->exists();

                if ($duplicateRef || $duplicatePostedPayment) {
                    throw ValidationException::withMessages([
                        'reference_number' => ['This '.$paymentMethod->label().' reference has already been used.'],
                    ]);
                }

                // Record payment
                app(RecordBillingPayment::class)->handle(
                    billingRecord: $billingRecord,
                    amount: (float) $billingRecord->balance_due,
                    paymentMethod: $paymentMethod->value,
                    recorder: $reviewer,
                    referenceNumber: $lockedProof->reference_number,
                    notes: $paymentMethod->label().' payment verified for accessory order.',
                    chargesReviewed: true,
                    notifyPatient: false,
                );

                // Mark proof accepted
                $lockedProof->update([
                    'status' => OrderPaymentProofStatus::Accepted,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                ]);

                // Transition order to queued
                $order->update([
                    'status' => JobOrderStatus::Queued,
                    'payment_expires_at' => null,
                ]);

                $this->auditLog->handle(
                    subject: $lockedProof,
                    action: AuditEvent::PaymentProofAccepted,
                    metadata: [
                        'job_order_id' => $order->id,
                        'payment_method' => $paymentMethod->value,
                        'status' => OrderPaymentProofStatus::Accepted->value,
                    ],
                    actorId: $reviewer->id,
                );

                $lockedProof = $lockedProof->fresh(['jobOrder.patient']);
                $this->notifyPatientAccount->opticalOrderConfirmed($order->fresh(['patient']));

                return $lockedProof;
            });
        });
    }

    private function assertReviewer(User $reviewer): void
    {
        if (! $reviewer->is_active || (! $reviewer->isAdmin() && ! $reviewer->isStaff())) {
            throw ValidationException::withMessages([
                'reviewer' => ['Only active staff or administrators can accept payment proofs.'],
            ]);
        }
    }
}
