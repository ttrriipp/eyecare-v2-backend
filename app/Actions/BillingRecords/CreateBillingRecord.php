<?php

namespace App\Actions\BillingRecords;

use App\Actions\OpticalOrders\CreateOpticalOrder as CreateOpticalOrderAction;
use App\Enums\BillingItemSourceKind;
use App\Enums\DiscountType;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Service;
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
        bool $discountEligibilityVerified = false,
        ?Carbon $paymentDueDate = null,
        ?string $notes = null,
    ): BillingRecord {
        $serviceItems ??= collect();
        $serviceItems = $this->applyCatalogServiceTaxTreatment($serviceItems, $creator);

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

        $resolvedDiscountType = DiscountType::tryFrom($discountType);

        if ($resolvedDiscountType === null) {
            throw ValidationException::withMessages([
                'discount_type' => ['Select a valid discount type.'],
            ]);
        }

        if ($resolvedDiscountType->isStatutory()) {
            if (
                $resolvedDiscountType === DiscountType::SeniorCitizen
                && (($patient->ageInYears() ?? 0) < DiscountType::SeniorCitizen->minimumAge())
            ) {
                throw ValidationException::withMessages([
                    'discount_type' => ['Senior Citizen discount requires the patient to be at least 60 years old.'],
                ]);
            }

            if (! $discountEligibilityVerified) {
                throw ValidationException::withMessages([
                    'discount_eligibility_verified' => ['Verify the patient’s entitlement and exclusive use before applying this discount.'],
                ]);
            }

            if (! collect($orderItems)->merge($serviceItems)->contains(
                fn (array $item): bool => (bool) ($item['statutory_discount_eligible'] ?? false),
            )) {
                throw ValidationException::withMessages([
                    'discount_type' => ['At least one legally qualifying line is required for a statutory discount.'],
                ]);
            }
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
            $discountEligibilityVerified,
            $resolvedDiscountType,
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
                    discountAmount: $serviceItems->isEmpty()
                        ? $this->discountForOrderValidation(
                            discountAmount: $discountAmount,
                            discountType: $discountType,
                            orderItems: $orderItems,
                        )
                        : null,
                    discountType: $serviceItems->isEmpty() ? $discountType : DiscountType::None->value,
                    discountEligibilityVerified: $serviceItems->isEmpty() && $discountEligibilityVerified,
                );
            } else {
                $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
                    patient: $patient,
                    encounter: $encounter,
                    actor: $creator,
                );
            }

            $billingRecord->update([
                'discount_type' => $discountType,
                'discount_eligibility_verified' => $discountEligibilityVerified,
                'discount_amount' => $resolvedDiscountType === DiscountType::Other ? ($discountAmount ?? 0) : 0,
            ]);

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
                discountAmount: $resolvedDiscountType === DiscountType::Other
                    ? ($discountAmount ?? (float) $billingRecord->discount_amount)
                    : null,
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
        bool $discountEligibilityVerified,
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
            discountEligibilityVerified: $discountEligibilityVerified,
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

    /**
     * Keep catalog service tax treatment controlled by its administrator-owned configuration.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function applyCatalogServiceTaxTreatment(Collection $items, User $creator): Collection
    {
        if ($creator->isAdmin()) {
            return $items;
        }

        return $items->map(function (array $item): array {
            if (blank($item['service_id'] ?? null)) {
                return $item;
            }

            $service = Service::query()->find((int) $item['service_id']);

            if ($service === null) {
                return $item;
            }

            return [
                ...$item,
                'vat_treatment' => $service->vat_treatment?->value ?? 'vatable',
                'statutory_discount_eligible' => (bool) $service->statutory_discount_eligible,
            ];
        });
    }
}
