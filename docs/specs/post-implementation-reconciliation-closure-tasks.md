# Tasks: Post-Implementation Reconciliation Closure

## Status

Approved by the project owner on 2026-07-27. Phase 3 is complete. The Phase A
portion of Phase 4, Tasks 1–5 and Checkpoint A, is complete. Phase B has
started; Tasks 6–7 are complete and work continues with Task 8.

These tasks implement the approved closure specification and technical plan.

## Execution Rules

For every implementation task:

1. read the relevant approved spec and plan section;
2. search installed Laravel/Filament documentation before PHP changes;
3. write or restore the focused Pest assertion before changing behavior;
4. change no more than five primary files;
5. run the smallest affected suite through Sail;
6. run `vendor/bin/sail bin pint --dirty --format agent` after PHP edits;
7. inspect the diff for unrelated work and secrets;
8. commit one coherent, passing increment; and
9. mark checkboxes only with the commit and exact evidence.

If a task requires more than five primary files, stop and revise this task list
before expanding its scope. Deletions remain replacement-first.

## Dependency Graph

```text
1 truthful claims
  -> 2-5 Appointment Type closure
  -> 6-7 dead fixture removal
  -> 8-11 canonical migrations
  -> 12-13 exact patient API
  -> 14 coverage audit
       -> 15-18 retained coverage
       -> 19 canonical seeded journeys
  -> 20 automated evidence
  -> 21 browser evidence
  -> 22 restore/context/final closure
```

## Progress Tracker

### Phase A: Truth and Appointment Type

- [x] Task 1: Reopen contradicted claims
- [x] Task 2: Lock Appointment Type regressions with populated records
      (`7dd2f78`, `b270545`, `4e872d0`)
- [x] Task 3: Cut core Appointment screens over to Appointment Type
      (`7dd2f78`, `b270545`)
- [x] Task 4: Cut schedule widgets over to Appointment Type
      (`4e872d0`)
- [x] Task 5: Delete the replaced Visit Reason domain
      (`ed19a27`)

### Phase B: Dead Support and Canonical Schema

- [x] Task 6: Remove dead Order test-data support
- [x] Task 7: Remove dead Billing test-data support
- [ ] Task 8: Create Patients and Appointments canonically
- [ ] Task 9: Create Prescriptions canonically
- [ ] Task 10: Create Conversations canonically
- [ ] Task 11: Create Feedback canonically

### Phase C: Exact Patient API

- [ ] Task 12: Enforce the approved 34-route equality contract
- [ ] Task 13: Prove Conversation and attachment isolation

### Phase D: Retained Coverage and Seeded Journeys

- [ ] Task 14: Audit and assign every restore-required row
- [ ] Task 15: Close Appointment, identity, and Intake coverage
- [ ] Task 16: Close communication and clinical-read coverage
- [ ] Task 17: Close catalog, inventory, finance, and print coverage
- [ ] Task 18: Close staff authorization, notification, and audit coverage
- [ ] Task 19: Prove canonical seeded clinic journeys

### Phase E: Release Evidence

- [ ] Task 20: Run all automated release gates
- [ ] Task 21: Run optometrist and receptionist browser journeys
- [ ] Task 22: Validate restoration and reconcile final context

## Task 1: Reopen Contradicted Claims

**Description:** Restore an honest starting state before changing code.

**Acceptance criteria:**

- [x] Tasks 13, 16–17, 24–28, 35–36, and 39–40 are marked open.
- [x] Checkpoints B, D, E, and F are reopened where evidence is contradicted.
- [x] Prior commit references remain intact with concise reopening reasons.

**Verification:**

- [x] `git diff --check`
- [x] Reopened claims match the closure specification audit baseline.

**Dependencies:** None.

**Likely files:** parent reconciliation tasks, recovery map, closure task list.

**Scope:** Small, three documentation files.

## Task 2: Lock Appointment Type Regressions With Populated Records

