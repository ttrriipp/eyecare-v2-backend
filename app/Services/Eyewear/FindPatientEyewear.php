<?php

namespace App\Services\Eyewear;

use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Quotation;
use App\Models\QuotationRevision;

class FindPatientEyewear
{
    public function __construct(
        private readonly BuildEyewearAggregate $buildAggregate,
    ) {}

    /**
     * Find an eyewear aggregate by canonical key or jo_{id} alias.
     * Returns null if not found or outside patient scope.
     */
    public function handle(Patient $patient, string $key): ?EyewearAggregate
    {
        // Try alias first: jo_{job_order_id}
        if (str_starts_with($key, 'jo_')) {
            return $this->findByAlias($patient, $key);
        }

        return $this->findByCanonicalKey($patient, $key);
    }

    private function findByCanonicalKey(Patient $patient, string $key): ?EyewearAggregate
    {
        $jobOrder = $this->findJobOrder($patient, $key);
        $quotation = $this->findQuotation($patient, $key);

        if ($jobOrder === null && $quotation === null) {
            return null;
        }

        return $this->buildAggregate->handle($quotation, $jobOrder);
    }

    private function findByAlias(Patient $patient, string $alias): ?EyewearAggregate
    {
        $jobOrderId = (int) substr($alias, 3);

        if ($jobOrderId <= 0) {
            return null;
        }

        $jobOrder = JobOrder::query()
            ->where('patient_id', $patient->id)
            ->whereKey($jobOrderId)
            ->whereNull('deleted_at')
            ->with(['items', 'encounter.appointment', 'billingRecord.postedPayments'])
            ->first();

        if ($jobOrder === null) {
            return null;
        }

        // Find the linked quotation if it exists
        $quotation = null;

        if ($jobOrder->quotation_revision_id !== null) {
            $revision = QuotationRevision::query()
                ->whereKey($jobOrder->quotation_revision_id)
                ->first();

            if ($revision !== null) {
                $quotation = Quotation::query()
                    ->where('patient_id', $patient->id)
                    ->whereKey($revision->quotation_id)
                    ->whereNull('deleted_at')
                    ->with(['revisions.items', 'encounter.appointment'])
                    ->first();
            }
        }

        return $this->buildAggregate->handle($quotation, $jobOrder);
    }

    private function findJobOrder(Patient $patient, string $key): ?JobOrder
    {
        return JobOrder::query()
            ->where('patient_id', $patient->id)
            ->where('eyewear_key', $key)
            ->whereNull('deleted_at')
            ->with(['items', 'encounter.appointment', 'billingRecord.postedPayments'])
            ->first();
    }

    private function findQuotation(Patient $patient, string $key): ?Quotation
    {
        return Quotation::query()
            ->where('patient_id', $patient->id)
            ->where('eyewear_key', $key)
            ->whereNull('deleted_at')
            ->with(['revisions.items', 'encounter.appointment'])
            ->first();
    }
}
