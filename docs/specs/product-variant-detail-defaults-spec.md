# Spec: Product-Level Variant Detail Defaults

Status: Approved  
Date: 2026-09-15

## Objective

Move the reusable definition and starting values for additional product details
to the Product level. Every newly created variant starts with a copy of those
defaults, while its final sellable details remain stored on ProductVariant.

The change should make product entry easier, avoid repeatedly typing shared
details, and still support genuine differences between variants such as color,
size, or package configuration.

## Approved Decisions

### Product defaults and variant values have different roles

Products store default_variant_attributes as authoring defaults. ProductVariant
continues to store attributes as the effective details for that SKU.

Defaults are copied into a new variant once. They are not resolved dynamically
at read time and do not create a permanent inheritance relationship.

This preserves correct modeling for products whose variants differ:

- contact-lens colors;
- frame colors and sizes;
- accessory volumes or package sizes; and
- any future product-specific detail.

### Default changes are not retroactive

Editing a Product's default details affects variants created afterward. Existing
variants are not automatically updated or overwritten.

An explicit previewable bulk-apply action may be designed later, but it is not
part of this change.

### Product creation is two-stage

Staff first creates the Product and its default variant details. After saving,
the application redirects to the Product edit page, where staff creates the
first variant from the prefilled defaults.

A Product may temporarily have no variants. It remains visible to staff but
must not be returned as a patient-purchasable catalog item.

### Frame templates remain

Frame means the structured catalog detail template containing fields such as
lens width, bridge width, temple length, lens height, color, and material.

The separate 3D AR calibration presets for scale, anchor, rotation, and physical
model calibration are unchanged.

### Contact lenses become generic

Contact lenses no longer use a fixed list or conditional template for lens
design, power, base curve, diameter, cylinder, axis, add, color, or pack size.
They use the same generic additional-details editor as accessories.

The generic editor may still contain those labels, but staff chooses the keys
that apply to the Product.

## Current State

- products has no attribute/default JSON column.
- product_variants.attributes is nullable JSON and is returned by variant API
  resources.
- Frame variants use a structured Frame Size & Appearance form.
- Contact-lens variants use a hard-coded parameter form.
- Accessory variants use a generic key/value field that infers keys from the
  most recently created variant.
- ContactLensAttributeValidator filters order snapshots to canonical keys.
- Product creation currently requires at least one inline variant.
- The frame catalog has a legacy query path that can return an active frame with
  no variants.

The development database audit on 2026-09-15 found:

- five accessory Products with five variants;
- one contact-lens Product with twelve variants; and
- nine frame Products with ten variants.

The contact-lens variants share base curve, diameter, and pack size but have
different colors. This supports copying shared Product defaults into distinct
variant values rather than moving all effective attributes out of variants.

## Data Contract

### Product

Add one nullable JSON column:

    products.default_variant_attributes

Example:

~~~json
{
  "base_curve": "8.6",
  "diameter": "14.2",
  "pack_size": "2"
}
~~~

The field is:

- editable by authorized catalog staff;
- cast to an array by Product;
- included in product audit changes;
- used only to initialize new variants; and
- excluded from patient-facing Product resources.

### ProductVariant

Keep:

    product_variants.attributes

This remains the source of truth for:

- patient catalog variant details;
- optical item snapshots;
- frame display and AR calibration starting measurements; and
- the exact attributes of the selected SKU.

### Generic key/value normalization

For contact lenses and accessories:

- trim keys and values;
- normalize keys to snake_case;
- reject blank keys;
- reject duplicate keys after normalization;
- remove rows with both key and value blank;
- limit the number and length of entries; and
- store values as descriptive strings without guessing numeric types.

Numeric-looking values must not be automatically cast because formatting can be
meaningful, such as power -3.00, model code 001, or add +2.00.

Existing JSON types remain unchanged until a record is explicitly edited.

### Frame detail normalization

Frames retain structured validation:

- dimensions remain numeric and constrained to plausible ranges;
- color and material remain descriptive text;
- model_code, color_code, and other non-template values must be preserved; and
- optional extra rows may be used for legitimate details outside the template.

## Prefill Rules

### New variant

When the relation-manager Create Variant action opens:

1. Read the owner Product's current default_variant_attributes.
2. Copy the defaults into the form's attributes state.
3. Let staff add, remove, or override values.
4. Save the resulting state to ProductVariant.attributes.

The copy occurs only when the create form is initialized.

### Existing variant

When editing a variant:

- load only the variant's saved attributes;
- do not merge Product defaults;
- do not restore a default that staff intentionally removed; and
- preserve unrecognized existing keys.

### Product-default edit

Saving Product defaults:

- does not update existing variants;
- does not update historical order snapshots;
- affects the next newly opened variant creation form; and
- does not change any API response by itself.

## Admin User Experience

### Create Product

The Product form order is:

1. Product identity and type.
2. Product description and images.
3. Default Variant Details.
4. Associations and status.

The Default Variant Details section explains:

> These values prefill new variants. Changing them later does not update
> existing variants.

Product-type behavior:

