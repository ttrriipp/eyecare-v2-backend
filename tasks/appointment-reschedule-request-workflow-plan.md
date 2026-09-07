# Implementation Plan: Patient Appointment Reschedule Requests

**Status:** Approved by the user on 2026-09-07
**Specification:**
`docs/specs/appointment-reschedule-request-workflow-spec.md` (approved
2026-09-07)
**Checklist:** `tasks/appointment-reschedule-request-workflow-todo.md` (approved
2026-09-07)

## Outcome

Replace immediate patient self-rescheduling with a staff-reviewed request
workflow. A request records one preferred time and up to two alternatives while
the original appointment remains confirmed. Staff approval revalidates capacity
under the existing schedule locks, changes the appointment once, and creates the
existing immutable reschedule history. Rejection, withdrawal, expiry, and
conflicts never move the appointment.

## Architecture Decisions

### 1. Keep pending requests separate from completed history

Create `appointment_reschedule_requests` and an enum-backed model. Do not add
pending states to `appointment_reschedules`; that table remains the immutable
record of schedule changes that actually occurred.

The new model owns lifecycle state, patient choices, original-time snapshot,
staff resolution, expiry, and the optional link to the completed history row.

### 2. Serialize request creation through the appointment row

MySQL cannot express the required partial uniqueness rule portably. The submit
action will lock the appointment, verify its ownership and current schedule,
expire any stale pending row, then enforce at most one effective pending request
before inserting. The model exposes one shared effective-status predicate used
by API, Filament, expiry, and authorization code.

### 3. Keep requested capacity non-binding

Submission validates that every preference is currently selectable but creates
no capacity hold. Approval locks the affected schedule dates in deterministic
order and reruns the existing provider, duration, grid, clinic-hours, and
capacity checks. A changed schedule produces a safe conflict and zero writes.

### 4. Extract one final schedule-mutation boundary

Refactor the transactional core of `RescheduleAppointment` into a reusable
collaborator that changes the appointment, creates `appointment_reschedules`,
and audits once. Existing direct clinic rescheduling continues through the same
boundary. Request approval supplies `initiated_by: patient` and the approving
staff account as actor without emitting the obsolete
**Appointment Rescheduled by Patient** admin alert.

### 5. Treat request outcomes as explicit API state

Add dedicated create/list/show/withdraw endpoints and a nullable
`pending_reschedule_request` projection on `AppointmentResource`. New state
failures use stable machine-readable codes. Ownership mismatches use `404`;
malformed fields retain Laravel validation responses.

### 6. Separate operational and patient notifications

Submission and withdrawal update the Filament review queue and admin bell.
Approval and rejection notify the patient through database notification and
SMS; expiry uses database notification only. Free-text patient reasons never
appear in notifications, logs, badges, or audit metadata.

### 7. Use a coordinated route cutover

The new resource endpoints are additive. Once their API and Filament behavior
is green, remove the patient-facing
`POST /appointments/{appointment}/reschedule` route rather than silently
changing its response semantics. The internal direct-reschedule action remains
available to Filament. Android implementation is an external handoff and is not
performed in this repository.

## Dependency Graph

```text
Approved specification
        |
        v
Request schema + enum + model
        |
        v
Submit action ----> create/list/show API ----> appointment pending projection
        |                         |
        +------------------------> withdraw API
        |
        v
Shared final schedule mutation
        |
        +--------> approve action
        +--------> reject action
        +--------> expiry action + scheduled command
                           |
                           v
Filament queue + review actions
                           |
                           v
Admin/patient notifications
                           |
                           v
Old route retirement + canonical docs + full verification
```

## Phase 1: Patient Request Vertical Slice

### Task 1: Establish the request lifecycle model

**Description:** Add the table, status enum, Eloquent model, relationships,
factory states, and focused model coverage for effective pending/expired state.

**Acceptance criteria:**

- [ ] The schema contains the approved ownership, preference, resolution,
      expiry, and completed-history fields with appropriate indexes and foreign
      keys.
- [ ] Enum casts, relationships, request-number generation, and effective
      status behave consistently for all five states.
