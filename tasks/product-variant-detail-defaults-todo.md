# Product-Level Variant Detail Defaults — Checklist

Status: Approved — implementation pending  
Spec: docs/specs/product-variant-detail-defaults-spec.md  
Plan: tasks/product-variant-detail-defaults-plan.md

## 1. Characterization

- [ ] Add Product defaults behavior tests.
- [ ] Add create-only inheritance tests.
- [ ] Add non-retroactive Product edit tests.
- [ ] Add arbitrary contact-lens snapshot tests.
- [ ] Add incomplete Product catalog tests.

Checkpoint:

- [ ] New tests fail for the intended missing behavior.

## 2. Schema and model

- [ ] Add products.default_variant_attributes as nullable JSON.
- [ ] Backfill a single variant's non-empty attributes.
- [ ] Backfill only common equal values for multi-variant Products.
- [ ] Ignore deleted variants during backfill.
- [ ] Leave every ProductVariant unchanged.
- [ ] Add Product fillable and array cast.
- [ ] Include default changes in Product audit events.
- [ ] Update ProductFactory.
- [ ] Test rollback behavior.

Checkpoint:

- [ ] Product defaults persist and the backfill is non-destructive.

## 3. Shared normalization

- [ ] Add ProductAttributeNormalizer.
- [ ] Trim keys and values.
- [ ] Normalize keys to snake_case.
- [ ] Reject blank and duplicate normalized keys.
- [ ] Remove fully blank rows.
- [ ] Enforce entry and length limits.
- [ ] Preserve generic values as strings.
- [ ] Remove contact-lens canonical-key restrictions.
- [ ] Preserve unknown frame details.

Checkpoint:

- [ ] Contact lenses and accessories can share one safe generic contract.

## 4. Product form

- [ ] Add Default Variant Details section.
- [ ] Explain create-only default behavior.
- [ ] Render the structured frame template.
- [ ] Add optional frame other-details rows.
- [ ] Render the generic contact-lens editor.
- [ ] Render the generic accessory editor.
- [ ] Hydrate and save existing Product defaults.

## 5. Two-stage Product creation

- [ ] Remove the inline Variants repeater.
- [ ] Permit Product creation without a variant.
- [ ] Redirect to Edit Product after creation.
- [ ] Prompt staff to add the first variant.
- [ ] Show an incomplete/no-variants admin indication.
- [ ] Do not automatically change is_active.

Checkpoint:

- [ ] Staff can create the Product and defaults before creating variants.

## 6. Variant prefill

- [ ] Prefill Create Variant from owner Product defaults.
- [ ] Copy defaults only when the create form opens.
- [ ] Allow overrides, additions, and removals.
- [ ] Save the result to ProductVariant.attributes.
- [ ] Keep Edit Variant isolated from Product defaults.
- [ ] Verify variants and Product defaults do not share mutable state.
- [ ] Verify later creates use the latest defaults.

Checkpoint:

- [ ] New variants inherit once; existing variants never change implicitly.

## 7. Catalog and snapshots

- [ ] Exclude Products without eligible active variants from patient endpoints.
- [ ] Keep default_variant_attributes out of public resources.
- [ ] Preserve the variant attributes response field.
- [ ] Snapshot all non-empty effective contact-lens attributes.
- [ ] Leave historical snapshots unchanged.
- [ ] Confirm frame AR calibration presets are unchanged.

Checkpoint:

- [ ] Public consumers see only complete Products and effective SKU details.

## 8. Seed data

- [ ] Define Product defaults explicitly in CatalogSeeder.
- [ ] Put shared contact-lens details on the Product defaults.
- [ ] Keep contact-lens colors on variants.
- [ ] Put shared frame details on Product defaults.
- [ ] Keep differing frame details on variants.
- [ ] Verify idempotent seeding.

## 9. Documentation and verification

- [ ] Update docs/specs/product-data-structure.md.
- [ ] Run ProductVariantDetailDefaultsTest.
- [ ] Run VariantFormVisibilityTest.
- [ ] Run ContactLensVariantAttributesTest.
- [ ] Run BuildOpticalItemSnapshotTest.
- [ ] Run ContactLensSnapshotTest.
- [ ] Run ProductCatalogTaxonomyTest.
- [ ] Run ContactLensInventorySeederTest.
- [ ] Run FrameCatalogTest.
- [ ] Run any additional affected tests.
- [ ] Run vendor/bin/sail bin pint --dirty --format agent.
- [ ] Confirm no unrelated files changed.

## Done

- [ ] Product defaults are safely stored and backfilled.
- [ ] Product creation is a clear two-stage workflow.
- [ ] New variants receive independent prefilled details.
- [ ] Existing variants are never silently overwritten.
- [ ] Frames retain structured details and AR presets.
- [ ] Contact lenses and accessories use the generic editor.
- [ ] Patient APIs and optical snapshots use effective variant attributes.
- [ ] Incomplete Products never appear as purchasable catalog records.
