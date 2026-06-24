# Spec: Service Billing

## Objective

Extend the billing system to support charging for clinical services (eye exams, fittings, repairs) alongside existing order-based billing. Staff can bill patients for services performed during appointments or walk-in visits.

**Success criteria:**
- Staff can manage a fee schedule (services with prices) in Settings
- Staff can create a service record and generate a billing for it
- Billings work identically for orders and services (same payment flow, statuses, balance tracking)
- Existing order billing continues to work unchanged
- SC/PWD/Loyalty discounts apply to service billings
- All existing tests continue to pass

## Tech Stack

- PHP 8.5 / Laravel 13 / Filament 5 / Pest 4
- MySQL via Sail
- Existing billing infrastructure (payments, statuses, RecordPayment, RecalculateBillingBalance)

## Commands

```
Build:    vendor/bin/sail npm run build
Test:     vendor/bin/sail artisan test --compact
Filter:   vendor/bin/sail artisan test --compact --filter=ServiceBilling
Lint:     vendor/bin/sail bin pint --dirty --format agent
Migrate:  vendor/bin/sail artisan migrate
Fresh:    vendor/bin/sail artisan migrate:fresh --seed
```

## Project Structure

```
app/Models/Service.php                              → Fee schedule model
app/Models/ServiceRecord.php                        → Performed service instance
app/Actions/Billing/GenerateBillingForService.php   → Creates billing from service record
app/Filament/Resources/Services/                    → Settings CRUD for fee schedule
app/Filament/Resources/ServiceRecords/              → Service records resource (bill service flow)
database/migrations/xxxx_create_services_table.php
database/migrations/xxxx_create_service_records_table.php
database/migrations/xxxx_make_billings_polymorphic.php
database/seeders/ServiceSeeder.php
tests/Feature/ServiceBillingTest.php
tests/Feature/Filament/ServiceResourceTest.php
```

## Schema Changes

### New: `services`

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned PK | |
| name | varchar(255) | "Comprehensive Eye Exam" |
| description | text, nullable | |
| price | decimal(10,2) | Default fee |
| is_active | boolean, default true | Visibility toggle |
| timestamps | | |

### New: `service_records`

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned PK | |
| customer_id | FK users | Patient |
| service_id | FK services | Which service |
| appointment_id | FK appointments, nullable | Link to appointment if applicable |
| staff_id | FK users | Who performed/billed it |
| amount | decimal(10,2) | Actual charge (defaults from service.price, overridable) |
| discount_type_id | FK discount_types, nullable | SC/PWD/Loyalty/Custom |
| discount_amount | decimal(10,2), default 0 | Calculated discount |
| total_amount | decimal(10,2) | amount − discount_amount |
| notes | text, nullable | |
| performed_at | datetime | When service was performed |
| timestamps | | |
| soft_deletes | | |

### Modified: `billings`

| Change | Detail |
|---|---|
| Drop | `order_id` FK + column |
| Add | `billable_type` varchar(255) |
| Add | `billable_id` bigint unsigned |
| Add | Morph index on (billable_type, billable_id) |

After migration, existing billings get `billable_type = 'App\Models\Order'` and `billable_id = (old order_id value)`.

## Code Style

Follow existing patterns. Key references:
- `GenerateBillingForOrder` → pattern for `GenerateBillingForService`
- `app/Filament/Resources/Brands/` → pattern for `ServiceResource` (Settings CRUD)
- `Order` model → pattern for `ServiceRecord` (discount fields, soft deletes)

## Testing Strategy

- **Feature tests** for: service CRUD, service record creation, billing generation, discount application, polymorphic billing queries
- **Filament tests** for: ServiceResource list/create/edit, "Bill Service" action
- **Regression tests**: existing order billing tests must pass unchanged
- Run with `vendor/bin/sail artisan test --compact`

## Boundaries

**Always:**
- Run existing billing tests after the polymorphic migration to catch regressions
- Use actions for billing generation (never create billings directly)
- Apply Pint after PHP changes
- Use factories in tests

**Ask first:**
- Adding service billing to the mobile API
- Adding new navigation groups
- Changing billing number format

**Never:**
- Break existing order→billing flow
- Auto-generate service billings without staff action
- Remove or rename existing billing statuses
- Modify payment infrastructure (RecordPayment, RecalculateBillingBalance)

## Implementation Plan

### Phase 1: Schema & Models (foundation)

Create tables, models, factories, seeders. Migrate billings to polymorphic. Ensure existing tests pass.

### Phase 2: Actions (business logic)

`GenerateBillingForService` action + discount application. Wire up the polymorphic relationship in `Billing` model.

### Phase 3: Filament Resources (UI)

Service fee schedule CRUD in Settings. Service records resource with "Bill Service" flow. Update Billings resource to show source type.

### Phase 4: Integration (glue)

"Bill Service" action on Appointment edit page and Patient page. Seeder with sample services. End-to-end test.

## Tasks

### Phase 1: Foundation (Schema & Models)

