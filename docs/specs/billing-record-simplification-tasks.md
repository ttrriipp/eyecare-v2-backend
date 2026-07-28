# Tasks: Internal Billing Record Simplification

## Status

Phase 3 draft for project-owner review on 2026-07-28. Begin only after the
Frame Reservation track is complete and committed. Use test-driven and
incremental implementation for every task.

Source documents:

- `docs/specs/billing-record-simplification-spec.md`
- `docs/specs/billing-record-simplification-plan.md`

## Progress

- [ ] B1 — Replace the canonical ledger schema and status model
- [ ] B2 — Establish Billing Record relationships, policy, and factories
- [ ] B3 — Implement payment recording and balance derivation
- [ ] B4 — Implement payment correction and Billing Record voiding
- [ ] B5 — Replace invoice issuance in the dispensing transaction
- [ ] B6 — Add the clinic dispensing workflow
- [ ] B7 — Build the Billing Record list and navigation
- [ ] B8 — Build Billing Record detail and financial actions
- [ ] B9 — Replace the patient Invoice API
- [ ] B10 — Reconcile reporting and seeded workflow data
- [ ] B11 — Remove obsolete Invoice domain classes
- [ ] B12 — Remove obsolete Invoice factories
- [ ] B13 — Remove obsolete Invoice Filament resource
- [ ] B14 — Retire superseded Invoice domain tests
- [ ] B15 — Retire superseded Invoice surface tests
- [ ] B16 — Reconcile documentation and run final regression

## B1 — Replace the canonical ledger schema and status model

**Description:** Replace Invoice tables with the minimal Billing Record and
Billing Payment schema, remove copied items, and introduce the approved
statuses and number format.

**Acceptance criteria:**

- [ ] Canonical schema contains only approved Billing Record/payment fields.
- [ ] `job_order_id` is unique and required; `invoice_items` no longer exists.
- [ ] Billing Record status supports only unpaid, partially paid, paid, voided.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/BillingRecordLedgerTest.php`

**Dependencies:** Reservation completion checkpoint.

**Files likely touched:**

- `database/migrations/2026_07_26_020000_create_invoice_tables.php`
- `app/Enums/BillingRecordStatus.php`
- `app/Models/BillingRecord.php`
- `app/Models/BillingPayment.php`
- `tests/Feature/BillingRecords/BillingRecordLedgerTest.php`

**Estimated scope:** Medium.

## B2 — Establish Billing Record relationships, policy, and factories

**Description:** Connect Patient, Job Order, Encounter, dispensing event, actor,
and payment history using the renamed domain and valid factories.

**Acceptance criteria:**

- [ ] Relationships navigate the ledger without Invoice aliases.
- [ ] Factories derive patient, encounter, and total from a valid Job Order.
- [ ] Existing staff/admin authorization rules are preserved under Billing
  Record terminology.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/BillingRecordRelationshipsTest.php`

**Dependencies:** B1.

**Files likely touched:**

- `database/factories/BillingRecordFactory.php`
- `database/factories/BillingPaymentFactory.php`
- `app/Policies/BillingRecordPolicy.php`
- `app/Models/JobOrder.php`
- `tests/Feature/BillingRecords/BillingRecordRelationshipsTest.php`

**Estimated scope:** Medium.

## B3 — Implement payment recording and balance derivation

**Description:** Replace invoice payment recording with a locked,
transactional Billing Record ledger operation.

**Acceptance criteria:**

