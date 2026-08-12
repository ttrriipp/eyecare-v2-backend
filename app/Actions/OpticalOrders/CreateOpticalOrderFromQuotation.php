<?php

namespace App\Actions\OpticalOrders;

use App\Actions\BillingRecords\AddChargesToBilling;
use App\Actions\BillingRecords\RecalculateBillingRecordTotals;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Actions\BillingRecords\ResolveOpenCheckoutBillingRecord;
use App\Actions\Quotations\ValidateOpticalQuotation;
use App\Enums\BillingItemSourceKind;
use App\Enums\CommercialItemKind;
use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOpticalOrderFromQuotation
{
    public function __construct(
        private readonly BuildOpticalOrder $buildOrder,
    ) {}

    /**
     * Confirm a quotation and create an optical order + billing record atomically.
     *
     * Replaces ConfirmQuotationSale, AcceptAndStartOpticalOrder,
     * CompleteImmediateOpticalOrder, and CreateJobOrder.
     *
     * Service-only quotations create no order but still resolve billing.
     * Idempotent — confirming twice returns the same order.
     *
     * @return array{quotation: Quotation, optical_order: ?JobOrder, billing_record: BillingRecord}
     */
    public function handle(
        Quotation $quotation,
        User $confirmer,
        array $performedServiceItemIds = [],
        ?Carbon $paymentDueDate = null,
        ?float $depositAmount = null,
        ?string $depositPaymentMethod = null,
        ?string $depositReference = null,
        string $fulfillmentMode = 'prepared',
        bool $usesExternalSupplier = false,
        ?string $recipientName = null,
    ): array {
        if (! in_array($quotation->status, [QuotationStatus::Draft, QuotationStatus::Accepted], true)) {
            throw ValidationException::withMessages([
                'quotation' => ['Only draft or accepted quotations can be confirmed.'],
            ]);
        }

        return DB::transaction(function () use ($quotation, $confirmer, $performedServiceItemIds, $paymentDueDate, $depositAmount, $depositPaymentMethod, $depositReference, $fulfillmentMode, $usesExternalSupplier, $recipientName): array {
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            $wasAlreadyAccepted = $quotation->status === QuotationStatus::Accepted;

            $productItems = $quotation->items()
                ->whereIn('item_kind', CommercialItemKind::productKindValues())
                ->get();

            $prescription = $quotation->prescription_id
                ? Prescription::find($quotation->prescription_id)
                : null;

            if ($productItems->isNotEmpty()) {
                app(ValidateOpticalQuotation::class)->handle(
                    items: $productItems->map(fn ($item) => [
                        'item_kind' => $item->item_kind,
                        'product_variant_id' => $item->product_variant_id,
                    ])->values(),
                    patient: $quotation->patient,
                    prescription: $prescription,
                );
            }

            // Idempotency: check for existing order
            $opticalOrder = $productItems->isNotEmpty()
                ? JobOrder::query()
                    ->where('quotation_id', $quotation->id)
                    ->lockForUpdate()
                    ->first()
                : null;

            if (! $wasAlreadyAccepted) {
                $quotation->update([
                    'status' => QuotationStatus::Accepted,
                    'confirmed_by' => $confirmer->id,
                    'confirmed_at' => now(),
                ]);
            }

            // Create order only if product items exist and no order yet
            if ($productItems->isNotEmpty() && $opticalOrder === null) {
                $itemSnapshots = $productItems->map(fn ($item): array => [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                    'product_variant_id' => $item->product_variant_id,
                    'lens_category_id' => $item->lens_category_id,
                    'lens_option_id' => $item->lens_option_id,
                    'item_type' => TransactionItemType::Product,
                    'item_kind' => $item->item_kind,
                    'item_snapshot' => $item->item_snapshot,
                ]);

                $opticalOrder = $this->buildOrder->handle(
                    patientId: $quotation->patient_id,
                    encounterId: $quotation->encounter_id,
                    prescriptionId: $quotation->prescription_id,
                    quotationId: $quotation->id,
                    fulfillmentMode: $fulfillmentMode,
                    usesExternalSupplier: $usesExternalSupplier,
                    items: $itemSnapshots,
                );

                if ($fulfillmentMode === 'immediate' && $recipientName !== null) {
                    $opticalOrder->dispensingEvents()->latest()->first()?->update([
                        'recipient_name' => $recipientName,
                    ]);
                }
            }

            // Resolve billing
            $billingRecord = BillingRecord::query()
                ->where('quotation_id', $quotation->id)
                ->lockForUpdate()
                ->first();

            if ($billingRecord === null && $opticalOrder !== null) {
                $billingRecord = BillingRecord::query()
                    ->where('job_order_id', $opticalOrder->id)
                    ->lockForUpdate()
                    ->first();
            }

            $billingRecord ??= app(ResolveOpenCheckoutBillingRecord::class)->handle(
                patient: $quotation->patient,
                jobOrder: $opticalOrder,
                encounter: $quotation->encounter,
            );

            // Append order items to billing
            if ($opticalOrder !== null) {
                $existingJobOrderItemIds = $billingRecord->items()
                    ->whereNotNull('job_order_item_id')
                    ->pluck('job_order_item_id')
                    ->toArray();

                $newOrderItems = $opticalOrder->items()
                    ->whereNotIn('id', $existingJobOrderItemIds)
                    ->get()
                    ->map(fn ($item): array => [
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'amount' => $item->amount,
                        'item_type' => $item->item_type,
                        'job_order_item_id' => $item->id,
                    ]);

                if ($newOrderItems->isNotEmpty()) {
                    app(AddChargesToBilling::class)->handle(
                        billingRecord: $billingRecord,
                        sourceKind: BillingItemSourceKind::OpticalOrder,
                        items: $newOrderItems,
                    );
                }
            }

            // Append selected services to billing
            if (! empty($performedServiceItemIds)) {
                $existingQuotationItemIds = $billingRecord->items()
                    ->whereNotNull('quotation_item_id')
                    ->pluck('quotation_item_id')
                    ->toArray();

                $newServiceIds = array_diff($performedServiceItemIds, $existingQuotationItemIds);

                if (! empty($newServiceIds)) {
                    $serviceItems = $quotation->items()
                        ->whereIn('id', $newServiceIds)
                        ->where('item_kind', CommercialItemKind::Service->value)
                        ->get()
                        ->map(fn ($item): array => [
                            'description' => $item->description,
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'amount' => $item->amount,
                            'item_type' => \App\Enums\TransactionItemType::Service,
                            'quotation_item_id' => $item->id,
                        ]);

                    if ($serviceItems->isNotEmpty()) {
                        app(AddChargesToBilling::class)->handle(
                            billingRecord: $billingRecord,
                            sourceKind: BillingItemSourceKind::Quotation,
                            items: $serviceItems,
                        );
                    }
                }
            }

            // Service-only: add remaining services directly if no order
            if ($opticalOrder === null) {
                $alreadyBilledIds = collect($performedServiceItemIds)->map(fn ($id) => (int) $id)->toArray();

                $serviceItems = $quotation->items()
                    ->where('item_kind', CommercialItemKind::Service->value)
                    ->whereNotIn('id', $alreadyBilledIds)
                    ->get()
                    ->map(fn ($item): array => [
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'amount' => $item->amount,
                        'item_type' => \App\Enums\TransactionItemType::Service,
                        'quotation_item_id' => $item->id,
                    ]);

                if ($serviceItems->isNotEmpty()) {
                    app(AddChargesToBilling::class)->handle(
                        billingRecord: $billingRecord,
                        sourceKind: BillingItemSourceKind::Quotation,
                        items: $serviceItems,
                    );
                }
            }

            // Set quotation link and discount
            $billingRecord->update([
                'quotation_id' => $quotation->id,
                'discount_amount' => $quotation->discount_amount,
                'payment_due_date' => $paymentDueDate ?? $billingRecord->payment_due_date,
            ]);

            // Recalculate totals
            $billingRecord->refresh();
            app(RecalculateBillingRecordTotals::class)->handle(
                $billingRecord,
                discountAmount: (float) $quotation->discount_amount,
            );

            // Record optional deposit
            $alreadyRecordedInitialDeposit = $billingRecord->payments()
                ->where('status', 'posted')
                ->where('notes', 'Initial deposit at confirmation')
                ->exists();

            if ($depositAmount !== null && $depositAmount > 0 && ! $alreadyRecordedInitialDeposit) {
                app(RecordBillingPayment::class)->handle(
                    billingRecord: $billingRecord,
                    amount: $depositAmount,
                    paymentMethod: $depositPaymentMethod ?? 'cash',
                    recorder: $confirmer,
                    referenceNumber: $depositReference,
                    notes: 'Initial deposit at confirmation',
                    chargesReviewed: true,
                );
            }

            return [
                'quotation' => $quotation->fresh(),
                'optical_order' => $opticalOrder?->fresh(),
                'billing_record' => $billingRecord->fresh(),
            ];
        });
    }
}
