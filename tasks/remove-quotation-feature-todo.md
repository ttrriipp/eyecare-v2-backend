# Task Checklist: Remove the Quotation Feature

**Status:** Proposed 2026-09-14
**Plan:** `tasks/remove-quotation-feature-plan.md`

## Execution Rules

- Implement in order and stop at every checkpoint.
- Use Laravel Boost `search-docs` before Laravel, Filament, or Pest changes.
- Use Sail for every PHP, Artisan, Composer, and Node command.
- Write or move the relevant test before deleting behavior it protects.
- Do not delete or rewrite posted billing, payments, inventory movements,
  dispensing events, or completed Optical Orders.
- Keep the destructive schema contraction in a separate deploy.
- Run `vendor/bin/sail bin pint --dirty --format agent` after PHP changes.
- Historical migrations/specs/tasks may retain quotation references; active
  runtime code and current documentation may not.

## Phase 0: De-risk the replacement

### Task 0: Characterize surviving sale behavior

**Description:** Pin the behavior that canonical direct Optical Order creation
must retain before any quotation code is deleted.

**Acceptance criteria:**

- [ ] Tests cover product, corrective eyewear, immediate/prepared fulfillment,
      discount, deposit, inventory commitment, billing, and notifications
- [ ] A current prescription is still required for corrective eyewear
- [ ] A completed Encounter can be associated with the created Order

**Verification:**

- [ ] Existing and new tests pass against the current code before deletion
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders tests/Feature/Filament/OpticalOrdersNewDirectOrderTest.php`

**Dependencies:** None

**Files likely touched:** Optical Order characterization tests only

**Estimated scope:** M

### Task 1: Rename the shared item snapshot builder

**Description:** Move `BuildQuotationItemSnapshot` into the Optical Order domain
with a quotation-neutral name and preserve its frame, lens, contact-lens,
accessory, and custom-product behavior.

**Acceptance criteria:**

- [ ] Production Optical Order code imports the renamed builder
- [ ] Useful snapshot tests live outside `tests/Feature/Quotations`
- [ ] The old builder class no longer exists

**Verification:**

- [ ] Run the relocated snapshot and direct-order tests

**Dependencies:** Task 0

**Files likely touched:** Renamed action, direct-order action, 2–3 tests

**Estimated scope:** M

### Task 2: Rename the shared optical-item validator

**Description:** Move `ValidateOpticalQuotation` into the Optical Order domain
with a name and API describing order-item validation.

**Acceptance criteria:**

- [ ] Direct order creation uses the renamed validator
- [ ] Prescription/product/lens-option invariants are unchanged
- [ ] The old validator class no longer exists

**Verification:**

- [ ] Run the relocated validator, prescription-invariant, and direct-order tests

**Dependencies:** Task 0

**Files likely touched:** Renamed action, direct-order action, 2–3 tests

**Estimated scope:** M

### Task 3: Promote canonical Optical Order creation

**Description:** Rename `CreateDirectOpticalOrder` and its Filament page to
canonical Optical Order terminology. Remove the nullable quotation argument
from `BuildOpticalOrder`, and add explicit optional Encounter context.

**Acceptance criteria:**

- [ ] Only `CreateOpticalOrder` terminology appears in active code
- [ ] `BuildOpticalOrder` never accepts or writes a quotation ID
- [ ] An Encounter passed by the create page is persisted on the Order

**Verification:**

- [ ] Run direct-order action/page tests under their new names
- [ ] Assert the created `job_orders.encounter_id`

**Dependencies:** Tasks 1–2

**Files likely touched:** 2 actions, Optical Order page/resource, focused tests

**Estimated scope:** M

## Phase 1: Replace all entry points

### Task 4: Retarget patient and prescription actions

**Description:** Replace “Create Quotation” actions with “Create Optical Order”
links that prefill the patient or current prescription.

**Acceptance criteria:**

- [ ] Patient action opens canonical Order creation with `patient`
- [ ] Current Prescription action opens it with `prescription`
- [ ] Permissions and current-prescription guards are unchanged

**Verification:**

- [ ] Run patient, prescription, and Filament navigation tests

**Dependencies:** Task 3

**Files likely touched:** 2 Filament pages, 2 focused tests

**Estimated scope:** M

### Task 5: Retarget encounter actions and links

**Description:** Create and find Orders directly by `encounter_id`, removing the
Quotation traversal from encounter header actions, table actions, and form links.

**Acceptance criteria:**

- [ ] Completed Encounter action opens canonical Order creation with `encounter`
- [ ] Visibility checks use existing Job Orders rather than Quotations
- [ ] Encounter “Optical Order” links query `JobOrder` directly

**Verification:**

- [ ] Run Encounter resource and end-to-end clinic workflow tests
- [ ] Manually verify one completed Encounter → Order flow

**Dependencies:** Task 3

**Files likely touched:** 3 Encounter Filament files, 1–2 tests

**Estimated scope:** M

## Phase 2: Remove cross-cutting consumers

### Task 6: Remove quotation from Order and Billing UI

**Description:** Remove source-quotation actions/placeholders/eager loads and
collapse `WorkflowRail` to Optical Order → Billing Record.

**Acceptance criteria:**

- [ ] Order and Billing pages render without Quotation relationships
- [ ] Sale workflow uses a two-stage layout
- [ ] No active URL points to the Quotation resource

**Verification:**

- [ ] Run Optical Order and Billing Record Filament tests

**Dependencies:** Tasks 4–5

**Files likely touched:** Workflow rail plus Order/Billing resource files

**Estimated scope:** M

### Task 7: Remove the patient API quotation field

**Description:** Remove `source_quotation` from Optical Order JSON and its eager
loads as a coordinated clean API contract break.

**Acceptance criteria:**

- [ ] Index/show responses contain no `source_quotation`
- [ ] Controller and resource never load/access `quotation`
- [ ] Route/API contract tests assert the new response shape

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1`

