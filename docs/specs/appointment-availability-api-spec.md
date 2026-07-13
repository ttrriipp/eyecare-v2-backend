# Spec: Backend-Supported Appointment Availability

**Status:** Complete — backend implementation shipped  
**Date:** 2026-07-13  
**Scope:** Backend API contract and scheduling guarantees for Android booking and rescheduling.

## Implementation Result

Completed on 2026-07-13 in Phase 4. The backend now:

- Evolves `GET /api/appointments/availability` in place to return the documented metadata object and slot state grid.
- Uses `Asia/Manila` clinic-local dates and explicit-offset ISO-8601 slot timestamps.
- Supports optional `optometrist_id` and customer-owned `appointment_id` self-exclusion.
- Uses one shared availability evaluator for preview, booking, staff scheduled creation, and rescheduling.
- Handles non-grid visit durations and peak concurrent capacity across candidate intervals.
- Treats pending, confirmed, arrived, and completed appointments as blocking; cancelled, no-show, and soft-deleted appointments do not block.
- Requires customer booking/rescheduling to use configured 15-minute starts while staff scheduling keeps exact-minute support.
- Locks scheduling mutations with `appointment_schedule_locks.schedule_date` rows and `FOR UPDATE`.
- Returns HTTP 422 with `code = "SLOT_UNAVAILABLE"` and refresh context for stale booking/reschedule conflicts.
- Keeps availability queries bounded by loading blockers and capacity once per availability request.

## Assumptions

1. The Android app authenticates customers with Laravel Sanctum tokens and will call this endpoint as the signed-in customer.
2. Clinic-local scheduling uses `Asia/Manila` and the deployed `APP_TIMEZONE` remains set accordingly.
3. The configured 15-minute interval is the slot-generation cadence only; visit durations and existing appointments may begin or end off-grid.
4. Availability is a snapshot, never a reservation or guarantee. Booking and rescheduling remain authoritative.
5. The existing provider model remains: eligible optometrists are staff/admin users with `is_optometrist = true`; no new provider table is assumed.
6. Pending, confirmed, and arrived appointments consume capacity. Cancelled and no-show appointments do not. The treatment of completed appointments is proposed below and requires confirmation.
7. Soft-deleted appointments do not consume capacity because normal Eloquent queries exclude them.
8. Walk-ins continue to bypass advance scheduling validation when created, but an existing walk-in consumes capacity when it overlaps a generated slot.
9. This phase may add or update only this version-controlled specification. It does not authorize code, tests, migrations, dependencies, or Android changes.

## Objective and User-Facing Outcome

Give Android patients reliable, pre-confirmation appointment choices after they select a visit reason and date. The app replaces manual hour/minute selection with a grid based on backend availability, and supports loading, empty, and retry states.

The patient should see which clinic-generated start times are selectable before Review & Confirm. If another booking wins a race, the final mutation must reject safely with a stable machine-readable error that tells Android to refresh availability. The backend remains the final authority.

## Existing Backend Behavior

### Existing endpoint

The endpoint already exists inside the authenticated API group:

```http
GET /api/appointments/availability?date=YYYY-MM-DD&visit_reason_id={id}&optometrist_id={id}
Authorization: Bearer {sanctum-token}
Accept: application/json
```

- Route: `routes/api.php`
- Request: `AppointmentAvailabilityRequest`
- Controller: `AppointmentAvailabilityController`
- Slot generation: `ListAvailableAppointmentSlots`
- Authoritative validation: `ScheduleAppointment`
- Tests: `tests/Feature/Api/AppointmentAvailabilityTest.php`

The request currently authorizes customers only. It validates:

- `date`: required, exact `Y-m-d`, today or later.
- `visit_reason_id`: required integer referencing `visit_reasons.id`.
- `optometrist_id`: optional integer referencing `users.id`.

The endpoint currently returns only available slots:

```json
{
  "data": [
    "2026-07-13T09:00:00+08:00",
    "2026-07-13T09:15:00+08:00"
  ]
}
```

Slots are generated every 15 minutes from the configured opening time. A candidate is omitted if the visit duration would exceed closing time or if `ScheduleAppointment` raises any validation exception. On the current `Asia/Manila` deployment, `toIso8601String()` includes `+08:00`; the response does not separately declare the timezone, interval, visit duration, clinic hours, or why a slot was omitted.

### Current scheduling rules

- Configuration: 09:00–17:00, Sunday closed, 15-minute generation interval.
- The proposed interval is `[start, start + requested visit duration)`.
- An existing appointment overlaps when `existing_start < proposed_end` and `existing_end > proposed_start`. This correctly handles durations that are not multiples of 15 minutes and permits touching boundaries.
- Existing duration comes from its visit reason, falling back to 30 minutes in the SQL expression.
- Cancelled and no-show appointments do not block. Pending, confirmed, arrived, and completed appointments do block.
- With an optometrist, only appointments assigned to that optometrist are counted. Unassigned appointments are ignored in this branch.
- Without an optometrist, all overlapping appointments are counted against the number of eligible optometrists, with a minimum capacity of one. Unassigned and assigned appointments both count.
- Same-day elapsed candidates are omitted because `ScheduleAppointment` rejects past starts.
- Booking accepts an optional optometrist and creates a pending appointment.
- Rescheduling uses the same scheduler and excludes the appointment being moved from its own conflict query. The availability endpoint has no equivalent appointment-exclusion parameter today.

### Known gaps and inconsistencies

1. `optometrist_id` validates only that a user exists. A non-optometrist therefore produces a misleading `200` with no slots because provider validation is swallowed during slot generation.
2. Closed days also produce `200` with an empty list and no reason metadata.
3. Availability cannot represent unavailable slots, so Android cannot distinguish conflicts, elapsed time, clinic closure, or duration overflow.
4. Booking and rescheduling return Laravel validation errors but no stable domain error code such as `SLOT_UNAVAILABLE`.
5. Booking validation and insertion are not wrapped in a transaction or serialized lock. Two concurrent requests can both pass the availability check and create overlapping appointments.
6. Rescheduling validates before its update transaction, so it has the same race.
7. The unassigned capacity calculation counts every appointment overlapping the whole proposed range. It does not calculate peak concurrent usage within sub-intervals and may be overly restrictive for longer visits.
8. Provider-specific checks ignore unassigned appointments, which can make selected-provider and clinic-wide results disagree.
9. There is only a single-column `scheduled_at` index. Status/provider/date access paths may need composite-index evaluation using real query plans before rollout.
10. The endpoint is documented only at summary level; its exact JSON and error contract are not documented.

