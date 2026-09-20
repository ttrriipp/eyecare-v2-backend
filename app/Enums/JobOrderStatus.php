<?php

namespace App\Enums;

enum JobOrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case PaymentReview = 'payment_review';
    case Queued = 'queued';
    case InProgress = 'in_progress';
    case ReadyForDispensing = 'ready_for_dispensing';
    case Dispensed = 'dispensed';
    case Cancelled = 'cancelled';
}
