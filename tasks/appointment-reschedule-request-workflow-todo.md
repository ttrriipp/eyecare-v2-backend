# Task Checklist: Patient Appointment Reschedule Requests

**Status:** Approved by the user on 2026-09-07; Tasks 1-13 complete; Checkpoints A-B passed
**Specification:**
`docs/specs/appointment-reschedule-request-workflow-spec.md` (approved
2026-09-07)
**Plan:** `tasks/appointment-reschedule-request-workflow-plan.md` (approved
2026-09-07)

Sixteen dependency-ordered implementation tasks across four phases. No
application code may change under the task and checkpoint rules below.

## Execution Rules

- Implement in order and do not cross a checkpoint until its evidence passes.
- Before each Laravel, Filament, or Pest change, use Laravel Boost
  `search-docs` with version-scoped topic queries.
- Apply `laravel-best-practices` to PHP/Laravel work,
  `test-driven-development` and `pest-testing` to every behavior change,
  `security-and-hardening` to ownership and free-text boundaries,
  `api-and-interface-design` to the public contract, and
  `incremental-implementation` to each vertical slice.
- Generate supported Laravel and Pest files with Sail Artisan and
  `--no-interaction`. Run PHP, Artisan, Composer, and Node through Sail.
- Add or update a failing focused Pest expectation before implementation.
- Run `vendor/bin/sail bin pint --dirty --format agent` after every PHP task.
- Preserve unrelated worktree edits; never delete or weaken an existing test.
- Stop and split a task if it grows beyond roughly five files, three acceptance
  criteria, or one focused implementation session.
- Keep patient reason text out of logs, audits, badges, admin notifications,
  SMS, and production-facing test failure output.
- Stop for approval before adding dependencies, capacity holds, unrequested
  staff-selected times, a new cutoff or rate limit, new delivery channels, or
  any change to immediate confirmed-appointment cancellation.

---

## Phase 1: Patient Request Vertical Slice

### Task 1: Establish the request lifecycle model

**Acceptance:**

- [x] Add the approved schema, indexes, foreign keys, status enum, model,
      relationships, request-number generation, and factory states.
- [x] Effective status consistently recognizes pending, approved, rejected,
      cancelled, and expired requests.
- [x] Keep existing `appointment_reschedules` schema and history semantics
      unchanged.

**Verify:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentRescheduleRequestModelTest.php`
- [x] The focused test rebuilds the schema in its isolated test database and
      proves the migration and foreign keys apply successfully.
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** None

**Files:** migration, status enum, model, factory, focused model test (5)

### Task 2: Implement concurrency-safe request submission

**Acceptance:**

- [x] Lock the appointment and validate linked ownership, future scheduled
      state, original snapshot, preferences, availability, and expiry.
- [x] Enforce one effective pending request under repeated or concurrent
      attempts.
- [x] Return stable state errors and create no request, audit, or notification
      after a failed submission.

**Verify:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/SubmitAppointmentRescheduleRequestTest.php`
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 1

**Files:** submit action, state exception, audit enum, exception renderer,
focused action test (5)

### Task 3: Expose create, list, and detail endpoints

**Acceptance:**

- [x] Create returns `201` and never changes the appointment.
- [x] List and show are patient-safe, paginated or scoped as applicable, and
      use active-link authorization plus enumeration-safe `404` ownership.
- [x] Validation rejects malformed, unknown, duplicate, past, and unavailable
      preferences with the documented envelope.

**Verify:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRescheduleRequestTest.php --filter='create|list|show'`
- [x] `vendor/bin/sail artisan route:list --path=api/v1 --except-vendor`
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 2

**Files:** form request, API resource, controller, routes, API test (5)

### Task 4: Add patient withdrawal

**Acceptance:**

- [x] An owning patient can withdraw an effective pending request and receive
      the resource with `status: cancelled`.
- [x] Withdrawal records a PII-safe audit and changes neither the appointment
      nor completed reschedule history.
- [x] Terminal, expired, or non-owned attempts fail with stable state or `404`
      behavior and zero writes.

**Verify:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRescheduleRequestTest.php --filter=withdraw`
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 3

**Files:** withdrawal action, controller, routes, API test (4)

### Task 5: Project pending state through appointments

**Acceptance:**

- [x] Appointment list and detail include the authenticated patient's effective
      pending request or `null`.
- [x] Terminal and effectively expired requests are excluded without querying
      from inside `AppointmentResource`.
- [x] Existing appointment fields, ratings, and query behavior do not regress.

