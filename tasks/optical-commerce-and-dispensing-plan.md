# Implementation Plan: Practical Optical Commerce and Dispensing

**Status:** Approved
**Specification:** `docs/specs/optical-commerce-and-dispensing-spec.md`
**Specification approved:** 2026-08-10
**Plan approved:** 2026-08-10
**Task breakdown:** Approved 2026-08-10
**Implementation:** Not started

## Overview

Implement the approved optical-commerce refinements as a sequence of tested
vertical slices over the existing Quotation, `JobOrder`/Optical Order, unified
Billing Record, payment, and inventory architecture.

The implementation adds stable optical item classifications and snapshots, one
structured eyewear specification per corrective-eyewear order, optometrist
approval and fulfillment verification, contact-lens lot/expiration
traceability, strict overpayment prevention, and an auditable admin-only
release-with-balance exception.

It does not replace the existing aggregates, reinterpret the clinic
Prescription, introduce a contact-lens clinical record, or build purchasing,
supplier accounting, insurance, tax documents, lab integration, remakes,
returns, partial fulfillment, or multi-location inventory.

## Planning Gate

This Phase 2 document defines architecture, dependency order, vertical slices,
risks, and verification checkpoints. Approval authorizes Phase 3 task
breakdown only. It does not authorize application-code changes.

The executable checklist in `tasks/optical-commerce-and-dispensing-todo.md` must not be replaced with this
feature's tasks until this plan is approved.

## Current-State Constraints

The plan adapts these implemented behaviors rather than rebuilding them:

- Quotations and Optical Orders are separate Filament resources and tables.
- `JobOrder` remains the internal model behind the **Optical Order** label.
- Confirm Sale atomically accepts a Quotation, creates one Optical Order for
  Product lines, commits inventory, resolves unified checkout, appends selected
  Services, and optionally records a deposit.
- Job Order items contain only Product lines.
- Billing Records already support deposits, partial payments, append-only
  payment correction, charge-set locking after the first payment, and unified
  Product/Service checkout.
- Inventory movements already provide row-locked aggregate quantity changes,
  Frame Reservation conversion, commitment reversal, and audit attribution.
- Product types are `frame`, `lens`, `contact_lens`, and `accessory`.
- The Prescription form intentionally preserves one clinically unidentified
  neutral value and excludes PD and axis; this feature cannot reinterpret it.
- Patient Quotation and Optical Order resources already enforce patient
  ownership and hide internal supplier data.

## Architecture Decisions

### 1. Preserve the aggregate boundaries

Keep `Quotation`, `JobOrder`, `BillingRecord`, and `InventoryMovement` as
separate canonical records. Add the eyewear specification as a one-to-one
child of `JobOrder`; do not create a replacement sale aggregate or rename the
existing tables and foreign keys.

### 2. Add stable item-kind snapshots

Retain `TransactionItemType` (`product` or `service`) for the high-level
financial boundary. Add an optical commercial item-kind enum and persisted
snapshot fields to Quotation and Job Order items:

```text
frame
lens_package
lens_option
contact_lens
accessory
custom_product
service
```

Catalog selections derive their initial kind from controlled relationships,
but every transaction persists the kind plus relevant names, SKU, physical
attributes, and contact-lens parameters. Confirmation copies the frozen
Quotation snapshot rather than re-reading mutable catalog data.

### 3. Model one eyewear specification per corrective order

Add one `JobOrderEyewearSpecification` record only when a confirmed order has a
prescription lens package. It references the immutable Prescription and the
relevant Job Order items, and stores:

- catalog or patient-supplied frame source;
- lens design/package, material, index, and option snapshots;
- conditional PD and height measurements;
- internal lab instructions;
- optometrist approval attribution; and
- completed-eyewear verification attribution.

Use encrypted text storage for patient-linked measurements and narrative
notes. Do not query or sort by those values.

### 4. Keep clinical and dispensing semantics separate

