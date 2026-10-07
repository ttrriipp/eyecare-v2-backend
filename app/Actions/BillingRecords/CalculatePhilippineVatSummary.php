<?php

namespace App\Actions\BillingRecords;

use App\Enums\DiscountType;
use App\Enums\VatTreatment;
use Illuminate\Validation\ValidationException;

class CalculatePhilippineVatSummary
{
    /**
     * @param  array<int, array{amount: int|float|string, vat_treatment: VatTreatment|string, statutory_discount_eligible?: bool|int|string|null}>  $items
     * @return array{subtotal_cents: int, vatable_sales_cents: int, vat_amount_cents: int, vat_exempt_sales_cents: int, zero_rated_sales_cents: int, discount_cents: int, vat_exemption_cents: int, total_cents: int}
     */
    public function handle(
        array $items,
        DiscountType $discountType = DiscountType::None,
        float $discountAmount = 0,
    ): array {
        $normalizedItems = [];
        $subtotalCents = 0;

        foreach ($items as $index => $item) {
            if (
                ! isset($item['amount'])
                || ! is_numeric($item['amount'])
                || ! is_finite((float) $item['amount'])
                || (float) $item['amount'] < 0
            ) {
                throw ValidationException::withMessages([
                    "items.{$index}.amount" => ['Each line amount must be a non-negative number.'],
                ]);
            }

            $rawTreatment = $item['vat_treatment'] ?? null;
            $treatment = $rawTreatment instanceof VatTreatment
                ? $rawTreatment
                : VatTreatment::tryFrom((string) $rawTreatment);

            if ($treatment === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.vat_treatment" => ['Select a valid VAT treatment for each line.'],
                ]);
            }

            $amountCents = (int) round((float) $item['amount'] * 100, 0, PHP_ROUND_HALF_UP);

            $normalizedItems[] = [
                'amount_cents' => $amountCents,
                'vat_treatment' => $treatment,
                'statutory_discount_eligible' => filter_var(
                    $item['statutory_discount_eligible'] ?? false,
                    FILTER_VALIDATE_BOOLEAN,
                ),
            ];
            $subtotalCents += $amountCents;
        }

        $customDiscountCents = 0;

        if ($discountType === DiscountType::Other) {
            if (! is_finite($discountAmount) || $discountAmount < 0) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Discount must be a finite, non-negative amount.'],
                ]);
            }

            $customDiscountCents = (int) round($discountAmount * 100, 0, PHP_ROUND_HALF_UP);

            if ($customDiscountCents > $subtotalCents) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Discount cannot exceed subtotal.'],
                ]);
            }
        }

        $allocatedDiscounts = $this->allocateDiscount($normalizedItems, $customDiscountCents, $subtotalCents);
        $vatableGrossCents = 0;
        $statutoryVatableGrossCents = 0;
        $vatExemptSalesCents = 0;
        $zeroRatedSalesCents = 0;
        $statutoryOtherSalesCents = 0;

        foreach ($normalizedItems as $index => $item) {
            $amountCents = $item['amount_cents'] - ($allocatedDiscounts[$index] ?? 0);

            if ($discountType->isStatutory() && $item['statutory_discount_eligible']) {
                match ($item['vat_treatment']) {
                    VatTreatment::Vatable => $statutoryVatableGrossCents += $amountCents,
                    VatTreatment::Exempt => $vatExemptSalesCents += $amountCents,
                    VatTreatment::ZeroRated => $zeroRatedSalesCents += $amountCents,
                };

                if ($item['vat_treatment'] !== VatTreatment::Vatable) {
                    $statutoryOtherSalesCents += $amountCents;
                }

                continue;
            }

            match ($item['vat_treatment']) {
                VatTreatment::Vatable => $vatableGrossCents += $amountCents,
                VatTreatment::Exempt => $vatExemptSalesCents += $amountCents,
                VatTreatment::ZeroRated => $zeroRatedSalesCents += $amountCents,
            };
        }

        $vatableSalesCents = $this->vatExclusiveAmount($vatableGrossCents);
        $vatAmountCents = $vatableGrossCents - $vatableSalesCents;
        $statutoryVatableSalesCents = $this->vatExclusiveAmount($statutoryVatableGrossCents);
        $vatExemptionCents = $statutoryVatableGrossCents - $statutoryVatableSalesCents;
        $vatExemptSalesCents += $statutoryVatableSalesCents;
        $statutoryDiscountCents = $this->percentageAmount(
            $statutoryVatableSalesCents + $statutoryOtherSalesCents,
            20,
        );
        $discountCents = $discountType->isStatutory()
            ? $statutoryDiscountCents
            : $customDiscountCents;
        $totalCents = $subtotalCents - $customDiscountCents - $vatExemptionCents - $statutoryDiscountCents;

        return [
            'subtotal_cents' => $subtotalCents,
            'vatable_sales_cents' => $vatableSalesCents,
            'vat_amount_cents' => $vatAmountCents,
            'vat_exempt_sales_cents' => $vatExemptSalesCents,
            'zero_rated_sales_cents' => $zeroRatedSalesCents,
            'discount_cents' => $discountCents,
            'vat_exemption_cents' => $vatExemptionCents,
            'total_cents' => max($totalCents, 0),
        ];
    }

    /**
     * @param  array<int, array{amount_cents: int, vat_treatment: VatTreatment, statutory_discount_eligible: bool}>  $items
     * @return array<int, int>
     */
    private function allocateDiscount(array $items, int $discountCents, int $subtotalCents): array
    {
        if ($discountCents === 0 || $subtotalCents === 0) {
            return [];
        }

        $allocations = [];
        $remainders = [];
        $allocatedCents = 0;

        foreach ($items as $index => $item) {
            $exactShare = ($item['amount_cents'] / $subtotalCents) * $discountCents;
            $share = (int) floor($exactShare);
            $allocations[$index] = $share;
            $allocatedCents += $share;
            $remainders[$index] = $exactShare - $share;
        }

        uksort($remainders, fn (int $left, int $right): int => ($remainders[$right] <=> $remainders[$left]) ?: ($left <=> $right));
        $lineIndexes = array_keys($remainders);

        for ($remainingCents = $discountCents - $allocatedCents, $index = 0; $remainingCents > 0; $remainingCents--, $index++) {
            $lineIndex = $lineIndexes[$index % count($lineIndexes)];
            $allocations[$lineIndex]++;
        }

        ksort($allocations);

        return $allocations;
    }

    private function vatExclusiveAmount(int $vatInclusiveCents): int
    {
        return intdiv(($vatInclusiveCents * 100) + 56, 112);
    }

    private function percentageAmount(int $amountCents, int $percentage): int
    {
        return intdiv(($amountCents * $percentage) + 50, 100);
    }
}
