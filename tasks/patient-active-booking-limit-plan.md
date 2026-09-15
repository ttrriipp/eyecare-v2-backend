# Implementation Plan: One Active Patient Booking Journey

Status: Approved direction — implementation pending  
Date: 2026-09-15

## Overview

Limit each patient account to one active self-service booking journey at a time.
This is primarily a backend rule so every client receives the same protection.
Android should also reflect the rule in its interface, but client-side controls
must not be the only enforcement.

An active booking journey is either:

- one actionable pending appointment request; or
- one future scheduled or currently checked-in appointment.

A pending rebooking request for the patient's existing appointment remains part
of the same journey and is allowed when all other rebooking rules pass.

## Goals

- Prevent patients from creating overlapping appointment requests or appointments.
- Apply the rule consistently to new requests and rebooking requests.
- Preserve a clear route for rescheduling or cancelling an existing appointment.
- Give Android enough eligibility data to explain why booking is unavailable.
- Make concurrent submissions safe so two requests cannot pass simultaneously.
- Preserve staff workflows and existing records unless explicitly changed.

## Non-goals

- Limiting direct appointment creation by authorized staff in Filament.
- Removing alternative proposed times from one request.
- Automatically cancelling or deleting existing conflicting data.
- Adding a database constraint that attempts to encode cross-table lifecycle rules.
- Implementing the Android interface in this backend repository.

## Confirmed Policy

### What blocks a new request

A patient cannot submit a new appointment request when the linked account has:

- an actionable pending appointment request;
- a future appointment with status scheduled; or
- an appointment with status checked_in until it reaches a terminal status.

An actionable pending request is one that is still pending, has not expired, and
is not a stale rebooking request for an appointment that is no longer scheduled.

### What does not block a new request

- Requests that are accepted, rejected, cancelled, expired, or otherwise stale.
- Appointments that are fulfilled, cancelled, or marked no_show.
- A past scheduled appointment, so stale historical data cannot block booking
  indefinitely.

### Rebooking

A patient may submit a rebooking request for their own future scheduled
appointment. The scheduled appointment and its pending rebooking request are one
booking journey, not two.

Rebooking remains unavailable when:

- that appointment already has an actionable pending rebooking request;
- the account has another actionable pending request;
- the appointment is not owned by the patient;
- the appointment is no longer scheduled; or
- the requested replacement time fails existing availability or timing rules.

### Existing operations

The limit must not prevent a patient from:

- viewing their requests or appointments;
- updating an eligible pending request;
- cancelling a pending request;
- cancelling or rescheduling through an allowed existing workflow; or
- viewing availability.

### Staff behavior

- Authorized staff may continue creating appointments directly.
- When staff accept a pending new appointment request, the backend must recheck
  whether the patient now has another active appointment.
- If a conflict appeared after submission, acceptance must stop with a validation
  error rather than silently creating a second active appointment.
- A rebooking acceptance may update the appointment that belongs to that same
  booking journey.

### Existing conflicting records

Existing conflicts are grandfathered. The change must not delete, cancel, or
rewrite them automatically. Those accounts cannot start another booking journey
until their active records are resolved.

## Current Backend Findings

- The configured active request limit is currently 2 at
  config/patient_accounts.php.
- SubmitAppointmentRequest enforces the pending-request count for both new
  requests and rebooking.
- The rebooking path performs its check while locking the account, while the new
  request path currently checks outside its transaction and is vulnerable to a
  concurrent double submission.
- The API already returns HTTP 422 with ACTIVE_REQUEST_LIMIT_REACHED.
- AppointmentRequest::actionablePending() already captures request expiry and
  stale-rebooking semantics.
- Patient self-service booking uses POST /api/v1/appointment-requests.
- Direct POST /appointments is retired.
- GET /appointments lists confirmed appointments.
- The API contract currently documents a maximum of two active requests.

## Architecture Decisions

### 1. Centralize booking eligibility

Create a small domain action or service that evaluates a patient account and
returns a value object containing:

- whether a new request may be submitted;
- the blocking reason, if any;
- the actionable pending request, if any;
- the blocking appointment, if any; and
- whether rebooking the active appointment is available.

Use this evaluator in submission, staff acceptance, and API eligibility metadata.
Keep query scopes on the relevant models for reusable definitions such as
actionable pending requests and active appointments.

### 2. Serialize patient submissions

For both new requests and rebooking:

1. Start a database transaction.
2. Lock the patient account row with lockForUpdate().
3. Evaluate eligibility inside the transaction.
4. Validate the requested appointment or rebooking context.
5. Create the request.

Locking the common account row makes concurrent requests for the same patient
serialize consistently.

### 3. Do not add a schema constraint

The rule depends on:

- two tables;
- request expiry;
- request and appointment statuses;
- future versus past timestamps; and
- whether a rebooking request points to the same appointment.

A normal MySQL unique constraint cannot accurately represent those conditions.
Transactional application enforcement is the appropriate primary mechanism.

### 4. Preserve compatible API errors

Keep the existing request-limit error shape and change its configured maximum to
one:

~~~json
{
  "error": {
    "code": "ACTIVE_REQUEST_LIMIT_REACHED",
    "message": "You already have an active appointment request.",
    "max_active_requests": 1,
    "active_request_id": 123
  }
}
~~~

The active_request_id may be included only when the request belongs to the
authenticated account.

Add a distinct conflict for an active appointment:

~~~json
{
  "error": {
    "code": "ACTIVE_APPOINTMENT_EXISTS",
    "message": "You already have an active appointment. You may reschedule or cancel it before requesting another.",
    "appointment_id": 42,
    "appointment_status": "scheduled"
  }
}
~~~

Use HTTP 422 for both because the authenticated request is understood but cannot
be accepted in the patient's current booking state.

### 5. Expose booking eligibility

Add an additive object to the appointment-request list response:

~~~json
{
  "meta": {
    "booking_eligibility": {
      "can_submit_new_request": false,
      "blocking_reason": "scheduled_appointment_exists",
      "active_request_id": null,
      "appointment_id": 42,
      "can_request_rebooking": true
    }
  }
}
~~~

Allowed blocking_reason values:

- active_request_exists
- scheduled_appointment_exists
- checked_in_appointment_exists
- null

The backend errors remain authoritative. Metadata is a convenience that lets
Android render the correct state before a submission attempt.

### 6. Keep alternative times

Alternative times belong to one request and do not reserve capacity. They should
remain available because they improve the chance that staff can accept the
patient's single request without starting another journey.

## Implementation Tasks

### Task 1: Characterize current behavior

- Add or update focused Pest tests for the current request, rebooking, ownership,
  and staff-review flows.
- Confirm the current two-request behavior before changing the configuration.
- Add concurrency-oriented coverage where the test environment can reliably
  exercise the account lock; otherwise prove the transaction and lock path with
  focused integration coverage.

### Task 2: Add the shared eligibility evaluator

- Define the active-appointment query semantics.
- Create the eligibility result value object.
- Create the evaluator action or service.
- Cover pending, expired, stale, scheduled, past scheduled, checked-in, and
  terminal cases.
- Cover rebooking of the same scheduled appointment.

### Task 3: Enforce one actionable pending request safely

- Change appointment_requests.max_active_per_account from 2 to 1.
- Move the new-request eligibility check inside the transaction.
- Lock the patient account before evaluating and creating.
- Reuse the same transactional path for rebooking where practical.
- Keep ACTIVE_REQUEST_LIMIT_REACHED and return max_active_requests as 1.

### Task 4: Block new requests when an active appointment exists

- Check future scheduled appointments.
- Check checked-in appointments.
- Exclude past scheduled and terminal appointments.
- Throw a dedicated domain exception mapped to ACTIVE_APPOINTMENT_EXISTS.
- Return only appointment context owned by the authenticated patient.

### Task 5: Recheck during staff acceptance