The existing Prescription remains the source of clinical values and immutable
version history. The eyewear specification adds dispensing measurements and
selected construction only. No action, migration, label, serializer, or test
may infer axis or another meaning for `main_*_value` or `add_*_value`.

### 5. Gate corrective-eyewear fulfillment in domain actions

Staff may draft measurements, but an active optometrist must approve the
specification. Starting Processing requires valid approved data. Marking Ready
requires completed verification and the external supplier reference when
applicable. Mutating an approved specification clears approval. Filament pages
delegate to reusable actions; UI visibility is not the authorization boundary.

### 6. Keep one fulfillment unit

An Optical Order contains at most one corrective-eyewear build and may contain
related Product lines. There is one order-level fulfillment status. Partial
line fulfillment is not introduced. Separate prescription pairs require
separate Quotations and Optical Orders.

### 7. Use lot-aware inventory only where required

Frames and accessories continue using aggregate variant stock. Contact-lens
variants gain `InventoryLot` records and lot-aware movements. The variant
aggregate remains the fast existing stock value, while the sum of contact-lens
lot quantities is a transactionally maintained invariant.

Use FEFO allocation by default under row locks, allow an explicit eligible lot
selection, restore cancellation quantity to the source lot, and prohibit
expired-lot allocation.

### 8. Reconcile existing contact-lens stock explicitly

Do not fabricate historical lots or expirations. An admin-only initialization
workflow allocates each existing nonzero contact-lens aggregate across real
physical lots. Allocations must sum exactly to the current aggregate and do not
increase stock or create a fake restock. Until reconciliation succeeds, the
variant cannot be committed to an order.

### 9. Extend the existing Receive Stock action

Refactor stock mutation behind the existing inventory action instead of adding
a parallel ledger. Ordinary products require positive quantity and notes as
currently appropriate. Contact lenses additionally require lot number and
expiration and atomically update the lot, aggregate, and movement.

### 10. Enforce payment and release invariants under locks

Change payment recording to reject an amount greater than the locked balance;
never clamp the balance while allowing `amount_paid` to exceed the total.
Preserve first-payment charge locking and append-only corrections.

Change dispensing so a pickup payment is prevalidated to clear the balance and
then recorded with dispensing in one transaction. A remaining balance requires
an admin-only override with due date and reason snapshotted onto the Dispensing
Event. Ordinary partial payments remain a separate Billing action.

### 11. Add no new public workflow

Keep existing API routes and response envelopes. Add only patient-useful item
snapshot fields if required for stable display. Eyewear measurements, internal
lab data, lots, approval, verification, supplier reference, and balance-
override metadata remain server-internal.

### 12. Use additive forward migrations

Introduce new tables and nullable/backfillable columns without resetting the
database or rewriting historical records destructively. Existing Quotation and
Job Order lines receive deterministic item kinds from their controlled foreign
keys where possible; ambiguous custom Product rows become `custom_product`
rather than being guessed from their descriptions.

## Dependency Graph

```text
Approved specification
    -> current-behavior characterization
        -> item-kind enum + transaction snapshot schema
            -> quotation creation/update validation
                -> atomic confirmation snapshot copying
                    -> eyewear-specification persistence
                        -> measurement validation + optometrist approval
                            -> Processing/Ready fulfillment gates

Current-behavior characterization
    -> payment overage lock tests
        -> strict payment action
            -> paid-before-dispense + admin override

Item-kind contract
    -> canonical contact-lens attributes
        -> inventory-lot schema
            -> existing-stock reconciliation
                -> Receive Stock
                    -> FEFO/explicit-lot commitment
                        -> same-lot cancellation reversal

All domain slices
    -> Filament integration
        -> patient API privacy/regression
            -> end-to-end commerce workflow
                -> documentation reconciliation and release verification
```

The item-kind contract is the common prerequisite for Quotation validation,
eyewear-specification creation, and deciding when lot-aware inventory applies.
Payment work is mostly independent after characterization. Inventory lots are
independent of eyewear measurements after the item-kind contract is stable.

## Vertical Implementation Slices

### Slice 1: Characterize the implemented transaction boundaries

