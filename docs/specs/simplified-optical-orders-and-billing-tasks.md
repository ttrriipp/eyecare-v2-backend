# Tasks: Simplified Optical Orders and Billing

## Status

Draft for project-owner review. This is Phase 3 (**Tasks**) of the
spec-driven workflow. It breaks down the approved specification and plan into
small, dependency-ordered implementation sessions. No application-code change
is authorized until this task list is approved.

## Approved Inputs

- `docs/specs/simplified-optical-orders-and-billing-spec.md`
- `docs/specs/simplified-optical-orders-and-billing-plan.md`
- Existing unrelated Appointment and Encounter worktree changes remain outside
  this feature and must be preserved.

## Execution Rules

- Complete tasks in dependency order unless the dependency is explicitly
  satisfied by an earlier reviewed change.
- Use Laravel Sail for every PHP, Artisan, Composer, Node, formatting, and test
  command.
- Search the installed Laravel and Filament documentation before each code
  task that depends on framework behavior.
- Start behavior-changing tasks with the named Pest test, then make the minimum
  application change needed to pass it.
- Keep a task to roughly five files or fewer. If implementation reveals a
  wider change, split the task before proceeding.
- Run `vendor/bin/sail bin pint --dirty --format agent` after modifying PHP.
- Do not edit deployed migrations. Generate new migrations with Artisan.
- Do not delete revision structures until the transition, application, and API
  checkpoints all pass against direct relationships.
- An order item is not limited to a lens. Tests and interfaces must support
  frames, lens categories, coatings, accessories, services, and custom charge
  lines through the existing nullable catalog relationships and description.

## Phase A: Baseline and Additive Schema

### Task A1: Characterize the Current Aggregate

**Description**

Add focused characterization coverage for the current Quotation -> Revision ->
Job Order -> Billing Record aggregate before changing its storage. Capture the
invariants that the migration must preserve: ownership, totals, line counts,
stable `eyewear_key`, and the single linked Job Order.

**Acceptance criteria**

- The test creates representative legacy data with product, lens, service, and
  custom-description lines.
- It proves current totals, ownership, Job Order linkage, Billing linkage, and
  `eyewear_key` continuity.
- The test is characterization only; no production behavior changes.

**Files**

- `tests/Feature/OpticalOrders/LegacyOpticalOrderCharacterizationTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/LegacyOpticalOrderCharacterizationTest.php
```

**Dependencies:** None.

### Task A2: Add and Backfill Direct Commercial Fields

**Description**

Generate an additive transition migration. Add direct totals and lifecycle
metadata to `quotations`, add nullable direct foreign keys to
`quotation_items` and `job_orders`, and add
`billing_records.payment_due_date`. Backfill from each Quotation's highest
revision number without removing the legacy relationships.

**Acceptance criteria**

- Existing Quotations receive the latest revision's subtotal, discount, total,
  presentation metadata, and items.
- Existing Job Orders resolve to the same Quotation, patient, and
  `eyewear_key`; ambiguous or invalid legacy data makes the migration fail
  clearly rather than guessing.
- The migration is reversible and leaves legacy tables/columns intact.
- Direct lookup and lifecycle-list indexes are present.

**Files**

- `database/migrations/*_add_direct_fields_to_optical_order_aggregate.php`
- `tests/Feature/OpticalOrders/OpticalOrderTransitionMigrationTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/OpticalOrderTransitionMigrationTest.php
vendor/bin/sail artisan migrate:fresh --seed --no-interaction
```

**Dependencies:** A1.

### Task A3: Introduce Direct Quotation Relationships

**Description**

Teach the Quotation and Quotation Item models and factories about direct
ownership while the legacy bridge remains available for migration safety.

**Acceptance criteria**

- `Quotation::items()` and `Quotation::jobOrder()` use direct keys.
- Direct totals, presentation metadata, and confirmation metadata have correct
  casts/fillability.
- Factories can create direct drafts with heterogeneous item types without a
  Quotation Revision.

**Files**