## Proposed API Contract

This is an additive evolution of the existing route rather than a new endpoint.

### Request

```http
GET /api/appointments/availability
    ?date=2026-07-13
    &visit_reason_id=3
    &optometrist_id=8
    &appointment_id=42
```

| Parameter | Required | Rules | Meaning |
|---|---:|---|---|
| `date` | Yes | String in exact `Y-m-d`; clinic-local date; today or later | Date whose candidates are generated. |
| `visit_reason_id` | Yes | Integer; existing visit reason | Supplies requested duration. |
| `optometrist_id` | No | Integer; existing eligible optometrist | Restricts availability to that provider. |
| `appointment_id` | No | Integer; active appointment owned by authenticated customer; status pending or confirmed | Reschedule mode; excludes this appointment from conflicts. Its visit reason and optometrist must match the supplied scheduling context unless the reschedule API is explicitly expanded later. |

Unknown query parameters may be ignored for Laravel compatibility, but clients must not rely on them. Dates are interpreted in the clinic timezone, not the device timezone.

### Successful response

Return all generated start candidates, including unavailable ones, so Android can render a stable grid and clearly disable choices. Candidates that cannot fit before closing are not generated at all. A closed day has an empty `slots` array and a machine-readable day-level state.

```json
{
  "data": {
    "date": "2026-07-13",
    "timezone": "Asia/Manila",
    "interval_minutes": 15,
    "visit_reason_id": 3,
    "visit_duration_minutes": 15,
    "optometrist_id": null,
    "appointment_id": null,
    "day_status": "open",
    "generated_at": "2026-07-13T08:12:04+08:00",
    "slots": [
      {
        "starts_at": "2026-07-13T11:30:00+08:00",
        "ends_at": "2026-07-13T11:45:00+08:00",
        "available": false,
        "reason": "capacity_reached"
      },
      {
        "starts_at": "2026-07-13T11:45:00+08:00",
        "ends_at": "2026-07-13T12:00:00+08:00",
        "available": false,
        "reason": "capacity_reached"
      },
      {
        "starts_at": "2026-07-13T12:00:00+08:00",
        "ends_at": "2026-07-13T12:15:00+08:00",
        "available": true,
        "reason": null
      }
    ]
  }
}
```

Contract details:

- `starts_at`, `ends_at`, and `generated_at` are ISO-8601 strings with an explicit numeric UTC offset.
- `timezone` is the IANA clinic timezone and is authoritative for labels and date grouping.
- `day_status` is one of `open` or `closed` in this scope. Future holiday support must add a backward-compatible value and tests.
- Slot `reason` is `null` when available. Initial unavailable reasons are `elapsed` and `capacity_reached`. These are display hints, not authorization data.
- The response never exposes another patient's details, appointment IDs, or provider schedules.
- Slot order is ascending and slot start values are unique.

### Compatibility decision

Changing `data` from an array of strings to an object is backward-incompatible for any Android build already consuming the endpoint. Before implementation, Android context must establish whether the endpoint is currently consumed in a released build.

If it is consumed, implementation must use one of these approved rollout strategies:

1. Introduce a versioned endpoint/representation (recommended), retaining the legacy response temporarily; or
2. Coordinate a backend-and-Android cutover with an explicitly accepted compatibility break.

Do not silently replace the existing shape.

## Availability Calculation Rules

1. Resolve the clinic-local date, visit reason, optional eligible optometrist, and optional owned appointment-to-exclude before generating candidates.
2. If the weekday is configured closed, return `day_status = closed` and no slots.
3. Generate candidate starts from opening time at `interval_minutes` increments while `candidate_start + visit_duration <= closing_time`.
4. Mark a same-day candidate `elapsed` when its start is not strictly after the backend's current clinic-local time. The backend clock, not the device clock, decides this.
5. Evaluate overlaps with half-open intervals: `existing_start < candidate_end` and `existing_end > candidate_start`.
6. Use each existing appointment's visit-reason duration. Durations need not be divisible by the generation interval.
7. Exclude soft-deleted, cancelled, and no-show appointments. Include pending, confirmed, and arrived appointments. Proposed rule: include completed appointments defensively if their stored interval overlaps, because changing status should not retrospectively create historical availability and future-completed data indicates an anomaly.
8. In reschedule mode, exclude only the authorized `appointment_id` being moved.
9. Apply one shared calculation path to availability, booking, staff booking, calendar rescheduling, and customer rescheduling so preview and enforcement do not drift.
10. Provider/capacity behavior must be finalized from the open questions below. The calculation must use peak concurrent capacity across the candidate interval, not a raw count of every appointment that intersects it.

## Booking and Rescheduling Behavior

- `POST /api/appointments` continues to accept an explicit-offset `scheduled_at` and optional eligible `optometrist_id`.
- `POST /api/appointments/{appointment}/reschedule` continues to authorize ownership, permits pending/confirmed appointments only, and returns customer-initiated reschedules to pending.
- Both mutations parse the instant, normalize it to clinic time for rule evaluation, and require it to match a valid generation boundary unless an explicit off-grid staff-only exception is retained.
- Rescheduling excludes the appointment itself from the conflict calculation but not any other appointment.
- A prior availability response creates no hold. Both mutations recalculate inside their authoritative transaction.
- On stale availability, no appointment is created or moved. Android retains the form state, shows a friendly conflict message, refetches the same availability context, and asks the patient to choose again.

## Timezone Handling

