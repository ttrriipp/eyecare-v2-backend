# Contact-Lens Expiry Tracking Checklist

The active pointer is `tasks/todo.md`. Execute in dependency order; do not
start a task until its dependency and preceding checkpoint are green.

## Phase 1: Lot Foundation

- [ ] Task 1: Establish the lot schema contract.
  - [ ] Acceptance criteria satisfied.
  - [ ] Schema test and migration verification pass.
- [ ] Task 2: Add the lot domain model.
  - [ ] Relationships, casts, and scopes are covered.
  - [ ] Domain and ledger tests pass.
- [ ] Checkpoint: Lot Foundation is green.

## Phase 2: Owner Stock Entry

- [ ] Task 3: Receive contact-lens stock by lot.
  - [ ] Month-end and conflicting-lot paths are covered.
  - [ ] Receipt and Filament regression tests pass.
- [ ] Task 4: Write off the physical source lot.
  - [ ] Lot/aggregate atomicity and ordinary products are covered.
  - [ ] Write-off and characterization tests pass.
- [ ] Checkpoint: Stock Entry is green.

## Phase 3: Fulfillment Enforcement

- [ ] Task 5: Commit contact-lens orders by multi-lot FEFO.
  - [ ] Expired, split-lot, insufficient, and rollback paths are covered.
  - [ ] Commitment and atomicity tests pass.
- [ ] Task 6: Restore exact source lots on cancellation.
  - [ ] Exact and idempotent restoration is covered.
  - [ ] Cancellation regression tests pass.
- [ ] Checkpoint: Fulfillment is green.

## Phase 4: Owner Inventory Experience

- [ ] Task 7: Show expiry on each Inventory row.
  - [ ] Status, usable quantity, and read-only batch details are covered.
  - [ ] Filament expiry and Inventory tests pass.
- [ ] Task 8: Add expiring and expired queues.
  - [ ] Tabs, widget counts, and reorder behavior are covered.
  - [ ] Filament expiry and regression tests pass.
- [ ] Task 9: Seed disposable lot-backed demo stock.
  - [ ] Seeded aggregates equal lot sums.
  - [ ] Fresh migration and seed succeed.
- [ ] Checkpoint: Owner Experience is green.

## Phase 5: Verification and Context

- [ ] Task 10: Reconcile canonical context and close the plan.
  - [ ] Preserve pre-existing canonical-document changes.
  - [ ] Focused suites pass.
  - [ ] Pint and `git diff --check` are clean.
  - [ ] Full suite passes or unrelated failures are evidenced.
- [ ] Checkpoint: Complete and ready for human review.
