# Implementation Plan: Commerce Model Simplification

**Status:** ✅ Implemented 2026-08-13
**Specification:** `docs/specs/commerce-model-simplification-spec.md`
**Checklist:** `tasks/commerce-model-simplification-todo.md`
**Implementation:** Complete — all 22 tasks landed, `96bf3b6`..`3b8c3ec`
**Scope:** 22 tasks in 10 phases

## Overview

Eight subtractions across quotations, optical orders, products, inventory,
billing, and ratings. Unlike the reservation project, this touches **running
code with live consumers** throughout — every phase must leave a working system.

Baseline at planning time:

| Area | Now |
|---|---|
| `app/Actions/Quotations/` | 7 actions, 1,126 lines |
| `app/Actions/OpticalOrders/` | 4 actions, 724 lines |
| `app/Actions/JobOrders/` | 3 actions, 423 lines |
| `app/Actions/BillingRecords/` | 11 actions, 996 lines |
| Order creation paths | 5 actions, 986 lines |

Already satisfied by the shipped reservation work:
`CommitJobOrderInventory::$excludeProductVariantIds` is gone,
`ApplyQuotationFrameReservationSelection` and
`ValidateQuotationFrameReservation` are deleted, and `frame_reservation_id` is
dropped from both `quotations` and `job_orders`. The spec's references to those
are already done.

## Architecture Decisions

### 1. Remove consumers before collapsing what they consume

The patient quotation API filters on `presented`. Collapsing the status enum
while that filter exists means writing code twice. So the API goes first
(Phase 1), then the lifecycle (Phase 2). The same logic puts the lifecycle
before order-creation consolidation: `ConfirmQuotationSale` reads quotation
status, so consolidating it against final statuses avoids a rewrite.

### 2. Simplify `CommitJobOrderInventory` before consolidating its callers

Lot removal changes that action's signature (`$selectedLotIds` disappears).
Doing it *after* the order consolidation would mean the new unified paths pass
a parameter and then stop passing it. Lots therefore come first (Phase 3),
so the riskiest phase — order consolidation — is written against a settled,
simpler inventory API.

### 3. Order consolidation is five tasks, not one

Folding four actions into one is L-sized and would violate the ~5-file rule.
It is split into: extract the shared collaborator, build the quotation path,
fold in the fulfillment variants, rework the direct path, then remove
`eyewear_key`. Each step leaves the suite green.

### 4. Billing consolidation follows order consolidation

`AppendJobOrderItemsToBillingRecord` is called from the order paths. Collapsing
billing first would mean rewiring call sites that Phase 4 is about to rewrite.

### 5. The droppable phase contains everything that depends on `item_type`

The spec flags Phase 7 (`item_type`, ~77 references) as the least valuable.
The legacy work also splits along that line: `products.lens_category_id` and the
`lens` product type are independent, but `legacy_other` and the `QuotationItem`
boot-hook defaulting only die *with* `item_type`.

So the independent legacy work is its own phase (7) and everything
`item_type`-dependent is grouped into the final phase (8). Phase 8 can be
dropped whole without stranding half a cleanup.

### 6. Characterization-first, because this is subtraction

The dominant risk is deleting behavior something still needs. Before removing
any consolidated action, confirm a test covers the behavior being folded into
its replacement — and write one if not. This matters most for the three
behaviors that are easy to lose silently: a service-only quotation creating no
order, immediate versus prepared fulfillment, and double-confirmation
idempotency.

### 7. Phase 0 front-loads the risk that ordering cannot

Good practice puts high-risk work first so failure is cheap. Here the dependency
graph forbids it: order consolidation needs the quotation lifecycle and the
simplified inventory commit to land first, so it cannot move earlier without
being written twice.

Phase 0 resolves the tension by moving the *discovery* rather than the work.
It writes the characterization tests for all five current order-creation paths
before anything is deleted. If that behavior turns out to be more tangled than
this plan assumes, it surfaces on day one — and the tests are required by
Task 8 regardless, so the phase costs nothing beyond sequencing.

A surprise in Phase 0 is a signal to re-scope Phase 4, not to push through.

## Dependency Graph

```text
Phase 0  Characterize order creation (no production change)
              │   ← surprises here re-scope Phase 4
              ▼
Phase 1  Patient quotation API removed
              │
Phase 2  Quotation lifecycle → 3 statuses
              │
Phase 3  Inventory lots removed ────┐
              │                      │
              ▼                      ▼
Phase 4  Order creation 5 → 2 paths (uses simplified commit)
              │
Phase 5  Billing append 5 → 1 path
              │
              ├────────────► Phase 6  Ratings (independent — may run anytime)
              │
Phase 7  Legacy: products.lens_category_id, lens product type
              │
Phase 8  item_type + legacy_other + boot defaulting   ← droppable
              │
Phase 9  Documentation
```

