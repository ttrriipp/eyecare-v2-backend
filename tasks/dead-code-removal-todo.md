# Checklist: Dead Code and Unreachable Feature Removal

**Status:** Phase 3 approved 2026-08-14; implementation deferred by owner

**Specification:** `docs/specs/dead-code-removal-spec.md` (`2904eed`)

**Plan:** `tasks/dead-code-removal-plan.md` (`235595f`)

**Implementation:** Not started; do not execute without separate owner approval

**Scope:** 12 tasks in 5 phases; no implementation task exceeds five files

Four-axis verification is mandatory before deletion: class name, relation
name, table name, and route/view registration. A newly reachable consumer stops
implementation and returns the project to the specification phase.

Message attachments and privacy code are protected. References to them below
are regression checks, not removal work.

---

## Phase 1: Revalidation and schema-file hygiene

### Task 1: Refresh reachability and baseline evidence

**Description:** Repeat the approved audit against the implementation-day
worktree before changing application code. Record results here rather than
assuming the 2026-08-14 findings remain true.

**Acceptance criteria:**

- [ ] Four axes are recorded for complaints:
  - class (`Complaint`, `ComplaintStatus`, policy/action): ______
  - relation (`complaint`, `complaints`): ______
  - table (`complaints`): ______
  - route/view/Filament registration: ______
- [ ] Four axes are recorded for patient intakes:
  - class (`PatientIntake`, `IntakeStatus`, actions/command): ______
  - relation (`intake`, `intakes`): ______
  - table (`patient_intakes`, `encounters.patient_intake_id`): ______
  - route/view/Filament registration: ______
- [ ] Database counts, route evidence, focused-test result, full-suite baseline,
      protected message-attachment paths, privacy paths, and dirty-worktree
      files are recorded: ______

**Verification:**

- [ ] `rg` searches cover `app/`, `database/`, `routes/`, `resources/`, and
      `tests/`; `vendor/bin/sail artisan route:list --except-vendor` is checked.
- [ ] Read-only database/schema inspection confirms whether legacy rows exist.
- [ ] No application file changes; any new consumer returns to Phase 1.

**Dependencies:** None.

**Files likely touched:** `tasks/dead-code-removal-todo.md` only, to record
evidence.

**Estimated scope:** S

---

### Task 2: Delete the cancelled inventory-status migration pair

**Description:** Remove only the matched create/drop migrations for
`inventory_movement_statuses`. Notification migration history and the live
`inventory_movement_types` table remain unchanged.

**Acceptance criteria:**

- [ ] `2026_06_07_090019_create_inventory_movement_statuses_table.php` and
      `2026_06_19_003812_drop_inventory_movement_statuses_table.php` are deleted.
