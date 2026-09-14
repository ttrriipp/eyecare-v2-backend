# Implementation Plan: Remove Individual Optometrist Hours

**Status:** Proposed on 2026-09-15; awaiting approval.
**Checklist:** `tasks/remove-provider-hours-todo.md`

## Outcome

Remove recurring per-optometrist weekly hours and make appointment capacity use
one rule everywhere:

> During an open clinic interval, capacity is the number of active
> optometrists who are not absent for that interval, less concurrent confirmed
> appointments.

Clinic hours remain the weekly source of truth. Date-specific clinic closures,
early closings, full-day optometrist absences, and partial optometrist absences
remain. Appointment assignment and same-optometrist collision prevention also
remain.

## Why This Is Safe for the Current Data

The planning audit found:

- 14 `provider_hours` rows across two active optometrists;
- all 14 rows use the default `09:00`–`17:00` range;
- no disabled provider days;
- no customized provider times;
- no `provider_hours.updated` audit entries; and
- two existing appointments with an assigned optometrist.

There is therefore no unique provider schedule to translate. Dropping
`provider_hours` expands each active optometrist to clinic hours and does not
invalidate appointments. Existing appointments and schedule overrides are not
historical provider-hour data and must not be deleted.

## Decisions

### Keep

- `clinic_hours` and clinic-wide schedule calculation;
- `schedule_overrides`, including partial and full-day provider absences;
- active optometrist role/capability checks;
- optional staff assignment of an optometrist to an appointment;
- same-provider overlap prevention;
- concurrent clinic-capacity enforcement;
- both public availability endpoint shapes; and
- the response-only `optometrist_id: null` compatibility field until a
  separately coordinated API version removes it.

### Remove

- the `provider_hours` table and its 14 current rows;
- `ProviderHour`, its factory, relation, seeder, and update action;
- the Filament Optometrist Hours page;
- provider-hour impact evaluation and audit-event creation;
- automatic creation of seven provider-hour rows by optometrist factories;
- provider-hour-only tests; and
- the separate schedule-block availability implementation if its final
  reference disappears during consolidation.

### Explicit non-goals

- Do not remove optometrist accounts, appointment assignments, appointments,
  clinic hours, or schedule overrides.
- Do not add shifts, split working hours, lunch breaks, or another recurring
  provider-calendar model.
- Do not add patient preferred-provider selection.
- Do not change appointment-request holds: pending requests remain non-binding.
- Do not rewrite historical specifications; update only canonical current-state
  documentation.

## Existing Problem to Correct During Removal

There are currently two slot engines:

1. `ListAvailableAppointmentSlots` uses optometrist capacity.
2. `ListAppointmentRequestAvailabilitySlots` marks a slot unavailable when any
   confirmed appointment overlaps, effectively treating clinic capacity as one.

This difference is unrelated to the number of provider-hour rows and would
remain confusing after their deletion. Both availability endpoints, new
request validation, request preference updates, and linked-rebooking validation
must use the same interval-based evaluator.

The shared generator must calculate capacity for each candidate interval. It
must not precompute capacity once for the full clinic day because a partial
absence can affect only some slots. Load the day's active optometrists,
provider absences, and blocking appointments once, then evaluate candidate
intervals in memory or through an equivalent bounded-query context.

## Dependency Graph

```text
Approve simplified availability rule
                 |
                 v
Add characterization tests for the shared rule
                 |
                 v
Simplify interval eligibility and capacity
                 |
                 v
Consolidate all slot consumers on one generator
                 |
          +------+------+
          |             |
          v             v
Remove provider UI  Remove model/factory/seeder
          |             |
          +------+------+
                 v
       Zero-reference checkpoint
                 |
                 v
 Drop provider_hours + feature audit history
                 |
                 v
 Canonical docs and full verification
```

## Phase 0: Characterize the Replacement Contract

### Task 1: Encode the simplified availability rule

**Description:** Rewrite the provider availability characterization around
active optometrists, interval absences, concurrent appointments, and assigned
provider conflicts before removing implementation code.

**Acceptance criteria:**

- [ ] An open clinic interval with two active, present optometrists has total
  capacity two without any `ProviderHour` fixture.
- [ ] A deactivated or non-optometrist account contributes no capacity.
- [ ] A full-day absence removes that optometrist for the date.
- [ ] A partial absence removes that optometrist only from overlapping slots.
- [ ] One assigned appointment consumes one unit of clinic capacity; the same
  assigned optometrist cannot overlap another appointment.
