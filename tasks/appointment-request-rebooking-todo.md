# Task Checklist: Appointment-Request Rebooking

**Status:** Approved by the user on 2026-09-08; implementation pending
**Specification:** `docs/specs/appointment-request-rebooking-spec.md`
**Plan:** `tasks/appointment-request-rebooking-plan.md`

## Execution rules

- Implement Task 1, review Checkpoint A, then Task 2, review Checkpoint B, then
  Task 3 and Checkpoint C.
- Use Laravel Boost `search-docs` before Laravel, Filament, or Pest changes.
- Add a failing focused Pest expectation before behavior changes.
- Run `vendor/bin/sail bin pint --dirty --format agent` after every PHP task.
- Commit each task or checkpoint as a separate logical save point.
- Do not reopen the original accepted booking request or create a second
  appointment for a linked rebooking.
- Keep patient reason text out of audit metadata, admin alerts, SMS, and test
  failure output.

## Task 1: Add linked request state and patient submission

- [ ] Add `request_type`, `original_scheduled_at`, and
      `selected_scheduled_at` with the appointment association/index change.
- [ ] Extend model, enum, factory, validation, submission, and API response
      for linked rebooking rows.
- [ ] Prove target ownership, availability, pending uniqueness, active limit,
      and no appointment mutation with focused Pest tests.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRequestRebookingTest.php tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Commit:** `feat: add linked appointment rebooking requests`

## Checkpoint A: Submission contract

- [ ] New-booking behavior remains green.
- [ ] Rebooking creates one pending row and leaves the appointment unchanged.
- [ ] Ownership, validation, and sensitive-reason boundaries pass.
- [ ] Migration/model evidence and Pint are clean.

**Review commit:** `test: close appointment rebooking submission checkpoint`

## Task 2: Resolve linked requests atomically

- [ ] Approve a linked request by moving the existing appointment once and
      recording immutable history.
- [ ] Preserve appointment state on rejection, cancellation, expiry, stale
      snapshots, conflicts, and replay.
- [ ] Reuse existing patient outcome delivery without duplicate effects.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRequestTest.php tests/Feature/Appointments/AppointmentRequestRebookingReviewTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Commit:** `feat: resolve linked appointment rebooking requests`

## Checkpoint B: Resolution integrity

- [ ] Both request kinds share the staff acceptance boundary.
- [ ] Only the linked appointment moves, once.
- [ ] Rejection, expiry, replay, and conflict tests pass.
- [ ] Existing clinic rescheduling and new-booking acceptance remain green.

**Review commit:** `test: close appointment rebooking resolution checkpoint`

## Task 3: Reconcile staff/API cutover and documentation

- [ ] Extend the existing Filament request queue/detail/review UI for linked
      rows without exposing patient free text.
- [ ] Remove the direct patient reschedule route and update API/route tests.
- [ ] Update API contract and backend context; record Android handoff and
      deployment ordering.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRequestResourceTest.php tests/Feature/Api/V1/RouteContractTest.php tests/Feature/Api/V1/AppointmentRequestRebookingTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `git diff --check`

**Commit:** `docs: reconcile appointment-request rebooking contracts`

## Checkpoint C: Handoff readiness

- [ ] All three tasks and success criteria are reconciled.
- [ ] Unified appointment-request flow is the only patient rebooking path.
- [ ] Canonical docs match code and tests.
- [ ] Focused and full suites are green; the working tree is clean.

**Review commit:** `test: close appointment-request rebooking workflow`

