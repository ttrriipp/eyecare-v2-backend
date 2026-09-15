# Implementation Plan: Product-Level Variant Detail Defaults

Status: Approved — implementation pending  
Date: 2026-09-15  
Spec: docs/specs/product-variant-detail-defaults-spec.md

## Overview

Add Product-level defaults that initialize each new ProductVariant's additional
details. Retain effective attributes on variants, replace the contact-lens
template with the generic editor used by accessories, preserve the structured
frame template, and change Product creation to a two-stage flow.

## Architecture Decisions

- Add products.default_variant_attributes as nullable JSON.
- Keep product_variants.attributes as the effective SKU-level source of truth.
- Copy defaults once when a variant create form opens.
- Never merge defaults into an existing variant edit.
- Use one shared generic detail editor and normalizer for contact lenses and
  accessories.
- Keep structured frame fields and preserve optional unknown frame attributes.
- Keep Product defaults private to Filament; do not add them to patient APIs.
- Exclude Products without eligible variants from patient catalogs.
- Leave 3D AR calibration presets unchanged.

## Dependency Graph

~~~text
Approved data contract
    -> schema and deterministic backfill
        -> Product model, audit, factory, and seeder support
            -> shared normalization and form components
                -> two-stage Product creation
                    -> variant create-time prefill
                        -> snapshot and catalog compatibility
                            -> focused verification and documentation
~~~

## Task 1: Protect the approved behavior with characterization tests

Description:

Add failing Pest coverage for Product defaults, create-only inheritance,
non-retroactive edits, generic contact-lens details, and incomplete catalog
visibility before changing production behavior.

Acceptance criteria:

- Tests distinguish Product defaults from effective variant attributes.
- Tests prove existing variant edits do not receive newer Product defaults.
- Tests prove arbitrary contact-lens details survive snapshots.
- Tests prove a Product with no eligible variant is absent from patient catalogs.

Verification:

~~~text
vendor/bin/sail artisan test --compact tests/Feature/Products/ProductVariantDetailDefaultsTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameCatalogTest.php
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/ContactLensSnapshotTest.php
~~~

Dependencies: None

Likely files:

- tests/Feature/Products/ProductVariantDetailDefaultsTest.php
- tests/Feature/Api/V1/FrameCatalogTest.php
- tests/Feature/OpticalOrders/ContactLensSnapshotTest.php

Estimated scope: Medium

## Task 2: Add Product defaults and deterministic backfill

Description:

Add the nullable JSON column, Product cast/fillable support, audit tracking, and
a deterministic backfill that derives common defaults without changing any
variant.

Acceptance criteria:

- Products persist default_variant_attributes as an array.
- A single-variant Product receives its non-empty attributes as defaults.
- A multi-variant Product receives only equal key/value pairs common to every
  non-deleted variant.
- Variant rows are byte-for-byte logically unchanged by the migration.
- Rolling back removes only the new Product column.

Verification:

~~~text
vendor/bin/sail artisan test --compact tests/Feature/Products/ProductVariantDetailDefaultsTest.php
~~~

Dependencies: Task 1

Likely files:

- database/migrations/[timestamp]_add_default_variant_attributes_to_products_table.php
- app/Models/Product.php
- app/Observers/ProductObserver.php
- database/factories/ProductFactory.php
- tests/Feature/Products/ProductVariantDetailDefaultsTest.php

Estimated scope: Medium

## Task 3: Introduce shared detail normalization

Description:

Create one normalizer for generic Product and ProductVariant detail rows.
Replace contact-lens canonical filtering with normalized arbitrary keys while
leaving frame numeric validation in the structured form.

Acceptance criteria:

- Generic keys are trimmed, snake-cased, and unique after normalization.
- Blank rows are removed and blank or duplicate keys produce clear validation.
- Generic values remain strings and preserve formatting such as -3.00.
- Contact lenses accept arbitrary legitimate details.
- Existing frame attributes outside the structured template remain preservable.

Verification:

~~~text
vendor/bin/sail artisan test --compact tests/Feature/Products/ProductVariantDetailDefaultsTest.php
vendor/bin/sail artisan test --compact tests/Feature/Products/ContactLensVariantAttributesTest.php
~~~

Dependencies: Task 2

Likely files:

