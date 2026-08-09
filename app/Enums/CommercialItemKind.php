<?php

namespace App\Enums;

enum CommercialItemKind: string
{
    case Frame = 'frame';
    case LensPackage = 'lens_package';
    case LensOption = 'lens_option';
    case ContactLens = 'contact_lens';
    case Accessory = 'accessory';
    case CustomProduct = 'custom_product';
    case Service = 'service';
}