- Lock the relevant patient account during acceptance.
- Re-evaluate a pending new request before creating its appointment.
- Reject acceptance when another active appointment now exists.
- Permit a valid rebooking acceptance to update its own scheduled appointment.
- Ensure failed acceptance leaves the request and appointment unchanged.

### Task 6: Publish eligibility to clients

- Add meta.booking_eligibility to GET /api/v1/appointment-requests.
- Keep the addition backward compatible.
- Verify metadata and submission decisions use the same evaluator.
- Test each blocking reason and the eligible state.

### Task 7: Update backend documentation

- Update docs/API_CONTRACT.md from two active requests to one active booking
  journey.
- Document the active-appointment error.
- Document booking_eligibility and its blocking reasons.
- Document that rebooking the same scheduled appointment is allowed.
- Document that staff-created appointments remain outside the patient submission
  limit.

### Task 8: Coordinate the Android companion change

This task belongs in the Android repository after the backend contract is ready:

- Read booking_eligibility when loading the appointment-request screen.
- Disable or replace the Request Appointment action when blocked.
- Link the scheduled-appointment state to reschedule or cancel actions.
- Show a pending-request state that links to the existing request.
- Continue handling both backend 422 error codes in case state changes after load.
- Refresh requests, appointments, and eligibility after create, cancel, accept,
  reject, or rebook operations.

### Task 9: Final verification

Run the focused suites:

~~~text
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SubmitAppointmentRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRequestRebookingTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php
vendor/bin/sail artisan test --compact tests/Feature/UpdateAppointmentRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentRequestRebookingReviewTest.php
vendor/bin/sail bin pint --dirty --format agent
~~~

Run any additional affected tests discovered during implementation.

## Test Matrix

| Existing state | Attempt | Expected result |
| --- | --- | --- |
| No active request or appointment | New request | Allowed |
| One actionable pending new request | New request | 422 ACTIVE_REQUEST_LIMIT_REACHED |
| One actionable pending rebooking request | New request | 422 ACTIVE_REQUEST_LIMIT_REACHED |
| Expired pending request | New request | Allowed |
| Stale pending rebooking request | New request | Allowed |
| Future scheduled appointment | New request | 422 ACTIVE_APPOINTMENT_EXISTS |
| Checked-in appointment | New request | 422 ACTIVE_APPOINTMENT_EXISTS |
| Past scheduled appointment | New request | Allowed |
| Fulfilled, cancelled, or no-show appointment | New request | Allowed |
| Own future scheduled appointment | Rebook same appointment | Allowed |
| Same appointment already has pending rebooking | Rebook same appointment | Rejected |
| Another actionable request exists | Rebook appointment | Rejected |
| Another patient's appointment | Rebook appointment | Rejected without data leakage |
| Conflict appears before staff accepts new request | Accept request | Validation failure; no second appointment |
| Valid rebooking request | Accept request | Existing appointment moved; no second appointment |
| Two concurrent submissions | Submit both | At most one request created |

## Risks and Mitigations

- Race conditions: lock the patient account and evaluate inside the transaction.
- Definition drift: share one evaluator across writes and response metadata.
- Stale scheduled data: require the appointment to be in the future, except
  checked_in, which blocks until terminal.
- Information leakage: include only identifiers for resources owned by the
  authenticated account.
- Staff workflow regression: keep direct staff creation unrestricted and test
  only the patient-request acceptance boundary.
- Client drift: backend remains authoritative and Android handles server errors
  even when eligibility metadata was recently loaded.
- Existing conflicts: grandfather them and block only further self-service
  booking until resolved.

## Completion Criteria

- A patient account can have at most one actionable pending request.
- A patient cannot create a new request while a future scheduled or checked-in
  appointment is active.
- A patient can still request rebooking for that same future scheduled
  appointment.
- Concurrent submissions cannot create two actionable requests.
- Staff acceptance cannot create a conflicting second active appointment.
- Existing conflicts are preserved without automatic mutation.
- API errors and eligibility metadata are documented and tested.
- Android work is tracked as a separate companion change.
- Focused backend tests pass and modified PHP files are formatted with Pint.