**Dependencies:** Task 3; Android consumer coordination

**Files likely touched:** API controller, resource, contract tests

**Estimated scope:** S

### Task 8: Remove quotation billing provenance

**Description:** Remove the `Quotation` billing source kind, quotation item
payload support, display label, and financial-report bucket.

**Acceptance criteria:**

- [ ] `BillingItemSourceKind` has no Quotation case
- [ ] Charge appenders accept no `quotation_item_id`
- [ ] Billing displays/reports contain no quotation source label

**Verification:**

- [ ] Run billing ledger, add-charge, terminology, and financial-report tests

**Dependencies:** Task 6

**Files likely touched:** Billing enum/action/model and financial report

**Estimated scope:** M

### Task 9: Remove quotation dashboard metrics

**Description:** Delete draft-quotation cards, counts, navigation badges, and
their tests without inventing replacement metrics.

**Acceptance criteria:**

- [ ] Dashboards query no Quotation model/status
- [ ] Widget layouts remain valid after subtraction
- [ ] Navigation tests expect Optical Orders but not Quotations

**Verification:**

- [ ] Run dashboard and admin navigation tests

**Dependencies:** Tasks 4–5

**Files likely touched:** 2 widgets and focused navigation/dashboard tests

**Estimated scope:** M

### Task 10: Remove model and catalog lifecycle relations

**Description:** Remove Quotation relations/fillable fields from surviving
models and quotation-table checks from catalog lifecycle enforcement.

**Acceptance criteria:**

- [ ] `JobOrder`, `BillingRecord`, `BillingRecordItem`, and `LensOption` expose
      no Quotation relationship
- [ ] Optical/Billing resource queries contain no quotation eager load
- [ ] Catalog lifecycle checks only active order/billing tables

**Verification:**

- [ ] Run model relationship, catalog lifecycle, and Optical Order tests

**Dependencies:** Tasks 6–8

**Files likely touched:** Surviving models and `CatalogLifecycle`

**Estimated scope:** M

## Phase 3: Delete the quotation subsystem

### Task 11: Delete the Filament Quotation resource

**Description:** Remove the auto-discovered resource and all quotation pages,
schemas, tables, and stats widgets.

**Acceptance criteria:**

- [ ] `app/Filament/Resources/Quotations` does not exist
- [ ] Quotation routes return 404 and global search has no Quotation result
- [ ] No active Filament class imports `QuotationResource`

**Verification:**

- [ ] Run Filament navigation/route tests
- [ ] Inspect `vendor/bin/sail artisan route:list --except-vendor`

