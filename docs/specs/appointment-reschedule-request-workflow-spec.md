# Spec: Patient Appointment Reschedule Requests

## Status

Approved by the user on 2026-09-07. Implementation remains gated on approval
of the dependency-ordered plan and task checklist.

This specification replaces immediate patient self-rescheduling with a
staff-reviewed request workflow. It supersedes the patient-originated confirmed
appointment reschedule event in
`docs/specs/admin-patient-action-notifications-spec.md`; all other events and
rules in that specification remain unchanged.

## Objective

Allow a patient to request a different appointment time without immediately
changing or surrendering the confirmed appointment. Clinic staff retain control
of the schedule and approve a requested time only after its availability is
revalidated.

Success means:

1. submitting a request never changes the confirmed appointment;
2. staff can approve one patient-requested time or reject the request;
3. approval is concurrency-safe and creates the existing immutable reschedule
   history;
4. rejection, withdrawal, expiry, and scheduling conflicts leave the confirmed
   appointment unchanged; and
5. both Android and Filament show an unambiguous pending and final state.

## Product Decisions

- The original appointment remains confirmed at its existing time until staff
  approval succeeds.
- Requested times do not reserve or reduce appointment capacity.
- A patient may have only one pending reschedule request per appointment.
- A request contains one preferred time and up to two distinct alternatives.
- Staff may approve only one of the submitted times. Staff must use the existing
  direct clinic reschedule workflow if a different time is agreed separately.
- Patients may withdraw their own pending request. Withdrawal does not cancel
  or modify the appointment.
- Patient cancellation of the confirmed appointment remains a separate,
  immediate workflow and is outside this change.
- Direct staff rescheduling remains available. It supersedes any pending
  patient reschedule request because the request's original appointment
  snapshot is then stale.
- There is no additional hour-based cutoff. A request may be submitted only
  while the appointment is `scheduled` and in the future. The app must explain
  that the original appointment remains in force and urgent changes should be
  handled by contacting the clinic.
- Existing `appointment_reschedules` rows remain immutable records of completed
  schedule changes. Pending requests use a new table and model.

## Patient Workflow

1. Android shows **Request reschedule** for a future appointment whose status is
   `scheduled` and which has no pending reschedule request.
2. The patient selects a preferred available time and optionally up to two
   alternatives, then reviews the original and requested times.
3. Android displays: "Your current appointment will stay scheduled until the
   clinic approves a new time."
4. Submission creates a pending request and an actionable admin notification.
5. While pending, Android shows the submitted choices and a **Withdraw request**
   action instead of allowing another submission.
6. Approval updates the appointment and shows the selected time. Rejection,
   withdrawal, or expiry preserves the original appointment.

## Staff Workflow

- Add a **Reschedule Requests** review queue under the existing appointment
  workflow in Filament.
- The list shows request number, patient, appointment number, original time,
  preferred time, submission time, and status. It must not show free-text reason
  content in navigation badges or notification bodies.
- The review page shows all requested times and current availability.
- **Approve** requires staff to select one submitted time. The action rechecks
  availability under schedule locks immediately before changing the
  appointment.
- **Reject** requires a patient-safe rejection reason.
- A request that is no longer actionable exposes no approve or reject action.
- Staff may open the confirmed appointment and use the existing direct
  reschedule workflow when the clinic and patient agree on a different time.

## State Model

Statuses are `pending`, `approved`, `rejected`, `cancelled`, and `expired`.

| From | Event | To | Appointment effect |
|---|---|---|---|
| none | Patient submits valid request | pending | None |
| pending | Staff approves an available submitted time | approved | Appointment is rescheduled |
| pending | Staff rejects | rejected | None |
| pending | Patient withdraws | cancelled | None |
| pending | Appointment is directly rescheduled, cancelled, checked in, fulfilled, or otherwise leaves `scheduled` | expired | None from this request |
| pending | Original appointment time or all submitted preferences pass | expired | None |

Every transition is terminal except creation of a new request after the previous
request reaches a terminal status. Approval, rejection, withdrawal, and expiry
are idempotent only when replaying the same completed outcome; conflicting
transitions fail with a stable state error.

## Data Model

Create `appointment_reschedule_requests` with:

| Column | Purpose |
|---|---|
| `id` | Primary key |
| `request_number` | Unique patient/staff reference such as `ARR-2026-000001` |
| `appointment_id` | Confirmed appointment being discussed |
| `user_id` | Patient account that submitted the request |
| `patient_id` | Clinical patient snapshot for ownership and history |
| `current_scheduled_at` | Original appointment time at submission |
| `requested_scheduled_at` | Preferred new time |
| `alternative_scheduled_times` | Nullable JSON array with at most two ordered alternatives |
| `encrypted_reason_details` | Nullable patient-provided context, maximum 1000 characters |
| `status` | Pending/approved/rejected/cancelled/expired enum value |
| `selected_scheduled_at` | Submitted time chosen during approval, otherwise null |
| `resolved_by_user_id` | Staff/admin account that approved or rejected |
| `resolved_at` | Approval or rejection time |
| `rejection_reason` | Nullable patient-safe reason returned to the patient |
| `expires_at` | Earlier of the original appointment start and the latest submitted preference |
| `appointment_reschedule_id` | Nullable link to the immutable history row created on approval |
| timestamps | Creation and update times |

