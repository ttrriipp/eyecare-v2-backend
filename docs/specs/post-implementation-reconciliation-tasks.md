# Tasks: Post-Implementation Reconciliation

## Status

Approved by the project owner on 2026-07-26 after revision with the
planning-and-task-breakdown process. Phase 4 is authorized to execute one task
at a time. Task 1 is complete; Task 2 awaits authorization.

## Execution Rules

Every implementation task:

1. searches the installed Laravel/Filament documentation before PHP edits;
2. restores or writes the focused Pest test first;
3. changes no more than five primary files;
4. runs the smallest affected suite through Sail;
5. runs `vendor/bin/sail bin pint --dirty --format agent` after PHP edits;
6. inspects the diff and preserves unrelated work; and
7. commits only when its focused verification passes.

No task may restore `customer_id`, direct patient ordering, legacy Billing, or
weaken a retained-behavior test merely to make it pass.

## Dependency Graph

```text
release/test truth
  -> Patient relationship repair
      -> print/report/communication repair
      -> Appointment Type vertical slices
      -> payment and Services cleanup
          -> exact patient API
              -> canonical migration history
                  -> canonical seed journeys
                      -> restored acceptance coverage
                          -> final release evidence
```

Migration consolidation is intentionally late. The application and its tests
must stop consuming a legacy structure before that structure is removed from
the undeployed migration history.

## Progress Tracker

A task is checked only after its acceptance criteria pass and its atomic commit
is recorded.

### Phase A: Truthful Coverage and Dangling Consumers

- [x] Task 1: Reopen release claims and freeze the test-recovery map
      (`c8521b8`)
- [x] Task 2: Repair core Patient relationships
      (`2c79ccf`)
- [x] Task 3: Remove legacy SMS relationships
      (`630309f`)
- [x] Task 4: Cut printing over to Invoice
      (`d7994bf`)
- [x] Task 5: Repair daily summary and dashboard demo data
      (`659f9b9`)
- [x] Task 6: Repair canonical conversation contexts
      (`d0581ca`)
- [x] Task 7: Repair Feedback presentation and ownership
      (`e276de1`, `3c10938`)

### Phase B: Appointment Type Completion

- [x] Task 8: Add the booked duration snapshot
      (`85ff848`)
- [x] Task 9: Move availability evaluation to Appointment Type
      (`46fc0e1`)
- [x] Task 10: Move appointment creation to Appointment Type
      (`5f44484`)
- [x] Task 11: Move rescheduling to the booked duration
      (completed as part of Task 10)
- [x] Task 12: Correct the appointment API contract
      (`9c3dbd6`)
- [x] Task 13: Correct Filament appointment forms
      (`9c3dbd6`)
- [x] Task 14: Complete appointment-linked Intake
      (`e270489`)
- [x] Task 15: Remove Visit Reason Filament UI
      (`44c5e06`)
- [x] Task 16: Remove Visit Reason presentation helpers
      (completed as part of Task 15)
- [x] Task 17: Remove Visit Reason wiring
      (`e270489`, `44c5e06`)

### Phase C: Supporting Catalog Cleanup

- [x] Task 18: Introduce the canonical payment-method value contract
      (`04ad9bc`)
- [x] Task 19: Remove legacy Payment models and factories
      (`2670548`)
- [x] Task 20: Remove legacy Payment actions and seeders
      (`ed9ad2d`)
- [x] Task 21: Remove Services Filament UI
      (`c3093eb`)
- [x] Task 22: Remove Services domain files
      (`97a1572`)

### Phase D: Exact Patient API

- [x] Task 23: Normalize authentication and `/me`
      (`bb98308`)
- [x] Task 24: Normalize Appointment and Intake routes
      (`03436a4`)
- [x] Task 25: Normalize Conversation and attachment routes
      (`03436a4`)
- [x] Task 26: Normalize Feedback and rating routes
      (`03436a4`)
- [x] Task 27: Remove extra catalog and notification routes
      (`03436a4`)
- [x] Task 28: Lock the full route equality contract
      (`ebb75ff`)

### Phase E: Canonical Migration History

- [x] Task 29: Retire legacy commerce migrations batch 1
      (`4147c27`)
- [x] Task 30-34: Retire remaining legacy commerce migrations
      (`b0c2d3a`)
- [x] Task 35: Consolidate Appointment Type and supporting schema
      (`b5280c5`)
- [x] Task 36: Consolidate Patient transition migrations
      (`b5280c5`)