- Clinic timezone: `Asia/Manila` from `APP_TIMEZONE`; deployment must fail configuration verification if it differs unintentionally.
- Availability request `date` is a clinic-local calendar date.
- Availability timestamps always carry `+08:00` while the clinic remains in Manila and also include `timezone: Asia/Manila`.
- Booking/reschedule timestamps must contain an explicit offset. Offsetless timestamps should be rejected after a compatibility review rather than guessed from the Android device.
- Database persistence may continue using the established application convention, but comparisons and API serialization must be deterministic and covered by timezone tests.

## Validation and Error Contracts

### Query validation

Laravel validation failures remain HTTP `422` with the conventional `message` and `errors` object. Add a stable top-level `code` for domain-aware clients where relevant.

Examples:

- `INVALID_DATE`
- `VISIT_REASON_NOT_FOUND`
- `OPTOMETRIST_NOT_ELIGIBLE`
- `APPOINTMENT_NOT_RESCHEDULABLE`

Unauthorized requests return `401`. A customer requesting another customer's reschedule context should return `404` to avoid disclosing its existence. Authenticated non-customer roles remain `403` unless product requirements later authorize them.

### Stale availability on mutation

Use HTTP `422` to preserve the existing Laravel/client validation path, with an additive stable code:

```json
{
  "message": "This time slot is no longer available. Please choose another time.",
  "code": "SLOT_UNAVAILABLE",
  "errors": {
    "scheduled_at": [
      "This time slot is no longer available. Please choose another time."
    ]
  },
  "availability": {
    "date": "2026-07-13",
    "visit_reason_id": 3,
    "optometrist_id": null,
    "appointment_id": null
  }
}
```

The `availability` object contains safe request parameters for a refresh, not a trusted URL and not a replacement slot list. Android refetches the endpoint.

## Concurrency and Transactional Guarantees

1. Availability reads are snapshots and do not lock or reserve slots.
2. Booking and rescheduling must perform conflict validation and persistence in one database transaction.
3. Concurrent scheduling mutations for the same capacity scope and clinic date must be serialized with a database-backed locking strategy. Merely using a transaction without a shared lock target is insufficient when no conflicting appointment row exists yet.
4. After acquiring the lock, the mutation reruns all time, provider, status, and overlap validation before writing.
5. The loser of a booking race receives `422` + `SLOT_UNAVAILABLE`; it must not create notifications, SMS records, or audit entries.
6. Deadlock/lock retry behavior must be bounded and tested. Infrastructure failures remain server errors and must not be mislabeled as slot conflicts.

The exact lock mechanism belongs in Phase 2 because it may require a schema change; any migration is Ask First.

## Authentication and Authorization

- Require `auth:sanctum` and the existing API throttle.
- Availability is customer-only in the initial contract.
- `appointment_id` is usable only when owned by the authenticated customer and eligible for customer rescheduling.
- `optometrist_id` must resolve through the `optometrists()` eligibility scope, not merely `users.id` existence.
- Responses reveal capacity states only, never identities or appointment details.
- Staff/admin panel scheduling continues through its existing server-side actions; exposing this REST endpoint to staff is outside this scope.

## Query Performance and Indexing

- Generate candidates in memory, but fetch relevant blocking appointments for the clinic-local date/range in a bounded query rather than issuing one full conflict query per slot.
- Eager-load or join visit durations and statuses once; avoid N+1 queries.
- Preserve the sargable `scheduled_at` range as the primary narrowing condition. The computed existing end time may still require per-row duration evaluation.
- Before adding indexes, capture `EXPLAIN` for provider-specific and clinic-capacity queries against representative data.
- Evaluate a composite appointment index beginning with scheduling scope and time (for example provider/time) plus the cost of status filtering. Do not add speculative indexes without measured query plans.
- Keep response work bounded by configured clinic hours and interval; for the current configuration there are at most 33 raw starts before duration filtering.

## Testing Strategy

Use Pest feature tests and focused action tests. Phase 4 must add coverage for:

- Authentication, customer-only authorization, ownership hiding, and throttling compatibility.
- Exact request validation, including malformed dates, past dates, missing/deleted visit reasons, ineligible providers, and invalid reschedule contexts.
- Exact success schema, ISO-8601 offsets, IANA timezone, ordering, uniqueness, interval, duration, and generation timestamp.
- Open day, Sunday closure, same-day elapsed slots, opening/closing boundaries, and a visit ending exactly at closing.
- Available and unavailable slot states.
- Overlaps at start, middle, and end; touching boundaries; 15-, 20-, 30-, and non-grid durations.
- Every appointment status and soft-deleted appointments.
- Assigned and unassigned appointments under zero, one, and multiple eligible-provider configurations.
- Peak concurrency across a long candidate interval.
- Reschedule self-exclusion, ownership, eligible statuses, unchanged slots, and conflicts with another appointment.
- Booking/rescheduling parity with availability calculations.
- Two concurrent booking attempts: exactly one succeeds and one receives `SLOT_UNAVAILABLE`.
- No side effects from the losing mutation.
- Legacy endpoint compatibility or versioned-contract behavior, depending on the rollout decision.
- Query-count regression and representative `EXPLAIN` review for the availability query.

