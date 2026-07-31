<?php

namespace App\Enums;

enum PatientInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Failed = 'failed';
}
