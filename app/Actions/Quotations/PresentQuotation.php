<?php

namespace App\Actions\Quotations;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PresentQuotation
{
    public function handle(Quotation $quotation, User $presenter): Quotation
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw ValidationException::withMessages([
                'quotation' => ['Only draft quotations can be presented.'],
            ]);
        }

        $latestRevision = $quotation->latestRevision;

        if ($latestRevision === null) {
            throw ValidationException::withMessages([
                'quotation' => ['Cannot present a quotation with no revisions.'],
            ]);
        }

        $latestRevision->update([
            'presented_by' => $presenter->id,
            'presented_at' => Carbon::now(),
        ]);

        $quotation->update(['status' => QuotationStatus::Presented]);

        return $quotation->fresh();
    }
}
