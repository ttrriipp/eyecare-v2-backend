<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProductUsage: string implements HasLabel
{
    case OneDay = '1_day';
    case OneMonth = '1_month';
    case ThreeMonths = '3_months';
    case SixMonths = '6_months';
    case OneYear = '1_year';

    public function getLabel(): string
    {
        return match ($this) {
            self::OneDay => '1 Day',
            self::OneMonth => '1 Month',
            self::ThreeMonths => '3 Months',
            self::SixMonths => '6 Months',
            self::OneYear => '1 Year',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $usage) {
            $options[$usage->value] = $usage->getLabel();
        }

        return $options;
    }
}
