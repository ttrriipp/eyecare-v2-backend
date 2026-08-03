# Implementation Plan: Optical Transaction Types and Unified Checkout Billing

## Status

Approved by the project owner on 2026-08-03. Phase 2 (**Plan**) of the
spec-driven workflow is complete. Phase 3 (**Tasks**) remains subject to
project-owner review before application-code changes are authorized.

## Overview

Adapt the canonical Quotation, Job Order, Billing Record, Encounter, payment,
and patient-eyewear paths to support product-only, service-only, and mixed
transactions without introducing a Services catalog.

The implementation will make each commercial line explicitly a product or
service, snapshot all charges into canonical Billing Record items, allow
Encounter and Optical Order charges to share one checkout bill before payment,
and add immediate versus prepared fulfillment. Existing status values, payment
records, routes, and patient eyewear envelopes remain compatible.

## Current State and Gaps

1. `quotation_items` and `job_order_items` contain descriptions and optional
   product/lens references but do not identify product versus service lines.
2. `AcceptAndStartOpticalOrder` always creates a queued Job Order and one
   Billing Record tied to that Job Order.
3. `billing_records.job_order_id` is required and unique, so an Encounter-only
   bill and a corrected reissue for a voided Job Order bill are impossible.
4. Billing Records do not own item snapshots. Their admin detail page reads
   `jobOrder.items`, which cannot represent Encounter-originating charges.
5. The Encounter page has no separate Charges section or billing action.
6. Every Job Order follows the prepared-work lifecycle, and supplier invoice is
   universally required before Ready for Pickup.
7. The patient eyewear aggregate uses the Billing Record total and balance but
   exposes only Quotation/Job Order items, so a future Combined bill needs an
   explicit distinction between the Optical Order total and the overall
   checkout payment figures.

## Architecture Decisions

### 1. Typed transaction lines without a catalog

Persist `item_type` on canonical Quotation and Job Order items using controlled
values:

```text
product | service | legacy_other
```

- New forms may create only `product` or `service`.
- Product variant and lens-category selections always create `product` lines.
- A Service line is free text with quantity and unit price.
- `legacy_other` is migration-only for historical custom lines whose meaning
  cannot be inferred safely.
- Product-only, service-only, or mixed is derived from the line set; no
  aggregate transaction-type column is added.

### 2. Canonical Billing Record item snapshots

Add `billing_record_items` as the authoritative itemized financial snapshot:

```text
billing_record_id
item_type
description
quantity
unit_price
amount
job_order_item_id    nullable
encounter_id         nullable
timestamps
```

An item originates from either a Job Order item or an Encounter. A composite
uniqueness rule on Billing Record plus Job Order item makes optical snapshotting
idempotent while allowing the same Job Order item to appear on a later corrected
reissue after the previous bill is voided.

Billing Records gain `subtotal_amount` and `discount_amount`; all totals are
recalculated from their item snapshots. An accepted Quotation supplies the
checkout discount. Encounter charge prices are entered at their final
transaction values and do not overwrite that discount.

### 3. Billing Record as checkout, not exclusive source child

Make `billing_records.job_order_id` nullable and non-unique. Keep
`encounter_id` nullable. At least one source must be populated, while both are
valid for a Combined bill.

Relationships change to preserve financial history:

- `JobOrder::billingRecords()` is a history relationship.
- `JobOrder::activeBillingRecord()` resolves the non-voided record.
- `Encounter::billingRecords()` exposes Encounter and Combined bills.
- `BillingRecord::items()` owns immutable snapshots.

Application validation keeps at most one non-voided Billing Record for a Job
Order. Historical voided records remain queryable.

### 4. Open same-checkout resolution

Introduce one domain action that resolves the bill to receive a new confirmed
source:

1. Lock candidate Billing Records for the Patient and Encounter.
2. Reuse a non-voided, unpaid record only when it has no posted payments and no
   conflicting Job Order.
3. Otherwise create a new Billing Record.
4. Validate that Patient, Encounter, and Job Order ownership agree.
5. Append the new source items idempotently and recalculate totals inside the
   same transaction.

