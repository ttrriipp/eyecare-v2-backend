<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AppointmentStatusName: string implements HasColor, HasLabel
{
    case Scheduled = 'scheduled';
    case CheckedIn = 'checked_in';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function getLabel(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::CheckedIn => 'Checked In',
            self::Fulfilled => 'Fulfilled',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Scheduled => 'info',
            self::CheckedIn => 'warning',
            self::Fulfilled => 'success',
            self::Cancelled => 'danger',
            self::NoShow => 'gray',
        };
    }

    /**
     * @return list<string>
     *
     * @deprecated Remove after all consumers migrate in Task 21.
     */
    public static function transitionBridgeValues(): array
    {
        return [
            'pending',
            'confirmed',
            'arrived',
            'completed',
        ];
    }
}
