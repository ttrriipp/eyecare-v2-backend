# Task List: Practical Clinical Encounter Workflow

**Status:** Approved — implementation not started
**Spec:** `docs/specs/encounter-workflow-spec.md`
**Plan:** `tasks/plan.md`
**Spec approved:** 2026-08-09
**Plan approved:** 2026-08-09
**Task list approved:** 2026-08-09
**Implementation:** Not started

Implementation begins only after this checklist receives separate approval.

## Standing Definition of Done

Every task must:

- search installed-version Laravel or Filament documentation before changing
  that domain;
- write or update the focused Pest expectation before changing behavior;
- use Sail for PHP, Artisan, Composer, Node, tests, and formatting;
- use Artisan or Filament generators for framework-owned files;
- preserve unrelated worktree changes and re-read shared files before editing;
- keep mutations behind policies and typed action classes;
- run its focused verification command; and
- leave the application in a working, testable state.

Run `vendor/bin/sail bin pint --dirty --format agent` after every task that
changes PHP. Stop and return to planning if a task would exceed five files,
change an approved contract, or require a dependency.

## Phase 1: Characterize and Extend the Clinical Draft

### Task 1: Characterize the existing Encounter lifecycle

**Description:** Protect the valid check-in, draft, optional-prescription, and
completion behavior before refactoring. Do not encode the known cross-provider
authorization defects or active Intake attachment as desired behavior.

**Acceptance criteria:**

- [ ] Scheduled and walk-in check-in still produce exactly one planned Encounter.
- [ ] Draft, prescription, completion attribution, and Appointment fulfillment are covered.
- [ ] Tests clearly mark legacy behavior that later tasks intentionally replace.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/EncounterLifecycleCharacterizationTest.php tests/Feature/Encounters/PrescriptionLifecycleTest.php tests/Feature/Encounters/CheckInTransactionTest.php`

**Dependencies:** None

**Files likely touched:**

- `tests/Feature/Encounters/EncounterLifecycleCharacterizationTest.php`
- `tests/Feature/Encounters/PrescriptionLifecycleTest.php`
- `tests/Feature/Encounters/CheckInTransactionTest.php`

**Estimated scope:** Medium (3 files)

### Task 2: Persist assessment and device-neutral supporting results

**Description:** Generate an additive migration, update Encounter and its
factory, and prove that drafts can store assessment and optional supporting
test narrative without introducing device-specific fields.

**Acceptance criteria:**

- [ ] Nullable `TEXT` columns preserve historical rows and require no backfill.
- [ ] Both attributes use encrypted casts and raw storage hides submitted narrative.
- [ ] The factory supports complete and partial clinical drafts.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/EncounterClinicalFieldsTest.php`

**Dependencies:** Task 1

**Files likely touched:**

- `database/migrations/*_add_assessment_and_supporting_test_results_to_encounters_table.php`
- `app/Models/Encounter.php`
- `database/factories/EncounterFactory.php`
- `tests/Feature/Encounters/EncounterClinicalFieldsTest.php`

**Estimated scope:** Medium (4 files)

## Checkpoint A: Characterized Data Foundation — Tasks 1–2

- [ ] Focused tests for Tasks 1–2 pass.
- [ ] `vendor/bin/sail artisan migrate --no-interaction` succeeds on a verified development database.
- [ ] `vendor/bin/sail bin pint --dirty --format agent` passes.
- [ ] Existing rows remain readable and no Patient Intake data is removed.

## Phase 2: Enforce Provider-Owned Entry into Care

### Task 3: Enforce the Encounter role and assignment policy

**Description:** Generate an Encounter policy implementing the approved role
matrix. Optometrist role and assigned-provider identity remain independent
requirements; plain administrator access is operational, not clinical.

**Acceptance criteria:**

- [ ] View, assign, start, draft, complete, transfer, addendum, and print abilities match the spec matrix.
- [ ] Inactive accounts are denied mutation abilities.
- [ ] Staff and plain administrators never receive clinical authorship.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/EncounterPolicyTest.php`

**Dependencies:** Task 2

**Files likely touched:**

- `app/Policies/EncounterPolicy.php`
- `tests/Feature/Encounters/EncounterPolicyTest.php`

**Estimated scope:** Small (2 files)

### Task 4: Check in without active Patient Intake consumption

**Description:** Update the check-in slice from Appointment through planned
Encounter: stop Intake lookup/attachment, copy assigned provider, and prefill
chief complaint from the Appointment reason.

**Acceptance criteria:**

- [ ] Check-in creates exactly one planned Encounter with a null Intake link.
- [ ] Provider and reason are copied without preventing later clinical editing.
- [ ] Existing transaction rollback and duplicate-check-in safeguards remain intact.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/EncounterCheckInTest.php tests/Feature/Encounters/CheckInTransactionTest.php`

