# Tasks: Separate Quotations, Optical Orders, and Service Billing

## Status

Draft for project-owner review on 2026-08-03. This is Phase 3 (**Tasks**) of the
spec-driven workflow. The Phase 1 specification and Phase 2 implementation plan
are approved. No application-code changes are authorized until this task
breakdown is approved.

## Execution Rules

- Execute tasks in dependency order. Stop when a task's focused tests fail for
  an unexplained reason; diagnose before advancing.
- Before the first code change in each Laravel or Filament area, search the
  installed-version documentation using Laravel Boost.
- Use Sail-prefixed Artisan or Filament generators with `--no-interaction` for
  new Laravel classes, migrations, tests, resources, and clusters.
- Start every behavior-changing task with a failing or characterization Pest
  test. Use existing factories and add purposeful states instead of creating
  ad hoc records repeatedly.
- Keep orchestration in typed actions. Filament callbacks call those actions
  and do not duplicate business rules.
- Run the smallest named Pest suite after each task and run
  `vendor/bin/sail bin pint --dirty --format agent` after PHP changes.
- Preserve unrelated worktree changes. Do not reset or overwrite files outside
  this commerce scope.
- Never run `migrate:fresh` until the active database is positively verified as
  local or testing.

## Phase A: Baseline and Clean Schema

### Task 1: Capture the surviving commerce safeguards

**Goal:** Characterize behavior that remains valid across the clean break,
without preserving old response shapes or mixed-item behavior.

**Work:**

- Extend characterization coverage for Quotation lifecycle, inventory
  commitment, Frame Reservation conversion, cancellation, fulfillment,
  dispensing, Billing reconciliation, and payment correction.
- Explicitly characterize the no-posted-payment charge lock and the current
  prescription rule for corrective Products.
- Record existing stale revision-resource failures separately; do not encode
  them as required behavior.

**Acceptance criteria:**

- [ ] Every safeguard retained by the approved spec has a passing pre-change
  assertion.
- [ ] No new test requires `legacy_other`, eyewear keys, revision wrappers, or
  Service Job Order items.
- [ ] Existing unrelated appointment, Encounter, patient-account, inventory,
  and Frame Rating focused tests remain green.

**Likely files:**

- `tests/Feature/OpticalOrders/LegacyOpticalOrderCharacterizationTest.php`
- `tests/Feature/BillingRecords/BillingRecordLedgerTest.php`
- `tests/Feature/JobOrders/JobOrderInventoryAtomicTest.php`
- `tests/Feature/OpticalOrders/FrameReservationJobOrderLinkTest.php`
- `tests/Feature/BillingRecords/PaymentLifecycleTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/LegacyOpticalOrderCharacterizationTest.php tests/Feature/BillingRecords/BillingRecordLedgerTest.php tests/Feature/JobOrders/JobOrderInventoryAtomicTest.php tests/Feature/OpticalOrders/FrameReservationJobOrderLinkTest.php tests/Feature/BillingRecords/PaymentLifecycleTest.php
```

**Dependencies:** None.

### Task 2: Replace the Quotation schema and item invariant

**Goal:** Make the base Quotation migration represent the final direct proposal
model with Product and Service items only.

**Work:**

- Add a new fresh-schema Pest test before editing migrations.
- Rewrite the base Quotation migration to create direct totals, presentation
  and acceptance metadata, and direct `quotation_items`.
- Remove `quotation_revisions`, eyewear keys, and `legacy_other` from the fresh
  schema.
- Reduce `TransactionItemType` to Product and Service and align Quotation item
  casts, relationships, and factory states.
- Enforce that Service items have no Product Variant or Lens Category link.

**Acceptance criteria:**

- [ ] A fresh test database creates `quotations` and `quotation_items` without
  revision or compatibility columns/tables.
- [ ] Quotation items persist only Product or Service.
- [ ] Invalid Service/catalog combinations fail validation and valid factory
  states round-trip.

**Likely files:**

