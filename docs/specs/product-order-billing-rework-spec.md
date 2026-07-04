# Spec: Product Taxonomy, Order Flow & Billing Rework

## Objective

Rework three interconnected systems to better reflect actual optical clinic operations:

1. **Product taxonomy simplification** — Collapse 4 product types into 3 (`frame`, `lens`, `general`). Only `frame` and `lens` have special system behavior; everything else (contact lenses, solutions, accessories, cases) is `general` and organized by categories.

2. **Order flow improvements** — Admin-created orders skip `requested` and start at `confirmed` (editable). Inventory commits at `processing` (not `confirmed`). Lens category selection and lens assignment happen on the edit form during `confirmed` status. Single "New Order" button replaces dual buttons.

3. **Billing simplification** — Remove OR number. Remove discount duplication (discount lives on billing only). Auto-generate billing at `processing`. Support pre-linking orders to existing billings. Add notes field. Remove "Bill Service" actions from appointment/patient pages.

**Rename:** "Lens Type" → "Lens Category" throughout the system.

**Users:** Admin and staff operating the Filament panel; customers using the mobile API.

**Success:** The system accurately models the optical clinic workflow where orders are prepared during `confirmed` status, committed at `processing`, and billing is the single source of truth for discounts and charges.

---

## Tech Stack

- PHP 8.5, Laravel 13, Filament 5, Pest 4
- No new dependencies required

## Commands

```
Test:    vendor/bin/sail artisan test --compact
Filter:  vendor/bin/sail artisan test --compact --filter=Name
Lint:    vendor/bin/sail bin pint --dirty --format agent
Migrate: vendor/bin/sail artisan migrate
Fresh:   vendor/bin/sail artisan migrate:fresh --seed
```

## Project Structure

```
app/Models/
  Product.php                    → product_type values change
  LensType.php                   → renamed to LensCategory
  Order.php                      → discount fields removed; billing_id FK + preLinkedBilling()/resolvedBilling() added
  OrderItem.php                  → lens_type_id renamed to lens_category_id
  Billing.php                    → or_number removed, notes added
database/migrations/             → new migrations for schema changes (includes orders.billing_id, not in original plan — see Decisions)
app/Actions/Orders/
  UpdateOrderStatus.php          → inventory commits at processing, not confirmed; cancel-void respects shared billings
  ApplyDiscount.php              → deleted (discount is billing-only)
app/Actions/Billing/
  GenerateBillingForOrder.php    → triggered at processing, handles pre-linked billing
  AddOrderItemsToBilling.php     → no longer overwrites billing.order_id once set (multi-order encounter fix)
  CreateServiceBilling.php       → deleted (Task 17, after its callers were removed)
app/Filament/Resources/
  Orders/                        → single New Order button, lens UI on edit only, billing_id pre-link support on create
  Billings/                      → Create page, "Create Order" action, remove OR#
  Appointments/                  → remove "Bill Service" action, add BillingsRelationManager
  Patients/                      → remove "Bill Service" action
  LensCategories/                → renamed from LensTypes
app/Http/Controllers/Api/        → update product API (frame + general), order API excludes lens products from line items
```

## Code Style

Follow existing patterns. Key conventions for this spec:
- Status transitions always go through action classes
- Filament actions use `Action::make()` from `Filament\Actions\Action`
- Migrations never modify deployed migrations — always create new ones
- Renaming uses new migrations + model updates (not editing old migrations)

## Testing Strategy

- Pest feature tests for all changed behavior
- Each feature gets its own test file or updates to existing test files
- Critical paths: order status transitions, inventory commit timing, billing generation, lens assignment gates
- Full suite must stay green after each task
- Run filtered tests during development, full suite at checkpoints

## Boundaries

- **Always:** Run tests before commits; update BACKEND_CONTEXT.md at the end; stage only relevant files
- **Ask first:** Nothing — all decisions made in this spec
- **Never:** Break existing Android API contract without migration path; delete test files without approval; modify deployed migrations

---

## Decisions & Rationale

### Product Types: `frame`, `lens`, `general`

| Type | Special behavior | Use case |
|---|---|---|
| `frame` | AR fields, paired with lens on orders, shown on mobile API | Eyeglass frames |
| `lens` | Has lens category, staff-assigned to frame items | Prescription lenses (progressive, single vision, etc.) |
| `general` | No special behavior — categories organize them | Contact lenses, solutions, cases, accessories, cleaning kits, etc. |