**Description:** Add tests that fail while real Appointment records or staff
views still depend on Visit Reason.

**Acceptance criteria:**

- [x] Appointment model coverage requires the `appointmentType` relationship.
- [x] Staff table/edit coverage uses a populated Appointment Type record.
- [x] Widget coverage cannot pass because a legacy relationship is empty.

**Verification:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/AppointmentModelTest.php`
- [x] Focused Appointment Filament tests failed for only the audited mismatch
      before Tasks 3–4 made them pass.

**Dependencies:** Task 1.

**Likely files:** `AppointmentModelTest.php`, `DashboardTest.php`, and one
Appointment resource/calendar test.

**Scope:** Medium, at most three test files.

## Task 3: Cut Core Appointment Screens Over to Appointment Type

**Description:** Remove the legacy relationship from the model and non-widget
Appointment presentation.

**Acceptance criteria:**

- [x] Appointment has no `visit_reason_id` fillable field or `visitReason()`.
- [x] Edit, table, and Patient relation views use `appointmentType`.
- [x] Labels consistently say “Appointment Type.”

**Verification:**

- [x] Task 2 model and Appointment resource tests pass.
- [x] `rg -n "visitReason|visit_reason_id" app/Models/Appointment.php app/Filament/Resources`
- [x] Pint passes for changed PHP.

**Dependencies:** Task 2.

**Likely files:** Appointment model, EditAppointment, AppointmentsTable,
AppointmentsRelationManager, one focused test.

**Scope:** Medium, five files.

## Task 4: Cut Schedule Widgets Over to Appointment Type

**Description:** Correct dashboard and calendar eager loads, labels, and event
payloads.

**Acceptance criteria:**

- [x] Today’s Schedule displays populated Appointment Type names.
- [x] Calendar events and actions load only `appointmentType`.
- [x] Duration-aware scheduling behavior remains unchanged.

**Verification:**

- [x] Focused dashboard and calendar tests pass.
- [x] Appointment scheduling tests pass.
- [x] Pint passes for changed PHP.

**Dependencies:** Task 3.

**Likely files:** TodaysScheduleWidget, AppointmentCalendarWidget,
DashboardTest, one calendar test.

**Scope:** Medium, four files.

## Task 5: Delete the Replaced Visit Reason Domain

**Description:** Remove the obsolete model and test-data wiring after all
runtime consumers have replacements.

**Acceptance criteria:**

- [x] VisitReason model, factory, and seeder are deleted.
- [x] DatabaseSeeder does not call VisitReasonSeeder.
- [x] Canonical seeding and Appointment tests pass.

**Verification:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Seeders`
- [x] `rg -n "visit_reason|VisitReason" app database routes tests` returns only
      intentional negative regression assertions.
- [x] Pint passes for changed PHP.

**Dependencies:** Tasks 3–4.

**Likely files:** VisitReason model, factory, seeder, DatabaseSeeder,
CanonicalSeederTest.

**Scope:** Medium, five files.

## Checkpoint A

- [x] Tasks 1–5 have passing focused tests and atomic commits.
- [x] No executable Visit Reason reference remains.
- [x] Appointment Type, referral, duration, and intake behavior still pass.
- [x] The application is runnable before schema consolidation.

Phase A evidence: the focused checkpoint passed 143 tests and 413 assertions;
the final full regression passed 424 tests and 1,071 assertions.

## Task 6: Remove Dead Order Test-Data Support

**Description:** Delete factories and seeding that reference the removed Order
aggregate without changing canonical Job Orders.

**Acceptance criteria:**

- [x] Order, OrderItem, and OrderStatus factories are deleted.
- [x] OrderStatusSeeder is deleted and no longer invoked.
- [x] Job Order, inventory, and canonical seeder tests remain green.

**Verification:**