Optical Order confirmation and Encounter charge entry both call this action.
Whichever source is confirmed first creates the bill; the other may join it
until the first posted payment. Payment recording is therefore the charge-set
lock boundary. Every admin path that can post the first payment or deposit must
show the current charge summary, warn that the charge set will be finalized,
and require explicit staff acknowledgement. Later payments do not repeat the
warning.

### 5. Optical confirmation remains the transaction boundary

Refactor `AcceptAndStartOpticalOrder` rather than introducing another sale
aggregate. Its transaction will:

1. lock and confirm the Quotation;
2. create the typed Job Order snapshot once;
3. commit only catalog-backed product inventory once;
4. resolve or create the same-checkout Billing Record;
5. snapshot Job Order items into Billing Record items;
6. recalculate subtotal, discount, total, and balance;
7. convert a selected frame reservation;
8. when a deposit is entered, require acknowledgement of the charge-set warning
   and record it last, after every charge in that confirmation is present;
9. complete the chosen fulfillment path;
10. audit the resulting records.

Repeated confirmation returns the existing Job Order and its active Billing
Record without duplicating items, inventory movements, payments, or reservation
conversion.

### 6. Fulfillment mode uses existing statuses

Add Job Order metadata:

```text
fulfillment_mode        immediate | prepared
uses_external_supplier  boolean
```

- Existing Job Orders backfill to `prepared` and external-supplier enforcement
  remains enabled for compatibility.
- **Prepare for pickup** creates the existing `queued` state and follows
  `queued -> in_progress -> ready_for_dispensing -> dispensed`.
- **Complete sale now** completes the Job Order atomically without artificial
  Processing and Ready steps, while setting the existing timestamps.
- A physical product completed immediately receives a Dispensing Event so
  downstream rating and handover behavior remains available.
- A service-only immediate transaction does not create a meaningless physical
  Dispensing Event.
- Supplier invoice is required before Ready only when
  `uses_external_supplier` is true.
- User-facing copy uses **Processing**, **Ready for Pickup**, and **Completed**;
  persisted status values remain unchanged.

### 7. Encounter Charges stays outside the clinical wizard

Add a separate Charges section to the Encounter edit page. It will:

- show service lines already present in the linked Optical Order for staff
  comparison;
- show the current open or active checkout Billing Record summary;
- provide **Add charges to billing** using free-text Service rows;
- create an Encounter-only bill or reuse the same-checkout Optical bill;
- remain available during an in-progress or completed Encounter;
- never run automatically during Encounter completion.

The action validates descriptions, positive quantities, non-negative prices,
due-date requirements, Patient ownership, and the no-posted-payment lock. With
no Services catalog, semantic duplicate detection is deliberately not attempted;
the UI makes existing order Service lines visible so staff can avoid duplicate
manual entry.

### 8. Billing & Payments becomes source-neutral

Update the existing resource rather than adding another navigation item:

- derive Source as Encounter, Optical Order, or Combined;
- show both applicable references;
- summarize canonical Billing Record items;
- filter by derived source and payment status;
- render grouped item snapshots instead of `jobOrder.items`;
- retain due-date, payment, correction, and void actions;
- show the current itemized total and require acknowledgement of the charge-set
  finalization warning before the first payment; keep later payments concise;
- query the active Billing Record explicitly wherever older code assumes a
  unique Job Order bill.

### 9. Patient API changes are additive

Keep current `/api/v1/eyewear` routes and top-level fields. The patient-facing
contract remains order-focused:

- item descriptions and fulfillment status come only from the patient-owned
  Quotation and Job Order;
- the top-level `total_amount` remains the Optical Order total rather than the
  total of an attached Combined Billing Record;
- payment status, amount paid, balance, and due date may come from the active
  Billing Record and therefore represent the overall checkout when the bill is
  Combined;
- `payment_summary` identifies that scope explicitly and may expose aggregate
  checkout figures, but it does not expose Encounter-originating line items.