- [ ] No notification migration is added, edited, or deleted.
- [ ] `inventory_movement_types`, its model, and its consumers are unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan migrate:fresh --env=testing` succeeds.
- [ ] `rg -n 'inventory_movement_statuses|inventory_movement_types' app database
      routes resources tests` confirms only the approved pair disappeared.

**Dependencies:** Task 1.

**Files likely touched:**

- `database/migrations/2026_06_07_090019_create_inventory_movement_statuses_table.php`
- `database/migrations/2026_06_19_003812_drop_inventory_movement_statuses_table.php`

**Estimated scope:** S

### Checkpoint: Baseline locked

- [ ] Task 1 found no new supported consumer.
- [ ] Testing fresh migration succeeds.
- [ ] Notification history is unchanged.

---

## Phase 2: Complaints

### Task 3: Remove complaint fixtures, seed data, and dedicated test

**Description:** Detach demo/test consumers while the complaint runtime and
table still exist. Preserve every unrelated line in the untracked
`ScenarioCoverageSeeder.php`.

**Acceptance criteria:**

- [ ] `ComplaintFactory.php` and `ComplaintRestartTest.php` are deleted after
      confirming the test asserts only the removed workflow.
- [ ] `ClinicWorkflowSeeder` loses its complaint import, documentation entry,
      call, and method without changing other seeded workflows.
- [ ] `ScenarioCoverageSeeder` loses only complaint imports, its call, and
      `seedComplaintStatuses()`; all unrelated untracked content is preserved.

**Verification:**

- [ ] `rg -n 'Complaint|ComplaintStatus|seedComplaint' database/seeders
      database/factories tests/Feature/Complaints` returns no complaint runtime
      consumer.
- [ ] `vendor/bin/sail artisan test --compact
      tests/Feature/Seeders/ClinicWorkflowSeederTest.php` is run and any known
      unrelated baseline failure is recorded rather than expanded in scope.

**Dependencies:** Task 2.

**Files likely touched:**

- `database/factories/ComplaintFactory.php`
- `tests/Feature/Complaints/ComplaintRestartTest.php`
- `database/seeders/ClinicWorkflowSeeder.php`
- `database/seeders/ScenarioCoverageSeeder.php`

**Estimated scope:** M

---

### Task 4: Delete complaint runtime code

**Description:** Delete the now-consumerless complaint model boundary while its
table remains available until Task 10.

**Acceptance criteria:**

- [ ] `RestartComplaintWorkflow`, `ComplaintStatus`, `Complaint`, and
      `ComplaintPolicy` are deleted.
- [ ] Policy discovery/mapping is checked and contains no remaining complaint
      registration.
- [ ] Unrelated clinical “Chief Complaint” fields, labels, and tests are
      unchanged.

**Verification:**

- [ ] `rg -n '\bComplaint(Status|Policy)?\b|RestartComplaintWorkflow'
      app database routes resources tests` returns only clinical text or
      planning documentation.
- [ ] `vendor/bin/sail artisan route:list --except-vendor` contains no complaint
      route, and affected focused tests remain green.

**Dependencies:** Task 3.

**Files likely touched:**

- `app/Actions/Complaints/RestartComplaintWorkflow.php`
- `app/Enums/ComplaintStatus.php`
- `app/Models/Complaint.php`
- `app/Policies/ComplaintPolicy.php`

**Estimated scope:** M

### Checkpoint: Complaints detached

- [ ] Complaint runtime, factory, test, and both seeder blocks are absent.
- [ ] The `complaints` table remains temporarily for safe sequencing.
- [ ] Clinical chief-complaint behavior is intact.

---

## Phase 3: Patient intakes

### Task 5: Delete orphaned intake surfaces and approved legacy route

**Description:** Remove the three orphaned display surfaces and the manually
reachable legacy route whose compatibility break the owner approved. Preserve
the separate live encounter print route and dirty encounter print view.

**Acceptance criteria:**

- [ ] `HealthRecordRelationManager`, `intake-form.blade.php`, and the orphaned
      appointment `health-record-print.blade.php` are deleted.
- [ ] `appointments.health-record.print` and only its unused imports are removed
      from `routes/web.php`.
- [ ] `/encounters/{encounter}/print`, its tests, and
      `resources/views/filament/encounters/print.blade.php` are untouched.

**Verification:**

- [ ] `vendor/bin/sail artisan route:list --except-vendor` shows no appointment
      health-record print route and still shows the encounter print route.
- [ ] `vendor/bin/sail artisan test --compact
      tests/Feature/Encounters/EncounterPrintTest.php
      tests/Feature/Encounters/EncounterPrintAuditTest.php` passes.

**Dependencies:** Task 4.

**Files likely touched:**

- `app/Filament/Resources/Patients/RelationManagers/HealthRecordRelationManager.php`
- `resources/views/filament/resources/appointments/pages/intake-form.blade.php`
- `resources/views/filament/resources/appointments/pages/health-record-print.blade.php`
- `routes/web.php`

**Estimated scope:** M

---

### Task 6: Remove intake integration from encounter creation and tests

**Description:** Stop encounter code and characterization tests from reading or
writing `patient_intake_id` before deleting intake-specific code.

**Acceptance criteria:**

- [ ] `CheckInAppointment`, `EncounterResource`, and `EncounterFactory` no
      longer write, eager-load, or supply intake state.
- [ ] `EncounterLifecycleCharacterizationTest` removes legacy intake setup and
      intake-only assertions while retaining encounter behavior coverage.
- [ ] `EncounterCheckInTest` removes only `patient_intake_id` assertions and
      retains its check-in assertions.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact
      tests/Feature/Encounters/EncounterCheckInTest.php
      tests/Feature/Encounters/EncounterLifecycleCharacterizationTest.php`
      passes.
