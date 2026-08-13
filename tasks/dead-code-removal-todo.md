# Checklist: Dead Code and Unreachable Feature Removal

**Specification:** `docs/specs/dead-code-removal-spec.md`
**Plan:** `tasks/dead-code-removal-plan.md`
**Status:** Not started — 9 tasks in 4 phases

Four-axis verification is mandatory before any deletion. Record the result of
each axis inline; a blank axis blocks the task.

**Re-run the verification before starting.** These results were gathered on
2026-08-13 and the audit behind them has been wrong five times across three
passes. Do not trust a recorded result you did not produce.

For Filament classes and Blade views, check **registration**, not just
references: a relation manager must be absent from `getRelations()`, a page
from `getPages()`, a view must have no renderer. A grep proves a reference
exists; it does not prove a user can reach it.

---

## Phase 1: Dead schema

### Task 1: Drop the dead notification tables and delete the stale migration pair

**Description:** `notification_channels` and `notification_templates` exist only
as migrations — no model, no seeder, zero references on any axis. Drop them with
a new migration. Separately, `inventory_movement_statuses` was already dropped
in June by `2026_06_19_003812`; its create/drop pair now cancels out on every
`migrate:fresh`, so delete both files rather than leaving matched no-ops.

**Acceptance criteria:**

- [ ] Four axes recorded for `notification_channels`:
  - [ ] class name — no model exists: ______
  - [ ] relation name — no accessor: ______
  - [ ] table name across `app/ database/ routes/ resources/`: ______
  - [ ] route / view: ______
- [ ] Four axes recorded for `notification_templates`: ______
- [ ] New migration drops both tables, with a `down()` that recreates them
- [ ] `2026_06_06_020934_create_notification_channels_table.php` and
      `2026_06_06_021106_create_notification_templates_table.php` remain
      (history stays intact for tables that existed until now)
- [ ] `2026_06_07_090019_create_inventory_movement_statuses_table.php` and
      `2026_06_19_003812_drop_inventory_movement_statuses_table.php` are deleted
- [ ] `inventory_movement_types` is untouched and still live

**Verification:** `vendor/bin/sail artisan migrate:fresh --seed` succeeds;
`vendor/bin/sail artisan test --compact` green.

**Dependencies:** None.

**Files likely touched:** 1 new migration, 2 deleted migrations.

**Estimated scope:** S

---

## Phase 2: Complaints

### Task 2: Verify complaints unreachable on four axes

**Description:** Prove the complaints workflow has no front door before deleting
it. The first audit pass got complaints wrong in *both* directions — reporting
it as UI-coupled because of the unrelated "Chief Complaint" clinical field, then
reporting it as fully unreferenced when `ClinicWorkflowSeeder` creates one. This
task is verification only; it changes no code.

**Acceptance criteria:**

- [ ] Axis 1 — `grep -rn "\bComplaint\b" app/ routes/ database/ tests/ resources/`,
      results classified as model vs. "Chief Complaint" string: ______
- [ ] Axis 2 — relation names `complaints` / `complaint` on `Patient` and
      `JobOrder`, and every caller of them: ______
- [ ] Axis 3 — `grep -rn "complaints" app/ database/ routes/ resources/views/`: ______
- [ ] Axis 4 — `artisan route:list | grep -i complaint` and a grep of
      `resources/views/`: ______
- [ ] Confirmed: no Filament resource, page, relation manager, or widget targets
      `Complaint`
- [ ] Full list of files to delete recorded in Task 3

**Verification:** All four axis results written into this checklist. Any live
reference found halts the phase and returns to the spec.

**Dependencies:** None.

**Files likely touched:** None — this checklist only.

**Estimated scope:** S

---

### Task 3: Delete complaints code, factory, test, and seeder block

**Description:** Remove the complaints subsystem's code now that Task 2 has
proven it unreachable. The table itself stays until Task 4, so a mistake here
surfaces as a failing test rather than a broken database.

**Acceptance criteria:**

- [ ] `app/Models/Complaint.php`, `app/Enums/ComplaintStatus.php`,
      `app/Policies/ComplaintPolicy.php`, and `app/Actions/Complaints/` deleted
- [ ] `Patient` and `JobOrder` complaint relations removed
- [ ] `ComplaintPolicy` deregistered wherever policies are mapped
- [ ] `database/factories/ComplaintFactory.php` deleted
- [ ] `tests/Feature/Complaints/ComplaintRestartTest.php` deleted (deleted, not
      skipped) — confirm it asserts nothing that applies elsewhere
- [ ] `ClinicWorkflowSeeder::seedComplaint()` and its call site removed, along
      with the now-unused imports and the docblock's complaint line

**Verification:** `vendor/bin/sail artisan test --compact` green;
`vendor/bin/sail artisan migrate:fresh --seed` succeeds with the table still
present but unused.

