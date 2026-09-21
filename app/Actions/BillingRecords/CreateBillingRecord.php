<?php

namespace App\Actions\BillingRecords;

use App\Actions\OpticalOrders\CreateOpticalOrder as CreateOpticalOrderAction;
use App\Enums\BillingItemSourceKind;
use App\Enums\DiscountType;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBillingRecord
{
    /**
     * Create a bill from newly built optical-order items and/or services.
     *
     * Product items create a prepared optical order and its billing record.
     * Service-only submissions create a direct billing record without an order.
     *
     * @param  array<int, array<string, mixed>>  $orderItems
     * @param  Collection<int, array<string, mixed>>  $serviceItems
     */
    public function handle(
        Patient $patient,
        User $creator,
        array $orderItems = [],
        ?Prescription $prescription = null,
        ?Encounter $encounter = null,
        ?Collection $serviceItems = null,
        ?float $discountAmount = null,
        string $discountType = 'none',
        ?Carbon $paymentDueDate = null,
        ?string $notes = null,
    ): BillingRecord {
        $serviceItems ??= collect();

        if ($orderItems === [] && $serviceItems->isEmpty()) {
            throw ValidationException::withMessages([
                'bill' => ['Add at least one product or service line before creating the bill.'],
            ]);
        }

        if (! $creator->hasPanelRole()) {
            throw ValidationException::withMessages([
                'creator' => ['Only clinic staff can create a bill.'],
            ]);
        }

        if (($discountAmount ?? 0) > 0 && ! $creator->isAdmin()) {
            throw ValidationException::withMessages([
                'discount_amount' => ['Only an administrator can apply a discount.'],
            ]);
        }

        return DB::transaction(function () use (
            $patient,
            $creator,
            $orderItems,
            $prescription,
            $encounter,
            $serviceItems,
            $discountAmount,
            $discountType,
            $paymentDueDate,
            $notes,
        ): BillingRecord {
            if ($orderItems !== []) {
                $billingRecord = $this->createOpticalOrderBilling(
                    patient: $patient,
                    creator: $creator,
                    orderItems: $orderItems,
                    prescription: $prescription,
                    encounter: $encounter,
                    discountAmount: $this->discountForOrderValidation(
                        discountAmount: $discountAmount,
                        discountType: $discountType,
                        orderItems: $orderItems,
                    ),
                    discountType: $discountType,
                );
            } else {
                $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
                    patient: $patient,
                    encounter: $encounter,
                    actor: $creator,
                );
            }

            if ($serviceItems->isNotEmpty()) {
                $billingRecord = app(AddChargesToBilling::class)->handle(
                    billingRecord: $billingRecord,
                    sourceKind: BillingItemSourceKind::DirectService,
                    items: $serviceItems,
                    actor: $creator,
                );
            }

            $billingRecord = app(RecalculateBillingRecordTotals::class)->handle(
                billingRecord: $billingRecord,
                discountAmount: $discountAmount ?? (float) $billingRecord->discount_amount,
            );

            return $this->updateMetadata(
                billingRecord: $billingRecord,
                paymentDueDate: $paymentDueDate,
                notes: $notes,
            );
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $orderItems
     */
    private function createOpticalOrderBilling(
        Patient $patient,
        User $creator,
        array $orderItems,
        ?Prescription $prescription,
        ?Encounter $encounter,
        ?float $discountAmount,
        string $discountType,
    ): BillingRecord {
        $result = app(CreateOpticalOrderAction::class)->handle(
            patient: $patient,
            creator: $creator,
            items: $orderItems,
            fulfillmentMode: 'prepared',
            usesExternalSupplier: false,
            prescription: $prescription,
            encounter: $encounter,
            discountAmount: $discountAmount,
            discountType: $discountType,
        );

        return $result['billing_record'];
    }

    /**
     * The optical-order action validates custom discounts against product
     * subtotal before services are attached. The final bill recalculation below
     * validates the discount against the complete product-plus-service subtotal.
     *
     * @param  array<int, array<string, mixed>>  $orderItems
     */
    private function discountForOrderValidation(
        ?float $discountAmount,
        string $discountType,
        array $orderItems,
    ): ?float {
        if ($discountType !== DiscountType::Other->value || $discountAmount === null) {
            return $discountAmount;
        }

        $productSubtotal = collect($orderItems)->sum(
            fn (array $item): float => ((float) ($item['quantity'] ?? 0))
                * ((float) ($item['unit_price'] ?? 0)),
        );

        return min($discountAmount, $productSubtotal);
    }

    private function updateMetadata(
        BillingRecord $billingRecord,
        ?Carbon $paymentDueDate,
        ?string $notes,
    ): BillingRecord {
        if ($paymentDueDate === null && blank($notes)) {
            return $billingRecord;
        }

        $updates = [];

        if ($paymentDueDate !== null) {
            $updates['payment_due_date'] = $paymentDueDate->toDateString();
        }

        if (filled($notes)) {
            $updates['notes'] = trim($notes);
        }

        if ($updates !== []) {
            $billingRecord->update($updates);
        }

        return $billingRecord->fresh();
    }
}