- `app/Models/Quotation.php`
- `app/Models/QuotationItem.php`
- `database/factories/QuotationFactory.php`
- `database/factories/QuotationItemFactory.php`
- `tests/Feature/Quotations/QuotationDirectRelationshipsTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Quotations/QuotationDirectRelationshipsTest.php
```

**Dependencies:** A2.

### Task A4: Introduce Direct Job Order and Billing Fields

**Description**

Add the direct Job Order-to-Quotation relationship and Billing payment-due-date
behavior, including a reusable outstanding/overdue query definition.

**Acceptance criteria**

- `JobOrder::quotation()` uses `quotation_id` and Quotation ownership can be
  traversed in both directions.
- `BillingRecord::payment_due_date` is date-cast.
- Overdue means an active unpaid balance whose due date is before today; paid
  and voided records are never overdue.
- Factories create valid direct aggregate records.

**Files**

- `app/Models/JobOrder.php`
- `app/Models/BillingRecord.php`
- `database/factories/JobOrderFactory.php`
- `database/factories/BillingRecordFactory.php`
- `tests/Feature/BillingRecords/BillingRecordDueDateTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/BillingRecordDueDateTest.php
```

**Dependencies:** A2.

### Checkpoint A

Before Phase B:

```bash
vendor/bin/sail artisan test --compact \
  tests/Feature/OpticalOrders/LegacyOpticalOrderCharacterizationTest.php \
  tests/Feature/OpticalOrders/OpticalOrderTransitionMigrationTest.php \
  tests/Feature/Quotations/QuotationDirectRelationshipsTest.php \
  tests/Feature/BillingRecords/BillingRecordDueDateTest.php
vendor/bin/sail bin pint --dirty --format agent
```

Review the backfill reconciliation results before switching application writes.

## Phase B: Direct Draft Lifecycle

### Task B1: Create Drafts Without Revisions

**Description**

Refactor the Quotation creation action to store current totals and items
directly. Accept a Patient with optional Encounter and Prescription context so
staff can begin an Optical Order outside an Encounter when clinically valid.

**Acceptance criteria**

- Creating a draft writes one Quotation and its direct items, and writes no
  revision.
- Quantity, unit price, amount, subtotal, discount, and total are calculated or
  validated server-side.
- Corrective lens lines require an eligible Prescription; non-corrective sales
  do not require an Encounter.
- Mixed product/service/custom line items are preserved.

**Files**

- `app/Actions/Quotations/CreateQuotation.php`
- `tests/Feature/Quotations/CreateQuotationTest.php`
- `tests/Feature/Quotations/QuotationRevisionTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Quotations/CreateQuotationTest.php tests/Feature/Quotations/QuotationRevisionTest.php
```

**Dependencies:** A3, A4.

### Task B2: Add a Server-Calculated Draft Update Action

**Description**

Create one action for editing draft or presented commercial data and replacing
its direct item set atomically.

**Acceptance criteria**

- The action permits edits only in Draft or Presented.
- It recalculates every line amount and aggregate total server-side in one
  transaction.
- Editing a Presented quotation clears presentation metadata and returns it to
  Draft.
- Accepted, declined, and expired quotations are immutable.

**Files**

- `app/Actions/Quotations/UpdateQuotationDraft.php`
- `tests/Feature/Quotations/UpdateQuotationDraftTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Quotations/UpdateQuotationDraftTest.php
```

**Dependencies:** B1.

### Task B3: Move Presentation and Decisions onto Quotation

**Description**

Refactor share/presentation and decision actions to use direct Quotation
metadata and the approved status transitions.

**Acceptance criteria**

- Draft can be presented or accepted; Presented can be accepted, declined, or
  expired.
- Presentation and decision actors/timestamps are stored directly on the
  Quotation.
- Invalid or repeated terminal transitions fail without partial writes.
- Drafts remain excluded from patient-visible queries.

**Files**

- `app/Actions/Quotations/PresentQuotation.php`
- `app/Actions/Quotations/RecordQuotationDecision.php`
- `tests/Feature/Quotations/QuotationLifecycleTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Quotations/QuotationLifecycleTest.php
```