- `database/migrations/2026_07_26_000000_create_quotation_tables.php`
- `app/Enums/TransactionItemType.php`
- `app/Models/Quotation.php`
- `app/Models/QuotationItem.php`
- `database/factories/QuotationItemFactory.php`
- `tests/Feature/Commerce/CommerceSchemaTest.php`
- `tests/Feature/Quotations/QuotationItemTypeTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Commerce/CommerceSchemaTest.php tests/Feature/Quotations/QuotationItemTypeTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 1.

### Task 3: Replace the Optical Order schema and Product-only invariant

**Goal:** Make the base Job Order migration the clean internal implementation
of product fulfillment.

**Work:**

- Rewrite the base Job Order migration with final Quotation, Encounter,
  Prescription, Frame Reservation, fulfillment, supplier, totals, and timestamp
  fields.
- Make `quotation_id` unique and nullable.
- Remove Job Order item type and eyewear-key columns; Job Order items are
  Product structurally.
- Align Job Order models and factories while retaining persisted status values
  and `JO-*` identifiers.

**Acceptance criteria:**

- [ ] The schema cannot store a Service discriminator on a Job Order item.
- [ ] One Quotation creates at most one Job Order; Service-only Quotations may
  have none.
- [ ] Fulfillment, supplier, prescription, Frame Reservation, and inventory
  relationships remain representable.

**Likely files:**

- `database/migrations/2026_07_26_010000_create_job_order_tables.php`
- `app/Models/JobOrder.php`
- `app/Models/JobOrderItem.php`
- `database/factories/JobOrderFactory.php`
- `database/factories/JobOrderItemFactory.php`
- `tests/Feature/Commerce/CommerceSchemaTest.php`
- `tests/Feature/OpticalOrders/OpticalOrderProductInvariantTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Commerce/CommerceSchemaTest.php tests/Feature/OpticalOrders/OpticalOrderProductInvariantTest.php tests/Feature/JobOrders/JobOrderSupplierRequirementTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 2.

### Task 4: Replace Billing schema with explicit charge provenance

**Goal:** Make the base Billing migration support Product-only, Service-only,
and Combined checkouts without fake operational records.

**Work:**

- Rewrite the base invoice/Billing migration to create final Billing Records,
  immutable Billing items, payments, nullable `quotation_id`, and source FKs.
- Add a controlled `BillingItemSourceKind` enum for Optical Order, Quotation,
  Encounter, and Direct Service.
- Add unique origin constraints for Job Order items and Quotation items within
  one Billing Record.
- Align Billing models, relationships, casts, source labels, and factories.

**Acceptance criteria:**

- [ ] Database and model tests cover every valid source-kind/type combination.
- [ ] Invalid source/type combinations are rejected by domain validation.
- [ ] Billing source labels resolve to Optical Order, Services, or Combined,
  with line-level provenance available separately.
- [ ] A Billing Record may link to a Quotation without a Job Order.

**Likely files:**

- `database/migrations/2026_07_26_020000_create_invoice_tables.php`
- `app/Enums/BillingItemSourceKind.php`
- `app/Models/BillingRecord.php`
- `app/Models/BillingRecordItem.php`
- `database/factories/BillingRecordFactory.php`
- `database/factories/BillingRecordItemFactory.php`
- `tests/Feature/BillingRecords/BillingRecordRelationshipsTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Commerce/CommerceSchemaTest.php tests/Feature/BillingRecords/BillingRecordRelationshipsTest.php tests/Feature/BillingRecords/BillingRecordFactoryTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 3.

### Task 5: Remove the superseded migration chain

**Goal:** Leave one coherent fresh-install commerce schema rather than running
old transition and cleanup migrations after the new base tables.

**Work:**

- Delete the commerce-only incremental migrations for quotation revisions,
  eyewear keys, direct aggregate fields, typed mixed Job Order items, supplier
  and Frame Reservation additions now folded into the base schema, and unified
  checkout additions now folded into Billing.
- Remove schema tests whose only purpose is proving historical backfill or
  compatibility, after the new fresh-schema tests pass.
- Confirm unrelated migrations, including Frame Rating revisions, remain.

**Acceptance criteria:**

- [ ] `migrate:fresh` creates the target schema without follow-up commerce
  mutation migrations.
- [ ] No inactive `orders`, `billings`, `billing_items`, `service_records`, or
  `quotation_revisions` table exists.
- [ ] No unrelated migration is removed.

**Likely files:**

- `database/migrations/2026_07_29_121658_add_eyewear_keys_to_quotations_and_job_orders.php`
- `database/migrations/2026_07_29_143339_add_supplier_invoice_number_to_job_orders_table.php`
- `database/migrations/2026_07_31_145618_add_frame_reservation_id_to_job_orders_table.php`
- `database/migrations/2026_08_02_204659_add_direct_fields_to_optical_order_aggregate.php`
- `database/migrations/2026_08_02_230920_remove_quotation_revision_architecture.php`
- `database/migrations/2026_08_03_130538_add_item_types_and_fulfillment_to_canonical_optical_tables.php`
- `database/migrations/2026_08_03_131025_add_itemized_unified_checkout_to_billing_records.php`
- obsolete transition/cleanup migration tests listed in the removal approval
  below

**Verification:**

```text
vendor/bin/sail artisan migrate:fresh --env=testing --no-interaction
vendor/bin/sail artisan test --compact tests/Feature/Commerce/CommerceSchemaTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 2-4. The reset command requires verified testing
configuration.

