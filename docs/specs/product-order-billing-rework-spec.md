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
  LensType.php                   → rename to LensCategory
  Order.php                      → remove discount fields
  OrderItem.php                  → lens_type_id rename to lens_category_id
  Billing.php                    → remove or_number, add notes
database/migrations/             → new migrations for schema changes
app/Actions/Orders/
  UpdateOrderStatus.php          → inventory commits at processing, not confirmed
  ApplyDiscount.php              → moves to billing-only context
app/Actions/Billing/
  GenerateBillingForOrder.php    → triggered at processing, handles pre-linked billing
app/Filament/Resources/
  Orders/                        → single New Order button, lens UI on edit only
  Billings/                      → Create page, "Create Order" action, remove OR#
  Appointments/                  → remove "Bill Service" action
  Patients/                      → remove "Bill Service" action
  LensCategories/                → renamed from LensTypes
app/Http/Controllers/Api/        → update product API (frame + general)
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

- [ ] Product form shows 3 types: Frame, Lens, General
- [ ] Existing `contact_lens` and `accessory` products migrated to `general`
- [ ] "Lens Type" renamed to "Lens Category" in all UI, models, and tables
- [ ] Admin-created orders start at `confirmed` (single "New Order" button)
- [ ] Customer-submitted orders start at `requested` (unchanged)
- [ ] Orders are fully editable in `confirmed` status (items, quantities, lens category, lens assignment)
- [ ] Orders lock at `processing` — no edits allowed
- [ ] Inventory commits when order advances to `processing`
- [ ] Billing auto-generates at `processing` (or attaches to pre-linked billing)
- [ ] Discount fields removed from orders table; discount lives on billing only
- [ ] OR number removed from billing (column, logic, UI, PDFs)
- [ ] Billing has a `notes` field, editable while not voided
- [ ] "Bill Service" actions removed from Appointment and Patient pages
- [ ] "Create Order" action exists on ViewBilling page
- [ ] Product selector on order form excludes `lens` type products
- [ ] Lens category field on order items only shown for `frame` type products
- [ ] Mobile API returns frame + general products
- [ ] Walk-in appointments bypass slot conflict check
- [ ] All existing tests updated and passing
- [ ] Full test suite green

---

## Open Questions

None — all decisions made during ideation.

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

#### Task 1: Migration — rename lens_types table and FK columns
**Description:** Create migration to rename `lens_types` → `lens_categories`, `products.lens_type_id` → `products.lens_category_id`, `order_items.lens_type_id` → `order_items.lens_category_id`.

**Acceptance criteria:**
- [ ] `lens_types` table renamed to `lens_categories`
- [ ] `products.lens_type_id` renamed to `products.lens_category_id`
- [ ] `order_items.lens_type_id` renamed to `order_items.lens_category_id`
- [ ] Migration is reversible

**Verification:** `vendor/bin/sail artisan migrate && vendor/bin/sail artisan migrate:rollback && vendor/bin/sail artisan migrate`

**Dependencies:** None

**Files:**
- `database/migrations/YYYY_MM_DD_rename_lens_types_to_lens_categories.php`

**Size:** S

---

#### Task 2: Rename LensType model → LensCategory + update relationships
**Description:** Rename model file, class, factory, seeder. Update `Product::lensCategory()` and `OrderItem::lensCategory()` relationships. Update all imports across the codebase.

**Acceptance criteria:**
- [ ] `LensCategory` model exists referencing `lens_categories` table
- [ ] `Product::lensCategory()` works (was `lensType()`)
- [ ] `OrderItem::lensCategory()` works (was `lensType()`)
- [ ] No remaining `LensType` references in app/ or database/ directories
- [ ] Factory creates records successfully

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

#### Task 3: Rename LensType Filament resource → LensCategory
**Description:** Rename the Filament resource class, pages, and relation managers. Update navigation label to "Lens Categories" under Settings.

**Acceptance criteria:**
- [ ] `LensCategoryResource` exists with correct model reference
- [ ] Navigation shows "Lens Categories" under Settings group
- [ ] Relation manager on edit page shows lens products correctly
- [ ] Tests for the resource pass

**Verification:** `vendor/bin/sail artisan test --compact --filter=LensCategory`

**Dependencies:** Task 2

**Files:**
- `app/Filament/Resources/LensCategoryResource.php` (renamed)
- `app/Filament/Resources/LensCategoryResource/Pages/*.php`

**Size:** S

---