- [x] Focused Job Order, inventory, and seeder tests pass:
      `vendor/bin/sail artisan test --compact tests/Feature/Seeders tests/Feature/Invoices tests/Feature/PrintingTest.php tests/Feature/Filament/InvoiceResourceTest.php tests/Feature/Api/V1/InvoiceTest.php tests/Feature/JobOrders tests/Feature/Inventory tests/Feature/Filament/JobOrderResourceTest.php tests/Feature/Filament/InventoryResourceTest.php tests/Feature/Filament/InventoryMovementResourceTest.php tests/Feature/Api/V1/JobOrderTest.php`
      passed 88 tests and 236 assertions.
- [x] Static scans find no missing Order model import:
      `rg -n -F "App\\Models\\Order" app database tests routes --glob "*.php"`.

**Dependencies:** Task 5.

**Likely files:** three Order factories, OrderStatusSeeder,
CanonicalSeederTest.

**Scope:** Medium, five files.

## Task 7: Remove Dead Billing Test-Data Support

**Description:** Delete factories and seeding that reference removed Billing
structures without changing canonical Invoices.

**Acceptance criteria:**

- [x] Billing, BillingItem, and BillingStatus factories are deleted.
- [x] BillingStatusSeeder is deleted and no longer invoked.
- [x] Invoice, payment, print, and canonical seeder tests remain green.

**Verification:**

- [x] Focused Invoice, payment, print, and seeder tests pass:
      `vendor/bin/sail artisan test --compact tests/Feature/Seeders tests/Feature/Invoices tests/Feature/PrintingTest.php tests/Feature/Filament/InvoiceResourceTest.php tests/Feature/Api/V1/InvoiceTest.php tests/Feature/JobOrders tests/Feature/Inventory tests/Feature/Filament/JobOrderResourceTest.php tests/Feature/Filament/InventoryResourceTest.php tests/Feature/Filament/InventoryMovementResourceTest.php tests/Feature/Api/V1/JobOrderTest.php`
      passed 88 tests and 236 assertions.
- [x] Static scans find no missing Billing model import:
      `rg -n -F "App\\Models\\Billing" app database tests routes --glob "*.php"`.

**Dependencies:** Task 6.

**Likely files:** three Billing factories, BillingStatusSeeder,
CanonicalSeederTest.

**Scope:** Medium, five files.

## Task 8: Create Patients and Appointments Canonically

**Description:** Reorder the undeployed Patient creation and make the original
Appointment migration express its final required relationships directly.

**Acceptance criteria:**

- [ ] Patients exist before Appointments in fresh migration order.
- [ ] Appointment `patient_id`, `appointment_type_id`, and
      `duration_minutes` are non-null.
- [ ] Appointment Type deletion is restrictive and Patient foreign-key
      behavior matches the approved lifecycle.

**Verification:**

- [ ] A focused canonical-schema Pest test passes.
- [ ] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] Foreign-key and nullability inspection matches the specification.

**Dependencies:** Tasks 5–7.

**Likely files:** Patient creation migration, Appointment creation migration,
one canonical-schema test.

**Scope:** Medium, three files.

## Task 9: Create Prescriptions Canonically

**Description:** Fold Patient/Encounter ownership and encryption-compatible
final columns into Prescription creation.

**Acceptance criteria:**

- [ ] Prescription creation uses `patient_id` and optional `encounter_id`
      directly.
- [ ] No Prescription migration creates `customer_id`.
- [ ] Superseded Patient-link and encryption transition migrations are removed
      only after their final behavior is preserved.

**Verification:**

- [ ] Canonical-schema and Prescription lifecycle tests pass.
- [ ] Fresh migrate/seed passes.
- [ ] Migration scans find no Prescription `customer_id`.

**Dependencies:** Task 8.

**Likely files:** Prescription creation migration, Patient-link migration,
encryption migration, canonical-schema test.

**Scope:** Medium, four files.

## Task 10: Create Conversations Canonically

**Description:** Make Conversation creation Patient-owned and reduce the
messaging rework migration to canonical structures only.

**Acceptance criteria:**

