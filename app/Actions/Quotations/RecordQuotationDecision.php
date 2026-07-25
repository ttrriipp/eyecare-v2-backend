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
     * Record a patient's decision on a presented quotation.
     *
     * @param  'accepted'|'declined'|'expired'  $decision
     */
    public function handle(
        Quotation $quotation,
        string $decision,
        User $recorder,
    ): Quotation {
        if ($quotation->status !== QuotationStatus::Presented) {
            throw ValidationException::withMessages([
                'quotation' => ['Only presented quotations can have a decision recorded.'],
            ]);
        }

        $status = QuotationStatus::from($decision);

        $attributes = ['status' => $status];

        if ($status === QuotationStatus::Accepted) {
            $latestRevision = $quotation->latestRevision;

            if ($latestRevision === null) {
                throw ValidationException::withMessages([
                    'quotation' => ['Cannot accept a quotation with no revisions.'],
                ]);
            }

            $latestRevision->update([
                'accepted_by' => $recorder->id,
                'accepted_at' => Carbon::now(),
            ]);
        }

        $quotation->update($attributes);

        return $quotation->fresh();
    }
}
