# Implementation Plan: Practical Clinical Encounter Workflow

**Status:** Approved
**Spec:** `docs/specs/encounter-workflow-spec.md`
**Spec approved:** 2026-08-09
**Plan approved:** 2026-08-09
**Implementation:** Not started

## Overview

Implement the approved optometrist-owned encounter workflow as a sequence of
small, testable vertical slices. The work keeps the four-step Filament wizard,
adds assessment and device-neutral supporting results, enforces treating-provider
ownership, makes completion atomic, supports explicit provider transfer, and
adds immutable post-completion addenda and printing.

The implementation does not add mobile intake, structured eye measurements,
autorefractor-specific fields, uploads, diagnosis codes, configurable clinical
templates, external services, or dependencies.

## Architecture Decisions

1. **Keep the existing Encounter aggregate and lifecycle.** Continue using
   `planned`, `in_progress`, `completed`, and `cancelled`; use **Waiting** only as
   the UI label for `planned`.
2. **Use additive clinical schema changes.** Add nullable encrypted assessment
   and supporting-results fields so drafts and historical rows remain valid.
3. **Treat Patient Intake as inactive legacy behavior.** Stop attaching Intake
   during check-in and do not consume it in the Encounter UI. Do not drop its
   schema in this feature.
4. **Centralize mutation contracts in actions and an Encounter policy.** Page
   closures configure UI and delegate state changes; they do not directly
   update provider ownership or clinical lifecycle state.
5. **Bind authorship to the assigned provider.** Starting an unassigned
   Encounter claims it as the current optometrist. Starting on behalf of
   another provider is removed.
6. **Synchronize treating provider across Encounter and Appointment.** Planned
   assignment, self-claim, and in-progress transfer update both records inside
   a locked transaction.
7. **Make completion one atomic boundary.** Final clinical state, optional
   prescription finalization, Encounter completion, Appointment fulfillment,
   and audit attribution either all succeed or all roll back.
8. **Use append-only addenda rather than reopening.** Corrections and
   supplements are separate encrypted records with constrained authorship and
   no edit/delete path.
9. **Keep audit metadata non-clinical.** Store IDs, enum categories, states, and
   timestamps only; clinical narrative stays in encrypted domain columns.
10. **Use an authenticated Blade print view.** Reuse existing print conventions
    and avoid a new PDF/rendering dependency.

## Dependency Graph

```text
Approved specification and characterization tests
    -> encrypted Encounter clinical fields
        -> EncounterPolicy
            -> provider-owned check-in and start
                -> draft action and four-step wizard
                    -> atomic completion and prescription finalization
                        -> planned assignment and in-progress transfer
                            -> append-only addenda
                                -> completed summary and print
                                    -> documentation reconciliation and full regression
```

Schema and core contracts are sequential prerequisites. Once those contracts
are stable, UI tests and print presentation can be developed independently, but
no concurrent agent work is assumed or required.

## Task List

The executable checklist is maintained in `tasks/todo.md`:

### Phase 1: Characterize and Extend the Clinical Draft

- [ ] Task 1: Characterize the existing Encounter lifecycle.
- [ ] Task 2: Persist assessment and device-neutral supporting results.

### Phase 2: Enforce Provider-Owned Entry into Care

- [ ] Task 3: Enforce the Encounter role and assignment policy.
- [ ] Task 4: Check in without active Patient Intake consumption.
- [ ] Task 5: Start as the authenticated treating optometrist.

### Phase 3: Deliver the Four-Step Autosaving Wizard

- [ ] Task 6: Save partial drafts through a domain action.
- [ ] Task 7: Deliver the autosaving four-step Filament workflow.

### Phase 4: Complete the Encounter Atomically

- [ ] Task 8: Complete required clinical care atomically.
- [ ] Task 9: Finalize an optional prescription in the completion transaction.
- [ ] Task 10: Complete from the Filament review step.

### Phase 5: Coordinate Provider Assignment and Transfer

- [ ] Task 11: Assign a planned Encounter through an authorized action.
- [ ] Task 12: Transfer in-progress ownership with an allowlisted audit event.
- [ ] Task 13: Expose transfer as a confirmed Filament action.

### Phase 6: Add Immutable Corrections and Supplements

- [ ] Task 14: Persist encrypted append-only addenda.
- [ ] Task 15: Create authorized corrections through an append-only action.
- [ ] Task 16: Create attributed supplements safely under concurrency.

### Phase 7: Present and Print the Signed Record

- [ ] Task 17: Render the completed record and addendum actions.
- [ ] Task 18: Print the authenticated signed clinical record.
- [ ] Task 19: Audit successful printing without clinical metadata.

### Phase 8: Reconcile and Release

- [ ] Task 20: Reconcile backend context and verify the release candidate.

Checkpoints in `tasks/todo.md` occur after every two or three tasks. Each task
has no more than three acceptance criteria, identifies dependencies and focused
verification, and is limited to five likely files.