- [ ] Conversations are created with unique `patient_id`.
- [ ] Conversation creation never introduces customer, staff, Appointment,
      Order, or subject columns.
- [ ] Message context links are still created without a legacy drop sequence.

**Verification:**

- [ ] Canonical-schema and Conversation tests pass.
- [ ] Fresh migrate/seed passes.
- [ ] Migration scans find no Conversation `customer_id` or `order_id`.

**Dependencies:** Task 9.

**Likely files:** Conversation creation migration, messaging rework migration,
Patient transition migration, canonical-schema test.

**Scope:** Medium, four files.

## Task 11: Create Feedback Canonically

**Description:** Complete the Patient-owned Feedback creation and remove the
now-empty Patient transition migration.

**Acceptance criteria:**

- [ ] Feedback is created directly with `patient_id`.
- [ ] Feedback creation contains no `customer_id` or `order_id`.
- [ ] The superseded Patient transition migration is deleted.

**Verification:**

- [ ] Canonical-schema, Feedback, and moderation tests pass.
- [ ] Fresh migrate/seed passes.
- [ ] Migration scans find no Feedback legacy ownership.

**Dependencies:** Task 10.

**Likely files:** Feedback creation migration, Patient transition migration,
canonical-schema test.

**Scope:** Medium, three files.

## Checkpoint B

- [ ] Tasks 6–11 have passing focused tests and atomic commits.
- [ ] Fresh migration and seed pass.
- [ ] Canonical foreign keys, indexes, and nullability are inspected.
- [ ] No final migration creates a legacy identity or commerce structure only
      to remove it later.

## Task 12: Enforce the Approved 34-Route Equality Contract

**Description:** Make the route test authoritative, align route paths, and
remove the unapproved staff API infrastructure.

**Acceptance criteria:**