- [ ] `appointment_reschedules` remains unchanged and immutable.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentRescheduleRequestModelTest.php`
- [ ] The focused test rebuilds the schema in its isolated test database and
      proves the migration and foreign keys apply successfully.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** None

**Files likely touched:**

- `database/migrations/*_create_appointment_reschedule_requests_table.php`
- `app/Enums/AppointmentRescheduleRequestStatus.php`
- `app/Models/AppointmentRescheduleRequest.php`
- `database/factories/AppointmentRescheduleRequestFactory.php`
- `tests/Feature/Appointments/AppointmentRescheduleRequestModelTest.php`

**Estimated scope:** M (5 files)

### Task 2: Implement concurrency-safe request submission

**Description:** Add the submit action, stable state exception rendering, audit
event, and action-level tests while leaving HTTP routing unchanged.

**Acceptance criteria:**

- [ ] Submission locks the appointment, validates linked ownership, scheduled
      future state, snapshot consistency, and one effective pending request.
- [ ] Preferred and alternative times are distinct, available at submission,
      non-binding, and produce the approved expiry value.
- [ ] Concurrent/repeated attempts cannot create two effective pending rows and
      failures create no request, audit, or notification.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/SubmitAppointmentRescheduleRequestTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 1

**Files likely touched:**

- `app/Actions/Appointments/SubmitAppointmentRescheduleRequest.php`
- `app/Exceptions/AppointmentRescheduleRequestStateException.php`
- `app/Enums/AuditEvent.php`
- `bootstrap/app.php`
- `tests/Feature/Appointments/SubmitAppointmentRescheduleRequestTest.php`

**Estimated scope:** M (5 files)

### Task 3: Expose create, list, and detail endpoints

**Description:** Add the validated mobile HTTP boundary and patient-safe
resource representation for submission and history reads.

**Acceptance criteria:**

- [ ] The create endpoint returns `201` with a pending request while the
      appointment timestamp and status remain byte-for-byte unchanged.
- [ ] List and detail responses are patient-safe, newest-first, paginated where
      applicable, and scoped through the authenticated active patient link.
- [ ] Unknown fields, invalid times, inactive links, and ownership mismatches
      fail with the approved validation, `403`, or enumeration-safe `404`
      behavior.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRescheduleRequestTest.php --filter='create|list|show'`
- [ ] `vendor/bin/sail artisan route:list --path=api/v1 --except-vendor`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 2

**Files likely touched:**

- `app/Http/Requests/Api/StoreAppointmentRescheduleRequest.php`
- `app/Http/Resources/AppointmentRescheduleRequestResource.php`
- `app/Http/Controllers/Api/AppointmentRescheduleRequestController.php`
- `routes/api.php`
- `tests/Feature/Api/V1/AppointmentRescheduleRequestTest.php`

**Estimated scope:** M (5 files)

### Task 4: Add patient withdrawal

**Description:** Add a locked withdrawal action and endpoint for an owned,
effective pending request.

**Acceptance criteria:**

- [ ] Withdrawal persists `cancelled`, records a PII-safe audit, and returns the
      terminal request resource.
- [ ] Withdrawal never changes the confirmed appointment or completed
      reschedule history.
- [ ] Terminal, expired, and non-owned requests fail with stable state or `404`
      behavior and zero writes.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRescheduleRequestTest.php --filter=withdraw`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 3

**Files likely touched:**

- `app/Actions/Appointments/WithdrawAppointmentRescheduleRequest.php`
- `app/Http/Controllers/Api/AppointmentRescheduleRequestController.php`
- `routes/api.php`
- `tests/Feature/Api/V1/AppointmentRescheduleRequestTest.php`

**Estimated scope:** M (4 files)

### Task 5: Project pending state through appointments

**Description:** Add the relationship and eager-loaded nullable
`pending_reschedule_request` projection used by Android appointment screens.

**Acceptance criteria:**

- [ ] Appointment list/detail responses include only an effective pending
      request owned by the authenticated patient's account.
- [ ] Terminal and effectively expired requests produce `null` without queries
      from inside the resource.
- [ ] Existing appointment response keys, rating behavior, and query counts do
      not regress.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRescheduleRequestTest.php --filter=pending_projection`
- [ ] Existing focused appointment API tests pass.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 3 and 4

**Files likely touched:**

- `app/Models/Appointment.php`
- `app/Http/Resources/AppointmentResource.php`
- `app/Http/Controllers/Api/AppointmentController.php`
- `tests/Feature/Api/V1/AppointmentRescheduleRequestTest.php`

**Estimated scope:** M (4 files)

### Checkpoint A: Patient request contract

- [ ] Tasks 1–5 focused suites pass.
- [ ] Submission and withdrawal leave the confirmed appointment unchanged.
- [ ] One effective pending request is enforced under repeated attempts.
- [ ] Patient ownership and sensitive-reason boundaries are green.
- [ ] Appointment responses expose an accurate nullable pending state.
- [ ] Migrations rebuild cleanly and Pint is clean.

## Phase 2: Staff Resolution and Scheduling Integrity

### Task 6: Extract the shared final reschedule mutation

**Description:** Separate the locked appointment move/history/audit operation
from channel-specific notifications so direct staff rescheduling and request
approval use one mutation path.

**Acceptance criteria:**

- [ ] The collaborator validates and moves an appointment once, creates one
      immutable `appointment_reschedules` row, and records the explicit
      initiator and actor.
- [ ] Existing clinic rescheduling retains its validation, SMS, patient
      notification, history, and audit behavior.
- [ ] Existing patient direct-reschedule behavior remains temporarily green
      until the coordinated retirement task.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/AppointmentSchedulingTest.php`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php --filter=reschedul`
- [ ] Existing patient reschedule API tests pass.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Checkpoint A

**Files likely touched:**

- `app/Actions/Appointments/CommitAppointmentReschedule.php`
- `app/Actions/Appointments/RescheduleAppointment.php`
- `tests/Feature/AppointmentSchedulingTest.php`
- `tests/Feature/Filament/AppointmentResourceTest.php`

**Estimated scope:** M (4 files)

### Task 7: Implement atomic staff approval

**Description:** Add approval that selects one submitted time, revalidates the
request and schedule under locks, invokes the shared mutation, and links the
resulting history row.

**Acceptance criteria:**

- [ ] Approval accepts only a submitted choice and verifies request,
      appointment, original-time snapshot, reviewer, and effective state.
- [ ] Successful approval updates the appointment and request atomically and
      creates exactly one linked completed-history row.
- [ ] Unavailable, stale, or replayed approval leaves all records unchanged or
      returns the already-completed result without duplicating effects.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRescheduleRequestTest.php --filter=approve`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 6

**Files likely touched:**

- `app/Actions/Appointments/ApproveAppointmentRescheduleRequest.php`
- `app/Models/AppointmentRescheduleRequest.php`
- `tests/Feature/Appointments/ReviewAppointmentRescheduleRequestTest.php`

**Estimated scope:** M (3 files)

### Task 8: Implement staff rejection

**Description:** Add the locked rejection transition with a required,
patient-safe reason and no appointment mutation.

**Acceptance criteria:**

- [ ] Active staff/admin reviewers can reject an effective pending request with
      a bounded nonblank reason.
- [ ] Rejection stores reviewer and resolution time while preserving the
      appointment and completed history.
- [ ] Inactive, unauthorized, stale, or terminal attempts fail with zero writes.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRescheduleRequestTest.php --filter=reject`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 7

**Files likely touched:**

- `app/Actions/Appointments/RejectAppointmentRescheduleRequest.php`
- `tests/Feature/Appointments/ReviewAppointmentRescheduleRequestTest.php`

**Estimated scope:** S (2 files)

### Task 9: Expire stale requests deterministically

**Description:** Add idempotent expiry for elapsed preferences, elapsed or
non-scheduled appointments, and original-time snapshots invalidated by direct
clinic changes; integrate it into the existing scheduled expiry command.

**Acceptance criteria:**

- [ ] Effective stale requests cannot be approved even before the scheduler
      persists their `expired` state.
- [ ] The scheduled command persists expiry exactly once and records PII-safe
      audit events without touching appointments.
- [ ] Direct reschedule and concurrent approval resolve consistently through
      appointment/request locks and snapshot checks.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ExpireAppointmentRescheduleRequestsTest.php`
- [ ] Existing `ExpireAppointmentRequestsTest` remains green.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 7 and 8

**Files likely touched:**

- `app/Actions/Appointments/ExpireAppointmentRescheduleRequests.php`
- `app/Actions/Appointments/ExpireAppointmentRequests.php`
- `app/Console/Commands/ExpireAppointmentRequestsCommand.php`
- `tests/Feature/Appointments/ExpireAppointmentRescheduleRequestsTest.php`

**Estimated scope:** M (4 files)

### Checkpoint B: Resolution integrity

- [ ] Tasks 6–9 focused suites pass.
- [ ] Only a submitted, currently available time can be approved.
- [ ] Approval produces one appointment move and one history row.
- [ ] Rejection, conflict, expiry, and stale snapshots produce no appointment
      movement.
- [ ] Existing direct clinic rescheduling is unchanged.
- [ ] Scheduling and expiry regressions pass and Pint is clean.

## Phase 3: Filament Review and Notifications

### Task 10: Build the Filament review queue

**Description:** Add the resource shell, navigation badge, searchable/filterable
table, policy, list page, and focused authorization/list coverage.

**Acceptance criteria:**

- [ ] Active panel users can view the queue; inactive users and patient-only
      accounts cannot.
- [ ] Effective pending requests sort first and drive the navigation badge;
      terminal/effectively expired rows remain available as history.
- [ ] Table content is operational and excludes patient free-text reasons.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRescheduleRequestResourceTest.php --filter='list|authorization|badge'`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Checkpoint B

**Files likely touched:**

- `app/Filament/Resources/AppointmentRescheduleRequests/AppointmentRescheduleRequestResource.php`
- `app/Filament/Resources/AppointmentRescheduleRequests/Pages/ListAppointmentRescheduleRequests.php`
- `app/Filament/Resources/AppointmentRescheduleRequests/Tables/AppointmentRescheduleRequestsTable.php`
- `app/Policies/AppointmentRescheduleRequestPolicy.php`
- `tests/Feature/Filament/AppointmentRescheduleRequestResourceTest.php`

**Estimated scope:** M (5 files)

### Task 11: Add the review page and resolution actions

**Description:** Add a read-only request detail page with current availability
and guarded approve/reject actions using the domain actions from Phase 2.

**Acceptance criteria:**

- [ ] The page displays original time, requested choices, current availability,
      status, and staff-safe patient context.
- [ ] Approve permits exactly one currently available submitted choice; reject
      requires a patient-safe reason.
- [ ] Terminal or effectively expired requests expose no mutation actions and
      concurrent failures render a useful Filament error without partial writes.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRescheduleRequestResourceTest.php --filter='view|approve|reject'`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 10

**Files likely touched:**

- `app/Filament/Resources/AppointmentRescheduleRequests/AppointmentRescheduleRequestResource.php`
- `app/Filament/Resources/AppointmentRescheduleRequests/Pages/ViewAppointmentRescheduleRequest.php`
- `app/Filament/Resources/AppointmentRescheduleRequests/Schemas/AppointmentRescheduleRequestInfolist.php`
- `tests/Feature/Filament/AppointmentRescheduleRequestResourceTest.php`

**Estimated scope:** M (4 files)

### Task 12: Reconcile operational admin alerts

**Description:** Replace the immediate patient-rescheduled alert with request
submission and withdrawal notifications linked to the new review resource.

**Acceptance criteria:**

- [ ] Submission sends **Appointment Reschedule Requested** once to every active
      staff/admin recipient after commit.
- [ ] Withdrawal sends **Appointment Reschedule Request Withdrawn** once and
      links to request details; failed or duplicate transitions are silent.
- [ ] Optometrist-only/inactive accounts receive nothing, and notification
      payloads contain no patient reason text.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Notifications/AdminPatientActionNotificationTest.php --filter=reschedule_request`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 4 and 11

**Files likely touched:**

- `app/Actions/Notifications/NotifyAdminUsers.php`
- `app/Actions/Appointments/SubmitAppointmentRescheduleRequest.php`
- `app/Actions/Appointments/WithdrawAppointmentRescheduleRequest.php`
- `tests/Feature/Notifications/AdminPatientActionNotificationTest.php`

**Estimated scope:** M (4 files)

### Task 13: Notify patients of request outcomes

**Description:** Add one patient-safe outcome notification contract and connect
approval, rejection, and expiry to the approved database/SMS channel rules.

**Acceptance criteria:**

- [ ] Approval sends the final appointment time through patient database
      notification and SMS only after commit.
- [ ] Rejection sends database notification and SMS with the safe rejection
      reason and confirms the original time; expiry sends database notification
      only.
- [ ] Retries, failures, withdrawal, and stale transitions do not duplicate
      messages, and patient reason text never appears in payloads.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Notifications/AppointmentRescheduleRequestOutcomeNotificationTest.php`
- [ ] Existing appointment notification and SMS tests remain green.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 7–9

**Files likely touched:**

- `app/Notifications/AppointmentRescheduleRequestStatusChanged.php`
- `app/Actions/Appointments/ApproveAppointmentRescheduleRequest.php`
- `app/Actions/Appointments/RejectAppointmentRescheduleRequest.php`
- `app/Actions/Appointments/ExpireAppointmentRescheduleRequests.php`
- `tests/Feature/Notifications/AppointmentRescheduleRequestOutcomeNotificationTest.php`

**Estimated scope:** M (5 files)

### Checkpoint C: Complete staff/patient workflow

- [ ] Tasks 10–13 focused suites pass.
- [ ] Staff can find, review, approve, and reject requests through Filament.
- [ ] Admin and patient notifications match the approved transitions and
      recipients.
- [ ] Free-text patient context is absent from notification and audit payloads.
- [ ] Failed/replayed transitions produce no duplicate side effects.
- [ ] Pint is clean.

## Phase 4: Coordinated Cutover and Reconciliation

### Task 14: Retire immediate patient self-rescheduling

**Description:** Remove the old patient route and its now-unused HTTP request
path after the new workflow is green, while preserving Filament's direct clinic
reschedule behavior.

**Acceptance criteria:**

- [ ] `POST /api/v1/appointments/{appointment}/reschedule` is absent and every
      new reschedule-request route is present exactly once.
- [ ] No live controller/request reference permits a patient to invoke the
      direct reschedule action.
- [ ] Filament direct rescheduling and request approval both remain green.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php`
- [ ] `vendor/bin/sail artisan route:list --path=api/v1 --except-vendor`
- [ ] `rg -n "RescheduleAppointmentRequest|appointments/.*/reschedule" app routes tests`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Checkpoint C