Required project commands during implementation:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentAvailabilityTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentBookingTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentRescheduleTest.php
vendor/bin/sail bin pint --dirty --format agent
```

## Compatibility and Rollout

1. Confirm whether any released Android build consumes the current string-array response.
2. If yes, use an API-versioned or additive transition rather than replacing `data` in place.
3. Ship backend support before Android switches from its manual picker.
4. Android must retain handling for `401`, `403/404`, `422`, network failure, empty/closed days, and retry.
5. During rollout, final booking remains compatible with existing clients, while newer clients understand `SLOT_UNAVAILABLE` and refresh metadata.
6. Observe availability request latency, validation failures by code, and booking race frequency without logging patient details.
7. No caching is initially required. If caching is later introduced, invalidation and maximum staleness require a separate approved design.

## Project Structure and Code Style

Expected implementation locations, subject to Phase 2 approval:

- `routes/api.php` — authenticated route contract.
- `app/Http/Requests/Api/` — availability and mutation validation/authorization.
- `app/Http/Controllers/Api/AppointmentAvailabilityController.php` — HTTP representation only.
- `app/Actions/Appointments/` — shared availability and authoritative scheduling rules.
- `tests/Feature/Api/` — API behavior and contract tests.
- `config/appointments.php` — clinic scheduling configuration.
- `database/migrations/` — only if an approved locking/index strategy requires it.

Implementation must follow existing Laravel conventions: explicit parameter and return types, action classes for domain behavior, Form Requests for authorization/validation, Eloquent scopes for eligible providers, curly braces for control structures, Pest tests, and Pint formatting. Controllers must not duplicate overlap logic.

## Boundaries

### Always

- Keep booking/rescheduling authoritative and transactional.
- Use one shared overlap/capacity rule for preview and mutation paths.
- Return explicit-offset timestamps and the clinic timezone.
- Validate provider eligibility and reschedule ownership.
- Preserve patient privacy in availability responses.
- Write focused Pest coverage and run Pint for implementation changes.
- Update this specification first if approved behavior changes.

### Ask First

- Any database migration, new table, or index.
- Any dependency addition.
- A breaking response-shape change or API versioning strategy.
- Changing which statuses consume capacity.
- Changing assigned/unassigned provider semantics.
- Enforcing 15-minute alignment on existing staff workflows.
- Adding slot holds, caching, holidays, provider working hours, or per-provider schedules.
- Exposing availability to unauthenticated callers or staff roles.

### Never

- Treat an availability response as a reservation or guaranteed slot.
- Trust Android/device time as the authority.
- Expose patient, appointment, or provider schedule details in a slot response.
- Permit booking/rescheduling to bypass the final conflict check.
- Resolve races only in application memory or with process-local locks.
- Swallow invalid-provider validation and return a misleading empty success.
- Hardcode clinic hours independently in controller or Android logic.
- Delete tests, edit vendor code, or silently break an existing mobile contract.

## Specific, Testable Success Criteria

1. An authenticated customer can request availability for a valid clinic-local date and visit reason and receives the documented deterministic schema.
2. Every timestamp contains an explicit offset; the response identifies `Asia/Manila`, 15-minute generation interval, and visit duration.
3. The response represents all generated candidates with `available` state; Android does not need to infer conflicts.
4. A 20-minute existing appointment at 11:30 makes a 15-minute candidate at 11:45 unavailable because `[11:45, 12:00)` overlaps `[11:30, 11:50)`.
5. Candidates ending after clinic close are absent; a candidate ending exactly at close is eligible.
6. Same-day elapsed candidates are unavailable, based on backend clinic time.
7. Cancelled, no-show, and soft-deleted appointments do not consume capacity; approved blocking statuses do.
8. A customer reschedule availability request excludes only that customer's eligible appointment from conflicts.
9. Invalid or ineligible optometrist IDs return a validation error, not an empty success.
10. Availability and final booking/rescheduling return the same decision for an unchanged database snapshot.
11. In a two-request race for the last unit of capacity, exactly one mutation succeeds and the other returns `422` with `code = SLOT_UNAVAILABLE` and refresh context.
12. The losing race creates no appointment update, notification, SMS, or audit side effect.
13. The endpoint performs a bounded number of database queries independent of the number of generated slots.
14. Existing Android clients retain functionality through the explicitly approved compatibility strategy.
15. All focused API tests pass and modified PHP is Pint-formatted before implementation is considered complete.

## Ambiguities and Missing Business Rules

The current code does not establish these product decisions:

1. Whether Android currently lets patients select a specific optometrist or always books into any-provider capacity.
2. Whether an unassigned appointment should reserve generic clinic capacity, block every provider, or later be allocated to a specific provider.
3. Whether a selected provider can be shown available while generic/unassigned appointments have consumed some clinic capacity.
4. Whether future appointments erroneously marked completed should still block, or completed should always be non-blocking.
5. Whether arrived appointments should block for their scheduled duration only or until explicitly completed.
6. Whether customers must select only 15-minute-grid starts at final submission, and whether staff may retain exact/off-grid scheduling.
7. Whether provider count alone represents clinic capacity or whether rooms/equipment impose a lower clinic-wide limit.
8. Whether holidays, lunch breaks, provider leave, and provider-specific working hours are intentionally out of scope.
9. Whether an existing released Android build consumes the current response.
10. Whether stale availability should stay on HTTP `422` for compatibility or move to `409 Conflict` in a versioned API.

## Focused Clarification Questions

1. Does the Android booking flow allow patients to choose an optometrist, or should availability always mean “any eligible optometrist”?
2. When an appointment has no assigned optometrist, should it consume one shared unit of clinic capacity (recommended), and may any still-free provider accept another appointment?
3. Should completed appointments be non-blocking in all cases, or should anomalous future-completed appointments continue blocking defensively (recommended)?
4. Should the API enforce 15-minute start alignment for Android booking/rescheduling while preserving exact-minute staff scheduling (recommended)?
5. Are holidays, lunch breaks, provider leave, rooms, and equipment explicitly outside this first availability release?
6. Is the existing availability endpoint consumed by any released Android build? If yes, please share the relevant API DTO/service and error-parsing code before Phase 2.
7. For stale availability, should we preserve the existing `422` validation flow (recommended for compatibility) or define `409 Conflict` in a versioned contract?

## Open Questions

Phase 1 was approved with the documented recommendations used as planning defaults. Android consumption of the existing response remains unresolved and is carried into Phase 2 as a compatibility gate.

---

# Phase 2: Technical Implementation Plan

## Planning Basis

Phase 1 was approved on 2026-07-13. Unless corrected before Phase 3, the implementation plan uses these defaults:

- `docs/ANDROID_CONTEXT.md` confirms that Android currently uses a manual 15-minute picker, does not list the availability endpoint among consumed endpoints, and has no provider-selection step.
- Android therefore requests any-eligible-provider availability. The optional provider filter remains supported by the backend for future use.
- An unassigned appointment consumes one shared clinic-capacity unit. Another appointment may use any genuinely free provider while total capacity remains.
- Pending, confirmed, arrived, and anomalous future-completed appointments block their stored scheduled intervals. Cancelled, no-show, and soft-deleted appointments do not.
- Android booking and customer rescheduling require starts aligned to the configured 15-minute grid. Existing staff workflows retain exact-minute scheduling.
- Holidays, lunch breaks, provider leave, provider-specific hours, rooms, equipment, caching, and temporary slot holds are outside this release.
- Stale availability uses the existing HTTP `422` validation path with the additive `SLOT_UNAVAILABLE` code.
- The existing availability response is not documented as consumed by the Android app, so the endpoint may evolve in place under a coordinated backend-first rollout.

## Architecture Overview

The implementation will separate three concerns while keeping a single scheduling policy:

```text
HTTP availability request
        │
        ▼
