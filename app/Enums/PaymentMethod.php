<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case GCash = 'gcash';
    case BankTransfer = 'bank_transfer';
    case CreditCard = 'credit_card';
    case Check = 'check';
}
