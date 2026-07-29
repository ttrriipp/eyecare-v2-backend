<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BillingRecordStatus: string implements HasLabel
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
            self::Voided => 'Voided',
        };
    }
}
