# Spec: Lens Inventory & Order Fulfillment

Status: Draft — awaiting review
Phase: Specification

## Assumptions

1. Lens products exist in the catalog (type `lens`) with variants tracked by stock.
2. `lens_types` table stays — it's the customer-facing category (Single Vision, Progressive, Bifocal) with base pricing.
3. Each lens product belongs to a `lens_type` (e.g., "Essilor Varilux 1.67" is type "progressive").
4. Order items gain an optional `lens_product_variant_id` FK for the specific lens variant staff assigns.
5. On order confirmation, both frame variant stock AND lens product variant stock deduct.
6. Customer (mobile) still picks `lens_type_id` only — staff assigns the specific lens product during processing.
7. The Filament order confirm/review flow gains a lens product variant selector per item.
8. Mobile API `GET /products` returns only `product_type = 'frame'`. All other types are admin-panel-only.
9. If no lens product variant is assigned, only frame stock deducts (backwards-compatible with existing orders).
10. Existing tests stay green — adapt, don't break.

Correct these before approval.

## Objective

Connect lens inventory to the order workflow so that when staff confirms an order, both frame and lens stock deduct correctly. A clinic can track lens blank consumption and know when to reorder.

**Users:**
- Customer: picks frame + lens type (unchanged)
- Staff: assigns specific lens product variant during order processing, sees lens inventory levels

**Success:** After order confirmation, both frame variant `stock_quantity` and lens product variant `stock_quantity` decrease by the order item quantity.

## Tech Stack

Same as all previous phases. No new dependencies.

## Commands

```
Run tests:          vendor/bin/sail artisan test --compact
Run filtered:       vendor/bin/sail artisan test --compact --filter=SomeName
Fresh seed:         vendor/bin/sail artisan migrate:fresh --seed --no-interaction
Format PHP:         vendor/bin/sail bin pint --dirty --format agent
```

## Boundaries