**Why:** Only `frame` and `lens` trigger actual code paths. Everything else is organizational — that's what `product_categories` handles. Adding a new product type (e.g., "eye drops") is now a category row, not a code change.

**Migration from current types:**
- `frame` → stays `frame`
- `lens` → stays `lens`
- `contact_lens` → becomes `general`
- `accessory` → becomes `general`

### Order Status Flow

| Source | Starting status | Flow |
|---|---|---|
| Customer (mobile) | `requested` | `requested → confirmed → processing → ready_for_pickup → completed` |
| Admin/Staff (Filament) | `confirmed` | `confirmed → processing → ready_for_pickup → completed` |

- `cancelled` available from any non-terminal status
- **Inventory commits at `processing`** (not `confirmed`)
- **Order editable during `confirmed`** — items, quantities, lens category, lens assignment
- **Locked at `processing`** — no further edits
- Lens gate: cannot advance to `processing` unless all frame items have lens assigned (when `is_non_prescription = false`)
- Prescription gate: still applies at `processing` (moved from `confirmed`)

### Billing Flow

- **Auto-generates at `processing`** — when order advances to `processing`, billing is created (or items are added to a pre-linked billing)
- **Pre-linked billing:** If an order has `billing_id` set (via "Create Order" action on a billing), items attach to that billing instead of creating a new one
- **Encounter grouping preserved:** If a billing already exists for the same `appointment_id`, order items merge into it
- **Multi-order billings:** A billing can accumulate items from more than one order (either via appointment-based encounter grouping or explicit pre-linking). `billings.order_id` records only the *first* order that generated the billing — it is never overwritten by subsequent orders attaching items to the same billing. `Order::resolvedBilling()` is the correct accessor for "the billing this order's items are on" since it checks the pre-linked FK (`billing_id`) before falling back to the `order_id`-based relationship. Cancelling one order on a shared billing does not void the billing if other active orders still reference it.
- **Discount lives on billing only** — removed from orders table
- **OR number removed** — `billing_number` is the sole identifier
- **Notes field added** — nullable text for staff annotations
- **Manual creation:** Staff can create standalone billings from Billings resource (for services without an order)
- **"Create Order" action on ViewBilling:** Creates an order pre-linked to that billing, customer pre-filled

### Removed Features

- "Bill Service" header action on Appointment edit page
- "Bill Service" header action on Patient edit page
- "Walk-in Sale" separate button (replaced by single "New Order" that starts at `confirmed`)
- OR number (`or_number` column, generation logic, all UI/PDF references)
- Discount fields on orders (`discount_type_id`, `discount_amount` on `orders` table)
- `contact_lens` and `accessory` product type values (migrated to `general`)

### Renamed

- `lens_types` table → `lens_categories`
- `LensType` model → `LensCategory`
- `lens_type_id` FK on products → `lens_category_id`
- `lens_type_id` FK on order_items → `lens_category_id`
- All Filament UI labels: "Lens Type" → "Lens Category"
- Settings nav: "Lens Types" → "Lens Categories"

### Mobile API Changes

- `GET /products` returns `frame` + `general` products (was frame-only)
- `POST /orders` still works — `items[].lens_type_id` renamed to `items[].lens_category_id` (with backward compat alias during transition)
- Product response `product_type` values: `frame`, `lens`, `general` (no more `contact_lens` or `accessory`)

### Walk-in Appointments

- Keep as quick intake records
- Staff creates with "now" as scheduled time
- No conflict check for walk-in appointments (bypass slot validation)
- Helps "Today's Schedule" widget show full clinic picture

---

## Success Criteria