Validated scheduling context ──► availability snapshot/query ──► slot representation
        │                                      │
        │                                      ▼
        └────────────────────────────► shared scheduling decision
                                               ▲
                                               │
Booking/reschedule mutation ──► date lock ──► authoritative recheck ──► persistence + side effects
```

The availability endpoint obtains a read-only snapshot. Booking and rescheduling build the same scheduling context but acquire a clinic-date database lock, rerun the shared decision inside a transaction, persist, and only then produce side effects. Controllers remain responsible for HTTP translation rather than overlap logic.

## Major Components and Dependencies

### 1. Scheduling context and validation

Create one validated domain input containing:

- Clinic-local date or start instant.
- Visit reason and duration.
- Optional eligible optometrist.
- Optional appointment being rescheduled.
- Caller mode: customer grid-constrained or staff exact-minute.

Form Requests validate HTTP shape, authentication, ownership, and resource eligibility. Domain actions still validate invariants so Filament and API callers cannot bypass them.

This foundation is required before changing either availability output or mutations.

### 2. Shared overlap and capacity evaluator

Refactor the existing per-candidate exception loop into a shared evaluator that returns a decision instead of using exceptions as ordinary control flow. Exceptions remain appropriate at mutation boundaries.

For each candidate interval:

1. Reject clinic closure, elapsed start, duration overflow, or customer off-grid start.
2. Load relevant blocking appointments for the bounded day/range with status, visit duration, and provider assignment.
3. Exclude the authorized appointment in reschedule mode.
4. Build event boundaries from candidate start/end and overlapping appointment start/end values.
5. Evaluate every resulting sub-interval using half-open interval semantics.
6. Without a selected provider, the candidate is available only when adding it would not make peak concurrent appointments exceed eligible-provider capacity, falling back to one.
7. With a selected provider, the candidate is unavailable if that provider is assigned another overlapping appointment or if adding the candidate would exceed total clinic capacity. Unassigned appointments consume generic capacity but do not automatically block every provider.

This replaces raw “number of appointments intersecting the whole candidate” behavior and correctly handles long candidates spanning separate non-concurrent appointments.

### 3. Availability representation

The controller maps evaluator results into the approved metadata object and ordered slot objects. It must not query per candidate or expose appointment data.

Closed dates return `day_status = closed` with an empty slot list. On open same-day dates, elapsed generated candidates remain in the grid with `available = false` and `reason = elapsed`. Candidates that cannot finish before closing are not generated.

Use a dedicated response mapper/resource only if it matches existing API conventions without adding unnecessary abstraction. Exact JSON contract tests are written before changing the controller.

### 4. Compatibility boundary

`docs/ANDROID_CONTEXT.md` establishes the current client boundary:

- Booking and rescheduling currently construct explicit-offset timestamps from locally selected date/time values.
- The booking UI uses a manual time picker rather than backend availability.
- The availability endpoint is absent from the documented consumed endpoint list.
- Android already parses `422` error bodies and maps DTOs to domain models at the repository boundary.

Therefore, evolve `GET /api/appointments/availability` in place, add the new Kotlin DTO/domain/repository path before replacing the picker, and keep booking/rescheduling routes stable with additive error fields. If source inspection later reveals an undocumented consumer before implementation, stop and restore the versioned-route branch rather than silently breaking it.

### 5. Transactional mutation boundary

Move scheduling validation for create and reschedule inside their persistence transactions. Both use the same evaluator as availability.

Use a small database-backed clinic-date lock row:

- A schedule-lock table has one unique clinic-local date per row.
- The mutation inserts the date row if absent, then selects it `FOR UPDATE` within the transaction.
- All appointment-creating and rescheduling paths that use scheduled capacity acquire the same date lock before validation.
- Date-wide locking is intentionally coarser than provider locking: clinic traffic is low, correctness is easier to prove, and any-provider/unassigned capacity spans providers.
- Moving across dates locks both dates in chronological order if the old date also needs serialization, preventing deadlock cycles. The authoritative target-date recheck is mandatory.

This requires a migration and is therefore explicitly subject to approval with this Phase 2 plan. MySQL named locks are not preferred because they are connection-scoped, easier to leak across error paths, and less portable in tests.

### 6. Structured mutation errors

Translate an authoritative conflict into the documented `422` response with:

- `code = SLOT_UNAVAILABLE`.
- Conventional `errors.scheduled_at` content.
- Safe availability refresh parameters.

Other validation errors retain distinct codes where approved. Failed mutations roll back before notifications, SMS, or audit records are created. Existing successful response resources remain unchanged.

### 7. Query and index evaluation

Initially query relevant appointments once per availability request, bounded by the clinic date/range. Reuse the in-memory set for every generated candidate.

During implementation:

- Capture query counts in tests.
- Run `EXPLAIN` for provider-specific and any-provider queries using representative rows.
- Evaluate a composite provider/time index only after observing the plan.
- Keep the existing `scheduled_at` index unless evidence supports an additive composite index.

Any performance index beyond the required schedule-lock table remains a separate Ask First decision.

## Implementation Order

1. **Freeze the exact contract.** Use the approved in-place endpoint evolution based on the Android context, and confirm the exact success/stale-error fixtures before PHP changes.
2. **Characterize current behavior with tests.** Add failing contract and domain cases for status handling, non-grid durations, long-interval peak capacity, selected/unassigned providers, reschedule exclusion, and timezone output.
3. **Introduce the shared evaluator.** Make availability and existing scheduler consume one decision model while preserving mutation behavior.
4. **Upgrade the availability response.** Add metadata, all generated states, exact validation, and reschedule context under the approved compatibility boundary.
5. **Add the database scheduling lock.** Introduce the date-lock schema and transactional lock action, then prove mutual exclusion independently.
6. **Harden booking.** Acquire the lock, re-evaluate, create the appointment, and return structured stale errors without losing existing successful behavior.
7. **Harden rescheduling.** Use the same transaction/lock/evaluator path, preserve self-exclusion and lifecycle rules, and keep side effects atomic.
8. **Verify other scheduling callers.** Ensure Filament create, reschedule actions, and calendar rescheduling continue using authoritative shared rules; exact-minute staff behavior remains intact.
9. **Measure and finalize queries.** Verify bounded query count, review `EXPLAIN`, and request approval only if an additional performance index is justified.
10. **Run focused and regression verification.** Execute appointment API, scheduling, Filament appointment/calendar tests, Pint, and route inspection; update backend context only for the approved final contract.

This is an ordered plan, not the Phase 3 task list. Phase 3 will divide these steps into focused tasks of no more than approximately five files each, with acceptance criteria and commands.

## Dependency Graph

```text
Approved in-place contract
        │
        ▼