- [ ] `rg -n 'patient_intake_id|with\(.?intake'` over the five files returns no
      integration reference.

**Dependencies:** Task 5.

**Files likely touched:**

- `app/Actions/Encounters/CheckInAppointment.php`
- `app/Filament/Resources/Encounters/EncounterResource.php`
- `database/factories/EncounterFactory.php`
- `tests/Feature/Encounters/EncounterLifecycleCharacterizationTest.php`
- `tests/Feature/Encounters/EncounterCheckInTest.php`

**Estimated scope:** M

---

### Task 7: Delete intake-only tests and factory

**Description:** Remove tests and fixtures whose entire subject is the dead
intake subsystem after encounter characterization no longer depends on them.

**Acceptance criteria:**

- [ ] `PatientIntakeTest`, `IntakeVerificationTest`, and
      `LegacyIntakeCleanupAuditTest` are deleted, not skipped.
- [ ] `PatientIntakeFactory` is deleted and no retained test references it.
- [ ] `PrescriptionLifecycleTest` is confirmed to preserve the equivalent
      clinical authorization boundary before `IntakeVerificationTest` is removed.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact
      tests/Feature/Encounters/PrescriptionLifecycleTest.php` passes.
- [ ] `rg -n 'PatientIntakeFactory|PatientIntake::factory|IntakeVerification'
      tests database/factories` returns no retained reference.

**Dependencies:** Task 6.

**Files likely touched:**

- `tests/Feature/Encounters/PatientIntakeTest.php`
- `tests/Feature/Encounters/IntakeVerificationTest.php`
- `tests/Feature/Encounters/LegacyIntakeCleanupAuditTest.php`
- `database/factories/PatientIntakeFactory.php`

**Estimated scope:** M

---

### Task 8: Delete intake actions and audit machinery

**Description:** Remove the intake lifecycle entry points, legacy-readiness
audit, command, and intake-only audit event cases before deleting their model.

**Acceptance criteria:**

- [ ] `ReturnIntakeForCorrection`, `VerifyPatientIntake`, and
      `AuditLegacyPatientIntakes` are deleted.
- [ ] `AuditLegacyPatientIntakesCommand` is deleted and no command registration
      remains.
- [ ] Only `AuditEvent::IntakeSubmitted`, `IntakeVerified`, and
      `IntakeReturnedForCorrection` are removed from the enum.

**Verification:**

- [ ] `vendor/bin/sail artisan list` contains no
      `encounters:audit-legacy-intakes` command.
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters` passes.

**Dependencies:** Task 7.

**Files likely touched:**

- `app/Actions/Intakes/ReturnIntakeForCorrection.php`
- `app/Actions/Intakes/VerifyPatientIntake.php`
- `app/Actions/Encounters/AuditLegacyPatientIntakes.php`
- `app/Console/Commands/AuditLegacyPatientIntakesCommand.php`
- `app/Enums/AuditEvent.php`

**Estimated scope:** M

---

### Task 9: Delete intake model, enum, and Eloquent relations

**Description:** Remove the final intake domain types and relation accessors
while the schema remains available until Task 10.

**Acceptance criteria:**

- [ ] `PatientIntake` and `IntakeStatus` are deleted.
- [ ] `Patient::intakes()` and `Appointment::intake()` are removed with their
      unused imports and PHPDoc.
- [ ] `Encounter::intake()` and `patient_intake_id` fillable state are removed
      without changing other encounter fields or relations.

**Verification:**

