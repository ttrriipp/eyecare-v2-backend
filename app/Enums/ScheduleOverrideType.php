<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ScheduleOverrideType: string implements HasColor, HasLabel
{
    case Closed = 'closed';
    case EarlyClose = 'early_close';
    case ProviderAbsence = 'provider_absence';

    public function getLabel(): string
    {
        return match ($this) {
            self::Closed => 'Clinic Closed',
            self::EarlyClose => 'Early Closing',
            self::ProviderAbsence => 'Optometrist Absence',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Closed => 'danger',
            self::EarlyClose => 'warning',
            self::ProviderAbsence => 'info',
        };
    }
}
