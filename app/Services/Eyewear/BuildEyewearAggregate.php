<?php

namespace App\Services\Eyewear;

use App\Enums\BillingRecordStatus;
use App\Enums\EyewearPaymentStatus;
use App\Enums\EyewearProgress;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\Quotation;

class BuildEyewearAggregate
{
    /**
     * Build an aggregate from a Quotation (estimate-only) or Job Order.
     * When a Job Order exists, it is the authoritative source.
     */
    public function handle(?Quotation $quotation, ?JobOrder $jobOrder): EyewearAggregate
    {
        $key = $jobOrder?->eyewear_key ?? $quotation?->eyewear_key;

        if ($key === null) {
            throw new \InvalidArgumentException('Cannot build aggregate without a source record.');
        }

        // Job Order is authoritative when present
        if ($jobOrder !== null) {
            return $this->fromJobOrder($jobOrder, $quotation);
        }

        return $this->fromQuotation($quotation);
    }

    private function fromJobOrder(JobOrder $jobOrder, ?Quotation $quotation): EyewearAggregate
    {
        $progress = $this->mapJobOrderProgress($jobOrder);
        $billingRecord = $this->findActiveBillingRecord($jobOrder);
        $paymentStatus = $this->mapPaymentStatus($billingRecord);
        $totalAmount = $this->resolveTotalAmount($billingRecord, $jobOrder, $quotation);
        $balanceDue = $this->resolveBalanceDue($billingRecord);
        $consultationAt = $this->resolveConsultationFromJobOrder($jobOrder);
        $createdAt = $this->resolveCreatedAt($quotation, $jobOrder);
        $activityAt = $this->resolveActivityAt($quotation, $jobOrder, $billingRecord);
        $description = $this->resolveDescription($jobOrder, $quotation);

        $estimate = $this->buildEstimateSection($quotation, $jobOrder);
        $preparation = $this->buildPreparationSection($jobOrder);
        $dispensing = $this->buildDispensingSection($jobOrder);
        $paymentSummary = $this->buildPaymentSummary($billingRecord);

        return new EyewearAggregate(
            key: $jobOrder->eyewear_key,
            description: $description,
            consultationAt: $consultationAt,
            createdAt: $createdAt,
            progress: $progress,
            paymentStatus: $paymentStatus,
            totalAmount: $totalAmount,
            balanceDue: $balanceDue,
            activityAt: $activityAt,
            estimate: $estimate,
            preparation: $preparation,
            dispensing: $dispensing,
            paymentSummary: $paymentSummary,
        );
    }

    private function fromQuotation(Quotation $quotation): EyewearAggregate
    {
        $progress = $this->mapQuotationProgress($quotation);
        $consultationAt = $this->resolveConsultationFromQuotation($quotation);
        $createdAt = $quotation->created_at?->toIso8601String() ?? now()->toIso8601String();
        $activityAt = $this->resolveQuotationActivityAt($quotation);
        $description = $this->resolveDescription(null, $quotation);

        $estimate = $this->buildEstimateSection($quotation, null);

        return new EyewearAggregate(
            key: $quotation->eyewear_key,
            description: $description,
            consultationAt: $consultationAt,
            createdAt: $createdAt,
            progress: $progress,
            paymentStatus: null,
            totalAmount: $this->resolveEstimateTotal($quotation),
            balanceDue: null,
            activityAt: $activityAt,
            estimate: $estimate,
            preparation: null,
            dispensing: null,
            paymentSummary: null,
        );
    }

    private function mapJobOrderProgress(JobOrder $jobOrder): EyewearProgress
    {
        return match ($jobOrder->status) {
            JobOrderStatus::Queued, JobOrderStatus::InProgress => EyewearProgress::InPreparation,
            JobOrderStatus::ReadyForDispensing => EyewearProgress::ReadyForPickup,
            JobOrderStatus::Dispensed => EyewearProgress::Dispensed,
            JobOrderStatus::Cancelled => EyewearProgress::Cancelled,
        };
    }

