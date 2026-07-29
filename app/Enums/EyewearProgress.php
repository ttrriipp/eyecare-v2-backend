<?php

namespace App\Enums;

enum EyewearProgress: string
{
    case EstimateAvailable = 'estimate_available';
    case InPreparation = 'in_preparation';
    case ReadyForPickup = 'ready_for_pickup';
    case Dispensed = 'dispensed';
    case EstimateDeclined = 'estimate_declined';
    case EstimateExpired = 'estimate_expired';
    case Cancelled = 'cancelled';
}
