# Implementation Plan: Contact-Lens Expiry Tracking

## Overview

Add lot-backed expiry enforcement for contact lenses while keeping the owner
workflow inside the existing Inventory screen. Each task is a small, testable
slice and leaves the application in a working state. The active-plan pointer
remains in `tasks/plan.md`, following this repository's multi-project
convention.

## Architecture Decisions

- Lots apply only to contact-lens variants; frames and accessories remain
  aggregate-only.
- `product_variants.stock_quantity` remains physical on-hand quantity and must
  equal the sum of contact-lens lot quantities.
- Usable contact-lens quantity is derived from non-expired lots.
- A commitment writes one movement per consumed lot. This supports multi-lot
  FEFO and exact reversal without another allocation table.
- Development inventory is disposable and will be recreated by the seeder; no
  reconciliation or backfill path is built.
- Patient API resources and routes remain unchanged.

## Dependency Graph

```text
Task 1: Schema contract
    -> Task 2: Domain model
        -> Task 3: Receive stock
            -> Task 4: Write off stock
                -> Task 5: FEFO commitment
                    -> Task 6: Exact reversal
                        -> Task 7: Inventory row experience
                            -> Task 8: Expiry queues and stats
                                -> Task 9: Disposable demo data
                                    -> Task 10: Context reconciliation
```

Tasks 1–6 are sequential because they mutate the same inventory invariant.
Tasks 7–9 are conceptually separable after Task 6, but this execution remains
sequential because they share model queries, Filament tests, and seeded
invariants.

## Phase 1: Lot Foundation

### Task 1: Establish the lot schema contract

**Description:** Write the failing schema test, then add one forward,
reversible migration that recreates `inventory_lots` and restores nullable
`inventory_movements.inventory_lot_id`. Historical migrations remain
untouched.

**Acceptance criteria:**

- [ ] Variant/lot number is unique and lot quantities cannot be negative.
- [ ] Required allocation and expiry indexes exist.
- [ ] Historical and non-contact-lens movements may keep a null lot reference.

**Verification:**

- [ ] RED then GREEN: `vendor/bin/sail artisan test --compact tests/Feature/Inventory/InventoryLotSchemaTest.php`
- [ ] Rollback/forward migration succeeds in the test database.
- [ ] Manual check: Boost schema output shows expected columns and indexes.

**Dependencies:** None

**Files likely touched:**

- `database/migrations/*_recreate_inventory_lots_and_link_movements.php`
- `tests/Feature/Inventory/InventoryLotSchemaTest.php`

**Estimated scope:** Small (2 files)

### Task 2: Add the lot domain model

**Description:** Write model behavior tests, then add `InventoryLot`, its
factory, typed relationships, casts, and explicit expiry/availability scopes.

**Acceptance criteria:**

- [ ] Relationships connect variants, movements, and receiving users.
- [ ] Expiry and quantity values are correctly cast.
- [ ] Available, expired, and expiring-soon scopes agree at date boundaries.

**Verification:**