    private function mapQuotationProgress(Quotation $quotation): EyewearProgress
    {
        return match ($quotation->status) {
            QuotationStatus::Presented, QuotationStatus::Accepted => EyewearProgress::EstimateAvailable,
            QuotationStatus::Declined => EyewearProgress::EstimateDeclined,
            QuotationStatus::Expired => EyewearProgress::EstimateExpired,
            QuotationStatus::Draft => throw new \InvalidArgumentException('Draft quotations cannot produce an aggregate.'),
        };
    }

    private function findActiveBillingRecord(JobOrder $jobOrder): ?BillingRecord
    {
        return BillingRecord::query()
            ->where('job_order_id', $jobOrder->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', BillingRecordStatus::Voided)
            ->first();
    }

    private function mapPaymentStatus(?BillingRecord $billingRecord): ?EyewearPaymentStatus
    {
        if ($billingRecord === null) {
            return null;
        }

        return match ($billingRecord->status) {
            BillingRecordStatus::Unpaid, BillingRecordStatus::PartiallyPaid => EyewearPaymentStatus::BalanceDue,
            BillingRecordStatus::Paid => EyewearPaymentStatus::Paid,
            default => null,
        };
    }

    private function resolveTotalAmount(?BillingRecord $billingRecord, ?JobOrder $jobOrder, ?Quotation $quotation): string
    {
        // Precedence: Billing Record > Job Order > Estimate revision > fallback
        if ($billingRecord !== null) {
            return number_format((float) $billingRecord->total_amount, 2, '.', '');
        }

        if ($jobOrder !== null) {
            return number_format((float) $jobOrder->total_amount, 2, '.', '');
        }

        return $this->resolveEstimateTotal($quotation);
    }

    private function resolveEstimateTotal(?Quotation $quotation): string
    {
        if ($quotation === null) {
            return '0.00';
        }

        $revision = $quotation->latestRevision ?? $quotation->revisions()->orderBy('revision_number')->first();

        if ($revision !== null) {
            return number_format((float) $revision->total, 2, '.', '');
        }

        return '0.00';
    }

    private function resolveBalanceDue(?BillingRecord $billingRecord): ?string
    {
        if ($billingRecord === null) {
            return null;
        }

        return number_format((float) $billingRecord->balance_due, 2, '.', '');
    }

    private function resolveConsultationFromJobOrder(JobOrder $jobOrder): ?string
    {
        return $jobOrder->encounter?->appointment?->scheduled_at?->toIso8601String();
    }

    private function resolveConsultationFromQuotation(Quotation $quotation): ?string
    {
        return $quotation->encounter?->appointment?->scheduled_at?->toIso8601String();
    }

    private function resolveCreatedAt(?Quotation $quotation, ?JobOrder $jobOrder): string
    {
        if ($quotation !== null && $quotation->created_at !== null) {
            return $quotation->created_at->toIso8601String();
        }

        if ($jobOrder !== null && $jobOrder->created_at !== null) {
            return $jobOrder->created_at->toIso8601String();
        }

        return now()->toIso8601String();
    }

    private function resolveQuotationActivityAt(Quotation $quotation): string
    {
        $timestamps = collect([
            $quotation->created_at,
            $quotation->latestRevision?->presented_at,
            $quotation->latestRevision?->accepted_at,
        ])->filter()->values();

        return $timestamps->max()?->toIso8601String() ?? now()->toIso8601String();
    }

    private function resolveActivityAt(?Quotation $quotation, ?JobOrder $jobOrder, ?BillingRecord $billingRecord): string
    {
        $timestamps = collect();

        if ($quotation !== null) {
            $timestamps->push($quotation->created_at);
            $timestamps->push($quotation->latestRevision?->presented_at);
            $timestamps->push($quotation->latestRevision?->accepted_at);
        }

        if ($jobOrder !== null) {
            $timestamps->push($jobOrder->created_at);
            $timestamps->push($jobOrder->started_at);
            $timestamps->push($jobOrder->ready_at);
            $timestamps->push($jobOrder->dispensed_at);
            $timestamps->push($jobOrder->cancelled_at);
        }

        if ($billingRecord !== null) {
            $timestamps->push($billingRecord->recorded_at);

            $billingRecord->postedPayments()->each(function ($payment) use ($timestamps) {
                $timestamps->push($payment->recorded_at);
            });
        }

        $filtered = $timestamps->filter()->values();

        return $filtered->max()?->toIso8601String() ?? now()->toIso8601String();
    }

    private function resolveDescription(?JobOrder $jobOrder, ?Quotation $quotation): string
    {
        // Prefer job order items, then quotation revision items
        $items = $jobOrder?->items ?? collect();

        if ($items->isEmpty() && $quotation !== null) {
            $revision = $quotation->latestRevision ?? $quotation->revisions()->orderBy('revision_number')->first();
            $items = $revision?->items ?? collect();
        }

        if ($items->isNotEmpty()) {
            $first = $items->sortBy('id')->first();
            $description = $first->description ?? 'Eyewear transaction';
            $remaining = $items->count() - 1;

            if ($remaining > 0) {
                return "{$description} + {$remaining} more";
            }

            return $description;
        }

        return 'Eyewear transaction';
    }

    private function buildEstimateSection(?Quotation $quotation, ?JobOrder $jobOrder): ?array
    {
        if ($quotation === null) {
            return null;
        }

        if ($quotation->status === QuotationStatus::Draft) {
            return null;
        }

        // If a job order exists, use the exact revision it references
        $revision = null;

        if ($jobOrder !== null && $jobOrder->quotation_revision_id !== null) {
            $revision = $quotation->revisions()
                ->whereKey($jobOrder->quotation_revision_id)
                ->first();
        }

        // Otherwise use latest visible revision
        if ($revision === null) {
            $revision = $quotation->latestRevision ?? $quotation->revisions()->orderBy('revision_number')->first();
        }

        if ($revision === null) {
            return null;
        }

        $items = $revision->items->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
            'amount' => number_format((float) $item->amount, 2, '.', ''),
        ])->toArray();

