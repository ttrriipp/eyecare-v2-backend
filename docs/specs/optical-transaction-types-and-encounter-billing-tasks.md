# Tasks: Optical Transaction Types and Unified Checkout Billing

## Status

Draft for project-owner review on 2026-08-03. This is Phase 3 (**Tasks**) of
the spec-driven workflow. The Phase 1 specification and Phase 2 implementation
plan are approved. No application-code changes are authorized until this task
breakdown is approved.

## Working Rules

- Execute tasks in dependency order and stop at every checkpoint.
- Search installed-version Laravel and Filament documentation before the first
  code change in each relevant area.
- Create new Laravel files with the appropriate Sail-prefixed Artisan
  `make:*` command and `--no-interaction`.
- Write or update the named Pest test before changing behavior.
- Keep each task within approximately five files.
- Run `vendor/bin/sail bin pint --dirty --format agent` after every task that
  changes PHP.
- Preserve unrelated changes in the existing dirty worktree.

## Phase A: Characterization and Schema Foundation

### Task 1: Lock current commerce behavior with characterization tests

**Description:** Capture the behavior that must survive the refactor before
changing schema or relationships.

**Acceptance criteria:**

- [ ] Confirmation, inventory commitment, reservation conversion, billing,
  optional deposit, cancellation, and dispensing are characterized.
- [ ] Existing Job Order billing lookup, patient eyewear total behavior,
  first-payment entry points, and the posted-payment charge lock are explicitly
  asserted before their safeguards change.
- [ ] The tests pass against the pre-change application.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/LegacyOpticalOrderCharacterizationTest.php tests/Feature/BillingRecords/BillingRecordLedgerTest.php
```

**Dependencies:** None.

**Files likely touched:**

- `tests/Feature/OpticalOrders/LegacyOpticalOrderCharacterizationTest.php`
- `tests/Feature/BillingRecords/BillingRecordLedgerTest.php`

**Estimated scope:** Small (2 files).

### Task 2: Add guarded item-type and fulfillment schema

**Description:** Add canonical Optical Order item classification and Job Order
fulfillment metadata with deterministic historical backfill.

**Acceptance criteria:**

- [ ] `quotation_items.item_type` and `job_order_items.item_type` are required
  after product/lens rows become `product` and ambiguous custom rows become
  `legacy_other`.
- [ ] Job Orders store `fulfillment_mode` and `uses_external_supplier`;
  existing rows retain prepared-work behavior.
- [ ] Migration guards abort when classification reconciliation fails.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/OpticalTransactionSchemaMigrationTest.php
```

**Dependencies:** Task 1.

**Files likely touched:**

- `database/migrations/YYYY_MM_DD_HHMMSS_add_item_types_and_fulfillment_to_canonical_optical_tables.php`
- `tests/Feature/OpticalOrders/OpticalTransactionSchemaMigrationTest.php`

**Estimated scope:** Small (2 files).

### Task 3: Add itemized unified-checkout billing schema

**Description:** Create canonical Billing Record items, add financial summary
fields, and permit Encounter-only, Combined, and corrected reissued bills.

**Acceptance criteria:**

- [ ] `billing_record_items` stores immutable item snapshots with nullable Job
  Order item or Encounter origin.
- [ ] Billing Records store subtotal and discount; `job_order_id` is nullable
  and non-unique, with at least one source required.
- [ ] Existing records backfill item counts and totals without changing paid,
  balance, due-date, or source values; irreconcilable data aborts migration.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/UnifiedCheckoutSchemaMigrationTest.php
```

**Dependencies:** Task 2.

**Files likely touched:**

- `database/migrations/YYYY_MM_DD_HHMMSS_add_itemized_unified_checkout_to_billing_records.php`
- `tests/Feature/BillingRecords/UnifiedCheckoutSchemaMigrationTest.php`

**Estimated scope:** Small (2 files).

### Task 4: Model typed Optical Order items

**Description:** Add enum-backed casts and factory states for product, service,
and migration-only legacy lines.

**Acceptance criteria:**

- [ ] Quotation and Job Order items cast `item_type` to one controlled enum.
- [ ] Factories create valid product and service states without generating new
  `legacy_other` lines by default.
- [ ] Model tests demonstrate round-trip persistence for every stored value.

**Verification:**

```text
vendor/bin/sail artisan test --compact --filter=TransactionItemType
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 2.