**Dependencies:** Tasks 2 and 3

**Files likely touched:**

- `app/Actions/Encounters/CheckInAppointment.php`
- `tests/Feature/Encounters/EncounterCheckInTest.php`
- `tests/Feature/Encounters/CheckInTransactionTest.php`

**Estimated scope:** Medium (3 files)

### Task 5: Start as the authenticated treating optometrist

**Description:** Refactor StartEncounter to accept only Encounter and actor.
Authorize and lock the transition, allow self-claim when unassigned, and keep
Encounter and Appointment provider assignments synchronized.

**Acceptance criteria:**

- [ ] The assigned optometrist can start, and an unassigned Encounter can be self-claimed.
- [ ] Nobody can start for another provider or start another provider's Encounter.
- [ ] Successful and failed transitions preserve Encounter/Appointment assignment consistency.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/StartEncounterTest.php`

**Dependencies:** Tasks 3 and 4

**Files likely touched:**

- `app/Actions/Encounters/StartEncounter.php`
- `tests/Feature/Encounters/StartEncounterTest.php`

**Estimated scope:** Small (2 files)

## Checkpoint B: Provider-Owned Start — Tasks 3–5

- [ ] Policy, check-in, and start focused tests pass.
- [ ] `vendor/bin/sail bin pint --dirty --format agent` passes.
- [ ] Check-in through start works for assigned and self-claim scenarios.
- [ ] Direct action invocation cannot bypass role or assignment checks.

## Phase 3: Deliver the Four-Step Autosaving Wizard

### Task 6: Save partial drafts through a domain action

**Description:** Add SaveEncounterDraft with policy and state enforcement,
narrative trimming and limits, and wizard-step validation. Completion-only
requirements must not invalidate a partial draft.

**Acceptance criteria:**

- [ ] Only the assigned active optometrist can save an in-progress draft.
- [ ] Narrative is trimmed, capped at 10,000 characters, and remains encrypted.
- [ ] `last_wizard_step` accepts 1–4 while incomplete drafts remain valid.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/SaveEncounterDraftTest.php`

**Dependencies:** Tasks 2, 3, and 5

**Files likely touched:**

- `app/Actions/Encounters/SaveEncounterDraft.php`
- `tests/Feature/Encounters/SaveEncounterDraftTest.php`

**Estimated scope:** Small (2 files)

### Task 7: Deliver the autosaving four-step Filament workflow

**Description:** Recompose the Encounter form and edit page into History,
Examination, Assessment & Plan, and Review & Complete. Delegate step saves to
SaveEncounterDraft and resume at the saved step.

**Acceptance criteria:**

- [ ] The approved fields, optional prescription, and persistent Encounter context appear in the correct steps.
- [ ] Forward navigation saves, Back preserves state, and reopening resumes the saved step.
- [ ] Only an authorized in-progress Encounter renders an editable wizard.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterWizardTest.php tests/Feature/Filament/EncounterDraftWorkflowTest.php`

**Dependencies:** Task 6

**Files likely touched:**

- `app/Filament/Resources/Encounters/Schemas/EncounterForm.php`
- `app/Filament/Resources/Encounters/Pages/EditEncounter.php`
- `resources/views/filament/encounters/complete-visit-button.blade.php`
- `tests/Feature/Filament/EncounterWizardTest.php`
- `tests/Feature/Filament/EncounterDraftWorkflowTest.php`

**Estimated scope:** Medium (5 files)

## Checkpoint C: Draft Authoring — Tasks 6–7

- [ ] Draft-action and Filament workflow tests pass.
- [ ] `vendor/bin/sail bin pint --dirty --format agent` passes.
- [ ] The treating optometrist can save, leave, and resume every wizard step.
- [ ] View access still does not grant clinical edit access.

## Phase 4: Complete the Encounter Atomically

### Task 8: Complete required clinical care atomically

**Description:** Make CompleteEncounter the sole terminal boundary. Lock and
revalidate state, actor, assignment, required fields, and Appointment before
recording attribution and fulfilling the Appointment in one transaction.

**Acceptance criteria:**

- [ ] Chief complaint, findings, assessment, and plan are required only at completion.
- [ ] Only the assigned active optometrist can complete and receive attribution.
- [ ] Stale or failed completion leaves Encounter and Appointment unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/CompleteEncounterTest.php`