- [x] Product form shows 3 types: Frame, Lens, General
- [x] Existing `contact_lens` and `accessory` products migrated to `general`
- [x] "Lens Type" renamed to "Lens Category" in all UI, models, and tables
- [x] Admin-created orders start at `confirmed` (single "New Order" button)
- [x] Customer-submitted orders start at `requested` (unchanged)
- [x] Orders are fully editable in `confirmed` status (items, quantities, lens category, lens assignment)
- [x] Orders lock at `processing` — no edits allowed
- [x] Inventory commits when order advances to `processing`
- [x] Billing auto-generates at `processing` (or attaches to pre-linked billing)
- [x] Discount fields removed from orders table; discount lives on billing only
- [x] OR number removed from billing (column, logic, UI, PDFs)
- [x] Billing has a `notes` field, editable while not voided
- [x] "Bill Service" actions removed from Appointment and Patient pages
- [x] "Create Order" action exists on ViewBilling page
- [x] Product selector on order form excludes `lens` type products
- [x] Lens category field on order items only shown for `frame` type products
- [x] Mobile API returns frame + general products
- [x] Walk-in appointments bypass slot conflict check (unchanged from before this spec — verified still true, not modified)
- [ ] All existing tests updated and passing — 18 pre-existing failures remain, confined to 4 files (`CatalogSchemaTest`, `DemoAccountsSeedTest`, `InventoryMovementTest`, `LensTypePricingTest`), scoped to Task 19
- [ ] Full test suite green — 534/552 passing as of the Phase 4 code review; Task 19 closes the gap

---

## Open Questions

- **OR number vs. BIR compliance:** Earlier specs (`docs/specs/defense-hardening-spec.md` and related) documented `or_number` as "required for BIR-compliant Official Receipt issuance in the Philippines." This spec removes it per explicit instruction. `billing_number` is now the sole identifier on receipts. **This is a business/legal question, not a code question** — confirm with clinic stakeholders whether `billing_number` alone satisfies real-world BIR Official Receipt requirements before this ships to production, or whether a compliant OR numbering scheme needs to be reintroduced under a different name.

---

## Deviations From Original Plan & Review Findings

Recorded during implementation and a subsequent five-axis code review (see `code-review-and-quality` skill). This section exists because the spec is a living document — these are the points where the actual build diverged from what was originally planned above, and why.

1. **`orders.billing_id` FK was not in the original file list** but was required to implement "pre-linked billing" (Billing Flow decisions, above). Added as part of Task 9. `Order::preLinkedBilling()` (belongsTo) and `Order::resolvedBilling()` (billing_id first, falls back to the order_id-based hasOne) were added to support this correctly.

2. **`CreateServiceBilling` deletion deferred from Task 9 to Task 17.** The spec's Task 9 says to delete it, but it was still used by the "Bill Service" actions on Appointment/Patient pages, which aren't removed until Task 17. Deleting it in Task 9 would have broken working functionality prematurely. Deleted in Task 17 once its only callers were removed — correct dependency order even though it differs from the literal Task 9 wording.

3. **Code review (post-Phase 4) found and fixed a Critical bug in the pre-linked billing feature:** `AddOrderItemsToBilling` unconditionally overwrote `billings.order_id` on every call. When a billing is shared by more than one pre-linked order (the exact scenario Task 9/15 built), the second order's items being added would silently overwrite the first order's link to that billing — breaking `Order::billing()`, the "View Billing" button, and the cancellation billing-void logic for the first order. Fixed by: no longer overwriting `order_id` once set; adding `Order::resolvedBilling()`; making the cancellation-void logic check whether the billing is exclusively this order's before voiding it. Regression tests added in `AddOrderItemsToBillingTest.php` and `UpdateOrderStatusTest.php`.

4. **Code review also found a gap in Task 12 (order API):** `StoreOrderRequest` validated `product_variant_id` against active products only, without excluding `product_type = 'lens'`. Every Filament admin surface (Tasks 10/11) excludes lens products from order item selection since lenses are staff-assigned to frame items, never ordered directly — the mobile API allowed customers to bypass this. Fixed by adding `whereIn('product_type', ['frame', 'general'])` to the validation rule, with a regression test in `OrderRequestTest.php`.

5. **`CreateOrder`'s customer-mismatch guard (pre-linked billing validation) is not wrapped in a try/catch** the way `EditOrder`'s equivalent status-transition call is. Verified directly (not just asserted) that the thrown `ValidationException` is well-formed and does prevent order creation — confirmed via reflection test that no order is persisted on mismatch. The open question is purely whether Filament/Livewire renders this as an inline form error or a less graceful error state during the live HTTP request cycle; this is a UX polish item, not a data-integrity bug. Left as-is; revisit if staff report a confusing error screen in practice.

6. **Migration data-loss pattern flagged, not fixed:** `remove_discount_from_orders` and `consolidate_product_types` migrations have `down()` paths that don't restore data (order discount amounts, contact_lens/accessory distinction). Not exploitable currently — dev database, no production data. **Before this ships to a real production database with real order/discount history, add a data-preservation step** (e.g., snapshot to an audit table) to both migrations, or accept the data loss explicitly with stakeholder sign-off.