**Dependencies:** B1, B2.

### Checkpoint B

```bash
vendor/bin/sail artisan test --compact tests/Feature/Quotations
vendor/bin/sail bin pint --dirty --format agent
```

Confirm that no new application write creates a Quotation Revision before
continuing.

## Phase C: Atomic Confirmation and Fulfilment

### Task C1: Create the Job Order Snapshot Once

**Description**

Refactor `AcceptAndStartOpticalOrder` as the canonical transaction and create a
single direct Job Order with a snapshot of every direct Quotation item.

**Acceptance criteria**

- Draft or Presented can be confirmed while holding a database row lock.
- Confirmation creates exactly one Job Order identified by direct
  `quotation_id` and matching patient/Prescription/`eyewear_key`.
- All product, lens, coating, accessory, service, and custom lines are copied
  with accepted descriptions and selling prices.
- Repeated confirmation cannot create a second Job Order.

**Files**

- `app/Actions/OpticalOrders/AcceptAndStartOpticalOrder.php`
- `tests/Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php`
- `tests/Feature/JobOrders/CreateJobOrderTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php tests/Feature/JobOrders/CreateJobOrderTest.php
```

**Dependencies:** B3.

### Task C2: Consolidate Inventory and Frame Reservation Conversion

**Description**

Move existing stock commitment and frame-reservation conversion behind the
canonical confirmation transaction so stock is committed once.

**Acceptance criteria**

- An eligible frame reservation converts to the created Job Order.
- Reserved or available inventory is committed exactly once for catalog-backed
  frame/accessory lines; service/custom lines do not affect inventory.
- Insufficient stock, ownership mismatch, or invalid reservation rolls back the
  Quotation status, Job Order, items, and every inventory movement.

**Files**

- `app/Actions/OpticalOrders/AcceptAndStartOpticalOrder.php`
- `app/Actions/JobOrders/CreateJobOrder.php`
- `tests/Feature/JobOrders/JobOrderInventoryAtomicTest.php`
- `tests/Feature/OpticalOrders/FrameReservationJobOrderLinkTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/JobOrders/JobOrderInventoryAtomicTest.php tests/Feature/OpticalOrders/FrameReservationJobOrderLinkTest.php
```

**Dependencies:** C1.

### Task C3: Create Billing and Optional Deposit at Confirmation

**Description**

Extend the canonical transaction to create the one Billing Record, store its
payment due date, and post an optional initial payment through the existing
ledger action.

**Acceptance criteria**

- Confirmation creates exactly one Billing Record whose total equals the
  accepted order total.
- A valid optional deposit creates one posted payment and updates paid,
  balance, and status; zero/omitted deposit creates no payment.
- Due date is nullable, stored as a date, and cannot precede confirmation.
- Invalid payment or duplicate submission rolls back the whole confirmation.

**Files**

- `app/Actions/OpticalOrders/AcceptAndStartOpticalOrder.php`
- `app/Actions/BillingRecords/RecordBillingPayment.php`
- `tests/Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php`
- `tests/Feature/BillingRecords/PaymentLifecycleTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php tests/Feature/BillingRecords/PaymentLifecycleTest.php
```

**Dependencies:** C2, A4.

### Task C4: Dispense Against Existing Billing

**Description**

Change dispensing to require and reuse the Billing Record created at
confirmation. Preserve pickup payments and dispensing events, but remove
billing creation from this stage.

**Acceptance criteria**

- A Ready Job Order with an active Billing Record can be dispensed according to
  the existing payment policy.
- Dispensing never creates another Billing Record.
- Missing/void billing, invalid pickup payment, or invalid state rolls back all
  dispensing changes.
- Supplier invoice remains required before Ready, not before initial
  confirmation.

**Files**

- `app/Actions/BillingRecords/DispenseJobOrder.php`
- `tests/Feature/BillingRecords/DispensingTest.php`
- `tests/Feature/Filament/JobOrderResourceTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/DispensingTest.php tests/Feature/Filament/JobOrderResourceTest.php
```