**Files likely touched:**

- `app/Enums/TransactionItemType.php`
- `app/Models/QuotationItem.php`
- `app/Models/JobOrderItem.php`
- `database/factories/QuotationItemFactory.php`
- `database/factories/JobOrderItemFactory.php`

**Estimated scope:** Medium (5 files).

### Task 5: Model Billing Record item and source history relationships

**Description:** Establish item ownership and active/history relationships
before dropping unique-bill assumptions from consumers.

**Acceptance criteria:**

- [ ] Billing Record owns items and exposes derived Encounter, Optical Order,
  or Combined source context.
- [ ] Job Order exposes billing history and its one non-voided active Billing
  Record; Encounter exposes related bills.
- [ ] Relationship tests cover Encounter-only, Optical-only, Combined, voided
  history, and active resolution.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/BillingRecordRelationshipsTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 3.

**Files likely touched:**

- `app/Models/BillingRecordItem.php`
- `app/Models/BillingRecord.php`
- `app/Models/JobOrder.php`
- `app/Models/Encounter.php`
- `tests/Feature/BillingRecords/BillingRecordRelationshipsTest.php`

**Estimated scope:** Medium (5 files).

### Task 6: Align factories with source ownership and reissue behavior

**Description:** Make test data honor Patient ownership across Billing Record,
Encounter, and Job Order sources.

**Acceptance criteria:**

- [ ] Factories provide Encounter-only, Optical-only, Combined, paid, voided,
  and reissued states with matching Patient ownership.
- [ ] Job Order factory supplies valid fulfillment defaults.
- [ ] Billing Record item factory produces reconciled snapshot amounts.

**Verification:**

```text
vendor/bin/sail artisan test --compact --filter=BillingRecordFactory
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 3-5.

**Files likely touched:**

- `database/factories/BillingRecordItemFactory.php`
- `database/factories/BillingRecordFactory.php`
- `database/factories/JobOrderFactory.php`
- `tests/Feature/BillingRecords/BillingRecordFactoryTest.php`

**Estimated scope:** Medium (4 files).

### Checkpoint A: Foundation

```text
vendor/bin/sail artisan migrate --no-interaction
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/OpticalTransactionSchemaMigrationTest.php tests/Feature/BillingRecords/UnifiedCheckoutSchemaMigrationTest.php tests/Feature/BillingRecords/BillingRecordRelationshipsTest.php
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] Guarded backfills reconcile all canonical records.
- [ ] Models and factories represent every approved source context.
- [ ] No application workflow behavior has changed yet.

## Phase B: Typed Optical Order Entry

### Task 7: Enforce item types in Quotation actions

**Description:** Make the domain create and update typed product and free-text
Service lines while rejecting new `legacy_other` input.

**Acceptance criteria:**

- [ ] Create and draft-update actions persist `product` or `service` for every
  line and recalculate totals from validated values.
- [ ] Product/lens references require product type; Service lines have no
  catalog relationship.
- [ ] Accepted Quotations remain immutable and malformed combinations fail
  validation without partial writes.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Quotations/CreateQuotationTest.php tests/Feature/Quotations/UpdateQuotationDraftTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 4.

**Files likely touched:**

- `app/Actions/Quotations/CreateQuotation.php`
- `app/Actions/Quotations/UpdateQuotationDraft.php`
- `tests/Feature/Quotations/CreateQuotationTest.php`
- `tests/Feature/Quotations/UpdateQuotationDraftTest.php`

**Estimated scope:** Medium (4 files).

### Task 8: Present one typed Optical Order item-entry form

**Description:** Update create/edit UX to offer Catalog Product, Lens Category,
Custom Product, and Service without a Services settings resource.

