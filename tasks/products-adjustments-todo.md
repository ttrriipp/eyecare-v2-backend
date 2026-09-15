# Product Entry and Variant Adjustments Checklist

Status: Proposed — awaiting product-owner confirmation
Plan: `tasks/products-adjustments-plan.md`

## Decisions

- [ ] Confirm variant-name uniqueness is scoped per product and includes
  inactive/archived variants.
- [ ] Confirm positive opening stock must create Inventory History.
- [ ] Confirm which contact-lens designs the clinic will stock.
- [ ] Confirm whether base curve, diameter, and pack size are mandatory for new
  contact-lens variants.

## Phase 1: Guardrails and consistency

- [ ] Task 1: Remove product edit-page Activate/Deactivate heading actions while
  retaining row and bulk lifecycle actions.
- [ ] Task 2: Audit duplicates and enforce normalized, per-product variant-name
  uniqueness in Filament and the database.
- [ ] Task 3: Extract and adopt one shared variant form schema.

### Foundation checkpoint

- [ ] Focused product and variant tests pass through Sail.
- [ ] Frame, contact-lens, and accessory forms render the shared schema.
- [ ] Patient API response fields remain unchanged.

## Phase 2: Guided, type-aware entry

- [ ] Task 4: Add pricing/inventory helper text and relational validation.
- [ ] Task 5: Improve frame measurement, color, and material inputs.
- [ ] Task 6: Add lens-design-aware contact-lens fields and validation.
- [ ] Task 7: Replace raw accessory attributes with guided key/value details.

### Guided-entry checkpoint

- [ ] All three product types pass focused create/edit tests.
- [ ] Existing seeded variants save without attribute loss.
- [ ] Validation errors identify the exact variant and field.

## Phase 3: Auditable opening stock

- [ ] Task 8: Capture opening stock through inventory movement/lot actions.
- [ ] Task 9: Run focused regressions, one bounded browser verification pass,
  and Pint.

### Completion checkpoint

- [ ] Variant-name duplication is prevented at UI and database layers.
- [ ] Positive opening quantities always have movement provenance.
- [ ] Contact-lens opening quantities always have lot and expiry provenance.
- [ ] Patient-facing API behavior remains backward compatible.
- [ ] Product owner accepts the final workflow.

