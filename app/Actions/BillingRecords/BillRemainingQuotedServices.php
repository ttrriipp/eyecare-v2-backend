<?php

namespace App\Actions\BillingRecords;

use App\Enums\QuotationStatus;
use App\Models\BillingRecord;
use App\Models\Quotation;
use Illuminate\Validation\ValidationException;

class BillRemainingQuotedServices
{
    public function __construct(
        private readonly ResolveOpenCheckoutBillingRecord $resolveOpenCheckoutBillingRecord,
        private readonly AppendQuotedServicesToBillingRecord $appendQuotedServicesToBillingRecord,
    ) {}

    /**
     * Bill quoted services that were skipped at confirm-sale time (or added
     * afterward), onto the same open checkout the sale was confirmed against.
     *
     * @param  array<int, int>  $quotationItemIds
     */
    public function handle(Quotation $quotation, array $quotationItemIds): BillingRecord
    {
        if ($quotation->status !== QuotationStatus::Accepted) {
            throw ValidationException::withMessages([
                'quotation' => ['Only a confirmed quotation has services that can be billed.'],
            ]);
        }

        $billingRecord = $this->resolveOpenCheckoutBillingRecord->handle(
            patient: $quotation->patient,
            jobOrder: $quotation->jobOrder,
            encounter: $quotation->encounter,
        );

        $this->appendQuotedServicesToBillingRecord->handle($billingRecord, $quotationItemIds);

        return $billingRecord->fresh();
    }
}
