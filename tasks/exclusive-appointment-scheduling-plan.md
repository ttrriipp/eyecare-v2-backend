# Implementation Plan: Exclusive Appointment Scheduling

**Status:** Implemented on 2026-09-17; full-suite pre-merge run remains pending.
**Checklist:** `tasks/exclusive-appointment-scheduling-todo.md`
**Supersedes:** The multi-optometrist clinic-capacity rule in
`tasks/remove-provider-hours-plan.md`.

## Outcome

Allow at most one scheduled appointment at a time across the clinic.

The rule is interval-based, not start-time-only:

```text
existing start < candidate end
AND
existing end > candidate start
```

Therefore, a 10:00–10:30 appointment blocks 10:00–10:30 and 10:15–10:45,
while a back-to-back 10:30 appointment remains valid.

The 15-minute start grid remains because it gives patients and staff
predictable appointment choices. What disappears is multi-optometrist capacity:
adding more optometrists no longer permits simultaneous appointments.

## Current-State Findings

- Availability currently treats active, present optometrists as parallel
  capacity. Two optometrists can therefore support two overlapping bookings.
- Both patient availability endpoints now use this shared capacity engine.
- Staff creation, request acceptance, rescheduling, and request preference
  updates already converge on the shared evaluator.
- Appointment writes use a unique `appointment_schedule_locks.schedule_date`
  row and `lockForUpdate()` to serialize writes for a clinic date.
- `appointments.scheduled_at` already has a normal index.
- The current database has no active overlapping appointments and no duplicate
  active start timestamps, so no appointment cleanup is required.

## Decisions

### One clinic, one scheduled interval

Any overlapping active appointment makes the candidate interval unavailable,
regardless of whether either appointment is assigned and regardless of whether
different optometrists are selected.

Blocking appointment statuses are `scheduled` and `checked_in`. Cancelled and
no-show appointments do not block. Fulfilled appointments are historical and
do not block future availability.

### Retain provider validity, not provider capacity

- An assigned optometrist must still be active, have optometrist capability,
  and not have an overlapping dated absence.
- An unassigned candidate requires at least one active optometrist who is not
  absent for the interval.
- Provider count is boolean availability only; it never multiplies the number
  of simultaneous bookings.

### Preserve walk-in semantics

Walk-ins remain immediate queue arrivals and bypass scheduled-slot validation.
The exclusive rule applies to scheduled appointments and accepted booking
requests, not to the number of patients physically waiting in the clinic.

### Keep the public API compatible

- Keep both availability endpoints, their `slots` arrays, the 15-minute
  `interval_minutes`, response fields, status codes, and request parameters.
- Keep pending appointment requests non-binding.
- Keep the existing public `capacity_reached` reason value for unavailable
  intervals to avoid an Android contract break, but remove capacity language
  from staff-facing labels and canonical explanations.
- Continue returning `SLOT_UNAVAILABLE` when submission loses a race.

### Do not add a unique appointment timestamp constraint

A `UNIQUE(scheduled_at)` index is insufficient because it does not catch
partial overlaps and cannot naturally ignore cancelled/no-show rows in MySQL.
Interval exclusion remains application-enforced under the existing per-date
database lock. No schema migration is required.

## Dependency Graph

```text
Approve exclusive interval rule
             |
             v
Characterize overlap and boundary behavior
             |
             v
Simplify shared evaluator to binary occupancy
             |
      +------+------+
      |             |
      v             v
Slot APIs       All mutation paths
      |             |
      +------+------+
             v
Remove capacity UI and obsolete tests
             |
             v
Concurrency, docs, and full verification
```

## Phase 0: Lock the Contract with Tests

### Task 1: Characterize exclusive interval behavior

**Description:** Replace tests that intentionally allow parallel bookings with
tests for clinic-wide interval exclusivity.

**Acceptance criteria:**

- [ ] A candidate with the same start and duration as an active appointment is
  unavailable even when a different optometrist is selected.
- [ ] Partial overlap from either side is unavailable.
- [ ] A candidate fully containing or contained by an active appointment is
  unavailable.