**Dependencies:** Tasks 5 and 6

**Files likely touched:**

- `app/Actions/Encounters/CompleteEncounter.php`
- `tests/Feature/Encounters/CompleteEncounterTest.php`

**Estimated scope:** Small (2 files)

### Task 9: Finalize an optional prescription in the completion transaction

**Description:** Integrate the existing optional prescription finalizer into
the Encounter completion transaction. Invalid prescription data must roll back
the prescription, Encounter, and Appointment together.

**Acceptance criteria:**

- [ ] Encounter completion succeeds without a prescription.
- [ ] A valid draft prescription finalizes with the Encounter.
- [ ] Any prescription or completion failure produces no partial effects.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/PrescriptionLifecycleTest.php tests/Feature/Encounters/EncounterCompletionTransactionTest.php`

**Dependencies:** Task 8

**Files likely touched:**

- `app/Actions/Encounters/CompleteEncounter.php`
- `app/Actions/Prescriptions/FinalizePrescription.php`
- `tests/Feature/Encounters/PrescriptionLifecycleTest.php`
- `tests/Feature/Encounters/EncounterCompletionTransactionTest.php`

**Estimated scope:** Medium (4 files)

### Task 10: Complete from the Filament review step

**Description:** Display the current full draft in Review & Complete and
delegate confirmed submission to CompleteEncounter. Surface safe validation
errors and transition successful completion to the read-only record.

**Acceptance criteria:**

- [ ] Review displays all approved clinical sections and optional prescription.
- [ ] Missing required fields block completion without echoing clinical content.
- [ ] Successful and direct Livewire submissions obey the same action authorization.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterCompletionWorkflowTest.php`

**Dependencies:** Tasks 7–9

**Files likely touched:**

- `app/Filament/Resources/Encounters/Pages/EditEncounter.php`
- `app/Filament/Resources/Encounters/Schemas/EncounterForm.php`
- `resources/views/filament/encounters/complete-visit-button.blade.php`
- `tests/Feature/Filament/EncounterCompletionWorkflowTest.php`

**Estimated scope:** Medium (4 files)

## Checkpoint D: Atomic Completion — Tasks 8–10

- [ ] Domain, prescription, and Filament completion tests pass.
- [ ] `vendor/bin/sail bin pint --dirty --format agent` passes.
- [ ] End-to-end check-in, start, draft, review, and completion succeeds.
- [ ] Failure injection proves there are no partial terminal states.

## Phase 5: Coordinate Provider Assignment and Transfer

### Task 11: Assign a planned Encounter through an authorized action

**Description:** Add a typed planned-assignment action and route the Encounter
table control through it. Resolve an active optometrist and update Encounter
and Appointment under one locked transaction.

**Acceptance criteria:**

- [ ] Every approved operational role can assign an active optometrist while planned.
- [ ] Stale, invalid-provider, and post-start assignments are rejected atomically.
- [ ] No inline editable assignment control bypasses the action.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/AssignEncounterOptometristTest.php tests/Feature/Filament/EncounterResourceTest.php`

**Dependencies:** Tasks 3 and 4

**Files likely touched:**

- `app/Actions/Encounters/AssignEncounterOptometrist.php`
- `app/Filament/Resources/Encounters/Tables/EncountersTable.php`
- `tests/Feature/Encounters/AssignEncounterOptometristTest.php`
- `tests/Feature/Filament/EncounterResourceTest.php`

**Estimated scope:** Medium (4 files)

### Task 12: Transfer in-progress ownership with an allowlisted audit event

**Description:** Add the transfer-reason enum and TransferEncounter action.
Permit only the current provider or administrator, synchronize both records,
preserve drafts, and audit identifiers plus the reason category only.

**Acceptance criteria:**

- [ ] A permitted actor can transfer to a different active optometrist atomically.
- [ ] Draft and prescription data survives; only the target can subsequently author.
- [ ] Audit metadata contains the approved identifiers and enum, never clinical text.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/TransferEncounterTest.php`

**Dependencies:** Tasks 3, 5, and 9

**Files likely touched:**

- `app/Enums/EncounterTransferReason.php`
- `app/Actions/Encounters/TransferEncounter.php`
- `app/Enums/AuditEvent.php`
- `tests/Feature/Encounters/TransferEncounterTest.php`

**Estimated scope:** Medium (4 files)

### Task 13: Expose transfer as a confirmed Filament action

