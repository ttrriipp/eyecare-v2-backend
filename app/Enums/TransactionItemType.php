<?php

namespace App\Enums;

enum TransactionItemType: string
{
    case Product = 'product';
    case Service = 'service';
    case LegacyOther = 'legacy_other';
}
