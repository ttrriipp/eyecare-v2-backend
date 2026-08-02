# Implementation Plan: Simplified Optical Orders and Billing

## Status

Approved by the project owner on 2026-08-02. Phase 2 (**Plan**) of the
spec-driven workflow is complete. It implements the approved
`docs/specs/simplified-optical-orders-and-billing-spec.md` without expanding
scope. Phase 3 tasks require project-owner approval before application-code
changes begin.

## Overview

Refactor the existing Quotation -> Quotation Revision -> Job Order -> Billing
Record workflow into one staff-facing Optical Orders process while retaining
the existing domain tables that still provide useful boundaries. Quotations
will own their current items and totals directly, accepted Quotations will
create one Job Order and one Billing Record, and linked patients will continue
using the existing eyewear aggregate API with additional paid, balance, and
payment-due-date information.

The implementation prioritizes reuse:

- adapt `OpticalOrderResource` rather than create a new aggregate resource;
- retain current persisted status values;
- reuse supplier invoice, lens category, payment, reservation, inventory, and
  eyewear-key structures;
- preserve current API routes and response envelopes;
- remove the revision architecture after a verified backfill rather than keep
  it as permanent legacy.

## Approved Inputs

- Specification:
  `docs/specs/simplified-optical-orders-and-billing-spec.md`
- Current database inspection on 2026-08-02:
  - 1 Quotation;
  - 1 Quotation Revision;
  - 1 Job Order linked to that revision;
  - maximum 1 revision per Quotation.
- Existing unrelated changes are present in Appointment and Encounter files.
  They belong to the user and must remain untouched unless an approved task
  cannot be completed without a carefully merged change.

## Architecture Decisions

### Quotation is the UI aggregate root

`OpticalOrderResource` remains backed by `Quotation`. Every direct sale still
creates a Quotation internally, but may move from Draft to Accepted in the same
staff interaction. This preserves the stable `eyewear_key`, avoids a new table,
and lets one Filament row represent the entire lifecycle.

Add these direct Quotation relationships:

```text
Quotation hasMany QuotationItem
Quotation hasOne JobOrder
JobOrder belongsTo Quotation
JobOrder hasOne BillingRecord
```

### Existing statuses remain persisted

No enum expansion is required:

```text
Quotation: draft, presented, accepted, declined, expired
Job Order: queued, in_progress, ready_for_dispensing, dispensed, cancelled
Billing: unpaid, partially_paid, paid, voided
```

Filament applies the approved labels such as **Awaiting Decision**,
**Confirmed**, **In Production**, **Ready for Pickup**, and **Completed**.

### Additive transition, then cleanup

Use two new migrations in the same feature:

1. **Transition migration:** add direct fields and foreign keys, backfill from
   the latest revision, and add `payment_due_date`. Legacy revision columns
   remain temporarily so the application can be switched without an
   intermediate broken state.
2. **Cleanup migration:** after model/action/API tests use only the direct
   relationships, enforce direct constraints and remove the revision foreign
   keys/table.

This is slightly more structured than a single destructive migration but is
cheaper to diagnose and safer to roll back. No legacy revision structure
remains after the feature is complete.

### One canonical confirmation transaction

Refactor `AcceptAndStartOpticalOrder` into the only staff confirmation
orchestrator. It will use focused existing collaborators where useful and own
the transaction boundary that:

```text
lock Quotation
-> validate items/prescription/due date
-> accept Quotation
-> create Job Order + copy items
-> commit inventory/convert reservation
-> create Billing Record
-> optionally post initial payment
-> audit
```

`CreateJobOrder` must not remain as a competing public workflow after callers
are migrated. Keeping two creation paths is the primary source of duplicate
inventory and billing risk in the current implementation.

### Billing begins at confirmation

`DispenseJobOrder` changes from “dispense and create Billing Record” to
“dispense against the existing active Billing Record.” It continues creating
the dispensing event and may record a pickup payment, but it must never create
a second Billing Record.

### Patient API remains an adapter

`/api/v1/eyewear` remains the canonical mobile contract. The aggregate builder
uses direct Quotation items before confirmation and Job Order items after
confirmation. Legacy Quotation responses may synthesize the current single
`revision` response object for compatibility, but there is no corresponding
revision model or table.

## Dependency Graph

