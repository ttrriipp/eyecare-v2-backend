# Implementation Plan: Dead Code and Unreachable Feature Removal

**Status:** Phase 3 approved 2026-08-14; Phase 4 implementation deferred

**Specification:** `docs/specs/dead-code-removal-spec.md` (`2904eed`)

**Checklist:** `tasks/dead-code-removal-todo.md` (approved 2026-08-14)

**Implementation:** Not started; requires separate owner authorization

**Proposed scope:** 12 tasks in 5 phases

## Overview

Remove the unreachable complaints and patient-intake subsystems, their orphaned
surfaces, and the already-cancelled `inventory_movement_statuses` migration
pair. The notification tables are already absent through migration
`2026_06_24_124601_drop_unused_notification_tables`; this project adds no
notification migration.

The supported quotation, order, billing, appointment, encounter, messaging,
privacy, and inventory workflows remain unchanged. The owner approved one
compatibility break: removing the unlinked but manually reachable
`appointments.health-record.print` route. The live encounter print route stays.

## Architecture Decisions

### 1. Re-verify before deleting

The reachability audit produced several incorrect conclusions before the final
specification. Task 1 therefore repeats the four-axis check—class, relation,
table, and route/view registration—against the implementation-day worktree.
Any newly reachable consumer returns the project to the specification phase.

### 2. Do not add another notification migration

`notification_channels` and `notification_templates` are already dropped by an
existing migration. Their historical migrations remain untouched. Only the
matched `inventory_movement_statuses` create/drop pair is deleted because it
cancels out during every fresh migration and ADR-002 records no deployed
database history to preserve.

### 3. Remove consumers before schema

Code and seed consumers are removed while their tables still exist. A single
cleanup migration is added only after both complaint and intake code are gone.
It drops, in dependency order:

1. the foreign key and `encounters.patient_intake_id` column;
2. `patient_intakes`;
3. `complaints`.

Its `down()` recreates those structures in the reverse dependency order.

### 4. Keep every implementation task at five files or fewer

The previous plan bundled most intake removal into one task touching more than
16 files. This plan separates surfaces, integrations, tests/factory, audit
machinery, and model relations. Each slice leaves the application in a
verifiable intermediate state.

### 5. Compare against the recorded test baseline

The focused readiness run is green: 244 tests and 460 assertions. The full
suite has 25 unrelated failures. Each phase must keep affected tests green; the
final full run must introduce no additional failures. Fixing the existing 25 is
outside this project.

All destructive migration checks explicitly use `--env=testing`.

### 6. Preserve overlapping worktree changes

`ScenarioCoverageSeeder.php` is currently untracked and contains work outside
this cleanup. The complaint imports, call, and method may be removed, but every
other line is preserved. No cleanup commit may absorb unrelated contents of
that file without explicit owner approval.

The dirty live encounter print view
`resources/views/filament/encounters/print.blade.php` is not the orphaned
appointment health-record print view and must remain untouched.

## Dependency Graph

```text
Phase 1  Task 1: refresh reachability and baseline evidence
              │
              ├──────── Task 2: migration-pair hygiene
              │
Phase 2       ├──────── Task 3: complaint fixtures, seeders, and test
              │              │
              │              └── Task 4: complaint runtime code
              │
Phase 3       └──────── Task 5: orphaned intake surfaces and route
                             │
                             └── Task 6: intake integrations
                                    │
                                    └── Task 7: intake tests and factory
                                           │
                                           └── Task 8: intake actions and audit
                                                  │
                                                  └── Task 9: model and relations
                                                         │
Phase 4                                                  └── Task 10: schema
                                                                │
Phase 5                                                        ├── Task 11: docs
                                                               └── Task 12: audit
```

## Task List

### Phase 1: Revalidation and schema-file hygiene

- [ ] **Task 1:** Repeat the four-axis reachability audit and record current
      database, route, registration, and test baselines. No application files.
- [ ] **Task 2:** Delete the two `inventory_movement_statuses` migration files
      and verify `inventory_movement_types` remains live. Two files.

### Checkpoint: Baseline locked

- [ ] No new consumer changes the approved specification.
- [ ] Notification migration history is unchanged.
- [ ] Fresh migration succeeds under `--env=testing`.

### Phase 2: Complaints

