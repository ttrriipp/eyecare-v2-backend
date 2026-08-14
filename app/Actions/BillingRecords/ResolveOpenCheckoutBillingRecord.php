<?php

namespace App\Actions\BillingRecords;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\BillingRecordStatus;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResolveOpenCheckoutBillingRecord
{
    /**
     * Resolve the open same-checkout Billing Record.
     *
     * Same-Patient, same-Encounter, unpaid records without posted payments
     * are reused when source relationships do not conflict.
     * Paid, partially paid, or voided records are never reopened.
     */
    public function handle(
        Patient $patient,
        ?JobOrder $jobOrder = null,
        ?Encounter $encounter = null,
        ?User $actor = null,
    ): BillingRecord {
        return DB::transaction(function () use ($patient, $jobOrder, $encounter, $actor) {
            // Look for an existing open billing record
            $existing = $this->findOpenRecord($patient, $jobOrder, $encounter);

            if ($existing !== null) {
                return $existing;
            }

            // Create a new billing record
            $billingRecord = BillingRecord::create([
                'patient_id' => $patient->id,
                'job_order_id' => $jobOrder?->id,
                'encounter_id' => $encounter?->id,
                'status' => BillingRecordStatus::Unpaid,
                'subtotal_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 0,
                'amount_paid' => 0,
                'balance_due' => 0,
                'recorded_by' => $actor?->id ?? auth()->id(),
                'recorded_at' => now(),
            ]);

            app(CreateAuditLog::class)->handle(
                subject: $billingRecord,
                action: AuditEvent::BillingRecordCreated,
                metadata: [
                    'patient_id' => $patient->id,
                    'job_order_id' => $jobOrder?->id,
                    'encounter_id' => $encounter?->id,
                ],
                actorId: $actor?->id ?? auth()->id(),
            );

            return $billingRecord;
        });
    }

    private function findOpenRecord(Patient $patient, ?JobOrder $jobOrder, ?Encounter $encounter): ?BillingRecord
    {
        $query = BillingRecord::query()
            ->where('patient_id', $patient->id)
            ->whereIn('status', [BillingRecordStatus::Unpaid, BillingRecordStatus::PartiallyPaid])
            ->whereNull('deleted_at')
            ->lockForUpdate();

        // Must have no posted payments
        $query->whereDoesntHave('payments', fn ($q) => $q->where('status', 'posted'));

        // Match source context
        if ($jobOrder !== null && $encounter !== null) {
            // Combined: must match both
            $query->where('job_order_id', $jobOrder->id)
                ->where('encounter_id', $encounter->id);
        } elseif ($jobOrder !== null) {
            // Optical-only: must match job order and no encounter
            $query->where('job_order_id', $jobOrder->id)
                ->whereNull('encounter_id');
        } elseif ($encounter !== null) {
            // Encounter caller with no specific Job Order in hand: reuse whatever
            // open record is already tied to this encounter, whether it originated
            // from a confirmed Quotation (has a job_order_id) or a direct charge
            // (does not) — a visit has one open checkout, not one per source.
            $query->where('encounter_id', $encounter->id);
        } else {
            return null;
        }

        return $query->first();
    }
}