### Phase F: Seed, Acceptance, and Release Evidence

- [ ] Task 37: Rebuild canonical seed data
- [ ] Task 38: Restore complete clinic acceptance journeys
- [ ] Task 39: Restore retained release-critical coverage
- [ ] Task 40: Execute final technical release evidence

## Task Index

The detailed section for each task supplies its acceptance criteria,
verification, and likely files. This index makes the outcome, dependency, and
size of every task explicit.

| Task | Outcome | Dependencies | Scope |
|---:|---|---|---|
| 1 | Reopen unsupported claims and classify deleted tests | None | M, 3–4 files |
| 2 | Make Patient relationships canonical | 1 | M, 4–5 files |
| 3 | Remove Order-only SMS behavior | 1, 2 | M, 4–5 files |
| 4 | Deliver canonical private Invoice printing | 1, 2 | M, 4–5 files |
| 5 | Produce summaries and demo data from canonical finance | 1, 2 | M, 4–5 files |
| 6 | Keep only owned canonical Conversation contexts | 1, 2 | M, 4–5 files |
| 7 | Separate private Feedback from verified frame ratings | 1, 2 | M, 4–5 files |
| 8 | Persist each appointment's booked duration | 1 | M, 4–5 files |
| 9 | Calculate availability from type defaults and booked snapshots | 8 | M, 4–5 files |
| 10 | Create scheduled and walk-in appointments from Appointment Type | 8, 9 | M, 4–5 files |
| 11 | Reschedule without rewriting booked duration | 8–10 | M, 4–5 files |
| 12 | Expose the Appointment Type booking contract | 8–11 | M, 4–5 files |
| 13 | Use Appointment Type in staff appointment screens | 8–12 | M, 4–5 files |
| 14 | Complete the appointment-linked Intake slice | 8–13 | M, 4–5 files |
| 15 | Remove the replaced Visit Reason staff UI | 13, 14 | M, 4–5 files |
| 16 | Remove Visit Reason domain and presentation helpers | 15 | M, 5 files |
| 17 | Remove remaining Visit Reason wiring | 15, 16 | M, 4–5 files |
| 18 | Use one typed Invoice Payment method contract | 2 | M, 4–5 files |
| 19 | Remove replaced Payment models and factories | 18 | M, 5 files |
| 20 | Remove replaced Payment actions and seeders | 18, 19 | M, 4–5 files |
| 21 | Remove the disconnected Services staff UI | 1 | M, 4–5 files |
| 22 | Remove the disconnected Services domain | 21 | M, 5 files |
| 23 | Make `/me` the single patient-safe identity endpoint | 2, 12 | M, 4–5 files |
| 24 | Lock Appointment and Intake route behavior | 14, 17, 23 | M, 4–5 files |
| 25 | Lock Conversation and attachment route behavior | 6, 23 | M, 4–5 files |
| 26 | Lock Feedback and verified rating route behavior | 7, 23 | M, 4–5 files |
| 27 | Remove extra catalog and notification API routes | 23 | M, 4–5 files |
| 28 | Prove equality with the complete patient route allow-list | 24–27 | M, 2–5 files |
| 29 | Retire legacy commerce migrations, batch 1 | 17, 20, 22, 28 | M, 5 files |
| 30 | Retire legacy commerce migrations, batch 2 | 29 | M, 5 files |
| 31 | Retire legacy commerce migrations, batch 3 | 30 | M, 5 files |
| 32 | Retire legacy commerce migrations, batch 4 | 31 | M, 5 files |
| 33 | Retire legacy commerce migrations, batch 5 | 32 | M, 5 files |
| 34 | Retire legacy commerce migrations, batch 6 | 33 | M, 5 files |
| 35 | Consolidate Appointment Type and supporting schema | 17, 20, 22, 34 | M, at most 5 files |
| 36 | Consolidate the Patient foreign-key transition | 2, 35 | M, at most 5 files |
| 37 | Seed only canonical clinic journeys | 36 | M, 4–5 files |
| 38 | Prove scheduled and walk-in clinic journeys | 37 | M, 4–5 files |
| 39 | Restore every retained release-critical assertion | 28, 38 | M, at most 5 files |
| 40 | Regenerate final technical release evidence | 37–39 | M, 3–5 files |

## Mandatory Review Stops

Implementation stops for review after Tasks **1–3**, **4–5**, **6–7**,
**8–10**, **11–12**, **13–14**, **15–17**, **18–20**, **21–22**, **23–25**,
**26–28**, **29–31**, **32–34**, **35–36**, **37–39**, and **40**.