Foreign keys preserve historical integrity. Do not overload
`appointment_reschedules` with pending states or nullable completion semantics.

MySQL does not provide a portable partial unique constraint for "one pending
row." Creation must lock the appointment row, recheck for an effective pending
request, and then insert within one transaction.

## Mobile API Contract

All routes require Sanctum authentication and an active patient link. Ownership
is derived from the authenticated account; client-supplied user or patient IDs
are prohibited.

### POST `/appointments/{appointment}/reschedule-requests`

Creates a request without changing the appointment.

```json
{
  "requested_scheduled_at": "2026-09-12T10:00:00+08:00",
  "alternative_scheduled_times": [
    "2026-09-12T11:00:00+08:00"
  ],
  "reason_details": "Work schedule changed."
}
```

Returns `201`:

```json
{
  "data": {
    "id": 15,
    "request_number": "ARR-2026-000001",
    "appointment_id": 42,
    "status": "pending",
    "current_scheduled_at": "2026-09-10T09:00:00+08:00",
    "requested_scheduled_at": "2026-09-12T10:00:00+08:00",
    "alternative_scheduled_times": [
      "2026-09-12T11:00:00+08:00"
    ],
    "selected_scheduled_at": null,
    "rejection_reason": null,
    "expires_at": "2026-09-10T09:00:00+08:00",
    "resolved_at": null,
    "created_at": "2026-09-07T14:00:00+08:00"
  }
}
```

The patient reason is accepted but is not returned by list responses. It may be
returned on the owning patient's detail response if Android needs to display
the submitted text.

### GET `/appointment-reschedule-requests`

Returns the authenticated patient's reschedule-request history, newest first,
using the existing paginated response envelope.

### GET `/appointment-reschedule-requests/{appointmentRescheduleRequest}`

Returns one request owned by the authenticated patient. An ownership mismatch
returns `404` to avoid disclosing another patient's request.

### POST `/appointment-reschedule-requests/{appointmentRescheduleRequest}/cancel`

Withdraws an owned pending request and returns its resource with
`status: "cancelled"`. The confirmed appointment is unchanged.

### Appointment Resource Addition

Confirmed appointment list and detail responses add nullable
`pending_reschedule_request`, containing the request ID, number, status,
preferred time, alternatives, and submission time. This lets Android render the
pending state without an additional request per appointment.

### Stable Errors

New state failures use the API's machine-readable error envelope:

```json
{
  "error": {
    "code": "RESCHEDULE_REQUEST_ALREADY_PENDING",
    "message": "This appointment already has a pending reschedule request."
  }
}
```

| Code | HTTP | Meaning |
|---|---|---|
| `APPOINTMENT_NOT_RESCHEDULABLE` | 422 | Appointment is not future and scheduled |
| `RESCHEDULE_REQUEST_ALREADY_PENDING` | 422 | Appointment already has an effective pending request |
| `RESCHEDULE_REQUEST_NOT_CANCELLABLE` | 422 | Patient attempted to withdraw a terminal request |
| `SLOT_UNAVAILABLE` | 422 | A submitted time is invalid or unavailable at submission |

Validation errors for malformed fields retain Laravel's validation envelope.

## Approval Transaction and Concurrency

Approval must:

1. lock the reschedule request and appointment;
2. confirm request ownership, `pending` state, and effective expiry;
3. confirm the appointment still matches `current_scheduled_at` and remains
   future and `scheduled`;
4. confirm the selected time exactly matches one of the submitted choices;
5. lock affected schedule dates in deterministic order;
6. revalidate provider, duration, grid, clinic hours, and capacity while ignoring
   the appointment's current slot;
7. update the appointment;
8. create one `appointment_reschedules` history row with
   `initiated_by: patient` and the approving staff account as actor;
9. mark the request approved and link the history row; and
10. create audits and patient notifications after the transaction succeeds.

If capacity changed, approval fails and leaves both the appointment and request
unchanged so staff can reject it or arrange a different time. Retrying a
successful approval must not move the appointment or create a second history
row.

## Notifications

- Submission creates an admin database notification titled **Appointment
  Reschedule Requested**, status `info`, linking to the request review page.
- The former patient event **Appointment Rescheduled by Patient** is removed;
  submission is now a request, not a completed schedule change.
- Approval sends the existing patient appointment-rescheduled database
  notification and SMS only after the new time commits.
- Rejection sends a patient database notification and SMS containing the
  patient-safe rejection reason and confirming that the original appointment
  remains scheduled.
- Expiry sends a patient database notification but no SMS.
- Patient withdrawal creates no notification for that same patient, but may
  create an informational admin alert only if the request had already appeared
  in the review queue.
- Notification bodies must not contain `reason_details`.

## Compatibility and Cutover

- Add the new endpoints before the Android change is released.
- Android changes its action from **Reschedule** to **Request reschedule** and
  consumes the request resource and pending state.