| Product type | Product defaults editor |
| --- | --- |
| Frame | Structured Frame Size & Appearance template plus optional other details |
| Contact lens | Generic additional-details rows |
| Accessory | Generic additional-details rows |

The inline Variants repeater is removed. After creation, redirect to Edit Product
and show a prompt to add the first variant.

### Create Variant

The form shows the Product defaults already populated. Staff may change them
before saving the variant.

The interface must make clear that these are copied values rather than a live
link to the Product.

### Edit Variant

The form shows only saved variant values. It must not silently incorporate newer
Product defaults.

### Incomplete Products

Products without an active, eligible variant:

- remain visible in Filament;
- show an incomplete/no-variants indication to staff; and
- are excluded from every patient catalog endpoint.

This feature does not introduce a new draft status or automatically toggle
is_active.

## API and Snapshot Behavior

### Patient API

Keep the existing public response shape:

~~~json
{
  "variants": [
    {
      "attributes": {
        "base_curve": "8.6",
        "diameter": "14.2",
        "color": "Blue"
      }
    }
  ]
}
~~~

Do not expose default_variant_attributes because it is an administrative
authoring aid, not patient catalog data.

### Optical item snapshots

Snapshots continue to copy ProductVariant.attributes at order creation.

Remove contact-lens canonical-key filtering so generic contact-lens details are
not silently discarded. Empty values should still be omitted by the shared
normalizer.

Historical snapshots are immutable and must not be rewritten.

## Migration and Backfill

Add the Product JSON column without removing or changing the variant column.

For every non-deleted Product:

- no variants or no usable attributes: leave defaults empty;
- one non-deleted variant: copy its non-empty attributes;
- multiple non-deleted variants: copy only key/value pairs that are present and
  equal across all variants; and
- omit differing values such as color.

Use deterministic comparison and preserve stored JSON value types during the
backfill. Do not modify ProductVariant rows.

The down migration removes only default_variant_attributes.

Seed data should define Product defaults explicitly rather than relying on the
backfill algorithm during fresh installation.

## Commands

Create files through Sail Artisan:

~~~text
vendor/bin/sail artisan make:migration add_default_variant_attributes_to_products_table --table=products --no-interaction
vendor/bin/sail artisan make:class Services/ProductAttributeNormalizer --no-interaction
vendor/bin/sail artisan make:test --pest ProductVariantDetailDefaultsTest --no-interaction
~~~

Focused verification:

~~~text
vendor/bin/sail artisan test --compact tests/Feature/Products/ProductVariantDetailDefaultsTest.php
vendor/bin/sail artisan test --compact tests/Feature/Filament/VariantFormVisibilityTest.php
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/BuildOpticalItemSnapshotTest.php
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/ContactLensSnapshotTest.php
vendor/bin/sail artisan test --compact tests/Feature/ProductCatalogTaxonomyTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameCatalogTest.php
vendor/bin/sail bin pint --dirty --format agent
~~~

## Testing Strategy

### Model and migration

- Product casts and persists defaults.
- Backfill copies a single variant's attributes.
- Backfill takes only the intersection of equal values for multiple variants.
- Backfill does not alter variant attributes.
- Empty and soft-deleted data are handled intentionally.

### Forms

- Frame Products render the structured template.
- Contact-lens and accessory Products render the generic editor.
- New variants receive an independent copy of Product defaults.
- Staff can override or remove prefilled values.
- Existing variant edits do not merge newer Product defaults.
- Unknown frame keys survive editing.
- Product creation succeeds without an inline variant and redirects correctly.

### Normalization

- Keys are trimmed and converted to snake_case.
- Blank and duplicate normalized keys fail clearly.
- Values are trimmed but retain textual formatting.
- Arbitrary contact-lens keys are accepted.

### Catalog and snapshots

- Products without eligible variants are excluded from patient endpoints.
- Patient resources expose effective variant attributes only.
- Contact-lens snapshots retain arbitrary non-empty attributes.
- Historical snapshots remain unchanged.

## Boundaries

Always:

- Keep final SKU details on ProductVariant.
- Prefill only during variant creation.
- Preserve unknown existing keys.
- Keep public APIs based on effective variant values.
- Run focused Pest tests and Pint through Sail.

Ask first:

- Adding automatic synchronization to existing variants.
- Exposing Product defaults through a public API.
- Introducing typed generic attributes or a separate attribute-definition table.
- Changing the AR calibration preset workflow.

Never:

- Drop product_variants.attributes in this change.
- Rewrite historical order snapshots.
- Silently overwrite existing variant values.
- Infer a generic value's data type from its text.
- Return incomplete products as purchasable patient catalog records.

## Success Criteria

- Staff defines reusable variant detail defaults once per Product.
- Every new variant form starts with an independent copy of those defaults.
- Existing variants never change when Product defaults change.
- Frames retain their structured catalog template.
- Contact lenses and accessories share one unrestricted generic editor.
- Variant API responses and order snapshots contain effective variant details.
- Incomplete Products remain out of patient catalogs.
- Existing data is safely backfilled without variant mutation.