- **Always:** Run affected tests after each task. Run pint after PHP edits. Keep existing tests green.
- **Ask first:** Changing the mobile API order submission contract. Changing order_items structure beyond adding columns.
- **Never:** Break the demo seed flow. Make lens product assignment mandatory (it's optional — existing orders without it still work).

## Decisions

1. `lens_types` gains a `lens_type_id` FK on the `products` table (only for products where `product_type = 'lens'`). This links lens products to their category.
2. `order_items` gains nullable `lens_product_variant_id` FK. When populated, that variant's stock deducts on confirmation.
3. Staff assigns the lens product variant via the Filament order confirm flow — not during initial order creation.
4. If staff confirms without assigning a lens product variant, only frame deducts (graceful degradation).
5. Mobile API filters products to `frame` only.
6. Lens product variant price can override `lens_type` base price on the order item. If staff assigns a specific lens, its price is used for billing.
7. Cancellation of a confirmed order restores both frame AND lens stock.

## Success Criteria

- [ ] Lens products (type `lens`) can be linked to a `lens_type` via FK.
- [ ] Mobile API `GET /products` returns only frame products.
- [ ] `order_items.lens_product_variant_id` nullable FK exists.
- [ ] Staff can assign a lens product variant per order item during processing (Filament UI).
- [ ] Order confirmation deducts both frame variant stock and lens product variant stock.
- [ ] Order cancellation (from confirmed) restores both.
- [ ] Orders without a lens product variant assigned still confirm normally (frame-only deduction).
- [ ] `lens_type_price` on order item is overridden by lens product variant price when one is assigned.
- [ ] Inventory movement records created for both frame and lens deductions.
- [ ] All existing tests remain green.
- [ ] Demo seed includes a lens product with stock.

## Implementation Plan

### Task 1: Add `lens_type_id` FK to products table

**Description:** Link lens products to their lens type category.

**Acceptance criteria:**
- [ ] `products.lens_type_id` nullable FK (only relevant for `product_type = 'lens'`).
- [ ] Product model has `lensType()` BelongsTo relationship.
- [ ] LensType model has `products()` HasMany relationship.
- [ ] Product form shows lens type select (only visible when `product_type = 'lens'`).

**Verification:**
- [ ] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=Product`

**Files likely touched:**
- New migration
- `app/Models/Product.php`
- `app/Models/LensType.php`
- `app/Filament/Resources/Products/Schemas/ProductForm.php`

**Estimated scope:** S

---

### Task 2: Filter mobile API to frame products only

**Description:** `GET /products` returns only `product_type = 'frame'`.

**Acceptance criteria:**
- [ ] API product listing filters to `product_type = 'frame'`.
- [ ] API product detail returns 404 for non-frame products.
- [ ] Existing product catalog tests adapted.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=ProductCatalog`

**Files likely touched:**
- `app/Http/Controllers/Api/ProductController.php`
- `tests/Feature/Api/ProductCatalogTest.php`

**Estimated scope:** S

---

### Task 3: Add `lens_product_variant_id` to order items

**Description:** Allow order items to reference a specific lens product variant for inventory tracking.

**Acceptance criteria:**
- [ ] `order_items.lens_product_variant_id` nullable FK to `product_variants`.
- [ ] OrderItem model has `lensProductVariant()` BelongsTo relationship.
- [ ] Existing order creation (API + Filament) continues to work without this field (null by default).

**Verification:**
- [ ] Fresh seed succeeds
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=Order`

**Files likely touched:**
- New migration
- `app/Models/OrderItem.php`

**Estimated scope:** S

---

### Task 4: Staff assigns lens product variant during order processing

**Description:** Filament order edit/confirm flow lets staff select a lens product variant per item.

**Acceptance criteria:**
- [ ] Order edit page shows order items with an "Assign Lens Product" selector per item.
- [ ] Selector is filtered to lens products matching the item's `lens_type_id`.
- [ ] Staff can save the assignment before or at confirmation time.
- [ ] Assignment is optional — staff can confirm without it.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=OrderResource`

**Files likely touched:**
- `app/Filament/Resources/Orders/Pages/EditOrder.php` or a RelationManager/infolist
- `app/Filament/Resources/Orders/Schemas/OrderForm.php`

**Estimated scope:** M

---

### Task 5: Dual inventory deduction on order confirmation

**Description:** When an order is confirmed, deduct stock for both frame variant AND lens product variant (if assigned).

**Acceptance criteria:**
- [ ] Frame variant stock deducts (existing behavior — unchanged).
- [ ] Lens product variant stock deducts if `lens_product_variant_id` is set.
- [ ] Inventory movement records created for both.
- [ ] If lens product variant is not assigned, only frame deducts (no error).
- [ ] `lens_type_price` on order item is overridden by lens product variant price when assigned.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=InventoryMovement`
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=OrderProcessing`

**Files likely touched:**
- `app/Actions/Orders/UpdateOrderStatus.php`

**Estimated scope:** M

---

### Task 6: Dual inventory restoration on cancellation

**Description:** When a confirmed order is cancelled, restore stock for both frame AND lens product variant.

**Acceptance criteria:**
- [ ] Frame variant stock restored (existing behavior).
- [ ] Lens product variant stock restored if it was deducted.
- [ ] Reversal inventory movement records created for both.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=InventoryMovement`

**Files likely touched:**
- `app/Actions/Orders/UpdateOrderStatus.php`

**Estimated scope:** S

---

### Task 7: Seed data and final verification

**Description:** Add demo lens products with stock. Verify full workflow.

**Acceptance criteria:**
- [ ] CatalogSeeder includes lens products linked to lens types (e.g., "Essilor Varilux Progressive 1.67" → lens_type: progressive, stock: 10).
- [ ] `migrate:fresh --seed` succeeds.
- [ ] Full test suite green.
- [ ] Pint clean.

**Verification:**
- [ ] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Files likely touched:**
- `database/seeders/CatalogSeeder.php`
- `docs/BACKEND_CONTEXT.md`

**Estimated scope:** S

---

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Existing order tests break because of new FK | Low | FK is nullable, default null — existing flows unaffected |
| Mobile app breaks if product API changes | Med | Filter is additive (was returning all active, now only frames) — Android dev needs to know |
| Lens product variant assignment UX is complex | Med | Keep it simple — select dropdown per item, not a separate workflow |
| Dual deduction logic in UpdateOrderStatus becomes complex | Low | Same pattern as existing frame deduction — just loop twice |

## Summary

| Task | Effort |
|------|--------|
| 1: lens_type_id on products | S |
| 2: Mobile API frame filter | S |
| 3: lens_product_variant_id on order items | S |
| 4: Staff lens assignment UI | M |
| 5: Dual inventory deduction | M |
| 6: Dual inventory restoration | S |
| 7: Seed data + verification | S |
| **Total: 7 tasks** | |

## Review Gate

Awaiting approval. Implementation one task at a time with tests and checkpoints.
