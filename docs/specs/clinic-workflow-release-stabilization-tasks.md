# Tasks: Clinic Workflow Release Stabilization

## Status

Approved by the project owner on 2026-07-26. Phase 4 implementation is
authorized incrementally, beginning with Task 1. Later tasks remain subject to
their dependency and checkpoint gates.

The list uses 26 small tasks instead of another broad rewrite. Mechanical
legacy deletion uses repeatable batches of no more than five files. Every task
must leave its focused replacement tests green before the next dependent task
starts.

## Shared Execution Rules

For every PHP task:

1. Search the installed Laravel/Filament documentation before editing.
2. Write or correct the focused Pest test first.
3. Run all PHP, Artisan, Composer, and Node commands through Sail.
4. Run `vendor/bin/sail bin pint --dirty --format agent`.
5. Inspect the exact diff and preserve unrelated user changes.

No task may:

- restore `customer_id`, patient checkout, legacy Order, or legacy Billing
  behavior;
- add preferred-optometrist selection;
- expose internal notes or optometrist-only data to patients or receptionists;
- delete the modified `GetOrCreateBillingTest.php` before Task 15 passes;
- claim a release gate passed without executing its real command.

## Phase A: Truthful Baseline

### Task 1: Freeze the repair and removal manifest

**Description:** Reopen the misleading release claims, capture the current
suite/route/schema evidence, and map every obsolete consumer to replacement
coverage or an approved removal batch.

**Acceptance criteria:**

- [x] Every Order, Billing, `customer_id`, old patient route, and failing test
      is classified.
- [x] Inventory consumers and exact deletion batches of at most five files are
      listed.
- [x] The authorized future retirement of `GetOrCreateBillingTest.php` is
      recorded without changing that file.

**Verification:** Run the full compact suite, route list, schema inventory, and
legacy vocabulary scans; record their exact outputs.

**Dependencies:** None.

**Files likely touched:** Existing redesign tasks, stabilization task manifest,
release evidence notes.

**Estimated scope:** Medium.

**Status:** Completed on 2026-07-26 as a documentation-only baseline. Evidence
and batches are recorded in
`clinic-workflow-release-stabilization-manifest.md`. No application behavior,
schema, route, or legacy file was changed.

### Checkpoint A

- [ ] No implementation or deletion occurred before the manifest was reviewed.
- [ ] Every planned removal has a replacement or an explicitly removed rule.

## Phase B: Canonical Appointment and Clinical Workflow

### Task 2: Establish canonical appointment types

**Description:** Replace the visit-reason classification with the four clinic
appointment types, configurable duration/active state, referral requirements,
and booking-time duration snapshots.

**Acceptance criteria:**

- [x] Seeded types are New Patient, Follow-up, Routine Check-up, and Referral.
- [x] Referral requires a referring person/source.
- [ ] Intake receives a system-owned type snapshot rather than editable free
      text.

**Verification:** Run focused appointment-type model, validation, migration,
factory, and seeder tests.

**Dependencies:** Task 1.

**Files likely touched:** Appointment-type migration/model/seeder/factory and
one focused test file.

**Estimated scope:** Medium.

### Task 3: Make scheduling respect real provider availability

**Description:** Calculate clinic slots from clinic hours, early closures,
provider hours, and provider absences while keeping provider assignment
clinic-controlled.

**Acceptance criteria:**

- [x] A slot exists only when at least one optometrist covers its full duration.
- [x] Absence or shortened availability removes only the affected capacity.
- [x] Patient requests and responses contain no preferred-provider selection.

**Verification:** Run clinic-hours, provider-availability, appointment
availability, and concurrency tests.

**Dependencies:** Task 2.

**Files likely touched:** Availability action, clinic schedule resolver,
provider schedule action, request/resource contract, focused test.

**Estimated scope:** Medium.

### Task 4: Enforce transactional check-in and walk-in invariants

**Description:** Make scheduled patients and walk-ins use one row-locked
check-in action that attaches the exact verified intake and creates exactly one
waiting Encounter.

**Acceptance criteria:**

- [x] Status-only arrival cannot bypass Encounter creation.
- [x] Cross-patient or appointment-less intake records cannot be selected.
- [x] Repeated/concurrent check-in creates one Encounter and one audit event.

