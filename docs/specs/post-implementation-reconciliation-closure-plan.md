# Plan: Post-Implementation Reconciliation Closure

## Status

Approved by the project owner on 2026-07-27. Phase 2 is complete. Phase 3 may
produce the checkbox task breakdown, but application implementation remains
unauthorized until that task breakdown is separately approved.

This plan implements the approved
`post-implementation-reconciliation-closure-spec.md`.

## Outcome

Produce one internally consistent backend where:

- Appointment Type is the only appointment classification;
- development migrations create the canonical schema directly;
- the patient API equals the approved 34-route allow-list;
- retained clinic behavior is mapped to passing tests;
- release evidence reflects commands that were actually executed; and
- `BACKEND_CONTEXT.md` describes the observed implementation.

No new clinic feature, dependency, role, or mobile capability is introduced.

## Architecture Decisions

### 1. Specifications remain authoritative

The approved parent specification and closure specification define intended
behavior. Current code and green tests are evidence to inspect, not sources
that may silently redefine the contract.

### 2. Replace before deleting

Canonical tests and consumers are established before legacy relationships,
factories, seeders, and migrations are removed. Each deletion therefore has a
passing replacement path.

### 3. Use a direct undeployed schema

Because the system is undeployed and its data is disposable, creation
migrations will describe the final schema. Transitional migrations that only
rename or remove legacy structures will be folded into the relevant creation
migrations and deleted.

### 4. Keep staff operations in Filament

Optometrists and receptionists use the private Filament panel. The patient API
will not retain a staff mutation or introduce a parallel staff API.

### 5. Test the API as an equality contract

The route-contract test will compare the actual non-vendor patient API to a
literal representation of the approved 34 routes. Extra routes fail the test
as clearly as missing routes.

### 6. Treat evidence as a deliverable

Tests, browser journeys, schema inspection, route inspection, asset build,
backup/restore validation, static scans, and context reconciliation are
separate gates. A green Pest suite alone cannot close the work.

## Component Map

| Area | Canonical target | Main locations |
| --- | --- | --- |
| Release truth | Reopened contradicted claims and named evidence | `docs/specs/post-implementation-reconciliation-tasks.md`, recovery map |
| Appointment domain | `appointmentType` only; required patient, type, and duration | Appointment model, requests, actions, Filament resources/widgets |
| Legacy support | No Visit Reason, Order, or Billing compatibility files | `app/Models`, factories, seeders, retained tests |
| Database | Direct canonical creation migrations | `database/migrations` |
| Patient API | Exact 34-route allow-list and singular Conversation paths | `routes/api.php`, API controllers/requests/resources |
| Coverage recovery | Every retained assertion mapped to a passing test | `tests/Feature`, recovery map |
| Release evidence | Reproducible technical and manual validation | tracker, recovery map, `BACKEND_CONTEXT.md` |

## Dependency Sequence

```text
Reset contradicted claims
        |
        v
Add mismatch-revealing tests
        |
        v
Complete Appointment Type consumers
        |
        +-----------> Remove dead support files
        |
        v
Rewrite direct canonical migrations
        |
        v
Enforce the exact patient API
        |
        v
Close retained-coverage mappings
        |
        v
Run full release evidence
        |
        v
Reconcile context and close claims
```

The route correction can be developed independently of dead factory cleanup
after the initial claim reset, but final schema and end-to-end checks wait for
both. Work should remain sequential unless separate ownership is explicitly
approved, because the affected tests and evidence documents overlap.

## Milestone 1: Restore Honest Project State

### Changes

1. Reopen Tasks 13, 16–17, 24–28, 35–36, and 39–40.
2. Reopen Checkpoints B, D, E, and F where evidence is contradicted.
3. Preserve prior commit references and clearly label why each claim reopened.
4. Add failing regression assertions for the confirmed mismatches:
   - Appointment and Filament consumers cannot use Visit Reason;
   - required Appointment fields cannot be null;
   - the fresh schema cannot contain transition-only legacy structures;
   - the patient route set must equal the approved 34 routes; and
   - recovery-map completion cannot be claimed with unmapped rows.

### Checkpoint A

- Contradicted claims are visibly open.
- Focused tests fail only for the intended audited mismatches.
- No implementation is deleted merely to make static scans pass.