### Checkpoint A

- [ ] Fresh commerce schema has only canonical tables and constraints.
- [ ] Quotation items allow Product or Service; Job Order items are Product-only.
- [ ] Billing provenance represents all approved charge paths.
- [ ] Factories create valid target states.

## Phase B: Domain Workflows

### Task 6: Align Quotation create, edit, and lifecycle actions

**Goal:** Make proposal actions operate only on the direct clean schema.

**Work:**

- Update create and draft-edit actions to validate Product and Service items,
  calculate totals, and remove revision compatibility.
- Preserve Present, edit-after-Present returns-to-Draft, Accept, Decline, and
  Expire behavior.
- Remove acceptance behavior from generic decision actions where it would
  bypass Confirm Sale orchestration.

**Acceptance criteria:**

- [ ] Product-only, Service-only, and Mixed Quotations can be created.
- [ ] Presented edits return to Draft and require presentation again.
- [ ] Accepted Quotations are immutable through draft actions.
- [ ] Totals use validated quantity and price values without float drift.

**Likely files:**

- `app/Actions/Quotations/CreateQuotation.php`
- `app/Actions/Quotations/UpdateQuotationDraft.php`
- `app/Actions/Quotations/PresentQuotation.php`
- `app/Actions/Quotations/RecordQuotationDecision.php`
- `tests/Feature/Quotations/CreateQuotationTest.php`
- `tests/Feature/Quotations/UpdateQuotationDraftTest.php`
- `tests/Feature/Quotations/QuotationLifecycleTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Quotations/CreateQuotationTest.php tests/Feature/Quotations/UpdateQuotationDraftTest.php tests/Feature/Quotations/QuotationLifecycleTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Checkpoint A.

### Task 7: Implement source-specific Billing append actions

**Goal:** Snapshot charges idempotently while keeping operational provenance
explicit.

**Work:**

- Replace the mixed Job Order appender with a Product-only Optical Order
  appender.
- Add a selected quoted-Service appender keyed by Quotation item IDs.
- Update the open-checkout resolver to accept optional Optical Order,
  Quotation, or Encounter context and create a new bill after posted payment.
- Recalculate subtotal, one checkout discount, total, paid, and balance from
  immutable items and posted payments.

**Acceptance criteria:**

- [ ] Repeating an append creates no duplicate source item.
- [ ] Patient/source mismatches and posted-payment appends fail atomically.
- [ ] Product-only, Service-only, and Combined totals reconcile exactly.
- [ ] A selected quoted Service retains Quotation provenance and optional
  Encounter context.

**Likely files:**

- `app/Actions/BillingRecords/AppendOpticalOrderItemsToBillingRecord.php`
- `app/Actions/BillingRecords/AppendQuotedServicesToBillingRecord.php`
- `app/Actions/BillingRecords/ResolveOpenCheckoutBillingRecord.php`
- `app/Actions/BillingRecords/RecalculateBillingRecordTotals.php`
- `tests/Feature/BillingRecords/AppendChargeSourcesTest.php`
- `tests/Feature/BillingRecords/ResolveOpenCheckoutBillingRecordTest.php`
- `tests/Feature/BillingRecords/RecalculateBillingRecordTotalsTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/AppendChargeSourcesTest.php tests/Feature/BillingRecords/ResolveOpenCheckoutBillingRecordTest.php tests/Feature/BillingRecords/RecalculateBillingRecordTotalsTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 6.

### Task 8: Implement Encounter and Direct Service charging

**Goal:** Support performed Service charges without Optical Orders.

**Work:**

- Update Encounter charge entry to create `encounter` provenance items.
- Add a direct-Service action that requires Patient, descriptions, quantities,
  final prices, optional due date, and actor.
- Reuse the same charge-lock and total calculation rules.
- Show existing same-source items by stable IDs; do not use free-text matching
  as duplicate prevention.

**Acceptance criteria:**

- [ ] Encounter charges remain explicit and are never created by completing an
  Encounter.
- [ ] Direct Service charges require no fake Encounter, Quotation, or Order.
- [ ] Posted-payment Billing Records receive no appended charges.
- [ ] Invalid or empty Service lines roll back the whole transaction.