**Dependencies:** C3.

### Task C5: Preserve Cancellation and Ledger Safeguards

**Description**

Update cancellation, reversal, and ledger regression tests for early billing
and direct Quotation ownership.

**Acceptance criteria**

- Cancellation releases eligible committed inventory exactly once.
- Posted payments cannot silently disappear; existing void/reversal rules
  remain enforced.
- Paid and voided Billing Records never appear overdue.
- Aggregate relationships remain consistent after allowed cancellation paths.

**Files**

- `tests/Feature/JobOrders/JobOrderInventoryTest.php`
- `tests/Feature/BillingRecords/BillingRecordLedgerTest.php`
- `tests/Feature/BillingRecords/BillingRecordRelationshipsTest.php`
- `tests/Feature/BillingRecords/BillingRecordDueDateTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/JobOrders tests/Feature/BillingRecords
```

**Dependencies:** C4.

### Checkpoint C

```bash
vendor/bin/sail artisan test --compact \
  tests/Feature/OpticalOrders \
  tests/Feature/JobOrders \
  tests/Feature/BillingRecords
vendor/bin/sail bin pint --dirty --format agent
```

Review one direct-sale and one shared-quotation path for duplicate Job Orders,
Billing Records, payments, reservations, and inventory movements.

## Phase D: Unified Filament Workflow

### Task D1: Build the Optical Order Draft Form and Pages

**Description**

Enable Optical Order creation and editing through dedicated pages that call the
approved draft actions. Reuse the existing item-builder fields rather than
maintain a second commercial form.

**Acceptance criteria**

- Staff can choose a Patient, optional Encounter/Prescription, validity date,
  notes, discount, and multiple heterogeneous line items.
- The UI supports catalog-backed frames/lenses/accessories and free-description
  service/custom lines; it does not label the entire item collection “Lens
  types.”
- Server validation and calculated totals are surfaced as Filament form errors.
- Edit is available only while Draft or Presented, with Presented edits
  returning to Draft.

**Files**

- `app/Filament/Resources/OpticalOrders/OpticalOrderResource.php`
- `app/Filament/Resources/OpticalOrders/Pages/CreateOpticalOrder.php`
- `app/Filament/Resources/OpticalOrders/Pages/EditOpticalOrder.php`
- `app/Filament/Resources/OpticalOrders/Schemas/OpticalOrderForm.php`
- `tests/Feature/Filament/OpticalOrderFormTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrderFormTest.php
```

**Dependencies:** B3, C3.

### Task D2: Add Lifecycle Tabs and Aggregate Columns

**Description**

Refactor the Optical Orders list into lifecycle tabs with direct eager-loaded
data and derived operational/financial columns.

**Acceptance criteria**

- Tabs are All, Drafts, Awaiting Decision, Confirmed, In Production, Ready for
  Pickup, and Completed with correct query membership.
- The table shows patient, order reference, stage, distinct lens categories,
  supplier invoice, total, payment state, balance, due date, and latest
  activity.
- Orders without lens-category lines display a neutral value while retaining
  their other product/service lines.
- Required relationships are eager loaded without row-by-row queries.

**Files**

- `app/Filament/Resources/OpticalOrders/Pages/ListOpticalOrders.php`
- `app/Filament/Resources/OpticalOrders/Tables/OpticalOrdersTable.php`
- `app/Filament/Resources/OpticalOrders/OpticalOrderResource.php`
- `tests/Feature/Filament/OpticalOrderListTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrderListTest.php
```

**Dependencies:** D1.

### Task D3: Make the Optical Order Detail Stage-Aware

**Description**

Organize the detail page into commercial, fulfilment, and payment sections and
show only actions valid for the current stage.

**Acceptance criteria**

- Draft offers edit, share estimate, and confirm sale; Awaiting Decision offers
  edit, confirm, decline, and expire as permitted.
- Confirm Sale collects payment due date and optional deposit, and displays a
  clear confirmation summary before committing.
- Confirmed/production stages show Job Order information, all items, supplier
  reference, payment summary, and only the next permitted operational action.