**Dependencies:** Tasks 4–10

**Files likely touched:** Quotation resource directory (deleted)

**Estimated scope:** M (mechanical directory deletion)

### Task 12: Delete quotation lifecycle and conversion actions

**Description:** Delete create/update/decision/conversion actions after all
shared helpers and consumers have moved.

**Acceptance criteria:**

- [ ] `CreateQuotation`, `UpdateQuotationDraft`,
      `RecordQuotationDecision`, and `CreateOpticalOrderFromQuotation` are gone
- [ ] `app/Actions/Quotations` does not exist
- [ ] Quotation audit event cases are removed

**Verification:**

- [ ] Run Optical Order, billing, inventory, notification, and audit tests

**Dependencies:** Tasks 1–11

**Files likely touched:** Quotation/conversion actions and `AuditEvent`

**Estimated scope:** M

### Task 13: Delete remaining domain and seed data

**Description:** Delete Quotation models, policy, status enum, and factories;
rewrite demo/scenario seeders to create representative direct Orders instead.

**Acceptance criteria:**

- [ ] No Quotation model, item model, policy, enum, or factory remains
- [ ] Seeders create no quotation rows and still cover Order statuses
- [ ] Demo workflows describe Order → Billing

**Verification:**

- [ ] Run seeder and end-to-end workflow tests
- [ ] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`

**Dependencies:** Tasks 11–12

**Files likely touched:** Models/policy/enum/factories plus 2 seeders

**Estimated scope:** M

### Task 14: Remove obsolete tests and relocate invariants

**Description:** Delete quotation-only tests and update mixed suites; retain
snapshot, prescription, product, inventory, billing, and notification behavior
under Optical Order tests.

**Acceptance criteria:**

- [ ] `tests/Feature/Quotations` does not exist
- [ ] Pest configuration references no deleted quotation test
- [ ] Mixed/end-to-end tests create Orders directly

**Verification:**

- [ ] Run all focused suites, then the full compact suite

**Dependencies:** Tasks 11–13

**Files likely touched:** Quotation tests (deleted/relocated), mixed tests,
`tests/Pest.php`

**Estimated scope:** M (mostly mechanical test deletion/rename)

## Phase 4: Delete historical data and schema

### Task 15: Contract the database in a separate release

**Description:** After Release A is fully deployed, ship one contract migration
that removes quotation data, inbound references, and the two quotation tables.

**Acceptance criteria:**

- [ ] Preflight aborts if any `billing_record_items.source_kind = quotation` or
      non-null `quotation_item_id` exists
- [ ] Linked Orders are preserved while `job_orders.quotation_id` is detached
- [ ] Quotation audit rows/metadata are removed
- [ ] Foreign keys/indexes and quotation columns are dropped before the tables
- [ ] `quotation_items` is dropped before `quotations`
- [ ] `down()` recreates the prior structure; the predeploy snapshot is the only
      way to restore deleted rows

**Verification:**

- [ ] Test migration `up → down → up` on a database snapshot
- [ ] Run schema assertions and `migrate:fresh --seed`
- [ ] Run the full compact suite after the final `up`

**Dependencies:** Release A deployed and verified; short-lived DB snapshot

**Files likely touched:** One new migration and schema-focused tests

**Estimated scope:** M

## Phase 5: Current documentation and final audit

### Task 16: Reconcile current documentation

**Description:** Update current product, API, and backend-context documents and
mark the old quotation-centric workflow decision superseded. Leave historical
specs and completed plans intact.

**Acceptance criteria:**

- [ ] `PRODUCT.md`, `docs/API_CONTRACT.md`, and `docs/BACKEND_CONTEXT.md`
      describe Optical Order → Billing only
- [ ] The prior quotation workflow ADR is clearly superseded
- [ ] A scoped runtime scan finds no active quotation symbol/reference
- [ ] Remaining matches are classified as historical migration/spec/task records

**Verification:**

- [ ] `rg -n -i 'quotation|quotations' app routes config database/factories database/seeders`
      returns no matches
- [ ] `vendor/bin/sail artisan route:list --except-vendor` contains no quotation route
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `git diff --check`

**Dependencies:** Task 15

**Files likely touched:** Current product/API/context documents and ADR status

**Estimated scope:** M
