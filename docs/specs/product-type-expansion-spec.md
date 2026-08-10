# Spec: Product and Prescription Catalog Taxonomy

Status: Implemented
Phase: Done

> This specification supersedes the earlier four-type Product taxonomy. Prescription
> lens packages and enhancements are catalog records of their own; they are not
> physical Product inventory.

## Objective

Keep the Product catalog limited to physical items the clinic intentionally stocks:

- 'frame' — eyewear frames
- 'contact_lens' — stocked contact lenses
- 'accessory' — cases, solutions, cleaning kits, and other stocked accessories

Prescription lens packages, lens enhancements, and clinical charges belong to
separate catalogs:

- LensCategory — the base prescription lens package
- LensOption — a separately billed enhancement such as Anti-Reflective,
  Photochromic, Blue-light filter, or Tint
- Service — a billable clinical or operational service

The old 'lens' Product type represented physical lens blanks and is no longer a
supported creation or seeding option. Existing product_type = 'lens' records
remain for historical references and are marked inactive by the forward migration
2026_08_10_193536_deactivate_legacy_lens_products.php.

## Current Catalog Contract

| Catalog | Supported records | Inventory |
|---|---|---|
| Product | 'frame', 'contact_lens', 'accessory' | Yes, through Product variants |
| Lens Category | Prescription lens packages | No |
| Lens Option | Separately billed lens enhancements | No |
| Service | Billable clinical or operational services | No |

products.lens_category_id remains in the schema and Product model temporarily
for compatibility with historical data. It is not used to create new Products and
must not be reused for contact lenses.

Lens Option lines in quotations and optical orders use the existing transaction
architecture with item_type = product and item_kind = lens_option. They carry
their own commercial line snapshot and do not create inventory movements.

## Product Management

The Filament Product resource uses Product::TYPE_OPTIONS, which exposes exactly
Frame, Contact Lens, and Accessory. Product type remains disabled after creation.
The form does not offer the legacy Lens type or a Lens Category selector.

Product tables, filters, brand relationships, and category relationships use the
same three-type allowlist. Historical 'lens' rows can remain readable by their
stored value while inactive, but they cannot be created through the current form.

Frame-only AR fields continue to describe augmented-reality try-on eligibility.
They must never be reused to represent Anti-Reflective coating.

## Seed Data and Migration

CatalogSeeder:

- creates the generic and converted prescription packages in LensCategory,
  including Essilor Varilux Progressive 1.67 and Zeiss Single Vision 1.50;
- creates Anti-Reflective in LensOption;
- creates frame, contact-lens, and accessory Products only;
- does not create Lens Products, Lens Product variants, or a Lenses Product
  category.

The legacy migration is intentionally forward-only:

    DB::table('products')
        ->where('product_type', 'lens')
        ->update(['is_active' => false]);

It preserves the Product, its variants, its historical references, and its
lens_category_id. Its down() method does not reactivate every legacy record,
because that could change an intentionally inactive historical Product.

No existing lens Product or variant is deleted. If a deployment contains
historical lens records, they remain available only for historical/reference
purposes and are excluded from active catalog and ordering flows by their
inactive state.

## APIs and Orders

The existing patient frame catalog remains frame-only: /api/v1/frames returns
active frame Products and excludes contact lenses, accessories, and legacy lens
Products. Optical quotations and orders use LensCategory and LensOption lines for
prescription builds.

Product factories default to frame and expose contactLens() and accessory()
states. There is no supported lens() or general() factory state.

## Testing and Verification

The taxonomy coverage includes:

- Product type options contain only frame, contact_lens, and accessory;
- a fresh CatalogSeeder creates no Lens Products or variants and creates the
  converted LensCategory and LensOption records;
- the legacy migration deactivates Lens Products without clearing
  lens_category_id;
- frame catalog behavior continues to exclude non-frame Product types;
- existing Product, contact-lens, lens-package, Service, and optical-commerce
  tests remain green.

Run the focused tests first, then formatting and the full suite:

    vendor/bin/sail artisan test --compact tests/Feature/ProductCatalogTaxonomyTest.php tests/Feature/Api/V1/CatalogTaxonomyTest.php tests/Feature/Reservations/CreateFrameReservationTest.php tests/Feature/Seeders/CanonicalSeederTest.php
    vendor/bin/sail bin pint --dirty --format agent
    vendor/bin/sail artisan test --compact

## Boundaries

- Do not reintroduce lens as a Product type for prescription lens blanks.
- Do not delete products.lens_category_id until historical and application
  references have been confirmed obsolete.
- Do not add stock, SKU, variants, or lots to LensCategory or LensOption.
- Do not introduce package-option compatibility matrices or package-specific
  option pricing in this change.
- Do not use ar_eligible for Anti-Reflective coating.
- If the clinic later stocks physical lens blanks, introduce that as an explicit
  inventory feature with its own taxonomy and workflows.