Add focused characterization tests before changing behavior. Cover direct and
presented confirmation, Product/Service separation, frame-reservation
conversion, unified checkout reuse, deposit creation, immediate/prepared
orders, cancellation reversal, current patient serialization, and current
authorization.

Key outcomes:

- the implementation preserves known-good commerce behavior;
- existing gaps are reproduced explicitly: generic optical lines, overpayment
  clamping, and dispensing without a balance check; and
- later slices can distinguish intentional refinements from regressions.

Likely areas:

- `tests/Feature/Quotations/`
- `tests/Feature/OpticalOrders/`
- `tests/Feature/BillingRecords/`
- `tests/Feature/Inventory/`
- `tests/Feature/Api/V1/`

### Checkpoint A: Characterization baseline

- Existing targeted suites pass without production-code changes.
- Each approved behavior being changed has a failing or characterization test.
- No test is deleted merely because it conflicts with the new direction; it is
  replaced only when the corresponding vertical slice lands.

### Slice 2: Introduce optical item kinds and stable snapshots

Add the enum, additive transaction-item columns, model casts/factory states,
safe historical backfill, and item-kind validation. Update Quotation creation
and draft editing so catalog selections derive controlled kinds and custom
choices require an explicit kind. Preserve the current Product/Service
boundary.

Then update confirmation to copy the frozen Product snapshot into Job Order
items and Billing to continue copying only its concise financial snapshot.

Key outcomes:

- behavior no longer depends on matching free-text descriptions;
- frames, lens packages, lens options, contacts, accessories, custom products,
  and Services are distinguishable after catalog changes;
- confirmed item snapshots are immutable; and
- retries do not duplicate snapshots or records.

Likely areas:

- new migration under `database/migrations/`
- new enum under `app/Enums/`
- `app/Models/QuotationItem.php`
- `app/Models/JobOrderItem.php`
- `app/Actions/Quotations/`
- Quotation forms and focused tests

### Slice 3: Enforce one optical-build quotation

Build server-side validation for the approved quotation shape and expose the
same guidance in Filament:

- exactly one lens package for corrective eyewear;
- at most one frame, with patient-supplied frame as a separate choice;
- lens options only with the package;
- current Patient-owned Prescription required at confirmation; and
- no use of a spectacle Prescription as contact-lens authorization.

Keep Service-only, ordinary Product-only, mixed, verbal direct-confirmation,
and optional-presentation paths working.

Key outcomes:

- invalid optical builds fail before inventory, Billing, or payment mutation;
- Quotation output is commercially readable and does not expose OD/OS values;
  and
- direct non-corrective sales do not receive unnecessary specification data.

Likely areas:

- dedicated Quotation validation action/class
- `CreateQuotation`, `UpdateQuotationDraft`, and `ConfirmQuotationSale`
- Quotation Filament schemas/pages
- Quotation policy for admin-only discounts
- focused Quotation/Filament tests

### Checkpoint B: Commercial contract

- Draft, Present, Edit, Decline/Expire, and Confirm flows pass.
- Corrective and non-corrective item combinations follow the approved matrix.
- Non-admin discounts fail at the server boundary.
- Confirmation remains atomic and idempotent.
- Modified PHP passes Pint.

### Slice 4: Persist and prepare the eyewear specification

Add the one-to-one specification model, migration, factory, encrypted casts,
relationships, and creation shell at corrective-order confirmation. Implement
conditional measurement and reference validation through focused actions.

Key outcomes:

- each corrective Optical Order has exactly one specification;
- ordinary immediate Product orders have none;
- the specification references the same current Prescription as the Optical
  Order;
- binocular or monocular PD is accepted correctly;
- lens-design-dependent heights are enforced without fabricated values; and
- sensitive measurements and notes are encrypted.

Likely areas:

- new migration and model/factory
- `app/Models/JobOrder.php`
- specification validation/save action
- `ConfirmQuotationSale`
- specification model/action tests

### Slice 5: Add optometrist approval and safe mutation