Contract tests ──► shared evaluator ──► availability representation
                         │
                         ▼
                date-lock migration/action
                         │
                  ┌──────┴──────┐
                  ▼             ▼
             booking path   reschedule path
                  └──────┬──────┘
                         ▼
              caller regression checks
                         ▼
               query-plan verification
```

## Sequential and Parallel Work

Must be sequential:

- Exact contract fixtures before response changes.
- Contract/domain tests before evaluator changes.
- Shared evaluator before endpoint and mutation adoption.
- Lock schema before concurrency-safe mutations.
- Booking/reschedule hardening before full regression verification.

May proceed independently after the shared contract is frozen:

- Android DTO/UI work and backend evaluator work.
- Availability representation tests and lock-mechanism tests.
- Booking and rescheduling adoption after the shared lock/evaluator APIs stabilize, provided overlapping files are coordinated.

No sub-agent or parallel implementation is required for this repository-sized change; the ordering above primarily supports clean, reviewable increments.

## Verification Checkpoints

### Checkpoint A: Contract frozen

- Android consumption is known.
- In-place endpoint evolution is recorded.
- Exact success and stale-error JSON are approved.
- Provider and status defaults are confirmed or explicitly accepted.

### Checkpoint B: Decision parity

- Shared evaluator tests cover all approved rules.
- Existing availability, booking, and rescheduling decisions remain consistent for unchanged data.
- No controller contains duplicate overlap logic.

### Checkpoint C: Availability API

- Exact schema, authentication, authorization, timezone, slot states, and reschedule context tests pass.
- Query count is bounded independently of slot count.
- Legacy compatibility behavior passes where required.

### Checkpoint D: Concurrency-safe mutations

- Two requests competing for the final capacity unit cannot both succeed.
- The losing request returns `SLOT_UNAVAILABLE`.
- Rollback prevents losing-request side effects.
- Booking and rescheduling tests pass.

### Checkpoint E: Complete backend verification

- API and action tests pass.
- Filament appointment and calendar scheduling regressions pass.
- Route inspection matches the approved contract.
- Pint reports clean formatting.
- Query plans have been reviewed; no speculative index is added.
- `BACKEND_CONTEXT.md` reflects shipped behavior.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Undocumented Android code consumes string-array `data` | Medium | Context shows no consumption; verify source when available and stop for versioning if contradictory code is found. |
| Transaction used without a shared lock row still races | High | Date-wide database row lock, authoritative recheck after lock, concurrency test. |
| Some scheduling caller bypasses the lock | High | Inventory every create/reschedule entry point and add regression coverage for API and Filament callers. |
| Capacity semantics drift between preview and mutation | High | One evaluator and shared scheduling context for both paths. |
| Raw overlap count rejects valid long appointments | Medium | Evaluate peak concurrency at interval event boundaries. |
| Unassigned appointments make provider results misleading | Medium | Count them against generic total capacity while separately enforcing selected-provider conflicts. |
| Coarse date locking reduces throughput | Low for current clinic | Keep simple date scope initially; measure lock wait before considering provider-granular locks. |
| Status or visit reason changes between preview and booking | Medium | Availability remains advisory; authoritative transaction reads current data. |
| Additional lock table adds operational complexity | Low | One minimal unique-date table, bounded growth, standard migration/rollback tests. |
| `DATE_ADD` duration expression limits index use | Medium | Fetch a bounded day range once; inspect `EXPLAIN`; add an index only with evidence. |
| Staff exact-minute schedules create off-grid conflicts | Low | Evaluator considers all exact intervals even though Android candidates are grid-aligned. |

## Phase 2 Approval Decisions

Approval of this plan authorizes Phase 3 task decomposition using these decisions:

1. Add a minimal database schedule-lock table for date-wide transactional serialization.
2. Keep HTTP `422` and add `SLOT_UNAVAILABLE` plus refresh context.
3. Use peak concurrent provider capacity, with unassigned appointments consuming generic capacity.
4. Keep completed appointments blocking defensively and arrived appointments blocking only their scheduled interval.
5. Enforce grid alignment for customer API mutations, not staff scheduling.
6. Keep schedule exceptions and slot holds out of scope.
7. Evolve the existing availability endpoint in place; introduce versioning only if later source inspection contradicts `ANDROID_CONTEXT.md`.

## Remaining Approval Needed Before Phase 3

Android compatibility context has been supplied and the plan now selects in-place endpoint evolution. No additional Android information is required for backend task decomposition.

No implementation tasks or application changes should begin until this Phase 2 plan is explicitly approved, including the minimal schedule-lock table migration.

---

# Phase 3: Implementation Tasks

## Task Breakdown Notes

- Phase 2, including the minimal schedule-lock migration, was approved on 2026-07-13.
- Tasks are ordered by dependency and each is limited to approximately five files.
- Phase 4 will execute one task at a time using tests first.
- Android UI implementation is a separate repository workstream. This backend phase delivers the contract and fixtures Android needs to replace its manual picker.
- Refined scope is approximately 14–18 backend files including focused tests, the migration, and documentation. The earlier 8–12 estimate covered the primary runtime files but did not fully count separate regression tests and the concurrency migration.

## Task 1: Lock the availability contract with failing API tests

**Description:** Expand the existing availability feature tests first so the approved in-place JSON contract, authorization, timezone metadata, slot states, and reschedule context are executable requirements before production code changes.

**Acceptance criteria:**

- [ ] Tests describe the exact metadata and slot-object schema, including explicit offsets, ordered unique starts, duration, interval, day status, and generation time.
- [ ] Tests cover customer-only access, invalid/ineligible optometrists, closed days, same-day elapsed states, and an owned pending/confirmed `appointment_id`.
- [ ] Tests prove another customer's appointment is hidden and an ineligible reschedule context is rejected.

**Verification:**

- [ ] Confirm the new tests fail for the expected missing contract behavior: `vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentAvailabilityTest.php`.
- [ ] Run `git diff --check`.

**Dependencies:** None.

**Files likely touched:**

- `tests/Feature/Api/AppointmentAvailabilityTest.php`

**Estimated scope:** Small — 1 file.

## Task 2: Introduce the shared availability decision model

**Description:** Refactor scheduling calculations into one reusable evaluator and decision representation. It will handle exact half-open overlaps, status filtering, visit durations, elapsed/closed/capacity reasons, selected providers, unassigned generic capacity, and reschedule self-exclusion.

**Acceptance criteria:**

- [ ] Evaluator tests cover touching boundaries, 20-minute overlaps, long candidates spanning separate appointments, all statuses, soft deletes, zero/one/multiple providers, and assigned/unassigned combinations.
- [ ] Peak concurrent usage replaces raw intersecting-row counts, and selected-provider conflicts also respect total clinic capacity.
- [ ] `ScheduleAppointment` and slot generation consume the same evaluator without duplicating overlap rules.

**Verification:**

- [ ] Run evaluator and scheduling tests: `vendor/bin/sail artisan test --compact tests/Feature/AppointmentSchedulingTest.php`.
- [ ] Run availability tests and confirm calculation cases pass even if response-shape cases remain red: `vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentAvailabilityTest.php`.

**Dependencies:** Task 1.

**Files likely touched:**

- `app/Actions/Appointments/EvaluateAppointmentAvailability.php` (new)
- `app/Actions/Appointments/AppointmentAvailabilityDecision.php` (new)
- `app/Actions/Appointments/ScheduleAppointment.php`
- `app/Actions/Appointments/ListAvailableAppointmentSlots.php`
- `tests/Feature/AppointmentSchedulingTest.php`

**Estimated scope:** Medium — 5 files.

## Checkpoint 1: Scheduling rules

- [ ] Shared evaluator tests pass.
- [ ] Existing booking and rescheduling tests have no unexplained regressions.
- [ ] One source of truth decides preview and mutation availability.
- [ ] No database schema has changed yet.

## Task 3: Deliver the enriched availability endpoint

**Description:** Update request validation and controller serialization to return all generated candidates using the approved in-place schema, including optional reschedule self-exclusion.

**Acceptance criteria:**

- [ ] `optometrist_id` resolves only eligible optometrists and no longer turns invalid users into empty successes.
- [ ] `appointment_id` is customer-owned, pending/confirmed, and consistent with the scheduling context before it can be excluded.
- [ ] Open and closed dates return the exact approved response, with no patient or schedule details leaked.

**Verification:**

- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentAvailabilityTest.php`.
- [ ] Inspect the registered route: `vendor/bin/sail artisan route:list --path=appointments/availability --except-vendor`.

