<?php

namespace App\Enums;

enum PrivacyRequestType: string
{
    case Access = 'access';
    case Correction = 'correction';
    case Objection = 'objection';
    case Erasure = 'erasure';
}

enum PrivacyRequestDisposition: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case PartiallyApproved = 'partially_approved';
    case Denied = 'denied';
    case RequiresReview = 'requires_review';
}