**Verify:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRescheduleRequestTest.php --filter=pending_projection`
- [x] Existing focused appointment API tests pass.
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 3 and 4

**Files:** appointment model, appointment resource, appointment controller,
API test (4)

### Checkpoint A: Patient request contract

- [x] Tasks 1-5 focused suites pass.
- [x] Submission and withdrawal leave the appointment unchanged.
- [x] One effective pending request is enforced.
- [x] Ownership and sensitive-reason boundaries pass.
- [x] Appointment pending projection is accurate and query-safe.
- [x] Migrations rebuild cleanly and Pint is clean.

---

## Phase 2: Staff Resolution and Scheduling Integrity

### Task 6: Extract the shared final reschedule mutation

**Acceptance:**

- [x] One collaborator moves the appointment, creates one immutable history
      row, and audits the explicit initiator and actor.
- [x] Existing clinic rescheduling retains validation, SMS, patient
      notification, history, and audit behavior.
- [x] Existing direct patient rescheduling remains temporarily green until the
      coordinated retirement task.

**Verify:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/AppointmentSchedulingTest.php`
- [x] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php --filter=reschedul`
- [x] Existing patient reschedule API tests pass.
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Checkpoint A

**Files:** shared mutation action, existing reschedule action, scheduling test,
Filament appointment test (4)

### Task 7: Implement atomic staff approval

**Acceptance:**

- [x] Approval accepts only a submitted choice and revalidates request,
      appointment, original snapshot, reviewer, and schedule under locks.
- [x] Success updates request and appointment atomically and creates exactly one
      linked completed-history row.
- [x] Conflict, stale state, or replay cannot produce duplicate movement,
      history, audit, or notification effects.

**Verify:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRescheduleRequestTest.php --filter=approve`
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 6

**Files:** approval action, request model, review action test (3)

### Task 8: Implement staff rejection

**Acceptance:**

- [x] Active staff or administrators can reject an effective pending request
      with a bounded, patient-safe reason.
- [x] Rejection stores reviewer and resolution time without changing the
      appointment or history.
- [x] Unauthorized, stale, or terminal attempts create no writes.

**Verify:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRescheduleRequestTest.php --filter=reject`
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 7

**Files:** rejection action and review action test (2)

### Task 9: Expire stale requests deterministically

**Acceptance:**

- [x] Effective stale requests cannot be approved before persisted expiry.
- [x] The scheduled command persists expiry once, records a PII-safe audit, and
      never changes an appointment.
- [x] Direct reschedule and concurrent approval resolve consistently through
      snapshot checks and locks.

**Verify:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ExpireAppointmentRescheduleRequestsTest.php`
- [x] Existing `ExpireAppointmentRequestsTest` passes.
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 7 and 8

**Files:** expiry action, existing expiry action, scheduled command, expiry test
(4)

### Checkpoint B: Resolution integrity

- [x] Tasks 6-9 focused suites pass.
- [x] Only submitted and currently available choices can be approved.
- [x] Approval creates one appointment movement and history row.
- [x] Rejection, conflict, expiry, and stale state preserve the appointment.
- [x] Existing direct clinic rescheduling remains unchanged.
- [x] Scheduling and expiry regressions pass and Pint is clean.

---

## Phase 3: Filament Review and Notifications

### Task 10: Build the Filament review queue

**Acceptance:**

- [x] Active panel users can view the queue; inactive and patient-only accounts
      cannot.
- [x] Effective pending requests sort first and drive the navigation badge;
      terminal and expired rows remain visible as history.
- [x] Search, filtering, and table content remain operational and exclude
      patient free-text reasons.

**Verify:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRescheduleRequestResourceTest.php --filter='list|authorization|badge'`
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Checkpoint B

**Files:** Filament resource, list page, table, policy, resource test (5)

### Task 11: Add the review page and resolution actions

**Acceptance:**

- [x] The page shows original time, submitted choices, current availability,
      status, and staff-safe patient context.
- [x] Approve permits one available submitted choice; reject requires a safe
      reason.
- [x] Terminal or expired requests expose no mutation actions, and concurrent
      failures render safely without partial writes.

**Verify:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRescheduleRequestResourceTest.php --filter='view|approve|reject'` (6 tests, 54 assertions)
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 10

**Files:** resource, view page, infolist schema, resource test (4)

### Task 12: Reconcile operational admin alerts

**Acceptance:**

- [x] Submission sends **Appointment Reschedule Requested** once to active
      staff and administrators after commit.
- [x] Withdrawal sends **Appointment Reschedule Request Withdrawn** once and
      links to request details; failed and duplicate transitions are silent.
