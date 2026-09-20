<?php

namespace App\Enums;

enum AccessoryOrderRequestStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    public function isActionable(): bool
    {
        return $this === self::Pending;
    }
}
