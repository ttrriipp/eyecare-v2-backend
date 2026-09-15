<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DiscountType: string implements HasLabel
{
    case None = 'none';
    case SeniorCitizen = 'senior_citizen';
    case Pwd = 'pwd';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'No discount',
            self::SeniorCitizen => 'Senior Citizen (20%)',
            self::Pwd => 'PWD (20%)',
            self::Other => 'Other (admin custom)',
        };
    }

    public function isStatutory(): bool
    {
        return in_array($this, [self::SeniorCitizen, self::Pwd], true);
    }

    public function percentage(): ?float
    {
        return $this->isStatutory() ? 20.0 : null;
    }

    public function minimumAge(): ?int
    {
        return match ($this) {
            self::SeniorCitizen => 60,
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->getLabel();
        }

        return $options;
    }
}