- [ ] Zero active/present optometrists yields `capacity_reached`.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments/SchedulingCharacterizationTest.php
```

**Dependencies:** Plan approval.

**Files likely touched:**

- `tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php`
- `tests/Feature/Appointments/SchedulingCharacterizationTest.php`
- `tests/Feature/Appointments/ProviderAvailabilityTest.php`

**Estimated scope:** Small.

## Phase 1: One Availability Engine

### Task 2: Remove provider hours from interval eligibility

**Description:** Make the evaluator derive eligibility from an active
optometrist account and non-overlapping provider absences. Preserve clinic-hour,
grid, appointment status, capacity-segment, and assigned-provider checks.

**Acceptance criteria:**

- [ ] `EvaluateAppointmentAvailability` has no `ProviderHour` import or query.
- [ ] `eligibleOptometristCapacity()` counts only active optometrist-capable
  accounts and applies full/partial absences to the exact interval.
- [ ] `isOptometristEligible()` rejects inactive accounts and overlapping
  absences without requiring a recurring schedule row.
- [ ] Existing clinic closure, early close, elapsed, grid, and collision reason
  codes remain stable.

**Verification:** Task 1 tests pass, plus:

```bash
vendor/bin/sail artisan test --compact tests/Feature/AppointmentSchedulingTest.php
```

**Dependencies:** Task 1.

**Files likely touched:**

- `app/Actions/Appointments/EvaluateAppointmentAvailability.php`
- `tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php`
- `tests/Feature/Appointments/SchedulingCharacterizationTest.php`

**Estimated scope:** Medium.

### Task 3: Make capacity interval-aware without query multiplication

**Description:** Update the slot generator so every candidate uses its exact
start/end interval while reusing day-scoped optometrist, absence, and confirmed
appointment data.

**Acceptance criteria:**

- [ ] A partial absence changes only overlapping returned slots.
- [ ] Capacity is not computed once from opening time through closing time.
- [ ] Slot generation performs a bounded number of queries independent of the
  number of generated slots.
- [ ] Pending appointment requests still do not consume capacity.
- [ ] Linked rebooking still excludes the appointment being moved.

**Verification:** Add assertions for morning/during/after a partial absence and
query count, then run:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SubmitAppointmentRequestTest.php
```

**Dependencies:** Task 2.

**Files likely touched:**

- `app/Actions/Appointments/ListAvailableAppointmentSlots.php`
- `app/Actions/Appointments/EvaluateAppointmentAvailability.php`
- `tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php`
- `tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`

**Estimated scope:** Medium.

### Task 4: Route every availability consumer through the shared generator

**Description:** Replace the capacity-one appointment-request path with the
shared interval-capacity generator. Preserve the two HTTP response schemas and
the request/rebooking exclusion rules.

**Acceptance criteria:**

- [ ] Both availability endpoints return the same availability decision for
  the same date, duration, and exclusion context.
- [ ] Creating or updating request preferences validates against the same rule
  used to list slots.
- [ ] Two simultaneous confirmed appointments are allowed only when two active,
  present optometrists provide capacity.
- [ ] `optometrist_id` remains absent from accepted query parameters and remains
  `null` in the existing linked-availability response for compatibility.
- [ ] `ListAppointmentRequestAvailabilitySlots` is either removed or retained
  only as a thin adapter with no independent availability logic.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SubmitAppointmentRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentRequestRebookingReviewTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments/UpdateAppointmentRequestScheduleTest.php
```

**Dependencies:** Task 3.

**Files likely touched:**

- `app/Actions/Appointments/ListAppointmentRequestAvailabilitySlots.php`
- `app/Http/Controllers/Api/AppointmentRequestAvailabilityController.php`
- `app/Actions/Appointments/SubmitAppointmentRequest.php`
- `app/Actions/Appointments/UpdateAppointmentRequestSchedule.php`
- `tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`

**Estimated scope:** Medium.

### Checkpoint: Shared rule

- [ ] Both endpoints and both mutation validators agree.
- [ ] Full-day and partial absences behave per interval.
- [ ] Assigned optometrist collisions remain protected.
- [ ] Slot generation has no per-slot database queries.

## Phase 2: Remove Provider-Hour Surfaces and Domain Code

### Task 5: Remove the Filament Optometrist Hours workflow

**Description:** Delete the recurring-hours page and update the remaining
Availability cluster so Clinic Hours and Schedule Overrides form a continuous,
correctly worded navigation sequence.

**Acceptance criteria:**

- [ ] No navigation item or direct Filament page exposes Optometrist Hours.
- [ ] Clinic Hours remains admin-managed.
- [ ] Optometrists can still manage their own absence overrides; admins can
  still manage clinic overrides and any provider absence.
- [ ] Schedule Overrides copy describes exceptions to clinic hours, not
  provider weekly hours.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/Availability
vendor/bin/sail artisan test --compact tests/Feature/Filament/AdminNavigationStructureTest.php
```