**Likely files:**

- `app/Actions/BillingRecords/AddEncounterChargesToBilling.php`
- `app/Actions/BillingRecords/AddDirectServiceChargesToBilling.php`
- `tests/Feature/BillingRecords/AddEncounterChargesToBillingTest.php`
- `tests/Feature/BillingRecords/AddDirectServiceChargesToBillingTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/AddEncounterChargesToBillingTest.php tests/Feature/BillingRecords/AddDirectServiceChargesToBillingTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 7.

### Task 9: Implement atomic Confirm Quotation Sale

**Goal:** Replace overlapping conversion flows with one safe orchestrator.

**Work:**

- Add `ConfirmQuotationSale` with explicit actor and performed-Service item IDs.
- Lock the Quotation, accept it once, copy Product lines only, and create no
  Optical Order for Service-only proposals.
- Append Order products and selected Services to the same bill, apply discount
  and due date once, then record an optional acknowledged deposit last.
- Commit inventory and convert a Frame Reservation only when a Product Order is
  created.
- Make retries and concurrent attempts idempotent through source constraints
  and row locks.

**Acceptance criteria:**

- [ ] Product-only confirmation creates one Order and one bill.
- [ ] Mixed confirmation creates a Product-only Order and bills only explicitly
  selected Services.
- [ ] Service-only confirmation creates no Order.
- [ ] Unselected quoted Services remain unbilled and may be appended later
  before first payment.
- [ ] Failure in inventory, reservation, Billing, or deposit rolls back all
  confirmation changes.
- [ ] Retrying does not duplicate any operational or financial record.

**Likely files:**

- `app/Actions/Quotations/ConfirmQuotationSale.php`
- `app/Actions/JobOrders/CommitJobOrderInventory.php`
- `app/Actions/Reservations/ConvertFrameReservationToJobOrder.php`
- `tests/Feature/Quotations/ConfirmQuotationSaleTest.php`
- `tests/Feature/Quotations/ConfirmQuotationSaleAtomicityTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Quotations/ConfirmQuotationSaleTest.php tests/Feature/Quotations/ConfirmQuotationSaleAtomicityTest.php tests/Feature/JobOrders/JobOrderInventoryAtomicTest.php tests/Feature/OpticalOrders/FrameReservationJobOrderLinkTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 7-8.

### Task 10: Implement direct Product Order and fulfillment behavior

**Goal:** Support product-only sales that do not require a prior Quotation.

**Work:**

- Replace the mixed immediate-order action with a typed direct Product Order
  action.
- Enforce current Prescription for corrective Products, while allowing valid
  non-prescription Products.
- Preserve prepared and immediate fulfillment modes, inventory commitment,
  cancellation, dispensing, and conditional supplier invoice requirements.
- Keep persisted Job Order statuses while exposing approved staff labels.

**Acceptance criteria:**

- [ ] Direct Orders reject Service rows.
- [ ] Corrective versus non-prescription Product rules are tested.
- [ ] Immediate Orders complete without artificial intermediate actions.
- [ ] Supplier invoice is required before Ready only for external work.
- [ ] Catalog inventory commits exactly once; Lens Category/custom Product
  lines create no fake inventory movement.

**Likely files:**

- `app/Actions/OpticalOrders/CreateDirectOpticalOrder.php`
- `app/Actions/OpticalOrders/CompleteImmediateOpticalOrder.php`
- `app/Actions/JobOrders/UpdateJobOrderStatus.php`
- `app/Actions/OpticalOrders/CancelOpticalOrder.php`
- `tests/Feature/OpticalOrders/CreateDirectOpticalOrderTest.php`
- `tests/Feature/OpticalOrders/CompleteImmediateOpticalOrderTest.php`
- `tests/Feature/JobOrders/JobOrderSupplierRequirementTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/CreateDirectOpticalOrderTest.php tests/Feature/OpticalOrders/CompleteImmediateOpticalOrderTest.php tests/Feature/JobOrders/JobOrderSupplierRequirementTest.php tests/Feature/JobOrders/JobOrderInventoryTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 9.

### Task 11: Enforce first-payment charge review

**Goal:** Make the first posted payment the explicit charge-set lock.

**Work:**

- Require a `chargesReviewed` acknowledgement for the first payment/deposit in
  the payment action.
- Return a reusable current charge summary for confirmation and Billing UI.
- Preserve later payment, correction, reversal, void-and-reissue, due-date, and
  balance behavior.

**Acceptance criteria:**

- [ ] The first payment fails without acknowledgement and changes no data.
- [ ] The first acknowledged payment locks further charges.
- [ ] Later payments do not repeat the first-payment acknowledgement.
- [ ] Confirmation deposits use the same rule rather than a bypass.

**Likely files:**

- `app/Actions/BillingRecords/RecordBillingPayment.php`
- `app/Actions/BillingRecords/BuildBillingChargeSummary.php`
- `tests/Feature/BillingRecords/PaymentLifecycleTest.php`
- `tests/Feature/Quotations/ConfirmQuotationSaleTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/PaymentLifecycleTest.php tests/Feature/BillingRecords/BillingRecordLedgerTest.php tests/Feature/Quotations/ConfirmQuotationSaleTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 9-10.

