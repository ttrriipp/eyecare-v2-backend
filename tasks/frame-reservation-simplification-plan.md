# Implementation Plan: Minimal Frame Reservations

**Status:** Approved 2026-08-12
**Specification:** `docs/specs/frame-reservation-simplification-spec.md` (approved 2026-08-12)
**Decision:** `docs/decisions/002-use-clean-break-frame-reservation-contract.md`
**Checklist:** `tasks/frame-reservation-simplification-todo.md`
**Implementation:** Not started

This plan replaces the previous 15-task version, which was written against the
superseded five-status lifecycle. That document is gone; nothing in it carries
forward.

## Overview

Frame Reservations collapse to two states carried by one nullable
`accepted_at` timestamp: a **request** holds nothing, an **accepted**
reservation holds exactly one unit per frame. Everything else — the
`ReservationStatus` enum, try-on, conversion to a sale, closure reasons,
reactivation, release attribution, the stored deadline, and every link between
a reservation and a Quotation or Optical Order — is deleted rather than
migrated.

The work is roughly 60% demolition and 40% rebuild. That ratio drives the
ordering below: the coupling comes out first, so the core is rebuilt against a
codebase where nothing else reaches into it.

## Architecture Decisions

### 1. Remove the sale coupling before touching the reservation core

`ConvertFrameReservationToJobOrder` and `ValidateQuotationFrameReservation`
both reference `ReservationStatus`. The enum cannot be deleted while they
exist, so decoupling is not merely convenient ordering — it is a hard
dependency. It is also the lowest-risk work in the project (pure deletion, no
new behavior), which satisfies the fail-fast preference for putting risk early:
if removing the linkage surfaces an unexpected consumer, that is far cheaper to
learn on day one than after the core is rewritten.

### 2. Two migrations, not one

The spec describes a single logical schema change. The implementation splits it:

- **Migration A** (Task 3) adds `accepted_at`. Purely additive.
- **Migration B** (Task 16) drops `status`, `expires_at`, `released_at`,
  `released_by`, `release_reason`, `deleted_at`, and `frame_reservation_id`
  from both `quotations` and `job_orders`.

Dropping `status` up front would red the entire suite for the length of the
project, because every action, factory, resource, and test still references it.
Splitting keeps every checkpoint green and lets each task be verified on its
own.

This is **not** the expand/migrate/contract pattern ADR-002 rejected. ADR-002
rejected staged rollout for *production data compatibility*; both migrations
here ship in the same release, and neither preserves an obsolete value. This
split exists only to keep the build working between commits.

### 3. One stock collaborator, five actions

`FrameReservationStock` owns `allocate()` and `release()`. Every allocation and
release in the system goes through it, so lock order and movement shape cannot
drift between the five actions. Building it before the actions (Task 4) means
each subsequent action is a thin, mostly-validation wrapper.

### 4. Acceptance is one-way, so there are no transitions to model

Deletion is the release. This removes an entire category of task — no
un-accept, no state machine, no transition table, no guards on reverse edges.
It is why Phase 3 is four small actions rather than a lifecycle module.

### 5. Test-first per action, because inventory is the risk

Every task in Phase 3 writes its Pest expectations before the behavior. Stock
correctness is the one thing in this project that fails silently, and the
allocate/release symmetry is exactly what a test catches and a reading does
not.

## Dependency Graph

```text
Task 1  Quotation linkage removal ─┐
Task 2  Optical Order linkage removal ─┴─→ unblocks enum deletion
                                              │
Task 3  Migration A: add accepted_at ─────────┤
                                              ▼
Task 4  FrameReservationStock ────────────────┤
                                              ▼
                  ┌───────────────────────────┼──────────────────┐
                  ▼                           ▼                  ▼
        Task 5 Create             Task 6 Accept          Task 7 Delete
                  └───────────────────────────┼──────────────────┘
                                              ▼
                                    Task 8 Add/Remove item
                                              │
                        ┌─────────────────────┼─────────────────────┐
                        ▼                     ▼                     ▼
              Task 9 Appointment      Task 10 Sweep        Task 11 API
                        └─────────────────────┼─────────────────────┘
                                              ▼
                        Task 12/13 Filament · Task 14 Policy
                                              ▼
                     Task 15 Dead code sweep → Task 16 Migration B
                                              ▼
                                      Task 17 Documentation
```

## Task List

### Phase 1: Decouple

- [ ] Task 1: Remove reservation linkage from the Quotation actions
- [ ] Task 2: Remove reservation conversion from the Optical Order path