Add an approval action and policy rules. Staff/admin operational users may save
draft specification data, but only an active optometrist may approve. Any
allowed edit after approval clears approval and emits a non-clinical audit
event.

Key outcomes:

- staff cannot approve through direct action calls;
- plain admin cannot approve;
- optometrist and dual-role owner can approve;
- approval is tied to the exact saved state; and
- audit metadata contains only identifiers, states, and timestamps.

Likely areas:

- approval/save actions under `app/Actions/JobOrders/`
- Optical Order policy or focused specification policy
- audit integration
- role-matrix and state-transition tests

### Slice 6: Gate Processing, verification, and Ready for Pickup

Refine the existing status action so a corrective order cannot enter Processing
without an approved specification and cannot become Ready without verification.
Add verification attribution and require the external supplier/lab reference
for external work. Keep immediate Product-only completion unchanged.

Expose the workflow in the existing Optical Order edit page as separate Save,
Approve, Start Processing, Verify, and Mark Ready actions.

Key outcomes:

- order status cannot outrun its optical work;
- verified specification becomes immutable at Ready;
- external references remain internal; and
- existing immediate orders avoid artificial stages.

Likely areas:

- `UpdateJobOrderStatus`
- new verification action
- `OpticalOrderForm` and `EditOpticalOrder`
- Optical Order table badges/actions
- fulfillment and Filament tests

### Checkpoint C: Corrective-eyewear fulfillment

- Confirm -> specification -> approval -> Processing -> verification -> Ready
  passes end to end.
- Invalid role, missing measurement, missing approval, and missing external
  reference paths fail without partial state changes.
- Immediate non-corrective order behavior remains green.
- Patient resources expose no new internal data.

### Slice 7: Reject overpayments safely

Write the concurrency-focused payment tests, then change payment recording to
compare against the locked current balance. Preserve first-payment charge
acknowledgement, status recalculation, reversal history, and ordinary deposits
and partial payments.

Key outcomes:

- single and concurrent overpayments fail;
- `amount_paid` never exceeds total for active posted payments;
- valid deposits and exact-balance payments still work; and
- no public or Filament entry point can bypass the action invariant.

Likely areas:

- `RecordBillingPayment`
- Billing payment Filament relation manager/action
- confirmation deposit validation
- `PaymentLifecycleTest` and focused Filament tests

### Slice 8: Enforce paid-before-dispense with admin exception

Add the Dispensing Event snapshot fields, domain validation, admin-only
override path, and UI. Reorder the pickup-payment flow so an entered payment is
validated as sufficient before payment and dispensing are committed together.

Key outcomes:

- routine actors cannot dispense with a balance;
- exact pickup payment and dispensing succeed atomically;
- insufficient pickup payment directs staff to record a separate partial
  payment and causes no mutation in the Dispense action;
- admin release requires a due date and reason; and
- the Dispensing Event retains balance-at-release and override attribution.

Likely areas:

- new dispensing-event migration
- `DispenseJobOrder`
- Billing/Optical Order dispensing modal
- policy/action authorization
- dispensing and Filament tests

### Checkpoint D: Financial integrity

- Deposit, partial, exact, correction, void, and concurrency tests pass.
- Charge-set locking still works across unified checkout sources.
- Routine dispensing requires zero balance.
- Admin override is fully attributed and patient-safe.
- Modified PHP passes Pint.

### Slice 9: Canonicalize contact-lens parameters and snapshots

Add conditional Product Variant validation and Filament fields for the approved
contact-lens attribute keys. Continue storing them in the existing attributes
JSON. Snapshot the applicable values into Quotation and Job Order items.

Key outcomes:

- contact-lens SKUs record only applicable power, base curve, diameter,
  cylinder, axis, add, color, and pack-size fields;
- frame/accessory forms are unaffected;
- catalog edits cannot change a confirmed contact-lens order; and
- the system does not label the spectacle Prescription as contact-lens
  authorization.

Likely areas:

- Product model/validation helper
- Product/variant Filament forms
- snapshot builder used by Quotation actions
- factories and catalog/Filament tests