The additive summary shape is:

```json
{
  "scope": "overall_checkout",
  "billing_record_number": "BIL-2026-000123",
  "other_clinic_charges_amount": "800.00",
  "checkout_total_amount": "5250.00",
  "amount_paid": "2000.00",
  "balance_due": "3250.00",
  "payment_due_date": "2026-08-31"
}
```

`scope` is present when the attached Billing Record is Combined so the mobile
client can label the balance as **Overall checkout balance**, not as the
eyewear-order balance. Existing Optical-only records retain their current
semantics. `other_clinic_charges_amount` is derived from Encounter-originating
Billing Record items and reconciles the order total to the checkout total under
the approved discount rules. It is a monetary aggregate only; Encounter item
descriptions, counts, origin identifiers, findings, histories, prescriptions,
measurements, supplier references, and internal notes remain unexposed.
Encounter-only bills remain unreachable because the eyewear query still starts
from patient-owned Quotations and Job Orders.

## Data Migration Strategy

Use additive deployed migrations with explicit reconciliation guards:

1. Add nullable `item_type` columns.
2. Backfill product- or lens-backed rows to `product`.
3. Backfill unreferenced custom rows to `legacy_other` without inspecting free
   text.
4. Assert no null classifications, then make the columns required.
5. Add fulfillment metadata and backfill existing Job Orders conservatively.
6. Create Billing Record items and add Billing Record summary columns.
7. Backfill each existing Billing Record from its Job Order items.
8. Set `subtotal_amount` to the item sum and derive the historical discount as
   `max(subtotal_amount - total_amount, 0)`; abort on irreconcilable totals
   rather than silently changing balances.
9. Make `job_order_id` nullable, replace its unique constraint with an index,
   and preserve both source IDs already stored on combined-context records.
10. Add the at-least-one-source check where supported.

Migration tests will verify row counts, totals, payment balances, due dates,
source links, idempotent item snapshots, and safe rollback structure. Inactive
legacy commerce tables are not migration sources.

## Implementation Order

### Wave 1: Characterization and schema foundation

- Characterize current confirmation, inventory, payment, cancellation,
  dispensing, and eyewear behavior before changing it.
- Add guarded item classification, fulfillment, Billing Record summary, and
  Billing Record item schema.
- Update models, relationships, factories, and enum/value helpers.

**Checkpoint:** migrations run cleanly; historical canonical records reconcile;
existing focused tests remain green after compatibility model updates.

### Wave 2: Typed Optical Order vertical slice

- Update Quotation create/edit actions and Filament item entry to persist typed
  product and free-text Service lines.
- Derive transaction type for tables and details.
- Preserve draft/presented edit rules and accepted locking.

**Checkpoint:** product-only, service-only, mixed, and legacy display cases pass
domain and Filament tests without changing confirmation behavior yet.

### Wave 3: Unified billing and optical confirmation

- Build same-checkout resolution, item append, and total recalculation actions.
- Refactor Optical Order confirmation onto those actions.
- Replace unique-bill assumptions with active/history relationships.
- Add the reviewed-charge acknowledgement before confirmation records a
  positive deposit.
- Prove concurrency, idempotency, cancellation, payment, and void/reissue
  behavior.

**Checkpoint:** confirming any transaction shape produces one Job Order and one
reconciled active Billing Record; repeated calls produce no duplicates.

### Wave 4: Fulfillment behavior

- Add confirmation choices and conditional supplier UI.
- Implement immediate completion and prepared fulfillment with existing status
  values.
- Preserve product inventory, reservation conversion, dispensing, and rating
  prerequisites.

**Checkpoint:** immediate service, immediate product, retained repair, and
external-lab eyewear paths each pass end-to-end tests.

### Wave 5: Encounter and Combined checkout slice

- Add the separate Encounter Charges section and domain action.
- Create Encounter-only bills and reuse open same-checkout Optical bills.
- Show linked Optical Service lines and prevent appending after payment.
- Make the open-versus-locked charge state visible before staff proceeds to
  payment.

