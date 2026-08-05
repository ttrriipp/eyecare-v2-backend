<?php

namespace App\Actions\BillingRecords;

use App\Enums\BillingItemSourceKind;
use App\Enums\TransactionItemType;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddDirectServiceChargesToBilling
{
    /**
     * Add direct Service charges to a Billing Record.
     *
     * Creates or reuses the open same-checkout Billing Record.
     * Direct services have no Quotation, Encounter, or Optical Order source.
     *
     * @param  array<int, array{description: string, quantity: int, unit_price: float, service_id?: int|null}>  $items
     */
    public function handle(
        Patient $patient,
        array $items,
        ?float $discountAmount = null,
        ?Carbon $paymentDueDate = null,
    ): BillingRecord {
        if (empty($items)) {
            throw ValidationException::withMessages([
                'items' => ['At least one service line is required.'],
            ]);
        }

        $this->assertServicesActive($items);

        /** @var User $recorder */
        $recorder = auth()->user();

        return DB::transaction(function () use ($patient, $items, $discountAmount, $paymentDueDate) {
            // Find or create the open checkout billing record (no encounter or job order)
            $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
                patient: $patient,
            );

            // Check if charges can be added (no posted payments)
            if ($billingRecord->payments()->where('status', 'posted')->exists()) {
                throw ValidationException::withMessages([
                    'billing_record' => ['Cannot add charges to a Billing Record with posted payments.'],
                ]);
            }

            // Add direct service items
            foreach ($items as $item) {
                $unitPriceInCents = (int) round(((float) $item['unit_price']) * 100);
                $amountInCents = $unitPriceInCents * (int) $item['quantity'];

                BillingRecordItem::create([
                    'billing_record_id' => $billingRecord->id,
                    'item_type' => TransactionItemType::Service,
                    'source_kind' => BillingItemSourceKind::DirectService,
                    'description' => trim($item['description']),
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => number_format($unitPriceInCents / 100, 2, '.', ''),
                    'amount' => number_format($amountInCents / 100, 2, '.', ''),
                    'encounter_id' => null,
                    'service_id' => $item['service_id'] ?? null,
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

    /**
     * @param  array<int, array{description: string, quantity: int, unit_price: float, service_id?: int|null}>  $items
     */
    private function assertServicesActive(array $items): void
    {
        $serviceIds = collect($items)->pluck('service_id')->filter()->unique();

        if ($serviceIds->isEmpty()) {
            return;
        }

        $activeCount = Service::query()->active()->whereIn('id', $serviceIds)->count();

        if ($activeCount !== $serviceIds->count()) {
            throw ValidationException::withMessages([
                'items' => ['One or more selected services are no longer available.'],
            ]);
        }
    }
}