- [x] Inactive and optometrist-only accounts receive nothing, and alert payloads
      contain no reason text.

**Verify:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Notifications/AdminPatientActionNotificationTest.php --filter='reschedule request'` (2 tests, 36 assertions)
- [x] Full `AdminPatientActionNotificationTest.php` (14 tests, 147 assertions)
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 4 and 11

**Files:** admin notification action, submit action, withdrawal action,
notification test (4)

### Task 13: Notify patients of request outcomes

**Acceptance:**

- [x] Approval sends the final time through patient database notification and
      SMS only after commit.
- [x] Rejection sends database notification and SMS with the safe rejection
      reason and original time; expiry sends database notification only.
- [x] Failure, replay, withdrawal, and stale transitions produce no duplicate
      patient messages or reason-text leakage.

**Verify:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Notifications/AppointmentRescheduleRequestOutcomeNotificationTest.php` (4 tests, 50 assertions)
- [x] Existing appointment notification and SMS tests pass (49 related tests, 336 assertions)
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 7-9

**Files:** outcome notification, approval action, rejection action, expiry
action, shared patient notifier, outcome notification test (6)

### Checkpoint C: Complete staff and patient workflow

- [ ] Tasks 10-13 focused suites pass.
- [ ] Staff can find, review, approve, and reject requests in Filament.
- [ ] Admin and patient notifications match approved transitions and recipients.
- [ ] Patient free text is absent from notification and audit payloads.
- [ ] Failed or replayed transitions produce no duplicate effects.
- [ ] Pint is clean.

---

## Phase 4: Coordinated Cutover and Reconciliation

### Task 14: Retire immediate patient self-rescheduling

**Acceptance:**

- [ ] Remove `POST /api/v1/appointments/{appointment}/reschedule` and retain
      every new reschedule-request route exactly once.
- [ ] No live controller or form-request path lets a patient invoke direct
      rescheduling.
- [ ] Filament direct rescheduling and request approval remain green.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php`
- [ ] `vendor/bin/sail artisan route:list --path=api/v1 --except-vendor`
- [ ] `rg -n "RescheduleAppointmentRequest|appointments/.*/reschedule" app routes tests`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Checkpoint C

**Files:** API routes, appointment controller, obsolete form request, route
contract test (4)

### Task 15: Reconcile canonical documentation and Android handoff

**Acceptance:**

- [ ] API documentation contains the new routes, examples, statuses, errors,
      and removed endpoint.
- [ ] Backend context records the schema, actions, expiry, Filament resource,
      notifications, and route count.
- [ ] Both feature specifications state final shipped behavior and identify
      external Android work clearly.

**Verify:**

- [ ] `git diff --check -- docs tasks`
- [ ] Documented routes match the Sail route list.
- [ ] Documented error payloads match focused API assertions.

**Dependencies:** Task 14

**Files:** API contract, backend context, notification spec, feature spec, task
tracking documents (5)

### Task 16: Run final verification and review

**Acceptance:**

- [ ] Focused API, scheduling, expiry, Filament, notification, SMS, and route
      suites pass without weakened assertions.
- [ ] Pint and `git diff --check` pass, and migration rollback/reapply works in
      the disposable test environment.
- [ ] Full-suite results and any unrelated baseline failures are reported
      precisely, with canonical documentation matching implementation.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRescheduleRequestTest.php tests/Feature/Appointments/SubmitAppointmentRescheduleRequestTest.php tests/Feature/Appointments/ReviewAppointmentRescheduleRequestTest.php tests/Feature/Appointments/ExpireAppointmentRescheduleRequestsTest.php`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRescheduleRequestResourceTest.php tests/Feature/Notifications/AdminPatientActionNotificationTest.php tests/Feature/Notifications/AppointmentRescheduleRequestOutcomeNotificationTest.php`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `git diff --check`

**Dependencies:** Task 15

**Files:** evidence and status only unless review identifies a scoped defect

### Checkpoint D: Ready for Android handoff

- [ ] All sixteen tasks and specification success criteria are reconciled.
- [ ] Immediate patient self-rescheduling is absent.
- [ ] Staff approval is atomic and concurrency-safe.
- [ ] Canonical API and backend documents match code and tests.
- [ ] External Android requirements and deployment ordering are explicit.
- [ ] The change is ready for user review and deployment planning.

## Approval Gate

User approval is required before Task 1 or any application-code change. Approval
confirms all sixteen tasks, checkpoint criteria, focused verification commands,
and execution rules above.