```text
Baseline characterization
        |
        v
Additive schema + deterministic backfill
        |
        v
Direct Eloquent relationships and factories
        |
        +--------------------+
        |                    |
        v                    v
Quotation draft flow   Billing due-date rules
        |                    |
        +----------+---------+
                   v
        Canonical confirmation transaction
                   |
        +----------+-----------+
        |                      |
        v                      v
Filament Optical Orders   Patient eyewear API
        |                      |
        +----------+-----------+
                   v
       Revision cleanup migration/code removal
                   |
                   v
       Full regression + documentation reconciliation
```

## Implementation Sequence

### Phase A: Baseline and migration safety

Purpose: preserve evidence of existing behavior and make data movement
verifiable before changing domain reads.

Plan:

- Capture focused baseline results for Quotation, Job Order, Billing Record,
  Eyewear aggregate, inventory, and Filament tests.
- Record database reconciliation queries for:
  - Quotation/revision counts;
  - latest revision per Quotation;
  - item totals versus revision totals;
  - Job Order-to-Quotation ownership and eyewear-key equality;
  - Billing Record-to-Job Order ownership and totals.
- Generate the transition migration through Sail.
- Add direct Quotation totals and lifecycle actor/timestamp fields.
- Add nullable `quotation_items.quotation_id` and
  `job_orders.quotation_id` foreign keys.
- Add nullable `billing_records.payment_due_date`.
- Backfill from each Quotation's highest revision number.
- Add indexes needed by lifecycle list queries.

The migration leaves old revision columns in place temporarily. This is an
implementation bridge, not retained product behavior.

Verification checkpoint A:

- Migration succeeds against a populated database and `migrate:fresh --seed`.
- Every existing Quotation total and item count matches its selected revision.
- Every existing Job Order resolves to the same Quotation, patient, and
  `eyewear_key`.
- Rollback restores the pre-transition schema without losing existing rows.
- Focused migration/model tests pass.

### Phase B: Direct model and draft lifecycle

Purpose: stop new application writes from creating revisions while preserving
the existing status vocabulary.

Plan:

- Move totals, presentation metadata, and confirmation metadata into
  `Quotation` fillable/casts.
- Change `QuotationItem` to belong directly to `Quotation`.
- Add `Quotation::items()` and `Quotation::jobOrder()`.
- Change `JobOrder` to belong directly to `Quotation`.
- Add `BillingRecord` payment-due-date cast and overdue query/accessor behavior.
- Update factories to produce valid direct relationships.
- Refactor `CreateQuotation` to accept a patient plus optional
  Encounter/Prescription context instead of requiring an Encounter as the only
  entry point.
- Add one focused draft-update action that recalculates line amounts, subtotal,
  discount, and total server-side.
- Refactor `PresentQuotation` to write presentation metadata directly.
- Saving changes to a presented Quotation returns it to Draft.
- Record accept/decline/expire decisions directly on the Quotation.

No persisted status rename is performed.

Verification checkpoint B:

- Draft create/edit/present/decision tests pass without creating a revision.
- Totals are calculated from server-validated quantities and unit prices.
- Drafts remain excluded from patient queries.
- Corrective item validation can identify when a prescription gate is needed.
- No new application write references `quotation_revisions`.

### Phase C: Confirmation, inventory, billing, and dispensing

Purpose: establish one atomic commercial-to-operational transition.

Plan:

- Make `AcceptAndStartOpticalOrder` the canonical confirmation action.
- Acquire a `lockForUpdate()` on the Quotation inside `DB::transaction()`.
- Permit confirmation from Draft or Presented.
- Validate ownership, items, corrective prescription, discount/total,
  reservation eligibility, due date, and optional initial payment.
- Create one Job Order by direct `quotation_id` and copy all direct Quotation
  items.
- Reuse existing inventory commitment and frame-reservation conversion logic,
  consolidating it so stock moves exactly once.
- Create one Billing Record with the accepted total and payment due date.
- Post the optional initial payment through `RecordBillingPayment`.
- Add unique constraints/idempotency checks so a repeated confirmation cannot
  duplicate Job Orders, Billing Records, payments, or inventory movements.
- Retire `CreateJobOrder` as an independent entry point after all callers and
  tests use the canonical action.
- Change `DispenseJobOrder` to require/reuse the existing active Billing Record.
- Keep supplier invoice optional during Queued/In Progress and required for the
  transition to Ready.
- Preserve cancellation, inventory reversal, payment correction, and Billing
  void safeguards.

Verification checkpoint C:

- End-to-end Draft-to-Confirmed creates exactly one aggregate.
- Presented-to-Confirmed works without a revision.
- Deposit, balance, status, and due-date rules are correct.
- Confirmation fully rolls back on insufficient inventory, invalid
  reservation, payment validation, or duplicate submission.
- Dispensing never creates a duplicate Billing Record.
- Inventory and payment lifecycle tests pass.

### Phase D: Unified Filament Optical Orders workflow

Purpose: expose the approved one-workspace admin experience without creating a
second resource.

Plan:

- Enable creation on `OpticalOrderResource` and add dedicated Create/Edit pages
  backed by the approved draft actions.
- Reuse/refactor `QuotationCreationForm` fields inside the Optical Orders
  builder rather than maintain two item builders.
- Keep standalone Quotation and Job Order navigation hidden.
- Add Filament list tabs through `ListOpticalOrders::getTabs()`:
  All, Drafts, Awaiting Decision, Confirmed, In Production, Ready for Pickup,
  and Completed.
- Define direct eager-loaded relationships for patient, items/lens categories,
  Job Order, and Billing Record.
- Replace revision columns with direct total/item columns.
- Show patient, reference, stage, lens types, supplier invoice, total, payment
  state, balance, due date, and activity.
- Make the detail page stage-aware with commercial, work-order, and payment
  sections.
- Expose only the relevant next action for each stage.
- Use an action modal for Confirm Sale and its due-date/deposit fields.
- Make `BillingRecordResource` visible under **Finance** as
  **Billing & Payments**.
- Add All, Outstanding, Overdue, Paid, and Voided billing tabs plus the due-date
  column and existing payment relation manager.
- Keep Frame Reservations in the Optical navigation group.

Filament relationship columns can use dot notation, which performs the needed
relationship eager loading; custom derived lens-type state should use one
explicit preloaded relationship to avoid per-row queries.

Verification checkpoint D:

- Filament Livewire tests prove navigation visibility, tab membership, forms,
  validation, actions, and notifications.
- The Optical Orders list performs without N+1 queries for required relations.
- Billing & Payments shows overdue records by derived date/balance rules.
- Production asset build succeeds.
- Real-browser verification confirms desktop and narrow layouts, modal form
  behavior, and absence of console/network errors.

### Phase E: Patient eyewear API adaptation

Purpose: preserve patient tracking while removing all revision reads.

Plan:

- Change `ListPatientEyewear`, `FindPatientEyewear`, and
  `BuildEyewearAggregate` to use direct Quotation items and direct Job Order
  linkage.
- Preserve canonical `eyewear_key`, current/history filters, deterministic
  ordering, and patient ownership checks.
- Add `amount_paid` and `payment_due_date` to the aggregate value object and
  summary/detail resources.
- Continue exposing line items in detail, selecting Quotation items before a
  Job Order exists and Job Order items afterward.
- Preserve existing progress enum values and mobile labels.
- Keep supplier invoice, staff notes, supplier cost, actors, and audit metadata
  excluded.
- Adapt the legacy Quotation resource/controller to compose its existing
  single-revision envelope from direct Quotation fields when compatibility is
  required.
- Leave API routes unchanged.

Verification checkpoint E:

- API contract tests cover estimate, confirmed, production, ready, completed,
  cancelled, paid, partial, overdue, and voided cases.
- Linked-patient scoping and unlinked-account denial remain unchanged.
- Drafts remain invisible.
- Summary/detail money strings, nullability, items, balance, and due date match
  the approved contract.
- Route-contract tests remain unchanged.

### Phase F: Revision removal and reconciliation

Purpose: finish the simplified architecture without permanent legacy cost.

Plan:

- Run the migration reconciliation queries from Phase A against the switched
  application.
- Generate the cleanup migration through Sail.
- Make direct Quotation foreign keys/relationships authoritative and enforce
  their final nullability/uniqueness rules.
- Drop `quotation_items.quotation_revision_id`.
- Drop `job_orders.quotation_revision_id`.
- Drop `quotation_revisions`.
- Delete `QuotationRevision`, its factory, and revision-only tests.
- Remove every active application reference to `latestRevision`,
  `revisions()`, `revision_number`, and `quotation_revision_id` while excluding
  the unrelated Frame Rating revision feature.
- Replace obsolete revision tests with direct-quotation lifecycle tests rather
  than merely deleting coverage.