- [ ] Positive posted payments derive amount paid, balance due, and status.
- [ ] Overpayment, zero/negative amounts, and voided records are rejected.
- [ ] Approved methods are cash, GCash, bank transfer, and card.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/PaymentLifecycleTest.php --filter=record`

**Dependencies:** B2.

**Files likely touched:**

- `app/Actions/BillingRecords/RecordBillingPayment.php`
- `app/Actions/Invoices/RecordInvoicePayment.php` (removed)
- `app/Models/BillingRecord.php`
- `app/Enums/PaymentMethod.php`
- `tests/Feature/BillingRecords/PaymentLifecycleTest.php`

**Estimated scope:** Medium.

## B4 — Implement payment correction and Billing Record voiding

**Description:** Preserve financial integrity using explicit payment reversal,
optional replacement, and record void actions.

**Acceptance criteria:**

- [ ] Correction preserves the original entry and attributes reversal and
  replacement.
- [ ] Voiding requires an admin actor and reason and blocks future payments.
- [ ] Each operation is atomic and audited.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/PaymentLifecycleTest.php`

**Dependencies:** B3.

**Files likely touched:**

- `app/Actions/BillingRecords/CorrectBillingPayment.php`
- `app/Actions/BillingRecords/VoidBillingRecord.php`
- `app/Actions/Invoices/CorrectInvoicePayment.php` (removed)
- `app/Enums/AuditEvent.php`
- `tests/Feature/BillingRecords/PaymentLifecycleTest.php`

**Estimated scope:** Medium.

## Checkpoint B — Ledger integrity

- [ ] B1–B4 focused suites pass together.
- [ ] No physical invoice/BIR field exists in the canonical schema.
- [ ] Ledger concurrency and correction rules are proven before dispensing.

## B5 — Replace invoice issuance in the dispensing transaction

**Description:** Create one Billing Record at dispensing and link the
dispensing event without draft, issued, or official-number behavior.

**Acceptance criteria:**

- [ ] Dispensing atomically updates the Job Order and creates one event and one
  Billing Record.
- [ ] Optional initial payment participates in the same transaction.
- [ ] Repeated/concurrent dispensing cannot create duplicate records.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/DispensingTest.php`

**Dependencies:** B4.

**Files likely touched:**

- `app/Actions/BillingRecords/DispenseJobOrder.php`
- `app/Actions/Invoices/DispenseJobOrder.php` (removed)
- `app/Models/DispensingEvent.php`
- `database/factories/DispensingEventFactory.php`
- `tests/Feature/BillingRecords/DispensingTest.php`

**Estimated scope:** Medium.

## B6 — Add the clinic dispensing workflow

**Description:** Add the ready-for-dispensing action to the Job Order page with
recipient, notes, and optional initial payment inputs.

**Acceptance criteria:**

- [ ] The action is visible only for ready Job Orders and authorized users.
- [ ] No official invoice number or BIR field is requested.
- [ ] Success refreshes the Job Order and exposes its Billing Record link.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/JobOrderDispensingTest.php`

**Dependencies:** B5.

**Files likely touched:**

- `app/Filament/Resources/JobOrders/Pages/EditJobOrder.php`
- `app/Filament/Resources/JobOrders/Schemas/JobOrderForm.php`
- `tests/Feature/Filament/JobOrderDispensingTest.php`

**Estimated scope:** Medium.

## B7 — Build the Billing Record list and navigation

**Description:** Replace Invoice navigation with a searchable, filterable
Billing Record list.

**Acceptance criteria:**

- [ ] Navigation and table use Billing Record language exclusively.
- [ ] Rows show internal number, patient, Job Order, status, total, and balance.
- [ ] Status filters and record navigation work.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/BillingRecordResourceTest.php --filter=list`

**Dependencies:** B6.

**Files likely touched:**

- `app/Filament/Resources/BillingRecords/BillingRecordResource.php`
- `app/Filament/Resources/BillingRecords/Pages/ListBillingRecords.php`
- `app/Filament/Resources/BillingRecords/Tables/BillingRecordsTable.php`
- `tests/Feature/Filament/BillingRecordResourceTest.php`

**Estimated scope:** Medium.

## B8 — Build Billing Record detail and financial actions

**Description:** Provide the operational ledger view with payment history and
authorized record, correction, and void actions.

**Acceptance criteria:**

- [ ] Detail shows Job Order linkage, totals, balance, status, and posted/reversed
  payment history.
- [ ] Staff can record payments; only admin can correct or void.
- [ ] No copied item table, official invoice field, tax section, or print action
  appears.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/BillingRecordResourceTest.php`