**Dependencies:** Task 2.

**Files likely touched:**

- `app/Http/Requests/Api/AppointmentAvailabilityRequest.php`
- `app/Http/Controllers/Api/AppointmentAvailabilityController.php`
- `tests/Feature/Api/AppointmentAvailabilityTest.php`

**Estimated scope:** Medium — 3 files.

## Task 4: Add the clinic-date transaction lock

**Description:** Add the approved minimal schedule-lock table and a small action that initializes and locks a clinic-local date row inside a caller-owned transaction. Prove deterministic acquisition ordering for multi-date operations.

**Acceptance criteria:**

- [ ] Migration creates a unique clinic-date lock target and rolls back cleanly.
- [ ] Lock acquisition requires an active transaction and uses a row-level write lock after safely initializing the date row.
- [ ] Tests prove same-date callers share a lock target and multiple dates are ordered consistently.

**Verification:**

- [ ] Run the focused lock test: `vendor/bin/sail artisan test --compact tests/Feature/AppointmentScheduleLockTest.php`.
- [ ] Run migration status inspection: `vendor/bin/sail artisan migrate:status`.

**Dependencies:** Task 2.

**Files likely touched:**

- `database/migrations/*_create_appointment_schedule_locks_table.php` (new)
- `app/Actions/Appointments/LockAppointmentScheduleDate.php` (new)
- `tests/Feature/AppointmentScheduleLockTest.php` (new)

**Estimated scope:** Medium — 3 files.

## Task 5: Make customer booking transactional and race-safe

**Description:** Move customer scheduled-appointment creation behind one application action that starts a transaction, locks the target clinic date, reruns the shared evaluator, persists only on success, and returns a stable stale-slot error.

**Acceptance criteria:**

- [ ] Customer booking enforces future explicit-offset, 15-minute-aligned starts and eligible optional providers.
- [ ] Competing requests for the final capacity unit cannot both persist; the loser receives `422`, `SLOT_UNAVAILABLE`, and safe refresh context.
- [ ] The losing request creates no appointment, notification, SMS, or audit side effect; the successful response remains backward-compatible.