At every stop:

- [ ] the focused tests for the completed tasks pass;
- [ ] Pint has formatted changed PHP files;
- [ ] each task stayed within five primary files;
- [ ] static scans show no newly introduced legacy vocabulary;
- [ ] the diff contains no unrelated work; and
- [ ] the system is left in a runnable state.

Tasks that discover more than five necessary primary files must stop. The
remaining work is added as another numbered task and approved before it is
executed; “per batch” never authorizes an unbounded task.

## Phase A: Truthful Coverage and Dangling Consumers

### Task 1: Reopen release claims and freeze the test-recovery map

**Acceptance:**

- inaccurate release/context claims are visibly reopened;
- every deleted test is classified as obsolete, replaced, or restore-required;
- the current 400-test result is recorded as baseline only.

**Verify:** `git diff --check` and compare the recovery map to
`git diff --numstat a0cf085..HEAD -- tests`.

**Primary files:** release task documents, `BACKEND_CONTEXT.md`, one recovery
manifest; maximum four.

### Task 2: Repair core Patient relationships

**Acceptance:**

- Patient prescriptions use `patient_id`;
- `User::orders()`, `Patient::orders()`, and `Appointment::billings()` are
  absent;
- relationship tests fail before and pass after the repair.

**Verify:** focused Patient/model relationship tests.

**Primary files:** `User.php`, `Patient.php`, `Appointment.php`, one restored
relationship test, one factory test.

### Task 3: Remove legacy SMS relationships

**Acceptance:**

- SmsNotification has no Order relationship;
- only approved Appointment/Job Order events remain;
- the SMS Filament UI contains no Order-only filters or labels.

**Verify:** SMS model, processing, notification-resource, and rendering tests.

**Primary files:** `SmsNotification.php`, its factory, SMS table, SMS schema,
one focused test.

### Task 4: Cut printing over to Invoice

**Acceptance:**

- Billing PDF/thermal routes are absent;
- canonical Invoice print/download behavior is reachable and authorized;
- prescription printing uses Patient and real creator relationships.

**Verify:** Invoice print, Prescription print, route, and authorization tests.

**Primary files:** `routes/web.php`, `PdfService.php`, Invoice print view or
page, Invoice print test, Prescription print test.

### Task 5: Repair daily summary and dashboard demo data

**Acceptance:**

- summary counts Job Orders and posted Invoice Payments;
- demo revenue creates canonical Invoices/Invoice Payments;
- no missing Order/Billing class is resolved.

**Verify:** daily-summary and demo-seeder tests.

**Primary files:** summary command, summary notification, dashboard demo
seeder, stats widget/report consumer, one focused test.

### Task 6: Repair canonical conversation contexts

**Acceptance:**

- Order context resolution is removed;
- owned Appointment, Frame/Product, and approved Job Order context behaves
  safely;
- cross-patient and private attachment access is denied.

**Verify:** versioned conversation, context, attachment, and Filament chat
tests.

**Primary files:** Conversation controller, resource, chat page, one API test,
one Filament test.

### Task 7: Repair Feedback presentation and ownership

**Acceptance:**

- private Feedback is Patient-owned and appointment-linked;
- Order labels/columns are absent;
- verified frame rating remains a separate dispensing-linked workflow.

**Verify:** Feedback API, Filament, audit, and rating tests.

**Primary files:** Feedback request, table, infolist, recent-feedback widget,
one focused test.

### Checkpoint A

- [x] No registered route/resource resolves a missing legacy class.
- [x] Core relationship, print, summary, SMS, conversation, and feedback tests
      pass.
- [x] Static scans find no active Order/Billing/customer consumer in repaired
      areas.

## Phase B: Appointment Type Completion

### Task 8: Add the booked duration snapshot

**Acceptance:**

- Appointment has required `duration_minutes`;
- booking copies the selected Appointment Type default;
- changing the type later does not alter existing appointments.

**Verify:** focused migration, model, factory, and snapshot tests.

**Primary files:** one migration, Appointment model, Appointment factory,
Appointment Type test, scheduling snapshot test.

### Task 9: Move availability evaluation to Appointment Type

**Acceptance:**

- candidate duration comes from Appointment Type;
- existing appointment end times use their booked snapshots;
- provider capacity, early closing, and overlap behavior remain correct.

**Verify:** restored availability, provider schedule, and concurrency tests.

**Primary files:** availability evaluator, slot-list action, scheduling action,
Appointment conflict query, one focused test.

