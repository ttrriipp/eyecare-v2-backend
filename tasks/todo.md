# Task Checklist: Practical Optical Commerce and Dispensing

**Status:** Complete
**Specification:** `docs/specs/optical-commerce-and-dispensing-spec.md`
**Plan:** `tasks/plan.md`
**Specification approved:** 2026-08-10
**Plan approved:** 2026-08-10
**Tasks approved:** 2026-08-10
**Implementation:** Complete (2026-08-10)

## Execution Rules

- Implement tasks in dependency order unless this checklist explicitly marks
  work as independent.
- Do not begin a task until its dependencies and preceding checkpoint pass.
- Use Laravel Boost `search-docs` before every Laravel, Filament, Livewire, or
  Pest implementation change.
- Use Sail-prefixed Artisan generators with `--no-interaction` for new Laravel
  files where an appropriate generator exists.
- Write or update the focused Pest test before changing behavior.
- Run the listed focused verification before checking off a task.
- Run `vendor/bin/sail bin pint --dirty --format agent` after PHP changes.
- Do not add a dependency, public route, top-level directory, deferred feature,
  or Prescription-field interpretation without returning to the project owner.
- No task may silently change the clinic-facing term **Optical Order** back to
  Job Order.

## Phase 1: Characterize the Existing Boundaries

## Task 1: Characterize Quotation confirmation

**Description:** Protect the currently working direct and presented Quotation
paths before changing item semantics or confirmation validation.

**Acceptance criteria:**

- [ ] Direct Draft confirmation and Presented confirmation each create at most
  one accepted Quotation, Optical Order, and Billing Record.
- [ ] Product lines enter the Optical Order while only explicitly selected
  performed Services enter Billing.
- [ ] Retried confirmation creates no duplicate order, billing item, payment,
  inventory movement, or reservation conversion.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations/QuotationLifecycleTest.php tests/Feature/Quotations/CreateQuotationTest.php tests/Feature/OpticalOrders/FrameReservationJobOrderLinkTest.php`

**Dependencies:** None

**Files likely touched:**

- `tests/Feature/Quotations/QuotationLifecycleTest.php`
- `tests/Feature/Quotations/CreateQuotationTest.php`
- `tests/Feature/OpticalOrders/FrameReservationJobOrderLinkTest.php`

**Estimated scope:** Medium

## Task 2: Characterize valid payment and dispensing behavior

**Description:** Protect deposits, partial payments, corrections, charge-set
locking, and valid Ready-to-Completed behavior before tightening their
invariants.

**Acceptance criteria:**

- [ ] Valid deposits, later partial payments, and exact-balance payments retain
  their current ledger and status behavior.
- [ ] First payment continues locking the Billing charge set.
- [ ] Payment correction remains append-only and successful valid dispensing
  records exactly one Dispensing Event.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/PaymentLifecycleTest.php tests/Feature/BillingRecords/BillingRecordLedgerTest.php tests/Feature/BillingRecords/DispensingTest.php`

**Dependencies:** None

**Files likely touched:**

- `tests/Feature/BillingRecords/PaymentLifecycleTest.php`
- `tests/Feature/BillingRecords/BillingRecordLedgerTest.php`
- `tests/Feature/BillingRecords/DispensingTest.php`

**Estimated scope:** Medium

## Task 3: Characterize inventory and patient privacy

**Description:** Protect aggregate stock, reservation conversion, cancellation
reversal, and the existing patient-safe commercial resources.

**Acceptance criteria:**

- [ ] Order commitment and cancellation remain quantity-safe and idempotent.
- [ ] Converted Frame Reservations do not commit the selected frame twice.
- [ ] Patient resources expose current commercial information while excluding
  supplier and internal notes.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Inventory/InventoryLedgerTest.php tests/Feature/Reservations/ConvertFrameReservationToJobOrderTest.php tests/Feature/Api/V1/WorkflowReadsTest.php`

**Dependencies:** None

**Files likely touched:**

- `tests/Feature/Inventory/InventoryLedgerTest.php`
- `tests/Feature/Reservations/ConvertFrameReservationToJobOrderTest.php`
- `tests/Feature/Api/V1/WorkflowReadsTest.php`

**Estimated scope:** Medium

## Checkpoint A: Existing behavior baseline

- [ ] Tasks 1–3 focused suites pass without intentional production behavior
  changes.
- [ ] No current test was deleted merely because later behavior will change.
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations tests/Feature/OpticalOrders tests/Feature/BillingRecords tests/Feature/Inventory tests/Feature/Reservations` passes.

## Phase 2: Establish Optical Item Contracts

## Task 4: Persist commercial item kinds

**Description:** Add the stable item-kind enum and additive Quotation/Job Order
item columns with a deterministic historical backfill.

**Acceptance criteria:**

- [ ] The enum contains exactly frame, lens package, lens option, contact lens,
  accessory, custom product, and service kinds.
- [ ] Existing controlled foreign keys backfill deterministically; ambiguous
  Product lines become custom product without parsing descriptions.