**Dependencies:** Task 2.

**Files likely touched:** ~5 deleted, 3 edited (`Patient`, `JobOrder`,
`ClinicWorkflowSeeder`).

**Estimated scope:** S

---

### Task 4: Drop the `complaints` table

**Description:** Drop the table now that nothing references it. Split from
Task 3 so the irreversible schema change sits behind a green suite.

**Acceptance criteria:**

- [ ] Migration drops `complaints`, with a `down()` recreating it
- [ ] `grep -rn "complaints"` across `app/ database/ routes/ resources/`
      returns nothing outside migrations
- [ ] No foreign key elsewhere referenced `complaints` (verify before dropping)

**Verification:** `migrate:fresh --seed` succeeds; full suite green.

**Dependencies:** Task 3.

**Files likely touched:** 1 new migration.

**Estimated scope:** S

---

### ✅ Checkpoint: Complaints

- [ ] Four-axis results recorded for `Complaint`, `complaints`, `ComplaintStatus`
- [ ] `grep -rn "Complaint"` returns only "Chief Complaint" clinical matches
- [ ] `migrate:fresh --seed` succeeds
- [ ] Full suite green, Pint clean

---

## Phase 3: Patient intakes

### Task 5: Delete the three orphaned intake surfaces and the print route

**Description:** Three surfaces read the `intake` relation, and none of them is
registered — this was established only on the audit's third pass, after two
earlier passes got it wrong in opposite directions. Delete them before the
model they read, so Task 6 has nothing left pointing at `PatientIntake`.

**Prove unreachability first — registration, not just references:**

- [ ] `HealthRecordRelationManager` is absent from
      `PatientResource::getRelations()` (which lists Prescriptions,
      Appointments, Encounters, OpticalOrders, Billing, InvitationHistory): ______
- [ ] No page class defines `getIntake()` / `getIntakeStatus()` —
      `app/Filament/Resources/Appointments/Pages/` holds only
      `AppointmentRequestsPage`, `CreateAppointment`, `EditAppointment`,
      `ListAppointments`: ______
- [ ] `health-record-print.blade.php` is rendered only by the
      `appointments.health-record.print` route, and that route is linked only
      from `intake-form.blade.php:31`: ______
- [ ] `artisan route:list | grep health-record` shows the route exists but no
      panel UI links to it: ______

**Acceptance criteria:**

- [ ] `app/Filament/Resources/Patients/RelationManagers/HealthRecordRelationManager.php`
      deleted
- [ ] `resources/views/filament/resources/appointments/pages/intake-form.blade.php`
      deleted
- [ ] `resources/views/filament/resources/appointments/pages/health-record-print.blade.php`
      deleted
- [ ] The `appointments.health-record.print` route removed from `routes/web.php`,
      along with its now-unused imports
- [ ] `/encounters/{encounter}/print` is **untouched** — it is a different,
      live route with its own tests (`EncounterPrintTest`, `EncounterPrintAuditTest`)
- [ ] `PatientIntake` and the rest of the intake code still exist and still pass

**Verification:** `vendor/bin/sail artisan test --compact` green;
`artisan route:list | grep health-record` returns nothing;
`artisan route:list | grep encounters` still shows the encounter printout.

**Dependencies:** None (Phase 2 recommended first per plan Decision 1).

**Files likely touched:** 3 deleted, `routes/web.php` edited.

**Estimated scope:** S

---

### Task 6: Delete intake code and tests

**Description:** With no surface reading `intake` any more, remove the
subsystem. The schema stays until Task 7.

**Acceptance criteria:**

- [ ] Four axes recorded for `PatientIntake`:
  - [ ] class name: ______
  - [ ] relation name `intake` / `intakes`: ______
  - [ ] table name `patient_intakes`: ______
  - [ ] route / view: ______
- [ ] `app/Models/PatientIntake.php`, `app/Enums/IntakeStatus.php`, and
      `app/Actions/Intakes/` deleted
- [ ] `Encounter::intake()`, `Appointment::intake()`, `Patient::intakes()` removed
- [ ] `patient_intake_id` removed from `Encounter`'s `Fillable` attribute,
      `EncounterFactory`, and `CheckInAppointment`
- [ ] `'intake'` removed from `EncounterResource::getEloquentQuery()`'s eager-loads
- [ ] `AuditLegacyPatientIntakes` and `AuditLegacyPatientIntakesCommand`
      (`encounters:audit-legacy-intakes`) deleted
- [ ] `AuditEvent::IntakeSubmitted`, `IntakeVerified`, and
      `IntakeReturnedForCorrection` removed
- [ ] `PatientIntakeFactory`, `PatientIntakeTest`, `IntakeVerificationTest`,
      `LegacyIntakeCleanupAuditTest` deleted