---

## Phase 2: Implementation Plan

### Architecture Decisions

1. **Database migrations first** — Rename tables/columns, add `notes`, remove `or_number`, remove order discount columns, migrate product types. All done as new migrations (never edit deployed ones).

2. **Models & relationships second** — Rename `LensType` → `LensCategory`, update all FK references, update casts/fillable.

3. **Action classes third** — Move inventory commit from `confirmed` to `processing` in `UpdateOrderStatus`. Move billing generation trigger. Update `ApplyDiscount` to work on billings only.

4. **Filament resources fourth** — Update forms, tables, actions, relation managers.

5. **API controllers last** — Update product filtering, order creation validation, response shapes.

### Dependency Graph

```
Migrations ──→ Models ──→ Actions ──→ Filament ──→ API
                                  ──→ Tests (parallel with Filament/API)
```

### Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Renaming lens_type columns breaks existing data | High | Migration renames columns in-place, no data loss |
| Moving inventory commit to `processing` leaves confirmed orders without stock hold | Medium | Acceptable — `confirmed` is a preparation stage, not a commitment. Stock checks happen at `processing`. |
| Removing discount from orders breaks existing order total calculations | High | Subtotal stays on orders (sum of items). `total_amount` on orders becomes same as subtotal. Discount only affects billing total. |
| Mobile API `lens_type_id` rename breaks Android app | High | Support both `lens_type_id` and `lens_category_id` in API request/response during transition. Deprecate old name. |
| Pre-linked billing edge cases (order cancelled after linking) | Medium | If order is cancelled, remove its items from the pre-linked billing. If billing becomes empty, void it. |
| Walk-in appointment bypass could allow scheduling conflicts | Low | Walk-ins are "now" appointments — conflicts are irrelevant for immediate visits. |

---

## Phase 3: Tasks

### Phase 1: Schema + Model Foundation (Lens Category Rename)

#### Task 1: Migration — rename lens_types table and FK columns (✅ DONE — 65f9d94)
**Description:** Create migration to rename `lens_types` → `lens_categories`, `products.lens_type_id` → `products.lens_category_id`, `order_items.lens_type_id` → `order_items.lens_category_id`.

**Acceptance criteria:**
- [x] `lens_types` table renamed to `lens_categories`
- [x] `products.lens_type_id` renamed to `products.lens_category_id`
- [x] `order_items.lens_type_id` renamed to `order_items.lens_category_id`
- [x] Migration is reversible

**Verification:** `vendor/bin/sail artisan migrate && vendor/bin/sail artisan migrate:rollback && vendor/bin/sail artisan migrate`

**Dependencies:** None

**Files:**
- `database/migrations/YYYY_MM_DD_rename_lens_types_to_lens_categories.php`

**Size:** S

---

#### Task 2: Rename LensType model → LensCategory + update relationships (✅ DONE — 6cec40c)
**Description:** Rename model file, class, factory, seeder. Update `Product::lensCategory()` and `OrderItem::lensCategory()` relationships. Update all imports across the codebase.

**Acceptance criteria:**
- [x] `LensCategory` model exists referencing `lens_categories` table
- [x] `Product::lensCategory()` works (was `lensType()`)
- [x] `OrderItem::lensCategory()` works (was `lensType()`)
- [x] No remaining `LensType` references in app/ or database/ directories
- [x] Factory creates records successfully

**Verification:** `vendor/bin/sail artisan tinker --execute 'App\Models\LensCategory::count();'`

**Dependencies:** Task 1

**Files:**
- `app/Models/LensCategory.php` (new, LensType.php deleted)
- `app/Models/Product.php`
- `app/Models/OrderItem.php`
- `database/factories/LensCategoryFactory.php`
- `database/seeders/` (any referencing LensType)

**Size:** M

---

#### Task 3: Rename LensType Filament resource → LensCategory (✅ DONE — cbd8ccd)
**Description:** Rename the Filament resource class, pages, and relation managers. Update navigation label to "Lens Categories" under Settings.

**Acceptance criteria:**
- [x] `LensCategoryResource` exists with correct model reference
- [x] Navigation shows "Lens Categories" under Settings group
- [x] Relation manager on edit page shows lens products correctly
- [x] Tests for the resource pass

