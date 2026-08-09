<?php

namespace App\Enums;

enum EncounterTransferReason: string
{
    case ProviderUnavailable = 'provider_unavailable';
    case ShiftChange = 'shift_change';
    case PatientRequest = 'patient_request';
    case Emergency = 'emergency';
    case Other = 'other';
}
