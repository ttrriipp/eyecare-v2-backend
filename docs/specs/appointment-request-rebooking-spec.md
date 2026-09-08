# Spec: Appointment-Request Rebooking

**Status:** Approved for implementation by the user on 2026-09-08
**Planning date:** 2026-09-08

## Objective

Let a patient ask the clinic for a different time by submitting another
ordinary appointment request, without introducing a separate reschedule
resource. The existing confirmed appointment remains scheduled and continues
to block its current slot until staff accepts the linked request.

The original booking request remains immutable. A linked request is a new
historical `AppointmentRequest` row with the same staff queue, review page,
status lifecycle, ownership rules, cancellation behavior, and notification
conventions as a new booking request.

## Assumptions and decisions

1. `POST /api/v1/appointment-requests` remains the only patient submission
   endpoint. Supplying `appointment_id` makes the new row a rebooking request;
   omitting it keeps the existing new-booking behavior.
2. `AppointmentRequest` gains `request_type` (`new` or `reschedule`),
   `original_scheduled_at`, and `selected_scheduled_at`. Its existing
   `appointment_id` becomes the associated appointment for both request types,
   so its old unique index is removed. The original accepted booking row and
   later rebooking rows may therefore reference the same appointment.
3. A rebooking request derives appointment type and duration from the existing
   appointment. The patient must have an active patient link, own the target
   appointment, and submit a future time different from the current time.
4. A pending rebooking request never reserves the proposed slots. The target
   appointment continues to occupy its current slot until approval.
5. Only one effective pending rebooking request may exist for an appointment.
   The account-wide maximum of two active pending appointment requests applies
   to both new-booking and rebooking rows.
6. Staff approval revalidates the request, target appointment, original time,
   submitted choice, and locked availability in one transaction. It updates
   the appointment, creates one immutable `AppointmentReschedule` history row,
   records the selected time, and marks the request accepted.
7. Rejection, patient cancellation, expiry, stale snapshots, and failed
   approval leave the appointment and completed reschedule history unchanged.
   A target appointment that is no longer scheduled makes the request
   effectively expired and non-actionable.
8. The direct patient endpoint
   `POST /api/v1/appointments/{appointment}/reschedule` is removed after the
   unified request path is green. The internal clinic reschedule action stays
   available.

## API contract

Existing new-booking payloads remain valid. The additive `appointment_id`
input is optional:

```json
{
  "appointment_id": 42,
  "scheduled_at": "2026-09-20T10:30:00+08:00",
  "alternative_scheduled_times": [
    "2026-09-20T11:30:00+08:00"
  ],
  "reason_for_visit": null
}
```

When `appointment_id` is present, the server derives the appointment type and
duration, prohibits `identity` and `referring_source`, validates ownership and
the scheduled appointment state, and treats `reason_for_visit` as an optional
request note. The response adds `request_type: "reschedule"`, preserves the
existing status values, returns `original_scheduled_at`, and includes the
associated appointment while pending. New-booking responses continue to use
`request_type: "new"` and retain their current shape.

The existing `GET /api/v1/appointment-availability?appointment_id=...`
contract is the availability source for the rebooking screen; it already
ignores the patient's own appointment when evaluating candidate slots.

## Staff workflow

- The existing Appointment Requests queue labels rebooking rows and links to
  the associated appointment without exposing encrypted identity or narrative
  fields beyond the patient's own request view.
- The existing Review & Schedule page shows the current appointment time and
  submitted alternatives. Appointment type and duration are read-only for a
  rebooking row.
- Accepting a submitted or explicitly chosen available time updates the
  existing appointment, records history/audit data, and sends the existing
  appointment-rescheduled patient notification/SMS after commit.
- Rejecting requires the existing bounded staff reason and never changes the
  appointment.

## Data and security boundaries

- `appointment_id` and `original_scheduled_at` are server-controlled for
  rebooking rows; clients cannot claim another patient's appointment.
- Patient reason text remains encrypted at rest and is omitted from audit
  metadata, admin notification bodies, and SMS content.
- All ownership, active-link, status, future-time, and availability checks
  happen at the API boundary and again under row locks during acceptance.
- No capacity hold, new delivery channel, dependency, or patient-record write
  is introduced.

## Testing strategy

- Add Pest feature coverage for schema/model state, rebooking submission,
  ownership and validation failures, one-pending enforcement, cancellation,
  stale/expired targets, and API response shape.
- Extend acceptance tests for atomic movement, immutable history, replay and
  conflict safety, rejection, and patient notification/SMS outcomes.
- Extend Filament and route-contract tests for the unified request queue and
  removal of the direct patient endpoint.
- Run focused suites, Pint, `git diff --check`, and the full Laravel suite
  before final handoff.

## Commands and project structure

- PHP/Artisan/tests: `vendor/bin/sail ...`
- Domain actions/models: `app/Actions/Appointments`, `app/Models`,
  `app/Enums`
- API boundary: `app/Http/Requests/Api`, `app/Http/Controllers/Api`,
  `app/Http/Resources`
- Staff UI: `app/Filament/Resources/AppointmentRequests`
- Feature tests: `tests/Feature/Api/V1`, `tests/Feature/Appointments`, and
  `tests/Feature/Filament`

## Boundaries

### Always

- Preserve original request and appointment history records.
- Use the existing availability evaluator, schedule-date locks, policies, and
  encrypted casts.
- Add a failing focused Pest expectation before each behavior change.
- Commit and review each implementation slice.

### Ask first

- Any change to cancellation cutoffs, request limits, capacity holds, patient
  record ownership, notification channels, or dependencies.

### Never

- Reopen or mutate the original accepted booking request.
- Create a second appointment for a rebooking request.
- Copy patient free text into logs, audits, admin alerts, or SMS.

## Success criteria

1. A linked patient can submit a rebooking through the existing appointment-
   request endpoint, and the current appointment remains unchanged while the
   request is pending.
2. Staff can approve one available submitted choice; approval moves the same
   appointment once and creates exactly one reschedule-history row.
3. Rejection, cancellation, expiry, stale snapshots, and replay cannot move
   the appointment or create duplicate effects.
4. New-booking requests retain their current API, staff, notification, and
   active-limit behavior.
5. The direct patient reschedule endpoint and separate reschedule resource are
   absent, with canonical API/backend documentation matching the implementation.