- Update seeders and end-to-end fixtures.
- Update `docs/BACKEND_CONTEXT.md` and mark superseded specification sections.

Verification checkpoint F:

- Repository search finds no active Quotation Revision references outside
  historical documentation/migrations and explicit API compatibility naming.
- Populated migration path and fresh migration path both pass.
- Focused domain, Filament, API, and end-to-end tests pass.
- Full `vendor/bin/sail artisan test --compact` passes.
- `vendor/bin/sail bin pint --dirty --format agent` produces clean formatting.
- `vendor/bin/sail npm run build` passes.
- Browser logs and application logs contain no new errors from the verified
  workflow.

## Sequential and Independent Work

### Must remain sequential

- Additive schema before direct model relationships.
- Direct draft storage before canonical confirmation.
- Canonical confirmation before Filament actions and patient aggregation.
- API/model switch before revision cleanup.
- Cleanup before final documentation reconciliation.

### Can be developed independently after Phase C

- Filament workspace changes and patient API adaptation touch different
  presentation layers once the model/action contracts are stable.
- Billing list tabs and Optical Orders list tabs are independently testable
  once `payment_due_date` and direct relationships exist.

Because both branches depend on shared models and this repository already has
unrelated local edits, the implementation should still integrate them through
small sequential tasks rather than simultaneous edits to shared files.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Backfill selects the wrong historical revision | High | Select deterministically by highest `revision_number`; assert counts, totals, patient, and eyewear key before cleanup. |
| Confirmation duplicates stock or billing | High | One canonical action, row lock, transaction, unique direct relationship, and duplicate-submission tests. |
| Current dispensing action conflicts with early billing | High | Switch dispensing to require/reuse the existing record before enabling the new UI. |
| Due date is missing on a positive balance | Medium | Domain validation at confirmation and due-date updates; nullable DB field supports paid and migrated records only. |
| Legacy Android response breaks | High | Preserve routes/envelopes and synthesize the single revision-shaped compatibility object from direct data. |
| Supplier invoice leaks through raw serialization | High | Continue model hiding and assert absence in every patient resource test. |
| Optical Orders tabs create expensive queries | Medium | Direct relationships, scoped `whereHas` queries, explicit eager loading, indexes, and query-count characterization. |
| Revision cleanup removes unrelated rating history | High | Target only Quotation revision names; retain `FrameRatingRevision` and its tests. |
| Existing user changes are overwritten | High | Avoid Appointment/Encounter files unless required; inspect and preserve overlapping diffs before every edit. |
| Migration rollback is unreliable | Medium | Test both populated migrate/rollback/migrate and fresh migration paths before cleanup. |

## Verification Strategy

Use vertical checkpoints rather than waiting for the end:

1. **Data checkpoint:** direct fields match legacy revision data.
2. **Domain checkpoint:** one atomic confirmation produces a valid aggregate.
3. **Admin checkpoint:** staff complete the lifecycle from one workspace.
4. **Patient checkpoint:** linked patients see the correct status, items, paid,
   balance, and due date without internal data.
5. **Cleanup checkpoint:** no active revision architecture remains and the full
   suite/build/browser checks pass.

During implementation, each behavior change begins with a focused Pest test.
Only the smallest relevant test files run during the inner loop; the full suite
runs at the final checkpoint.

## Expected Documentation Effects

After implementation:

- `docs/BACKEND_CONTEXT.md` becomes authoritative for the new schema and flow.
- This approved spec remains the product decision record.
- This plan records why the transition used an additive bridge followed by
  cleanup.
- Older revision and billing-at-dispensing specs remain historical but must be
  marked superseded where they could mislead future implementation.
- A separate ADR is unnecessary because the approved spec and plan already
  capture context, alternatives, decision, consequences, and migration path in
  version-controlled documentation.

## Plan Approval Criteria

The plan is ready for Phase 3 task breakdown when the project owner confirms:

- the two-migration transition/cleanup approach;
- `OpticalOrderResource` remains the only primary optical workspace;
- `AcceptAndStartOpticalOrder` becomes the one confirmation transaction;
- Billing Record creation moves to confirmation;
- API routes and compatibility envelopes remain;
- revision models and tables are removed only after backfill verification;
- the implementation sequence and checkpoints are acceptable.

## Open Questions

None. Any implementation discovery that changes scope, data ownership, API
compatibility, payment semantics, or migration safety returns to the
specification before the plan proceeds.
