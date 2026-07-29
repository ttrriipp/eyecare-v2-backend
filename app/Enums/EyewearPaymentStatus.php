<?php

namespace App\Enums;

enum EyewearPaymentStatus: string
{
    case BalanceDue = 'balance_due';
    case Paid = 'paid';
}
