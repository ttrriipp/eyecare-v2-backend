<?php

namespace App\Enums;

enum ArAssetStatus: string
{
    case Quarantined = 'quarantined';
    case Validated = 'validated';
    case Approved = 'approved';
    case Published = 'published';
    case Rejected = 'rejected';
    case Discarded = 'discarded';
    case Superseded = 'superseded';
    case Disabled = 'disabled';
}
