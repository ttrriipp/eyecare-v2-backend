<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case Reported = 'reported';
    case UnderInvestigation = 'under_investigation';
    case Contained = 'contained';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