**Verification:** `vendor/bin/sail artisan test --compact --filter=LensCategory`

**Dependencies:** Task 2

**Files:**
- `app/Filament/Resources/LensCategoryResource.php` (renamed)
- `app/Filament/Resources/LensCategoryResource/Pages/*.php`

**Size:** S

---

### ✅ Checkpoint 1 — Lens Category Rename Complete (DONE — commits 65f9d94, 6cec40c, cbd8ccd)
- [x] `migrate:fresh --seed` runs cleanly
- [x] LensCategory resource accessible in panel
- [x] No references to `LensType` remain (except deferred old-migration files, which are never edited)

---

### Phase 2: Product Type Simplification

#### Task 4: Migration — consolidate product types + update seeder (✅ DONE — 82978c6)
**Description:** Migrate `contact_lens` and `accessory` product type values to `general`. Update seeders to use new type values.

**Acceptance criteria:**
- [x] All `contact_lens` products now have type `general`
- [x] All `accessory` products now have type `general`
- [x] Seeders create products with types: `frame`, `lens`, `general`
- [x] Migration documents why type conversion is not reversible (data loss)

**Verification:** `vendor/bin/sail artisan migrate && vendor/bin/sail artisan db:seed`

**Dependencies:** Task 1

**Files:**
- `database/migrations/YYYY_MM_DD_consolidate_product_types.php`
- `database/seeders/` (product seeders)

**Size:** S

---

#### Task 5: Update Product Filament resource for 3 types (✅ DONE — ea7e74d, 26f8fd6)
**Description:** Update type selector options to Frame/Lens/General. Update conditional form logic. Update table badges and filters.

**Acceptance criteria:**
- [x] Product type options: Frame, Lens, General
- [x] Frame → shows AR fields on variants
- [x] Lens → shows Lens Category selector (required)
- [x] General → no special fields
- [x] Table badges: Frame (blue), Lens (green), General (gray)
- [x] Type filter has 3 values

**Verification:** `vendor/bin/sail artisan test --compact --filter=ProductResource`

**Dependencies:** Task 4

**Files:**
- `app/Filament/Resources/Products/Schemas/ProductForm.php`
- `app/Filament/Resources/Products/Tables/ProductsTable.php`

**Size:** S

---

#### Task 6: Update product API to return frame + general (✅ DONE — bb277f7)
**Description:** Update `GET /products` to return both `frame` and `general` products. Update `GET /products/{id}` to allow both (404 only for `lens`).

**Acceptance criteria:**
- [x] `GET /products` returns frame + general products
- [x] `GET /products/{id}` returns 200 for frame and general, 404 for lens
- [x] Response shape unchanged
- [x] Filters still work
- [x] API tests pass

**Verification:** `vendor/bin/sail artisan test --compact --filter=ProductApi`

**Dependencies:** Task 4

**Files:**
- `app/Http/Controllers/Api/ProductController.php`
- `tests/Feature/Api/ProductApiTest.php` (update assertions)

**Size:** S

---

### ✅ Checkpoint 2 — Product Types Simplified (DONE — commits 82978c6, ea7e74d, bb277f7, 26f8fd6)
- [x] Only 3 product types in system
- [x] Admin panel shows correct type options
- [x] Mobile API returns frame + general
- [x] Tests pass

---

### Phase 3: Order Flow Rework

#### Task 7: Migration — remove discount columns from orders (✅ DONE — 4358c03)
**Description:** Drop `discount_type_id` and `discount_amount` from `orders` table. Update Order model (remove from fillable, remove `discountType()` relationship).

**Acceptance criteria:**
- [x] `orders.discount_type_id` column removed
- [x] `orders.discount_amount` column removed
- [x] Order model fillable updated
- [x] `Order::discountType()` relationship removed
- [x] Migration is reversible

**Verification:** `vendor/bin/sail artisan migrate`

**Dependencies:** None (parallel with Phase 2)

**Files:**
- `database/migrations/YYYY_MM_DD_remove_discount_from_orders.php`
- `app/Models/Order.php`

**Size:** S

---

#### Task 8: Rework UpdateOrderStatus — move gates + inventory to `processing` (✅ DONE — 0bd1e5c)
**Description:** Move prescription gate, lens gate, and inventory deduction from `confirmed` to `processing`. Make `requested → confirmed` a simple status change. Remove discount logic from this action.

