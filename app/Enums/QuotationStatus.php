<?php

namespace App\Enums;

enum QuotationStatus: string
{
    case Draft = 'draft';
    case Presented = 'presented';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
}