**Files likely touched:**

- `routes/api.php`
- `app/Http/Controllers/Api/AppointmentController.php`
- `app/Http/Requests/Api/RescheduleAppointmentRequest.php` (removed if unused)
- `tests/Feature/Api/V1/RouteContractTest.php`

**Estimated scope:** M (4 files)

### Task 15: Reconcile canonical documentation and Android handoff

**Description:** Update authoritative backend/API context and the superseded
notification row with the exact shipped behavior and Android-facing contract.

**Acceptance criteria:**

- [ ] API documentation contains all new routes, request/resource examples,
      state values, error codes, and the removed immediate endpoint.
- [ ] Backend context records the new table, actions, expiry integration,
      Filament resource, notifications, and route count.
- [ ] The feature and notification specs state the final implemented behavior
      and clearly identify the external Android work that remains.

**Verification:**

- [ ] `git diff --check -- docs tasks`
- [ ] Documented routes match `vendor/bin/sail artisan route:list --path=api/v1 --except-vendor`.
- [ ] Error payload examples match focused API assertions.

**Dependencies:** Task 14

**Files likely touched:**

- `docs/API_CONTRACT.md`
- `docs/BACKEND_CONTEXT.md`
- `docs/specs/admin-patient-action-notifications-spec.md`
- `docs/specs/appointment-reschedule-request-workflow-spec.md`
- task tracking documents