**Acceptance criteria:**

- [ ] Item mode controls the relevant selectors and persists the correct
  product/service type.
- [ ] Editing round-trips item type and existing values without converting
  historical `legacy_other` rows silently.
- [ ] The form derives Product-only, Service-only, or Mixed as read-only
  transaction context.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrderResourceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 7.

**Files likely touched:**

- `app/Filament/Resources/OpticalOrders/Schemas/OpticalOrderForm.php`
- `app/Filament/Resources/OpticalOrders/Pages/CreateOpticalOrder.php`
- `app/Filament/Resources/OpticalOrders/Pages/EditOpticalOrder.php`
- `tests/Feature/Filament/OpticalOrderResourceTest.php`

**Estimated scope:** Medium (4 files).

### Checkpoint B: Typed orders

```text
vendor/bin/sail artisan test --compact tests/Feature/Quotations tests/Feature/Filament/OpticalOrderResourceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] Staff can save product-only, service-only, and mixed drafts.
- [ ] No new line can be ambiguously untyped.
- [ ] Confirmation still uses the existing prepared path until Phase C/D.

## Phase C: Unified Billing Engine

### Task 9: Recalculate Billing Record totals from snapshots

**Description:** Centralize subtotal, accepted Quotation discount, total,
amount-paid, balance, and status calculation.

**Acceptance criteria:**

- [ ] Totals derive exclusively from Billing Record items and approved
  discount input.
- [ ] Existing posted payments are preserved and balance/status are updated
  deterministically.
- [ ] Negative totals, discounts above subtotal, and paid/voided mutations are
  rejected according to the approved lock rules.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/RecalculateBillingRecordTotalsTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 5-6.

**Files likely touched:**

- `app/Actions/BillingRecords/RecalculateBillingRecordTotals.php`
- `tests/Feature/BillingRecords/RecalculateBillingRecordTotalsTest.php`

**Estimated scope:** Small (2 files).

### Task 10: Resolve the open same-checkout Billing Record

**Description:** Add the row-locked create-or-reuse decision shared by Optical
Order confirmation and Encounter charge entry.

**Acceptance criteria:**

- [ ] Same-Patient, same-Encounter, unpaid records without posted payments are
  reused when source relationships do not conflict.
- [ ] Paid, partially paid, or voided records are never reopened; later charges
  create another record.
- [ ] Concurrent calls cannot create two open checkout records for the same
  source combination.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/ResolveOpenCheckoutBillingRecordTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 9.

**Files likely touched:**

- `app/Actions/BillingRecords/ResolveOpenCheckoutBillingRecord.php`
- `tests/Feature/BillingRecords/ResolveOpenCheckoutBillingRecordTest.php`

**Estimated scope:** Small (2 files).

### Task 11: Snapshot Job Order items into Billing

**Description:** Append typed Job Order items idempotently and apply the
accepted Quotation discount without touching Encounter-originating lines.

**Acceptance criteria:**

- [ ] Every Job Order item creates one matching item per Billing Record.
- [ ] Repeated calls add no duplicate items or discount.
- [ ] Patient/source mismatches and posted-payment locks reject the entire
  transaction.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/AppendJobOrderItemsToBillingRecordTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 9-10.

**Files likely touched:**

- `app/Actions/BillingRecords/AppendJobOrderItemsToBillingRecord.php`
- `tests/Feature/BillingRecords/AppendJobOrderItemsToBillingRecordTest.php`

**Estimated scope:** Small (2 files).

### Task 12: Refactor prepared Optical Order confirmation

**Description:** Route the existing prepared confirmation through typed Job
Order snapshots and the unified billing engine while retaining reservations,
inventory, due dates, deposits, and idempotency.

**Acceptance criteria:**

- [ ] Prepared confirmation creates/reuses the correct checkout bill; a
  positive deposit is posted only after its items are snapshotted and the
  reviewed-charge acknowledgement is supplied.
- [ ] Repeated confirmation duplicates no Job Order, item, inventory movement,
  reservation conversion, Billing Record, or payment.
- [ ] Existing frame-reservation and ledger behavior remains green.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php tests/Feature/OpticalOrders/FrameReservationJobOrderLinkTest.php tests/Feature/BillingRecords/BillingRecordLedgerTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 7 and 9-11.

**Files likely touched:**

- `app/Actions/OpticalOrders/AcceptAndStartOpticalOrder.php`
- `tests/Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php`
- `tests/Feature/OpticalOrders/FrameReservationJobOrderLinkTest.php`
- `tests/Feature/BillingRecords/BillingRecordLedgerTest.php`

**Estimated scope:** Medium (4 files).

### Task 13: Replace unique-bill assumptions in operational consumers

**Description:** Make cancellation, dispensing, and Optical Order displays use
the active Billing Record while retaining voided history.

**Acceptance criteria:**

- [ ] Cancellation and dispensing resolve only the non-voided active bill.
- [ ] Optical Order table/form status, balance, and due date use active billing
  explicitly.
- [ ] A voided predecessor plus corrected active bill never selects the wrong
  record.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/BillingRecordLedgerTest.php tests/Feature/BillingRecords/DispensingTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 5 and 12.

**Files likely touched:**

- `app/Actions/OpticalOrders/CancelOpticalOrder.php`
- `app/Actions/BillingRecords/DispenseJobOrder.php`
- `app/Filament/Resources/OpticalOrders/Schemas/OpticalOrderForm.php`
- `app/Filament/Resources/OpticalOrders/Tables/OpticalOrdersTable.php`
- `tests/Feature/BillingRecords/BillingRecordLedgerTest.php`

**Estimated scope:** Medium (5 files).

### Checkpoint C: Unified optical billing

```text
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders tests/Feature/BillingRecords
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] Every confirmed transaction has reconciled canonical Billing items.
- [ ] Same-checkout reuse and payment locking are proven.
- [ ] Voided billing history does not break active operations.