### Checkpoint B

- [ ] Every approved transaction path works through domain actions.
- [ ] No Service can enter Job Order items.
- [ ] Confirmation and direct Order paths are atomic and idempotent.
- [ ] First payment finalizes the charge set explicitly.

## Phase C: Filament Admin Experience

### Task 12: Create the Optical Orders cluster and consolidate resources

**Goal:** Present one sidebar destination with separate model-backed sections.

**Work:**

- Generate a Filament cluster using the installed v5 command and enable cluster
  discovery/configuration in the Admin panel.
- Move/consolidate the JobOrder-backed resource into **Orders** and attach the
  Quotation resource to the same cluster.
- Use top sub-navigation with Orders first.
- Remove the quotation-backed aggregate tabs and stale revision eager loads.

**Acceptance criteria:**

- [ ] One **Optical Orders** sidebar item opens Orders by default.
- [ ] Orders queries `JobOrder` only; Quotations queries `Quotation` only.
- [ ] Both sections are reachable as top tabs and respect existing staff
  authorization.
- [ ] No hidden duplicate Job Order resource remains registered.

**Likely files:**

- `app/Filament/Clusters/OpticalOrders.php`
- `app/Providers/Filament/AdminPanelProvider.php`
- `app/Filament/Resources/OpticalOrders/OpticalOrderResource.php`
- `app/Filament/Resources/Quotations/QuotationResource.php`
- `app/Filament/Resources/OpticalOrders/Pages/ListOpticalOrders.php`
- `tests/Feature/Filament/OpticalOrdersWorkspaceTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrdersWorkspaceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Checkpoint B.

### Task 13: Build the Orders section and direct Order form

**Goal:** Give staff a product-fulfillment view without proposal statuses or
Service entry.

**Work:**

- Reuse and repair the existing Job Order table/form/page components under the
  Optical Order resource.
- Show Order number, Patient, Product summary, status, source Quotation,
  balance, due date, and relevant time.
- Add Product-only direct Order creation and approved status actions.
- Group details into Product items, fulfillment, linked records, and Billing.

**Acceptance criteria:**

- [ ] Filters are Confirmed, Processing, Ready for Pickup, Completed, and
  Cancelled only.
- [ ] Draft or Presented Quotations never appear.
- [ ] The form cannot submit Service rows.
- [ ] Supplier and immediate/prepared fields are conditional and domain-backed.
- [ ] Links to Quotation, Encounter, Prescription, Frame Reservation, and bill
  appear only when present.

**Likely files:**

- `app/Filament/Resources/OpticalOrders/Schemas/OpticalOrderForm.php`
- `app/Filament/Resources/OpticalOrders/Tables/OpticalOrdersTable.php`
- `app/Filament/Resources/OpticalOrders/Pages/CreateOpticalOrder.php`
- `app/Filament/Resources/OpticalOrders/Pages/EditOpticalOrder.php`
- `app/Filament/Resources/OpticalOrders/Pages/ViewOpticalOrder.php`
- `tests/Feature/Filament/OpticalOrderResourceTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrdersWorkspaceTest.php tests/Feature/Filament/OpticalOrderResourceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 12.

### Task 14: Repair the Quotations section and Confirm Sale UX

**Goal:** Make Quotations a clear proposal workspace with explicit conversion.

**Work:**

- Replace stale revision forms/tables with direct Quotation fields and items.
- Provide New Quotation, Present, edit, Decline, and Confirm Sale actions based
  on lifecycle state.
- Build Confirm Sale groups for Products to order, unchecked performed-Service
  checkboxes, checkout totals, due date, and optional deposit.
- Show billed versus not-billed quoted Services by stable source relationship.

**Acceptance criteria:**

