<?php

namespace App\Enums;

enum FrameSource: string
{
    case Catalog = 'catalog';
    case PatientSupplied = 'patient_supplied';
}
