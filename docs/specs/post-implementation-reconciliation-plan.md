# Plan: Post-Implementation Reconciliation

## Status

Approved by the project owner on 2026-07-26. This plan implements the approved
`post-implementation-reconciliation-spec.md`. Phase 3 may produce the bounded
task breakdown; application changes remain unauthorized until that task gate is
approved.

## Outcome

Repair the existing application in place so that:

- deleted Order/Billing structures have no remaining runtime consumers;
- Appointment Type is the only appointment classifier and duration source;
- the database contains only intentional canonical tables;
- the exact patient `/api/v1` contract matches the approved specification;
- retained behavior has meaningful regression coverage; and
- release claims are supported by fresh executable evidence.

The implementation is a reconciliation, not a feature expansion or rewrite.

## Guiding Strategy

Use a replacement-first cutover:

```text
restore retained-behavior tests
  -> expose current broken paths
  -> repair canonical consumers
  -> finish Appointment Type cutover
  -> remove orphan domains
  -> lock exact API
  -> consolidate migrations
  -> rebuild and verify release evidence
```

No missing test is repaired by restoring `customer_id`, Order, Billing, or
patient checkout behavior.

## Component Map

### 1. Verification and release truth

Responsibilities:

- reopen inaccurate completion claims;
- recover retained-behavior tests from Git history;
- classify each deleted test as obsolete or replacement-required;
- add a schema/consumer regression test for missing model and table references;
- retain the current 400-test run only as a baseline, not a release gate.

Primary areas:

- `docs/BACKEND_CONTEXT.md`
- both clinic workflow task documents
- `tests/Feature/Api/V1`
- retained appointment, Filament, notification, messaging, patient,
  prescription, privacy, and security suites

Dependency: none.

### 2. Canonical identity and relationship repair

Responsibilities:

- remove `User::orders()`;
- replace broken Patient prescription ownership with direct
  `Patient -> hasMany(Prescription)`;
- remove `Patient::orders()` and `Appointment::billings()`;
- remove `SmsNotification::order()` and deleted Order event assumptions;
- remove broken ServiceRecord relationships according to the approved Services
  removal;
- ensure every surviving relationship points to a real model/column;
- restore focused relationship and authorization coverage.

Dependency: verification coverage must exist first.

### 3. Canonical finance, printing, reporting, and demo data

Responsibilities:

- remove the old Billing controller/action/resource remnants;
- replace Billing PDF/thermal routes with the canonical Invoice print behavior
  where useful;
- make daily summary use Job Orders and posted Invoice Payments;
- rebuild dashboard demo data with Patients, Appointment Types, Job Orders,
  Invoices, and Invoice Payments;
- remove legacy Billing/Order labels from Feedback, Conversation, SMS, reports,
  and widgets;
- preserve Invoice Payment append-only correction and audit semantics.

Dependency: canonical identity repair.

### 4. Communication and feedback repair

Responsibilities:

- remove legacy Order context resolution;
- support patient-owned Appointment, Frame/Product, and Job Order contexts only
  where the approved resource contract needs them;
- ensure Conversation and Feedback ownership uses Patient;
- retain authenticated private attachment download;
- remove Order labels and counters from Filament presentation;
- restore cross-patient, attachment authorization, feedback privacy, and rating
  eligibility tests.

Dependency: canonical identity repair.

### 5. Appointment Type cutover

Responsibilities:

- make Appointment Type required for all appointments;
- copy the selected type's default duration into a required
  `appointments.duration_minutes` snapshot at booking;
- make Appointment Type duration drive availability, scheduling,
  rescheduling, conflict detection, and walk-ins;
- enforce Referral source requirements;
- migrate Filament forms and actions to Appointment Type;
- migrate factories and seed data;
- update Patient Intake's system-owned snapshot;
- remove VisitReason API, model, Filament resource, factories, seeders, tests,
  table, and foreign key.

Dependency: restored appointment test coverage.

### 6. Invoice Payment method normalization

Responsibilities:

- introduce one typed fixed payment-method value contract;
- validate Cash, GCash, Bank Transfer, Credit Card, and Check;
- use the same options in Invoice actions, forms, resources, factories, and
  tests;
