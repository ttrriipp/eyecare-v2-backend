<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum VatTreatment: string implements HasLabel
{
    case Vatable = 'vatable';
    case Exempt = 'exempt';
    case ZeroRated = 'zero_rated';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vatable => 'VATable (12%, included in price)',
            self::Exempt => 'VAT-exempt',
            self::ZeroRated => 'Zero-rated (0%)',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $treatment) {
            $options[$treatment->value] = $treatment->getLabel();
        }

        return $options;
    }
}