- [ ] Exactly adjacent intervals are available.
- [ ] Cancelled and no-show appointments do not block.
- [ ] Rescheduling can ignore the appointment being moved.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/AppointmentSchedulingTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments/SchedulingCharacterizationTest.php
```

**Dependencies:** Plan approval.

**Files likely touched:**

- `tests/Feature/AppointmentSchedulingTest.php`
- `tests/Feature/Appointments/SchedulingCharacterizationTest.php`
- `tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php`

**Estimated scope:** Medium.

## Phase 1: Simplify the Shared Availability Engine

### Task 2: Replace numeric clinic capacity with binary occupancy

**Description:** Simplify `EvaluateAppointmentAvailability` so any overlapping
blocking appointment rejects the candidate. Retain clinic hours, future/grid
checks, provider validity, provider absences, and ignored-appointment support.

**Acceptance criteria:**

- [ ] Appointment overlap no longer depends on optometrist count or identity.
- [ ] Assigned-provider absence and eligibility checks still run.
- [ ] An unassigned appointment is available only when at least one eligible
  optometrist exists, but additional optometrists add no capacity.
- [ ] `eligibleOptometristCapacity()`, `clinicCapacityForInterval()`, capacity
  segments, and numeric capacity parameters are removed or reduced to a clearly
  named boolean provider-presence check.
- [ ] Blocking queries use only the canonical scheduled/checked-in statuses.

**Verification:** Task 1 tests pass, plus:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php
```

**Dependencies:** Task 1.

**Files likely touched:**

- `app/Actions/Appointments/EvaluateAppointmentAvailability.php`
- `tests/Feature/AppointmentSchedulingTest.php`
- `tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php`

**Estimated scope:** Medium.

### Task 3: Simplify slot generation

**Description:** Remove interval capacity calculation from
`ListAvailableAppointmentSlots`. Load the day's blocking appointments once and
mark every overlapping candidate unavailable through the shared evaluator.
Retain a bounded provider-presence/absence query if needed for unassigned
appointments.

**Acceptance criteria:**

- [ ] One blocking appointment makes every overlapping generated slot
  unavailable for both API endpoints.
- [ ] Back-to-back slots remain available.
- [ ] Slot listing uses a bounded query count independent of slot count.
- [ ] Pending requests remain non-binding.
- [ ] Linked rebooking still excludes the appointment being moved.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SubmitAppointmentRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentRequestRebookingReviewTest.php
```

**Dependencies:** Task 2.

**Files likely touched:**

- `app/Actions/Appointments/ListAvailableAppointmentSlots.php`
- `app/Actions/Appointments/ListAppointmentRequestAvailabilitySlots.php`
- `tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`
- `tests/Feature/Appointments/AppointmentRequestRebookingReviewTest.php`

**Estimated scope:** Medium.

### Checkpoint: One availability rule

- [ ] Both availability endpoints agree for identical date/duration context.
- [ ] Different optometrists cannot produce overlapping scheduled bookings.
- [ ] Provider absence and zero-provider cases remain safe.
- [ ] No per-slot database query regression exists.

## Phase 2: Protect Every Write Path

### Task 4: Verify creation, acceptance, and rescheduling under the date lock

**Description:** Ensure every path that creates or moves a scheduled
appointment acquires the clinic-date lock and rechecks exclusive availability
inside the same transaction immediately before persistence.

**Acceptance criteria:**

- [ ] Direct staff creation and `CreateScheduledAppointment` lock and recheck.
- [ ] New request acceptance locks and rechecks.
- [ ] Staff and patient rebooking lock old/new dates in stable order and
  recheck while ignoring only the moved appointment.
- [ ] Calendar drag/drop commits through the same rescheduling action.
- [ ] Request preference submission/update remains advisory and does not
  reserve the interval.
- [ ] Two requests targeting the same interval may remain pending, but only the
  first accepted request can create an appointment.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/AppointmentScheduleLockTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentRequestRebookingReviewTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SubmitAppointmentRequestTest.php
```

**Dependencies:** Task 3.

**Files likely touched:**

- `app/Actions/Appointments/CreateScheduledAppointment.php`
- `app/Actions/Appointments/AcceptAppointmentRequest.php`
- `app/Actions/Appointments/RescheduleAppointment.php`
- `tests/Feature/AppointmentScheduleLockTest.php`
- `tests/Feature/Appointments/ReviewAppointmentRequestTest.php`

**Estimated scope:** Medium.

## Phase 3: Remove Capacity Presentation and Stale Coverage

### Task 5: Replace clinic-capacity wording in Filament

**Description:** Make staff scheduling surfaces display simple interval
availability rather than provider-count capacity.

**Acceptance criteria:**

- [ ] Request review shows `Time available` for an open interval.
- [ ] An overlap is labelled `Unavailable — another appointment overlaps` or
  equivalent plain language.