**Estimated scope:** M (5 files)

### Task 16: Run final verification and review

**Description:** Run focused and full regression checks, then perform API,
Laravel, security, and code-quality review against every success criterion.

**Acceptance criteria:**

- [ ] Focused API, scheduling, expiry, Filament, notification, SMS, and route
      suites pass without weakening existing assertions.
- [ ] Pint and `git diff --check` are clean; migration rollback/reapply is
      verified in the disposable test environment.
- [ ] Full-suite results and any unrelated pre-existing failures are reported
      precisely, and canonical docs match the final implementation.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRescheduleRequestTest.php tests/Feature/Appointments/SubmitAppointmentRescheduleRequestTest.php tests/Feature/Appointments/ReviewAppointmentRescheduleRequestTest.php tests/Feature/Appointments/ExpireAppointmentRescheduleRequestsTest.php`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRescheduleRequestResourceTest.php tests/Feature/Notifications/AdminPatientActionNotificationTest.php tests/Feature/Notifications/AppointmentRescheduleRequestOutcomeNotificationTest.php`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `git diff --check`

**Dependencies:** Task 15

**Files likely touched:** No planned production files; only evidence/status
updates if review finds no defect.

**Estimated scope:** S

### Checkpoint D: Ready for Android handoff

- [ ] All sixteen tasks and all specification success criteria are reconciled.
- [ ] The backend no longer allows immediate patient self-rescheduling.
- [ ] The staff approval path is atomic and concurrency-safe.
- [ ] Canonical API/backend documents match code and tests.
- [ ] External Android requirements and deployment ordering are explicit.
- [ ] The change is ready for user review and deployment planning.