### ✅ Checkpoint 1 — Lens Category Rename Complete
- [ ] `migrate:fresh --seed` runs cleanly
- [ ] LensCategory resource accessible in panel
- [ ] No references to `LensType` remain

---

### Phase 2: Product Type Simplification

#### Task 4: Migration — consolidate product types + update seeder
**Description:** Migrate `contact_lens` and `accessory` product type values to `general`. Update seeders to use new type values.

**Acceptance criteria:**
- [ ] All `contact_lens` products now have type `general`
- [ ] All `accessory` products now have type `general`
- [ ] Seeders create products with types: `frame`, `lens`, `general`
- [ ] Migration documents why type conversion is not reversible (data loss)

**Verification:** `vendor/bin/sail artisan migrate && vendor/bin/sail artisan db:seed`

**Dependencies:** Task 1

**Files:**
- `database/migrations/YYYY_MM_DD_consolidate_product_types.php`
- `database/seeders/` (product seeders)

**Size:** S

---

#### Task 5: Update Product Filament resource for 3 types
**Description:** Update type selector options to Frame/Lens/General. Update conditional form logic. Update table badges and filters.

**Acceptance criteria:**
- [ ] Product type options: Frame, Lens, General
- [ ] Frame → shows AR fields on variants
- [ ] Lens → shows Lens Category selector (required)
- [ ] General → no special fields
- [ ] Table badges: Frame (blue), Lens (green), General (gray)
- [ ] Type filter has 3 values

**Verification:** `vendor/bin/sail artisan test --compact --filter=ProductResource`

**Dependencies:** Task 4

**Files:**
- `app/Filament/Resources/Products/Schemas/ProductForm.php`
- `app/Filament/Resources/Products/Tables/ProductsTable.php`

**Size:** S

---

#### Task 6: Update product API to return frame + general
**Description:** Update `GET /products` to return both `frame` and `general` products. Update `GET /products/{id}` to allow both (404 only for `lens`).

**Acceptance criteria:**
- [ ] `GET /products` returns frame + general products
- [ ] `GET /products/{id}` returns 200 for frame and general, 404 for lens
- [ ] Response shape unchanged
- [ ] Filters still work
- [ ] API tests pass

**Verification:** `vendor/bin/sail artisan test --compact --filter=ProductApi`

**Dependencies:** Task 4

**Files:**
- `app/Http/Controllers/Api/ProductController.php`
- `tests/Feature/Api/ProductApiTest.php` (update assertions)

**Size:** S

---

### ✅ Checkpoint 2 — Product Types Simplified
- [ ] Only 3 product types in system
- [ ] Admin panel shows correct type options
- [ ] Mobile API returns frame + general
- [ ] Tests pass

---

### Phase 3: Order Flow Rework

#### Task 7: Migration — remove discount columns from orders
**Description:** Drop `discount_type_id` and `discount_amount` from `orders` table. Update Order model (remove from fillable, remove `discountType()` relationship).

**Acceptance criteria:**
- [ ] `orders.discount_type_id` column removed
- [ ] `orders.discount_amount` column removed
- [ ] Order model fillable updated
- [ ] `Order::discountType()` relationship removed
- [ ] Migration is reversible

**Verification:** `vendor/bin/sail artisan migrate`

**Dependencies:** None (parallel with Phase 2)

**Files:**
- `database/migrations/YYYY_MM_DD_remove_discount_from_orders.php`
- `app/Models/Order.php`

**Size:** S

---

#### Task 8: Rework UpdateOrderStatus — move gates + inventory to `processing`
**Description:** Move prescription gate, lens gate, and inventory deduction from `confirmed` to `processing`. Make `requested → confirmed` a simple status change. Remove discount logic from this action.

**Acceptance criteria:**
- [ ] `requested → confirmed` is a simple status update (no gates, no inventory)
- [ ] `confirmed → processing` triggers: prescription gate, lens gate, inventory deduction
- [ ] Cancelling from `processing`+ restores inventory
- [ ] Cancelling from `confirmed` does NOT restore inventory
- [ ] Discount code removed from action
- [ ] SMS + audit logs still fire correctly

**Verification:** `vendor/bin/sail artisan test --compact --filter=UpdateOrderStatus`

**Dependencies:** Task 7

**Files:**
- `app/Actions/Orders/UpdateOrderStatus.php`
- `app/Actions/Orders/ApplyDiscount.php` (delete — discount now billing-only)
- `tests/Feature/Actions/UpdateOrderStatusTest.php` (update)

