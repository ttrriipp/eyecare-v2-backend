# Spec: Backend Polish & Improvements

Status: Draft — awaiting review
Phase: Specification

## Assumptions

1. Data integrity tables (`inventory_movement_statuses`, `notification_channels`, `notification_templates`) are kept as-is — no removal.
2. All previous specs are implemented.
3. These are quality improvements, not new features. Existing tests stay green.
4. No new dependencies.

## Objective

Close remaining quality gaps: fix order subtotal recalculation, make `lens_type_id` nullable on order items, add pagination to API endpoints, clean up dead scaffold files, and improve admin panel navigation.

## Tech Stack

Same. PHP 8.5, Laravel 13, Filament 5, Pest 4, MySQL, Sail.

## Commands

```
Run tests:       vendor/bin/sail artisan test --compact
Filtered:        vendor/bin/sail artisan test --compact --filter=SomeName
Fresh seed:      vendor/bin/sail artisan migrate:fresh --seed --no-interaction
Format:          vendor/bin/sail bin pint --dirty --format agent
```

## Boundaries

- **Always:** Run affected tests. Pint after PHP edits. Keep tests green.
- **Ask first:** Changing API response shape (pagination adds meta fields — Android dev needs to know).
- **Never:** Break the demo seed. Delete tables without approval.

## Success Criteria

- [ ] Order `subtotal` field recalculates correctly when lens product variant is assigned.
- [ ] `order_items.lens_type_id` is nullable — orders for accessories/contact lenses don't need a lens type.
- [ ] `GET /products` and `GET /orders` are paginated.
- [ ] Products table shows a `Type` column.
- [ ] Settings nav group exists for lookup resources.
- [ ] Dead scaffold files removed (unused pages, forms for read-only resources).
- [ ] All tests pass.

## Implementation Plan

### Task 1: Fix order `subtotal` recalculation on lens assignment

**Description:** When staff assigns a lens product variant via ItemsRelationManager, the order-level `subtotal` should also be recalculated (currently only `total_amount` updates).

**Acceptance criteria:**
- [ ] `order.subtotal` = sum of all `order_items.subtotal` values.
- [ ] Recalculates alongside `total_amount` in the `assignLens` action.
- [ ] `total_amount` = `subtotal` - `discount_amount` (preserves existing discount).

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=OrderResource`

**Files likely touched:**
- `app/Filament/Resources/Orders/RelationManagers/ItemsRelationManager.php`
- Existing test adaptation

**Estimated scope:** S

---

### Task 2: Make `lens_type_id` nullable on order items

**Description:** Not every order item needs a lens type (accessories, contact lenses ordered standalone). Currently the API requires it.

**Acceptance criteria:**
- [ ] `order_items.lens_type_id` is nullable in the database.
- [ ] API `StoreOrderRequest` validates `lens_type_id` as `nullable|exists:lens_types,id` per item.
- [ ] Filament order create form allows blank lens type selection.
- [ ] Orders for non-lens products work without a lens type.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=Order`

**Files likely touched:**
- New migration (alter `lens_type_id` nullable if not already)
- `app/Http/Requests/Api/StoreOrderRequest.php`
- `app/Filament/Resources/Orders/Schemas/OrderForm.php`
- `app/Http/Controllers/Api/OrderController.php`
- `app/Filament/Resources/Orders/Pages/CreateOrder.php`

**Estimated scope:** S

---

### Task 3: Paginate `GET /products` and `GET /orders` API endpoints

**Description:** Both endpoints return all records. Add cursor or page-based pagination.

**Acceptance criteria:**
- [ ] `GET /products` returns paginated response (default 15 per page, accepts `?per_page=` param).
- [ ] `GET /orders` returns paginated response.
- [ ] Response includes `meta` (current_page, last_page, total) and `links` (next, prev).
- [ ] Existing tests adapted for paginated response structure.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=ProductCatalog`
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=OrderRequest`

**Files likely touched:**
- `app/Http/Controllers/Api/ProductController.php`
- `app/Http/Controllers/Api/OrderController.php`
- `tests/Feature/Api/ProductCatalogTest.php`
- `tests/Feature/Api/OrderRequestTest.php`

**Estimated scope:** S

---

### Task 4: Add `Type` column to products table

**Description:** Show product type as a badge column in the products list.

**Acceptance criteria:**
- [ ] Products table shows a `Type` column with badge (Frame/Lens/Contact Lens/Accessory).
- [ ] Column is filterable (already done — just adding the display column).

**Verification:**
- [ ] Manual verification: column visible in table.

**Files likely touched:**
- `app/Filament/Resources/Products/Tables/ProductsTable.php`

**Estimated scope:** S

---

### Task 5: Settings navigation group

**Description:** Group lookup/config resources under a "Settings" navigation group.

