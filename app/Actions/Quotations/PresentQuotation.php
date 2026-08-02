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

        $quotation->update([
            'status' => QuotationStatus::Presented,
            'presented_by' => $presenter->id,
            'presented_at' => Carbon::now(),
        ]);

        return $quotation->fresh();
    }
}