### Task 10: Move appointment creation to Appointment Type

**Acceptance:**

- scheduled and walk-in creation require Appointment Type;
- both persist the type and duration snapshot;
- Referral requires a source.

**Verify:** creation, walk-in, referral, and transaction tests.

**Primary files:** scheduled-creation action, walk-in action, schedule action,
one creation test, one walk-in test.

### Task 11: Move rescheduling to the booked duration

**Acceptance:**

- rescheduling preserves type and booked duration;
- rescheduling still requires a patient-readable reason where applicable;
- old appointments are not recalculated from current type defaults.

**Verify:** restored API/Filament reschedule and concurrency tests.

**Primary files:** reschedule action, availability request, reschedule request,
one API test, one Filament/calendar test.

### Task 12: Correct the appointment API contract

**Acceptance:**

- booking input uses `appointment_type_id` and conditional referral source;
- availability uses Appointment Type;
- patient response exposes type, scheduled duration, and no capacity details.

**Verify:** V1 Appointment Contract and ownership tests.

**Primary files:** appointment controller, availability controller, store
request, appointment resource, V1 appointment test.

### Task 13: Correct Filament appointment forms

**Acceptance:**

- Create, edit, and walk-in/check-in UI use Appointment Type;
- Referral conditionally requires source;
- duration is displayed but not patient-controlled.

**Verify:** restored Appointment Resource, Check In, and calendar component
tests.

**Primary files:** Appointment form, Create page, List page, Edit page, one
Filament test.

### Task 14: Complete appointment-linked Intake

**Acceptance:**

- type/referral are system-owned from Appointment;
- patient can draft, resume, and submit after booking;
- patient cannot verify or edit verified intake.

**Verify:** V1 Intake, Intake verification, and combined Health Record tests.

**Primary files:** intake controller, request, resource, Patient Intake model,
one focused test.

### Task 15: Remove Visit Reason Filament UI

**Acceptance:** Visit Reason navigation, pages, and resource are absent after
Appointment Type UI replacement passes.

**Verify:** Filament discovery/navigation and Appointment Type resource tests.

**Primary files:** VisitReason resource, its three pages, obsolete resource
test.

### Task 16: Remove Visit Reason presentation helpers

**Acceptance:** Visit Reason schema, table, relation manager, model, and factory
are absent.

**Verify:** class/file scan plus focused Appointment Type/Filament tests.

**Primary files:** VisitReason schema, table, relation manager, model, factory.

### Task 17: Remove Visit Reason wiring

**Acceptance:** seeding, API routes, and old model tests contain no VisitReason;
DatabaseSeeder uses Appointment Types only.

**Verify:** Appointment Type seeder, route contract, static scans.

**Primary files:** VisitReason seeder, DatabaseSeeder, `routes/api.php`, old
Appointment model test, old VisitReason resource test.

### Checkpoint B

- [x] Appointment Type is the only classifier and duration-default source.
- [x] Every Appointment has a booked duration snapshot.
- [x] Referral and Intake snapshot rules pass.
- [x] No active VisitReason route, UI, or resource remains. VisitReason
      model/factory/seeder retained for existing DB data until Phase E
      migration consolidation.

## Phase C: Supporting Catalog Cleanup

### Task 18: Introduce the canonical payment-method value contract

**Acceptance:**

- one typed value contract defines Cash, GCash, Bank Transfer, Credit Card, and
  Check;
- all Invoice Payment writes validate it;
- API/UI formatting uses the same contract.

**Verify:** Invoice Payment creation, invalid method, correction, and Filament
tests.

**Primary files:** PaymentMethod enum, record-payment action, Invoice edit page,
InvoicePayment factory, payment lifecycle test.

### Task 19: Remove legacy Payment models and factories

**Acceptance:** legacy Payment, PaymentMethod model, PaymentStatus model, and
their factories are absent; canonical InvoicePayment remains.

**Verify:** class scan and canonical Invoice Payment suite.

**Primary files:** Payment model, PaymentMethod model, PaymentStatus model,
Payment factory, PaymentStatus factory.

### Task 20: Remove legacy Payment actions and seeders

**Acceptance:** Billing RecordPayment and lookup seeders are absent;
DatabaseSeeder no longer seeds orphan payment catalogs.

**Verify:** seeder test, Invoice Payment suite, static scans.

**Primary files:** legacy RecordPayment action, PaymentMethod seeder,
PaymentStatus seeder, DatabaseSeeder, one seeder test.

