<?php

namespace App\Services\Eyewear;

use App\Enums\EyewearProgress;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Quotation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ListPatientEyewear
{
    public function __construct(
        private readonly BuildEyewearAggregate $buildAggregate,
    ) {}

    /**
     * List eyewear aggregates for a patient with deterministic ordering.
     *
     * @return LengthAwarePaginator<EyewearAggregate>
     */
    public function handle(
        Patient $patient,
        string $filter = 'current',
        int $perPage = 15,
    ): LengthAwarePaginator {
        // Collect candidate keys from both branches
        $jobOrderKeys = $this->jobOrderCandidateKeys($patient);
        $quotationOnlyKeys = $this->quotationOnlyCandidateKeys($patient, $jobOrderKeys);

        $allKeys = $jobOrderKeys->merge($quotationOnlyKeys)->unique();

        // Apply filter
        $filteredKeys = $allKeys->filter(function (string $key) use ($patient, $filter) {
            return $this->matchesFilter($patient, $key, $filter);
        });

        // Sort by activity_at DESC, key ASC
        $sorted = $filteredKeys->sort(function (string $a, string $b) use ($patient) {
            $activityA = $this->resolveActivityAt($patient, $a);
            $activityB = $this->resolveActivityAt($patient, $b);

            if ($activityA === $activityB) {
                return strcmp($a, $b);
            }

            return $activityB <=> $activityA;
        })->values();

        // Paginate
        $page = request()->input('page', 1);
        $offset = ($page - 1) * $perPage;
        $pageItems = $sorted->slice($offset, $perPage)->values();
        $total = $sorted->count();

        // Build aggregates for this page only
        $aggregates = $pageItems->map(function (string $key) use ($patient) {
            return $this->buildAggregateFor($patient, $key);
        })->filter()->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            items: $aggregates,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * Get eyewear keys from the Job Order branch.
     *
     * @return Collection<string>
     */
    private function jobOrderCandidateKeys(Patient $patient): Collection
    {
        return JobOrder::query()
            ->where('patient_id', $patient->id)
            ->whereNull('deleted_at')
            ->pluck('eyewear_key');
    }

    /**
     * Get eyewear keys from the estimate-only branch (quotations without a visible job order).
     *
     * @param  Collection<string>  $jobOrderKeys
     * @return Collection<string>
     */
    private function quotationOnlyCandidateKeys(Patient $patient, Collection $jobOrderKeys): Collection
    {
        return Quotation::query()
            ->where('patient_id', $patient->id)
            ->whereNull('deleted_at')
            ->whereNotIn('status', [QuotationStatus::Draft])
            ->whereNotIn('eyewear_key', $jobOrderKeys)
            ->pluck('eyewear_key');
    }

    private function matchesFilter(Patient $patient, string $key, string $filter): bool
    {
        $progress = $this->resolveProgress($patient, $key);

        if ($progress === null) {
            return false;
        }

        $currentProgresses = [
            EyewearProgress::EstimateAvailable,
            EyewearProgress::InPreparation,
            EyewearProgress::ReadyForPickup,
        ];

        $historyProgresses = [
            EyewearProgress::Dispensed,
            EyewearProgress::EstimateDeclined,
            EyewearProgress::EstimateExpired,
            EyewearProgress::Cancelled,
        ];

        if ($filter === 'current') {
            return in_array($progress, $currentProgresses, true);
        }

        return in_array($progress, $historyProgresses, true);
    }

    private function resolveProgress(Patient $patient, string $key): ?EyewearProgress
    {
        // Job Order progress takes precedence
        $jobOrder = JobOrder::query()
            ->where('patient_id', $patient->id)
            ->where('eyewear_key', $key)
            ->whereNull('deleted_at')
            ->first();

        if ($jobOrder !== null) {
            return match ($jobOrder->status) {
                JobOrderStatus::Queued, JobOrderStatus::InProgress => EyewearProgress::InPreparation,
                JobOrderStatus::ReadyForDispensing => EyewearProgress::ReadyForPickup,
                JobOrderStatus::Dispensed => EyewearProgress::Dispensed,
                JobOrderStatus::Cancelled => EyewearProgress::Cancelled,
            };
        }

        // Quotation-only
        $quotation = Quotation::query()
            ->where('patient_id', $patient->id)
            ->where('eyewear_key', $key)
            ->whereNull('deleted_at')
            ->first();

        if ($quotation === null) {
            return null;
        }

        return match ($quotation->status) {
            QuotationStatus::Presented, QuotationStatus::Accepted => EyewearProgress::EstimateAvailable,
            QuotationStatus::Declined => EyewearProgress::EstimateDeclined,
            QuotationStatus::Expired => EyewearProgress::EstimateExpired,
            QuotationStatus::Draft => null,
        };
    }

    private function resolveActivityAt(Patient $patient, string $key): string
    {
        $aggregate = $this->buildAggregateFor($patient, $key);

        return $aggregate?->activityAt ?? '1970-01-01T00:00:00+00:00';
    }

    private function buildAggregateFor(Patient $patient, string $key): ?EyewearAggregate
    {
        $jobOrder = JobOrder::query()
            ->where('patient_id', $patient->id)
            ->where('eyewear_key', $key)
            ->whereNull('deleted_at')
            ->with(['items', 'encounter.appointment', 'billingRecord.postedPayments'])
            ->first();

        $quotation = Quotation::query()
            ->where('patient_id', $patient->id)
            ->where('eyewear_key', $key)
            ->whereNull('deleted_at')
            ->with(['items', 'encounter.appointment'])
            ->first();

        if ($jobOrder === null && $quotation === null) {
            return null;
        }

        return $this->buildAggregate->handle($quotation, $jobOrder);
    }
}