- [ ] `rg -n 'PatientIntake|IntakeStatus|patient_intake_id|function intakes?'
      app database/factories routes resources tests` returns only migrations or
      planning documentation.
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters` passes.

**Dependencies:** Task 8.

**Files likely touched:**

- `app/Models/PatientIntake.php`
- `app/Enums/IntakeStatus.php`
- `app/Models/Patient.php`
- `app/Models/Appointment.php`
- `app/Models/Encounter.php`

**Estimated scope:** M

### Checkpoint: Intakes detached

- [ ] No retained runtime or test code references intake types or relations.
- [ ] The live encounter print route and view remain intact.
- [ ] Focused encounter and conversation suites are green.

---

## Phase 4: Contract schema

### Task 10: Add the reversible cleanup migration

**Description:** Contract the schema only after every complaint and intake
consumer has been removed. Generate the migration through Artisan, then define
complete reversible schemas from the original migrations.

**Acceptance criteria:**

- [ ] `vendor/bin/sail artisan make:migration
      drop_dead_intake_and_complaint_schema --no-interaction` creates one
      migration; no notification migration is created.
- [ ] `up()` drops the encounter foreign key/column before `patient_intakes`,
      then drops `complaints`.
- [ ] `down()` recreates `complaints`, recreates the complete
      `patient_intakes` schema, then restores the nullable encounter foreign key
      in dependency-safe order.

**Verification:**

- [ ] `vendor/bin/sail artisan migrate:fresh --env=testing` succeeds.
- [ ] The latest migration can be rolled back and reapplied under
      `--env=testing` without schema errors.
- [ ] `vendor/bin/sail artisan migrate:fresh --seed --env=testing` has no
      complaint/intake reference failure; unrelated known seeder failures are
      recorded separately.

**Dependencies:** Task 9.

**Files likely touched:**

- `database/migrations/<timestamp>_drop_dead_intake_and_complaint_schema.php`

**Estimated scope:** S

### Checkpoint: Schema contracted

- [ ] `complaints` and `patient_intakes` are absent.
- [ ] `encounters.patient_intake_id` is absent.
- [ ] Migration rollback/reapply works in the testing environment.

---

## Phase 5: Documentation and final audit

### Task 11: Reconcile backend context documentation

**Description:** Update the canonical backend context after the actual code and
schema diff is known. Do not rewrite historical specs or ADRs.

**Acceptance criteria:**

- [ ] Patient-intake, complaint, removed audit command, schema, action, and
      encrypted-field documentation matches the final application.
- [ ] The permission matrix describes audit logs as reachable and privacy
      administration as backend-only with no panel UI.
- [ ] Message attachments, live encounter printing, privacy code, and live
      inventory behavior remain documented.

**Verification:**

- [ ] Closing `rg` searches find no claim that a removed subsystem or route is
      available.
- [ ] Documentation diff contains no unrelated architectural rewrite.

**Dependencies:** Task 10.

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`

**Estimated scope:** S

---

### Task 12: Run final protected-surface and regression audit

**Description:** Prove the removal is complete and that protected working
features were not changed. This task is verification-only unless a scoped
failure requires returning to its owning task.

**Acceptance criteria:**

- [ ] Closing class/relation/table/route searches find no runtime intake or
      complaint orphan and no `inventory_movement_statuses` migration pair.
- [ ] Message upload/download/preview/API/Filament paths and all privacy code
      remain present and pass their focused tests.
- [ ] Final diff contains only approved files; unrelated dirty work remains
      preserved and the full suite introduces no failure beyond the recorded 25.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters
      tests/Feature/ConversationTest.php tests/Feature/ConversationChatPageTest.php
      tests/Feature/Privacy` is run.
- [ ] `vendor/bin/sail artisan test --compact` is compared with the recorded
      baseline; `vendor/bin/sail artisan migrate:fresh --env=testing` succeeds.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`, `git diff --check`,
      route inspection, schema inspection, and final `rg` searches pass.

**Dependencies:** Task 11.

**Files likely touched:** None expected. Findings return to the task that owns
the affected file.

**Estimated scope:** S

### Checkpoint: Complete

- [ ] All nine specification success criteria pass.
- [ ] Message attachments and privacy code are unchanged and tested.
- [ ] No new full-suite failure exists relative to the recorded baseline.
- [ ] Implementation is ready for owner review.

---

## Phase 3 Approval

- [x] Owner approved this 12-task checklist on 2026-08-14.
- [ ] Owner separately authorizes Phase 4 implementation.
