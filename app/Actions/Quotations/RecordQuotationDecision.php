<?php

namespace App\Actions\Quotations;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class RecordQuotationDecision
{
    /**
     * Accept or decline a draft quotation.
     *
     * - Draft → Accepted (with confirmed_by/confirmed_at)
     * - Draft → Declined (with decline_reason)
     */
    public function handle(
        Quotation $quotation,
        string $decision,
        User $recorder,
        ?string $reason = null,
    ): Quotation {
        $targetStatus = QuotationStatus::from($decision);

        if ($quotation->status !== QuotationStatus::Draft) {
            throw ValidationException::withMessages([
                'quotation' => ['Only draft quotations can be accepted or declined.'],
            ]);
        }

        $attributes = ['status' => $targetStatus];

        if ($targetStatus === QuotationStatus::Accepted) {
            $attributes['confirmed_by'] = $recorder->id;
            $attributes['confirmed_at'] = Carbon::now();
        }

        if ($targetStatus === QuotationStatus::Declined) {
            if (blank($reason)) {
                throw ValidationException::withMessages([
                    'reason' => ['A reason is required when declining a quotation.'],
                ]);
            }

            $attributes['decline_reason'] = $reason;
        }

        $quotation->update($attributes);

        return $quotation->fresh();
    }
}