## Vertical Slice Detail

### Slice 1: Characterize Current Behavior and Add the Data Foundation

Capture the behavior that must survive before changing it, then add the two
Encounter clinical columns, encrypted casts, and factory support. Addendum
storage is deferred until the completed-record correction slice needs it.

Key outcomes:

- current check-in, start, draft, prescription, and completion behavior is
  characterized;
- `assessment` and `supporting_test_results` exist as nullable encrypted text;
- existing completed rows remain readable without backfill.

Likely areas:

- `database/migrations/`
- `app/Models/Encounter.php`
- `database/factories/`
- `tests/Feature/Encounters/`

### Checkpoint A: Data Foundation

- Focused migration/model/encryption tests pass.
- A fresh test database migrates successfully.
- No existing Encounter or Prescription characterization test regresses.
- No Patient Intake table or data is dropped.

### Slice 2: Establish Provider-Owned Check-In and Start

Introduce `EncounterPolicy`, remove active Intake attachment from check-in,
copy the Appointment provider into the new planned Encounter, and change start
to operate as the authenticated optometrist rather than accepting an arbitrary
provider selection.

Key outcomes:

- check-in creates exactly one planned Encounter with no Intake attachment;
- Appointment reason prefills chief complaint when present;
- assigned optometrist can start;
- unassigned Encounter can be claimed by the starting optometrist;
- assignment synchronizes back to the Appointment;
- staff, plain admin, inactive users, and other optometrists are denied at the
  server boundary.

Likely areas:

- `app/Actions/Encounters/CheckInAppointment.php`
- `app/Actions/Encounters/StartEncounter.php`
- `app/Policies/EncounterPolicy.php`
- Encounter model/resource authorization wiring
- focused lifecycle and role-matrix tests

### Slice 3: Reshape the Autosaving Four-Step Wizard

Add a dedicated draft-save action and reshape the current wizard into History,
Examination, Assessment & Plan, and Review & Complete. Keep incomplete drafts
valid and enforce narrative length and step boundaries at the action boundary.

Key outcomes:

- history is authored directly in the Encounter;
- examination exposes required findings and optional device-neutral supporting
  results;
- assessment and plan are distinct fields;
- prescription remains optional within Assessment & Plan;
- step transitions save data and `last_wizard_step`;
- reopening resumes the saved step;
- Back navigation preserves state;
- staff/plain admin cannot invoke draft saves directly.

Likely areas:

- `app/Actions/Encounters/SaveEncounterDraft.php`
- `app/Filament/Resources/Encounters/Schemas/EncounterForm.php`
- `app/Filament/Resources/Encounters/Pages/EditEncounter.php`
- `resources/views/filament/encounters/`
- focused Filament and action tests

### Checkpoint B: Authoring Workflow

- Start-to-draft flow works for the assigned optometrist.
- All four steps render with the approved fields.
- Draft saves and resume behavior pass focused tests.
- Role tests prove that view access does not grant authorship.
- Modified PHP passes Pint.

### Slice 4: Make Completion and Optional Prescription Atomic

Move the final form persistence and optional prescription finalization behind
the completion boundary. Lock and revalidate Encounter and Appointment before
performing any terminal state changes.

Key outcomes:

- chief complaint, findings, assessment, and plan are required only at
  completion;
- only the assigned active optometrist can complete;
- a valid optional prescription finalizes with the Encounter;
- an invalid prescription or stale state rolls back all effects;
- successful completion records author/time, clears finalized draft data, and
  fulfills the Appointment;
- deadlock retries use Laravel's transaction attempts rather than manual sleeps
  or exception-message parsing.

Likely areas:

- `app/Actions/Encounters/CompleteEncounter.php`
- `app/Actions/Prescriptions/FinalizePrescription.php`
- `app/Filament/Resources/Encounters/Pages/EditEncounter.php`
- completion, prescription, transaction, and role tests

### Slice 5: Add Planned Assignment and In-Progress Transfer

Replace direct provider updates with authorized assignment and transfer actions.
Use the approved reason enum and audit allowlist.

Key outcomes:

- planned assignment is available to panel operational roles;
- in-progress transfer is limited to current provider or administrator;
- target must be a different active optometrist;
- draft and prescription data survive transfer;
- Encounter and Appointment providers stay synchronized;
- only the new provider may continue authoring/completion;
- audit metadata contains IDs and the reason category, never clinical text.

Likely areas:

- assignment action used by Encounter UI
- `app/Actions/Encounters/TransferEncounter.php`
- `app/Enums/EncounterTransferReason.php`
- Encounter page/table actions
- transfer and audit tests

### Checkpoint C: Lifecycle Integrity

- Check-in, start, draft, transfer, and completion pass as one end-to-end flow.
- Concurrent/stale transition tests prove invalid state cannot be committed.
- Appointment and Encounter provider/status invariants remain consistent.
- Plain admin operational transfer does not grant clinical authorship.
- Modified PHP passes Pint.