## Verification Strategy

Use test-driven vertical slices and the smallest affected Pest files for each
task. Broaden verification at checkpoints. All PHP, Artisan, Composer, and Node
commands run through Sail; supported files are generated with Sail Artisan and
`--no-interaction`.

The final minimum command set is:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRescheduleRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments/SubmitAppointmentRescheduleRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRescheduleRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments/ExpireAppointmentRescheduleRequestsTest.php
vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRescheduleRequestResourceTest.php
vendor/bin/sail artisan test --compact tests/Feature/Notifications/AdminPatientActionNotificationTest.php
vendor/bin/sail artisan test --compact tests/Feature/Notifications/AppointmentRescheduleRequestOutcomeNotificationTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail artisan test --compact
git diff --check
```

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Two submissions race | High | Lock appointment, recheck effective pending state, test concurrent/repeated attempts |
| Requested slot is taken before review | Expected | No hold; final locked availability check; preserve original appointment on conflict |
| Direct staff change makes request stale | High | Compare original-time snapshot and effective appointment state before approval; scheduled expiry persists terminal state |
| Approval duplicates history or messages | High | One transaction, linked history ID, idempotent replay tests, after-commit delivery |
| Old Android client still calls immediate route | High | Add new endpoints first and coordinate route removal with Android release/deployment |
| Resource projection adds N+1 queries | Medium | Explicit eager loading and query-count regression coverage |
| Patient reason leaks into operations data | High | Encrypted storage; resource allowlist; exclude from logs, audits, badges, and notification payloads |
| Expiry command overlaps | Medium | Reuse existing cache lock and row locks; make transitions idempotent |
| Existing dirty worktree changes overlap | Medium | Inspect before every patch, preserve unrelated edits, and review scoped diffs at checkpoints |

## Sequencing

The schema, domain actions, approval mutation, and route retirement are
sequential because they share state and public contracts. Filament work begins
only after resolution actions are stable. Notifications follow their successful
domain transitions. Documentation and route removal wait until the complete
replacement workflow is green.

The plan is approved. No implementation should begin until the generated
execution checklist is also approved.

## Open Questions

None. Plan approval confirms the architecture decisions, ordering, explicit
withdrawal admin alert, and coordinated route cutover above.

## Rollback and Deployment Notes

- Before Android cutover, the new table and endpoints are additive and can be
  rolled back without changing confirmed appointments.
- After request data exists, application rollback must retain or explicitly
  archive the new table; do not silently discard patient requests.
- Removing the immediate route is the policy-enforcement point and must be
  deployed with the Android switch to the new endpoints.
- Queue workers and the every-minute appointment-request expiry scheduler must
  be running before the feature is considered production-ready.
- No dependency change is planned.

## Approval Gate

The user approved this plan and checklist on 2026-09-07, confirming the
sequencing, explicit withdrawal admin alert, shared mutation refactor, and
coordinated removal of the old patient endpoint.