**Verification:** Run appointment check-in, walk-in, intake-isolation, rollback,
and concurrency tests.

**Dependencies:** Tasks 2–3.

**Files likely touched:** Check-in action, walk-in action, Appointment
relationships, one migration if required, focused test.

**Estimated scope:** Medium.

### Task 5: Add the real Check In UI and capability boundary

**Description:** Replace `Mark Arrived` with Check In in Today’s Queue and the
Appointment header, and enforce the approved receptionist/optometrist
capability hierarchy.

**Acceptance criteria:**

- [x] The modal shows patient, type, intake readiness, health-record link, and
      clinic-controlled optometrist assignment.
- [x] Optometrists can perform receptionist operations.
- [x] Receptionists cannot start/complete Encounters or author prescriptions.

**Verification:** Run Filament appointment/encounter action and policy tests.

**Dependencies:** Task 4.

**Files likely touched:** Appointment table/page, Encounter resource/page,
policy, focused Filament test.

**Estimated scope:** Medium.

### Task 6: Deliver the combined Patient Health Record workspace

**Description:** Present appointment type/referral, demographic snapshot,
complaints, and medical history in one Filament workspace without merging the
underlying records.

**Acceptance criteria:**

- [x] Optometrists see the complete Patient Health Record and related clinical
      navigation.
- [x] Receptionist access is limited to collection/verification needs.
- [x] Clinical findings and prescriptions remain separately capability-gated.

**Verification:** Run optometrist and receptionist Livewire tests for every
displayed/hidden section.

**Dependencies:** Tasks 2, 4, and 5.

**Files likely touched:** Health-record page/schema, Appointment or Intake
resource, policy, focused Filament test.

**Estimated scope:** Medium.

### Task 7: Repair retained patient and appointment support behavior

**Description:** Reuse one Patient identity per linked account, deliver
notifications through that account, and make reschedule reasons consistent and
audited.

**Acceptance criteria:**

- [x] Patient factories never create duplicate clinical identities.
- [x] Appointment notifications reach the linked account when present.
- [x] Staff rescheduling requires and preserves a patient-readable reason.

**Verification:** Run patient factory, appointment notification, and
rescheduling tests.

**Dependencies:** Task 4.

**Files likely touched:** Patient factory, appointment action, notification,
request/resource, focused test.

**Estimated scope:** Medium.

### Task 8: Repair prescription ownership and `CX` printing

**Description:** Complete Patient/Encounter ownership for prescriptions and
bind printed `CX` to cylinder while retaining axis separately.

**Acceptance criteria:**

- [x] No retained prescription query uses `customer_id`.
- [x] Only optometrist-capable users finalize or amend prescriptions.
- [x] Print tests prove OD/OS `CX` uses cylinder, not axis.

**Verification:** Run prescription lifecycle, authorization, and PDF/print
tests.

**Dependencies:** Tasks 4–6.

**Files likely touched:** Prescription model/action, policy, print view,
resource, focused test.

**Estimated scope:** Medium.

### Task 9: Repair feedback and conversation patient boundaries

**Description:** Retain private feedback and messaging while scoping ownership
through Patient and delivering through the optional linked account.

**Acceptance criteria:**

- [x] Cross-patient identifiers reveal no feedback, conversation, or attachment.
- [x] Internal moderation data and clinic-only notes are never patient-visible.
- [x] No retained query uses the obsolete customer relationship.

**Verification:** Run feedback, conversation, attachment, and negative
ownership tests.

**Dependencies:** Task 7.

**Files likely touched:** Feedback/conversation models or policies, controller,
resource, focused test.

**Estimated scope:** Medium.

### Checkpoint B

- [x] Appointment type, availability, intake, check-in, and Encounter handoff
      pass together.
- [x] The combined Patient Health Record passes both role journeys.
- [x] The retained patient/clinical focused suites contain no legacy customer
      dependency.

## Phase C: Canonical Catalog and Inventory

### Task 10: Normalize catalog taxonomy and frame-only mobile scope

**Description:** Keep fixed product behavior types, one optional category,
optional brand, physical variants, and separate non-stock lens/service
configuration.

**Acceptance criteria:**

- [x] Mobile catalog scopes return active frames only.
- [x] Accessories, lenses, supplier costs, and stock counts are absent.
- [x] Direct-order constants, tracking flags, and category pivots are absent.

