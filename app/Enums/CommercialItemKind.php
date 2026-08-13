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

    /**
     * Whether this kind represents a physical product (vs a service).
     */
    public function isProduct(): bool
    {
        return in_array($this, self::productKinds(), true);
    }

    /**
     * All product kinds (non-service).
     *
     * @return list<self>
     */
    public static function productKinds(): array
    {
        return [
            self::Frame,
            self::LensPackage,
            self::LensOption,
            self::ContactLens,
            self::Accessory,
            self::CustomProduct,
        ];
    }

    /**
     * All product kind string values for query scopes.
     *
     * @return list<string>
     */
    public static function productKindValues(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::productKinds());
    }
}