- app/Services/ProductAttributeNormalizer.php
- app/Services/ContactLensAttributeValidator.php
- tests/Feature/Products/ProductVariantDetailDefaultsTest.php
- tests/Feature/Products/ContactLensVariantAttributesTest.php

Estimated scope: Medium

## Checkpoint: Data foundation

- Product default data is stored and audited.
- Backfill is deterministic and non-destructive.
- Generic normalization behavior is test-covered.
- Variant API behavior has not changed.

## Task 4: Add Product-level detail editors

Description:

Add Default Variant Details to Product create/edit. Frames receive the structured
template plus optional other details; contact lenses and accessories receive the
shared generic editor.

Acceptance criteria:

- The section explains that defaults affect only newly created variants.
- Product type controls the correct editor.
- Frame values use the existing labels, ranges, and helper text.
- Contact-lens and accessory keys are unrestricted but normalized safely.
- Existing default values hydrate and save without losing unknown keys.

Verification:

~~~text
vendor/bin/sail artisan test --compact tests/Feature/Filament/VariantFormVisibilityTest.php
vendor/bin/sail artisan test --compact tests/Feature/Products/ProductVariantDetailDefaultsTest.php
~~~

Dependencies: Task 3

Likely files:

- app/Filament/Resources/Products/Schemas/ProductForm.php
- app/Filament/Resources/Products/Schemas/ProductDetailDefaultsForm.php
- app/Filament/Resources/Products/Schemas/GenericProductDetailsField.php
- tests/Feature/Filament/VariantFormVisibilityTest.php
- tests/Feature/Products/ProductVariantDetailDefaultsTest.php

Estimated scope: Medium

## Task 5: Convert Product creation to two stages

Description:

Remove the inline variant repeater, allow Product creation without a variant,
then redirect staff to Edit Product with a clear prompt to add the first variant.

Acceptance criteria:

- Product creation saves Product defaults without creating a variant.
- The user lands on Edit Product after creation.
- A visible notice explains that at least one variant is needed for catalog use.
- Existing Product edit and lifecycle controls continue to work.
- No automatic is_active change is introduced.

Verification:

~~~text
vendor/bin/sail artisan test --compact tests/Feature/Products/ProductVariantDetailDefaultsTest.php
vendor/bin/sail artisan test --compact tests/Feature/Filament/ProductListActionTest.php
~~~

Dependencies: Task 4

Likely files:

- app/Filament/Resources/Products/Schemas/ProductForm.php
- app/Filament/Resources/Products/Pages/CreateProduct.php
- app/Filament/Resources/Products/Pages/EditProduct.php
- tests/Feature/Products/ProductVariantDetailDefaultsTest.php
- tests/Feature/Filament/ProductListActionTest.php

Estimated scope: Medium

## Task 6: Prefill new variants exactly once

Description:

Initialize the relation-manager Create Variant form from the owner Product's
current defaults. Keep the Edit Variant form isolated from Product defaults.

Acceptance criteria:

- Opening Create Variant produces an independent copy of current defaults.
- Staff can override, add, or remove copied values before save.
- Reopening Create Variant uses the latest Product defaults.
- Edit Variant loads only saved variant attributes.
- Saving one variant cannot mutate Product defaults or another variant.

Verification:

~~~text
vendor/bin/sail artisan test --compact tests/Feature/Filament/VariantFormVisibilityTest.php
vendor/bin/sail artisan test --compact tests/Feature/Products/ProductVariantDetailDefaultsTest.php
~~~

Dependencies: Tasks 4 and 5

Likely files:

- app/Filament/Resources/Products/Schemas/VariantForm.php
- app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php
- app/Services/ProductAttributeNormalizer.php
- tests/Feature/Filament/VariantFormVisibilityTest.php
- tests/Feature/Products/ProductVariantDetailDefaultsTest.php

Estimated scope: Medium

## Checkpoint: Admin workflow

- Product creation and default editing work for all three Product types.
- New variants are prefilled; existing variants are never silently changed.
- Frames remain structured and contact lenses are generic.
- Staff can identify Products that still need a variant.

## Task 7: Preserve catalog and snapshot contracts

Description:

Make incomplete Product exclusion explicit in patient queries and update optical
snapshots to preserve generic contact-lens attributes.

Acceptance criteria:

- Every patient catalog endpoint excludes Products without an eligible active
  variant.