## Phase D: Fulfillment Modes

### Task 14: Make supplier invoice enforcement conditional

**Description:** Enforce supplier invoice only for prepared external work and
expose the operational toggle to staff.

**Acceptance criteria:**

- [ ] External prepared work cannot become Ready without supplier invoice.
- [ ] In-house prepared and immediate work do not require a supplier invoice.
- [ ] Existing prepared Job Orders retain their historical enforcement default.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/JobOrders/JobOrderSupplierRequirementTest.php tests/Feature/Filament/JobOrderResourceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 2 and 13.

**Files likely touched:**

- `app/Actions/JobOrders/UpdateJobOrderStatus.php`
- `app/Filament/Resources/JobOrders/Schemas/JobOrderForm.php`
- `tests/Feature/JobOrders/JobOrderSupplierRequirementTest.php`
- `tests/Feature/Filament/JobOrderResourceTest.php`

**Estimated scope:** Medium (4 files).

### Task 15: Complete immediate transactions atomically

**Description:** Add the no-remaining-work path while preserving inventory,
billing, timestamps, and physical-product dispensing eligibility.

**Acceptance criteria:**

- [ ] Immediate Service-only transactions finish without Processing, Ready, an
  inventory movement, supplier invoice, or physical Dispensing Event.
- [ ] Immediate product/mixed transactions commit catalog inventory once and
  create one Dispensing Event when a physical product is handed over.
- [ ] Prepared behavior remains unchanged and repeated confirmation stays
  idempotent.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php tests/Feature/BillingRecords/DispensingTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 12 and 14.

**Files likely touched:**

- `app/Actions/OpticalOrders/CompleteImmediateOpticalOrder.php`
- `app/Actions/OpticalOrders/AcceptAndStartOpticalOrder.php`
- `tests/Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php`
- `tests/Feature/BillingRecords/DispensingTest.php`

**Estimated scope:** Medium (4 files).

### Task 16: Add fulfillment choices to Confirm Sale

**Description:** Let staff choose Complete sale now or Prepare for pickup and
conditionally mark external supplier work in the existing confirmation modal.