        return [
            'quotation_number' => $quotation->quotation_number,
            'status' => $quotation->status->value,
            'valid_until' => $quotation->valid_until?->format('Y-m-d'),
            'subtotal' => number_format((float) $revision->subtotal, 2, '.', ''),
            'discount_amount' => number_format((float) $revision->discount_amount, 2, '.', ''),
            'total' => number_format((float) $revision->total, 2, '.', ''),
            'items' => $items,
        ];
    }

    private function buildPreparationSection(JobOrder $jobOrder): array
    {
        $items = $jobOrder->items->map(fn ($item) => [
            'id' => $item->id,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
            'amount' => number_format((float) $item->amount, 2, '.', ''),
            'product_variant_id' => $item->product_variant_id,
        ])->toArray();

        return [
            'job_order_number' => $jobOrder->job_order_number,
            'status' => $jobOrder->status->value,
            'total_amount' => number_format((float) $jobOrder->total_amount, 2, '.', ''),
            'started_at' => $jobOrder->started_at?->toIso8601String(),
            'ready_at' => $jobOrder->ready_at?->toIso8601String(),
            'items' => $items,
        ];
    }

    private function buildDispensingSection(JobOrder $jobOrder): ?array
    {
        if (! in_array($jobOrder->status, [JobOrderStatus::ReadyForDispensing, JobOrderStatus::Dispensed], true)) {
            return null;
        }

        return [
            'status' => $jobOrder->status->value,
            'ready_at' => $jobOrder->ready_at?->toIso8601String(),
            'dispensed_at' => $jobOrder->dispensed_at?->toIso8601String(),
        ];
    }

    private function buildPaymentSummary(?BillingRecord $billingRecord): ?array
    {
        if ($billingRecord === null) {
            return null;
        }

        $payments = $billingRecord->postedPayments()
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'payment_method' => $payment->payment_method,
                'reference_number' => $payment->reference_number,
                'recorded_at' => $payment->recorded_at?->toIso8601String(),
            ])
            ->toArray();

        return [
            'billing_record_number' => $billingRecord->billing_record_number,
            'status' => $billingRecord->status->value,
            'total_amount' => number_format((float) $billingRecord->total_amount, 2, '.', ''),
            'amount_paid' => number_format((float) $billingRecord->amount_paid, 2, '.', ''),
            'balance_due' => number_format((float) $billingRecord->balance_due, 2, '.', ''),
            'payments' => $payments,
        ];
    }
}