### Slice 10: Add lots and reconcile existing contact stock

Add the inventory-lot table/model/factory, lot reference on movements, and
admin-only existing-stock reconciliation. Block sale allocation for a nonzero
contact variant whose aggregate has not been fully allocated to real lots.

Key outcomes:

- real lot and expiration values are required;
- existing aggregate quantity is partitioned without increasing stock;
- fake legacy lot values are never generated;
- duplicate variant/lot numbers fail; and
- aggregate/lot equality is established before sale.

Likely areas:

- inventory migrations
- `InventoryLot` model/factory
- reconciliation action
- inventory policy/UI action
- lot schema and reconciliation tests

### Slice 11: Receive and allocate contact-lens stock

Extend Receive Stock to update aggregate stock, lot quantity, and movement in
one transaction. Add FEFO allocation and explicit eligible-lot selection to
order confirmation, then make cancellation restore the exact source lot.

Key outcomes:

- contact receipt requires lot and expiration;
- frames/accessories keep their simple aggregate receipt path;
- expired lots cannot sell;
- concurrent allocations cannot make any quantity negative;
- FEFO and explicit selection are deterministic; and
- cancellation is idempotent at aggregate and lot levels.

Likely areas:

- `RecordInventoryMovement` and/or dedicated Receive Stock action
- `CommitJobOrderInventory`
- `UpdateJobOrderStatus` cancellation reversal
- Inventory and Quotation/Optical Order Filament forms
- inventory concurrency and lifecycle tests

### Checkpoint E: Inventory traceability

- Receive -> allocate -> cancel flows preserve aggregate/lot equality.
- Existing stock reconciliation is auditable and quantity-neutral.
- Expired and near-expiry lots render correctly.
- Frame Reservation conversion still avoids double commitment.
- Full Inventory, Reservation, and Optical Order suites pass.

### Slice 12: Reconcile API, privacy, and the complete clinic workflow

Update existing serializers only where stable patient-facing snapshots need to
be exposed. Explicitly test that specification measurements, internal lab
data, lot values, supplier references, approval/verification metadata, and
balance-override reasons remain absent.

Extend the end-to-end clinic workflow test through:

```text
Encounter/current Prescription
    -> optical Quotation
    -> Confirm Sale + deposit
    -> approved eyewear specification
    -> external or local fulfillment
    -> verification and Ready
    -> final payment
    -> dispense
```

Finish by reconciling `docs/BACKEND_CONTEXT.md` and API documentation with only
the behavior actually implemented.

Key outcomes:

- patient ownership and privacy remain intact;
- one realistic flow proves the cross-aggregate contract;
- all focused and full tests pass; and
- canonical documentation matches the shipped state.

Likely areas:

- `app/Http/Resources/QuotationResource.php`
- `app/Http/Resources/Api/OpticalOrderResource.php`
- API and end-to-end tests
- `docs/BACKEND_CONTEXT.md`
- existing API contract documentation

### Checkpoint F: Release candidate

- Every specification success criterion has a corresponding passing test or
  explicit verified UI behavior.
- Focused suites pass through Sail.
- Full `vendor/bin/sail artisan test --compact` passes.
- Changed PHP is formatted with
  `vendor/bin/sail bin pint --dirty --format agent`.
- No dependency, route, top-level directory, or deferred workflow was added.
- Documentation describes only implemented behavior.
- The implementation is ready for code review, not automatically deployed.

## Implementation Order

The required sequential spine is:

1. Characterization baseline
2. Item kinds and snapshots
3. Optical-build quotation validation
4. Eyewear specification persistence
5. Optometrist approval
6. Processing/verification/Ready gates
7. Strict payment validation
8. Dispensing balance policy
9. Contact-lens parameter contract
10. Lot schema and reconciliation
11. Lot-aware receipt, allocation, and reversal
12. API/privacy/end-to-end/documentation reconciliation

Do not begin with Filament layout changes. Each UI change follows the tested
domain action it invokes.

## Parallelization Opportunities