## Milestone 2: Complete the Appointment Type Cutover

### Changes

1. Replace every `visitReason` consumer with `appointmentType`.
2. Remove `visit_reason_id` and `visitReason()` from Appointment.
3. Update Filament tables, forms, relation managers, calendars, and schedule
   widgets to use populated Appointment Type records.
4. Verify appointment intake still shows appointment type, patient
   information, complaints, and medical history in the approved combined view.
5. Delete VisitReason model, factory, and seeder after replacement tests pass.

### Focused verification

- Appointment model and scheduling tests.
- Appointment Filament resource and widget tests.
- Referral source and duration snapshot tests.
- Static Visit Reason scan.

### Checkpoint B

- No executable Visit Reason reference remains.
- Optometrist and receptionist views show Appointment Type correctly.
- Patient, type, duration, referral source, and intake behavior remain intact.

## Milestone 3: Remove Dead Legacy Support

### Changes

1. Delete Order, Order Item, Order Status, Billing, Billing Item, and Billing
   Status factories and seeders that target removed application concepts.
2. Remove missing-model imports and compatibility assumptions from retained
   tests and seeders.
3. Preserve Job Order, Invoice, Invoice Payment, Frame Reservation, and
   inventory behavior.
4. Keep only canonical seeded clinic scenarios.

### Focused verification

- Seeder and factory tests.
- Job Order, Invoice, payment, reservation, and inventory tests.
- Static legacy model/factory scans.

### Checkpoint C

- Fresh seed uses only existing canonical models and tables.
- No removed Order/Billing concept is reachable.
- Canonical ordering and finance workflows still pass.

## Milestone 4: Rewrite the Canonical Development Schema

### Changes

1. Modify original creation migrations so Appointments directly require:
   - `patient_id`;
   - `appointment_type_id` with restrictive deletion behavior; and
   - `duration_minutes`.
2. Create Prescriptions, Conversations, and Feedback directly with
   `patient_id`.
3. Fold required final indexes, constraints, and columns into creation
   migrations.
4. Delete superseded transition migrations after their final behavior is
   covered.
5. Preserve encryption-compatible columns, soft deletion, audit history,
   append-only payments, and current foreign-key behavior.

### Focused verification

- Migration/schema contract tests.
- `migrate:fresh --seed`.
- Schema, foreign-key, index, and nullability inspection.
- Static scans for legacy columns and removed table concepts.

### Checkpoint D

- A fresh database reaches the canonical schema without legacy transitions.
- Required Appointment fields are non-null at database and validation layers.
- No creation migration introduces structures only to remove them later.

## Milestone 5: Freeze the Patient API Contract

### Changes

1. Change Conversation routes to the approved singular paths.
2. Nest private attachment download beneath Conversation.
3. Remove the unapproved staff mutation and unintended aliases.
4. Update controllers, requests, resources, and route names only as necessary
   to support the approved contract.
5. Enforce attachment membership, Conversation ownership, patient ownership,
   and cross-patient denial.
6. Make the route equality fixture contain exactly the approved 34 routes.

### Focused verification

- Route equality and route-name tests.
- Conversation/message/attachment API tests.
- Positive ownership and cross-patient tests for every patient-owned resource.
- Authentication, authorization, rate-limit, privacy, and security tests.

### Checkpoint E

- The actual patient API equals the 34-route allow-list.
- No staff, plural Conversation, top-level attachment, checkout, payment, or
  legacy route remains.
- Patient isolation passes for every exposed resource.

## Milestone 6: Close Retained Coverage

### Changes

1. Review every “Restore required” recovery-map row.
2. Name current tests for each retained assertion.
3. Add missing canonical coverage using factories and realistic records.
4. Restore only canonical behavior from mixed legacy tests.
5. Keep the test count informational; close rows by behavior evidence.

### Checkpoint F

- Every retained assertion maps to one or more passing tests.
- Both recovery-map completion gates are closed by named evidence.
- No empty or documentation-only claim substitutes for coverage.

## Milestone 7: Produce Final Release Evidence

### Automated gates

1. Run focused suites for appointments, Filament, API, seeders, end-to-end,
   privacy, and security.
2. Run the full Pest suite.
3. Run Pint after PHP changes.
4. Run the production asset build.
5. Run fresh migration and seed.
6. Inspect API and complete route lists.
7. Run all approved static scans.

