<?php

namespace App\Enums;

enum IntakeStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Verified = 'verified';
}