- [ ] `X of Y clinic slots available`, `clinic capacity`, and provider-capacity
  help text are absent.
- [ ] Selecting an assigned provider still reports provider absence or
  ineligibility clearly.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/AppointmentRequestScheduleReviewTest.php
vendor/bin/sail artisan test --compact tests/Feature/Filament/ViewAppointmentRequestTest.php
```

**Dependencies:** Task 4.

**Files likely touched:**

- `app/Filament/Resources/AppointmentRequests/Pages/ReviewAppointmentRequestSchedule.php`
- `resources/views/filament/resources/appointment-requests/pages/review-appointment-request-schedule.blade.php`
- `tests/Feature/AppointmentRequestScheduleReviewTest.php`
- `tests/Feature/Filament/ViewAppointmentRequestTest.php`

**Estimated scope:** Medium.

### Task 6: Remove obsolete capacity tests and terminology

**Description:** Rewrite tests that assert simultaneous bookings or numeric
provider capacity. Delete only cases whose behavior is intentionally removed;
retain clinic-hours, provider-presence, provider-absence, grid, collision,
status, and concurrency coverage.

**Acceptance criteria:**

- [ ] No test expects different optometrists to overlap.
- [ ] No test creates multiple appointments merely to exhaust provider count.
- [ ] Provider-absence tests assert availability safety, not numeric booking
  capacity.
- [ ] Comments and test names describe exclusive interval scheduling.

**Verification:**

```bash
rg -n "clinic capacity|clinic slots|capacityForInterval|eligibleOptometristCapacity|clinicCapacityForInterval|different optometrists can" app tests
vendor/bin/sail artisan test --compact tests/Feature/AppointmentSchedulingTest.php tests/Feature/Appointments tests/Feature/AppointmentRequestScheduleReviewTest.php
```

**Dependencies:** Tasks 2 and 5.

**Files likely touched:**

- `tests/Feature/AppointmentSchedulingTest.php`
- `tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php`
- `tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`
- `tests/Feature/AppointmentRequestScheduleReviewTest.php`

**Estimated scope:** Medium.

### Checkpoint: Behavior and UI complete

- [ ] Patient listing, submission, staff acceptance, direct creation, and
  rescheduling all enforce the same exclusive interval.
- [ ] Staff UI contains no numeric clinic-capacity presentation.
- [ ] Walk-ins still follow their existing immediate-queue workflow.
- [ ] Public API fields and coded errors remain compatible.

## Phase 4: Canonical Documentation and Final Verification

### Task 7: Document and verify exclusive scheduling

**Description:** Update canonical documentation, run a stale-reference scan,
format changed PHP, execute focused suites, then execute the full suite and
review the concurrency boundary.

**Acceptance criteria:**

- [ ] `docs/BACKEND_CONTEXT.md` states that only one scheduled appointment may
  occupy an interval clinic-wide.
- [ ] `docs/API_CONTRACT.md` explains that an active appointment blocks every
  overlapping candidate while pending requests remain non-binding.
- [ ] Historical specs remain unchanged.
- [ ] No current code or canonical documentation describes optometrist count as
  simultaneous appointment capacity.
- [ ] Pint and the full Pest suite pass.

**Verification:**

```bash
rg -n "clinic capacity|provider capacity|clinic slots available|eligibleOptometristCapacity|clinicCapacityForInterval" app tests docs/API_CONTRACT.md docs/BACKEND_CONTEXT.md PRODUCT.md
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail artisan test --compact
```

**Dependencies:** Behavior/UI checkpoint.

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`
- `docs/API_CONTRACT.md`
- `PRODUCT.md` if it describes parallel appointment capacity

**Estimated scope:** Small.

## Rollout and Rollback

This is an application-only behavior change with no destructive data or schema
migration. It can ship in one release after focused and full verification.

Before deployment, rerun the overlap audit in the target database. If active
overlaps exist there, do not delete appointments automatically. Review and
reschedule them with clinic staff before enabling the rule. The current
environment has no such conflicts.

Rollback is an ordinary application rollback because the database shape and
existing appointments remain unchanged.

## Final Success Criteria

- At most one scheduled/checked-in appointment occupies any clinic interval.
- Different providers never permit simultaneous scheduled bookings.
- Back-to-back appointments remain valid.
- Cancelled and no-show appointments release their interval.
- Patient APIs, staff actions, and request acceptance agree.
- Concurrent acceptance or creation cannot double-book a clinic date.
- The 15-minute selection grid remains, but numeric clinic capacity is gone.
