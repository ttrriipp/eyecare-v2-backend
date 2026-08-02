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
     * Allowed transitions:
     * - Draft -> Presented (via PresentQuotation) or Accepted (direct sale)
     * - Presented -> Draft (via UpdateQuotationDraft), Accepted, Declined, Expired
     * - Accepted, Declined, Expired -> terminal
     *
     * @param  'presented'|'accepted'|'declined'|'expired'  $decision
     */
    public function handle(
        Quotation $quotation,
        string $decision,
        User $recorder,
    ): Quotation {
        $targetStatus = QuotationStatus::from($decision);

        // Validate allowed transitions
        $this->validateTransition($quotation->status, $targetStatus);

        $attributes = ['status' => $targetStatus];

        if ($targetStatus === QuotationStatus::Accepted) {
            $attributes['confirmed_by'] = $recorder->id;
            $attributes['confirmed_at'] = Carbon::now();
        }

        $quotation->update($attributes);

        return $quotation->fresh();
    }

    private function validateTransition(QuotationStatus $current, QuotationStatus $target): void
    {
        $allowed = match ($current) {
            QuotationStatus::Draft => [QuotationStatus::Presented, QuotationStatus::Accepted],
            QuotationStatus::Presented => [QuotationStatus::Accepted, QuotationStatus::Declined, QuotationStatus::Expired],
            default => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw ValidationException::withMessages([
                'quotation' => ["Cannot transition from {$current->value} to {$target->value}."],
            ]);
        }
    }
}
