# Implementation Plan: Product Entry and Variant Adjustments

Status: Proposed — awaiting product-owner confirmation
Date: 2026-09-15

## Overview

Improve the Filament Products workflow so clinic staff can create products and
variants with less ambiguity and fewer data-entry errors. The change removes
redundant lifecycle actions from the product edit-page heading, enforces
meaningful variant-name uniqueness, explains commercial and inventory fields,
uses product-type-aware inputs, and supports opening stock without bypassing the
inventory ledger.

This plan preserves the current product taxonomy (`frame`, `contact_lens`, and
`accessory`), the patient API response shape, existing catalog lifecycle
behavior, and the lot-aware contact-lens inventory model.

## Goals

- Make the common product-creation path understandable without external
  instructions.
- Prevent duplicate, malformed, or internally inconsistent variant data.
- Keep inventory quantities auditable from the moment a variant is created.
- Use structured inputs where the domain has known values while preserving an
  escape hatch for legitimate catalog data.
- Keep product creation and later variant creation/editing behavior consistent.

## Non-Goals

- Changing the three product types or introducing a new catalog taxonomy.
- Changing the patient-facing API contract.
- Replacing the existing inventory movement or contact-lens lot systems.
- Removing lifecycle actions from product table rows or bulk actions.
- Retrospectively creating inventory movements for seeded or historical stock.
- Building a purchasing or supplier-ordering workflow.

## Current-State Findings

- Product edit pages currently expose Activate/Deactivate heading actions in
  `app/Filament/Resources/Products/Pages/EditProduct.php`; the same lifecycle
  operations also exist as product table row and bulk actions.
- The inline product-creation variant form and the Variants relation-manager
  form are independently defined and have already drifted.
- SKU is globally unique, but variant name has no application or database
  uniqueness rule.
- Product creation directly accepts stock for frames and accessories, while
  later variant creation disables stock editing. Contact-lens creation forces
  stock to zero because inventory must be received into a lot with an expiry.
- Compare-at and cost-price guidance exists only in the Adjust Price action, not
  in the main create/edit forms.
- Frame color/material and contact-lens color are free text. Accessories expose
  an unexplained raw key/value Attributes field.
- Contact-lens parameters are nullable by design, but the form does not explain
  which lens designs require cylinder, axis, or add power.

## Architecture Decisions

### 1. Variant name is unique within its parent product

The human-readable name identifies a variant inside one product. SKU remains
the globally unique technical/business identifier. Global name uniqueness would
incorrectly prevent common values such as `Blue`, `Black`, or `Standard` from
appearing under unrelated products.

Uniqueness will therefore be enforced on `(product_id, name)`, with names
trimmed and whitespace normalized before validation and persistence. Duplicate
names that differ only by case or surrounding/repeated whitespace must be
rejected. Inactive and archived variants remain part of the uniqueness scope so
reactivation cannot create a collision.

Enforcement must exist at both layers:

- Friendly Filament validation in the inline repeater and relation-manager
  create/edit forms.
- A database composite unique constraint as the concurrency backstop.

Before adding the constraint, implementation must audit existing rows and stop
with an actionable report if duplicates would make the migration unsafe.

### 2. Use one shared variant form definition

Extract the common identity, pricing, inventory-settings, type-specific, and
image fields into a reusable Filament schema builder. The builder may accept
context such as product type and create/edit mode, but there should be one
definition for labels, hints, validation, and input behavior.

### 3. Opening stock is accepted but always ledger-backed

Staff may enter opening stock while creating a product or a later variant, but
the value must not be written directly to `stock_quantity`.

- Frames/accessories: collect quantity plus optional reference and notes, create
  the variant at zero, then record a restock/opening movement through the
  existing inventory action.
- Contact lenses: collect quantity, lot number, and expiry month, create the
  variant at zero, then use the existing lot-aware receiving action.
- A zero opening quantity creates no movement.
- Product, variants, opening lots, and opening movements must be coordinated so
  a validation or persistence failure cannot silently leave a partial stock
  setup.

After creation, stock remains read-only in normal variant editing and changes
only through Receive Stock or inventory deduction/write-off workflows.

### 4. Keep customer-visible and internal pricing separate