### Slice 6: Add Immutable Corrections and Supplements

Create the addendum action and UI on completed Encounters. Enforce type-specific
authorship and sequence allocation under a parent Encounter lock.

Key outcomes:

- original completing optometrist may add a correction;
- another active optometrist may add a clearly attributed supplement;
- staff/plain admin cannot add either type;
- reason and content are encrypted and length-limited;
- sequence is stable and unique under concurrent creation;
- no supported edit, delete, archive, or reopen path exists;
- prescription corrections remain inaccessible through Encounter addenda.

Likely areas:

- `app/Actions/Encounters/CreateEncounterAddendum.php`
- `app/Models/EncounterAddendum.php`
- `app/Policies/EncounterPolicy.php`
- completed Encounter page/schema
- addendum authorization, encryption, and immutability tests

### Slice 7: Complete Read-Only Presentation and Printing

Render completed Encounters as a cohesive one-page summary followed by addenda,
and add the authenticated print path using existing Blade conventions.

Key outcomes:

- completed and cancelled records do not render an editable wizard;
- summary includes all approved clinical and authorship fields;
- print displays the original signed record before chronological addenda;
- each addendum is unmistakably labeled as leaving the original unchanged;
- print authorization follows panel read access;
- print audit contains identifiers only;
- all patient-authored or clinical text is escaped.

Likely areas:

- Encounter schema/page read-only presentation
- `resources/views/filament/encounters/`
- authenticated web route or controller
- print and audit tests

### Checkpoint D: Release Candidate

- All specification success criteria have focused test coverage.
- Encounter, Prescription, Appointment, role, and printing suites pass.
- Full Pest suite passes.
- Pint passes on changed PHP.
- Frontend assets build only if bundled assets changed.
- `docs/BACKEND_CONTEXT.md` matches the implemented behavior.
- No deferred intake, device, structured-measurement, upload, or diagnosis scope
  has entered the implementation.

## Migration and Compatibility Strategy

- Use additive, reversible migrations for Encounter fields and addenda.
- Keep new Encounter narrative columns nullable for drafts and historical rows.
- Enforce new required fields only when completing a new/in-progress Encounter.
- Display an em dash for absent fields on historical completed records.
- Do not backfill synthetic clinical narrative.
- Stop creating new `patient_intake_id` links but retain legacy schema until a
  separately approved cleanup.
- Do not change patient APIs or appointment-request contracts.

## Verification Strategy

Run the narrowest relevant test after each task, the enclosing focused suites at
each checkpoint, and the full suite only at the final checkpoint.

Primary commands:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Encounters
vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterResourceTest.php
vendor/bin/sail artisan test --compact tests/Feature/Encounters/PrescriptionLifecycleTest.php
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail artisan test --compact
```

Migration verification must use test databases or an explicitly disposable
development database. Do not run destructive migration commands against an
unverified target.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Existing page currently coordinates draft, prescription, and completion directly | High | Characterize first, introduce actions incrementally, and make completion atomic before UI cleanup. |
| Current start action can select another provider | High | Change the action contract and add direct-invocation authorization tests before reshaping UI. |
| Any optometrist can currently complete another provider's Encounter | High | Enforce assigned-provider checks after row lock and cover every role/assignment combination. |
| Concurrent start/transfer/completion causes split Appointment and Encounter state | High | Lock both records in a consistent order and update them in one transaction. |
| Encrypted narrative cannot be searched | Medium | Keep clinical columns out of search/filter configuration and document the constraint. |
| Historical completed rows lack new required fields | Medium | Keep columns nullable, enforce only at future completion, and render missing values safely. |
| Addendum sequence races | Medium | Lock the parent Encounter and retain a unique composite database constraint. |
| Audit metadata leaks health details | High | Use enum reason categories and explicit metadata allowlists; test raw audit payloads. |
| Legacy Intake code confuses implementation scope | Medium | Remove only active Encounter consumption now; handle table/model deletion in a separate approved cleanup. |
| Appointment implementation changes overlap shared files | Medium | Re-read each file before editing and preserve unrelated uncommitted changes. |
| Existing appointment planning records must remain available | Medium | Preserve them in `tasks/appointment-scheduling-plan.md` and `tasks/appointment-scheduling-todo.md`. |

## Parallelization and Coordination

No parallel agent execution is assumed. If parallel work is later explicitly
authorized, only independent tests or print presentation should branch after
the schema and action contracts are fixed. Schema, Encounter policy, shared page
code, and lifecycle actions must remain sequential or be tightly coordinated.

## Phase 3 Output

The detailed checklist now lives at the required canonical path,
`tasks/todo.md`. The prior appointment-scheduling plan and checklist remain
preserved under feature-specific filenames in the same directory.

## Open Questions

There are no blocking plan questions. Structured examination measurements and
legacy Intake deletion remain deliberately outside this feature and require
separate approval.