- Internal supplier reference and staff notes are clearly separated from
  patient-visible content.

**Files**

- `app/Filament/Resources/OpticalOrders/Pages/ViewOpticalOrder.php`
- `app/Filament/Resources/OpticalOrders/Schemas/OpticalOrderInfolist.php`
- `app/Filament/Resources/OpticalOrders/OpticalOrderResource.php`
- `tests/Feature/Filament/OpticalOrderWorkflowTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrderWorkflowTest.php
```

**Dependencies:** D2, C5.

### Task D4: Expose Billing & Payments Navigation and List

**Description**

Make the existing Billing Record resource visible under Finance and reshape
its list for receivables work.

**Acceptance criteria**

- Navigation label is Billing & Payments under Finance; technical Quotation and
  Job Order navigation remains hidden.
- Tabs are All, Outstanding, Overdue, Paid, and Voided with correct membership.
- The table shows billing number, patient, Job Order, total, paid, balance,
  status, and payment due date, with clear overdue treatment.
- Existing authorization rules still protect the resource.

**Files**

- `app/Filament/Resources/BillingRecords/BillingRecordResource.php`
- `app/Filament/Resources/BillingRecords/Pages/ListBillingRecords.php`
- `app/Filament/Resources/BillingRecords/Tables/BillingRecordsTable.php`
- `tests/Feature/Filament/BillingRecordResourceTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/BillingRecordResourceTest.php
```

**Dependencies:** A4, C5.

### Task D5: Add Billing Due-Date and Payment Operations

**Description**

Add focused Billing detail actions for adjusting an allowed due date and using
the existing payment ledger without turning Billing into a second order editor.

**Acceptance criteria**

- Authorized staff can set or change the due date on an active unpaid balance;
  invalid dates are rejected.
- Existing post, void, and reverse payment actions remain available and update
  derived status/balance.
- Order items and commercial prices are read-only from Billing.

**Files**

- `app/Filament/Resources/BillingRecords/Pages/EditBillingRecord.php`
- `app/Filament/Resources/BillingRecords/RelationManagers/PaymentsRelationManager.php`
- `tests/Feature/Filament/BillingRecordResourceTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/BillingRecordResourceTest.php tests/Feature/BillingRecords/PaymentLifecycleTest.php
```

**Dependencies:** D4.