**Acceptance criteria:**
- [x] `requested → confirmed` is a simple status update (no gates, no inventory)
- [x] `confirmed → processing` triggers: prescription gate, lens gate, inventory deduction
- [x] Cancelling from `processing`+ restores inventory
- [x] Cancelling from `confirmed` does NOT restore inventory
- [x] Discount code removed from action
- [x] SMS + audit logs still fire correctly

**Verification:** `vendor/bin/sail artisan test --compact --filter=UpdateOrderStatus`

**Dependencies:** Task 7

**Files:**
- `app/Actions/Orders/UpdateOrderStatus.php`
- `app/Actions/Orders/ApplyDiscount.php` (delete — discount now billing-only)
- `tests/Feature/Actions/UpdateOrderStatusTest.php` (update)

**Size:** M

---

#### Task 9: Move billing generation to `processing` + pre-linked billing support (✅ DONE — 6c35a81; fixed post-review in 8d0dccd)
**Description:** Update `GenerateBillingForOrder` to be triggered at `processing` (called from `UpdateOrderStatus`). Add pre-linked billing support: if order has `billing_id` set, attach items to that billing. Remove discount copying. Delete `CreateServiceBilling`.

**Acceptance criteria:**
- [x] Billing generates when order moves to `processing`
- [x] Pre-linked billing: if `order.billing_id` is set, items added to that billing
- [x] No pre-linked billing: creates new or reuses by appointment_id
- [x] No discount copied from order (discount applied directly on billing)
- [x] `CreateServiceBilling` action deleted
- [x] Tests for billing generation pass

**Verification:** `vendor/bin/sail artisan test --compact --filter=Billing`

**Dependencies:** Task 8

**Files:**
- `app/Actions/Billing/GenerateBillingForOrder.php`
- `app/Actions/Billing/AddOrderItemsToBilling.php`
- `app/Actions/Billing/CreateServiceBilling.php` (delete)
- `tests/Feature/Actions/BillingActionsTest.php` (update)

**Size:** M

---

#### Task 10: Update Order create form — single button, starts at `confirmed` (✅ DONE — ddf7d67)
**Description:** Remove "Walk-in Sale" header action. Single "New Order" button creates orders at `confirmed` status. Simplify create form: patient, product (frame/general only), variant, quantity. No lens fields on create.

**Acceptance criteria:**
- [x] Single "New Order" button on ListOrders (no Walk-in Sale)
- [x] Created orders start at `confirmed` status
- [x] Product selector excludes `lens` type products
- [x] No lens category or lens assignment fields on create form
- [x] Customer quick-create still works

**Verification:** `vendor/bin/sail artisan test --compact --filter=CreateOrder`

**Dependencies:** Task 8

**Files:**
- `app/Filament/Resources/Orders/Pages/CreateOrder.php`
- `app/Filament/Resources/Orders/Pages/ListOrders.php`

**Size:** S

---

#### Task 11: Update Order edit form — lens fields in `confirmed`, locked at `processing` (✅ DONE — 90c6510)
**Description:** Add lens category selector and lens product variant assignment to the edit form, visible only during `confirmed` status and only for frame-type items. Lock all fields when status is `processing` or beyond. Remove discount fields from edit form.

**Acceptance criteria:**
- [x] Frame items show lens category selector in `confirmed` status
- [x] Frame items show lens assignment selector in `confirmed` status (after lens category selected)
- [x] General items never show lens fields
- [x] All item fields locked at `processing`+
- [x] Discount fields removed from edit form
- [x] Status toggle shows valid transitions from current status

**Verification:** `vendor/bin/sail artisan test --compact --filter=EditOrder`

**Dependencies:** Task 10

**Files:**
- `app/Filament/Resources/Orders/Pages/EditOrder.php`
- `app/Filament/Resources/Orders/Schemas/OrderForm.php`

**Size:** M

---

#### Task 12: Update order API for lens_category_id rename + backward compat (✅ DONE — c2bc388; hardened post-review in a1228c9)
**Description:** Update `POST /orders` to accept `lens_category_id` with `lens_type_id` as alias. Update GET responses to include both field names during transition. Update resource classes.

**Acceptance criteria:**
- [x] `POST /orders` accepts `items[].lens_category_id` and `items[].lens_type_id` (alias)
- [x] `GET /orders` response includes both `lens_category_id` and `lens_type_id`
- [x] Android app backward compatibility maintained
- [x] API tests pass