- [ ] RED then GREEN: `vendor/bin/sail artisan test --compact tests/Feature/Inventory/InventoryLotTest.php`
- [ ] Regression: `vendor/bin/sail artisan test --compact tests/Feature/Inventory/InventoryLedgerTest.php`
- [ ] Format: `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 1

**Files likely touched:**

- `app/Models/InventoryLot.php`
- `database/factories/InventoryLotFactory.php`
- `app/Models/ProductVariant.php`
- `app/Models/InventoryMovement.php`
- `tests/Feature/Inventory/InventoryLotTest.php`

**Estimated scope:** Medium (5 files)

## Checkpoint: Lot Foundation

- [ ] Tasks 1–2 focused tests pass.
- [ ] Existing Inventory ledger tests pass.
- [ ] Migration is reversible and no global expiry scope exists.

## Phase 2: Owner Stock Entry

### Task 3: Receive contact-lens stock by lot

**Description:** Write receipt behavior tests, then route the existing Receive
Stock action through a focused action. Contact lenses require lot number and
expiry month; ordinary products retain existing inputs and behavior.

**Acceptance criteria:**

- [ ] Expiry month normalizes to its final day and rejects an expired month.
- [ ] Receipt atomically updates lot, aggregate, movement, and audit.
- [ ] Reusing a lot with a conflicting expiry changes no stock.

**Verification:**

- [ ] RED then GREEN: `vendor/bin/sail artisan test --compact tests/Feature/Inventory/ReceiveContactLensStockTest.php`
- [ ] Regression: `vendor/bin/sail artisan test --compact tests/Feature/Filament/InventoryResourceTest.php`
- [ ] Manual check: only contact-lens receiving shows lot and expiry inputs.

**Dependencies:** Task 2

**Files likely touched:**

- `app/Actions/Inventory/ReceiveInventoryStock.php`
- `app/Filament/Support/StockActions.php`
- `tests/Feature/Inventory/ReceiveContactLensStockTest.php`
- `tests/Feature/Filament/InventoryResourceTest.php`

**Estimated scope:** Medium (4 files)

### Task 4: Write off the physical source lot

**Description:** Write lot-specific write-off tests, then add a focused action
that decrements the selected contact-lens lot and aggregate atomically. The
field defaults to the earliest-expiring lot with stock.

**Acceptance criteria:**

- [ ] Contact-lens write-off requires an owned lot with sufficient quantity.
- [ ] Lot, aggregate, movement, and audit remain atomic and quantity-safe.
- [ ] Non-contact-lens write-off behavior is unchanged.

**Verification:**

- [ ] RED then GREEN: `vendor/bin/sail artisan test --compact tests/Feature/Inventory/WriteOffContactLensStockTest.php`
- [ ] Regression: `vendor/bin/sail artisan test --compact tests/Feature/Inventory/InventoryAndPrivacyCharacterizationTest.php`
- [ ] Manual check: ordinary products show no lot selector.

**Dependencies:** Task 3

**Files likely touched:**

- `app/Actions/Inventory/WriteOffInventoryStock.php`
- `app/Filament/Support/StockActions.php`
- `tests/Feature/Inventory/WriteOffContactLensStockTest.php`
- `tests/Feature/Filament/InventoryResourceTest.php`

**Estimated scope:** Medium (4 files)

## Checkpoint: Stock Entry

- [ ] Tasks 3–4 focused tests pass.
- [ ] Existing Inventory resource and ledger tests pass.
- [ ] Receipt and write-off preserve the aggregate/lot invariant.

## Phase 3: Fulfillment Enforcement

### Task 5: Commit contact-lens orders by multi-lot FEFO

**Description:** Write failing fulfillment tests, then extend
`CommitJobOrderInventory` to lock usable lots in deterministic
`expires_on, id` order and split an item across lots when necessary.

**Acceptance criteria:**

- [ ] Expired quantities never satisfy an order.
- [ ] Multi-lot FEFO succeeds without staff lot selection.
- [ ] Insufficient usable stock rolls back every related change.

**Verification:**

- [ ] RED then GREEN: `vendor/bin/sail artisan test --compact tests/Feature/JobOrders/CommitContactLensInventoryTest.php`
- [ ] Regression: `vendor/bin/sail artisan test --compact tests/Feature/JobOrders/JobOrderInventoryAtomicTest.php`
- [ ] Manual check: movements identify each consumed lot in FEFO order.

**Dependencies:** Task 4

**Files likely touched:**

- `app/Actions/JobOrders/CommitJobOrderInventory.php`
- `tests/Feature/JobOrders/CommitContactLensInventoryTest.php`

**Estimated scope:** Small (2 files)

### Task 6: Restore exact source lots on cancellation

**Description:** Write cancellation tests, then make reversal restore recorded
lot commitments exactly once. Aggregate-only reversal remains unchanged.

**Acceptance criteria:**

- [ ] Every source lot is restored by its committed quantity.
- [ ] Repeated reversal cannot double-restore stock.
- [ ] Frame/accessory cancellation behavior remains unchanged.

**Verification:**

- [ ] RED then GREEN: `vendor/bin/sail artisan test --compact tests/Feature/JobOrders/RestoreContactLensInventoryTest.php`
- [ ] Regression: `vendor/bin/sail artisan test --compact tests/Feature/JobOrders/JobOrderInventoryTest.php`
- [ ] Manual check: reversal movements retain source lot identifiers.

**Dependencies:** Task 5

**Files likely touched:**

- `app/Actions/JobOrders/UpdateJobOrderStatus.php`
- `tests/Feature/JobOrders/RestoreContactLensInventoryTest.php`

**Estimated scope:** Small (2 files)

## Checkpoint: Fulfillment

- [ ] Tasks 5–6 focused tests pass.
- [ ] Job-order inventory and atomicity suites pass.
- [ ] Receive → commit → cancel preserves every lot and aggregate.

## Phase 4: Owner Inventory Experience

### Task 7: Show expiry on each Inventory row

**Description:** Write Filament tests, then add usable quantity, earliest
expiry, status badges, and a read-only View Batches modal to the Inventory row.

**Acceptance criteria:**

- [ ] Rows distinguish Good, Expiring Soon, and Expired states.
- [ ] View Batches exposes details without mutation actions.
- [ ] Ordinary product rows remain free of contact-lens-only details.

**Verification:**

- [ ] RED then GREEN: `vendor/bin/sail artisan test --compact tests/Feature/Filament/ContactLensExpiryInventoryTest.php`
- [ ] Regression: `vendor/bin/sail artisan test --compact tests/Feature/Filament/InventoryResourceTest.php`
- [ ] Manual check: earliest expiry is understandable from the list.

**Dependencies:** Task 6

**Files likely touched:**

- `app/Filament/Resources/Inventory/Tables/InventoryTable.php`
- `app/Filament/Resources/Inventory/InventoryResource.php`
- `resources/views/filament/inventory/contact-lens-batches.blade.php`
- `tests/Feature/Filament/ContactLensExpiryInventoryTest.php`

**Estimated scope:** Medium (4 files)

### Task 8: Add expiring and expired queues

**Description:** Write tab/widget tests, then add Expiring Soon and Expired
tabs plus concise statistics based on usable lot quantities.

**Acceptance criteria:**

- [ ] Tabs return only matching contact-lens variants with physical stock.
- [ ] Reorder signals use usable contact-lens quantity.
- [ ] Existing inventory tabs remain valid.

**Verification:**

- [ ] RED then GREEN: `vendor/bin/sail artisan test --compact tests/Feature/Filament/ContactLensExpiryInventoryTest.php`
- [ ] Regression: `vendor/bin/sail artisan test --compact tests/Feature/Filament/InventoryResourceTest.php`
- [ ] Manual check: widget counts match filtered tables.

**Dependencies:** Task 7

**Files likely touched:**

- `app/Models/ProductVariant.php`
- `app/Filament/Resources/Inventory/Pages/ListInventory.php`
- `app/Filament/Resources/Inventory/Widgets/InventoryStatsWidget.php`
- `tests/Feature/Filament/ContactLensExpiryInventoryTest.php`

**Estimated scope:** Medium (4 files)

### Task 9: Seed disposable lot-backed demo stock

**Description:** Write a fresh-seed invariant test, then replace seeded
contact-lens aggregate-only quantities with representative lot records.

**Acceptance criteria:**

- [ ] Every seeded nonzero contact-lens variant has lot-backed quantity.
- [ ] Seeded aggregate quantity equals its lot sum.
- [ ] Seed data demonstrates normal and expiring-soon states safely.

**Verification:**

- [ ] RED then GREEN: `vendor/bin/sail artisan test --compact tests/Feature/Inventory/ContactLensInventorySeederTest.php`
- [ ] Fresh database: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] Manual check: Inventory displays coherent demo expiry states.

**Dependencies:** Task 8

**Files likely touched:**

- `database/seeders/CatalogSeeder.php`
- `tests/Feature/Inventory/ContactLensInventorySeederTest.php`

**Estimated scope:** Small (2 files)

## Checkpoint: Owner Experience

- [ ] Tasks 7–9 focused tests pass.
- [ ] Fresh seeding produces only tracked nonzero contact-lens stock.
- [ ] The workflow remains inside the existing Inventory workspace.

## Phase 5: Verification and Context

### Task 10: Reconcile canonical context and close the plan

**Description:** After behavior is proven, update only relevant inventory
sections of the canonical backend context and mark the plan complete. Preserve
the user's pre-existing canonical-document edits; the API contract remains
behaviorally unchanged.

**Acceptance criteria:**

- [ ] Backend context documents schema, expiry semantics, and owner workflow.
- [ ] Patient API contract has no lot or expiry additions.
- [ ] Plan and checklist reflect verified completion rather than intention.

**Verification:**

- [ ] Focused suites: `vendor/bin/sail artisan test --compact tests/Feature/Inventory tests/Feature/JobOrders tests/Feature/Filament/InventoryResourceTest.php tests/Feature/Filament/ContactLensExpiryInventoryTest.php`
- [ ] Format: `vendor/bin/sail bin pint --dirty --format agent`
- [ ] Full suite: `vendor/bin/sail artisan test --compact`
- [ ] Manual review: `git diff --check` and scoped diffs show no API leakage or
      overwritten user work.

**Dependencies:** Task 9

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`
- `docs/specs/contact-lens-expiry-tracking-spec.md`
- `tasks/contact-lens-expiry-tracking-plan.md`
- `tasks/contact-lens-expiry-tracking-todo.md`

**Estimated scope:** Medium (4 files)

## Checkpoint: Complete

- [ ] Every task acceptance criterion is satisfied.
- [ ] Focused and full tests pass, or unrelated failures are evidenced.
- [ ] Pint and `git diff --check` are clean.
- [ ] No task exceeded five files without being split first.
- [ ] Implementation is ready for human review.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Aggregate and lots drift | High | Transactions, row locks, invariant tests |
| One order spans lots | High | Deterministic multi-row FEFO allocation |
| Expiry boundary mismatch | High | One inclusive clinic-local rule |
| Cancellation restores wrong lot | High | Reverse recorded lot movements |
| Owner-facing complexity | Medium | Automatic allocation and read-only details |
| Commerce regression | High | Characterization tests at each checkpoint |
| Canonical-doc overlap | Medium | Preserve existing dirty changes |

## Parallelization

- Tasks 1–6 must remain sequential because they mutate one stock invariant.
- Tasks 7–9 are theoretically separable after Task 6, but sequential work is
  safer because they share queries, Filament tests, and seeded invariants.
- There is no API-contract work to parallelize.

## Open Questions

None. The approved specification controls scope.