No parallel agent work is assumed. If explicitly authorized later:

- Payment/dispensing slices may proceed independently of eyewear specification
  work after characterization, but both touch the final Dispense flow and need
  integration review.
- Contact-lens Product-form work may proceed after the item-kind contract while
  eyewear-specification actions are developed.
- API privacy tests may be written against approved contracts while internal
  domain slices proceed, but serializer changes wait for stable snapshots.
- Migrations, shared item enums, `ConfirmQuotationSale`,
  `CommitJobOrderInventory`, and end-to-end tests require sequential ownership.

## Verification Commands

```text
Start services:
vendor/bin/sail up -d

Focused Quotations and Orders:
vendor/bin/sail artisan test --compact tests/Feature/Quotations tests/Feature/OpticalOrders tests/Feature/JobOrders

Focused Billing:
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords

Focused Inventory and Reservations:
vendor/bin/sail artisan test --compact tests/Feature/Inventory tests/Feature/Reservations

Focused Filament:
vendor/bin/sail artisan test --compact tests/Feature/Filament/QuotationResourceTest.php tests/Feature/Filament/OpticalOrderResourceTest.php tests/Feature/Filament/InventoryResourceTest.php tests/Feature/Filament/BillingRecordResourceTest.php

Focused patient API:
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/QuotationTest.php tests/Feature/Api/V1/JobOrderTest.php tests/Feature/Api/V1/WorkflowReadsTest.php

Format PHP:
vendor/bin/sail bin pint --dirty --format agent

Full regression:
vendor/bin/sail artisan test --compact
```

Before each implementation slice, use Laravel Boost `search-docs` for the
relevant Laravel, Filament, Livewire, or Pest behavior and use Sail-prefixed
Artisan generators for new Laravel classes, models, migrations, policies, and
tests where available.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Item-kind backfill misclassifies historical custom lines | High | Derive only from controlled foreign keys; use `custom_product` for ambiguity; never parse descriptions |
| Eyewear fields accidentally reinterpret the clinic Prescription | High | Keep explicit dispensing names, no migration of neutral values, and regression tests for prescription labels/contracts |
| Confirmation grows into an unsafe monolithic action | High | Extract focused validators/snapshot builders while retaining one outer transaction and idempotency tests |
| Aggregate and contact-lens lot stock drift | High | One mutation path, row locks, atomic dual updates, sum-invariant tests after every operation |
| Existing stock receives invented traceability data | High | Require real admin reconciliation; block sale until exact allocation; never auto-create a fake lot |
| Concurrent payments overpay a bill | High | Compare against the freshly locked balance and add concurrent-attempt tests |
| Pickup payment is posted but dispensing fails | High | Prevalidate sufficient amount, then record payment and dispensing inside one transaction |
| Plain admin performs clinical approval | High | Require active optometrist role in the domain action; test dual-role owner separately |
| Internal optical data leaks through patient serializers | High | Explicit resource allowlists and negative privacy assertions |
| One-order fulfillment becomes awkward for two pairs | Medium | Enforce one build now and direct staff to create two orders; keep multi-build and combined multi-order billing deferred |
| Lot/expiry UI becomes a purchasing module | Medium | Limit it to Receive Stock, reconciliation, allocation, and read-only lot visibility |
| Older specs conflict with the new direction | Medium | Treat the approved specification as authoritative and update canonical context only after implementation |

## Phase 2 Open Questions

There are no unresolved product decisions. Phase 3 may choose exact class,
table, enum, and test filenames while keeping tasks within five likely files
and preserving this dependency order.

## Phase 2 Approval Gate

Before Phase 3 task breakdown begins, the project owner must confirm that:

1. the architecture decisions preserve the intended clinic workflow;
2. the slice order addresses the highest-risk invariants early enough;
3. the inventory-lot reconciliation is acceptable without fabricated legacy
   data;
4. the plan remains intentionally limited to one corrective-eyewear build per
   Optical Order; and
5. no implementation should begin until the later task checklist is separately
   reviewed and approved.
