<?php

namespace App\Actions\OpticalOrders;

use App\Actions\BillingRecords\AddChargesToBilling;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Actions\BillingRecords\ResolveOpenCheckoutBillingRecord;
use App\Enums\BillingItemSourceKind;
use App\Enums\DiscountType;
use App\Enums\VatTreatment;
use App\Models\BillingRecord;
use App\Models\DispensingEvent;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateOpticalOrder
{
    public function __construct(
        private readonly BuildOpticalOrder $buildOrder,
    ) {}

    /**
     * Create an Optical Order with billing.
     *
     * @param  array<int, array{description: string, quantity: int, unit_price: float, product_variant_id?: int|null, lens_category_id?: int|null}>  $items
     * @return array{job_order: JobOrder, billing_record: BillingRecord, dispensing_event: ?DispensingEvent}
     */
    public function handle(
        Patient $patient,
        User $creator,
        array $items,
        string $fulfillmentMode = 'prepared',
        bool $usesExternalSupplier = false,
        ?Prescription $prescription = null,
        ?Encounter $encounter = null,
        ?Carbon $paymentDueDate = null,
        ?float $depositAmount = null,
        ?string $depositPaymentMethod = null,
        ?string $depositReference = null,
        ?string $recipientName = null,
        ?float $discountAmount = null,
        ?string $discountType = null,
        bool $discountEligibilityVerified = false,
    ): array {
        if (! $creator->hasPanelRole()) {
            throw ValidationException::withMessages([
                'creator' => ['Only clinic staff can create an optical order.'],
            ]);
        }

        if (! in_array($fulfillmentMode, ['immediate', 'prepared'], true)) {
            throw ValidationException::withMessages([
                'fulfillment_mode' => ['Fulfillment mode must be immediate or prepared.'],
            ]);
        }

        $validatedItems = $this->validateItems(
            $items,
            allowCatalogTaxOverrides: $creator->isAdmin(),
        );

        $discountType = DiscountType::tryFrom(
            $discountType ?? ($discountAmount !== null
                ? DiscountType::Other->value
                : DiscountType::None->value),
        );

        if ($discountType === null) {
            throw ValidationException::withMessages([
                'discount_type' => ['Select a valid discount type.'],
            ]);
        }

        if ($discountType === DiscountType::SeniorCitizen && ! $this->isSeniorCitizenEligible($patient)) {
            throw ValidationException::withMessages([
                'discount_type' => ['Senior Citizen discount requires the patient to be at least 60 years old.'],
            ]);
        }

        if ($discountType->isStatutory()) {
            if (! $discountEligibilityVerified) {
                throw ValidationException::withMessages([
                    'discount_eligibility_verified' => ['Verify the patient’s entitlement and exclusive use before applying this discount.'],
                ]);
            }

            if (! collect($validatedItems)->contains(
                fn (array $item): bool => (bool) ($item['statutory_discount_eligible'] ?? false),
            )) {
                throw ValidationException::withMessages([
                    'discount_type' => ['Mark at least one legally qualifying line before applying this statutory discount.'],
                ]);
            }
        }

        $discountAmount = $this->resolveDiscountAmount(
            discountType: $discountType,
            requestedAmount: $discountAmount,
            items: $validatedItems,
            creator: $creator,
        );

        $hasCorrectiveItems = collect($validatedItems)->contains(
            fn (array $item): bool => filled($item['lens_category_id'] ?? null),
        );

        if ($hasCorrectiveItems) {
            if ($prescription === null) {
                throw ValidationException::withMessages([
                    'prescription' => ['A current prescription is required when the order includes corrective eyewear.'],
                ]);
            }

            if ($prescription->patient_id !== $patient->id || ! $prescription->isCurrentVersion()) {
                throw ValidationException::withMessages([
                    'prescription' => ['The selected prescription is not this patient\'s current prescription.'],
                ]);
            }

            // Corrective eyewear still needs its lens ground and fitted — it
            // cannot be marked dispensed at the moment of order creation.
            if ($fulfillmentMode === 'immediate') {
                throw ValidationException::withMessages([
                    'fulfillment_mode' => ['Corrective eyewear must be prepared before dispensing — it cannot be completed immediately.'],
                ]);
            }
        }

        app(ValidateOpticalOrderItems::class)->handle(
            items: collect($validatedItems)->map(function (array $item): array {
                $snapshot = app(BuildOpticalItemSnapshot::class)->handle(
                    productVariantId: $item['product_variant_id'] ?? null,
                    lensCategoryId: $item['lens_category_id'] ?? null,
                    lensOptionId: $item['lens_option_id'] ?? null,
                );

                return [
                    'item_kind' => $snapshot['item_kind'],
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'lens_option_id' => $item['lens_option_id'] ?? null,
                    'quantity' => $item['quantity'],
                ];
            })->values(),
            patient: $patient,
            prescription: $prescription,
            allowMultipleFrameQuantity: true,
        );

        return DB::transaction(function () use ($patient, $validatedItems, $fulfillmentMode, $usesExternalSupplier, $prescription, $encounter, $paymentDueDate, $depositAmount, $depositPaymentMethod, $depositReference, $recipientName, $discountAmount, $discountType, $discountEligibilityVerified, $creator) {
            $itemSnapshots = collect($validatedItems)->map(function (array $item): array {
                $unitPriceInCents = (int) round(((float) $item['unit_price']) * 100);
                $amountInCents = $unitPriceInCents * (int) $item['quantity'];

                $snapshotResult = app(BuildOpticalItemSnapshot::class)->handle(
                    productVariantId: $item['product_variant_id'] ?? null,
                    lensCategoryId: $item['lens_category_id'] ?? null,
                    lensOptionId: $item['lens_option_id'] ?? null,
                );

                return [
                    'description' => trim($item['description']),
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => $this->formatMoney($unitPriceInCents),
                    'amount' => $this->formatMoney($amountInCents),
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'lens_category_id' => $item['lens_category_id'] ?? null,
                    'lens_option_id' => $item['lens_option_id'] ?? null,
                    'item_kind' => $snapshotResult['item_kind'],
                    'item_snapshot' => $snapshotResult['item_snapshot'],
                    'vat_treatment' => $item['vat_treatment'],
                    'statutory_discount_eligible' => $item['statutory_discount_eligible'],
                ];
            });

            $jobOrder = $this->buildOrder->handle(
                patientId: $patient->id,
                encounterId: $encounter?->id,
                prescriptionId: $prescription?->id,
                fulfillmentMode: $fulfillmentMode,
                usesExternalSupplier: $usesExternalSupplier,
                items: $itemSnapshots,
                dispensedBy: $creator->id,
                actorId: $creator->id,
            );

            if ($fulfillmentMode === 'immediate' && $recipientName !== null) {
                $jobOrder->dispensingEvents()->latest()->first()?->update([
                    'recipient_name' => $recipientName,
                ]);
            }

            $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
                patient: $patient,
                jobOrder: $jobOrder,
                encounter: $encounter,
                actor: $creator,
            );

            $billingRecord->update([
                'discount_type' => $discountType,
                'discount_eligibility_verified' => $discountEligibilityVerified,
                'discount_amount' => $discountType === DiscountType::Other ? ($discountAmount ?? 0) : 0,
            ]);

            $orderItems = $jobOrder->items()
                ->orderBy('id')
                ->get()
                ->values()
                ->map(function ($item, int $index) use ($itemSnapshots): array {
                    $snapshot = $itemSnapshots->get($index);

                    return [
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'amount' => $item->amount,
                        'job_order_item_id' => $item->id,
                        'vat_treatment' => $snapshot['vat_treatment'] ?? VatTreatment::Vatable->value,
                        'statutory_discount_eligible' => (bool) ($snapshot['statutory_discount_eligible'] ?? false),
                    ];
                });

            app(AddChargesToBilling::class)->handle(
                billingRecord: $billingRecord,
                sourceKind: BillingItemSourceKind::OpticalOrder,
                items: $orderItems,
                discountAmount: $discountAmount,
                actor: $creator,
            );

            if ($paymentDueDate !== null) {
                $billingRecord->update(['payment_due_date' => $paymentDueDate]);
            }

            if ($depositAmount !== null && $depositAmount > 0) {
                app(RecordBillingPayment::class)->handle(
                    billingRecord: $billingRecord,
                    amount: $depositAmount,
                    paymentMethod: $depositPaymentMethod ?? 'cash',
                    recorder: $creator,
                    referenceNumber: $depositReference,
                    notes: 'Initial deposit at order creation',
                    chargesReviewed: true,
                    notifyPatient: false,
                );
            }

            $dispensingEvent = $fulfillmentMode === 'immediate'
                ? $jobOrder->dispensingEvents()->latest()->first()
                : null;

            return [
                'job_order' => $jobOrder->fresh(),
                'billing_record' => $billingRecord->fresh(),
                'dispensing_event' => $dispensingEvent,
            ];
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function validateItems(array $items, bool $allowCatalogTaxOverrides): array
    {
        $validator = Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.item_kind' => ['nullable', Rule::in(['catalog', 'lens', 'lens_option', 'custom'])],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'items.*.product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')
                    ->whereNull('deleted_at')
                    ->where('is_active', true),
            ],
            'items.*.lens_category_id' => [
                'nullable',
                'integer',
                Rule::exists('lens_categories', 'id')
                    ->whereNull('deleted_at')
                    ->where('is_active', true),
            ],
            'items.*.lens_option_id' => [
                'nullable',
                'integer',
                Rule::exists('lens_options', 'id')->where('is_active', true),
            ],
            'items.*.vat_treatment' => ['nullable', Rule::enum(VatTreatment::class)],
            'items.*.statutory_discount_eligible' => ['nullable', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($items): void {
            foreach ($items as $index => $item) {
                $references = collect([
                    $item['product_variant_id'] ?? null,
                    $item['lens_category_id'] ?? null,
                    $item['lens_option_id'] ?? null,
                ])->filter(fn (mixed $reference): bool => filled($reference));

                if ($references->count() > 1) {
                    $validator->errors()->add(
                        "items.{$index}.item_kind",
                        'An order item can reference only one catalog entry.',
                    );
                }

                if (filled($item['lens_option_id'] ?? null)
                    && filled($item['item_kind'] ?? null)
                    && $item['item_kind'] !== 'lens_option') {
                    $validator->errors()->add(
                        "items.{$index}.lens_option_id",
                        'A lens option must use the Lens Option item type.',
                    );
                }

                if (($item['item_kind'] ?? null) === 'lens_option'
                    && blank($item['lens_option_id'] ?? null)) {
                    $validator->errors()->add(
                        "items.{$index}.lens_option_id",
                        'A Lens Option item requires a catalog lens option.',
                    );
                }
            }

            $optionIds = collect($items)
                ->pluck('lens_option_id')
                ->filter()
                ->map(fn (mixed $id): int => (int) $id);

            foreach ($optionIds->duplicates()->unique() as $duplicateOptionId) {
                $validator->errors()->add(
                    'items',
                    "Lens option {$duplicateOptionId} may be selected only once per order.",
                );
            }

            $variantIds = collect($items)
                ->pluck('product_variant_id')
                ->filter()
                ->unique()
                ->values();

            if ($variantIds->isEmpty()) {
                return;
            }

            $activeVariantIds = ProductVariant::query()
                ->active()
                ->whereIn('id', $variantIds)
                ->whereHas('product', fn (Builder $query): Builder => $query->where('is_active', true))
                ->pluck('id');

            foreach ($variantIds->diff($activeVariantIds) as $invalidVariantId) {
                $validator->errors()->add(
                    'items',
                    "Product variant {$invalidVariantId} is not available for ordering.",
                );
            }
        });

        $validatedItems = $validator->validate()['items'];
        $catalogVariants = ProductVariant::query()
            ->with('product')
            ->whereKey(collect($validatedItems)->pluck('product_variant_id')->filter())
            ->get()
            ->keyBy('id');

        return collect($validatedItems)
            ->map(function (array $item) use ($allowCatalogTaxOverrides, $catalogVariants): array {
                $product = filled($item['product_variant_id'] ?? null)
                    ? $catalogVariants->get((int) $item['product_variant_id'])?->product
                    : null;
                $canUseProvidedTreatment = $allowCatalogTaxOverrides
                    && filled($item['vat_treatment'] ?? null);
                $canUseProvidedEligibility = $allowCatalogTaxOverrides
                    && array_key_exists('statutory_discount_eligible', $item);

                return [
                    ...$item,
                    'vat_treatment' => $product !== null && ! $canUseProvidedTreatment
                        ? ($product->vat_treatment?->value ?? VatTreatment::Vatable->value)
                        : ($item['vat_treatment'] ?? VatTreatment::Vatable->value),
                    'statutory_discount_eligible' => $product !== null && ! $canUseProvidedEligibility
                        ? (bool) $product->statutory_discount_eligible
                        : (bool) ($item['statutory_discount_eligible'] ?? $product?->statutory_discount_eligible ?? false),
                ];
            })
            ->all();
    }

    private function formatMoney(int $amountInCents): string
    {
        return number_format($amountInCents / 100, 2, '.', '');
    }

    /**
     * Resolve custom discounts and protect them at the server boundary.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function resolveDiscountAmount(
        DiscountType $discountType,
        ?float $requestedAmount,
        array $items,
        User $creator,
    ): ?float {
        $subtotalInCents = array_sum(array_map(
            fn (array $item): int => (int) round(((float) $item['unit_price']) * 100)
                * (int) $item['quantity'],
            $items,
        ));

        $discountInCents = match ($discountType) {
            DiscountType::None => $this->resolveNoDiscount($requestedAmount),
            DiscountType::SeniorCitizen, DiscountType::Pwd => 0,
            DiscountType::Other => $this->resolveCustomDiscount($requestedAmount),
        };

        if ($discountInCents > $subtotalInCents) {
            throw ValidationException::withMessages([
                'discount_amount' => ['Discount cannot exceed subtotal.'],
            ]);
        }

        if ($discountInCents > 0 && ! $creator->isAdmin()) {
            throw ValidationException::withMessages([
                'discount_amount' => ['Only an administrator can apply a discount.'],
            ]);
        }

        return $discountInCents > 0 ? $discountInCents / 100 : null;
    }

    private function isSeniorCitizenEligible(Patient $patient): bool
    {
        $age = $patient->ageInYears();
        $minimumAge = DiscountType::SeniorCitizen->minimumAge();

        return $age !== null && $minimumAge !== null && $age >= $minimumAge;
    }

    private function resolveNoDiscount(?float $requestedAmount): int
    {
        if (($requestedAmount ?? 0.0) !== 0.0) {
            throw ValidationException::withMessages([
                'discount_amount' => ['A discount amount is not allowed when no discount is selected.'],
            ]);
        }

        return 0;
    }

    private function resolveCustomDiscount(?float $requestedAmount): int
    {
        if ($requestedAmount !== null && ! is_finite($requestedAmount)) {
            throw ValidationException::withMessages([
                'discount_amount' => ['Discount must be a finite amount.'],
            ]);
        }

        if (($requestedAmount ?? 0) < 0) {
            throw ValidationException::withMessages([
                'discount_amount' => ['Discount cannot be negative.'],
            ]);
        }

        $discountInCents = (int) round(($requestedAmount ?? 0) * 100);

        return $discountInCents;
    }
}
