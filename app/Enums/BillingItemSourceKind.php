<?php

namespace App\Enums;

enum BillingItemSourceKind: string
{
    case OpticalOrder = 'optical_order';
    case Quotation = 'quotation';
    case Encounter = 'encounter';
    case DirectService = 'direct_service';
}
