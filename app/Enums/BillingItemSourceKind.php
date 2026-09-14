<?php

namespace App\Enums;

enum BillingItemSourceKind: string
{
    case OpticalOrder = 'optical_order';
    case Encounter = 'encounter';
    case DirectService = 'direct_service';
}
