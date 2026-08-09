<?php

namespace App\Actions\Quotations;

use App\Enums\CommercialItemKind;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ValidateOpticalQuotation
{
    /**
     * Validate the optical item matrix for a quotation.
     *
     * Enforces the approved single-build structure:
     * - Corrective eyewear requires exactly one lens_package
     * - At most one frame (catalog or patient-supplied)
     * - Lens options only when a lens_package exists
     * - Service-only, non-corrective Product-only, and mixed remain valid
     *
     * @param  Collection<int, array{item_kind: CommercialItemKind, ...}>  $items
     * @return array{is_corrective: bool, has_frame: bool, has_lens_package: bool}
     */
    public function handle(Collection $items): array
    {
        $lensPackages = $items->where('item_kind', CommercialItemKind::LensPackage);
        $frames = $items->whereIn('item_kind', [CommercialItemKind::Frame, CommercialItemKind::CustomProduct])
            ->filter(fn (array $item): bool => filled($item['product_variant_id'] ?? null));
        $lensOptions = $items->where('item_kind', CommercialItemKind::LensOption);
        $contactLenses = $items->where('item_kind', CommercialItemKind::ContactLens);
        $accessories = $items->where('item_kind', CommercialItemKind::Accessory);

        $isCorrective = $lensPackages->isNotEmpty();

        // Exactly one lens package for corrective eyewear
        if ($lensPackages->count() > 1) {
            throw ValidationException::withMessages([
                'items' => ['A corrective-eyewear quotation must have exactly one lens package.'],
            ]);
        }

        // At most one frame
        if ($frames->count() > 1) {
            throw ValidationException::withMessages([
                'items' => ['A corrective-eyewear quotation must have at most one frame.'],
            ]);
        }

        // Lens options require a lens package
        if ($lensOptions->isNotEmpty() && $lensPackages->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => ['Lens options require a lens package in the same quotation.'],
            ]);
        }

        return [
            'is_corrective' => $isCorrective,
            'has_frame' => $frames->isNotEmpty(),
            'has_lens_package' => $lensPackages->isNotEmpty(),
        ];
    }
}