- Remove `POST /appointments/{appointment}/reschedule` from the patient API in
  the coordinated cutover. Do not silently change that endpoint from an
  appointment response to a request response because existing clients depend
  on its immediate-reschedule semantics.
- The internal `RescheduleAppointment` action remains available to Filament and
  becomes the shared final schedule mutation used by request approval.
- Update `docs/API_CONTRACT.md`, `docs/BACKEND_CONTEXT.md`, route contract tests,
  and the admin patient-action notification specification when implementation
  ships.

## Tech Stack and Commands

- PHP 8.5, Laravel 13, Filament 5, Livewire 4, Pest 4, MySQL, and Laravel Sail.
- Generate backend files with explicit Sail Artisan commands and
  `--no-interaction`.
- Focused API tests:
  `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRescheduleRequestTest.php`
- Focused Filament tests:
  `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRescheduleRequestResourceTest.php`
- Notification regressions:
  `vendor/bin/sail artisan test --compact tests/Feature/Notifications/AdminPatientActionNotificationTest.php`
- Related scheduling regressions:
  `vendor/bin/sail artisan test --compact tests/Feature/AppointmentSchedulingTest.php tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`
- Format modified PHP:
  `vendor/bin/sail bin pint --dirty --format agent`
- Full verification:
  `vendor/bin/sail artisan test --compact`

## Project Structure

- `app/Models/` and `app/Enums/` contain the request model and status enum.
- `app/Actions/Appointments/` contains submit, withdraw, approve, reject, and
  expiry actions.
- `app/Http/Controllers/Api/`, `app/Http/Requests/Api/`, and
  `app/Http/Resources/` expose the patient contract.
- `app/Filament/Resources/` contains the staff review queue and actions.
- `app/Notifications/` and `app/Actions/Notifications/` retain notification
  delivery responsibilities.
- `tests/Feature/Api/V1/`, `tests/Feature/Filament/`, and
  `tests/Feature/Notifications/` contain behavior and contract coverage.

## Code Style

Use typed, single-purpose Laravel actions and enum-backed state checks:

```php
public function handle(
    AppointmentRescheduleRequest $request,
    CarbonInterface $selectedScheduledAt,
    User $reviewer,
): AppointmentRescheduleRequest {
    return DB::transaction(function () use ($request, $selectedScheduledAt, $reviewer): AppointmentRescheduleRequest {
        // Lock, revalidate, transition, and persist atomically.
    });
}
```

Follow existing snake_case API fields, explicit return types, promoted
constructors, enum casts, Eloquent relationships, policies, API resources, and
Pest conventions. Patient authorization belongs at the request/controller
boundary; state and concurrency invariants belong in actions.

## Testing Strategy

Pest feature coverage must prove:

1. submitting a valid request returns `201` and does not change the appointment;
2. ownership, active-link, appointment state, field validation, and slot checks
   fail safely;
3. concurrent or repeated submissions cannot create two effective pending
   requests for one appointment;
4. withdrawal changes only the request status;
5. approval accepts only a submitted time, rechecks capacity, changes the
   appointment once, and creates exactly one immutable history row;
6. rejection and failed approval leave the appointment unchanged;
7. stale requests expire when appointment state/time or direct staff changes
   invalidate them;
8. patient, staff, and SMS notifications occur only for their specified
   successful transitions and omit sensitive reason text;
9. Filament actions obey active staff/admin authorization and hide terminal
   actions; and
10. the removed immediate patient route and the new route list match the
    coordinated API contract.

## Boundaries

### Always

- Preserve the original appointment until approval commits.
- Revalidate availability under locks during approval.
- Record requester, reviewer, state transitions, final schedule history, and
  PII-safe audit metadata.
- Keep patient reason content out of navigation badges, logs, audit metadata,
  and notification bodies.
- Use the existing patient-link ownership boundary and enumeration-safe `404`
  behavior.
- Update backend and Android contracts together.

### Ask First

- Holding capacity for a pending request.
- Allowing staff to approve a time the patient did not submit.
- Adding an hour-based cutoff or limiting the number of requests over time.
- Adding email, push, or websocket delivery.
- Changing immediate confirmed-appointment cancellation.

### Never

- Change the confirmed appointment at request submission.
- Reuse completed reschedule history as a mutable pending queue.
- Approve without a final availability check.
- Delete terminal requests or immutable reschedule history.
- Expose another patient's request or sensitive free text.

## Success Criteria

1. Patients request rather than directly perform schedule changes.
2. The original appointment remains authoritative until staff approval.
3. Staff can safely approve one requested time or reject with a patient-safe
   reason.
4. All terminal and race conditions preserve consistent request, appointment,
   schedule-history, audit, and notification state.
5. Android can display pending and final outcomes without guessing from the
   appointment timestamp.
6. API, Filament, scheduling, notification, and route-contract tests pass, and
   Pint reports clean formatting.

## Open Questions

None in this draft. Approval confirms the product decisions and cutover rules
above; any requested change returns this document to Proposed status.