- retain `posted`/`voided` Invoice Payment status semantics;
- remove orphan Payment, PaymentMethod, PaymentStatus, Billing payment action,
  factories, seeders, and lookup tables.

Dependency: canonical Invoice tests and print/report consumers.

### 7. Services removal

Responsibilities:

- remove the disconnected Services Filament resource and model;
- remove its factory, seeder, tests, and table;
- remove ServiceRecord references;
- retain snapshotted free-form descriptions on Quotation, Job Order, and
  Invoice Items.

Dependency: confirm no canonical seeder, form, or report uses Service.

### 8. Exact patient API

Responsibilities:

- expose only the approved method/URI allow-list;
- merge patient-safe account/profile representation into `/api/v1/me`;
- expose Appointment Types and Appointment Type-driven availability;
- use appointment-nested intake routes;
- expose frame-only catalog/reservations;
- preserve read-only prescription, quotation, Job Order, and Invoice access;
- normalize the single Conversation routes plus private attachment download;
- expose only private Feedback submission and verified Job Order Item rating;
- remove duplicate products, brands, categories, notification inbox,
  VisitReason, patient profile, old intake, feedback-list, rating, and generic
  staff-status routes;
- place clinic-only mutations in Filament or an explicitly separate staff
  contract, not the patient allow-list.

Dependency: appointment, communication, payment, and relationship repairs.

### 9. Canonical migration history and seed scenario

Responsibilities:

- replace the create-legacy-then-drop chain with coherent canonical migrations;
- ensure foreign keys, indexes, nullability, defaults, and soft deletes reflect
  actual models;
- remove all legacy `customer_id`, Order, Billing, VisitReason, Services, and
  orphan payment lookup migrations;
- preserve privacy, audit, inventory, clinical, and canonical finance schema;
- seed clinic hours every day, two optometrist-capable users, a receptionist,
  linked and account-less Patients, Appointment Types, and complete clinic
  journeys;
- ensure the demo scenario uses only canonical models.

Dependency: all application consumers and domain decisions must be settled.

### 10. Final documentation and release evidence

Responsibilities:

- run full suite, Pint, build, fresh seed, exact route and schema scans;
- execute private print checks;
- execute real browser journeys for optometrist and receptionist;
- execute patient isolation, privacy, MFA, and backup/restore gates;
- rewrite `BACKEND_CONTEXT.md` from observed implementation;
- close only the task/checkpoint claims proven by exact evidence.

Dependency: all implementation components.

## Implementation Order

### Milestone 1: Re-establish the verification firewall

1. Reopen the misleading release checkboxes and context banner.
2. Build a retained-test recovery inventory from `a0cf085` to the current
   branch.
3. Restore or replace tests for still-supported behavior.
4. Add failing tests for the known dangling relationships and routes.

Checkpoint:

- every deleted test has an explicit obsolete/replacement classification;
- restored tests fail only for known reconciliation gaps;
- no application behavior has been changed merely to reduce failures.

### Milestone 2: Remove dangling legacy consumers

1. Repair Patient/User/Appointment relationships.
2. repair Invoice printing and private routes;
3. repair daily summary and canonical demo data;
4. repair SMS, Feedback, Conversation, and related Filament presentation;
5. delete exclusive dead factories, seeders, actions, and models in bounded
   batches.

Checkpoint:

- loading any registered route/resource no longer resolves a missing class;
- application code has no `App\Models\Order`, `App\Models\Billing`,
  `App\Models\ServiceRecord`, or active `customer_id`;
- focused patient, print, notification, messaging, and Filament tests pass.

### Milestone 3: Finish the Appointment Type domain

1. Characterize current scheduling behavior with restored tests.
2. change application services from VisitReason to AppointmentType;
3. update API and Filament input contracts;
4. update factories and seeders;
5. make `appointment_type_id` and the booked duration snapshot required;
6. prove later Appointment Type duration edits do not alter existing bookings;
7. remove VisitReason and prove equivalent scheduling behavior.

Checkpoint:

- all four official types and Referral validation pass;
- new bookings snapshot Appointment Type duration;
- availability and overlap calculations use each Appointment's booked duration;
- later type edits affect future bookings only;
- VisitReason scans return no active results;
- appointment, intake, check-in, and browser component tests pass.

### Milestone 4: Normalize supporting catalogs

1. introduce the canonical Invoice Payment method enum;
2. migrate all Invoice Payment writers/readers/tests;
3. remove orphan payment models, actions, factories, seeders, and lookup
   schema;
4. remove the disconnected Services feature and tests;
5. verify Quotation/Job Order/Invoice descriptions remain complete.

Checkpoint:

- no orphan lookup model/table remains;
- only approved payment values can be written;
- payment correction remains append-only;
- no clinic workflow depends on Services.

### Milestone 5: Lock the patient API

1. Write the exact approved route equality test first.
2. adapt controllers, requests, resources, and route bindings;
3. move to Appointment Type and nested Intake contracts;
4. normalize singular Conversation and attachment access;
5. remove all extra routes and unused API classes;
6. run ownership, pagination, validation, privacy, and rate-limit tests.

Checkpoint:

- the method/URI set equals the approved list exactly;
- every resource is linked-patient scoped;
- no patient mutation creates Job Orders, Invoices, or payments;
- no staff-only action appears in the patient group.

### Milestone 6: Consolidate schema and seed data

1. Freeze the final canonical schema inventory.
2. prepare coherent domain migrations;
3. remove superseded development migrations in reviewed batches;
4. update canonical factories/seeders;
5. run an isolated fresh migration and seed;
6. inspect schema, foreign keys, and representative seeded journeys.

Checkpoint:

- fresh schema contains only canonical tables;
- no create-then-drop legacy migration chain remains;
- fresh seed produces the required clinic roles and journeys;
- migration and seed tests pass.

### Milestone 7: Reissue release evidence

1. run all focused suites;
2. run the full Pest suite;
3. run Pint and the production build;
4. inspect exact routes and schema;
5. run print/PDF tests;
6. run optometrist and receptionist browser journeys;
7. run privacy, security, and backup/restore gates;
8. update context and release checkpoints from outputs.

Checkpoint:

- every technical success criterion has reproducible evidence;
- Android integration and production/privacy governance remain open external
  gates;
- the backend is ready for clinic UAT, not automatically production-approved.

## Test Recovery Policy

The old 973-test count is not restored mechanically. Each deleted test is
handled according to behavior:

| Deleted behavior | Treatment |
|---|---|
| patient-created Order, legacy Billing, old Payment, old ServiceRecord | Remain deleted |
| appointment availability/booking/reschedule/cancel | Restore and migrate to Appointment Type |
| patient, prescription, notification, expiry, print | Restore and repair Patient ownership |
| conversations, attachments, private feedback | Restore and repair canonical contexts |
| frame catalog/reservation/inventory | Restore canonical assertions |
| Filament appointment/calendar/patient/prescription/conversation | Restore retained workflow coverage |
| privacy, MFA, rate limit, authorization | Restore unless equal or stronger canonical replacement already exists |
| duplicate tests superseded by stronger end-to-end coverage | May remain deleted only with documented assertion mapping |

The Phase 3 task manifest will name exact files and replacement tests before
any further test deletion.

## Migration Approach

The project is undeployed, so the plan uses migration consolidation rather than
shipping another sequence of compatibility drops.

The final history should be organized by dependency:

```text
identity and roles
  -> patients and privacy
  -> clinic hours and provider schedules
  -> appointment types and appointments
  -> intake, encounters, prescriptions, physical charts
  -> catalog and inventory
  -> reservations
  -> quotations and job orders
  -> invoices, invoice payments, dispensing
  -> conversations, feedback, ratings, complaints
  -> audit, notifications, retention, incidents
```

Migration consolidation occurs only after application code and tests no longer
need the legacy schema. The current development database is then rebuilt from
seed data. No production-data backfill is designed.

## API Cutover Approach

The API change is a clean pre-deployment contract correction:

- no `/api/v2`;
- no compatibility aliases;
- no temporary dual route families;
- no redirect from removed endpoints; and
- no patient Order/Billing adapter.

Controllers may be reused where their behavior is canonical, but route names,
request fields, ownership, and resources must match the approved contract.
Unused controllers and requests are deleted after exact replacement tests pass.

## Security and Privacy Controls

Every patient route must:

- require Sanctum authentication except register/login;
- resolve the linked Patient once;
- return a consistent hidden-resource response for cross-patient access;
- validate input with a Form Request;
- return a patient-safe API Resource;
- avoid internal notes, actor IDs, costs, stock counts, and capacity details;
- rate-limit mutations; and
- audit sensitive changes where the existing policy requires it.

Private files remain non-public. Attachment download checks both Conversation
ownership and Message/Attachment membership.

Patient Health Record intake:

- is prompted only after booking;
- is not required to reserve the slot;
- supports draft and resume;
- is patient-editable only before submission/verification;
- uses a system-owned Appointment Type/referral snapshot; and
- remains fillable by clinic users for walk-ins/account-less Patients.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Passing tests hide unexecuted missing classes | High | Restore retained tests first; add route/resource smoke coverage |
| More useful tests are deleted as “legacy” | High | Per-test assertion mapping and replacement-first gate |
| Appointment Type migration changes slot capacity | High | Restore concurrency/duration tests before changing scheduler |
| Schema consolidation breaks migration order | High | Freeze dependency graph; isolated `migrate:fresh --seed` after every batch |
| Invoice payment audit history weakens | High | Preserve append-only void/replacement tests |
| Cross-patient health data leaks | Critical | Negative ownership tests for every patient resource and private attachment |
| API implementation drifts from approved list again | High | Full equality contract test, not subset assertions |
| Service removal loses a needed clinic price list | Medium | Approved decision recorded; descriptions remain snapshotted; future catalog is separate |
| Fixed payment methods later need configuration | Low | Typed values provide a stable migration path to a lookup table if required |
| Browser UI remains broken despite component tests | High | Real optometrist/receptionist seeded browser journeys |
| Documentation becomes stale after late schema edit | Medium | Update context only from final command output |

## Sequential and Parallel Work

Sequential:

- test recovery before behavior repair;
- relationship repair before dependent reports/messages;
- Appointment Type code cutover before VisitReason schema removal;
- canonical consumer cleanup before migration consolidation;
- exact API after domain contracts stabilize;
- final release evidence after every implementation milestone.

Potentially parallel only if explicitly authorized later:

- Invoice print/report cleanup and communication cleanup after identity repair;
- Services removal and payment-method normalization after canonical finance
  tests are fixed;
- privacy/security test restoration and non-overlapping browser fixture work.

Shared routes, Appointment models/actions, migrations, factories, seeders, and
release documentation remain sequential to avoid conflicting edits.

## Verification Matrix

| Area | Focused verification |
|---|---|
| Identity/relationships | patient factory, model relationships, cross-patient policies |
| Appointment Types | model/seeder, availability, booking, reschedule, concurrency, Filament |
| Intake/Encounter | draft, submit, verify, check-in transaction, combined record |
| Communication | conversation ownership, attachment access, feedback privacy, rating eligibility |
| Inventory | reservation lifecycle, movement ledger, Job Order atomicity/idempotency |
| Finance | Invoice ledger, payment/correction, dispensing, print |
| API | exact route equality, auth/profile, pagination, validation, isolation, rate limits |
| Schema/seed | fresh migration, seed scenario, foreign-key inventory, legacy scans |
| Staff UI | optometrist and receptionist browser journeys |
| Governance | privacy, MFA, audit, backup/restore, production configuration |

## Phase 2 Approval Gate

- [x] Component boundaries and dependencies are approved.
- [x] Milestone order and checkpoints are approved.
- [x] Test recovery policy is approved.
- [x] Migration consolidation strategy is approved.
- [x] API clean-cutover strategy is approved.
- [x] Risks and mitigations are accepted.
- [x] The project owner authorizes Phase 3 task breakdown on 2026-07-26.

Phase 3 may now produce the bounded task breakdown.
