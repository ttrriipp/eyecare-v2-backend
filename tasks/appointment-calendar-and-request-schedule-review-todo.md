# Appointment Calendar and Request Schedule Review Checklist

**Spec:** `docs/specs/appointment-calendar-and-request-schedule-review-spec.md`
**Plan:** `tasks/appointment-calendar-and-request-schedule-review-plan.md`
**Status:** Proposed; implementation not started

## Phase 1: Protect Existing Contracts

- [ ] Task 1: Characterize calendar and request-acceptance boundaries
  - Acceptance: Event scope, preference order, linked-pending access, contact
    note rules, and atomic acceptance have focused tests.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentCalendarTest.php tests/Feature/Filament/AppointmentRequestScheduleReviewTest.php tests/Feature/Appointments/ReviewAppointmentRequestTest.php`
  - Files: Three focused test files.

- [ ] Task 2: Restore the general Calendar as a resource view
  - Acceptance: Appointments exposes List and Calendar header controls with one
    unchanged sidebar item; the calendar page renders Month/Week/Day views.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentCalendarTest.php tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/AdminNavigationStructureTest.php`
  - Files: Appointment resource, List page, Calendar page, focused test, and
    stale view only if confirmed unused.

### Checkpoint 1

- [ ] General Calendar is reachable inside Appointments.
- [ ] Appointment table behavior is unchanged.
- [ ] Focused Calendar and navigation tests pass.

## Phase 2: Request Availability Decisions

- [ ] Task 3: Add a reusable schedule-review availability projection
  - Acceptance: Ordered preferences are evaluated with current duration and
    optional provider using existing availability reason codes.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Appointments/EvaluateAppointmentRequestPreferencesTest.php tests/Feature/Appointments/SchedulingCharacterizationTest.php`
  - Files: Evaluation action, focused test, and decision value object only if
    required.

- [ ] Task 4: Harden request authorization and terminal transitions
  - Acceptance: Supported panel roles are authorized; expired requests and
    stale accept/reject races cannot mutate; acceptance rechecks the patient
    link and required active provider under lock.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRequestTest.php tests/Feature/Appointments/LinkAppointmentRequestToPatientTest.php tests/Feature/Filament/AppointmentRequestResourceTest.php`
  - Files: Request policy, three request actions, and focused domain test.

- [ ] Task 5: Create the Review & Schedule resource page foundation
  - Acceptance: Linked pending requests can enter; ineligible requests cannot;
    scheduling fields, required provider, preference states, and visible field
    errors follow current rules.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRequestScheduleReviewTest.php tests/Feature/Filament/ViewAppointmentRequestTest.php`
  - Files: Request resource, view page, review page, Blade view, and focused test.

### Checkpoint 2

- [ ] Preferred times show explicit live availability states.
- [ ] Resource access and form state are tested.
- [ ] No new appointment mutation exists yet.

## Phase 3: Schedule Context and Selection

- [ ] Task 6: Add the request schedule calendar
  - Acceptance: Existing blocking appointments and the proposed selection are
    distinct; pending requests are not occupied events; empty-slot clicks update
    the owning page.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRequestScheduleReviewTest.php tests/Feature/Filament/AppointmentCalendarTest.php`
  - Files: Request calendar widget, review page/view, and focused test.

- [ ] Task 7: Complete atomic acceptance and conflict recovery
  - Acceptance: Acceptance delegates to the locked action, non-preferred times
    require notes, and stale-slot conflicts refresh safely.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRequestScheduleReviewTest.php tests/Feature/Appointments/ReviewAppointmentRequestTest.php`
  - Files: Review page/view and focused Filament/domain tests.

### Checkpoint 3

- [ ] Staff can review, select, and accept without leaving schedule context.
- [ ] Concurrent conflicts do not double-book.
- [ ] Focused Filament and domain tests pass.

## Phase 4: Calendar Safety and Polish

- [ ] Task 8: Repair or disable drag-rescheduling before exposure
  - Acceptance: The calendar cannot reschedule without the required reason and
    cannot persist an invalid or stale move.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentCalendarTest.php tests/Feature/Appointments/RescheduleAppointmentTest.php`
  - Files: Appointment calendar widget and focused tests.

- [ ] Task 9: Responsive, accessibility, and final verification
  - Acceptance: Layout, keyboard access, dark mode, explicit status text,
    focused tests, build, and formatting all pass.
  - Verify: Focused suites from the plan, `vendor/bin/sail npm run build`, and
    `vendor/bin/sail bin pint --dirty --format agent`.
  - Files: Existing review view/theme/tests and backend context after shipping.

### Checkpoint 4

- [ ] All specification success criteria are met.
- [ ] No schema, API, dependency, or new sidebar item is introduced.
- [ ] Focused tests, asset build, Pint, and browser checks pass.
- [ ] `docs/BACKEND_CONTEXT.md` is updated only after implementation ships.

## Planning Gate

- [ ] The user has reviewed the specification.
- [ ] The user has approved the architecture decisions.
- [ ] The user has approved implementation to begin.