### Task 21: Remove Services Filament UI

**Acceptance:** the disconnected Services navigation/resource/pages are absent.

**Verify:** Filament navigation and absence test.

**Primary files:** Service resource, three pages, Service resource test.

### Task 22: Remove Services domain files

**Acceptance:** Service model/factory/seeder and remaining UI helpers are
absent; line-item snapshots still accept service descriptions.

**Verify:** Quotation, Job Order, Invoice item and Filament discovery tests.

**Primary files:** Service model, factory, seeder, form schema, table.

### Checkpoint C

- [x] Invoice Payment accepts only approved values and remains append-only.
- [x] Legacy payment lookup application files are absent.
- [x] Services has no application/UI consumer.

## Phase D: Exact Patient API

### Task 23: Normalize authentication and `/me`

**Acceptance:**

- `/me` is the single patient-safe account/linked-profile representation;
- duplicate `/patient/profile` routes/classes are removed;
- missing Patient linkage has a stable error.

**Verify:** V1 auth/profile and cross-account tests.

**Primary files:** Auth controller, patient profile controller, auth/profile
resource or request, V1 auth test, `routes/api.php`.

### Task 24: Normalize Appointment and Intake routes

**Acceptance:**

- Appointment Type, availability, appointment, and appointment-nested Intake
  routes match the approved methods/URIs;
- patient intake verification and contact-note extras are absent.

**Verify:** V1 Appointment, Intake, and route-contract tests.

**Primary files:** `routes/api.php`, appointment controller, intake controller,
one Appointment test, one Intake test.

### Task 25: Normalize Conversation and attachment routes

**Acceptance:** singular Conversation routes and the private attachment route
match the approved contract; mark-read extras are absent.

**Verify:** exact route, ownership, message, and attachment tests.

**Primary files:** `routes/api.php`, Conversation controller, Conversation
resource, messaging test, route-contract test.

### Task 26: Normalize Feedback and rating routes

**Acceptance:** only private Feedback submission and Job Order Item rating
remain; Feedback list/show and generic ratings routes are absent.

**Verify:** exact route, private Feedback, rating eligibility, and isolation
tests.

**Primary files:** `routes/api.php`, Feedback controller, FrameRating
controller, messaging/feedback/rating test, route-contract test.

### Task 27: Remove extra catalog and notification routes

**Acceptance:**

- `/products`, brands, categories, Visit Reasons, and notification inbox routes
  are absent;
- frame-only catalog and approved delivery mechanisms remain.

**Verify:** exact route, frame catalog, notification delivery, and privacy
tests.

**Primary files:** `routes/api.php`, Product controller, Notification
controller, catalog test, notification test.

### Task 28: Lock the full route equality contract

**Acceptance:** the complete method/URI set equals the approved allow-list and
explicitly denies patient commerce/staff mutations.

