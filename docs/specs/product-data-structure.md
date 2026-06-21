# Spec: Product Data Structure Refactor

Status: Complete — implemented in commit b5133f7
Phase: Done

## Decision

Removed `price` and `dimensions` from the `products` table. These fields now exist exclusively on `product_variants`.

## Rationale

An optical clinic never sells "a product" — it sells a specific variant (frame model + color + size). Price and physical dimensions belong at the variant level because:

- Different variants of the same product can have different prices (e.g., a titanium variant costs more than acetate)
- Frame dimensions (lens width, bridge width, temple length) are physical measurements of a specific size — a "Ray-Ban Aviator" comes in 55mm and 58mm, which are different variants
- Orders already snapshot `product_variants.price`, making `products.price` unused and misleading
- This matches industry standard: Shopify, WooCommerce, Frames Data, and all optical retail systems model price/dimensions at the variant (SKU) level

## Product vs Variant Model

**Product** (`products` table) = the catalog entry
- brand, category, name, slug, description, is_active
- images (product-level hero shots)
- Has many variants

**Variant** (`product_variants` table) = the purchasable SKU
- name (e.g., "Matte Black"), sku (auto: VAR-XXXXXX)
- price (the actual sellable price)
- dimensions (JSON, nullable — lens_width, bridge_width, temple_length)
- stock_quantity, low_stock_threshold
- ar_eligible, ar_asset_reference
- is_active

## Simple Products (No Dimensions)

For products with no meaningful variation (e.g., lens cleaning kit), staff creates one variant named "Standard" or "Default" with price and stock. Dimensions are left blank — the field is nullable. No special "product type" flag is needed.

## Enforcement

Every product must have at least one variant to be purchasable. The Filament create form enforces `->minItems(1)` on the variants Repeater.

## Files Changed

- `database/migrations/2026_06_18_015335_drop_price_and_dimensions_from_products.php`
- `app/Models/Product.php` — removed from fillable and casts
- `app/Http/Resources/ProductResource.php` — removed from API response
- `database/factories/ProductFactory.php` — removed from default state
- `app/Filament/Resources/Products/Schemas/ProductForm.php` — removed fields, added minItems(1)
- `app/Observers/ProductObserver.php` — removed price from tracked audit fields
- `database/seeders/CatalogSeeder.php` — dimensions moved to variant entries

## Known Issue

`product_image_uploads_use_public_visibility_and_validation` test fails due to a pre-existing Filament FileUpload + Livewire fake limitation. Unrelated to this change.
