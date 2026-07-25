<?php

namespace App\Enums;

enum JobOrderStatus: string
{
    case Queued = 'queued';
    case InProgress = 'in_progress';
    case ReadyForDispensing = 'ready_for_dispensing';
    case Dispensed = 'dispensed';
    case Cancelled = 'cancelled';
}