**Dependencies:** B7.

**Files likely touched:**

- `app/Filament/Resources/BillingRecords/Pages/EditBillingRecord.php`
- `app/Filament/Resources/BillingRecords/Schemas/BillingRecordForm.php`
- `tests/Feature/Filament/BillingRecordResourceTest.php`

**Estimated scope:** Medium.

## B9 — Replace the patient Invoice API

**Description:** Replace the two read-only Invoice endpoints with
ownership-scoped Billing Record resources and posted payment history.

**Acceptance criteria:**

- [ ] `/billing-records` list/show are paginated, authenticated, and
  patient-scoped.
- [ ] `/invoices` routes no longer exist.
- [ ] Responses omit internal notes, staff identities, correction reasons, and
  audit metadata.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/BillingRecordTest.php`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php`

**Dependencies:** B8.

**Files likely touched:**

- `app/Http/Controllers/Api/BillingRecordController.php`
- `app/Http/Resources/BillingRecordResource.php`
- `app/Http/Resources/BillingPaymentResource.php`
- `routes/api.php`
- `tests/Feature/Api/V1/BillingRecordTest.php`

**Estimated scope:** Medium.

## Checkpoint C — Complete user workflows

- [ ] Clinic dispensing and financial management work end to end.
- [ ] Patient Billing Record reads are stable and privacy-safe.
- [ ] Route-contract changes are intentional and tested.

## B10 — Reconcile reporting and seeded workflow data

**Description:** Move operational summaries and representative development data
onto Billing Payments and Billing Records.

**Acceptance criteria:**

- [ ] Revenue uses posted Billing Payments only.
- [ ] Seed data includes unpaid, partially paid, paid, and voided examples.
- [ ] No seeded physical invoice number, tax replica, or copied item exists.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Seeders`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Console/SendDailySummaryCommandTest.php`

**Dependencies:** B9.

**Files likely touched:**

- `app/Console/Commands/SendDailySummaryCommand.php`
- `database/seeders/ClinicWorkflowSeeder.php`
- `database/seeders/DashboardDemoSeeder.php`
- `tests/Feature/Seeders/ClinicWorkflowSeederTest.php`
- `tests/Feature/Console/SendDailySummaryCommandTest.php`

**Estimated scope:** Medium.

## B11 — Remove obsolete Invoice domain classes

**Description:** Remove the old Invoice model boundary after all runtime
consumers use Billing Records.

**Acceptance criteria:**

- [ ] Old Invoice models, status enum, and policy no longer exist.
- [ ] Application bootstrap and policy discovery resolve Billing Record classes.
- [ ] Focused Billing Record suites still pass without aliases.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/BillingRecords`

**Dependencies:** B10.

**Files likely touched:**

- `app/Models/Invoice.php` (removed)
- `app/Models/InvoiceItem.php` (removed)
- `app/Models/InvoicePayment.php` (removed)
- `app/Enums/InvoiceStatus.php` (removed)
- `app/Policies/InvoicePolicy.php` (removed)

**Estimated scope:** Small, deletion-only.

## B12 — Remove obsolete Invoice factories

**Description:** Remove factory aliases and ensure all tests and seeders build
only valid Job Order-backed Billing Records.

**Acceptance criteria:**

- [ ] No Invoice, Invoice Item, or Invoice Payment factory remains.
- [ ] Canonical and workflow seeders execute using Billing Record factories or
  actions.
- [ ] Seeder suites pass from a clean database.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Seeders`

**Dependencies:** B11.

**Files likely touched:**

- `database/factories/InvoiceFactory.php` (removed)
- `database/factories/InvoiceItemFactory.php` (removed)
- `database/factories/InvoicePaymentFactory.php` (removed)