### Operational gates

1. Exercise seeded optometrist and receptionist Filament journeys in a real
   browser.
2. Inspect actual schema, constraints, indexes, and nullability.
3. Dump and restore only the approved non-sensitive development database into
   the confirmed disposable `eyecare_restore_check` database.
4. Record command, browser, and restore results without credentials or patient
   data.
5. Rewrite `BACKEND_CONTEXT.md` from observed routes, schema, roles,
   navigation, seeded accounts, and verification results.

### Checkpoint G

- Every success criterion in the closure specification has reproducible
  evidence.
- Task 40 and release checkpoints close only with that evidence.
- Android integration and production privacy governance remain separate,
  explicitly open follow-ups.

## Test-First Strategy

Each behavior-changing milestone follows:

1. inspect version-specific Laravel or Filament documentation;
2. identify the smallest mismatching behavior;
3. add or restore a focused failing Pest assertion;
4. make the smallest canonical implementation change;
5. run the focused test;
6. run adjacent regression tests;
7. format changed PHP;
8. commit one coherent milestone; and
9. update evidence only after its command succeeds.

Tests should use populated factories and real relationship records. Empty
relationships, mocked table state, test counts, or static scans alone cannot
prove a user workflow.

## Verification Matrix

| Requirement | Primary proof | Secondary proof |
| --- | --- | --- |
| Appointment Type only | Model/Filament tests | Static scan and browser |
| Required Appointment fields | Validation and persistence tests | Schema inspection |
| No legacy support files | Seeder/factory tests | File and import scans |
| Direct canonical schema | Fresh migration tests | Constraint inspection |
| Exact patient API | Route equality test | Route-list inspection |
| Patient isolation | Cross-patient feature tests | Authorization review |
| Retained behavior | Recovery-map test links | Full suite |
| Staff workflow | Filament feature tests | Real browser journeys |
| Build readiness | Production Vite build | Browser console inspection |
| Recoverability | Controlled dump/restore | Restored table inspection |
| Accurate context | Context diff against evidence | Final review |

## Risks and Mitigations

| Risk | Mitigation |
| --- | --- |
| Deleting legacy files breaks hidden canonical setup | Inspect all consumers and establish replacement tests first |
| Migration rewrite loses a final constraint or index | Compare fresh schema before and after, then inspect foreign keys and indexes |
| Route cleanup breaks the separate Android client | Freeze and publish the backend contract; Android integration remains a separate gate |
| Filament tests pass on empty relationships | Seed real Appointment Type and Patient relationships in UI tests |
| Receptionist gains clinical authority | Preserve existing policies and add negative authorization coverage |
| Finance history becomes mutable | Retain append-only payment/correction tests |
| Static scans report historical documentation | Scope technical scans to executable paths; review documentation matches separately |
| Backup validation targets meaningful data | Confirm disposable environment and exact restore-check database before execution |
| Tracker is closed before evidence exists | Update a checkbox only in the evidence-producing commit |

## Delivery and Commit Strategy

Use small, reversible commits:

1. `docs(release): reopen contradicted reconciliation claims`
2. `test(appointments): expose appointment type cutover gaps`
3. `refactor(appointments): complete appointment type cutover`
4. `refactor(seed): remove dead ordering compatibility`
5. `test(database): define canonical fresh schema`
6. `refactor(database): fold legacy transition migrations`
7. `test(api): enforce approved patient route contract`
8. `refactor(api): align conversation and staff routes`
9. `test(recovery): restore retained behavior coverage`
10. `docs(release): record reconciliation closure evidence`

Exact commit boundaries may combine a test with its implementation when
separating them would leave the branch unusable, but no commit may mix
unrelated milestones or claim unexecuted evidence.

## Phase 2 Approval Gate

- [x] Architecture decisions were accepted on 2026-07-27.
- [x] Milestone order and dependencies were accepted on 2026-07-27.
- [x] Testing and verification strategy were accepted on 2026-07-27.
- [x] Risks and mitigations were accepted on 2026-07-27.
- [x] Commit and checkpoint strategy were accepted on 2026-07-27.
- [x] The project owner approved this technical plan on 2026-07-27.

Phase 3 may now convert this plan into a granular checkbox task list.
Application implementation remains paused until that task list is approved.