**Size:** M

---

#### Task 9: Move billing generation to `processing` + pre-linked billing support
**Description:** Update `GenerateBillingForOrder` to be triggered at `processing` (called from `UpdateOrderStatus`). Add pre-linked billing support: if order has `billing_id` set, attach items to that billing. Remove discount copying. Delete `CreateServiceBilling`.

**Acceptance criteria:**
- [ ] Billing generates when order moves to `processing`
- [ ] Pre-linked billing: if `order.billing_id` is set, items added to that billing
- [ ] No pre-linked billing: creates new or reuses by appointment_id
- [ ] No discount copied from order (discount applied directly on billing)
- [ ] `CreateServiceBilling` action deleted
- [ ] Tests for billing generation pass

**Verification:** `vendor/bin/sail artisan test --compact --filter=Billing`

**Dependencies:** Task 8

**Files:**
- `app/Actions/Billing/GenerateBillingForOrder.php`
- `app/Actions/Billing/AddOrderItemsToBilling.php`
- `app/Actions/Billing/CreateServiceBilling.php` (delete)
- `tests/Feature/Actions/BillingActionsTest.php` (update)

**Size:** M

---

#### Task 10: Update Order create form — single button, starts at `confirmed`
**Description:** Remove "Walk-in Sale" header action. Single "New Order" button creates orders at `confirmed` status. Simplify create form: patient, product (frame/general only), variant, quantity. No lens fields on create.

**Acceptance criteria:**
- [ ] Single "New Order" button on ListOrders (no Walk-in Sale)
- [ ] Created orders start at `confirmed` status
- [ ] Product selector excludes `lens` type products
- [ ] No lens category or lens assignment fields on create form
- [ ] Customer quick-create still works

**Verification:** `vendor/bin/sail artisan test --compact --filter=CreateOrder`

**Dependencies:** Task 8

**Files:**
- `app/Filament/Resources/Orders/Pages/CreateOrder.php`
- `app/Filament/Resources/Orders/Pages/ListOrders.php`

**Size:** S

---

#### Task 11: Update Order edit form — lens fields in `confirmed`, locked at `processing`
**Description:** Add lens category selector and lens product variant assignment to the edit form, visible only during `confirmed` status and only for frame-type items. Lock all fields when status is `processing` or beyond. Remove discount fields from edit form.

**Acceptance criteria:**
- [ ] Frame items show lens category selector in `confirmed` status
- [ ] Frame items show lens assignment selector in `confirmed` status (after lens category selected)
- [ ] General items never show lens fields
- [ ] All item fields locked at `processing`+
- [ ] Discount fields removed from edit form
- [ ] Status toggle shows valid transitions from current status

**Verification:** `vendor/bin/sail artisan test --compact --filter=EditOrder`

**Dependencies:** Task 10

**Files:**
- `app/Filament/Resources/Orders/Pages/EditOrder.php`
- `app/Filament/Resources/Orders/Schemas/OrderForm.php`

**Size:** M

---

#### Task 12: Update order API for lens_category_id rename + backward compat
**Description:** Update `POST /orders` to accept `lens_category_id` with `lens_type_id` as alias. Update GET responses to include both field names during transition. Update resource classes.

**Acceptance criteria:**
- [ ] `POST /orders` accepts `items[].lens_category_id` and `items[].lens_type_id` (alias)
- [ ] `GET /orders` response includes both `lens_category_id` and `lens_type_id`
- [ ] Android app backward compatibility maintained
- [ ] API tests pass

**Verification:** `vendor/bin/sail artisan test --compact --filter=OrderApi`

**Dependencies:** Task 2 (model rename)

**Files:**
- `app/Http/Controllers/Api/OrderController.php`
- `app/Http/Resources/OrderItemResource.php`
- `app/Http/Requests/Api/StoreOrderRequest.php`
- `tests/Feature/Api/OrderApiTest.php` (update)

**Size:** S

---

### ✅ Checkpoint 3 — Order Flow Reworked
- [ ] Admin creates orders at `confirmed`
- [ ] Customer orders start at `requested`
- [ ] Inventory commits at `processing`
- [ ] Lens assignment works on edit form (confirmed only)
- [ ] No discount on orders
- [ ] API backward compatible
- [ ] All order tests pass

---

### Phase 4: Billing Rework