## Task List

### Phase 0: De-risk

- [ ] Task 0: Characterize order-creation behavior

### Phase 1: Patient API

- [ ] Task 1: Remove the patient quotation endpoints and the rating alias

### Phase 2: Quotation lifecycle

- [ ] Task 2: Collapse `QuotationStatus` to three cases
- [ ] Task 3: Update Filament quotation tabs, filters, and columns

### Checkpoint: Quotations

- [ ] `/quotations` returns 404; `/optical-orders` unchanged
- [ ] A draft can be accepted or declined; a past-`valid_until` draft can still
      be accepted
- [ ] Full suite green

### Phase 3: Inventory lots

- [ ] Task 4: Remove lot allocation from inventory commit and cancellation
- [ ] Task 5: Remove lot receiving, reconciliation, and the relation manager
- [ ] Task 6: Drop `inventory_lots` and `inventory_movements.inventory_lot_id`

### Checkpoint: Inventory

- [ ] Committing and cancelling a contact-lens order moves aggregate stock
      correctly with no lot reference
- [ ] `migrate:fresh --seed` succeeds
- [ ] Full suite green

### Phase 4: Order creation

- [ ] Task 7: Extract the shared order-building collaborator
- [ ] Task 8: Build `CreateOpticalOrderFromQuotation`
- [ ] Task 9: Fold immediate and accept-and-start fulfillment into it
- [ ] Task 10: Rework `CreateDirectOpticalOrder` onto the collaborator
- [ ] Task 11: Remove `eyewear_key`

### Checkpoint: Orders

- [ ] Exactly two creation actions remain
- [ ] Confirming the same quotation twice creates one order and commits
      inventory once
- [ ] A service-only accepted quotation creates no order
- [ ] Immediate and prepared fulfillment both work
- [ ] Full suite green

### Phase 5: Billing

- [ ] Task 12: Build `AddChargesToBilling` and delete the five append actions
- [ ] Task 13a: Rewire the order-path call sites
- [ ] Task 13b: Rewire the encounter and direct-service call sites

### Checkpoint: Billing

- [ ] All four source kinds append through one action
- [ ] Charge set still locks on first payment; overpayment still rejected
- [ ] Full suite green

### Phase 6: Ratings

- [ ] Task 14: Drop rating revision history
- [ ] Task 15: Fix the hidden-rating aggregate bug

### Phase 7: Legacy (independent)

- [ ] Task 16: Drop `products.lens_category_id` and the `lens` product type

### Phase 8: `item_type` (droppable)

- [ ] Task 17a: Add `CommercialItemKind` helpers and rewrite the model scopes
- [ ] Task 17b: Rewrite the remaining `item_type` readers
- [ ] Task 18: Drop `item_type`, `TransactionItemType`, and legacy defaulting

### Phase 9: Documentation

- [ ] Task 19: Update `BACKEND_CONTEXT.md` and `API_CONTRACT.md`

### Checkpoint: Complete

- [ ] `grep -ri "eyewear_key\|InventoryLot\|TransactionItemType\|legacy_other"`
      returns only historical spec files
- [ ] `migrate:fresh --seed` succeeds
- [ ] Full suite green, Pint clean
- [ ] Ready for review

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Consolidating 4 order paths loses an edge case | High | Phase 0 pins all five paths' behavior before anything is deleted; the three named behaviors are re-asserted at the Phase 4 checkpoint |
| Double-confirmation creates two orders after the `eyewear_key` guard moves to `quotation_id` | High | Task 11 keeps the idempotency test as its acceptance criterion; the guard moves before the column drops |
| Billing rewire breaks the charge-set lock | High | Phase 5 changes only *where* lines come from, never the lock or totals logic; existing payment tests must pass untouched |
| Contact-lens stock goes wrong without lots | Medium | Phase 3 checkpoint asserts commit and cancel round-trip on a contact-lens variant |
| Phase 8's 77 references cause wide breakage | Medium | Sequenced last and droppable; split 17a/17b so models and callers move separately, both before Task 18 drops the column |
| Rating bug fix changes an aggregate a test asserts | Low | Task 15 updates the assertion deliberately — the old behavior was the bug |
| A dropped column is still referenced by a factory or seeder | Low | Every checkpoint runs `migrate:fresh --seed` |

## Parallelization

Phases 1→2 and 3→4→5 are strictly sequential. **Phase 6 (ratings) is fully
independent** and can run at any point, including alongside another phase —
it shares no file with the rest. Phase 7 is independent of everything except
Phase 8.

## Open Questions

None blocking. Two items are settled during implementation rather than now:
`ReceiveContactLensStock`'s disposition (fold or delete, decided in Task 5 by
reading the generic restock path), and whether Phase 8 is worth its cost
(decided after Phase 7 lands).
