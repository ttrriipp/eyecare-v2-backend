# Implementation Plan: Appointment-Request Rebooking

**Specification:** `docs/specs/appointment-request-rebooking-spec.md`
**Status:** Approved for implementation on 2026-09-08

## Overview

Reuse the existing `AppointmentRequest` lifecycle for patient rebooking. A
new row links to the existing appointment and snapshots its current scheduled
time. Staff review remains in the existing Appointment Requests resource;
acceptance updates the existing appointment atomically instead of creating a
second appointment.

## Architecture decisions

1. **One request model, explicit kind.** Add `request_type` rather than a
   second model/table. `appointment_id` is the associated appointment for both
   new and rebooking rows; remove only the obsolete uniqueness constraint.
2. **Immutable source request.** Never reopen an accepted booking request. A
   rebooking is a new row with its own request number, status, timestamps, and
   audit entry.
3. **Shared review action with a narrow branch.** Keep `AcceptAppointmentRequest`
   as the staff boundary. Its rebooking path locks the target appointment and
   schedule, delegates availability to the existing evaluator, and records
   one `AppointmentReschedule` history row.
4. **Existing patient API shape, additive fields.** Keep the existing list,
   show, cancel, availability, and request endpoint names. Add only the
   optional submission field and response metadata needed to identify a linked
   request. Remove the direct patient reschedule route during cutover.
5. **Existing staff surface.** Extend the current Appointment Requests table,
   detail form, and Review & Schedule page. Do not add a sidebar resource.

## Dependency graph

```text
Spec and contract
    -> request kind + schema snapshot
        -> unified patient submission and API response
            -> atomic staff acceptance/rejection/expiry
                -> shared Filament review surface and route cutover
                    -> docs, full verification, and review
```

## Task 1: Add linked request state and patient submission

**Acceptance criteria:**

- `AppointmentRequest` distinguishes `new` and `reschedule` rows, snapshots
  the original appointment time, and keeps the existing request lifecycle.
- A linked patient can submit a rebooking through `POST /appointment-requests`
  with no appointment mutation, capacity hold, or duplicate pending row.
- Ownership, target status, future time, distinct preferences, availability,
  reason/identity boundaries, and the two-active-request limit are enforced.

**Files likely touched:** migration, enum, model, factory, submit action,
form request, API controller/resource, focused Pest tests.

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRequestRebookingTest.php tests/Feature/Appointments/SubmitAppointmentRequestTest.php`

`vendor/bin/sail bin pint --dirty --format agent`

## Checkpoint A: Submission contract

- New booking behavior remains green.
- Rebooking creates one pending row and leaves the appointment unchanged.
- Sensitive reason and ownership boundaries pass.
- Migration and model tests pass; Pint is clean.

## Task 2: Resolve linked requests atomically

**Acceptance criteria:**

- Staff approval updates the existing appointment, records one immutable history
  row, stores the selected time, and marks the request accepted atomically.
- Rejection, cancellation, expiry, stale snapshots, and replay preserve the
  appointment and do not duplicate history, SMS, or patient notifications.
- New-booking acceptance remains unchanged.

**Files likely touched:** acceptance action, rejection/expiry actions,
notification/SMS helper if needed, focused review tests.

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRequestTest.php tests/Feature/Appointments/AppointmentRequestRebookingReviewTest.php`

`vendor/bin/sail bin pint --dirty --format agent`

## Checkpoint B: Resolution integrity

- Both request kinds use the same staff acceptance boundary.
- Only the linked appointment moves, once.
- Existing direct clinic rescheduling and new-booking acceptance remain green.
- Rejection, expiry, replay, and conflict tests pass; Pint is clean.

## Task 3: Reconcile staff/API cutover and documentation

**Acceptance criteria:**

- Existing Appointment Requests Filament pages identify and review linked rows
  without exposing patient free text.
- API list/detail/cancel responses document the additive request type and
  associated appointment; the direct patient reschedule route is removed.
- API contract, backend context, and the rebooking spec match routes, states,
  notifications, and deployment ordering.

**Files likely touched:** Filament request resource/pages/table/form, routes,
appointment controller, route/API tests, API contract, backend context, task
checklist.

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRequestResourceTest.php tests/Feature/Api/V1/RouteContractTest.php tests/Feature/Api/V1/AppointmentRequestRebookingTest.php`

`vendor/bin/sail bin pint --dirty --format agent`

`git diff --check`

## Checkpoint C: Handoff readiness

- All three tasks and success criteria are reconciled.
- The unified request flow is the only patient rebooking path.
- Canonical docs match the implementation and tests.
- Focused and full suites are green; the working tree is clean.

## Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Existing `appointment_id` uniqueness blocks multiple request rows | High | Drop only the unique index in an additive migration; retain the foreign key and historical rows. |
| A clinic change races approval | High | Lock request, target appointment, and schedule dates; compare `original_scheduled_at`. |
| Rebooking acceptance creates a second appointment | High | Branch in the existing acceptance action and assert appointment count/history in Pest. |
| Patient reason leaks into operational messages | Medium | Keep encrypted storage and use identifier/status-only notification payloads. |