- Product defaults are absent from public resources.
- Variant attributes retain the existing response field and meaning.
- Contact-lens snapshots include arbitrary non-empty effective attributes.
- Existing historical snapshots are untouched.

Verification:

~~~text
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameCatalogTest.php
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/BuildOpticalItemSnapshotTest.php
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/ContactLensSnapshotTest.php
~~~

Dependencies: Tasks 3, 5, and 6

Likely files:

- app/Models/Product.php
- app/Http/Controllers/Api/FrameController.php
- app/Actions/OpticalOrders/BuildOpticalItemSnapshot.php
- tests/Feature/Api/V1/FrameCatalogTest.php
- tests/Feature/OpticalOrders/ContactLensSnapshotTest.php

Estimated scope: Medium

## Task 8: Align seed data and factories

Description:

Define Product defaults directly in fresh seed data and keep final, differing
values on variants. Update factories to make the new state easy to test.

Acceptance criteria:

- Freshly seeded Products have useful defaults.
- Shared contact-lens values live in Product defaults and colors remain on
  individual variants.
- Shared frame values are defaults while variant-specific color/code values
  remain on variants.
- Seeding remains idempotent.

Verification:

~~~text
vendor/bin/sail artisan test --compact tests/Feature/ProductCatalogTaxonomyTest.php
vendor/bin/sail artisan test --compact tests/Feature/ContactLensInventorySeederTest.php
~~~

Dependencies: Tasks 2 and 3

Likely files:

- database/seeders/CatalogSeeder.php
- database/factories/ProductFactory.php
- tests/Feature/ProductCatalogTaxonomyTest.php
- tests/Feature/ContactLensInventorySeederTest.php

Estimated scope: Medium

## Task 9: Final verification and documentation alignment

Description:

Run the affected suites, format PHP, and update existing Product data-structure
documentation so it distinguishes Product defaults from variant source-of-truth
values.

Acceptance criteria:

- Focused suites pass through Sail.
- Pint reports no remaining formatting changes.
- Product documentation no longer implies Products can never contain variant
  authoring defaults.
- No unrelated behavior or files change.

Verification:

~~~text
vendor/bin/sail artisan test --compact tests/Feature/Products/ProductVariantDetailDefaultsTest.php
vendor/bin/sail artisan test --compact tests/Feature/Filament/VariantFormVisibilityTest.php
vendor/bin/sail artisan test --compact tests/Feature/Products/ContactLensVariantAttributesTest.php
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/BuildOpticalItemSnapshotTest.php
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/ContactLensSnapshotTest.php
vendor/bin/sail artisan test --compact tests/Feature/ProductCatalogTaxonomyTest.php
vendor/bin/sail artisan test --compact tests/Feature/ContactLensInventorySeederTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameCatalogTest.php
vendor/bin/sail bin pint --dirty --format agent
~~~

Dependencies: Tasks 1–8

Likely files:

- docs/specs/product-data-structure.md
- docs/specs/product-variant-detail-defaults-spec.md
- affected tests and PHP files from prior tasks

Estimated scope: Small

## Risk Register

| Risk | Impact | Mitigation |
| --- | --- | --- |
| Product edits overwrite intentional variant values | High | Copy only on Create Variant initialization |
| Generic contact-lens details disappear from snapshots | High | Remove canonical filtering and add snapshot coverage |
| Variantless active frames appear to patients | High | Require an eligible active variant in catalog queries |
| Backfill chooses a differing value as a default | Medium | Use intersection across every non-deleted variant |
| Generic numeric text loses meaningful formatting | Medium | Store generic values as strings without type inference |
| Unknown frame keys disappear during editing | Medium | Preserve and expose optional other-detail rows |
| Staff mistakes defaults for live inheritance | Medium | Explicit helper text and create-only terminology |
| Fresh seed data differs from upgraded installations | Medium | Define defaults explicitly and test both paths |

## Completion Criteria

- Product-level defaults are stored, editable, audited, and safely backfilled.
- Product creation is two-stage and incomplete Products stay patient-invisible.
- Every new variant receives a one-time independent copy of Product defaults.
- Existing variants remain unchanged when defaults change.
- Frames retain structured catalog details and AR presets remain untouched.
- Contact lenses and accessories share a generic normalized details editor.
- Patient APIs continue exposing only effective variant attributes.
- Optical snapshots preserve arbitrary contact-lens details.
- Focused tests pass and PHP changes are formatted.