- [ ] Filters are Draft, Presented, Accepted, Declined, and Expired.
- [ ] Product and Service proposal lines are visually distinct.
- [ ] Service checkboxes are never selected by acceptance alone.
- [ ] Service-only confirmation clearly states that no Optical Order will be
  created.
- [ ] Accepted records link to their Order when present and to their bill.

**Likely files:**

- `app/Filament/Resources/Quotations/Schemas/QuotationCreationForm.php`
- `app/Filament/Resources/Quotations/Schemas/QuotationForm.php`
- `app/Filament/Resources/Quotations/Tables/QuotationsTable.php`
- `app/Filament/Resources/Quotations/Pages/ListQuotations.php`
- `app/Filament/Resources/Quotations/Pages/EditQuotation.php`
- `tests/Feature/Filament/QuotationResourceTest.php`
- `tests/Feature/Filament/QuotationCreationTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/QuotationResourceTest.php tests/Feature/Filament/QuotationCreationTest.php tests/Feature/Filament/OpticalOrdersWorkspaceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 12-13.

### Task 15: Update Billing and Encounter charge panels

**Goal:** Make financial source and charge state understandable from the admin
interface.

**Work:**

- Render `BillingRecord.items`, grouped into Products and Services with
  Quotation/Encounter/Direct provenance.
- Add **New service charge** from the Billing list for immediate Direct Service
  checkout.
- Update the Encounter Billing section to show existing quoted/ordered/charged
  items and add Encounter services explicitly.
- Add the reviewed-charge summary and acknowledgement to first-payment and
  confirmation-deposit modals.

**Acceptance criteria:**

- [ ] Billing shows Optical Order, Services, or Combined correctly.
- [ ] Staff can enter Service-only direct checkout without an Optical Order.
- [ ] Encounter billing does not inspect Service rows from Job Order items.
- [ ] Already charged quoted Services are evident before another append.
- [ ] First payment cannot submit without the acknowledgement.

**Likely files:**

- `app/Filament/Resources/BillingRecords/Tables/BillingRecordsTable.php`
- `app/Filament/Resources/BillingRecords/Pages/ListBillingRecords.php`
- `app/Filament/Resources/BillingRecords/Pages/EditBillingRecord.php`
- `app/Filament/Resources/BillingRecords/RelationManagers/PaymentsRelationManager.php`
- `app/Filament/Resources/Encounters/Schemas/EncounterForm.php`
- `tests/Feature/Filament/BillingRecordResourceTest.php`
- `tests/Feature/Filament/EncounterResourceTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/BillingRecordResourceTest.php tests/Feature/Filament/EncounterResourceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 14 and 11.

### Checkpoint C

- [ ] Staff sees proposals and fulfillment as separate sections.
- [ ] All write actions delegate to tested domain actions.
- [ ] Direct Product, quoted sale, Encounter Service, and Direct Service entry
  are usable without duplicate or fake records.
- [ ] Payment-lock consequences are visible before the first payment.

## Phase D: Patient API Replacement

### Task 16: Replace Quotation and Optical Order read contracts

**Goal:** Publish patient-safe, active-link-scoped proposal and fulfillment
resources.

**Work:**

- Replace the revision-shaped Quotation resource with direct fields and typed
  proposal items.
- Generate an Optical Order controller and API Resource around `JobOrder`.
- Add canonical list/show routes and consistent paginated envelopes.
- Scope all queries to the authenticated account's active Patient link and hide
  Draft Quotations.
- Add Order-focused payment summary including aggregate other clinic charges.

**Acceptance criteria:**

- [ ] Quotation responses show presented Product and Service proposals and an
  optional Optical Order reference.
- [ ] Optical Order responses contain Product items only and optional source
  Quotation reference.
- [ ] Combined checkout totals reconcile without exposing Service descriptions,
  clinical data, supplier invoice, internal notes, or staff identities.
- [ ] Cross-patient and Draft access returns 404.

**Likely files:**