#### Task 13: Migration — remove OR number, add notes to billings
**Description:** Drop `billings.or_number` column. Add nullable `notes` text column. Update Billing model (remove `or_number` from fillable, remove `generateOrNumber()`, add `notes`).

**Acceptance criteria:**
- [ ] `billings.or_number` column removed
- [ ] `billings.notes` column added (nullable text)
- [ ] Billing model updated (fillable, removed generation method)
- [ ] Migration is reversible

**Verification:** `vendor/bin/sail artisan migrate`

**Dependencies:** None (parallel with Phase 3)

**Files:**
- `database/migrations/YYYY_MM_DD_remove_or_number_add_billing_notes.php`
- `app/Models/Billing.php`

**Size:** S

---

#### Task 14: Add Billing create page (standalone invoices)
**Description:** Add a create page to the Billings Filament resource. Form: customer selector, optional appointment selector, notes. Creates billing with status `issued` and zero amounts.

**Acceptance criteria:**
- [ ] Create page accessible from Billings list
- [ ] Form has: customer (required), appointment (optional), notes (optional)
- [ ] Created billing has status `issued`, zero amounts
- [ ] `billing_number` auto-generated
- [ ] Test: billing can be created manually

**Verification:** `vendor/bin/sail artisan test --compact --filter=CreateBilling`

**Dependencies:** Task 13

**Files:**
- `app/Filament/Resources/Billings/Pages/CreateBilling.php` (new)
- `app/Filament/Resources/Billings/Pages/ListBillings.php` (register create page)
- `tests/Feature/Filament/CreateBillingTest.php` (new)

**Size:** S

---

#### Task 15: Update ViewBilling — remove OR#, add notes, add "Create Order" action
**Description:** Remove OR number from infolist. Add editable notes field. Add "Create Order" header action that redirects to order create form with customer pre-filled and `billing_id` set.

**Acceptance criteria:**
- [ ] OR number removed from billing infolist
- [ ] Notes field visible and editable (when billing not voided)
- [ ] "Create Order" action visible on non-voided billings
- [ ] "Create Order" redirects to order create with customer pre-filled
- [ ] Created order has `billing_id` linking to this billing

**Verification:** `vendor/bin/sail artisan test --compact --filter=ViewBilling`

**Dependencies:** Task 14

**Files:**
- `app/Filament/Resources/Billings/Pages/ViewBilling.php`
- `tests/Feature/Filament/ViewBillingTest.php` (update)

**Size:** S

---

#### Task 16: Update Billing table + list — remove OR# references
**Description:** Remove OR number column from billings table. Update list page stats. Remove OR# from PDF and thermal receipt templates.

**Acceptance criteria:**
- [ ] OR# column removed from billings table
- [ ] Billing PDF template no longer shows OR#
- [ ] Thermal receipt template no longer shows OR#
- [ ] Billing API response no longer includes `or_number`
- [ ] Table shows: billing #, customer, items summary, total, balance, status

**Verification:** `vendor/bin/sail artisan test --compact --filter=Billing`

**Dependencies:** Task 13

**Files:**
- `app/Filament/Resources/Billings/Tables/BillingsTable.php`
- `resources/views/pdf/billing.blade.php`
- `resources/views/thermal/billing.blade.php`
- `app/Http/Resources/BillingResource.php` (API)

**Size:** S

---

#### Task 17: Remove "Bill Service" from Appointment + Patient pages
**Description:** Remove "Bill Service" header actions. Add Billings relation manager to Appointment page (read-only list of linked billings).

**Acceptance criteria:**
- [ ] "Bill Service" removed from EditAppointment
- [ ] "Bill Service" removed from EditPatient
- [ ] Billings relation manager on Appointment edit page (where `appointment_id` matches)
- [ ] No orphaned `CreateServiceBilling` references

**Verification:** `vendor/bin/sail artisan test --compact --filter=Appointment`

**Dependencies:** Task 9 (CreateServiceBilling deleted)

**Files:**
- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `app/Filament/Resources/Patients/Pages/EditPatient.php`
- `app/Filament/Resources/Appointments/RelationManagers/BillingsRelationManager.php` (new)

**Size:** S

---

### ✅ Checkpoint 4 — Billing Rework Complete
- [ ] Billing creates manually (standalone)
- [ ] Billing auto-generates at `processing` (from order)
- [ ] Pre-linked billing works via "Create Order" action
- [ ] OR number gone everywhere
- [ ] Notes field works
- [ ] "Bill Service" removed from appointment/patient
- [ ] All billing tests pass

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
