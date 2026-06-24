# Spec: Encounter Billing Refactor

## Objective

Refactor the billing system from "one billing per source (order/service)" to an **encounter/invoice model** where a billing is a standalone invoice with line items. A single billing can contain both product charges (from orders) and service charges — giving staff one consolidated bill per patient visit.

**Success criteria:**
- A billing is a standalone invoice with a `customer_id`, not a polymorphic child of an order/service
- Billings contain `billing_items` (line items) that can be products or services
- Order confirmation auto-creates a billing with product line items (preserves existing behavior)
- Staff can add service line items to any billing (new or existing)
- Staff can create a standalone service billing (no order involved)
- Discount applied at the billing level (to the total), not per-item
- All existing payment infrastructure works unchanged (RecordPayment, RecalculateBillingBalance)
- ServiceRecordResource removed as standalone nav — services are line items on billings
- All existing tests updated and passing

## Tech Stack

- PHP 8.5 / Laravel 13 / Filament 5 / Pest 4
- MySQL via Sail
- Existing: payments, billing_statuses, payment_methods, discount_types

## Commands

```
Test:     vendor/bin/sail artisan test --compact
Filter:   vendor/bin/sail artisan test --compact --filter=Billing
Lint:     vendor/bin/sail bin pint --dirty --format agent
Fresh:    vendor/bin/sail artisan migrate:fresh --seed
```

## Schema Changes

### New: `billing_items`

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned PK | |
| billing_id | FK billings | Parent invoice |
| type | enum('product','service') | What kind of charge |
| description | varchar(255) | "Classic Frame (Matte Black)" or "Comprehensive Eye Exam" |
| quantity | int unsigned, default 1 | |
| unit_price | decimal(10,2) | |
| amount | decimal(10,2) | unit_price × quantity |
| order_item_id | FK order_items, nullable | Links to source order item (for product type) |
| service_record_id | FK service_records, nullable | Links to source service record (for service type) |
| created_at | timestamp | |

### Modified: `billings`

| Change | Detail |
|---|---|
| Drop | `billable_type`, `billable_id` (polymorphic columns) |
| Add | `customer_id` FK users (not null) |
| Add | `order_id` FK orders, nullable (primary order that triggered this billing, if any) |
| Add | `discount_type_id` FK discount_types, nullable |
| Add | `discount_amount` decimal(10,2), default 0 |
| Add | `subtotal` decimal(10,2) (sum of billing_items.amount) |
| Keep | `total_amount` (subtotal - discount_amount), `amount_paid`, `balance_due`, `billing_status_id`, `issued_at`, etc. |

### Modified: `service_records`

| Change | Detail |
|---|---|
| Drop | `discount_type_id`, `discount_amount`, `total_amount` (discount moves to billing level) |
| Keep | `customer_id`, `service_id`, `appointment_id`, `staff_id`, `amount`, `notes`, `performed_at` |

### Unchanged

- `services` table (fee schedule) — stays as-is
- `orders`, `order_items` — unchanged
- `payments` — unchanged (still FK to billings)
- `billing_statuses`, `payment_methods`, `payment_statuses` — unchanged

## Data Model (after refactor)

```
Billing (invoice)
  ├── customer_id (who's paying)
  ├── order_id (nullable — which order triggered this, if any)
  ├── discount at billing level
  ├── billing_items[] (line items)
  │     ├── type: product, order_item_id → snapshot from order
  │     └── type: service, service_record_id → service performed
  └── payments[] (unchanged)

Order (operational)
  ├── status lifecycle (requested → completed)
  ├── order_items[]
  └── On confirmation → creates Billing + billing_items from order_items

ServiceRecord (operational log)
  ├── what service, for whom, by whom, when
  └── Referenced by billing_items (not a billing source itself)
```

## UI Changes

### Billings List (enhanced)

| Billing # | Patient | Items summary | Total | Status |
|---|---|---|---|---|
| BIL-2026-000001 | Juan | Eye Exam, Classic Frame, Progressive Lens | ₱5,840 | Partially Paid |