- [ ] The equality fixture contains exactly the approved 34 method/path pairs.
- [ ] Conversation paths are singular and attachment download is nested.
- [ ] The staff mutation, controller, middleware, and unintended aliases are
      absent.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php`
- [ ] `vendor/bin/sail artisan route:list --except-vendor --path=api`
- [ ] Pint passes for changed PHP.

**Dependencies:** Tasks 8–11.

**Likely files:** `routes/api.php`, RouteContractTest,
StaffAppointmentController, EnsureUserIsStaff.

**Scope:** Medium, four files.

## Task 13: Prove Conversation and Attachment Isolation

**Description:** Update endpoint tests and prove private attachment membership,
ownership, and cross-patient denial under the singular contract.

**Acceptance criteria:**

- [ ] Own Conversation, message list, and send behavior use singular paths.
- [ ] Attachment downloads require ownership of the containing Conversation.
- [ ] An attachment outside that Conversation or owned by another Patient
      returns no protected data.

**Verification:**

- [ ] Focused Conversation/API tests pass.
- [ ] Privacy and authentication suites pass.
- [ ] Pint passes for changed PHP.

**Dependencies:** Task 12.

**Likely files:** ConversationTest, MessagingFeedbackRatingTest,
ConversationController, at most one attachment-focused test.

**Scope:** Medium, four files.

## Checkpoint C

- [ ] Tasks 12–13 have passing focused tests and atomic commits.
- [ ] Route equality reports exactly 34 approved patient routes.
- [ ] No staff or legacy mutation remains in the patient API.
- [ ] Conversation and attachment cross-patient negatives pass.

## Task 14: Audit and Assign Every Restore-Required Row

**Description:** Compare each recovery-map assertion with current test content
before writing more tests.

**Acceptance criteria:**

- [ ] Every restore-required row names an existing passing test or one of Tasks
      15–18 as its recovery owner.
- [ ] Obsolete assertions remain explicitly excluded.
- [ ] No row is closed based only on a test count or claim-only commit.

**Verification:**

- [ ] Recovery map covers all 46 restore-required rows exactly once.
- [ ] Named existing tests can be found and run.
- [ ] `git diff --check`

**Dependencies:** Tasks 1–13.

**Likely files:** recovery map only.

**Scope:** Small, one documentation file.

## Task 15: Close Appointment, Identity, and Intake Coverage

**Description:** Restore missing patient booking, scheduling, authentication,
profile, token, and Intake assertions identified by Task 14.

**Acceptance criteria:**

- [ ] Appointment lifecycle, duration, capacity, concurrency, and isolation are
      mapped to passing tests.
- [ ] Registration, login/logout, `/me`, token, validation, and throttling are
      mapped to passing tests.
- [ ] Intake draft, submit, immutability, verification, and isolation are
      mapped to passing tests.

**Verification:**

- [ ] Focused Appointment and API tests pass.
- [ ] Recovery-map rows for this task name the passing tests.

**Dependencies:** Task 14.

**Likely files:** recovery map plus at most four Appointment/Auth/Intake test
files selected by the Task 14 audit.

**Scope:** Medium, five files maximum.

## Task 16: Close Communication and Clinical-Read Coverage

**Description:** Restore missing messaging, attachment, Feedback, prescription,
and canonical workflow-read assertions.

**Acceptance criteria:**

- [ ] Messaging, attachment storage/validation, staff delivery, and ownership
      are mapped to passing tests.
- [ ] Private Feedback validation and isolation are mapped to passing tests.
- [ ] Own Prescription, Quotation, Job Order, and Invoice reads have
      authentication and cross-patient negatives.

**Verification:**

- [ ] Focused communication, Feedback, and workflow-read tests pass.
- [ ] Recovery-map rows for this task name the passing tests.

**Dependencies:** Tasks 13–14.

**Likely files:** recovery map plus at most four communication/clinical API
test files selected by the Task 14 audit.

**Scope:** Medium, five files maximum.

## Task 17: Close Catalog, Inventory, Finance, and Print Coverage

**Description:** Restore retained operational behavior without restoring
patient ordering or legacy Billing.

**Acceptance criteria:**

- [ ] Frame/catalog taxonomy, inventory, stock target, and archival behavior
      are mapped to passing tests.
- [ ] Job Order, Invoice, append-only payment, summary, and report behavior are
      mapped to passing tests.
- [ ] Patient-owned health-record, Prescription, and Invoice print protection
      are mapped to passing tests.

**Verification:**

- [ ] Focused catalog, inventory, finance, report, and print tests pass.
- [ ] Recovery-map rows for this task name the passing tests.

**Dependencies:** Task 14.

**Likely files:** recovery map plus at most four operational test files selected
by the Task 14 audit.

**Scope:** Medium, five files maximum.

## Task 18: Close Staff Authorization, Notification, and Audit Coverage

**Description:** Restore retained Filament access boundaries and system-side
effects for the canonical workflow.

**Acceptance criteria:**

- [ ] Admin/staff/optometrist capability boundaries are mapped to passing
      positive and negative tests.
- [ ] Appointment, Conversation, Job Order, and Invoice notifications are
      mapped without a mobile notification inbox.
- [ ] Retained audit, global-search, bulk-action, and archive/restore behavior
      is mapped to passing tests.

**Verification:**

- [ ] Focused Filament, role, notification, and audit tests pass.
- [ ] Recovery-map rows for this task name the passing tests.

**Dependencies:** Task 14.

**Likely files:** recovery map plus at most four staff/system test files selected
by the Task 14 audit.

**Scope:** Medium, five files maximum.

## Task 19: Prove Canonical Seeded Clinic Journeys

**Description:** Validate realistic seed data and both scheduled and
account-less walk-in workflows.

**Acceptance criteria:**

- [ ] Seed data contains two optometrist-capable users, one receptionist, a
      linked Patient, and an account-less Patient.
- [ ] Seeded Appointments use Appointment Type and duration snapshots.
- [ ] Scheduled and walk-in journeys reach canonical dispensing without
      legacy Order or Billing models.

**Verification:**

- [ ] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] Seeder and EndToEnd suites pass.
- [ ] Capability and patient-isolation negatives pass.

**Dependencies:** Tasks 15–18.

**Likely files:** DatabaseSeeder, DemoUserSeeder, ClinicWorkflowSeeder,
CanonicalSeederTest, ClinicWorkflowTest.

**Scope:** Medium, five files.

## Checkpoint D

- [ ] Tasks 14–19 have passing focused tests and atomic commits.
- [ ] Every restore-required row names passing equal-or-stronger coverage.
- [ ] Both recovery-map technical gates are closed.
- [ ] Scheduled and walk-in seeded journeys pass without legacy concepts.

## Task 20: Run All Automated Release Gates

**Description:** Execute the full technical command set and record exact
results without changing behavior to fit the evidence.

**Acceptance criteria:**

- [ ] All focused suites and the full Pest suite pass.
- [ ] Pint, production asset build, and fresh migration/seed pass.
- [ ] Route, schema, foreign-key, index, nullability, and static scans match the
      approved specification.

**Verification:**

- [ ] Every automated command in the closure specification has a recorded
      command, date, exit result, and relevant count.
- [ ] No credential or patient data is recorded.

**Dependencies:** Task 19.

**Likely files:** closure task list and at most one release-evidence document
only if an existing approved document requires it.

**Scope:** Small code scope; large command execution.

## Task 21: Run Optometrist and Receptionist Browser Journeys

**Description:** Exercise actual seeded Filament pages for both clinic roles.

**Acceptance criteria:**

- [ ] An optometrist completes the approved appointment-to-dispensing path.
- [ ] A receptionist completes allowed front-desk operations.
- [ ] Receptionist clinical finalization is denied and no browser console or
      failed-network error blocks the journeys.

**Verification:**

- [ ] Browser evidence names role, route, action, result, and any console or
      network finding.
- [ ] No real patient data or credentials are captured.

**Dependencies:** Task 20.

**Likely files:** closure task list and `BACKEND_CONTEXT.md` only after the
journeys pass.

**Scope:** Small code scope; manual runtime verification.

## Task 22: Validate Restoration and Reconcile Final Context

**Description:** Prove non-sensitive recoverability, rewrite context from
observed state, and close only supported claims.

**Acceptance criteria:**

- [ ] The approved disposable database is dumped and restored into the
      confirmed disposable `eyecare_restore_check` database.
- [ ] Restored schema/table validation passes.
- [ ] `BACKEND_CONTEXT.md` matches observed schema, routes, roles, navigation,
      seed accounts, and verification results.
- [ ] Task 40 and release checkpoints close only with Tasks 20–22 evidence.
- [ ] Android integration and production privacy/governance remain open.

**Verification:**

- [ ] Every success criterion in the closure specification has a reproducible
      evidence reference.
- [ ] `git diff --check`
- [ ] Final full Pest and route equality tests still pass.

**Dependencies:** Tasks 20–21.

**Likely files:** `BACKEND_CONTEXT.md`, parent reconciliation tasks, recovery
map, closure task list.

**Scope:** Medium, four documentation files plus controlled database commands.

## Checkpoint E

- [ ] Automated, browser, schema, and restoration evidence all pass.
- [ ] Context documentation matches the observed application.
- [ ] No technical recovery or release checkbox remains unsupported.
- [ ] Backend is ready for clinic UAT, not automatically production-approved.

## Phase 3 Approval Gate

- [x] Tasks are ordered by dependency.
- [x] Every task has checkbox acceptance criteria and verification.
- [x] No task authorizes more than five primary files.
- [x] Checkpoints occur after each major dependency group.
- [x] Deletion and migration rewriting remain replacement-first.
- [x] Release evidence includes automated, browser, and restoration gates.
- [x] The project owner approved Tasks 1–22 on 2026-07-27.

Phase 4 is authorized for Tasks 1–5 only. Each task starts after its
dependencies pass and the previous increment is committed.