**Estimated scope:** Small, deletion-only.

## B13 — Remove obsolete Invoice Filament resource

**Description:** Delete the replaced Invoice resource after Billing Record list
and detail pages pass.

**Acceptance criteria:**

- [ ] Only Billing Records appear in financial navigation.
- [ ] No Invoice Filament namespace or page remains.
- [ ] Panel discovery and Billing Record resource tests pass.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/BillingRecordResourceTest.php`

**Dependencies:** B12.

**Files likely touched:**

- `app/Filament/Resources/Invoices/InvoiceResource.php` (removed)
- `app/Filament/Resources/Invoices/Pages/EditInvoice.php` (removed)
- `app/Filament/Resources/Invoices/Pages/ListInvoices.php` (removed)
- `app/Filament/Resources/Invoices/Schemas/InvoiceForm.php` (removed)
- `app/Filament/Resources/Invoices/Tables/InvoicesTable.php` (removed)

**Estimated scope:** Small, deletion-only.

## B14 — Retire superseded Invoice domain tests

**Description:** Remove only tests whose behavior has already been replaced by
approved Billing Record ledger, payment, and dispensing suites.

**Acceptance criteria:**

- [ ] No obsolete test asserts draft/issued, official number, copied items, or
  Invoice payment behavior.
- [ ] Equivalent Billing Record behaviors remain covered.
- [ ] All Billing Record domain suites pass after removal.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/BillingRecords`

**Dependencies:** B13.

**Files likely touched:**

- `tests/Feature/Invoices/DispensingTest.php` (removed)
- `tests/Feature/Invoices/InvoiceLedgerTest.php` (removed)
- `tests/Feature/Invoices/PaymentLifecycleTest.php` (removed)
- `tests/Feature/Invoices/PaymentMethodTest.php` (removed)

**Estimated scope:** Small, deletion-only.

## B15 — Retire superseded Invoice surface tests

**Description:** Remove replaced API/Filament tests and reconcile the end-to-end
clinic workflow with Billing Record behavior.

**Acceptance criteria:**

- [ ] No old `/invoices` or Invoice resource test remains.
- [ ] API and Filament Billing Record replacement suites pass.
- [ ] End-to-end workflow passes with Billing Record terminology.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/BillingRecordTest.php tests/Feature/Filament/BillingRecordResourceTest.php tests/Feature/EndToEnd/ClinicWorkflowTest.php`

**Dependencies:** B14.

**Files likely touched:**

- `tests/Feature/Api/V1/InvoiceTest.php` (removed)
- `tests/Feature/Filament/InvoiceResourceTest.php` (removed)
- `tests/Feature/EndToEnd/ClinicWorkflowTest.php`

**Estimated scope:** Small.

## B16 — Reconcile documentation and run final regression

**Description:** Publish the authoritative Billing Record contract, format the
implementation, run full regression, and audit every approved success
criterion.

**Acceptance criteria:**

- [ ] API/backend documentation contains no active Invoice contract.
- [ ] Pint and the complete Pest suite pass.
- [ ] Repository runtime search finds no BIR replica or obsolete Invoice
  implementation.

**Verification:**

- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `rg -n "Invoice|official_number|invoice_items|/invoices" app routes database docs tests`
- [ ] `git diff --check`

**Dependencies:** B15.

**Files likely touched:**

- `docs/API_CONTRACT.md`
- `docs/BACKEND_CONTEXT.md`
- `tests/Feature/Api/V1/RouteContractTest.php`
- `tests/Feature/Api/V1/WorkflowReadsTest.php`
- `tests/Feature/EndToEnd/ClinicWorkflowTest.php`

**Estimated scope:** Medium.

## Completion Checkpoint

- [ ] All B1–B16 checkboxes are complete.
- [ ] Billing specs, plans, tasks, code, tests, routes, seeds, and documentation
  agree.
- [ ] The final implementation is committed and its hash is recorded for the
  Android team.
