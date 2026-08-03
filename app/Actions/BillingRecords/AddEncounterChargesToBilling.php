<?php

namespace App\Actions\BillingRecords;

use App\Enums\TransactionItemType;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddEncounterChargesToBilling
{
    /**
     * Add Encounter service charges to a Billing Record.
     *
     * Creates or reuses the open same-checkout Billing Record.
     * Encounter-only charges create itemized Billing Records with matching
     * Patient and Encounter source.
     *
     * @param  array<int, array{description: string, quantity: int, unit_price: float}>  $items
     */
    public function handle(
        Encounter $encounter,
        array $items,
        ?float $discountAmount = null,
        ?Carbon $paymentDueDate = null,
    ): BillingRecord {
        if (empty($items)) {
            throw ValidationException::withMessages([
                'items' => ['At least one service line is required.'],
            ]);
        }

        /** @var User $recorder */
        $recorder = auth()->user();

        return DB::transaction(function () use ($encounter, $items, $discountAmount, $paymentDueDate) {
            // Find or create the open checkout billing record
            $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
                patient: $encounter->patient,
                encounter: $encounter,
            );

            // Check if charges can be added (no posted payments)
            if ($billingRecord->payments()->where('status', 'posted')->exists()) {
                throw ValidationException::withMessages([
                    'billing_record' => ['Cannot add charges to a Billing Record with posted payments.'],
                ]);
            }

            // Add encounter items
            foreach ($items as $item) {
                $unitPriceInCents = (int) round(((float) $item['unit_price']) * 100);
                $amountInCents = $unitPriceInCents * (int) $item['quantity'];

                BillingRecordItem::create([
                    'billing_record_id' => $billingRecord->id,
                    'item_type' => TransactionItemType::Service,
                    'description' => trim($item['description']),
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => number_format($unitPriceInCents / 100, 2, '.', ''),
                    'amount' => number_format($amountInCents / 100, 2, '.', ''),
                    'encounter_id' => $encounter->id,
                ]);
            }

            // Set payment due date if provided
            if ($paymentDueDate !== null) {
                $billingRecord->update(['payment_due_date' => $paymentDueDate]);
            }

            // Recalculate totals
            $billingRecord->refresh();
            app(RecalculateBillingRecordTotals::class)->handle(
                $billingRecord,
                discountAmount: $discountAmount ?? (float) $billingRecord->discount_amount,
            );

            return $billingRecord->fresh();
        });
    }
}