**Verification:** `vendor/bin/sail artisan test --compact --filter=OrderApi`

**Dependencies:** Task 2 (model rename)

**Files:**
- `app/Http/Controllers/Api/OrderController.php`
- `app/Http/Resources/OrderItemResource.php`
- `app/Http/Requests/Api/StoreOrderRequest.php`
- `tests/Feature/Api/OrderApiTest.php` (update)

**Size:** S

---

### ✅ Checkpoint 3 — Order Flow Reworked (DONE — commits 4358c03, 0bd1e5c, 6c35a81, ddf7d67, 90c6510, c2bc388, c2bbc72)
- [x] Admin creates orders at `confirmed`
- [x] Customer orders start at `requested`
- [x] Inventory commits at `processing`
- [x] Lens assignment works on edit form (confirmed only)
- [x] No discount on orders
- [x] API backward compatible
- [x] All order tests pass (522/540 at this checkpoint; remaining 18 failures pre-date this phase, confined to 4 files scoped to Task 19)

---

### Phase 4: Billing Rework

#### Task 13: Migration — remove OR number, add notes to billings (✅ DONE — 5b733da)
**Description:** Drop `billings.or_number` column. Add nullable `notes` text column. Update Billing model (remove `or_number` from fillable, remove `generateOrNumber()`, add `notes`).

**Acceptance criteria:**
- [x] `billings.or_number` column removed
- [x] `billings.notes` column added (nullable text)
- [x] Billing model updated (fillable, removed generation method)
- [x] Migration is reversible

**Verification:** `vendor/bin/sail artisan migrate`

**Dependencies:** None (parallel with Phase 3)

**Files:**
- `database/migrations/YYYY_MM_DD_remove_or_number_add_billing_notes.php`
- `app/Models/Billing.php`

**Size:** S

---

#### Task 14: Add Billing create page (standalone invoices) (✅ DONE — 8d72319)
**Description:** Add a create page to the Billings Filament resource. Form: customer selector, optional appointment selector, notes. Creates billing with status `issued` and zero amounts.

**Acceptance criteria:**
- [x] Create page accessible from Billings list
- [x] Form has: customer (required), appointment (optional), notes (optional)
- [x] Created billing has status `issued`, zero amounts
- [x] `billing_number` auto-generated
- [x] Test: billing can be created manually

**Verification:** `vendor/bin/sail artisan test --compact --filter=CreateBilling`

**Dependencies:** Task 13

**Files:**
- `app/Filament/Resources/Billings/Pages/CreateBilling.php` (new)
- `app/Filament/Resources/Billings/Pages/ListBillings.php` (register create page)
- `tests/Feature/Filament/CreateBillingTest.php` (new)

**Size:** S

---

#### Task 15: Update ViewBilling — remove OR#, add notes, add "Create Order" action (✅ DONE — d4b9b36)
**Description:** Remove OR number from infolist. Add editable notes field. Add "Create Order" header action that redirects to order create form with customer pre-filled and `billing_id` set.

**Acceptance criteria:**
- [x] OR number removed from billing infolist
- [x] Notes field visible and editable (when billing not voided)
- [x] "Create Order" action visible on non-voided billings
- [x] "Create Order" redirects to order create with customer pre-filled
- [x] Created order has `billing_id` linking to this billing

**Verification:** `vendor/bin/sail artisan test --compact --filter=ViewBilling`

**Dependencies:** Task 14

**Files:**
- `app/Filament/Resources/Billings/Pages/ViewBilling.php`
- `tests/Feature/Filament/ViewBillingTest.php` (update)

**Size:** S

---

#### Task 16: Update Billing table + list — remove OR# references (✅ DONE — 2706133)
**Description:** Remove OR number column from billings table. Update list page stats. Remove OR# from PDF and thermal receipt templates.

**Acceptance criteria:**
- [x] OR# column removed from billings table
- [x] Billing PDF template no longer shows OR#
- [x] Thermal receipt template no longer shows OR#
- [x] Billing API response no longer includes `or_number`
- [x] Table shows: billing #, customer, items summary, total, balance, status

**Verification:** `vendor/bin/sail artisan test --compact --filter=Billing`

**Dependencies:** Task 13

**Files:**
- `app/Filament/Resources/Billings/Tables/BillingsTable.php`
- `resources/views/pdf/billing.blade.php`
- `resources/views/thermal/billing.blade.php`
- `app/Http/Resources/BillingResource.php` (API)