- `app/Http/Controllers/Api/QuotationController.php`
- `app/Http/Controllers/Api/OpticalOrderController.php`
- `app/Http/Resources/QuotationResource.php`
- `app/Http/Resources/OpticalOrderResource.php`
- `routes/api.php`
- `tests/Feature/Api/V1/QuotationTest.php`
- `tests/Feature/Api/V1/OpticalOrderTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/QuotationTest.php tests/Feature/Api/V1/OpticalOrderTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Checkpoint C.

### Task 17: Rename and preserve Frame Rating behavior

**Goal:** Align the rating endpoint with public Optical Order terminology while
preserving its authorization and eligibility rules.

**Work:**

- Rename the route to `/api/v1/optical-order-items/{item}/rating`.
- Keep active-link ownership, Product Variant matching, dispensing-event, and
  completed-Order validation.
- Ensure no old Job Order route remains.

**Acceptance criteria:**

- [ ] A linked patient can rate an eligible dispensed Product once under the
  existing revision/moderation rules.
- [ ] Unauthenticated, cross-patient, mismatched, and non-completed attempts
  fail as before.
- [ ] The old endpoint returns 404.

**Likely files:**

- `routes/api.php`
- `app/Http/Controllers/Api/FrameRatingController.php`
- `app/Actions/Ratings/SaveFrameRating.php`
- `tests/Feature/Api/V1/FrameRatingTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameRatingTest.php tests/Feature/Ratings/VerifiedFrameRatingTest.php tests/Feature/Ratings/FrameRatingModerationTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 16.

### Task 18: Remove unreleased compatibility APIs and aggregate code

**Goal:** Complete the clean mobile contract without carrying dual read models.

**Work:**

- Remove `/job-orders`, `/eyewear`, and patient `/billing-records` routes.
- Delete their controllers, Eyewear services/resources/request, compatibility
  enums, identifiers, and aliases after replacement API tests pass.
- Add explicit 404 assertions for removed routes.

**Acceptance criteria:**

- [ ] Only canonical Quotation and Optical Order commerce read routes remain.
- [ ] Repository search finds no live `eyw_*`, `jo_{id}` alias, or Eyewear
  aggregate reference.
- [ ] Removed endpoints return 404 rather than redirecting or adapting.
- [ ] Admin Billing resources remain unaffected by removing patient Billing
  endpoints.

**Likely files:**

- `routes/api.php`
- `app/Http/Controllers/Api/JobOrderController.php`
- `app/Http/Controllers/Api/EyewearController.php`
- `app/Http/Controllers/Api/BillingRecordController.php`
- `app/Services/Eyewear/`
- `app/Http/Resources/EyewearDetailResource.php`
- `app/Http/Resources/EyewearSummaryResource.php`
- `app/Http/Requests/Api/ListEyewearRequest.php`
- replacement/removal tests listed below

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/QuotationTest.php tests/Feature/Api/V1/OpticalOrderTest.php tests/Feature/Api/V1/FrameRatingTest.php
vendor/bin/sail artisan route:list --path=api/v1 --except-vendor
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 16-17.

### Checkpoint D

- [ ] Mobile API distinguishes proposals from Product fulfillment.
- [ ] Ownership and privacy rules are resource-tested.
- [ ] No compatibility API or combined Eyewear aggregate remains.

## Phase E: Cleanup, Seed, and Verification

### Task 19: Remove superseded application code and tests

**Goal:** Leave one implementation for each approved workflow after all
replacement consumers pass.

**Work:**

- Remove the old quotation-to-mixed-Job-Order actions, duplicate Job Order
  resource components, stale revision relationships, and inactive commerce
  models/code.
- Remove obsolete tests only where replacement tests cover the intended target
  behavior.
- Run repository searches for forbidden concepts and repair any legitimate
  consumer still using them.

**Acceptance criteria:**

- [ ] No live code references `QuotationRevision`, `latestRevision`,
  `quotationRevision`, `legacy_other`, eyewear keys, or Service Job Order items.
- [ ] No duplicate conversion or admin resource remains.
- [ ] Test count reduction is limited to compatibility assertions explicitly
  approved below; target behavior coverage is present first.

**Likely files:**

- `app/Actions/OpticalOrders/AcceptAndStartOpticalOrder.php`
- `app/Actions/JobOrders/CreateJobOrder.php`
- `app/Actions/BillingRecords/AppendJobOrderItemsToBillingRecord.php`
- `app/Filament/Resources/JobOrders/`
- stale revision-era resource components and tests

**Verification:**

```text
rg -n "QuotationRevision|latestRevision|quotationRevision|legacy_other|eyewear_key|EyewearAggregate" app database routes tests
vendor/bin/sail artisan test --compact --filter=Quotation
vendor/bin/sail artisan test --compact --filter=OpticalOrder
vendor/bin/sail artisan test --compact --filter=BillingRecord
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Checkpoints C-D.

### Task 20: Align seed data with real clinic examples

**Goal:** Make a fresh local install demonstrate each approved transaction
without legacy fixtures.

**Work:**

- Update commerce factories and demo seeders for a Presented mixed Quotation, a
  confirmed Product Order, a Service-only accepted Quotation, a Combined bill,
  a Direct Product Order, and an Encounter Service charge.
- Ensure Patient, Encounter, Prescription, inventory, and Billing ownership
  agree in every seeded graph.

**Acceptance criteria:**

- [ ] `migrate:fresh --seed` completes in a verified local/testing environment.
- [ ] Seeded Orders contain Products only.
- [ ] Seeded Billing totals and balances reconcile.
- [ ] Filament pages load all seeded examples without query or enum errors.

**Likely files:**

- `database/seeders/ClinicWorkflowSeeder.php`
- `database/seeders/DashboardDemoSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- commerce factories as required
- `tests/Feature/Commerce/CommerceSeederTest.php`