**Acceptance criteria:**
- [ ] Categories, Brands, Lens Types, Visit Reasons show under "Settings" in the sidebar.
- [ ] Other operational resources remain at the top level.

**Verification:**
- [ ] Manual verification: sidebar groups correctly.

**Files likely touched:**
- `app/Filament/Resources/Categories/CategoryResource.php`
- `app/Filament/Resources/LensTypes/LensTypeResource.php`
- `app/Filament/Resources/VisitReasons/VisitReasonResource.php`
- Add a BrandResource if it exists, otherwise create one or add `$navigationGroup` inline.

**Estimated scope:** S

---

### Task 6: Product type UX fixes

**Description:** Disable product type on edit, show attributes KeyValue for all product types.

**Acceptance criteria:**
- [ ] Product type select is disabled on edit form (`->disabledOn('edit')->dehydrated()`).
- [ ] Attributes KeyValue is visible for ALL product types on both the inline Repeater and the VariantsRelationManager form (remove the `frame`-only condition).
- [ ] AR fields (ar_eligible, ar_asset_reference) remain frame-only.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=Product`

**Files likely touched:**
- `app/Filament/Resources/Products/Schemas/ProductForm.php`
- `app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php`

**Estimated scope:** S

---

### Task 7: Remove dead scaffold files

**Description:** Delete unused Create/Edit pages and form schemas for read-only resources.

**Acceptance criteria:**
- [ ] `InventoryMovements/Pages/CreateInventoryMovement.php` — deleted
- [ ] `InventoryMovements/Pages/EditInventoryMovement.php` — deleted
- [ ] `InventoryMovements/Schemas/InventoryMovementForm.php` — deleted
- [ ] No references remain.
- [ ] Tests still pass.

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact`

**Files likely touched:**
- Delete 3 files

**Estimated scope:** S

---

### Task 8: Remove stock_quantity from variant edit form

**Description:** Make stock_quantity read-only in the variant edit form. All stock changes must go through the "Adjust Stock" action to ensure movements are recorded.

**Acceptance criteria:**
- [ ] `stock_quantity` field is disabled on the VariantsRelationManager edit form.
- [ ] `stock_quantity` field is disabled on the inline variant repeater on edit.
- [ ] Stock changes only happen through the Adjust Stock action (already records movements).
- [ ] `low_stock_threshold` remains editable (it's a config value, not a stock movement).

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=Product`

**Files likely touched:**
- `app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php`
- `app/Filament/Resources/Products/Schemas/ProductForm.php`

**Estimated scope:** S

---

### Task 9: Graceful insufficient stock handling

**Description:** Catch `RuntimeException` from `RecordInventoryMovement` in `UpdateOrderStatus` and convert to a `ValidationException` so staff sees a clear error instead of a 500.

**Acceptance criteria:**
- [ ] When order confirmation fails due to insufficient stock, a validation error is returned (not a crash).
- [ ] The error message includes the variant name and available stock.
- [ ] The order status remains unchanged (transaction rolled back).

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=OrderProcessing`

**Files likely touched:**
- `app/Actions/Orders/UpdateOrderStatus.php`

**Estimated scope:** S

---

### Task 10: Drop unused `inventory_movement_statuses` table

**Description:** Remove the `inventory_movement_statuses` table, model, and seeder. It's never referenced by any FK or code.

**Acceptance criteria:**
- [ ] Migration drops `inventory_movement_statuses` table.
- [ ] `app/Models/InventoryMovementStatus.php` — deleted.
- [ ] `database/seeders/InventoryMovementStatusSeeder.php` — deleted.
- [ ] Removed from `DatabaseSeeder.php`.
- [ ] No other references remain.

**Verification:**
- [ ] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] `vendor/bin/sail artisan test --compact`

**Files likely touched:**
- New migration (drop table)
- Delete model + seeder
- `database/seeders/DatabaseSeeder.php`

**Estimated scope:** S

---

### Task 11: Final verification

**Verification:**
- [ ] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] Update `docs/BACKEND_CONTEXT.md`

---

## Summary

| Task | Description | Effort |
|------|-------------|--------|
| 1 | Fix order subtotal recalculation | S |
| 2 | Make lens_type_id nullable on order items | S |
| 3 | Paginate products + orders API | S |
| 4 | Type column on products table | S |
| 5 | Settings navigation group | S |
| 6 | Product type UX fixes (disable on edit, show attributes for all types) | S |
| 7 | Remove dead scaffold files | S |
| 8 | Remove stock_quantity from variant edit form (read-only, changes only via Adjust Stock) | S |
| 9 | Graceful insufficient stock handling (catch RuntimeException → ValidationException) | S |
| 10 | Drop unused `inventory_movement_statuses` table and model/seeder | S |
| 11 | Final verification | S |
| **Total: 11 tasks** | All S | |

## Review Gate

Awaiting approval. All tasks are small and independent (except Task 7 depends on all others).