**Verify:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php
vendor/bin/sail artisan route:list --except-vendor --path=api
```

**Primary files:** route-contract test, `routes/api.php`, at most three
route-only obsolete classes discovered by the final scan.

### Checkpoint D

- [x] The patient route set is exact (35 routes match approved contract).
- [x] Cross-patient isolation passes for every resource.
- [x] No patient Order, checkout, Job Order/Invoice creation, or payment
      mutation exists.

## Phase E: Canonical Migration History

Migration retirement tasks are mechanical and must not alter application
behavior. Each batch deletes or rewrites no more than five migration files
after canonical replacement coverage passes.

### Task 29: Retire legacy commerce migrations batch 1

**Files:** order-status, billing-status, payment-status, orders-create, and
billings-create migrations.

**Acceptance:** their still-needed canonical concepts already exist elsewhere;
fresh migration reaches the next dependency boundary.

**Verify:** isolated `migrate:fresh --seed` and schema test.

### Task 30: Retire legacy commerce migrations batch 2

**Files:** payments-create, order-notes, mixed business-soft-delete,
billing-number, and billing-due-date migrations.

**Acceptance:** canonical soft deletes/indexes are preserved in their owning
migrations.

**Verify:** isolated fresh rebuild and soft-delete schema assertions.

### Task 31: Retire legacy commerce migrations batch 3

**Files:** payment-method FK, order-discount, order-item lens-price,
order-item lens-variant, and nullable order-item lens migrations.

**Acceptance:** canonical lens/category and Invoice Payment schema remains.

**Verify:** fresh rebuild plus catalog and Invoice suites.

### Task 32: Retire legacy commerce migrations batch 4

**Files:** billing note/due-date removal, Order status rename, ServiceRecord
create, polymorphic Billing, and Billing Items migrations.

**Acceptance:** no canonical domain depends on these migrations.

**Verify:** fresh rebuild and legacy-table absence test.

### Task 33: Retire legacy commerce migrations batch 5

**Files:** Billing encounter refactor, ServiceRecord simplify, Billing
appointment link, mixed performance indexes, and OR-number migration.

**Acceptance:** canonical indexes and Invoice official-number behavior remain.

**Verify:** fresh rebuild, index inspection, Invoice print tests.

### Task 34: Retire legacy commerce migrations batch 6

**Files:** lens-type rename, Order discount removal, Order Billing link, Billing
notes migration, and final legacy-drop migration.

**Acceptance:** clean history no longer creates then drops commerce tables.

**Verify:** fresh rebuild and migration/schema scans.

### Task 35: Consolidate Appointment Type and supporting schema

**Acceptance:**

- canonical appointment migration creates type/referral/duration snapshot
  directly;
- VisitReason, Services, and orphan payment lookup migrations are absent;
- migration order has no missing-table dependency.

**Verify:** fresh rebuild, Appointment Type schema tests, static scans.

**Primary files:** canonical Appointment migration plus at most four obsolete
VisitReason/Service/payment migration files. If more remain, add another
numbered task before touching them.

### Task 36: Consolidate Patient transition migrations

**Acceptance:** Patients, Appointments, Prescriptions, Conversations, and
Feedback are created directly with canonical Patient foreign keys; no
`customer_id` transition is required.

**Verify:** fresh rebuild, foreign-key inventory, patient isolation tests.

**Primary files:** canonical identity/clinical migration plus at most four
transition migrations. If more remain, add another numbered task before
touching them.

### Checkpoint E

- [x] Fresh migration creates only canonical schema.
- [x] No migration creates a legacy structure merely to remove it later.
- [x] Foreign keys, indexes, defaults, and nullability match application code.

## Phase F: Seed, Acceptance, and Release Evidence

### Task 37: Rebuild canonical seed data

**Acceptance:**

- two optometrist-capable users and one receptionist exist;
- linked and account-less Patients exist;
- seeded appointments use Appointment Type and duration snapshots;
- complete canonical clinic journeys exist without legacy models.

**Verify:** `migrate:fresh --seed` and focused seeder tests.

**Primary files:** DatabaseSeeder, AppointmentTypeSeeder,
ClinicWorkflowSeeder, DemoUserSeeder, one seeder test.

### Task 38: Restore complete clinic acceptance journeys

**Acceptance:** scheduled patient and account-less walk-in journeys cover
booking/intake through dispensing, including capability and isolation
negatives.

**Verify:** EndToEnd, privacy, and capability suites.

**Primary files:** at most two EndToEnd tests, two authorization/privacy tests,
one supporting factory.

### Task 39: Restore retained release-critical coverage

**Acceptance:** all recovery-map entries for retained behavior point to a
passing replacement test; obsolete entries remain deleted.

**Verify:** coverage-map audit and all focused suites.

**Primary files:** recovery manifest plus at most four restored/replacement
tests. If the recovery map contains more retained gaps, add another numbered
task before touching them.

### Task 40: Execute final technical release evidence

**Acceptance:**

- full Pest, Pint, production build, fresh seed, route/schema scans, print,
  browser, privacy/security, and backup/restore gates pass;
- context and checkpoints reflect exact outputs;
- Android integration and production governance remain separate.

**Verify:** every command named in the approved specification.

**Primary files:** `BACKEND_CONTEXT.md`, release task documents, recovery
evidence test/procedure, at most two final evidence files.

### Checkpoint F

- [ ] Technical success criteria have reproducible evidence.
- [ ] No release claim relies only on checked boxes or a reduced test count.
- [ ] Backend is ready for clinic UAT, not automatically production-approved.

## Phase 3 Approval Gate

- [x] Tasks are ordered by dependency.
- [x] Each task has explicit acceptance and verification.
- [x] Each task has an explicit outcome, dependency, and estimated scope.
- [x] No task changes more than five primary files.
- [x] Review stops occur after every two or three tasks.
- [x] Legacy/migration deletion remains replacement-first and batch-limited.
- [x] The project owner approves Tasks 1–40 on 2026-07-26.
- [x] The project owner authorizes Phase 4 implementation beginning with
      Task 1 only.

Task 2 remains unauthorized until Task 1 is completed, verified, committed,
and reviewed.