- [x] **Task 1: Create `services` table, model, factory, seeder**
  - Description: Fee schedule table for optical clinic services.
  - Acceptance:
    - [ ] `services` table with columns: id, name, description (nullable), price, is_active (default true), timestamps
    - [ ] `Service` model with fillable attributes, `is_active` scope
    - [ ] `ServiceFactory` generates valid records
    - [ ] `ServiceSeeder` seeds: Comprehensive Eye Exam (₱800), Contact Lens Fitting (₱500), Visual Field Test (₱300), Frame Adjustment (₱150), Follow-up Consultation (₱0)
  - Verify: `vendor/bin/sail artisan migrate` + `vendor/bin/sail artisan db:seed --class=ServiceSeeder`
  - Files: migration, `app/Models/Service.php`, `database/factories/ServiceFactory.php`, `database/seeders/ServiceSeeder.php`
  - Scope: S (3 files + migration)

- [x] **Task 2: Create `service_records` table, model, factory**
  - Description: Records a service performed for a patient, with optional appointment link and discount.
  - Acceptance:
    - [ ] `service_records` table with all columns per schema spec (customer_id, service_id, appointment_id nullable, staff_id, amount, discount_type_id nullable, discount_amount, total_amount, notes, performed_at, soft deletes)
    - [ ] `ServiceRecord` model with relationships: `customer()`, `service()`, `appointment()`, `staff()`, `billing()` (morphOne inverse)
    - [ ] `ServiceRecordFactory` produces valid records with proper FK relationships
  - Verify: `vendor/bin/sail artisan migrate` succeeds, factory creates valid models in tinker
  - Dependencies: Task 1
  - Files: migration, `app/Models/ServiceRecord.php`, `database/factories/ServiceRecordFactory.php`
  - Scope: S (3 files)

- [x] **Task 3: Make `billings` table polymorphic**
  - Description: Replace `order_id` FK with `billable_type`/`billable_id` morph columns. Migrate existing data. Update all references.
  - Acceptance:
    - [ ] Migration adds `billable_type` + `billable_id`, copies `order_id` data into morph columns, drops `order_id`
    - [ ] `Billing` model: `billable()` morphTo relationship replaces `order()`
    - [ ] `Order` model: `billing()` becomes `morphOne(Billing::class, 'billable')`
    - [ ] `ServiceRecord` model: `billing()` is `morphOne(Billing::class, 'billable')`
    - [ ] `GenerateBillingForOrder` uses `billable_type`/`billable_id` instead of `order_id`
    - [ ] `BillingFactory` updated for polymorphic
    - [ ] **All existing tests pass** (zero failures)
  - Verify: `vendor/bin/sail artisan test --compact` — full suite green
  - Dependencies: Task 2
  - Files: migration, `app/Models/Billing.php`, `app/Models/Order.php`, `app/Models/ServiceRecord.php`, `app/Actions/Billing/GenerateBillingForOrder.php`, `database/factories/BillingFactory.php`
  - Scope: M (6 files) — **highest risk task, do carefully**

### ✓ Checkpoint: Foundation
- [ ] `vendor/bin/sail artisan migrate:fresh --seed` succeeds
- [ ] `vendor/bin/sail artisan test --compact` — all green
- [ ] `services` and `service_records` tables exist with correct structure
- [ ] Billings are polymorphic and existing order billings work unchanged

---

### Phase 2: Business Logic

- [x] **Task 4: `GenerateBillingForService` action + tests**
  - Description: Action that creates a billing from a service record, applying discount and generating billing number.
  - Acceptance:
    - [ ] Creates billing with `billable_type = App\Models\ServiceRecord`, correct total
    - [ ] Applies discount: `total_amount = amount - discount_amount`
    - [ ] Generates billing number (BIL-YYYY-XXXXXX)
    - [ ] Sets status to `issued`, `issued_at` to now
    - [ ] Fires audit log entry
    - [ ] Throws ValidationException if service record already has a billing
    - [ ] Feature test covers happy path + duplicate guard
  - Verify: `vendor/bin/sail artisan test --compact --filter=GenerateBillingForService`
  - Dependencies: Task 3
  - Files: `app/Actions/Billing/GenerateBillingForService.php`, `tests/Feature/ServiceBillingTest.php`
  - Scope: S (2 files)

