# Implementation Plan: Dead Code and Unreachable Feature Removal

**Status:** Draft 2026-08-13 — approval pending
**Specification:** `docs/specs/dead-code-removal-spec.md`
**Checklist:** `tasks/dead-code-removal-todo.md`
**Implementation:** Not started
**Scope:** 9 tasks in 4 phases

## Overview

Remove three unreachable subsystems: two dead tables, the complaints workflow,
and patient intakes. Unlike the commerce project this touches almost no running
logic — but it is not risk-free, because the audit that produced the spec was
wrong three times.

| Area | Now | After |
|---|---|---|
| Dead tables | 2 (`notification_channels`, `notification_templates`) | 0 |
| Stale migration files | 2 (`inventory_movement_statuses` create + drop) | 0 |
| Complaints | model, enum, policy, action, factory, test, seeder | gone |
| Patient intakes | model, enum, 3 relations, 3 actions, command, 3 audit events, factory, 4 tests | gone |
| Orphaned Filament surfaces | 1 relation manager, 2 views, 1 route | gone |

## Architecture Decisions

### 1. Complaints before intakes

Both are removals, but complaints is a clean cut — no UI, no view, no display
surface, one seeder block. Intakes touch two live surfaces and a route.

Doing complaints first proves the four-axis verification procedure and the
drop-migration shape on the subsystem where a mistake is cheapest to find. If
the procedure has a hole, it surfaces on the easy case rather than the one that
renders in front of a user.

### 2. Delete the orphaned surfaces before the model they read

All three intake surfaces — `HealthRecordRelationManager`,
`intake-form.blade.php`, and `health-record-print.blade.php` with its route —
are unregistered and unreachable. None of them is repointed; all are deleted.

They still go first, in their own task. Deleting the model while three files
still reference `intake` reds the suite for reasons hard to distinguish from
real breakage. Removing the readers first means Task 6's deletion of
`PatientIntake` has nothing left pointing at it, and any failure it does
produce is a genuine finding.

This is the ordering decision that matters most in the plan.

### 3. Schema drops come last within each subsystem

A dropped column with code still referencing it fails at runtime; code removed
while the column remains is merely untidy. Code first, schema second, every
time. Each subsystem's drop migration is its own task so a checkpoint sits
between the code change and the irreversible one.

### 4. The `inventory_movement_statuses` file pair is deleted, not migrated

Every other schema change in this plan adds a drop migration. This one deletes
two existing migration files, because the table is *already* dropped and the
pair cancels out. Per ADR-002 there is no deployed database whose migration
history must stay intact. It is grouped with Task 1 since both are pure
schema-file hygiene with zero code impact.

### 5. Verification is recorded, not asserted — and registration is checked

The spec's four-axis procedure exists because this audit has now been wrong
five times across three passes. A task that says "verified unreachable" without
recording the four grep results has not followed it. Every deletion task
carries the four axes as explicit checklist lines with space for the result.

The third pass added a rule the first two lacked. Both earlier failures ran a
search, got a result, and treated the result as an answer — first inferring
death from a filename miss, then inferring life from a relation-name hit. A
grep proves a reference exists; it does not prove a user can reach it.

So for every Filament class and Blade view being deleted, confirm its
*registration* is absent, not merely its references: relation managers must be
absent from `getRelations()`, pages from `getPages()`, views must have no
renderer. Walking outward from a surface to its caller is what found the truth;
it is now a checklist line.

## Dependency Graph

```text
Phase 1  Dead schema (independent — no code anywhere)
              │
Phase 2  Complaints removed  ← proves the procedure on the easy case
              │
              ▼
Phase 3  Intakes
         Task 5  Delete the three orphaned surfaces + print route
              │
         Task 6  Delete intake code
              │
         Task 7  Drop patient_intakes + encounters.patient_intake_id
              │
Phase 4  Documentation + closing audit
```

Phase 1 is independent of everything and could run at any point. Phases 2 and 3
are independent of each other in the code, but see Decision 1 for why they are
ordered.

## Task List

### Phase 1: Dead schema

- [ ] Task 1: Drop `notification_channels` and `notification_templates`, and
      delete the `inventory_movement_statuses` migration pair

### Phase 2: Complaints

- [ ] Task 2: Verify complaints unreachable on four axes
- [ ] Task 3: Delete complaints code, factory, test, and seeder block
- [ ] Task 4: Drop the `complaints` table

### Checkpoint: Complaints

- [ ] Four-axis results recorded for `Complaint`, `complaints`, `ComplaintStatus`
- [ ] `grep -rn "Complaint"` returns only "Chief Complaint" clinical matches
- [ ] `migrate:fresh --seed` succeeds
- [ ] Full suite green

### Phase 3: Patient intakes

- [ ] Task 5: Delete the three orphaned intake surfaces and the print route
- [ ] Task 6: Delete intake models, enums, relations, actions, command, audit
      events, factory, and tests
- [ ] Task 7: Drop `patient_intakes` and `encounters.patient_intake_id`

### Checkpoint: Intakes

- [ ] Registration absence recorded for all three surfaces, not just reference
      absence
- [ ] `artisan route:list | grep health-record` returns nothing
- [ ] The role boundary formerly asserted by `IntakeVerificationTest` is still
      covered somewhere
- [ ] Four-axis results recorded for `PatientIntake`, `intake`,
      `patient_intakes`
- [ ] `migrate:fresh --seed` succeeds
- [ ] Full suite green

### Phase 4: Documentation and closing audit

- [ ] Task 8: Update `BACKEND_CONTEXT.md`, including the permission matrix
- [ ] Task 9: Closing orphan audit — every table has a model, every model a
      table, every view a renderer

### Checkpoint: Complete

- [ ] `grep -rn "PatientIntake\|IntakeStatus\|Complaint\b\|notification_channels\|notification_templates"`
      returns only spec files
- [ ] Message attachments still work: upload, download, preview, Filament display
- [ ] Privacy compliance code untouched
- [ ] `migrate:fresh --seed` succeeds; full suite green; Pint clean

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| A sixth reference is missed, as five were | High | Four-axis procedure is a recorded checklist item per symbol, not a claim; registration is checked as well as references; Task 9 is a closing orphan audit independent of the per-task checks |
| A surface assumed orphaned is registered somewhere unexamined | High | Task 5 records the registration check per surface — `getRelations()`, `getPages()`, renderer, `route:list` — before deleting any of them |
| Someone wanted the health-record printout | Low | It is unreachable today, so nothing is lost that anyone currently has; `/encounters/{encounter}/print` already exists, and rebuilding is a separate spec |
| Deleting `IntakeVerificationTest` drops a role boundary still in force | Medium | Task 6 confirms `PrescriptionLifecycleTest` covers the clinical-authorization boundary and writes the case if not |
| Deleting migration files breaks a teammate's local database | Low | ADR-002: no deployed database; `migrate:fresh --seed` at every checkpoint is the contract |
| A dropped table is still referenced by a factory or seeder | Low | Every checkpoint runs `migrate:fresh --seed` |
| An appointment with no encounter renders blank in Visit History | Low | Correct behavior — a visit with no encounter has no clinical findings; the column keeps its `—` placeholder |

## Parallelization

Phase 1 is fully independent and can run at any time. Phases 2 and 3 share no
file and could run in parallel, but Decision 1 argues for running complaints
first to validate the procedure. Within Phase 3 the three tasks are strictly
sequential.

## Open Questions

None. One item is settled during implementation rather than now: whether
`PrescriptionLifecycleTest` fully covers the clinical-authorization role
boundary that `IntakeVerificationTest` asserts today — read it in Task 6 and
write the missing case if not.