### Checkpoint: Decoupled

- [ ] `tests/Feature/Quotations` and `tests/Feature/OpticalOrders` pass
- [ ] No file outside `app/{Actions/Reservations,Models,Filament,Http}` and
      `app/Console` references `FrameReservation`
- [ ] Full suite green

### Phase 2: Foundation

- [ ] Task 3: Migration A — add `accepted_at`; rewrite model and factory
- [ ] Task 4: Build the `FrameReservationStock` collaborator

### Checkpoint: Foundation

- [ ] `accepted_at` exists and casts to a datetime; `isHeld()` returns correctly
- [ ] Allocation and release each write exactly one movement per unit
- [ ] Full suite green

### Phase 3: Domain Actions

- [ ] Task 5: Rewrite `CreateFrameReservation` — no stock, no window
- [ ] Task 6: Replace `PrepareFrameReservation` with `AcceptFrameReservation`
- [ ] Task 7: Replace `ReleaseFrameReservation` with `DeleteFrameReservation`
- [ ] Task 8: Gate `AddFrameReservationItem` / `RemoveFrameReservationItem` on held state

### Checkpoint: Domain Actions

- [ ] `tests/Feature/Reservations` passes
- [ ] Request → accept → add → remove → delete leaves stock exactly as it
      started, verified against `inventory_movements`
- [ ] `app/Actions/Reservations/` contains five actions plus the collaborator

### Phase 4: Integration

- [ ] Task 9: Rewire appointment cancellation, no-show, and reschedule
- [ ] Task 10: Rewrite the sweep as `ExpireFrameReservations`

### Checkpoint: Integration

- [ ] `tests/Feature/Appointments` passes
- [ ] Cancelling an appointment with an accepted reservation restores stock in
      one transaction; rescheduling moves no stock at all

### Phase 5: Surfaces

- [ ] Task 11: Rewrite the patient API — `DELETE`, `is_held`, derived `expires_at`
- [ ] Task 12: Rewrite the Frame Reservations Filament resource
- [ ] Task 13: Rewrite the two Appointment relation managers
- [ ] Task 14: Simplify `FrameReservationPolicy`

### Checkpoint: Surfaces

- [ ] `tests/Feature/Api/V1/FrameReservationTest.php` and
      `tests/Feature/Filament` pass
- [ ] `AdminNavigationBadgeTest` reflects the unaccepted-only count
- [ ] Manual check: accept and release a reservation in the panel, confirm the
      stock figure on the product variant moves by exactly one per frame

### Phase 6: Cleanup

- [ ] Task 15: Delete dead code and obsolete tests
- [ ] Task 16: Migration B — drop every obsolete column
- [ ] Task 17: Update canonical documentation

### Checkpoint: Complete

- [ ] `grep -ri "ReservationStatus\|tried_on\|frame_reservation_id"` returns
      only historical spec files under `docs/specs/`
- [ ] `vendor/bin/sail artisan migrate:fresh --seed` succeeds
- [ ] Full suite green, Pint reports no changes
- [ ] Ready for review

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Dropping `status` reds the whole suite mid-project | High | Two migrations; the drop is Task 16, after every reference is gone |
| Allocate/release asymmetry silently corrupts stock | High | One `FrameReservationStock` collaborator; a round-trip test asserting stock and movement count return to baseline |
| Concurrent Accept double-allocates | Medium | Re-read `accepted_at` under the reservation lock inside the transaction; explicit concurrency test |
| Cascade delete on items drops a hold with no ledger row | Medium | `DeleteFrameReservation` releases explicitly before deleting; test asserts the movement count, not just the stock figure |
| Removing linkage breaks an unnoticed consumer | Medium | Phase 1 runs first and ends at a full-suite checkpoint |
| Dev databases carry obsolete rows that Migration B cannot drop cleanly | Low | ADR-002 permits reset; the checkpoint runs `migrate:fresh --seed` |
| Android still calls `POST .../cancel` | Low | Contract change is documented in Task 17; confirm before deploy, not before build |

## Parallelization

Phases 1 and 2 are strictly sequential. Within Phase 5, Tasks 11–14 touch
disjoint files and can run in parallel once Phase 4 lands. Tasks 5–8 share
`FrameReservationStock` but no source file, so they can be parallelized with
care; sequential is recommended given how small each one is.

## Open Questions

None blocking. The spec's single open item — Android coordination for the
`cancel` → `DELETE` and `status` → `is_held` change — is a pre-deploy
confirmation, not a pre-build one.