### View Billing (new: line items section)

Shows: billing details + line items table + discount summary + payments section. Staff can:
- "Add Service" action → adds a service line item (creates service_record behind the scenes)
- "Record Payment" (unchanged)
- "Void Payment" (unchanged)

### Removed

- **ServiceRecordResource** standalone nav item — gone
- **ServiceRecords list/create/edit pages** — gone

### Kept

- **ServiceResource** (Settings CRUD for fee schedule) — stays
- **"Bill Service" on Appointment/Patient pages** — now creates a billing directly with a service line item (or adds to existing draft billing)
- **Patient page** — can show service history via relation manager on billing_items

## Actions (after refactor)

| Action | What it does |
|---|---|
| `GenerateBillingForOrder` | Creates billing + product billing_items from order_items. Assigns customer_id from order. |
| `AddServiceToBilling` | Creates a service_record + adds a service billing_item to a billing. |
| `CreateServiceBilling` | Creates a new billing with a single service line item (for standalone services). |
| `RecalculateBillingTotals` | Sums billing_items.amount → subtotal, applies discount → total_amount, updates balance_due. |
| `RecordPayment` | Unchanged. |
| `RecalculateBillingBalance` | Unchanged (or merged with RecalculateBillingTotals). |

## Boundaries

**Always:**
- Run full test suite after each migration step
- Discount is at billing level only — never per line item
- Service records are created when adding a service item (audit trail)

**Ask first:**
- Changing mobile API billing response shape
- Adding "merge billings" functionality