**Dependencies:** Task 4.

**Files likely touched:**

- `app/Filament/Clusters/Availability/Pages/OptometristHours.php`
- `app/Filament/Clusters/Availability/Pages/ScheduleOverrides.php`
- `tests/Feature/Filament/Availability/OptometristHoursPageTest.php`
- `tests/Feature/Filament/Availability/ScheduleOverridesPageTest.php`
- `tests/Feature/Filament/AdminNavigationStructureTest.php`

**Estimated scope:** Small.

### Task 6: Remove provider-hour application artifacts

**Description:** Delete the write action and change-impact branch, then remove
the audit event after no producer remains.

**Acceptance criteria:**

- [ ] `UpdateProviderHours` and `providerHourChange()` are gone.
- [ ] No runtime code can create or update a provider-hour row.
- [ ] `provider_hours.updated` is not emitted by current code.
- [ ] Clinic-hour and provider-absence impact previews still work.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Appointments/AvailabilityChangeImpactTest.php
```

**Dependencies:** Task 5.

**Files likely touched:**

- `app/Actions/Appointments/UpdateProviderHours.php`
- `app/Actions/Appointments/EvaluateAvailabilityChangeImpact.php`
- `app/Enums/AuditEvent.php`
- `tests/Feature/Appointments/UpdateProviderHoursTest.php`
- `tests/Feature/Appointments/AvailabilityChangeImpactTest.php`

**Estimated scope:** Small.

### Task 7: Remove the model, factory hooks, and seed path

**Description:** Delete the provider-hour model/factory/seeder and remove the
User relationship plus automatic seven-row creation from optometrist factory
states.

**Acceptance criteria:**

- [ ] Creating an optometrist fixture creates only the requested account/role
  records, not seven schedule rows.
- [ ] `User` has no `providerHours()` relationship.
- [ ] `DatabaseSeeder` does not invoke `ProviderHoursSeeder`.
- [ ] Fresh seed data still contains active optometrists and clinic hours.

**Verification:** Run affected user, seeder, and appointment tests:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Appointments tests/Feature/Filament/Availability
```

**Dependencies:** Tasks 5 and 6.

**Files likely touched:**

- `app/Models/ProviderHour.php`
- `app/Models/User.php`
- `database/factories/ProviderHourFactory.php`
- `database/factories/UserFactory.php`
- `database/seeders/ProviderHoursSeeder.php`

**Follow-up file:** Remove the seeder entry from
`database/seeders/DatabaseSeeder.php` in the same slice or a tiny follow-up.

**Estimated scope:** Small.

### Task 8: Delete dead schedule-block machinery and obsolete tests

**Description:** After slot consolidation, remove `BuildScheduleBlocks`,
`ScheduleBlock`, and their isolated test file if a reference scan confirms that
they have no non-test consumers. Retain or rewrite tests that protect current
clinic capacity and request behavior.

**Acceptance criteria:**

- [ ] No production reference remains to either dead class.
- [ ] Provider-hour-only test files are deleted with the removed feature.
- [ ] Mixed files retain active optometrist, absence, capacity, and API
  contract coverage.
- [ ] Test deletion is limited to behavior that no longer exists or duplicate
  coverage replaced by the shared-engine tests.

**Verification:**

```bash
rg -n "BuildScheduleBlocks|ScheduleBlock|ProviderHour|providerHours" app database routes tests
vendor/bin/sail artisan test --compact tests/Feature/Appointments tests/Feature/Api/V1/SubmitAppointmentRequestTest.php
```

**Dependencies:** Tasks 4 and 7.

**Files likely touched:**