**Size:** S

---

#### Task 17: Remove "Bill Service" from Appointment + Patient pages (✅ DONE — acf7c04)
**Description:** Remove "Bill Service" header actions. Add Billings relation manager to Appointment page (read-only list of linked billings).

**Acceptance criteria:**
- [x] "Bill Service" removed from EditAppointment
- [x] "Bill Service" removed from EditPatient
- [x] Billings relation manager on Appointment edit page (where `appointment_id` matches)
- [x] No orphaned `CreateServiceBilling` references

**Verification:** `vendor/bin/sail artisan test --compact --filter=Appointment`

**Dependencies:** Task 9 (CreateServiceBilling deleted)

**Files:**
- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `app/Filament/Resources/Patients/Pages/EditPatient.php`
- `app/Filament/Resources/Appointments/RelationManagers/BillingsRelationManager.php` (new)

**Size:** S

---

### ✅ Checkpoint 4 — Billing Rework Complete (DONE — commits 5b733da, 8d72319, d4b9b36, 2706133, acf7c04; review fixes 8d0dccd, a1228c9)
- [x] Billing creates manually (standalone)
- [x] Billing auto-generates at `processing` (from order)
- [x] Pre-linked billing works via "Create Order" action (multi-order-per-billing bug found in review and fixed — see Deviations section)
- [x] OR number gone everywhere
- [x] Notes field works
- [x] "Bill Service" removed from appointment/patient
- [x] All billing tests pass (534/552 full suite after review fixes; remaining 18 failures pre-date Phase 4, confined to 4 files scoped to Task 19)

---

### Phase 5: Cleanup & Documentation

#### Task 18: Update factories and seeders for all changes
**Description:** Ensure all factories and seeders work with: new product types, LensCategory, no order discount, no OR number, billing notes.

**Acceptance criteria:**
- [ ] Product factory uses `frame`, `lens`, `general`
- [ ] Order factory does not set `discount_type_id` or `discount_amount`
- [ ] Billing factory does not set `or_number`, includes `notes`
- [ ] `LensCategory` factory works
- [ ] `migrate:fresh --seed` runs cleanly

**Verification:** `vendor/bin/sail artisan migrate:fresh --seed`

**Dependencies:** All previous tasks

**Files:**
- `database/factories/ProductFactory.php`
- `database/factories/OrderFactory.php`
- `database/factories/BillingFactory.php`
- `database/factories/LensCategoryFactory.php`
- `database/seeders/*.php`

**Size:** M

---

#### Task 19: Full test suite audit and fix
**Description:** Run full test suite. Fix any remaining broken tests from old references (lens_type_id, contact_lens, accessory, or_number, discount on orders, Bill Service actions).

**Acceptance criteria:**
- [ ] No references to `lens_type_id` in test assertions (except API backward compat tests)
- [ ] No references to `contact_lens` or `accessory` product types
- [ ] No references to `or_number`
- [ ] No references to order discount fields
- [ ] Full test suite green

**Verification:** `vendor/bin/sail artisan test --compact`

**Dependencies:** All previous tasks

**Files:**
- `tests/Feature/**/*.php` (various fixes)

**Size:** M

---

#### Task 20: Update BACKEND_CONTEXT.md
**Description:** Rewrite relevant sections of BACKEND_CONTEXT.md to reflect all changes. Register spec as complete.

**Acceptance criteria:**
- [ ] Product types section updated (3 types)
- [ ] Order status flow updated (admin starts at confirmed, inventory at processing)
- [ ] Billing section updated (no OR#, notes, manual creation, pre-linked)
- [ ] "Lens Type" → "Lens Category" throughout
- [ ] Removed features documented
- [ ] Spec registered in completed specs table

**Verification:** Read through document for accuracy

**Dependencies:** All previous tasks

**Files:**
- `docs/BACKEND_CONTEXT.md`

**Size:** M

---

### ✅ Final Checkpoint
- [ ] `vendor/bin/sail artisan migrate:fresh --seed` — clean
- [ ] `vendor/bin/sail artisan test --compact` — all green
- [ ] Filament panel fully functional
- [ ] Mobile API backward compatible
- [ ] No remaining references to: LensType, or_number, contact_lens, accessory, order discount
- [ ] BACKEND_CONTEXT.md accurate