**Never:**
- Break order confirmation → billing auto-generation flow
- Remove payment infrastructure
- Delete service_records table (it's the audit log of services performed)

## Testing Strategy

- Update all existing billing tests for new schema
- New tests: billing with mixed items, add service to billing action, discount on invoice
- Run `vendor/bin/sail artisan test --compact` at each checkpoint

## Open Questions

- Should "Bill Service" on an appointment page add to an existing billing for that order (if one exists from same appointment), or always create a new billing? **Recommendation:** If a billing already exists for that appointment's order, add to it. Otherwise create new.

## Tasks

### Phase 1: Schema Migration (highest risk — do first)

- [ ] **Task 1: Create `billing_items` table + model**
  - Description: New table for invoice line items. Model with relationships.
  - Acceptance:
    - [ ] `billing_items` table with: id, billing_id (FK), type (enum product/service), description, quantity, unit_price, amount, order_item_id (nullable FK), service_record_id (nullable FK), created_at
    - [ ] `BillingItem` model with relationships to Billing, OrderItem, ServiceRecord
    - [ ] `BillingItemFactory` works
  - Verify: `vendor/bin/sail artisan migrate`
  - Files: migration, `app/Models/BillingItem.php`, factory
  - Scope: S

- [ ] **Task 2: Migrate `billings` table — replace polymorphic with direct FKs**
  - Description: Drop `billable_type`/`billable_id`, add `customer_id`, `order_id` (nullable), `discount_type_id`, `discount_amount`, `subtotal`. Migrate existing data.
  - Acceptance:
    - [ ] Existing order billings get `customer_id` from order, `order_id` set
    - [ ] Existing service record billings get `customer_id` from service_record, `order_id` null
    - [ ] Polymorphic columns dropped
    - [ ] `Billing` model updated: `belongsTo(customer)`, `belongsTo(order)`, `hasMany(billingItems)`, drops `morphTo`
    - [ ] `Order.billing()` back to `hasOne(Billing::class)` with FK
    - [ ] `BillingFactory` updated for new columns
  - Verify: `vendor/bin/sail artisan migrate` + seeder works
  - Dependencies: Task 1
  - Files: migration, `app/Models/Billing.php`, `app/Models/Order.php`, `database/factories/BillingFactory.php`
  - Scope: M (4 files)

- [ ] **Task 3: Simplify `service_records` — drop billing-level fields**
  - Description: Remove `discount_type_id`, `discount_amount`, `total_amount` from service_records (discount now lives on billing). Update model + factory.
  - Acceptance:
    - [ ] Migration drops 3 columns
    - [ ] `ServiceRecord` model updated (remove discount/total fields from fillable + casts)
    - [ ] `ServiceRecord` drops `morphOne(Billing)` relationship (no longer owns a billing)
    - [ ] `ServiceRecordFactory` updated
  - Verify: `vendor/bin/sail artisan migrate`
  - Dependencies: Task 2
  - Files: migration, `app/Models/ServiceRecord.php`, `database/factories/ServiceRecordFactory.php`
  - Scope: S

### ✓ Checkpoint: Schema
- [ ] `vendor/bin/sail artisan migrate:fresh` succeeds
- [ ] Models compile without errors

---

### Phase 2: Actions (business logic rewrite)

- [ ] **Task 4: Rewrite `GenerateBillingForOrder` — create billing + billing_items**
  - Description: On order confirmation, create a billing with customer_id/order_id, then create billing_items from each order_item (type: product).
  - Acceptance:
    - [ ] Creates billing with `customer_id`, `order_id`, `discount_type_id`, `discount_amount`, `subtotal`, `total_amount`
    - [ ] Creates one billing_item per order_item (description = product_name + variant_name, unit_price, quantity, amount)
    - [ ] Duplicate guard: throws if billing already exists for this order
    - [ ] Audit log fires
    - [ ] Test passes
  - Verify: `vendor/bin/sail artisan test --compact --filter=BillingGeneration`
  - Dependencies: Task 2
  - Files: `app/Actions/Billing/GenerateBillingForOrder.php`, test file
  - Scope: S

- [ ] **Task 5: Create `AddServiceToBilling` action**
  - Description: Adds a service line item to an existing billing. Creates a service_record and a billing_item. Recalculates billing totals.
  - Acceptance:
    - [ ] Creates ServiceRecord (customer, service, staff, amount, performed_at)
    - [ ] Creates BillingItem (type: service, links to service_record)
    - [ ] Recalculates billing subtotal/total_amount/balance_due
    - [ ] Test covers happy path
  - Verify: `vendor/bin/sail artisan test --compact --filter=AddServiceToBilling`
  - Dependencies: Task 4
  - Files: `app/Actions/Billing/AddServiceToBilling.php`, test file
  - Scope: S

- [ ] **Task 6: Create `CreateServiceBilling` action**
  - Description: Creates a brand new billing with a single service line item (for standalone service billing with no order).
  - Acceptance:
    - [ ] Creates billing with customer_id, no order_id
    - [ ] Creates service_record + billing_item
    - [ ] Sets status to issued
    - [ ] Test passes
  - Verify: `vendor/bin/sail artisan test --compact --filter=CreateServiceBilling`
  - Dependencies: Task 5
  - Files: `app/Actions/Billing/CreateServiceBilling.php`, test file
  - Scope: S

- [ ] **Task 7: Remove `GenerateBillingForService` + update `RecalculateBillingBalance`**
  - Description: Delete the old action. Ensure RecalculateBillingBalance still works. Add `RecalculateBillingTotals` if needed (sums items → subtotal, applies discount → total).
  - Acceptance:
    - [ ] `GenerateBillingForService.php` deleted
    - [ ] Billing recalculation works with new subtotal/discount fields
    - [ ] Existing payment tests pass
  - Verify: `vendor/bin/sail artisan test --compact --filter=Billing`
  - Dependencies: Task 6
  - Files: delete action, possibly update RecalculateBillingBalance
  - Scope: S

### ✓ Checkpoint: Actions
- [ ] `vendor/bin/sail artisan test --compact --filter=Billing` — all pass
- [ ] Order confirmation still auto-generates billing

---

### Phase 3: Filament UI

- [ ] **Task 8: Update BillingsResource — list, view, infolist for encounter model**
  - Description: Replace polymorphic source column with customer name + items summary. ViewBilling shows line items table. Infolist shows customer directly.
  - Acceptance:
    - [ ] List shows: billing #, customer name, items summary, subtotal, discount, total, status
    - [ ] ViewBilling shows line items table (type, description, qty, amount)
    - [ ] Eager loading uses `customer`, `billingItems`, `order`
    - [ ] Source type filter replaced with customer search
    - [ ] Existing billing Filament tests pass
  - Verify: `vendor/bin/sail artisan test --compact --filter=BillingResource`
  - Dependencies: Task 4
  - Files: BillingsTable, BillingInfolist, ViewBilling, BillingResource (4 files)
  - Scope: M

- [ ] **Task 9: "Add Service" action on ViewBilling page**
  - Description: Header action on ViewBilling that opens a form (select service, override amount, select staff) and calls AddServiceToBilling.
  - Acceptance:
    - [ ] Action visible when billing status is not voided/paid
    - [ ] Form: service select (active only), amount (pre-filled from service price), staff (default auth)
    - [ ] On submit: billing_item added, totals recalculated, page refreshes
    - [ ] Test: Filament action test
  - Verify: `vendor/bin/sail artisan test --compact --filter=BillingResource`
  - Dependencies: Task 5, Task 8
  - Files: ViewBilling.php
  - Scope: S

- [ ] **Task 10: "Bill Service" actions — Appointment + Patient pages**
  - Description: Update existing "Bill Service" actions. If appointment has an order with a billing, add service to that billing. Otherwise create a standalone service billing.
  - Acceptance:
    - [ ] Appointment edit: "Bill Service" opens modal (service, amount, staff), calls AddServiceToBilling (if billing exists) or CreateServiceBilling
    - [ ] Patient edit: same but no appointment pre-link
    - [ ] Existing tests pass
  - Verify: `vendor/bin/sail artisan test --compact --filter=Appointment`
  - Dependencies: Task 6, Task 9
  - Files: EditAppointment.php, EditPatient.php
  - Scope: S

- [ ] **Task 11: Remove ServiceRecordResource**
  - Description: Delete standalone resource. Keep ServiceResource (fee schedule in Settings).
  - Acceptance:
    - [ ] `app/Filament/Resources/ServiceRecords/` directory deleted
    - [ ] `tests/Feature/ServiceRecordResourceTest.php` deleted
    - [ ] No broken imports or references
    - [ ] Nav shows no "Service Records" item
  - Verify: `vendor/bin/sail artisan test --compact`
  - Dependencies: Task 10
  - Files: delete directory + test
  - Scope: S

### ✓ Checkpoint: Filament UI
- [ ] All billing actions work in Filament
- [ ] No ServiceRecords nav item
- [ ] Full suite passes

---

### Phase 4: Cleanup + Regression

- [ ] **Task 12: Update seeders, API, full regression**
  - Description: Update ClinicWorkflowSeeder for encounter model. Fix BillingController/BillingResource for direct customer_id auth. Run full suite.
  - Acceptance:
    - [ ] `migrate:fresh --seed` clean
    - [ ] Demo customer has a billing with mixed items (product + service)
    - [ ] API `GET /billing/{id}` returns line items
    - [ ] Full test suite: same or fewer failures than before
    - [ ] Pint clean
  - Verify: `vendor/bin/sail artisan migrate:fresh --seed` + `vendor/bin/sail artisan test --compact`
  - Dependencies: All above
  - Files: seeders, BillingController, BillingResource (API), tests
  - Scope: M

### ✓ Final Checkpoint
- [ ] `vendor/bin/sail artisan migrate:fresh --seed` — clean
- [ ] `vendor/bin/sail artisan test --compact` — all green (same pre-existing failures)
- [ ] Encounter model works end-to-end

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Data migration from polymorphic → direct FK fails | High | Fresh migration (no production data). Test with `migrate:fresh --seed`. |
| Order confirmation billing generation breaks | High | Task 4 rewrites this first. Run billing generation tests immediately. |
| Existing tests expect old schema (billable_type) | Medium | Update tests in same task as schema change. Don't let tests accumulate failures. |
| ServiceRecordForm discount logic now dead | Low | Remove in Task 11 when deleting the resource. |