**Verification:** Run product taxonomy, frame API, variant, and authorization
tests.

**Dependencies:** Task 1.

**Files likely touched:** Product/variant models, frame query/controller,
resource, factory/seeder, focused test.

**Estimated scope:** Medium.

### Task 11: Establish allocation ownership and canonical inventory ledger

**Description:** Add database-backed reservation allocations/job-order
commitments and replace legacy Order movement attribution.

**Acceptance criteria:**

- [x] Movement source, actor, quantity, and before/after availability are
      attributable.
- [x] Allocation/commitment ownership determines whether reversal is legal.
- [x] Legacy `inventory_movements.order_id` has no canonical consumer.

**Verification:** Run migration, relationship, movement-integrity, and archived
variant history tests.

**Dependencies:** Tasks 1 and 10.

**Files likely touched:** Allocation/movement migration, models, recording
action, factory, focused test.

**Estimated scope:** Medium.

### Task 12: Complete reservation preparation, release, and expiry

**Description:** Make requested reservations non-holding and prepared
reservations allocate/release stock exactly once.

**Acceptance criteria:**

- [x] Preparation locks records and creates one allocation per selected frame.
- [x] Cancellation, expiry, appointment cancellation, and no-show release once.
- [x] The scheduled expiry command is idempotent and respects clinic closing.

**Verification:** Run reservation lifecycle, scheduler, rollback, and concurrent
request tests.

**Dependencies:** Task 11.

**Files likely touched:** Reservation actions, expiry command/schedule,
appointment cancellation integration, policy, focused test.

**Estimated scope:** Medium.

### Task 13: Make Job Order inventory atomic and idempotent

**Description:** Transfer prepared allocations or commit unreserved stock in
the same transaction that creates the clinic Job Order.

**Acceptance criteria:**

- [x] Reservation conversion never deducts the frame twice.
- [x] Any unavailable item rolls back the whole Job Order and movements.
- [x] Cancellation reverses only recorded, unreversed commitments once.

**Verification:** Run conversion, multi-item rollback, cancellation, and
concurrency tests.

**Dependencies:** Tasks 11–12.

**Files likely touched:** Job Order creation/cancellation actions, conversion
action, commitment model, focused test.

**Estimated scope:** Medium.

### Task 14: Repoint inventory UI and reporting

**Description:** Preserve useful adjustment, movement history, low-stock, and
replenishment UI while using reservation/Job Order terminology and sources.

**Acceptance criteria:**

- [x] Movement history links only canonical sources.
- [x] Available/allocated/physical quantities are labelled unambiguously.
- [x] Adjustment and low-stock behavior continues to pass.

**Verification:** Run Filament inventory, adjustment, movement-history, and
low-stock tests.

**Dependencies:** Tasks 11–13.

**Files likely touched:** Inventory resource/table, movement relation/view,
report/widget, resource test.

**Estimated scope:** Medium.

### Checkpoint C

- [x] Requested, prepared, released, converted, committed, reversed, and
      dispensed paths match the approved stock behavior.
- [x] Repeated and concurrent transitions change stock once.
- [x] Catalog/reservation/inventory/Job Order focused suites pass.

## Phase D: Clean Legacy Cutover

### Task 15: Prove canonical Job Order and Invoice replacement coverage

**Description:** Close replacement gaps before removing Order/Billing and only
then retire the authorized dirty legacy Billing test.

**Acceptance criteria:**

- [x] Job Order, Invoice, append-only payment/correction, and dispensing tests
      cover every retained rule.
- [x] The modified `GetOrCreateBillingTest.php` is deleted with its obsolete
      test only after replacement coverage passes.
- [x] No user change outside that authorized file is removed.

**Verification:** Run Job Order, Invoice, payment, dispensing, and inventory
integration suites before and after the exact deletion.

**Dependencies:** Tasks 13–14.

**Files likely touched:** Canonical replacement tests plus the authorized
legacy test; maximum five files.

**Estimated scope:** Medium.

### Task 16: Remove the direct Order API surface in bounded batches

**Description:** Remove patient Order creation/cancellation, staff Order status,
and their exclusive controllers/requests/tests using the Task 1 batch manifest.

**Acceptance criteria:**