**Verification:**

```text
vendor/bin/sail artisan migrate:fresh --env=testing --seed --no-interaction
vendor/bin/sail artisan test --compact tests/Feature/Commerce/CommerceSeederTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 19.

### Task 21: Update current system documentation

**Goal:** Make the repository describe the implemented contracts and mark the
conflicting workflow documents as superseded.

**Work:**

- Update `BACKEND_CONTEXT.md` and `API_CONTRACT.md` with canonical staff and
  mobile terminology, routes, privacy fields, and workflow boundaries.
- Mark the conflicting optical transaction, single aggregate, and simplified
  workflow specs/plans/tasks as superseded by this approved series.
- Record final implementation status in this spec/plan/tasks set.

**Acceptance criteria:**

- [ ] Documentation matches `route:list`, final schema, and staff UI.
- [ ] No current document tells implementers to put Services in Job Orders or
  use removed APIs.
- [ ] Historical documents remain available but are clearly non-executable.

**Likely files:**

- `docs/BACKEND_CONTEXT.md`
- `docs/API_CONTRACT.md`
- superseded files under `docs/specs/`
- this specification, plan, and task breakdown

**Verification:**

```text
vendor/bin/sail artisan route:list --except-vendor
rg -n "job-orders|eyewear|billing-records|legacy_other|Service.*Job Order" docs/BACKEND_CONTEXT.md docs/API_CONTRACT.md docs/specs
```

**Dependencies:** Task 20.

### Task 22: Run final quality and clean-install gates

**Goal:** Prove the repository works from a fresh database and production asset
build.

**Work:**

- Verify the active environment is local/testing.
- Run fresh migration and seed, focused regression suites, full Pest, Pint, and
  production frontend build.
- Inspect final routes and repository status; report any intentionally retained
  historical references.

**Acceptance criteria:**

- [ ] Fresh migration and seed pass.
- [ ] Full Pest suite passes.
- [ ] Pint produces no remaining formatting changes.
- [ ] Frontend production build passes.
- [ ] Route list contains only canonical patient commerce endpoints.
- [ ] No unexplained warnings, stale files, or unrelated modifications remain.

**Verification:**

```text
vendor/bin/sail artisan migrate:fresh --seed --no-interaction
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
vendor/bin/sail artisan route:list --except-vendor
git status --short
```

**Dependencies:** Task 21. The reset command requires verified local/testing
configuration.

## Explicit Test-Removal Approval Requested

Approval of this task breakdown also approves removing the following obsolete
tests **only after** their replacement target tests pass:

- `tests/Feature/Eyewear/` and `tests/Feature/Api/EyewearApiTest.php`;
- `tests/Feature/Api/V1/JobOrderTest.php`, replaced by
  `tests/Feature/Api/V1/OpticalOrderTest.php`;
- revision/backfill/compatibility-only assertions in
  `OpticalOrderTransitionMigrationTest.php`,
  `OpticalOrderCleanupMigrationTest.php`, and
  `OpticalTransactionSchemaMigrationTest.php`, replaced by
  `CommerceSchemaTest.php`;
- `CreateJobOrderTest.php` and `AcceptAndStartOpticalOrderTest.php`, replaced by
  `ConfirmQuotationSaleTest.php`, its atomicity suite, and direct Order tests;
- stale Filament revision-resource assertions replaced by the Orders workspace
  and direct Quotation resource tests.

Tests for inventory, reservation, supplier, payment, fulfillment, rating,
authorization, Encounter, and unrelated application behavior are retained and
updated rather than deleted.

## Phase 3 Approval Gate

Project-owner approval authorizes Phase 4 (**Implement**) to execute Tasks 1-22
in order, including the clean schema replacement and conditional obsolete-test
removals above. It does not authorize resetting any unidentified or non-local
database, adding a Services catalog, renaming persisted Job Order tables/status
values, or expanding into retained-work fulfillment.