- [ ] `EncounterLifecycleCharacterizationTest` and `ClinicWorkflowSeeder`
      updated to drop intake setup
- [ ] **Role boundary preserved:** `IntakeVerificationTest`'s last case asserts
      that non-optometrist staff verifying an intake does not authorize clinical
      findings. Confirm `PrescriptionLifecycleTest:45` covers the equivalent
      clinical-side boundary; write the missing case if it does not: ______

**Verification:** `vendor/bin/sail artisan test --compact`;
`artisan list | grep audit-legacy` returns nothing.

**Dependencies:** Task 5.

**Files likely touched:** ~9 deleted, ~7 edited. If this feels wide during
implementation, split the model/relation deletions from the audit-command and
audit-event removals — they share no file.

**Estimated scope:** M

---

### Task 7: Drop `patient_intakes` and `encounters.patient_intake_id`

**Description:** Drop the schema now that no code references it. The column
drop must come with the table drop, since the foreign key points at it.

**Acceptance criteria:**

- [ ] Migration drops `encounters.patient_intake_id` (and its foreign key)
      before dropping `patient_intakes`
- [ ] `down()` recreates both in the correct order
- [ ] `grep -rn "patient_intake"` across `app/ database/ routes/ resources/ tests/`
      returns nothing outside migrations

**Verification:** `migrate:fresh --seed` succeeds; full suite green.

**Dependencies:** Task 6.

**Files likely touched:** 1 new migration.

**Estimated scope:** S

---

### ✅ Checkpoint: Intakes

- [ ] Registration absence recorded for all three surfaces, not just reference
      absence
- [ ] `artisan route:list | grep health-record` returns nothing
- [ ] `/encounters/{encounter}/print` still works and still passes its tests
- [ ] The role boundary formerly asserted by `IntakeVerificationTest` is still
      covered
- [ ] Four-axis results recorded for `PatientIntake`, `intake`, `patient_intakes`
- [ ] `migrate:fresh --seed` succeeds
- [ ] Full suite green, Pint clean

---

## Phase 4: Documentation and closing audit

### Task 8: Update `BACKEND_CONTEXT.md`

**Description:** Bring the canonical doc in line with the code, including the
one privacy change in scope: the permission matrix advertises "Audit logs and
privacy administration" as an admin capability, but privacy administration has
no UI. Audit logs are real; the matrix must stop promising a front door that
does not exist.

**Acceptance criteria:**

- [ ] No mention of patient intakes, complaints, or the dead notification tables
- [ ] Permission matrix corrected — privacy administration is documented as
      backend-only with no panel surface
- [ ] No mention of a "Visit History" tab or an appointment-level health-record
      printout — neither was reachable, and both are gone
- [ ] `encounters:audit-legacy-intakes` removed from any command listing
- [ ] Privacy compliance otherwise described exactly as it is: kept, no UI

**Verification:** `grep -rn "intake\|complaint\|notification_channel"
docs/BACKEND_CONTEXT.md` returns only "Chief Complaint" clinical references.

**Dependencies:** Task 7.

**Files likely touched:** `docs/BACKEND_CONTEXT.md`.

**Estimated scope:** S

---

### Task 9: Closing orphan audit

**Description:** An independent sweep, not a re-run of the per-task checks.
Three findings in this project's audit history were produced by trusting a
single search, so the closing check looks for orphans in every direction rather
than confirming the symbols already known to be gone.

**Acceptance criteria:**

- [ ] Every table in the schema has a model, or is a documented pivot/ledger
- [ ] Every model has a table
- [ ] Every Blade view under `resources/views/filament/` has something that
      renders it — this is how `intake-form.blade.php` was found
- [ ] Message attachments verified live end to end: upload, download
      (`GET /api/v1/conversation/attachments/{attachment}`), preview,
      `MessageResource`, `ConversationResource.can_upload_attachments`,
      `ConversationChatPage`, `MessagesRelationManager`
- [ ] Privacy compliance code byte-identical to its pre-project state
      (`git diff` over `app/Actions/Privacy/`, the models, policy, and tests)
- [ ] `audit_logs` and `inventory_movements` untouched

**Verification:** `migrate:fresh --seed`; full suite green;
`vendor/bin/sail bin pint --dirty --format agent` reports no changes.

**Dependencies:** Task 8.

**Files likely touched:** None expected — findings become follow-up tasks.

**Estimated scope:** S

---

### ✅ Checkpoint: Complete

- [ ] `grep -rn "PatientIntake\|IntakeStatus\|Complaint\b\|notification_channels\|notification_templates"`
      returns only spec files
- [ ] Message attachments still work end to end
- [ ] Privacy compliance code untouched
- [ ] `migrate:fresh --seed` succeeds; full suite green; Pint clean
- [ ] Ready for review