- [ ] **Task 3:** Remove complaint-only factory/test coverage and complaint
      blocks from `ClinicWorkflowSeeder` and `ScenarioCoverageSeeder`. Four
      files.
- [ ] **Task 4:** Delete the complaint action, enum, model, and policy; confirm
      no manual policy mapping remains. Four files.

### Checkpoint: Complaints detached

- [ ] Complaint class, relation, route/view, and seeder references are absent.
- [ ] Clinical “Chief Complaint” fields remain untouched.
- [ ] Affected focused tests are green.

### Phase 3: Patient intakes

- [ ] **Task 5:** Delete the orphan relation manager, two appointment intake
      views, and the approved legacy print route. Four files.
- [ ] **Task 6:** Remove intake integration from `CheckInAppointment`,
      `EncounterResource`, `EncounterFactory`, and the two encounter
      characterization tests. Five files.
- [ ] **Task 7:** Delete the three intake-only test files and
      `PatientIntakeFactory`. Four files.
- [ ] **Task 8:** Delete the two intake actions, legacy audit action and command,
      and three intake-only `AuditEvent` cases. Five files.
- [ ] **Task 9:** Delete `PatientIntake` and `IntakeStatus`, then remove intake
      relations/fillable state from `Patient`, `Appointment`, and `Encounter`.
      Five files.

### Checkpoint: Intakes detached

- [ ] The orphan appointment health-record print route is absent.
- [ ] The live encounter print route and its dirty view are untouched.
- [ ] `PrescriptionLifecycleTest` still covers the clinical role boundary.
- [ ] Focused encounter and conversation tests are green.

### Phase 4: Contract schema

- [ ] **Task 10:** Add one reversible migration dropping
      `encounters.patient_intake_id`, `patient_intakes`, and `complaints`. One
      file.

### Checkpoint: Schema contracted

- [ ] `migrate:fresh --env=testing` succeeds.
- [ ] The seeded testing run has no dead-code table/class reference failure.
- [ ] Closing symbol searches find no runtime references.

### Phase 5: Documentation and final audit

- [ ] **Task 11:** Reconcile `docs/BACKEND_CONTEXT.md`, including the privacy
      permission-matrix wording. One file.
- [ ] **Task 12:** Run the closing orphan, route, message-attachment, privacy,
      migration, focused-test, full-baseline, and Pint checks. No files expected.

### Checkpoint: Complete

- [ ] All specification success criteria pass.
- [ ] No new full-suite failure exists relative to the 25-failure baseline.
- [ ] Message attachments and privacy code remain intact.
- [ ] Only intended files appear in the final diff.

## Verification Checkpoints

```bash
vendor/bin/sail artisan test --compact tests/Feature/Encounters
vendor/bin/sail artisan test --compact tests/Feature/ConversationTest.php
vendor/bin/sail artisan migrate:fresh --env=testing
vendor/bin/sail artisan migrate:fresh --seed --env=testing
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
```

The seeded fresh-migration run and full suite are evidence-producing checks. A
known unrelated baseline failure is recorded rather than repaired under this
scope; any new failure blocks completion.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| A newly added consumer makes a target reachable | High | Task 1 repeats all four axes before deletion; return to Phase 1 if found |
| A direct user relied on the legacy health-record URL | Medium | Compatibility break explicitly approved; live encounter print remains |
| Schema is dropped while code still references it | High | One contract migration runs only after Tasks 3–9 remove every consumer |
| A valid authorization rule disappears with intake tests | Medium | Confirm the existing prescription lifecycle role test before deletion |
| Existing dirty work is overwritten or committed | High | Patch only scoped lines, inspect every diff, and do not include unrelated files in commits |
| Existing suite/seeder failures expand the cleanup | Medium | Compare to the recorded baseline and report unrelated failures separately |
| Notification tables are redundantly migrated | Low | Plan explicitly forbids a new notification migration |

## Parallelization

Tasks 2 and 3 are technically independent after Task 1, but implementation is
kept sequential in the shared dirty worktree. Tasks 5–9 are strictly ordered.
Task 11 may be prepared after Task 10, but final wording depends on the actual
closing diff.

## Open Questions

None. The owner approved both removal of the manually reachable legacy print
route and this technical plan on 2026-08-14. The detailed checklist is also
approved. Implementation remains deferred until separately authorized.