- [x] No Order, checkout, or purchase route remains.
- [x] Each deletion batch contains at most five exact files.
- [x] Route and consumer scans are clean after every batch.

**Verification:** Run the exact route contract and affected API suites after
each batch.

**Dependencies:** Task 15.

**Files likely touched:** Exact Task 1 API batch, maximum five per commit.

**Estimated scope:** Medium per batch.

### Task 17: Remove legacy Order/Billing application files in bounded batches

**Description:** Remove obsolete panel resources, actions, notifications,
reports, models, factories, and tests without removing canonical consumers.

**Acceptance criteria:**

- [x] Each manifest batch deletes at most five files.
- [x] Replacement-focused tests pass before and after every batch.
- [x] No compatibility alias or dead navigation remains.

**Verification:** Run consumer scans plus the relevant Filament, notification,
report, factory, and canonical domain suites for each batch.

**Dependencies:** Tasks 15–16.

**Files likely touched:** Exact Task 1 application batch, maximum five per
commit.

**Estimated scope:** Medium per batch.

### Task 18: Cut over schema and canonical seed data

**Description:** Remove obsolete Order/Billing tables and status catalogs,
consolidate the undeployed schema, and replace demo data with the approved
clinic scenario.

**Acceptance criteria:**

- [x] Fresh schema contains Job Orders, Invoices, and Invoice Payments but no
      legacy Order/Billing tables.
- [x] No active schema or application contract contains `customer_id`.
- [x] `migrate:fresh --seed` succeeds with coherent optometrist, receptionist,
      linked-patient, account-less-patient, and workflow data.

**Verification:** Run the real fresh migration/seed command and focused model
factory suites.

**Dependencies:** Task 17.

**Files likely touched:** Canonical migration set, database seeder, affected
factories, schema test.

**Estimated scope:** Medium.

### Checkpoint D

- [x] Order/Billing routes, navigation, classes, tests, and tables are absent.
- [x] Canonical replacement and fresh-seed suites pass.
- [x] No legacy file was removed outside a reviewed batch.

## Phase E: Complete the Patient `/api/v1` Contract

### Task 19: Version authentication and patient profile

**Description:** Move registration, login, logout, and linked-patient profile
operations under the sole versioned patient contract.

**Acceptance criteria:**

- [x] `/api/v1` auth/profile responses are stable and patient-safe.
- [x] Missing patient linkage has a consistent machine-readable error.
- [x] Replaced unversioned auth/profile routes are absent.

**Verification:** Run versioned auth/profile contract and negative ownership
tests.

**Dependencies:** Task 18.

**Files likely touched:** API routes, auth/profile controllers, request/resource,
focused contract test.

**Estimated scope:** Medium.

### Task 20: Version appointment types, availability, and appointments

**Description:** Expose clinic-slot booking, viewing, rescheduling, and
cancellation without preferred-provider or internal-note leakage.

**Acceptance criteria:**

- [x] Lists are paginated and every appointment is linked-patient scoped.
- [x] Patient resources exclude internal notes, actor IDs, and provider
      capacity details.
- [x] Cross-patient substitution returns the consistent hidden-resource error.

**Verification:** Run versioned appointment contract, availability, lifecycle,
privacy, and pagination tests.

**Dependencies:** Tasks 3–7 and 19.

**Files likely touched:** API routes, appointment controller/request/resource,
focused V1 test.

**Estimated scope:** Medium.

### Task 21: Version appointment-linked intake

**Description:** Provide one draft/update/submit flow scoped to the patient's
appointment and prevent editing verified snapshots.

**Acceptance criteria:**

- [x] Each appointment exposes only its own Patient Health Record draft.
- [x] Appointment type/referral comes from Appointment rather than free text.
- [x] Submitted/verified records obey ownership and immutability rules.

**Verification:** Run versioned intake contract, ownership, validation, and
immutability tests.

**Dependencies:** Tasks 6 and 20.

**Files likely touched:** API routes, intake controller/request/resource,
focused V1 test.

**Estimated scope:** Medium.

### Task 22: Complete versioned frame and workflow reads

**Description:** Lock frame browsing/reservations and prescription, quotation,
Job Order, and Invoice reads to the approved patient-safe contract.

**Acceptance criteria:**