**Description:** Add a modal using active-optometrist choices and the approved
reason enum. Delegate entirely to TransferEncounter and refresh the ownership
context after success.

**Acceptance criteria:**

- [ ] Only the current provider or administrator sees and can invoke transfer.
- [ ] Server-side action validation rejects forged target or reason values.
- [ ] Plain administrator transfer does not grant clinical authorship afterward.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterTransferActionTest.php`

**Dependencies:** Task 12

**Files likely touched:**

- `app/Filament/Resources/Encounters/Pages/EditEncounter.php`
- `tests/Feature/Filament/EncounterTransferActionTest.php`

**Estimated scope:** Small (2 files)

## Checkpoint E: Ownership Changes — Tasks 11–13

- [ ] Assignment, transfer, audit, and Filament tests pass.
- [ ] `vendor/bin/sail bin pint --dirty --format agent` passes.
- [ ] Appointment and Encounter provider IDs remain synchronized through every path.
- [ ] Stale and concurrent ownership changes cannot produce split state.

## Phase 6: Add Immutable Corrections and Supplements

### Task 14: Persist encrypted append-only addenda

**Description:** Generate the addendum table, enum, model, and factory. Prove
encrypted narrative, restrictive foreign keys, stable timestamps, and unique
per-Encounter sequencing without adding update or delete semantics.

**Acceptance criteria:**

- [ ] Schema matches the approved columns, constraints, and absence of `updated_at`/soft deletes.
- [ ] Reason and content are encrypted and type/authored time are typed casts.
- [ ] Sequence uniqueness and restrictive foreign keys are enforced by the database.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/EncounterAddendumModelTest.php`

**Dependencies:** Task 2

**Files likely touched:**

- `database/migrations/*_create_encounter_addenda_table.php`
- `app/Enums/EncounterAddendumType.php`
- `app/Models/EncounterAddendum.php`
- `database/factories/EncounterAddendumFactory.php`
- `tests/Feature/Encounters/EncounterAddendumModelTest.php`

**Estimated scope:** Medium (5 files)

### Task 15: Create authorized corrections through an append-only action

**Description:** Add the ordered Encounter relationship and addendum action.
Lock the completed parent, allocate sequence, validate narrative, and permit a
correction only from the original completing optometrist.

**Acceptance criteria:**

- [ ] Only the original completing optometrist can correct a completed Encounter.
- [ ] Required reason/content obey 1,000/10,000-character limits and remain encrypted.
- [ ] No supported update, delete, archive, or reopen path is introduced.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/CreateEncounterCorrectionTest.php`

**Dependencies:** Tasks 3, 8, and 14

**Files likely touched:**

- `app/Models/Encounter.php`
- `app/Actions/Encounters/CreateEncounterAddendum.php`
- `app/Policies/EncounterPolicy.php`
- `tests/Feature/Encounters/CreateEncounterCorrectionTest.php`

**Estimated scope:** Medium (4 files)

### Task 16: Create attributed supplements safely under concurrency

**Description:** Extend the same action contract for supplements by active
optometrists with record access, and prove locked sequence allocation under
concurrent attempts.

**Acceptance criteria:**

- [ ] Active optometrists can supplement while staff and plain admin cannot.
- [ ] Supplements remain attributed and cannot masquerade as corrections or prescription amendments.
- [ ] Concurrent creation yields unique, monotonic per-Encounter sequences.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/CreateEncounterSupplementTest.php tests/Feature/Encounters/EncounterAddendumConcurrencyTest.php`

**Dependencies:** Task 15

**Files likely touched:**

- `app/Actions/Encounters/CreateEncounterAddendum.php`
- `tests/Feature/Encounters/CreateEncounterSupplementTest.php`
- `tests/Feature/Encounters/EncounterAddendumConcurrencyTest.php`

**Estimated scope:** Medium (3 files)

## Checkpoint F: Immutable Addenda — Tasks 14–16

- [ ] Model, correction, supplement, and concurrency tests pass.
- [ ] `vendor/bin/sail bin pint --dirty --format agent` passes.
- [ ] Raw storage and audit payload inspection reveals no clinical narrative.
- [ ] Completed Encounter and addendum records expose no mutation path.

## Phase 7: Present and Print the Signed Record

### Task 17: Render the completed record and addendum actions

**Description:** Replace the disabled terminal wizard with one read-only
clinical summary followed by chronological addenda. Add the confirmed addendum
modal only for actors and types allowed by policy.

**Acceptance criteria:**

