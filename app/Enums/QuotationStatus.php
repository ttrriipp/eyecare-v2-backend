<?php

namespace App\Enums;

enum QuotationStatus: string
{
    case Draft = 'draft';
    case Accepted = 'accepted';
    case Declined = 'declined';
}