- [x] Frame reservations require an eligible owned appointment.
- [x] Commercial/clinical resources are read-only and linked-patient scoped.
- [x] Lists are paginated and expose no costs, stock counts, or clinic-only
      mutations.

**Verification:** Run V1 frame, reservation, prescription, quotation, Job Order,
Invoice, and cross-patient tests.

**Dependencies:** Tasks 8, 10–13, 18, and 20.

**Files likely touched:** API routes, affected controller/resource pair, focused
V1 contract tests; maximum five primary files per slice.

**Estimated scope:** Medium per slice.

### Task 23: Complete versioned messaging, feedback, and ratings

**Description:** Expose the single conversation, private feedback, and
verified-purchase frame rating flows without inappropriate public content.

**Acceptance criteria:**

- [x] Conversation and attachment access is patient-scoped.
- [x] Feedback remains private and ratings require a dispensed eligible frame.
- [x] Moderation prevents inappropriate comments from public display.

**Verification:** Run V1 conversation, feedback, rating eligibility,
moderation, and isolation tests.

**Dependencies:** Tasks 9, 18, and 19.

**Files likely touched:** API routes, controller/resource or policy pairs,
focused V1 tests; maximum five primary files per slice.

**Estimated scope:** Medium per slice.

### Task 24: Lock the exact route contract

**Description:** Replace partial prefix checks with an exact method/URI
allow-list and explicit deny-list, then remove every superseded unversioned
patient route.

**Acceptance criteria:**

- [x] Every approved `/api/v1` route is present exactly once.
- [x] Unversioned patient resources and Order/Billing/checkout routes are
      absent.
- [x] Staff-only mutations are outside the patient route group.

**Verification:** Run the exact route-contract test and inspect
`vendor/bin/sail artisan route:list --except-vendor --path=api`.

**Dependencies:** Tasks 19–23.

**Files likely touched:** API routes, route-contract test, obsolete route-only
controllers or tests from the manifest.

**Estimated scope:** Medium.

### Checkpoint E

- [ ] The exact `/api/v1` allow-list and deny-list pass.
- [ ] Cross-patient isolation passes for every resource family.
- [ ] No patient API creates Orders, Job Orders, Invoices, or payments.

## Phase F: Release Evidence

### Task 25: Add complete clinic and privacy acceptance journeys

**Description:** Prove one scheduled-patient journey, one account-less walk-in
journey, patient isolation, and receptionist/optometrist capability boundaries.

**Acceptance criteria:**

- [ ] Both journeys cover appointment through dispensing.
- [ ] Check In creates the Encounter and the combined health record is used.
- [ ] Patient and receptionist negative authorization assertions pass.

**Verification:** Run focused end-to-end and privacy/capability feature suites.

**Dependencies:** Tasks 18 and 24.

**Files likely touched:** Two end-to-end tests, privacy/capability tests, minimal
factory states if required.

**Estimated scope:** Medium.

### Task 26: Produce reproducible technical-release evidence

**Description:** Execute the actual recovery, rebuild, full-suite, formatting,
asset, route, browser, and implemented-context gates before closing release
claims.

**Acceptance criteria:**

- [ ] Backup dump/restore evidence uses non-sensitive test data.
- [ ] Fresh seed, zero-failure full suite, Pint, production build, route/schema
      scans, and both clinic browser journeys pass.
- [ ] `BACKEND_CONTEXT.md`, Task 40, and Checkpoint 8 report only verified
      implementation evidence.

**Verification:** Execute every named command from the stabilization
specification and record its exact result; do not infer any pass.

**Dependencies:** Task 25.

**Files likely touched:** Recovery/release tests or procedure, verified
`BACKEND_CONTEXT.md`, existing redesign task evidence.

**Estimated scope:** Medium.

### Checkpoint F

- [ ] Technical criteria 1–19 in the stabilization specification have recorded
      evidence.
- [ ] Android integration and production-governance criteria remain separate
      and are not marked complete by backend tests.

## Phase 3 Approval Gate

- [x] Every task has a focused acceptance and verification boundary.
- [x] Dependency order and checkpoints are approved.
- [x] Repeatable deletion/API slices remain limited to five primary files.
- [x] The project owner approves Tasks 1–26.
- [x] The project owner authorizes Phase 4 implementation beginning with
      Task 1 only.
