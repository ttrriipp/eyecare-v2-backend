<?php

namespace App\Enums;

enum AppointmentRequestKind: string
{
    case New = 'new';
    case Reschedule = 'reschedule';
}