| Field | Meaning | Patient visibility | Rule |
|---|---|---|---|
| Selling price | Amount charged for the variant | Visible | Required, non-negative decimal |
| Compare-at price | Previous/reference price displayed crossed out to indicate a discount | Visible | Optional; must exceed selling price |
| Cost price | Clinic acquisition cost per sellable unit | Internal only | Optional; warn, but do not block, when above selling price |

The current API behavior remains unchanged: `compare_at_price` is exposed and
`cost_price` is excluded.

### 5. Treat inventory settings as integers with clear semantics

| Field | Meaning | Rule |
|---|---|---|
| Low stock threshold | At or below this quantity, the variant needs reorder attention; `0` disables the alert | Required non-negative integer |
| Target stock level | Desired quantity after restocking; used to calculate suggested reorder quantity | Optional non-negative integer and not below the threshold |
| Opening stock | Physical quantity on hand at creation | Optional non-negative integer; recorded through Inventory History |

### 6. Use product-type-aware catalog inputs

#### Frames

Split the current section into Frame Size and Appearance:

- Lens width: horizontal width of one lens in millimeters.
- Bridge width: distance between the lenses above the nose in millimeters.
- Temple length: length of the frame arm in millimeters.
- Lens height: vertical lens measurement in millimeters.
- Color and material: descriptive catalog values.

Keep dimensions optional because staff may not always have them during initial
entry, but display an incomplete-details hint when the standard lens
width/bridge/temple measurements are missing. Continue enforcing plausible
numeric ranges.

Use a searchable, creatable dropdown for color and material, populated from
normalized values already used by variants. Do not replace descriptive color
names with a single HTML color picker: values such as `Tortoise`, `Transparent
smoke gray`, and `Black / red` are operationally meaningful and cannot be
represented by one hex value. A separate optional swatch/hex field can be
considered later if the mobile UI needs visual swatches.

#### Contact lenses

Add a Lens Design selector with these values:

- Spherical
- Toric
- Multifocal
- Toric multifocal
- Cosmetic / plano

Use it to control relevant fields:

| Parameter | Meaning | Required behavior |
|---|---|---|
| Power / SPH | Corrective strength in diopters | Required for corrective designs; allow plano/`0.00` where applicable |
| Base curve / BC | Lens curvature used for fit | Normally required once clinic workflow confirms it is always available on packaging |
| Diameter / DIA | Overall lens width in millimeters | Normally required once clinic workflow confirms it is always available on packaging |
| Cylinder / CYL | Astigmatism correction | Visible and required for toric designs |
| Axis | Orientation of cylinder correction | Visible and required with cylinder; integer from 0 to 180 |
| Add | Additional near-vision power | Visible and required for multifocal designs |
| Color | Visible cosmetic tint | Required for colored/cosmetic products; otherwise optional |
| Pack size | Number of lenses in one sellable box | Required positive integer once confirmed by clinic workflow |

Cylinder and axis must behave as a pair. Prefer searchable, constrained options
for power, cylinder, axis, and add values when the product has a known available
range. Keep values human-readable in the existing `attributes` API object.

Authoritative terminology references:

- [FDA — Contact Lens Prescription](https://www.fda.gov/medical-devices/contact-lenses/contact-lens-prescription)
- [CooperVision — Toric Parameters](https://coopervision.com/practitioner/toric)
- [CooperVision — Multifocal Product Specifications](https://coopervision.com/practitioner/our-products/proclear-family/proclear-1-day-multifocal)
- [Ray-Ban — Frame Size Guide](https://india.ray-ban.com/size-guide)

#### Accessories

Rename Attributes to Additional Product Details and explain it as optional
facts describing the sellable item. Use repeatable key/value rows with suggested
keys and examples such as:

- `Volume` → `90 mL`
- `Package` → `Bottle`
- `Formulation` → `Preservative-free`
- `Compatible with` → `Soft contact lenses`

Prevent duplicate/blank keys, trim values, limit key/value lengths, and remove
fully blank rows before saving. Preserve an Other/custom-key option because
accessory properties vary widely.

## UX Structure

The variant form should follow the order in which staff identify and receive an
item:

1. Identity — name and optional auto-generated SKU.
2. Pricing — selling, compare-at, and internal cost prices.
3. Product details — frame, contact-lens, or accessory-specific fields.
4. Inventory settings — threshold, target, and opening-stock controls.
5. Images.
6. Availability — active status.

Use concise helper text below unfamiliar fields and short examples in
placeholders. Optional sections may be collapsible, but required inputs and
validation errors must never be hidden. Repeater items should display their
variant name as the item label and support cloning where it helps staff create
similar variants; cloned SKU and opening-stock fields must reset.

## Dependency Order

```text
Data audit and uniqueness policy
    -> Database constraint and name normalization
        -> Shared variant schema
            -> Type-specific fields and validation
                -> Ledger-backed opening stock
                    -> End-to-end verification
```

Removing the redundant heading action is independent and can be completed in
the first implementation slice.

## Task Plan

### Phase 1: Guardrails and consistency

#### Task 1: Remove product edit-page lifecycle heading actions

Remove only the Activate and Deactivate actions from the product edit-page
heading. Preserve lifecycle controls on table rows and bulk actions.

Acceptance criteria:

- The product edit page has no Activate or Deactivate heading action.
- Product table row and bulk lifecycle actions remain available to authorized
  administrators.
- Existing lifecycle service behavior is unchanged.

Likely files:

- `app/Filament/Resources/Products/Pages/EditProduct.php`
- `tests/Feature/Filament/ProductListActionTest.php` or a focused edit-page test

Estimated scope: Small

#### Task 2: Enforce normalized per-product variant-name uniqueness

Add normalization, form validation, and a composite database constraint after a
read-only duplicate audit.

Acceptance criteria:

- Two variants under the same product cannot share a normalized name.
- The same name remains valid under different products.
- Editing a variant without changing its name succeeds.
- Inactive/archived variants reserve their names.
- Concurrent writes are protected by the database constraint.

Likely files:

- New migration under `database/migrations/`
- `app/Models/ProductVariant.php`
- Shared/new variant schema class
- New focused ProductVariant uniqueness test

Estimated scope: Medium

#### Task 3: Consolidate the variant forms

Extract a shared variant schema and use it in both product creation and the
Variants relation manager.

Acceptance criteria:

- Create and edit surfaces share labels, hints, validation, and type-specific
  fields.
- Existing image upload, SKU generation, AR actions, and relationship saving
  continue to work.
- Product-type-specific sections remain correctly visible.

Likely files:

- New class under `app/Filament/Resources/Products/Schemas/`
- `app/Filament/Resources/Products/Schemas/ProductForm.php`
- `app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php`
- `tests/Feature/Filament/VariantFormVisibilityTest.php`

Estimated scope: Medium

### Checkpoint: Foundation

- Focused product and variant tests pass through Sail.
- The three product types render the correct shared schema.
- No patient API response fields have changed.

### Phase 2: Guided, type-aware entry

#### Task 4: Clarify pricing and inventory settings

Add the agreed helper text and tighten numeric/relational validation.

Acceptance criteria:

- Compare-at price, cost price, low-stock threshold, and target-stock level have
  concise plain-language guidance everywhere they appear.
- Quantities accept integers only.
- Compare-at price cannot be less than or equal to selling price.
- Target stock cannot be lower than the low-stock threshold.
- Cost above selling price produces a warning without blocking save.

Likely files:

- Shared variant schema class
- Focused Filament variant-form tests

Estimated scope: Small

#### Task 5: Improve frame inputs

Group dimensions and appearance, explain each measurement, and replace
unrestricted color/material entry with reusable searchable suggestions plus a
custom-value path.

Acceptance criteria:

- Frame measurements have meaningful labels, units, examples, and existing
  plausible ranges.
- Missing optional measurements are clearly identified without blocking save.
- Existing descriptive color/material values remain editable and API-compatible.
- Duplicate suggestions normalize to one displayed option.

Likely files:

- Shared variant schema class
- `app/Models/ProductVariant.php` or a focused option-provider/query class
- `tests/Feature/Filament/VariantFormVisibilityTest.php`

Estimated scope: Medium

#### Task 6: Add conditional contact-lens parameters

Add lens design and conditionally expose/validate prescription parameters.

Acceptance criteria:

- Spherical, toric, multifocal, toric-multifocal, and cosmetic/plano forms show
  only relevant fields.
- Cylinder and axis are validated as a pair.
- Add power is required only for multifocal designs.
- Existing valid contact-lens attribute JSON remains readable.
- Patient API attributes remain backward compatible.

Likely files:

- Shared variant schema class
- `app/Services/ContactLensAttributeValidator.php`
- `tests/Feature/Products/ContactLensVariantAttributesTest.php`
- `tests/Feature/Filament/VariantFormVisibilityTest.php`

Estimated scope: Medium

#### Task 7: Replace raw accessory attributes with guided details

Introduce suggested keys, validation, normalization, and explanatory copy while
preserving custom accessory properties.

Acceptance criteria:

- Staff can understand key/value rows without technical knowledge of JSON.
- Blank or duplicate keys cannot be stored.
- Existing accessory attributes load without data loss.
- Custom attributes remain possible.

Likely files:

- Shared variant schema class
- Focused accessory form tests

Estimated scope: Small

### Checkpoint: Guided entry

- Frame, contact-lens, and accessory creation paths pass focused tests.
- Existing seeded variants can be opened and saved without data loss.
- Validation errors identify the exact variant and field needing correction.

### Phase 3: Auditable opening stock

#### Task 8: Add opening stock to variant creation

Collect opening inventory in the creation UI and persist it through existing
inventory services only after the variant exists.

Acceptance criteria:

- Non-contact variants can start at zero or receive an opening quantity.
- Every positive opening quantity creates an inventory movement with previous
  stock zero and the correct resulting stock.
- Contact-lens opening stock requires lot number and non-expired expiry month and
  creates the matching inventory lot.
- Normal variant editing cannot directly overwrite stock.
- A failed opening receipt cannot silently leave a nonzero stock value without
  its movement/lot provenance.

Likely files:

- Shared variant schema class
- `app/Filament/Resources/Products/Pages/CreateProduct.php`
- `app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php`
- Existing inventory actions or a small orchestration action
- New focused opening-stock feature test

Estimated scope: Medium

#### Task 9: Complete regression and usability verification

Exercise the complete workflow using focused automated tests followed by one
bounded browser verification pass.

Acceptance criteria:

- Product and variant focused tests pass.
- Inventory movement and contact-lens lot tests pass.
- API catalog regression tests pass.
- The forms are usable for minimum, typical, and multi-variant products without
  hidden required fields or ambiguous failures.
- Changed PHP files pass Pint formatting.

Verification commands:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/VariantFormVisibilityTest.php
vendor/bin/sail artisan test --compact tests/Feature/Products/ContactLensVariantAttributesTest.php
vendor/bin/sail artisan test --compact tests/Feature/Filament/InventoryResourceTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameCatalogTest.php
vendor/bin/sail bin pint --dirty --format agent
```

The implementation should add and run more narrowly focused test files for
variant uniqueness and opening stock rather than overloading unrelated tests.

Estimated scope: Small

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Existing duplicate variant names block the migration | High | Run a read-only duplicate audit first; report exact conflicts and resolve deliberately before adding the constraint |
| Case behavior differs between test and production databases | Medium | Normalize in the application and retain a production database unique constraint |
| Shared schema abstraction becomes harder to understand than duplication | Medium | Keep it product-focused and parameterize only product type and operation context |
| Opening stock bypasses ledger provenance | High | Create variants at zero and invoke existing movement/lot-aware actions transactionally |
| Contact-lens conditional rules invalidate historical JSON | High | Preserve backward reads and apply stricter requirements only to newly submitted applicable designs |
| Color dropdown cannot represent real catalog descriptions | Medium | Use searchable suggestions with a custom-value path; do not restrict to a fixed color enum |
| New lens-design data changes the patient API unexpectedly | Medium | Keep it inside the existing attributes object and add API regression coverage |

## Open Decisions Requiring Confirmation

1. Confirm that variant names are unique per product, case/whitespace
   insensitive, including inactive/archived variants.
2. Confirm that opening stock stays in the creation form but must always create
   Inventory History (and a lot/expiry for contact lenses).
3. Confirm whether the clinic intends to stock spherical, toric, multifocal, and
   cosmetic/plano contact lenses. If the catalog will remain colored lenses
   only, the Lens Design work should be narrowed instead of adding unused
   complexity.
4. Confirm whether base curve, diameter, and pack size are always available to
   staff from contact-lens packaging; if so, require them for new variants.

## Completion Criteria

- All nine tasks and checkpoints are complete.
- Every changed behavior has automated Pest coverage.
- The product create/edit workflows are verified for all supported product
  types in Filament.
- Inventory quantities have auditable provenance.
- Patient-facing API behavior remains backward compatible.
- The product owner has resolved the open decisions above.