**Verification:**

- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentBookingTest.php`.
- [ ] Run the focused race test/filter defined in that file using `vendor/bin/sail artisan test --compact --filter=concurrent`.

**Dependencies:** Tasks 2 and 4.

**Files likely touched:**

- `app/Actions/Appointments/CreateScheduledAppointment.php` (new)
- `app/Http/Controllers/Api/AppointmentController.php`
- `app/Http/Requests/Api/StoreAppointmentRequest.php`
- `tests/Feature/Api/AppointmentBookingTest.php`

**Estimated scope:** Medium — 4 files.

## Task 6: Make rescheduling transactional and race-safe

**Description:** Move the authoritative reschedule validation inside the locked transaction, preserving ownership, lifecycle transitions, self-exclusion, notifications, SMS, and audit behavior.

**Acceptance criteria:**

- [ ] Customer rescheduling enforces explicit-offset, grid-aligned starts and returns structured stale-slot errors with its own `appointment_id` refresh context.
- [ ] Staff rescheduling retains exact-minute support while using the same lock and evaluator.
- [ ] A failed or racing reschedule leaves the original appointment and all side effects unchanged.

**Verification:**

- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentRescheduleTest.php`.
- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/AuditLogRecordingTest.php`.

**Dependencies:** Tasks 2 and 4.

**Files likely touched:**

- `app/Actions/Appointments/RescheduleAppointment.php`
- `app/Http/Requests/Api/RescheduleAppointmentRequest.php`
- `tests/Feature/Api/AppointmentRescheduleTest.php`
- `tests/Feature/AuditLogRecordingTest.php`

**Estimated scope:** Medium — 4 files.

## Checkpoint 2: Authoritative mutations

- [ ] Availability, booking, and rescheduling agree for an unchanged database snapshot.
- [ ] Booking and rescheduling races cannot overbook capacity.
- [ ] Stale failures use the documented `422` structure and have no side effects.
- [ ] Customer grid alignment and staff exact-minute behavior both pass.

## Task 7: Route staff creation through the locked scheduler

**Description:** Adopt the shared scheduled-creation action in the Filament create page and verify that table/calendar rescheduling already reaches the hardened `RescheduleAppointment` path. Remove redundant prevalidation only where the authoritative action makes it unnecessary without degrading user feedback.

**Acceptance criteria:**

- [ ] Filament scheduled creation acquires the same date lock and uses the same evaluator as customer booking.
- [ ] Table and calendar rescheduling reach the locked reschedule action; stale conflicts remain friendly Filament notifications.
- [ ] Existing separate date/time form behavior and exact-minute staff scheduling remain unchanged.

**Verification:**

- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php`.
- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Filament/CalendarInteractivityTest.php`.

**Dependencies:** Tasks 5 and 6.

**Files likely touched:**

- `app/Filament/Resources/Appointments/Pages/CreateAppointment.php`
- `app/Filament/Resources/Appointments/Widgets/AppointmentCalendarWidget.php` (only if redundant prevalidation must change)
- `tests/Feature/Filament/AppointmentResourceTest.php`
- `tests/Feature/Filament/CalendarInteractivityTest.php`

**Estimated scope:** Medium — 3–4 files.

## Task 8: Verify bounded queries and database access paths

**Description:** Prove that slot generation loads blocking appointments in a bounded query rather than querying once per candidate, then inspect MySQL plans before considering any additional index.

**Acceptance criteria:**

- [ ] A query-count regression test is independent of the number of generated slots.
- [ ] Provider-specific and any-provider availability queries are reviewed with representative `EXPLAIN` output.
- [ ] No additional index is added unless the plan demonstrates a concrete regression and receives separate approval.

**Verification:**

- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentAvailabilityTest.php`.
- [ ] Use Laravel Boost `database-query` for read-only `EXPLAIN` inspection during implementation.

**Dependencies:** Tasks 3, 5, and 6.

**Files likely touched:**

- `app/Actions/Appointments/EvaluateAppointmentAvailability.php`
- `tests/Feature/Api/AppointmentAvailabilityTest.php`

**Estimated scope:** Small — 2 files.

## Task 9: Finalize documentation and backend regression verification

**Description:** Update living backend documentation to the shipped contract, run focused appointment suites and formatter, and record any intentionally deferred Android work without changing Android code in this repository.

**Acceptance criteria:**

- [ ] `BACKEND_CONTEXT.md` documents exact availability parameters, response metadata, blocking statuses, provider capacity, reschedule exclusion, and `SLOT_UNAVAILABLE`.
- [ ] This specification reflects any approved implementation-time decision changes and marks completed success criteria accurately.
- [ ] Focused API, scheduling, and Filament regression suites pass with clean formatting.

**Verification:**

- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentAvailabilityTest.php tests/Feature/Api/AppointmentBookingTest.php tests/Feature/Api/AppointmentRescheduleTest.php tests/Feature/AppointmentSchedulingTest.php tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Filament/CalendarInteractivityTest.php`.
- [ ] Run `vendor/bin/sail bin pint --dirty --format agent`.
- [ ] Run `git diff --check` and `vendor/bin/sail artisan route:list --path=appointments --except-vendor`.

**Dependencies:** Tasks 1–8.

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`
- `docs/specs/appointment-availability-api-spec.md`

**Estimated scope:** Small — 2 files.

## Checkpoint 3: Ready for Android integration

- [ ] All backend success criteria are demonstrated by tests.
- [ ] Final booking and rescheduling remain authoritative under concurrency.
- [ ] The API contract is documented with stable fixtures for Kotlinx Serialization DTOs.
- [ ] Android can replace its manual picker without reproducing clinic-capacity logic locally.
- [ ] No Phase 4 implementation begins without explicit approval of this task breakdown.

## Phase 4 Execution Order

Execute Tasks 1 through 9 in order, pausing at each checkpoint if the shared contract, schema, or concurrency strategy must change. Any material change updates this specification and returns to review before dependent tasks continue.