**Acceptance criteria:**

- [ ] The modal defaults corrective eyewear to prepared work and hides external
  supplier input for immediate completion.
- [ ] Due date and optional deposit remain available; a positive deposit or
  first pickup payment shows the current charge summary and requires
  acknowledgement of the finalization warning.
- [ ] Filament tests prove both confirmation paths, the first-payment warning,
  and validation messages.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrderResourceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 15.

**Files likely touched:**

- `app/Filament/Resources/OpticalOrders/Pages/ViewOpticalOrder.php`
- `tests/Feature/Filament/OpticalOrderResourceTest.php`

**Estimated scope:** Small (2 files).

### Checkpoint D: Fulfillment

```text
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders tests/Feature/JobOrders tests/Feature/BillingRecords/DispensingTest.php tests/Feature/Filament/OpticalOrderResourceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] Immediate Service and physical-product sales complete correctly.
- [ ] Prepared in-house and external work follow the correct supplier rule.
- [ ] Status values remain backward compatible.

## Phase E: Encounter and Combined Checkout

### Task 17: Add Encounter charges to unified Billing

**Description:** Create or reuse the open same-checkout bill from free-text
Encounter Service rows without creating a Job Order.

**Acceptance criteria:**

- [ ] Encounter-only charges create itemized Billing Records with matching
  Patient and Encounter source.
- [ ] Encounter-first and Optical-first flows converge on one Combined bill
  while it has no posted payment.
- [ ] Posted payment or voiding forces later Encounter charges into a new bill;
  invalid input rolls back atomically.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/AddEncounterChargesToBillingTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 9-12.

**Files likely touched:**

- `app/Actions/BillingRecords/AddEncounterChargesToBilling.php`
- `tests/Feature/BillingRecords/AddEncounterChargesToBillingTest.php`

**Estimated scope:** Small (2 files).

### Task 18: Add the separate Encounter Charges section

**Description:** Place billing context outside the clinical wizard and expose
free-text service entry plus linked Optical Order comparison.

**Acceptance criteria:**

- [ ] In-progress and completed Encounters show a separate Charges section,
  current bill summary, and Add charges action.
- [ ] Linked Optical Order Service lines are visible so staff can avoid manual
  double entry.
- [ ] Completing or saving the Encounter never creates charges automatically.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterResourceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 17.

**Files likely touched:**

- `app/Filament/Resources/Encounters/Schemas/EncounterForm.php`
- `app/Filament/Resources/Encounters/Pages/EditEncounter.php`
- `tests/Feature/Filament/EncounterResourceTest.php`

**Estimated scope:** Medium (3 files).

### Checkpoint E: Encounter checkout

```text
vendor/bin/sail artisan test --compact tests/Feature/Encounters tests/Feature/BillingRecords/AddEncounterChargesToBillingTest.php tests/Feature/Filament/EncounterResourceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] Encounter-only and Combined bills work in both source orders.
- [ ] Payment locks and new-bill fallback are visible and tested.
- [ ] Clinical wizard behavior remains unchanged.

## Phase F: Financial UI and Patient Contract

### Task 19: Make Billing & Payments source-neutral and itemized

**Description:** Present Encounter, Optical Order, and Combined bills through
the existing financial workspace.

**Acceptance criteria:**

- [ ] List columns and filters show the derived source and applicable
  references without assuming a Job Order.
- [ ] Detail groups canonical Billing Record items by origin, reconciles
  subtotal, discount, total, paid, and balance, and retains due-date,
  correction, and void actions for all source contexts.
- [ ] Before the first payment, Record Payment shows the current itemized total,
  displays the charge-set finalization warning, and requires acknowledgement;
  later payments use the concise form.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/BillingRecordResourceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 13 and 17.

**Files likely touched:**

