<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Requested = 'requested';
    case Prepared = 'prepared';
    case TriedOn = 'tried_on';
    case Converted = 'converted';
    case Released = 'released';
    case Cancelled = 'cancelled';
}