- `app/Actions/Appointments/BuildScheduleBlocks.php`
- `app/Actions/Appointments/ScheduleBlock.php`
- `tests/Feature/Appointments/ScheduleBlockAvailabilityTest.php`
- `tests/Feature/Appointments/ProviderAvailabilityTest.php`
- `tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php`

**Estimated scope:** Small.

### Checkpoint: Consumers removed

- [ ] Runtime, factories, seeders, Filament, and tests contain no provider-hour
  reference.
- [ ] Only the original create migration and the pending drop migration mention
  `provider_hours`.
- [ ] Focused availability, request, scheduling, override, and navigation tests
  pass.

## Phase 3: Contract the Database

### Task 9: Drop provider-hours storage

**Description:** Add a new reversible contract migration. Its `up()` removes
only provider-hour audit rows (`action = provider_hours.updated` or the matching
provider-hour subject type) and drops `provider_hours`. Its `down()` recreates
the original table shape and constraints but cannot restore discarded rows.

This contraction starts only after the zero-reference checkpoint. Do not edit
the historical create migration because fresh installs need a consistent
migration chain.

**Acceptance criteria:**

- [ ] Migration preflight records/counts the rows it will discard.
- [ ] Only feature-specific current rows and audit history are removed.
- [ ] Appointments, users, roles, clinic hours, and schedule overrides are
  unchanged.
- [ ] `migrate`, rollback, and re-migrate succeed on a disposable test database.
- [ ] Fresh migration and seeding succeed without `provider_hours` at the end.

**Verification:**

```bash
vendor/bin/sail artisan migrate --no-interaction
vendor/bin/sail artisan migrate:rollback --step=1 --no-interaction
vendor/bin/sail artisan migrate --no-interaction
vendor/bin/sail artisan test --compact
```

Use only a disposable test database for any `migrate:fresh` verification.

**Dependencies:** Consumer-removal checkpoint and explicit approval to execute
the destructive migration.

**Files likely touched:**

- `database/migrations/<timestamp>_drop_provider_hours_table.php`
- `tests/Feature/Database/ProviderHoursRemovalMigrationTest.php`

**Estimated scope:** Small.

## Phase 4: Documentation and Final Verification

### Task 10: Reconcile canonical documentation and verify the removal

**Description:** Update only current-state documentation, then run a full
reference scan, formatter, focused suites, full suite, and a multi-axis review.

**Acceptance criteria:**

- [ ] `docs/BACKEND_CONTEXT.md` describes clinic-hours-plus-absence capacity,
  lists no provider-hours table/page/action, and preserves optometrist
  assignment semantics.
- [ ] `docs/API_CONTRACT.md` states that both availability endpoints use active
  optometrist capacity and date-specific absences without changing response
  shape.
- [ ] Historical specs remain intact as historical records.
- [ ] No active code, route, view, seeder, test, or canonical documentation
  references recurring provider hours.
- [ ] Pint and the full Pest suite pass.

**Verification:**

```bash
rg -n "ProviderHour|provider_hours|providerHours|provider hours|Optometrist Hours" app bootstrap config database resources routes tests docs/API_CONTRACT.md docs/BACKEND_CONTEXT.md PRODUCT.md
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail artisan test --compact
```

**Dependencies:** Task 9.

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`
- `docs/API_CONTRACT.md`
- `PRODUCT.md` if its current-state description needs correction

**Estimated scope:** Small.

## Rollout and Rollback

Use two releases:

1. **Consumer release:** Tasks 1–8 switch behavior and remove UI/domain
   consumers while leaving the unused table in place. Rollback is an ordinary
   application rollback.
2. **Contract release:** Task 9 drops the table after the consumer release is
   verified. Rollback recreates an empty table; the old rows are intentionally
   not restored because the current data has no custom schedule information.

Before the contract release, verify in the target environment that customized
or disabled provider-hour rows still equal zero. If not, pause and review those
exceptions with the clinic before deletion; do not silently widen availability.

## Final Success Criteria

- Staff configure one weekly schedule: Clinic Hours.
- Staff use Schedule Overrides for dated optometrist absences.
- Every active optometrist contributes capacity throughout clinic hours unless
  absent for the candidate interval.
- Both patient availability endpoints and request validators agree.
- Assigned optometrists cannot be double-booked.
- No recurring provider-hour UI, code, seed data, tests, or table remains.
- The simplified system is fully covered with fewer, behavior-focused tests.