- `app/Filament/Resources/BillingRecords/Tables/BillingRecordsTable.php`
- `app/Filament/Resources/BillingRecords/Pages/ListBillingRecords.php`
- `app/Filament/Resources/BillingRecords/Pages/EditBillingRecord.php`
- `app/Filament/Resources/BillingRecords/RelationManagers/PaymentsRelationManager.php`
- `tests/Feature/Filament/BillingRecordResourceTest.php`

**Estimated scope:** Medium (5 files).

### Task 20: Keep patient eyewear details order-focused

**Description:** Preserve Optical Order items and totals in the patient eyewear
contract while identifying payment figures from a Combined bill as an overall
checkout summary.

**Acceptance criteria:**

- [ ] Item descriptions, fulfillment status, and the top-level `total_amount`
  remain scoped to the patient-owned Quotation and Job Order.
- [ ] A Combined bill adds `scope: overall_checkout`, aggregate checkout
  figures, and `other_clinic_charges_amount`; the latter equals the Encounter-
  originating item sum and reconciles the order total to the checkout total
  under the approved discount rules.
- [ ] Existing top-level fields and sections remain backward compatible;
  the aggregate exposes no Encounter descriptions, counts, origin identifiers,
  findings, histories, prescriptions, measurements, supplier data, internal
  notes, or Encounter-only bills.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Eyewear/EyewearAggregateTest.php tests/Feature/Eyewear/EyewearResourceTest.php tests/Feature/Api/EyewearApiTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 13, 17, and 19.

**Files likely touched:**

- `app/Services/Eyewear/BuildEyewearAggregate.php`
- `tests/Feature/Eyewear/EyewearAggregateTest.php`
- `tests/Feature/Eyewear/EyewearResourceTest.php`
- `tests/Feature/Api/EyewearApiTest.php`

**Estimated scope:** Medium (4 files).

## Phase G: Conformance and Handoff

### Task 21: Run the full conformance gate and update approved documentation

**Description:** Audit for stale one-bill and Job-Order-item assumptions, run
the entire verification gate, and make the explicit contracts match shipped
behavior.

**Acceptance criteria:**

- [ ] No canonical caller assumes `billing_records.job_order_id` is unique or
  renders `jobOrder.items` as Billing Record charges.
- [ ] API contract and backend context describe typed lines, fulfillment mode,
  unified checkout, order-focused patient details, overall-checkout payment
  scope, the Other clinic charges aggregate, the first-payment warning, and
  mobile privacy boundaries.
- [ ] Full migration, Pest, Pint, and frontend build gates pass; the spec,
  plan, and tasks record completion without claiming unimplemented behavior.

**Verification:**

```text
vendor/bin/sail artisan migrate --no-interaction
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
```

**Dependencies:** Tasks 1-20.

**Files likely touched:**

- `docs/API_CONTRACT.md`
- `docs/BACKEND_CONTEXT.md`
- `docs/specs/optical-transaction-types-and-encounter-billing-spec.md`
- `docs/specs/optical-transaction-types-and-encounter-billing-plan.md`
- `docs/specs/optical-transaction-types-and-encounter-billing-tasks.md`

**Estimated scope:** Medium (5 documentation files).

### Final Checkpoint

- [ ] Product-only, Service-only, and Mixed Optical Orders work.
- [ ] Encounter-only, Optical-only, and Combined Billing Records work.
- [ ] Immediate and prepared fulfillment paths work.
- [ ] External supplier invoice is conditionally enforced.
- [ ] Posted payments lock charge sets; voided bills can be reissued safely.
- [ ] Staff must acknowledge the finalization warning before every first
  payment or deposit entry point.
- [ ] Staff totals reconcile to canonical Billing items; patient responses keep
  the Optical Order total separate and clearly scope a Combined balance to the
  overall checkout with a reconciled Other clinic charges aggregate.
- [ ] No clinical or supplier data leaks through the patient API.
- [ ] All focused and full verification commands pass.

## Phase 3 Approval Gate

Approval of this document authorizes Phase 4 (**Implement**) to execute these
tasks sequentially with their tests and checkpoints. Any material schema,
workflow, or API decision discovered during implementation will update the
approved specification first and return to the appropriate approval gate.
