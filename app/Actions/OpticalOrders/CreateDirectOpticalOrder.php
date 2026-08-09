<?php

namespace App\Actions\OpticalOrders;

use App\Actions\BillingRecords\AppendJobOrderItemsToBillingRecord;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Actions\BillingRecords\ResolveOpenCheckoutBillingRecord;
use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Actions\Quotations\BuildQuotationItemSnapshot;
use App\Enums\JobOrderStatus;
use App\Enums\TransactionItemType;
use App\Models\BillingRecord;
use App\Models\DispensingEvent;
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

class CreateDirectOpticalOrder
{
    /**
     * Create a product-only Optical Order with no source Quotation.
     *
     * Commits catalog inventory once, resolves/reuses the open Billing
     * Record, and — for immediate fulfillment — completes and dispenses
     * the order in the same transaction.
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
        ?Carbon $paymentDueDate = null,
        ?float $depositAmount = null,
        ?string $depositPaymentMethod = null,
        ?string $depositReference = null,
        ?string $recipientName = null,
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

        $validatedItems = $this->validateItems($items);

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
        }

        return DB::transaction(function () use ($patient, $validatedItems, $fulfillmentMode, $usesExternalSupplier, $prescription, $paymentDueDate, $depositAmount, $depositPaymentMethod, $depositReference, $recipientName, $creator) {
            $itemSnapshots = collect($validatedItems)->map(function (array $item): array {
                $unitPriceInCents = (int) round(((float) $item['unit_price']) * 100);
                $amountInCents = $unitPriceInCents * (int) $item['quantity'];

                // Build immutable catalog snapshot
                $snapshotResult = app(BuildQuotationItemSnapshot::class)->handle(
                    productVariantId: $item['product_variant_id'] ?? null,
                    lensCategoryId: $item['lens_category_id'] ?? null,
                );

                return [
                    'description' => trim($item['description']),
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => $this->formatMoney($unitPriceInCents),
                    'amount' => $this->formatMoney($amountInCents),
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'lens_category_id' => $item['lens_category_id'] ?? null,
                    'item_kind' => $snapshotResult['item_kind'],
                    'item_snapshot' => $snapshotResult['item_snapshot'],
                    'amount_in_cents' => $amountInCents,
                ];
            });

            $totalInCents = $itemSnapshots->sum('amount_in_cents');

            $jobOrder = JobOrder::create([
                'patient_id' => $patient->id,
                'prescription_id' => $prescription?->id,
                'quotation_id' => null,
                'status' => JobOrderStatus::Queued,
                'fulfillment_mode' => $fulfillmentMode,
                'uses_external_supplier' => $usesExternalSupplier,
                'total_amount' => $this->formatMoney($totalInCents),
            ]);

            $jobOrder->items()->createMany(
                $itemSnapshots
                    ->map(fn (array $item): array => [
                        ...collect($item)->except('amount_in_cents')->all(),
                        'item_type' => TransactionItemType::Product,
                    ])
                    ->all(),
            );

            app(CommitJobOrderInventory::class)->handle($jobOrder);

            if ($fulfillmentMode === 'immediate') {
                $jobOrder->update([
                    'status' => JobOrderStatus::Dispensed,
                    'started_at' => now(),
                    'dispensed_at' => now(),
                ]);
            }

            $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
                patient: $patient,
                jobOrder: $jobOrder,
            );

            app(AppendJobOrderItemsToBillingRecord::class)->handle(
                jobOrder: $jobOrder,
                billingRecord: $billingRecord,
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
                );
            }

            $dispensingEvent = null;

            if ($fulfillmentMode === 'immediate') {
                $dispensingEvent = DispensingEvent::create([
                    'job_order_id' => $jobOrder->id,
                    'billing_record_id' => $billingRecord->id,
                    'dispensed_by' => $creator->id,
                    'recipient_name' => $recipientName,
                    'notes' => 'Immediate completion',
                ]);
            }

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
    private function validateItems(array $items): array
    {
        $validator = Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'min:1', 'max:50'],
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
            'items.*.lens_category_id' => ['nullable', 'integer', Rule::exists('lens_categories', 'id')],
        ]);

        $validator->after(function ($validator) use ($items): void {
            foreach ($items as $index => $item) {
                if (filled($item['product_variant_id'] ?? null) && filled($item['lens_category_id'] ?? null)) {
                    $validator->errors()->add(
                        "items.{$index}.product_variant_id",
                        'An order item can reference either a catalog item or a lens category, not both.',
                    );
                }
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

        return $validator->validate()['items'];
    }

    private function formatMoney(int $amountInCents): string
    {
        return number_format($amountInCents / 100, 2, '.', '');
    }
}
