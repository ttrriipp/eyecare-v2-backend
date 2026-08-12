# Implementation Plan: Remove the Eyewear Specification Workflow

**Status:** Draft — approval pending
**Specification:** `docs/specs/remove-eyewear-specification-workflow-spec.md`
**Specification approved:** 2026-08-12 (Specify phase only)
**Plan approved:** Pending
**Task breakdown:** Pending
**Implementation:** Not started

## Overview

Delete the `JobOrderEyewearSpecification` approve/verify workflow so every
optical order — corrective or not — moves through the same unconditional
status flow. This is a pure removal: no new schema, no new UI, no new
business rule. The work is almost entirely deletion, with a handful of edits
to strip now-dead conditionals out of files that otherwise survive.

The removal is ordered top-down (consumers before foundations) so the system
stays in a working, fully-tested state after every task: UI first, then the
backend status-transition gate, then the quotation-confirmation shell
creator, then the model/actions/enum, and finally the migration. This is the
reverse of a typical build order, which is correct for a deletion — it keeps
the "load-bearing" table and model alive until nothing references them
anymore, so no task ever leaves a dangling reference mid-sequence.

## Architecture Decisions

### 1. Deletion order: UI → gate → quotation → model/actions → migration

Rationale: at every checkpoint prior to the final migration deletion, the
`job_order_eyewear_specifications` table and model still exist, so any code
not yet updated in a given task keeps working rather than throwing a missing-
table/class error. Only the last task removes the foundation, by which point
nothing references it.

### 2. Test deletion happens in the same task as the code change it invalidates

Rather than a separate "clean up tests" phase at the end, each task deletes
or updates exactly the tests that its own code change breaks. This keeps
every task's own verification step meaningful (tests pass *because of* the
task's change, not despite a backlog of known-broken tests) and matches the
spec's Testing Strategy.

### 3. Revert, don't just strip, the two Filament visibility conditions

`start` and `markReady`'s `->visible()` closures on the optical order edit
page currently include eyewear-spec conditions added in the immediately
prior session (to fix a UI/backend mismatch in the now-departing gate). Task
1 reverts those closures to their pre-fix, plain-status-only form — this is
the correct end state, not a special case, since the condition they were
compensating for no longer exists.

### 4. No down-migration; delete the migration file outright

Per the spec's approved Decision 2 — the table has only ever run in local
dev. The final task also runs `migrate:fresh --seed` to prove the schema
builds clean without it.

## Dependency Graph

```text
UI (header actions + form section + Filament tests)
    -> status-transition gate (UpdateJobOrderStatus)
        -> quotation-confirmation shell creator (ConfirmQuotationSale)
            -> model + relation + factory
                -> actions (Save/Approve/Verify) + FrameSource enum
                    -> migration deletion + fresh-migrate proof
                        -> full regression + grep-clean checkpoint
```

Tasks 1–6 are strictly sequential: each removes the outermost remaining layer
of the previous task's dependency. This is a small, single-session removal —
no parallelization opportunity exists (there is exactly one path through a
deletion this contained).

## Task List

### Phase 1: UI

- [ ] Task 1: Remove the eyewear-specification UI

### Checkpoint A
- [ ] `OpticalOrderResourceTest.php` passes with the eyewear-spec tests gone
- [ ] Page renders for both a corrective and non-corrective order with no
      leftover reference

### Phase 2: Status-transition gate

- [ ] Task 2: Remove the approval/verification gate from `UpdateJobOrderStatus`

### Checkpoint B
- [ ] `tests/Feature/JobOrders/` and `tests/Feature/OpticalOrders/` pass
- [ ] A corrective order transitions Queued→Processing→Ready identically to a
      non-corrective one (manual check via the two remaining gate tests'
      replacement coverage, or existing happy-path tests)

### Phase 3: Quotation confirmation

- [ ] Task 3: Remove the specification-shell creator from `ConfirmQuotationSale`

### Checkpoint C
- [ ] `tests/Feature/Quotations/` and `LensOptionQuotationTest.php` pass
- [ ] Confirming a quotation with a lens package still enforces the
      current-prescription rule (unchanged, untouched by this task)

### Phase 4: Model, actions, enum

- [ ] Task 4: Remove the model, relation, and factory
- [ ] Task 5: Remove the three actions and the `FrameSource` enum

### Checkpoint D
- [ ] `grep -rn "EyewearSpecification\|FrameSource" app database tests` finds
      nothing outside the not-yet-deleted migration
- [ ] Full app boots (`vendor/bin/sail artisan route:list` succeeds — proves
      no Filament resource file has a fatal missing-class reference)

### Phase 5: Migration and final sweep

- [ ] Task 6: Delete the migration; confirm the remaining privacy/workflow
      tests are clean; run the full regression and grep-clean checkpoint

### Checkpoint E (final)
- [ ] Every command in the spec's Commands section passes
- [ ] `vendor/bin/sail bin pint --dirty --format agent` reports clean
- [ ] `git status`/`git diff --stat` matches exactly the Scope table in the
      spec — nothing extra touched
- [ ] Grep-clean check returns zero results

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| A dead import survives a file edit (e.g. `Select` left imported in `OpticalOrderForm.php` after its only user is deleted) | Low — Pint won't catch unused `use` statements, PHP won't error until static analysis or a real unused-import lint runs | Manually re-read each edited file's full `use` block after editing and cross-check each class is still referenced in the body, per spec Boundary "re-check before removing" |
| `ConfirmQuotationSale.php`'s `Prescription`/`Collection` imports become unused after removing the shell-creator method | Low | Grep the file for other usages of `Prescription`/`Collection` before touching imports; only remove if truly zero remaining references |
| An audit-log row in local seeded dev data references `JobOrderEyewearSpecification` as a polymorphic subject, causing a `morphTo` failure if anything later loads it | Very low — local dev only, no code path currently loads arbitrary audit subjects generically enough to hit this | `migrate:fresh --seed` in Task 6 wipes and reseeds, so this cannot surface after this plan completes |
| Missing a reference not captured in the spec's Scope table (e.g. a Filament global search config, a seeder) | Low — spec's grep-based survey was thorough, but a seeder wasn't explicitly checked | Task 6's final grep-clean check is the safety net; if it finds anything, stop and follow the spec's "ask first" boundary rather than deleting on assumption |

## Open Questions

None — the three approved product decisions in the spec (no replacement
gate, migration deleted outright, tests deleted not skipped) resolve every
fork this removal has. Any *new* fork discovered mid-implementation (e.g. an
unlisted file referencing the removed classes) is handled per the spec's
"Ask first" boundary, not decided unilaterally.