**Checkpoint:** Encounter-first and Optical-first flows converge on one bill;
post-payment charges create a new bill; Encounter completion remains independent.

### Wave 6: Financial UI and patient contract

- Make Billing & Payments source-neutral and itemized.
- Add source filters, grouped detail, and history-safe links.
- Add the first-payment finalization warning to Billing & Payments and every
  Optical Order payment entry point.
- Keep the patient eyewear payload order-focused and add explicit overall
  checkout scope plus the privacy-safe Other clinic charges aggregate to
  Combined payment summaries.
- Update the explicit API and backend context documentation after behavior is
  verified.

**Checkpoint:** staff can operate and reconcile all three source contexts;
linked patients see their Optical Order details and a clearly labelled overall
checkout balance reconciled by a generic Other clinic charges amount, without
receiving Encounter charge descriptions, counts, clinical data, or supplier
data. Staff receive a clear warning before the first payment locks charges.

### Wave 7: Full conformance and cleanup

- Run targeted suites after every slice and the full suite at the final
  checkpoint.
- Run Pint after PHP changes and build frontend assets after Filament changes.
- Audit remaining direct `BillingRecord::where('job_order_id', ...)` and
  `jobOrder.items` billing assumptions.
- Remove only obsolete code made redundant by this implementation; do not
  delete inactive legacy tables without separate approval.

## Dependency Graph

```text
Characterization tests
    -> guarded schema and backfill
        -> models, relationships, factories
            -> typed Optical Order entry
            -> checkout billing actions
                -> Optical confirmation refactor
                    -> fulfillment choices
                -> Encounter Charges action
                    -> Combined Billing UI
                    -> additive patient API
                        -> full regression and docs
```

Schema, model, and core action work must be sequential. After the billing action
contract is stable, Filament source-neutral display and additive API
serialization are independent consumers, but this repository should still land
them incrementally to keep each checkpoint reviewable.

## Verification Checkpoints

Use focused Sail commands during implementation:

```text
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords
vendor/bin/sail artisan test --compact tests/Feature/Encounters
vendor/bin/sail artisan test --compact --filter=BillingRecordResource
vendor/bin/sail artisan test --compact --filter=Eyewear
```

Final gate:

```text
vendor/bin/sail artisan migrate --no-interaction
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
```

Before implementation, installed-version Laravel and Filament documentation
must be searched for migration constraint changes, transactional locking,
relationship patterns, Filament modal repeaters, schema actions, and resource
testing.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Financial backfill does not reconcile with historical totals | High | Guard counts and sums; abort migration instead of altering balances silently. |
| Two concurrent sources create separate checkout bills | High | Lock candidate records and perform resolve-plus-append in one transaction. |
| Repeated confirmation duplicates billing or inventory | High | Composite item idempotency, existing inventory guards, row locks, and repeated-call tests. |
| Removing Job Order bill uniqueness breaks callers | High | Add active/history relationships first and audit every direct lookup before dropping the constraint. |
| Deposit is recorded before Encounter charges are added | Medium | Show the current charge summary, warn that the first payment finalizes charges, require acknowledgement, and make later charges start a new bill. |
| Free-text services are entered twice | Medium | Show linked order Service lines on the Encounter card; do not use unsafe text matching. |
| Immediate physical sale loses dispensing/rating eligibility | Medium | Create a Dispensing Event for immediate orders containing physical products. |
| A Combined balance is mistaken for an eyewear-only balance on mobile | Medium | Keep the order total separate, add `scope: overall_checkout` and `other_clinic_charges_amount`, use explicit client copy, and test reconciliation without Encounter detail leakage. |
| Existing dirty worktree changes overlap implementation | Medium | Reinspect status before every wave and preserve unrelated user changes. |

## Phase 2 Approval Gate

Approval of this plan authorizes Phase 3 (**Tasks**) only. It does not authorize
application-code implementation. The task breakdown will keep each task within
approximately five files, provide acceptance criteria and focused Sail test
commands, and stop for project-owner approval before Phase 4.