- [ ] Original record remains visually distinct and unchanged before labeled addenda.
- [ ] Historical nulls render safely and all authored narrative is escaped.
- [ ] Completed/cancelled records have no edit, delete, or reopen control.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/CompletedEncounterViewTest.php tests/Feature/Filament/EncounterAddendumActionTest.php`

**Dependencies:** Tasks 10, 15, and 16

**Files likely touched:**

- `app/Filament/Resources/Encounters/Pages/EditEncounter.php`
- `app/Filament/Resources/Encounters/Schemas/EncounterForm.php`
- `tests/Feature/Filament/CompletedEncounterViewTest.php`
- `tests/Feature/Filament/EncounterAddendumActionTest.php`

**Estimated scope:** Medium (4 files)

### Task 18: Print the authenticated signed clinical record

**Description:** Add a named authenticated route, thin controller, and escaped
Blade print view using existing conventions. Print the original record before
chronological addenda without adding a rendering dependency.

**Acceptance criteria:**

- [ ] Authorized panel roles can print completed Encounters only.
- [ ] Output contains approved original attribution and unchanged-record addendum labels.
- [ ] Unauthorized, incomplete, and malicious-content scenarios are safely rejected/rendered.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/EncounterPrintTest.php`
- [ ] `vendor/bin/sail artisan route:list --except-vendor --name=encounters.print`

**Dependencies:** Task 17

**Files likely touched:**

- `routes/web.php`
- `app/Http/Controllers/EncounterPrintController.php`
- `resources/views/filament/encounters/print.blade.php`
- `tests/Feature/Encounters/EncounterPrintTest.php`

**Estimated scope:** Medium (4 files)

## Checkpoint G: Signed Presentation — Tasks 17–18

- [ ] Completed-view, addendum-action, and print tests pass.
- [ ] `vendor/bin/sail bin pint --dirty --format agent` passes.
- [ ] The original record and chronological addenda are readable and clearly attributed.
- [ ] Print authorization matches the approved role contract.

### Task 19: Audit successful printing without clinical metadata

**Description:** Add the print audit event and record identifier-only metadata
after successful authorization. Failed or unauthorized requests must not appear
as successful prints.

**Acceptance criteria:**

- [ ] Every successful print records the event and actor.
- [ ] Metadata is limited to approved stable identifiers with no clinical narrative.
- [ ] Failed and unauthorized print attempts do not record successful-print events.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/EncounterPrintAuditTest.php`

**Dependencies:** Task 18

**Files likely touched:**

- `app/Enums/AuditEvent.php`
- `app/Http/Controllers/EncounterPrintController.php`
- `tests/Feature/Encounters/EncounterPrintAuditTest.php`

**Estimated scope:** Medium (3 files)

## Phase 8: Reconcile and Release

### Task 20: Reconcile backend context and verify the release candidate

**Description:** After implementation is true, update backend context and run
the complete focused and regression checks. Do not use this task to remove
legacy Intake schema or introduce deferred clinical scope.

**Acceptance criteria:**

- [ ] `docs/BACKEND_CONTEXT.md` accurately describes the shipped workflow and deferrals.
- [ ] All focused tests, the full Pest suite, and Pint pass.
- [ ] No deferred feature or new dependency entered the implementation.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterResourceTest.php tests/Feature/Filament/EncounterWizardTest.php tests/Feature/Filament/EncounterDraftWorkflowTest.php tests/Feature/Filament/EncounterCompletionWorkflowTest.php tests/Feature/Filament/EncounterTransferActionTest.php tests/Feature/Filament/CompletedEncounterViewTest.php tests/Feature/Filament/EncounterAddendumActionTest.php`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 1–19

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`

**Estimated scope:** Small (1 documentation file plus verification)

## Checkpoint H: Release Candidate — Tasks 19–20

- [ ] Every approved success criterion has focused test coverage.
- [ ] Encounter, Appointment, Prescription, role, addendum, audit, and print suites pass.
- [ ] Full Pest suite and Pint pass.
- [ ] Print audit metadata contains no clinical narrative.
- [ ] Human review confirms readiness to ship.

## Explicitly Deferred

- Mobile or appointment-request intake.
- Removal of legacy Patient Intake tables, models, or nullable foreign keys.
- Structured refraction, visual-acuity, intraocular-pressure, or diagnosis fields.
- Autorefractor integration or machine-specific fields.
- Attachments, uploads, OCR, and external services.
- PRC license or other professional credential fields.
- Reopening or editing completed Encounters.
- New dependencies.