### ✓ Checkpoint: Business Logic
- [ ] Service billing can be generated programmatically
- [ ] Payments work on service billings (RecordPayment doesn't care about billable type)
- [ ] `vendor/bin/sail artisan test --compact` — all green

---

### Phase 3: Filament UI

- [x] **Task 5: `ServiceResource` — Settings CRUD**
  - Description: Fee schedule management in Settings nav group (same pattern as Brands/Categories).
  - Acceptance:
    - [ ] List page: name, price (formatted), visibility badge
    - [ ] Create/edit: name (required), description (optional), price (required, numeric), visibility toggle ("Visible"/"Hidden" labels)
    - [ ] Under "Settings" navigation group
    - [ ] Filament test: can render list, can create, can edit
  - Verify: `vendor/bin/sail artisan test --compact --filter=ServiceResource`
  - Dependencies: Task 1
  - Files: `app/Filament/Resources/Services/` (resource, pages, form, table)
  - Scope: M (4-5 files)

- [x] **Task 6: `ServiceRecordResource` + auto-billing on create**
  - Description: Resource where staff creates service records. On successful create, auto-generates billing.
  - Acceptance:
    - [ ] Create form: customer select (with walk-in quick-create), service select (populates amount from price), appointment select (optional, filtered to customer), staff (defaults to auth user), amount (overridable), discount type, performed_at (defaults to now), notes
    - [ ] On create: `GenerateBillingForService` fires automatically
    - [ ] List page: patient name, service name, amount, performed_at, billing status badge
    - [ ] Under "Orders & Billing" navigation group, labeled "Service Records"
    - [ ] Filament test: can create + billing generated
  - Verify: `vendor/bin/sail artisan test --compact --filter=ServiceRecord`
  - Dependencies: Tasks 4, 5
  - Files: `app/Filament/Resources/ServiceRecords/` (resource, pages, form, table)
  - Scope: M (4-5 files)

- [x] **Task 7: Update `BillingsResource` for polymorphic source**
  - Description: Add "Source" column and filter to the existing billings list. Ensure ViewBilling works for both types.
  - Acceptance:
    - [ ] "Source" column shows "Order #ORD-2026-000001" or "Service: Comprehensive Eye Exam"
    - [ ] Source type filter (All / Orders / Services)
    - [ ] ViewBilling header shows source link (navigates to order or service record)
    - [ ] Existing billing KPI stats include service billings
    - [ ] All existing billing Filament tests pass
  - Verify: `vendor/bin/sail artisan test --compact --filter=Billing`
  - Dependencies: Task 3
  - Files: Billings table file, Billings view page, Billings resource
  - Scope: M (3-4 files)

- [x] **Task 8: "Bill Service" actions on Appointment + Patient pages**
  - Description: Header action on appointment edit page and action on patient page that opens service record creation pre-filled.
  - Acceptance:
    - [ ] Appointment edit: "Bill Service" header action visible when appointment is `completed`
    - [ ] Pre-fills: customer from appointment, appointment_id, staff from auth user
    - [ ] Patient page: "Bill Service" action pre-fills customer_id
    - [ ] Both navigate to ServiceRecord create page (or open modal) with pre-filled data
    - [ ] Filament action test
  - Verify: `vendor/bin/sail artisan test --compact --filter=BillService`
  - Dependencies: Task 6
  - Files: Appointment edit page, Patient resource page
  - Scope: S (2 files)

### ✓ Checkpoint: Filament UI
- [ ] Staff can manage fee schedule in Settings
- [ ] Staff can create service records and billing is auto-generated
- [ ] Billings list shows both order and service billings with filter
- [ ] "Bill Service" action works from appointment and patient pages

---

### Phase 4: Integration & Polish

- [x] **Task 9: Seeders, demo data, full regression**
  - Description: Wire ServiceSeeder into DatabaseSeeder. Add demo service records + billings to ClinicWorkflowSeeder. Full test pass.
  - Acceptance:
    - [ ] `migrate:fresh --seed` creates services, demo service records, and their billings
    - [ ] Demo customer has at least one service billing visible
    - [ ] Full test suite passes
    - [ ] Pint passes on all modified files
  - Verify: `vendor/bin/sail artisan migrate:fresh --seed` + `vendor/bin/sail artisan test --compact` + `vendor/bin/sail bin pint --dirty --format agent`
  - Dependencies: All previous tasks
  - Files: `ServiceSeeder.php`, `DatabaseSeeder.php`, `ClinicWorkflowSeeder.php`
  - Scope: S (3 files)

### ✓ Final Checkpoint
- [ ] `vendor/bin/sail artisan migrate:fresh --seed` — clean
- [ ] `vendor/bin/sail artisan test --compact` — all green
- [ ] Billings work for both orders and services with identical payment flow
- [ ] Pint clean

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Polymorphic migration breaks existing billing tests/code | High | Task 3 is isolated — run full test suite immediately after. grep all `order_id` references in billing context before migrating. |
| Filament resources referencing `$billing->order` directly | Medium | Search all Filament billing files for `->order` before Task 3. Update in same task. |
| Factory state confusion (BillingFactory needs billable) | Low | Update factory to use `morphTo` state, default to Order for backward compat. |

## Open Questions

- Should the "Bill Service" action appear as a header action on the appointment page, or a row action in the appointments table? (Recommendation: header action on edit page — more contextual.)
- Should service records appear in the Patient's relation managers alongside prescriptions/appointments/orders? (Recommendation: yes — add a ServiceRecordsRelationManager.)

## Resolved Decisions

- **Navigation:** Service billings live in the existing Billings screen (no separate nav item). A "Source" column and filter distinguish order billings from service billings. The ServiceRecords resource (where staff creates/manages performed services) lives under "Orders & Billing" group.
