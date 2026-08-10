<?php

namespace App\Actions\Quotations;

use App\Enums\CommercialItemKind;
use App\Models\LensCategory;
use App\Models\ProductVariant;
use App\Services\ContactLensAttributeValidator;
use Illuminate\Validation\ValidationException;

final class BuildQuotationItemSnapshot
{
    /**
     * Build an immutable catalog snapshot for a transaction item.
     *
     * Derives the item kind from the explicit catalog selection and
     * captures identifying data needed to understand the sale after
     * catalog edits. Never infers kind from description text.
     *
     * @return array{item_kind: CommercialItemKind, item_snapshot: array<string, mixed>}
     */
    public function handle(
        ?int $productVariantId = null,
        ?int $lensCategoryId = null,
        ?string $explicitKind = null,
        ?int $serviceId = null,
    ): array {
        // Lens Category -> lens_package
        if ($lensCategoryId !== null) {
            $lensCategory = LensCategory::query()->findOrFail($lensCategoryId);

            return [
                'item_kind' => CommercialItemKind::LensPackage,
                'item_snapshot' => [
                    'lens_category_id' => $lensCategory->id,
                    'lens_category_name' => $lensCategory->name,
                ],
            ];
        }

        // Product Variant -> derive from product type
        if ($productVariantId !== null) {
            $variant = ProductVariant::query()
                ->with('product')
                ->findOrFail($productVariantId);

            $kind = $this->deriveKindFromProductType($variant->product->product_type);

            return [
                'item_kind' => $kind,
                'item_snapshot' => $this->buildVariantSnapshot($variant),
            ];
        }

        // Service catalog entry
        if ($serviceId !== null) {
            return [
                'item_kind' => CommercialItemKind::Service,
                'item_snapshot' => null,
            ];
        }

        // Custom line: require explicit kind
        if ($explicitKind !== null) {
            $kind = CommercialItemKind::tryFrom($explicitKind);

            if ($kind === null) {
                throw ValidationException::withMessages([
                    'item_kind' => ["Invalid item kind [{$explicitKind}]."],
                ]);
            }

            // Custom lines must be one of the allowed custom kinds
            $allowedCustomKinds = [
                CommercialItemKind::CustomProduct,
                CommercialItemKind::Service,
                CommercialItemKind::LensOption,
            ];

            if (! in_array($kind, $allowedCustomKinds, true)) {
                throw ValidationException::withMessages([
                    'item_kind' => ["Item kind [{$explicitKind}] requires a catalog reference."],
                ]);
            }

            return [
                'item_kind' => $kind,
                'item_snapshot' => null,
            ];
        }

        // No catalog reference and no explicit kind: default to custom_product
        return [
            'item_kind' => CommercialItemKind::CustomProduct,
            'item_snapshot' => null,
        ];
    }

    private function deriveKindFromProductType(string $productType): CommercialItemKind
    {
        return match ($productType) {
            'frame' => CommercialItemKind::Frame,
            'contact_lens' => CommercialItemKind::ContactLens,
            'accessory' => CommercialItemKind::Accessory,
            default => CommercialItemKind::CustomProduct,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVariantSnapshot(ProductVariant $variant): array
    {
        $snapshot = [
            'product_variant_id' => $variant->id,
            'sku' => $variant->sku,
            'variant_name' => $variant->name,
            'product_name' => $variant->product->name,
            'product_type' => $variant->product->product_type,
        ];

        // Include relevant physical attributes
        if (is_array($variant->attributes)) {
            // For contact lenses, include only canonical applicable parameters
            if ($variant->product->product_type === 'contact_lens') {
                $validator = app(ContactLensAttributeValidator::class);
                $snapshot['attributes'] = $validator->getApplicableAttributes($variant->attributes);
            } else {
                $snapshot['attributes'] = $variant->attributes;
            }
        }

        return $snapshot;
    }
}
