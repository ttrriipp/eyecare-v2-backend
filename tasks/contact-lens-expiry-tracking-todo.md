# Contact-Lens Expiry Tracking Checklist

The active pointer is `tasks/todo.md`. Execute in dependency order; do not
start a task until its dependency and preceding checkpoint are green.

Status: Implemented and verified on 2026-08-28; final full-suite result is
recorded in the closing checkpoint below.

## Phase 1: Lot Foundation

- [x] Task 1: Establish the lot schema contract.
  - [x] Acceptance criteria satisfied.
  - [x] Schema test and migration verification pass.
- [x] Task 2: Add the lot domain model.
  - [x] Relationships, casts, and scopes are covered.
  - [x] Domain and ledger tests pass.
- [x] Checkpoint: Lot Foundation is green.

## Phase 2: Owner Stock Entry

- [x] Task 3: Receive contact-lens stock by lot.
  - [x] Month-end and conflicting-lot paths are covered.
  - [x] Receipt and Filament regression tests pass.
- [x] Task 4: Write off the physical source lot.
  - [x] Lot/aggregate atomicity and ordinary products are covered.
  - [x] Write-off and characterization tests pass.
- [x] Checkpoint: Stock Entry is green.

## Phase 3: Fulfillment Enforcement

- [x] Task 5: Commit contact-lens orders by multi-lot FEFO.
  - [x] Expired, split-lot, insufficient, and rollback paths are covered.
  - [x] Commitment and atomicity tests pass.
- [x] Task 6: Restore exact source lots on cancellation.
  - [x] Exact and idempotent restoration is covered.
  - [x] Cancellation regression tests pass.
- [x] Checkpoint: Fulfillment is green.

## Phase 4: Owner Inventory Experience

- [x] Task 7: Show expiry on each Inventory row.
  - [x] Status, usable quantity, and read-only batch details are covered.
  - [x] Filament expiry and Inventory tests pass.
- [x] Task 8: Add expiring and expired queues.
  - [x] Tabs, widget counts, and reorder behavior are covered.
  - [x] Filament expiry and regression tests pass.
- [x] Task 9: Seed disposable lot-backed demo stock.
  - [x] Seeded aggregates equal lot sums.
  - [x] Fresh migration and seed succeed.
- [x] Checkpoint: Owner Experience is green.

## Phase 5: Verification and Context

- [x] Task 10: Reconcile canonical context and close the plan.
  - [x] Preserve pre-existing canonical-document changes.
  - [x] Focused suites pass.
  - [x] Pint and `git diff --check` are clean.
  - [x] Full suite completed with 1,789 passing tests and 22 unrelated
    pre-existing failures (6,011 assertions); no contact-lens lot/expiry test
    failed.
- [x] Checkpoint: Complete and ready for human review.