- [ ] Fresh and upgraded schemas expose nullable snapshot storage and required
  item-kind storage without removing existing columns.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Commerce/OpticalItemKindSchemaTest.php tests/Feature/Database/CanonicalSchemaTest.php`

**Dependencies:** Task 1

**Files likely touched:**

- `app/Enums/CommercialItemKind.php`
- `database/migrations/*_add_item_kinds_and_snapshots_to_commerce_items.php`
- `tests/Feature/Commerce/OpticalItemKindSchemaTest.php`
- `tests/Feature/Database/CanonicalSchemaTest.php`

**Estimated scope:** Medium

## Task 5: Cast item kinds and snapshots

**Description:** Teach transaction item models and factories to persist the
new enum and JSON snapshots without weakening the Product/Service invariant.

**Acceptance criteria:**

- [ ] Quotation and Job Order items cast item kind to the enum and snapshots to
  arrays.
- [ ] Job Order items remain Product-only and Service items reject Product
  references.
- [ ] Factory states produce valid representative kinds and snapshots.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations/QuotationItemTypeTest.php tests/Feature/OpticalOrders/TransactionItemTypeTest.php tests/Feature/Commerce/OpticalItemKindSchemaTest.php`

**Dependencies:** Task 4

**Files likely touched:**

- `app/Models/QuotationItem.php`
- `app/Models/JobOrderItem.php`
- `database/factories/QuotationItemFactory.php`
- `database/factories/JobOrderItemFactory.php`
- `tests/Feature/Commerce/OpticalItemKindSchemaTest.php`

**Estimated scope:** Medium

## Task 6: Build immutable catalog snapshots

**Description:** Add one focused builder that converts controlled catalog
selections into stable transaction snapshots.

**Acceptance criteria:**

- [ ] Frame/accessory/contact variants snapshot SKU, names, Product type, and
  applicable attributes.
- [ ] Lens Categories snapshot package identity and patient-facing name.
- [ ] Custom lines accept only an explicit permitted kind and never infer it
  from description text.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations/BuildQuotationItemSnapshotTest.php`

**Dependencies:** Task 5

**Files likely touched:**

- `app/Actions/Quotations/BuildQuotationItemSnapshot.php`
- `app/Models/ProductVariant.php`
- `app/Models/LensCategory.php`
- `tests/Feature/Quotations/BuildQuotationItemSnapshotTest.php`

**Estimated scope:** Medium

## Checkpoint B: Item foundation

- [ ] Tasks 4–6 focused tests pass on fresh and upgraded test databases.
- [ ] No snapshot builder reads clinical Prescription values.
- [ ] `vendor/bin/sail bin pint --dirty --format agent` completes cleanly.

## Task 7: Assign kinds during Quotation creation

**Description:** Route every new Quotation line through controlled item-kind
and snapshot construction.

**Acceptance criteria:**

- [ ] Catalog, Lens Category, Service, and custom selections persist the
  expected item type, item kind, and snapshot.
- [ ] Invalid kind/reference combinations fail before the Quotation is saved.
- [ ] Existing totals and one-to-fifty line validation remain unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations/CreateQuotationTest.php tests/Feature/Filament/QuotationCreationTest.php`

**Dependencies:** Task 6

**Files likely touched:**

- `app/Actions/Quotations/CreateQuotation.php`
- `app/Filament/Resources/Quotations/Schemas/QuotationCreationForm.php`
- `tests/Feature/Quotations/CreateQuotationTest.php`
- `tests/Feature/Filament/QuotationCreationTest.php`

**Estimated scope:** Medium

## Task 8: Preserve kinds during Draft updates

**Description:** Apply the same controlled item contract when an editable
Draft or Presented Quotation is revised.

**Acceptance criteria:**

- [ ] Updates rebuild snapshots from explicit submitted references and kinds.
- [ ] Editing a Presented Quotation still returns it to Draft.
- [ ] Accepted or otherwise terminal Quotations remain immutable.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations/UpdateQuotationDraftTest.php tests/Feature/Filament/QuotationResourceTest.php`

**Dependencies:** Task 7

**Files likely touched:**

- `app/Actions/Quotations/UpdateQuotationDraft.php`
- `app/Filament/Resources/Quotations/Pages/EditQuotation.php`
- `tests/Feature/Quotations/UpdateQuotationDraftTest.php`
- `tests/Feature/Filament/QuotationResourceTest.php`

**Estimated scope:** Medium

## Task 9: Copy Product snapshots at confirmation

**Description:** Copy the frozen Quotation Product kind and snapshot into Job
Order items without re-reading the catalog.

**Acceptance criteria:**

- [ ] Every created Job Order item matches its source Quotation Product line's
  kind and snapshot.
- [ ] A catalog edit between Quotation creation and confirmation does not alter
  the commercial snapshot being confirmed.
- [ ] Confirmation retries remain duplicate-free.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations/ConfirmQuotationSnapshotTest.php tests/Feature/Quotations/QuotationLifecycleTest.php`

**Dependencies:** Task 8

**Files likely touched:**

- `app/Actions/Quotations/ConfirmQuotationSale.php`
- `app/Models/QuotationItem.php`
- `app/Models/JobOrderItem.php`
- `tests/Feature/Quotations/ConfirmQuotationSnapshotTest.php`

**Estimated scope:** Medium

## Checkpoint C: Transaction snapshots

- [ ] Tasks 7–9 focused tests pass.
- [ ] Existing unified checkout and reservation tests remain green.
- [ ] Confirmed Product lines remain understandable after catalog mutation.

## Phase 3: Enforce the Optical Quotation Shape

## Task 10: Restrict discounts to admin authority

**Description:** Enforce the approved nonzero-discount permission in the
Quotation policy and mutation actions.

**Acceptance criteria:**

- [ ] Staff and optometrist accounts cannot create or update a nonzero
  discount unless the account also holds admin.
- [ ] Admin and dual-role owner can apply a valid discount that does not exceed
  subtotal.
- [ ] Direct action calls and Filament submissions enforce the same rule.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations/QuotationDiscountAuthorizationTest.php tests/Feature/Filament/QuotationCreationTest.php`

**Dependencies:** Task 8

**Files likely touched:**

- `app/Policies/QuotationPolicy.php`
- `app/Actions/Quotations/CreateQuotation.php`
- `app/Actions/Quotations/UpdateQuotationDraft.php`
- `tests/Feature/Quotations/QuotationDiscountAuthorizationTest.php`
- `tests/Feature/Filament/QuotationCreationTest.php`

**Estimated scope:** Medium

## Task 11: Validate one corrective-eyewear build

**Description:** Add a reusable server-side validator for the approved
single-build optical item matrix.

**Acceptance criteria:**

- [ ] Corrective eyewear requires exactly one lens package, at most one frame,
  and lens options only when that package exists.
- [ ] A patient-supplied frame is accepted without a fake zero-price Product.
- [ ] Service-only, non-corrective Product-only, and allowed mixed Quotations
  remain valid.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations/ValidateOpticalQuotationTest.php`

**Dependencies:** Task 9

**Files likely touched:**

- `app/Actions/Quotations/ValidateOpticalQuotation.php`
- `app/Enums/FrameSource.php`
- `tests/Feature/Quotations/ValidateOpticalQuotationTest.php`

**Estimated scope:** Medium

## Task 12: Require a current Patient prescription

**Description:** Extend the optical validator with current-version,
Patient-ownership, and non-contact-lens Prescription rules.

**Acceptance criteria:**

- [ ] A corrective lens package cannot confirm without the Patient's current
  non-superseded Prescription.
- [ ] Another Patient's or a superseded Prescription is rejected.
- [ ] Contact-lens-only and other non-corrective Product Quotations do not
  present the spectacle Prescription as contact-lens authorization.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations/QuotationPrescriptionInvariantTest.php tests/Feature/Encounters/PrescriptionLifecycleTest.php`

**Dependencies:** Task 11

**Files likely touched:**

- `app/Actions/Quotations/ValidateOpticalQuotation.php`
- `app/Models/Prescription.php`
- `tests/Feature/Quotations/QuotationPrescriptionInvariantTest.php`
- `tests/Feature/Encounters/PrescriptionLifecycleTest.php`

**Estimated scope:** Medium

## Checkpoint D: Optical domain validation

- [ ] Tasks 10–12 focused tests pass.
- [ ] The clinic Prescription field names and semantics remain unchanged.
- [ ] Plain admin cannot exercise optometrist-only clinical authority merely
  because discount authority is administrative.

## Task 13: Present an optical Quotation form

**Description:** Refine the existing Filament creation/edit form so staff
selects a frame, lens package, lens options, related Products, and Services
through the approved commercial structure.

**Acceptance criteria:**

- [ ] The form displays the linked Prescription separately from commercial
  lines and never requests OD/OS values in the item repeater.
- [ ] Patient-supplied frame and lens-option dependencies are understandable
  and validated.
- [ ] Human-facing lens package quantity reads as one pair while the persisted
  integer quantity remains one.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalQuotationFormTest.php tests/Feature/Filament/CreateQuotationPageTest.php`

**Dependencies:** Tasks 10–12

**Files likely touched:**

- `app/Filament/Resources/Quotations/Schemas/QuotationCreationForm.php`
- `app/Filament/Resources/Quotations/Schemas/QuotationForm.php`
- `app/Filament/Resources/Quotations/Pages/CreateQuotation.php`
- `tests/Feature/Filament/OpticalQuotationFormTest.php`
- `tests/Feature/Filament/CreateQuotationPageTest.php`

**Estimated scope:** Medium

## Task 14: Validate the build inside Confirm Sale

**Description:** Invoke the approved validator under the locked confirmation
transaction before any Optical Order, Billing, payment, reservation, or stock
mutation.

**Acceptance criteria:**

- [ ] Invalid build, Prescription, or discount state leaves every downstream
  aggregate unchanged.
- [ ] Valid verbal Draft and Presented confirmation paths still work.
- [ ] Concurrent or repeated confirmation remains idempotent.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations/ConfirmOpticalQuotationTest.php tests/Feature/Quotations/QuotationLifecycleTest.php`

**Dependencies:** Task 13

**Files likely touched:**

- `app/Actions/Quotations/ConfirmQuotationSale.php`
- `app/Actions/Quotations/ValidateOpticalQuotation.php`
- `tests/Feature/Quotations/ConfirmOpticalQuotationTest.php`
- `tests/Feature/Quotations/QuotationLifecycleTest.php`

**Estimated scope:** Medium

## Checkpoint E: Optical Quotation workflow

- [ ] Tasks 13–14 focused Filament and domain tests pass.
- [ ] Product-only, Service-only, and mixed checkout regressions pass.
- [ ] `vendor/bin/sail bin pint --dirty --format agent` completes cleanly.

## Phase 4: Build the Eyewear Specification

## Task 15: Persist eyewear specifications

**Description:** Add the one-to-one encrypted specification record, model,
factory, relationships, and schema tests.

**Acceptance criteria:**

- [ ] One Job Order may have at most one eyewear specification referencing the
  same Prescription and relevant frame/lens items.
- [ ] Measurements and lab/verification notes use encrypted storage with null
  rather than fabricated zero defaults.
- [ ] Approval and verification attribution use nullable foreign keys and
  timestamps without adding a second Order status enum.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/EyewearSpecificationModelTest.php tests/Feature/Database/CanonicalSchemaTest.php`

**Dependencies:** Task 14

**Files likely touched:**

- `database/migrations/*_create_job_order_eyewear_specifications_table.php`
- `app/Models/JobOrderEyewearSpecification.php`
- `app/Models/JobOrder.php`
- `database/factories/JobOrderEyewearSpecificationFactory.php`
- `tests/Feature/OpticalOrders/EyewearSpecificationModelTest.php`

**Estimated scope:** Medium

## Task 16: Create specification shells at confirmation

**Description:** Create exactly one empty specification shell only for a valid
corrective-eyewear Optical Order.

**Acceptance criteria:**

- [ ] Corrective confirmation creates a specification linked to the same
  Prescription and frozen lens/frame items.
- [ ] Ordinary immediate Product and Service-only transactions create no
  specification.
- [ ] Confirmation retry creates no second specification.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations/ConfirmEyewearSpecificationTest.php`

**Dependencies:** Task 15

**Files likely touched:**

- `app/Actions/Quotations/ConfirmQuotationSale.php`
- `app/Models/JobOrderEyewearSpecification.php`
- `tests/Feature/Quotations/ConfirmEyewearSpecificationTest.php`

**Estimated scope:** Medium

## Task 17: Save conditional dispensing measurements

**Description:** Add one action that validates and saves lens construction,
frame source, PD representation, required heights, and lab instructions.

**Acceptance criteria:**

- [ ] The action accepts either binocular distance PD or both monocular values,
  without requiring both forms.
- [ ] Near PD and fitting/segment heights are required only for applicable lens
  designs.
- [ ] It rejects cross-order item references and never mutates the clinical
  Prescription.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/SaveEyewearSpecificationTest.php`

**Dependencies:** Task 16

**Files likely touched:**

- `app/Actions/JobOrders/SaveEyewearSpecification.php`
- `app/Models/JobOrderEyewearSpecification.php`
- `tests/Feature/OpticalOrders/SaveEyewearSpecificationTest.php`

**Estimated scope:** Medium

## Checkpoint F: Eyewear data foundation

- [ ] Tasks 15–17 focused tests pass.
- [ ] Raw database assertions prove sensitive fields are encrypted.
- [ ] Prescription form, API, printing, and amendment tests remain green.

## Task 18: Render the specification form

**Description:** Add a conditional Eyewear Specification section to the
existing Optical Order page and delegate saves to the domain action.

**Acceptance criteria:**

- [ ] Corrective orders show frame, lens, conditional measurement, and lab
  fields; ordinary orders do not.
- [ ] Saved values reload correctly using millimetre-friendly decimal inputs.
- [ ] The page exposes no editable confirmed commercial prices or clinical
  Prescription values.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/EyewearSpecificationFormTest.php tests/Feature/Filament/OpticalOrderResourceTest.php`

**Dependencies:** Task 17

**Files likely touched:**

- `app/Filament/Resources/OpticalOrders/Schemas/OpticalOrderForm.php`
- `app/Filament/Resources/OpticalOrders/Pages/EditOpticalOrder.php`
- `tests/Feature/Filament/EyewearSpecificationFormTest.php`
- `tests/Feature/Filament/OpticalOrderResourceTest.php`

**Estimated scope:** Medium

## Task 19: Approve specifications as an optometrist

**Description:** Add server-side approval authorization and a locked approval
action tied to the exact saved specification state.

**Acceptance criteria:**

- [ ] Active optometrist and dual-role owner may approve a complete
  specification.
- [ ] Staff, plain admin, inactive optometrist, and cross-Patient invalid state
  are rejected.
- [ ] Editing approved construction, measurements, or instructions clears
  approval and writes a non-clinical audit event.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/ApproveEyewearSpecificationTest.php`

**Dependencies:** Task 18

**Files likely touched:**

- `app/Actions/JobOrders/ApproveEyewearSpecification.php`
- `app/Actions/JobOrders/SaveEyewearSpecification.php`
- `app/Policies/JobOrderPolicy.php`
- `tests/Feature/OpticalOrders/ApproveEyewearSpecificationTest.php`

**Estimated scope:** Medium

## Task 20: Expose approval in Filament

**Description:** Add an optometrist-only approval action and clear clinic-facing
state indicators without moving domain rules into the page.

**Acceptance criteria:**

- [ ] Only authorized users see and can invoke Approve Specification.
- [ ] The page shows Draft, Approved, and approval-cleared states with actor and
  timestamp.
- [ ] Direct Livewire calls by unauthorized accounts remain rejected.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/EyewearSpecificationApprovalTest.php`

**Dependencies:** Task 19

**Files likely touched:**

- `app/Filament/Resources/OpticalOrders/Pages/EditOpticalOrder.php`
- `app/Filament/Resources/OpticalOrders/Schemas/OpticalOrderForm.php`
- `tests/Feature/Filament/EyewearSpecificationApprovalTest.php`

**Estimated scope:** Medium

## Checkpoint G: Specification approval

- [ ] Tasks 18–20 Filament and action tests pass.
- [ ] Role matrix covers staff, optometrist, admin, and dual-role owner.
- [ ] Audit metadata contains no measurements or lab-note text.

## Phase 5: Gate Optical Fulfillment

## Task 21: Require approval before Processing

**Description:** Refine the status action so corrective eyewear cannot start
production without a complete approved specification.

**Acceptance criteria:**

- [ ] Corrective Confirmed-to-Processing fails when the specification is
  missing, incomplete, or unapproved.
- [ ] Approved corrective work transitions once and stamps `started_at`.
- [ ] Ordinary non-corrective and immediate-order behavior remains unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/OpticalOrderSpecificationGateTest.php tests/Feature/OpticalOrders/CompleteImmediateOpticalOrderTest.php`

**Dependencies:** Task 19

**Files likely touched:**

- `app/Actions/JobOrders/UpdateJobOrderStatus.php`
- `app/Models/JobOrder.php`
- `tests/Feature/OpticalOrders/OpticalOrderSpecificationGateTest.php`
- `tests/Feature/OpticalOrders/CompleteImmediateOpticalOrderTest.php`

**Estimated scope:** Medium

## Task 22: Verify completed eyewear

**Description:** Add a locked verification action that records who compared
the finished eyewear with the approved specification.

**Acceptance criteria:**

- [ ] Only an approved in-Processing corrective order can be verified.
- [ ] Verification records actor, time, and encrypted optional notes without
  modifying Prescription values.
- [ ] A retry does not create duplicate verification state or audit entries.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/VerifyEyewearTest.php`

**Dependencies:** Task 21

**Files likely touched:**

- `app/Actions/JobOrders/VerifyEyewear.php`
- `app/Models/JobOrderEyewearSpecification.php`
- `tests/Feature/OpticalOrders/VerifyEyewearTest.php`

**Estimated scope:** Medium

## Task 23: Require verification before Ready

**Description:** Gate Ready for Pickup on completed verification and required
external laboratory references.

**Acceptance criteria:**

- [ ] Unverified corrective work cannot become Ready for Pickup.
- [ ] External work additionally requires supplier/lab name and external job or
  invoice reference.
- [ ] Verified Ready specifications reject silent further edits.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/ReadyForPickupGateTest.php tests/Feature/JobOrders/JobOrderSupplierRequirementTest.php`

**Dependencies:** Task 22

**Files likely touched:**

- `app/Actions/JobOrders/UpdateJobOrderStatus.php`
- `app/Actions/JobOrders/SaveEyewearSpecification.php`
- `app/Models/JobOrder.php`
- `tests/Feature/OpticalOrders/ReadyForPickupGateTest.php`
- `tests/Feature/JobOrders/JobOrderSupplierRequirementTest.php`

**Estimated scope:** Medium

## Checkpoint H: Fulfillment integrity

- [ ] Tasks 21–23 focused tests pass.
- [ ] Confirmed -> Processing -> Verified -> Ready succeeds end to end.
- [ ] Missing approval, verification, or supplier reference produces no partial
  transition.

## Task 24: Expose verification and Ready actions

**Description:** Wire Start Processing, Verify Eyewear, and Mark Ready to the
existing Optical Order page using the tested domain actions.

**Acceptance criteria:**

- [ ] Only the action valid for the current state is shown.
- [ ] Validation errors identify the missing approval, measurement,
  verification, or supplier requirement.
- [ ] The page displays verification actor/time while keeping notes internal.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrderFulfillmentActionsTest.php`

**Dependencies:** Task 23

**Files likely touched:**

- `app/Filament/Resources/OpticalOrders/Pages/EditOpticalOrder.php`
- `app/Filament/Resources/OpticalOrders/Schemas/OpticalOrderForm.php`
- `app/Filament/Resources/OpticalOrders/Tables/OpticalOrdersTable.php`
- `tests/Feature/Filament/OpticalOrderFulfillmentActionsTest.php`

**Estimated scope:** Medium

## Phase 6: Harden Billing and Dispensing

## Task 25: Reject payment overages under lock

**Description:** Add the failing overpayment/concurrency cases and enforce the
balance comparison against the locked Billing Record.

**Acceptance criteria:**

- [ ] Zero, negative, and greater-than-balance payments are rejected without a
  payment row or ledger mutation.
- [ ] Concurrent attempts cannot make posted amount exceed Billing total.
- [ ] Valid deposits, partial payments, exact payments, and corrections retain
  their existing behavior.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/PaymentLifecycleTest.php tests/Feature/BillingRecords/PaymentConcurrencyTest.php`

**Dependencies:** Task 2

**Files likely touched:**

- `app/Actions/BillingRecords/RecordBillingPayment.php`
- `tests/Feature/BillingRecords/PaymentLifecycleTest.php`
- `tests/Feature/BillingRecords/PaymentConcurrencyTest.php`

**Estimated scope:** Medium

## Task 26: Prevent invalid payment submission in Filament

**Description:** Align Billing and confirmation payment forms with the strict
domain limit while retaining server-side enforcement.

**Acceptance criteria:**

- [ ] Payment inputs communicate and validate the current maximum balance.
- [ ] Confirmation rejects an excessive deposit before any aggregate is
  persisted.
- [ ] A forged Livewire call cannot bypass the action rule.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/BillingPaymentLimitTest.php tests/Feature/Quotations/QuotationLifecycleTest.php`

**Dependencies:** Task 25

**Files likely touched:**

- `app/Filament/Resources/BillingRecords/RelationManagers/PaymentsRelationManager.php`
- `app/Filament/Resources/Quotations/Pages/EditQuotation.php`
- `app/Actions/Quotations/ConfirmQuotationSale.php`
- `tests/Feature/Filament/BillingPaymentLimitTest.php`
- `tests/Feature/Quotations/QuotationLifecycleTest.php`

**Estimated scope:** Medium

## Checkpoint I: Payment integrity

- [ ] Tasks 25–26 focused tests pass.
- [ ] `amount_paid` cannot exceed total through any tested entry point.
- [ ] First-payment charge locking and append-only correction tests remain
  green.

## Task 27: Persist release-with-balance attribution

**Description:** Add additive Dispensing Event fields for balance at release,
admin override attribution, encrypted reason, and due-date snapshot.

**Acceptance criteria:**

- [ ] Existing Dispensing Events migrate with zero released balance and null
  override fields.
- [ ] New override foreign key and due-date fields are nullable and indexed as
  appropriate.
- [ ] Override reason is encrypted and excluded from mass serialization.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/DispensingOverrideSchemaTest.php tests/Feature/Database/CanonicalSchemaTest.php`

**Dependencies:** Task 2

**Files likely touched:**

- `database/migrations/*_add_balance_override_to_dispensing_events.php`
- `app/Models/DispensingEvent.php`
- `database/factories/DispensingEventFactory.php`
- `tests/Feature/BillingRecords/DispensingOverrideSchemaTest.php`

**Estimated scope:** Medium

## Task 28: Require payment before routine dispensing

**Description:** Reorder Dispense so a pickup payment is prevalidated to clear
the balance and committed atomically with the Dispensing Event.

**Acceptance criteria:**

- [ ] Routine actors cannot dispense while a balance remains.
- [ ] Exact pickup payment plus dispensing commits both effects once, while an
  insufficient pickup amount commits neither within Dispense.
- [ ] Staff may still record an ordinary separate partial payment through
  Billing before trying to dispense.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/PaidBeforeDispensingTest.php tests/Feature/BillingRecords/DispensingTest.php`

**Dependencies:** Tasks 25 and 27

**Files likely touched:**

- `app/Actions/BillingRecords/DispenseJobOrder.php`
- `app/Actions/BillingRecords/RecordBillingPayment.php`
- `tests/Feature/BillingRecords/PaidBeforeDispensingTest.php`
- `tests/Feature/BillingRecords/DispensingTest.php`

**Estimated scope:** Medium

## Task 29: Authorize the admin balance override

**Description:** Add the explicit domain exception for an admin releasing an
order with a documented remaining balance.

**Acceptance criteria:**

- [ ] Only admin or dual-role owner may override; staff and optometrist-only
  accounts are rejected.
- [ ] Override requires a nonblank reason and current/future due date.
- [ ] The Dispensing Event snapshots remaining balance, actor, reason, and due
  date with a non-clinical audit entry.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/ReleaseWithBalanceTest.php`

**Dependencies:** Task 28

**Files likely touched:**

- `app/Actions/BillingRecords/DispenseJobOrder.php`
- `app/Policies/BillingRecordPolicy.php`
- `app/Models/DispensingEvent.php`
- `tests/Feature/BillingRecords/ReleaseWithBalanceTest.php`

**Estimated scope:** Medium

## Task 30: Expose the dispensing exception safely

**Description:** Update the Optical Order dispensing modal to collect final
payment or, for admins only, an override reason and due date.

**Acceptance criteria:**

- [ ] Routine users see the remaining balance and can enter only a sufficient
  final payment in the Dispense action.
- [ ] Only admins see release-with-balance controls and required confirmation.
- [ ] Patient-facing and ordinary staff views do not expose the internal
  override reason.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrderDispensingPolicyTest.php tests/Feature/Api/V1/JobOrderTest.php`

**Dependencies:** Task 29

**Files likely touched:**

- `app/Filament/Resources/OpticalOrders/Pages/EditOpticalOrder.php`
- `app/Filament/Resources/OpticalOrders/Schemas/OpticalOrderForm.php`
- `tests/Feature/Filament/OpticalOrderDispensingPolicyTest.php`
- `tests/Feature/Api/V1/JobOrderTest.php`

**Estimated scope:** Medium

## Checkpoint J: Billing and dispensing

- [ ] Tasks 27–30 focused tests pass.
- [ ] Exact-payment and admin-override dispensing both produce one event.
- [ ] Routine balance release, overpayment, and internal-data leakage are
  rejected.
- [ ] `vendor/bin/sail bin pint --dirty --format agent` completes cleanly.

## Phase 7: Structure Contact-Lens Catalog Data

## Task 31: Validate canonical contact-lens attributes

**Description:** Replace unrestricted contact-lens KeyValue entry with
conditional fields backed by the approved attribute keys while retaining JSON
storage.

**Acceptance criteria:**

- [ ] Contact-lens variants accept applicable power, base curve, diameter,
  cylinder, axis, add, color, and pack-size values.
- [ ] Invalid ranges or incompatible values fail validation without affecting
  frame/accessory/lens variants.
- [ ] Existing valid attribute JSON remains readable.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Products/ContactLensVariantAttributesTest.php tests/Feature/Filament/ContactLensVariantFormTest.php`

**Dependencies:** Task 6

**Files likely touched:**

- `app/Models/ProductVariant.php`
- `app/Filament/Resources/Products/Schemas/ProductForm.php`
- `app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php`
- `tests/Feature/Products/ContactLensVariantAttributesTest.php`
- `tests/Feature/Filament/ContactLensVariantFormTest.php`

**Estimated scope:** Medium

## Task 32: Snapshot contact-lens parameters

**Description:** Extend the item snapshot builder and tests so confirmed
contact-lens lines retain the exact sellable variant parameters.

**Acceptance criteria:**

- [ ] Quotation contact-lens snapshots contain only canonical applicable
  parameters plus Product/variant identity.
- [ ] Confirmation copies the same parameters to the Job Order item.
- [ ] Later Product Variant edits do not change either transaction snapshot.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations/ContactLensSnapshotTest.php`

**Dependencies:** Tasks 9 and 31

**Files likely touched:**

- `app/Actions/Quotations/BuildQuotationItemSnapshot.php`
- `tests/Feature/Quotations/ContactLensSnapshotTest.php`

**Estimated scope:** Small

## Checkpoint K: Contact-lens catalog contract

- [ ] Tasks 31–32 focused tests pass.
- [ ] Contact-lens parameters are commercial Product data, not fields on the
  spectacle Prescription.
- [ ] Existing frame, lens, accessory, and catalog API tests remain green.

## Phase 8: Add Contact-Lens Lot Traceability

## Task 33: Persist inventory lots

**Description:** Add the contact-lens lot table, movement reference, model,
factory, relationships, and database constraints.

**Acceptance criteria:**

- [ ] Variant/lot number is unique and lot quantities cannot be negative.
- [ ] Movements may reference an exact lot while existing non-lot movements
  remain valid.
- [ ] Lot receipt/expiry values use date/time types and identify receiving
  actor without adding purchasing tables.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Inventory/InventoryLotSchemaTest.php tests/Feature/Database/CanonicalSchemaTest.php`

**Dependencies:** Task 32

**Files likely touched:**

- `database/migrations/*_create_inventory_lots_and_link_movements.php`
- `app/Models/InventoryLot.php`
- `app/Models/InventoryMovement.php`
- `database/factories/InventoryLotFactory.php`
- `tests/Feature/Inventory/InventoryLotSchemaTest.php`

**Estimated scope:** Medium

## Task 34: Reconcile existing contact stock

**Description:** Add an admin-only action that partitions an existing
contact-lens aggregate across real lots without changing total stock.

**Acceptance criteria:**

- [ ] Allocations require real lot/expiry values and sum exactly to the locked
  current aggregate.
- [ ] The action creates no restock and no fabricated legacy lot.
- [ ] Reconciliation is atomic, auditable, idempotent, and rejects non-admin
  actors.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Inventory/ReconcileContactLensLotsTest.php`

**Dependencies:** Task 33

**Files likely touched:**

- `app/Actions/Inventory/ReconcileContactLensLots.php`
- `app/Models/ProductVariant.php`
- `app/Policies/InventoryLotPolicy.php`
- `tests/Feature/Inventory/ReconcileContactLensLotsTest.php`

**Estimated scope:** Medium

## Task 35: Expose lot reconciliation to admins

**Description:** Add a conditional reconciliation action to the existing
Product Variant inventory interface.

**Acceptance criteria:**

- [ ] Only unreconciled contact-lens variants with nonzero stock offer the
  action.
- [ ] The form requires allocations summing to displayed aggregate stock and
  identifies expired dates before submission.
- [ ] Unauthorized or forged calls fail at the action boundary.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/ContactLensLotReconciliationTest.php`

**Dependencies:** Task 34

**Files likely touched:**

- `app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php`
- `app/Filament/Resources/Products/Pages/EditProduct.php`
- `tests/Feature/Filament/ContactLensLotReconciliationTest.php`

**Estimated scope:** Medium

## Checkpoint L: Lot foundation

- [ ] Tasks 33–35 focused tests pass on fresh and upgraded databases.
- [ ] Existing stock is never increased or assigned fake traceability data.
- [ ] Unreconciled nonzero contact stock is identifiable and cannot proceed to
  allocation in later tasks.

## Task 36: Receive stock into a lot

**Description:** Extend the existing stock movement path so contact-lens
receipts atomically update a real lot, aggregate quantity, and movement.

**Acceptance criteria:**

- [ ] Contact-lens receipt requires positive quantity, nonblank lot, and
  non-expired expiration date.
- [ ] Frame/accessory receipt retains its simple aggregate-only path.
- [ ] Failed or concurrent receipt cannot drift aggregate and lot totals.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Inventory/ReceiveContactLensStockTest.php tests/Feature/Inventory/InventoryLedgerTest.php`

**Dependencies:** Task 35

**Files likely touched:**

- `app/Actions/Inventory/RecordInventoryMovement.php`
- `app/Models/InventoryLot.php`
- `app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php`
- `tests/Feature/Inventory/ReceiveContactLensStockTest.php`
- `tests/Feature/Inventory/InventoryLedgerTest.php`

**Estimated scope:** Medium

## Task 37: Allocate contact stock by FEFO

**Description:** Make order commitment choose the earliest-expiring eligible
lot by default or honor an explicitly selected eligible physical lot.

**Acceptance criteria:**

- [ ] Default allocation is deterministic FEFO across non-expired lots.
- [ ] Explicit selection accepts only a reconciled non-expired lot belonging to
  the ordered variant.
- [ ] Concurrent allocations cannot make variant or lot stock negative and
  preserve their sum invariant.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Inventory/CommitContactLensLotTest.php tests/Feature/JobOrders/JobOrderInventoryAtomicTest.php`

**Dependencies:** Task 36

**Files likely touched:**

- `app/Actions/JobOrders/CommitJobOrderInventory.php`
- `app/Models/InventoryLot.php`
- `tests/Feature/Inventory/CommitContactLensLotTest.php`
- `tests/Feature/JobOrders/JobOrderInventoryAtomicTest.php`

**Estimated scope:** Medium

## Task 38: Restore the source lot on cancellation

**Description:** Extend idempotent cancellation reversal to return each
contact-lens commitment to the exact lot it consumed.

**Acceptance criteria:**

- [ ] Cancellation restores aggregate and source-lot quantity once.
- [ ] Repeated cancellation/reversal attempts create no duplicate restoration.
- [ ] Frame/accessory reversal and Frame Reservation conversion remain
  unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Inventory/ReverseContactLensLotTest.php tests/Feature/JobOrders/JobOrderInventoryTest.php tests/Feature/Reservations/ConvertFrameReservationToJobOrderTest.php`

**Dependencies:** Task 37

**Files likely touched:**

- `app/Actions/JobOrders/UpdateJobOrderStatus.php`
- `app/Models/InventoryMovement.php`
- `tests/Feature/Inventory/ReverseContactLensLotTest.php`
- `tests/Feature/JobOrders/JobOrderInventoryTest.php`
- `tests/Feature/Reservations/ConvertFrameReservationToJobOrderTest.php`

**Estimated scope:** Medium

## Checkpoint M: Lot-aware stock lifecycle

- [ ] Tasks 36–38 focused and concurrency tests pass.
- [ ] Receive -> allocate -> cancel preserves aggregate/lot equality.
- [ ] Expired, unreconciled, foreign, and insufficient lots are rejected
  without partial movements.
- [ ] `vendor/bin/sail bin pint --dirty --format agent` completes cleanly.

## Task 39: Display contact-lens lots and expiry

**Description:** Add read-only per-lot visibility and expired/near-expiry
indicators inside the existing inventory/product workspace.

**Acceptance criteria:**

- [ ] Staff can see lot number, expiration, and remaining quantity for a
  contact-lens variant.
- [ ] Expired and configurable near-expiry lots have distinct labels without
  adding notification delivery.
- [ ] Non-contact Products do not show empty lot-management UI.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/ContactLensLotVisibilityTest.php`

**Dependencies:** Task 38

**Files likely touched:**

- `app/Filament/Resources/Products/RelationManagers/InventoryLotsRelationManager.php`
- `app/Filament/Resources/Products/ProductResource.php`
- `config/inventory.php`
- `tests/Feature/Filament/ContactLensLotVisibilityTest.php`

**Estimated scope:** Medium

## Checkpoint N: Inventory operations

- [ ] Task 39 Filament tests pass with the lot lifecycle suites from Checkpoint
  M.
- [ ] Contact-lens lots are operationally visible without introducing purchase
  orders, supplier balances, or notification delivery.
- [ ] Non-contact Product workflows remain free of lot-specific UI.

## Phase 9: Protect Patient Contracts and Finish the Workflow

## Task 40: Serialize stable patient-facing item data

**Description:** Expose only the stable commercial item information needed by
existing patient Quotation and Optical Order screens.

**Acceptance criteria:**

- [ ] Patient resources retain descriptions, quantities, prices, item kind,
  status, total, due date, and payment summary where currently applicable.
- [ ] Eyewear measurements, lab instructions, lots, supplier references,
  approval/verification metadata, and override reason remain absent.
- [ ] Ownership and Draft visibility rules remain unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/QuotationTest.php tests/Feature/Api/V1/JobOrderTest.php tests/Feature/Api/V1/OpticalCommercePrivacyTest.php`

**Dependencies:** Tasks 24, 30, 32, and 39

**Files likely touched:**

- `app/Http/Resources/QuotationResource.php`
- `app/Http/Resources/Api/OpticalOrderResource.php`
- `tests/Feature/Api/V1/OpticalCommercePrivacyTest.php`
- `tests/Feature/Api/V1/QuotationTest.php`
- `tests/Feature/Api/V1/JobOrderTest.php`

**Estimated scope:** Medium

## Task 41: Prove the complete prepared-eyewear journey

**Description:** Extend the end-to-end clinic workflow through optical
quotation, deposit, approved specification, fulfillment, verification, final
payment, and dispensing.

**Acceptance criteria:**

- [ ] One realistic corrective-eyewear path crosses every approved aggregate
  without duplicate records or inconsistent totals.
- [ ] External fulfillment cannot become Ready without verification and the
  required supplier/lab reference.
- [ ] Final payment clears the balance before ordinary dispensing completes.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/EndToEnd/OpticalCommerceWorkflowTest.php tests/Feature/EndToEnd/ClinicWorkflowTest.php`

**Dependencies:** Task 40

**Files likely touched:**

- `tests/Feature/EndToEnd/OpticalCommerceWorkflowTest.php`
- `tests/Feature/EndToEnd/ClinicWorkflowTest.php`
- `database/seeders/ClinicWorkflowSeeder.php`
- `database/factories/JobOrderFactory.php`
- `database/factories/QuotationFactory.php`

**Estimated scope:** Medium

## Task 42: Reconcile canonical documentation

**Description:** Update canonical backend and API documentation only after all
implemented behavior and route/resource contracts are verified.

**Acceptance criteria:**

- [ ] Backend context describes item kinds, eyewear specification, approval,
  verification, payment release policy, and lot traceability accurately.
- [ ] API documentation lists only patient-visible fields and explicitly omits
  internal optical data.
- [ ] Older conflicting specs remain historical and point to the approved
  specification where needed.

**Verification:**

- [ ] `git diff --check`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php tests/Feature/Database/CanonicalSchemaTest.php`

**Dependencies:** Task 41

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`
- `docs/API_CONTRACT.md`
- `docs/specs/optical-commerce-and-dispensing-spec.md`
- `tasks/plan.md`
- `tasks/todo.md`

**Estimated scope:** Medium

## Final Checkpoint: Release candidate

- [ ] Every task and intermediate checkpoint is complete.
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations tests/Feature/OpticalOrders tests/Feature/JobOrders tests/Feature/BillingRecords tests/Feature/Inventory tests/Feature/Reservations tests/Feature/Api/V1` passes.
- [ ] `vendor/bin/sail artisan test --compact` passes.
- [ ] `vendor/bin/sail bin pint --dirty --format agent` completes cleanly.
- [ ] `git diff --check` passes.
- [ ] No dependency, public route, top-level directory, Prescription-field
  interpretation, partial fulfillment, purchasing, insurance, return/remake,
  or lab-integration scope was introduced.
- [ ] Specification, plan, checklist, backend context, and API contract match
  the implemented state.
- [ ] Implementation is ready for code review and project-owner acceptance; it
  is not automatically committed or deployed.

## Phase 3 Approval Gate

Implementation must not begin until the project owner approves this checklist.
Approval means the implementation may proceed task by task in dependency order
with the listed checkpoints; it does not authorize deployment, destructive
database resets, dependency changes, or deferred features.