### Checkpoint D

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrderFormTest.php tests/Feature/Filament/OpticalOrderListTest.php tests/Feature/Filament/OpticalOrderWorkflowTest.php tests/Feature/Filament/BillingRecordResourceTest.php
vendor/bin/sail npm run build
vendor/bin/sail bin pint --dirty --format agent
```

Use the real browser to verify desktop and narrow layouts, modal behavior,
tab counts, keyboard flow, and recent console/network logs.

## Phase E: Patient Eyewear API

### Task E1: Query Direct Patient Eyewear Ownership

**Description**

Refactor list and find queries to resolve direct Quotation/Job Order ownership
without revisions while preserving privacy and history filters.

**Acceptance criteria**

- Linked patients can list/find only their own Presented or confirmed orders.
- Drafts and another patient's eyewear keys return no patient data.
- Current/history filters and deterministic ordering remain compatible.
- Queries eager load the direct item, Job Order, Billing, and payment data used
  by serialization.

**Files**

- `app/Services/Eyewear/ListPatientEyewear.php`
- `app/Services/Eyewear/FindPatientEyewear.php`
- `tests/Feature/Eyewear/EyewearListQueryTest.php`
- `tests/Feature/Eyewear/FindPatientEyewearTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Eyewear/EyewearListQueryTest.php tests/Feature/Eyewear/FindPatientEyewearTest.php
```

**Dependencies:** C5.

### Task E2: Build the Direct Eyewear Aggregate

**Description**

Build the patient aggregate from Quotation items before confirmation and Job
Order snapshot items after confirmation. Add amount paid and due date while
preserving existing progress values.

**Acceptance criteria**

- Presented estimates use direct Quotation items; confirmed orders use Job
  Order items.
- Every patient-facing product/service/custom line is serialized with
  description, quantity, unit price, and amount.
- The aggregate exposes total, amount paid, remaining balance, payment status,
  and nullable payment due date.
- Supplier invoice, internal notes, supplier cost, and draft data are absent.

**Files**

- `app/Services/Eyewear/BuildEyewearAggregate.php`
- `app/Services/Eyewear/EyewearAggregate.php`
- `tests/Feature/Eyewear/EyewearAggregateTest.php`
- `tests/Feature/Eyewear/EyewearKeyTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Eyewear/EyewearAggregateTest.php tests/Feature/Eyewear/EyewearKeyTest.php
```

**Dependencies:** E1.

### Task E3: Update Patient Eyewear Resources

**Description**

Expose the approved financial and item fields through the canonical
`/api/v1/eyewear` summary/detail resources without changing route envelopes.

**Acceptance criteria**

- Summary and detail responses include the approved total, amount-paid,
  balance, status, and due-date fields.
- Detail includes all order items, not only lens categories.
- Existing keys and progress values remain backward compatible.
- API tests assert privacy exclusions explicitly.

**Files**

- `app/Http/Resources/EyewearSummaryResource.php`
- `app/Http/Resources/EyewearDetailResource.php`
- `tests/Feature/Eyewear/EyewearResourceTest.php`
- `tests/Feature/Api/EyewearApiTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Eyewear/EyewearResourceTest.php tests/Feature/Api/EyewearApiTest.php
```

**Dependencies:** E2.

### Task E4: Preserve Legacy Direct API Envelopes

**Description**

Adapt the existing direct Quotation and Job Order `/api/v1` serializers to the
direct model. Where required for compatibility, synthesize a single current
`revision` object from Quotation fields without retaining a revision model.

**Acceptance criteria**

- Existing route names and top-level response envelopes remain unchanged.
- Quotation output reads direct totals/items and exposes no internal supplier
  data.
- Job Order output uses direct Quotation ownership and continues hiding the
  supplier invoice.
- Authorization and unlinked-account restrictions remain unchanged.

**Files**

- `app/Http/Resources/QuotationResource.php`
- `app/Http/Controllers/Api/QuotationController.php`
- `app/Http/Controllers/Api/JobOrderController.php`
- `tests/Feature/Api/V1/QuotationTest.php`
- `tests/Feature/Api/V1/JobOrderTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/QuotationTest.php tests/Feature/Api/V1/JobOrderTest.php
```

**Dependencies:** E2.

### Checkpoint E

```bash
vendor/bin/sail artisan test --compact tests/Feature/Eyewear tests/Feature/Api/EyewearApiTest.php tests/Feature/Api/V1/QuotationTest.php tests/Feature/Api/V1/JobOrderTest.php
vendor/bin/sail bin pint --dirty --format agent
```

Review example JSON for Presented, Confirmed, In Production, Ready, Completed,
partially paid, overdue, paid, and cancelled orders.

## Phase F: Revision Removal and Final Reconciliation

### Task F1: Add the Revision Cleanup Migration

**Description**

After every direct-flow checkpoint passes, generate the destructive cleanup
migration that enforces direct ownership and removes revision foreign keys and
the `quotation_revisions` table.

**Acceptance criteria**

- Reconciliation guards stop cleanup if a Quotation item or Job Order lacks a
  valid direct Quotation.
- Direct `quotation_id` constraints and uniqueness prevent duplicate Job Orders
  for one Quotation.
- Revision foreign keys/columns and the revision table are removed in valid
  dependency order.
- Rollback recreates a structurally valid bridge or the migration is explicitly
  documented/tested as safely irreversible according to project convention.
- The unrelated `frame_rating_revisions` structure is untouched.

**Files**

- `database/migrations/*_remove_quotation_revision_architecture.php`
- `tests/Feature/OpticalOrders/OpticalOrderCleanupMigrationTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/OpticalOrderCleanupMigrationTest.php
vendor/bin/sail artisan migrate:fresh --seed --no-interaction
```

**Dependencies:** Checkpoints C, D, and E.

### Task F2: Remove Revision Domain Artifacts

**Description**

Delete the unused revision model/factory and remove remaining domain-level
revision relationships and independent Job Order creation entry points.

**Acceptance criteria**

- No runtime model/action references `QuotationRevision` or
  `quotation_revision_id`.
- The canonical confirmation action is the only sale-to-Job-Order entry point.
- Revision-specific tests are replaced by direct lifecycle assertions, not
  simply deleted.

**Files**

- `app/Models/QuotationRevision.php`
- `database/factories/QuotationRevisionFactory.php`
- `app/Actions/JobOrders/CreateJobOrder.php`
- `tests/Feature/Quotations/QuotationRevisionTest.php`
- `tests/Feature/JobOrders/CreateJobOrderTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Quotations tests/Feature/JobOrders tests/Feature/OpticalOrders
```

**Dependencies:** F1.

### Task F3: Remove Revision UI and Serializer Reads

**Description**

Remove or refactor the remaining hidden technical Quotation UI and serializers
so no interface queries revisions.

**Acceptance criteria**

- No Filament schema, table, page, controller, or API resource loads latest
  revision or revision items.
- Hidden technical Quotation/Job Order navigation stays hidden while valid
  compatibility routes still work.
- Optical Orders and Billing & Payments remain the staff entry points.

**Files**

- `app/Filament/Resources/Quotations/QuotationResource.php`
- `app/Filament/Resources/Quotations/Schemas/QuotationForm.php`
- `app/Filament/Resources/Quotations/Tables/QuotationsTable.php`
- `tests/Feature/Filament/QuotationResourceTest.php`
- `tests/Feature/Filament/QuotationCreationTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/QuotationResourceTest.php tests/Feature/Filament/QuotationCreationTest.php tests/Feature/Filament/OpticalOrderFormTest.php
```

**Dependencies:** F1, F2.

### Task F4: Reconcile Seeders and End-to-End Workflow

**Description**

Update seeded clinic data and the end-to-end workflow to use the simplified
direct aggregate from draft through dispensing and payment.

**Acceptance criteria**

- Seeded data contains representative direct sale and shared-quotation paths
  with heterogeneous items.
- End-to-end coverage proves confirmation creates one Job Order/Billing Record,
  patient tracking shows all approved fields, and dispensing reuses billing.
- No seeder creates a Quotation Revision.

**Files**

- `database/seeders/ClinicWorkflowSeeder.php`
- `tests/Feature/Seeders/ClinicWorkflowSeederTest.php`
- `tests/Feature/EndToEnd/ClinicWorkflowTest.php`

**Verify**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Seeders/ClinicWorkflowSeederTest.php tests/Feature/EndToEnd/ClinicWorkflowTest.php
```

**Dependencies:** F2, F3.

### Task F5: Final Static Search and Regression Gate

**Description**

Run the complete quality gate and reconcile the implemented behavior with the
approved spec and plan. This task changes code only if a failing gate reveals a
defect; it does not add scope.

**Acceptance criteria**

- Static search finds no application reference to `QuotationRevision`,
  `quotation_revisions`, `quotation_revision_id`, or `latestRevision`; migration
  history is the only permitted occurrence.
- `frame_rating_revisions` remains intact.
- Full tests, formatting, and production asset build pass.
- Real-browser smoke testing passes for Optical Orders and Billing & Payments
  with no recent console/network error.
- Git diff contains no unintended Appointment/Encounter changes from this
  feature.

**Files**

- No planned file; defect-specific files only after a failing gate.

**Verify**

```bash
rg -n "QuotationRevision|quotation_revisions|quotation_revision_id|latestRevision" app database tests routes
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
git diff --check
git status --short
```

**Dependencies:** F4.

## Completion Definition

The feature is complete only when all task acceptance criteria and checkpoints
pass, direct and shared quotation flows both work, patient tracking contains all
order items plus approved financial fields, revision application code and
storage are gone, and unrelated user changes remain preserved.
