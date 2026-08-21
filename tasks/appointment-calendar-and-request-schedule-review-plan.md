# Implementation Plan: Appointment Calendar and Request Schedule Review

**Specification:** `docs/specs/appointment-calendar-and-request-schedule-review-spec.md`
**Status:** In progress; approved by user on 2026-08-21
**Prepared:** 2026-08-21

## Overview

Add an Appointment Calendar view and a record-scoped Review & Schedule page for
appointment requests. The implementation reuses the existing Guava calendar,
availability evaluator, and atomic request-acceptance action. It does not add a
sidebar item, dependency, schema change, or public API change.

## Current-State Findings

- `guava/calendar` is already installed and included in the Filament theme.
- `Appointment` already implements the package's `Eventable` contract.
- `AppointmentCalendarWidget` already supports Month, Week, Day, event quick
  view, click-to-create, and drag-to-reschedule.
- The current `ListAppointments` page no longer mounts that widget.
- The unused `view-toggle.blade.php` refers to `showCalendar` state that the
  current page no longer owns.
- The current Accept Request modal shows preference badges and scheduling
  fields, but not live availability or surrounding appointments.
- `AcceptAppointmentRequest` already performs the required locked, transactional
  recheck and creates the Appointment.
- The current UI and action check only `status === pending`, so an expired
  request may remain actionable unless expiry is rechecked.
- The living backend context requires a provider during request acceptance,
  while the current action still permits `null`; this must be reconciled before
  exposing the new workflow.
- Appointment Requests do not currently have an explicit policy, and Reject
  does not lock/recheck the row before writing a terminal state.
- Existing calendar drag-rescheduling is not ready to expose: it does not supply
  the reason category now required by `RescheduleAppointment`.

## Architecture Decisions

### 1. Resource pages, not new navigation

- Add a custom Calendar page to `AppointmentResource`.
- Add a custom Review & Schedule page to `AppointmentRequestResource` using the
  existing record route.
- Use header actions to move between List and Calendar.
- Use **Review & Schedule** as the request entry point.

This keeps URLs bookmarkable and authorization/resource hydration idiomatic
without overloading a large action modal with nested Livewire state.

### 2. Separate calendar responsibilities

- The general appointment calendar remains an appointment-management widget.
- The request schedule calendar is read-only for existing events and emits only
  a proposed date/time selection to its owning Review & Schedule page.
- Both use `Appointment::toCalendarEvent()` for persisted appointments.

This avoids coupling request-form state to the general calendar's edit and
reschedule actions.

### 3. Parent page owns scheduling form state

The Review & Schedule resource page owns appointment type, duration,
optometrist, selected date/time, referring source, and contact note. Preference
cards and the calendar update that one state owner. Livewire events coordinate
calendar focus/selection only; business state is not duplicated in JavaScript.

### 4. Advisory availability, authoritative acceptance

- `EvaluateAppointmentAvailability` produces live preference and selected-slot
  decisions.
- The page may cache results only within the current Livewire request.
- `AcceptAppointmentRequest` remains the sole mutation path and rechecks under
  the schedule-date lock.

### 5. Explicit authorization and terminal-state safety

- Add an Appointment Request policy for the supported panel roles.
- Treat expiry as an ineligible pending state in the UI and actions.
- Recheck the request, patient link, provider activity/eligibility, and slot
  inside the locked transaction.
- Lock and recheck competing terminal transitions so reject cannot overwrite an
  accepted request.

### 6. Safe initial calendar interaction

Do not expose the existing broken drag-reschedule path. Either keep drag disabled
in the first working Calendar slice or repair it in its own tested task with the
same reason-category contract as the table action.

### 7. Accessibility and responsive behavior

- Use semantic Filament fields/actions and native buttons.
- Pair icons/text with color-coded availability and status.
- Use one column on small screens and request/calendar columns on larger screens.
- Preserve visible focus states and meaningful empty/loading/conflict states.

## Dependency Graph

```text
Characterization tests
    -> General Appointment Calendar route and view
    -> Schedule-review availability projection
        -> Request authorization and transition hardening
            -> Review & Schedule page state/form
                -> Request calendar selection bridge
                    -> Atomic acceptance and conflict refresh
                        -> Optional safe drag-reschedule
                            -> Browser/build/final verification
```

## Phase 1: Protect Existing Contracts

### Task 1: Characterize calendar and request-acceptance boundaries

Add focused failing/characterization tests for the intended event scope,
preference ordering, linked-pending access, non-preferred contact notes, and
atomic acceptance delegation before changing UI behavior.

**Acceptance criteria:**

- Calendar event scope distinguishes capacity-blocking from terminal records.
- Primary and alternative preferences remain ordered.
- Only linked pending requests can enter the scheduling mutation flow.

**Files likely touched:**

- `tests/Feature/Filament/AppointmentCalendarTest.php`
- `tests/Feature/Filament/AppointmentRequestScheduleReviewTest.php`
- `tests/Feature/Appointments/ReviewAppointmentRequestTest.php`

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentCalendarTest.php tests/Feature/Filament/AppointmentRequestScheduleReviewTest.php tests/Feature/Appointments/ReviewAppointmentRequestTest.php`

**Dependencies:** None
**Estimated scope:** Medium (3 files)

### Task 2: Restore the general Calendar as a resource view

Create the Appointment Calendar resource page, mount the existing widget, and
add List/Calendar header navigation without adding a sidebar item. Remove or
replace the stale view toggle only after the new route is covered.

**Acceptance criteria:**

- Existing Appointments navigation still has one sidebar item.
- List and Calendar header controls resolve to their respective resource pages.
- The calendar page renders the widget and Month/Week/Day controls.

**Files likely touched:**

- `app/Filament/Resources/Appointments/AppointmentResource.php`
- `app/Filament/Resources/Appointments/Pages/ListAppointments.php`
- `app/Filament/Resources/Appointments/Pages/CalendarAppointments.php`
- `tests/Feature/Filament/AppointmentCalendarTest.php`
- `resources/views/filament/appointments/view-toggle.blade.php` (remove only if
  confirmed unused)

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentCalendarTest.php tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/AdminNavigationStructureTest.php`

**Dependencies:** Task 1
**Estimated scope:** Medium (4-5 files)

### Checkpoint 1

- General Calendar is reachable only within Appointments.
- Existing table behavior remains intact.
- Focused Calendar/navigation tests pass.

## Phase 2: Request Availability Decisions

### Task 3: Add a reusable schedule-review availability projection

Create a focused collaborator that evaluates the ordered request preferences
and selected slot using `EvaluateAppointmentAvailability`. Return presentation-
neutral decisions containing timestamp, preference label, availability boolean,
and reason code.

**Acceptance criteria:**

- Primary and alternatives are evaluated in submitted order.
- Duration and optional optometrist affect every decision.
- Reason codes map from the existing evaluator without inventing new capacity
  rules.

**Files likely touched:**

- `app/Actions/Appointments/EvaluateAppointmentRequestPreferences.php`
- `tests/Feature/Appointments/EvaluateAppointmentRequestPreferencesTest.php`
- `app/Actions/Appointments/AppointmentAvailabilityDecision.php` only if an
  existing accessor is insufficient

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/Appointments/EvaluateAppointmentRequestPreferencesTest.php tests/Feature/Appointments/SchedulingCharacterizationTest.php`

**Dependencies:** Task 1
**Estimated scope:** Small (2-3 files)

### Task 4: Harden request authorization and terminal transitions

Add explicit Appointment Request authorization, reject expired requests, require
the documented active provider for acceptance, and lock/recheck terminal
transitions. Preserve idempotent retries for an already accepted request.

**Acceptance criteria:**

- Supported panel roles can review requests; patient/non-panel accounts cannot.
- Expired requests cannot be linked, accepted, or rejected as pending work.
- Acceptance rechecks the patient link and active eligible provider after
  locking.
- Concurrent accept/reject operations cannot overwrite each other's terminal
  result.

**Files likely touched:**

- `app/Policies/AppointmentRequestPolicy.php`
- `app/Actions/Appointments/AcceptAppointmentRequest.php`
- `app/Actions/Appointments/RejectAppointmentRequest.php`
- `app/Actions/Appointments/LinkAppointmentRequestToPatient.php`
- `tests/Feature/Appointments/ReviewAppointmentRequestTest.php`

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRequestTest.php tests/Feature/Appointments/LinkAppointmentRequestToPatientTest.php tests/Feature/Filament/AppointmentRequestResourceTest.php`

**Dependencies:** Task 3
**Estimated scope:** Medium (5 files)

### Task 5: Create the Review & Schedule resource page foundation

Generate a record-scoped Filament resource page for linked pending requests.
Add typed form state, defaults from the request, authorization/access checks,
preference availability cards, and responsive page composition. Do not accept
the request yet in this slice.

**Acceptance criteria:**

- Linked pending requests can open the page from **Review & Schedule**.
- Unlinked, expired, accepted, rejected, and cancelled requests cannot perform
  scheduling actions.
- Type, duration, required provider, date/time, referral source, and contact note
  follow current acceptance rules.
- Domain validation is mapped to visible form fields without closing or losing
  the scheduling context.

**Files likely touched:**

- `app/Filament/Resources/AppointmentRequests/AppointmentRequestResource.php`
- `app/Filament/Resources/AppointmentRequests/Pages/ViewAppointmentRequest.php`
- `app/Filament/Resources/AppointmentRequests/Pages/ReviewAppointmentRequestSchedule.php`
- `resources/views/filament/appointment-requests/pages/review-appointment-request-schedule.blade.php`
- `tests/Feature/Filament/AppointmentRequestScheduleReviewTest.php`

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRequestScheduleReviewTest.php tests/Feature/Filament/ViewAppointmentRequestTest.php`

**Dependencies:** Task 4
**Estimated scope:** Medium (5 files)

### Checkpoint 2

- Request preferences have live, explicit availability states.
- Access and form-state rules are covered.
- No appointment mutation exists on the new page yet.

## Phase 3: Schedule Context and Selection

### Task 6: Add the request schedule calendar

Create a request-specific calendar widget that shows existing capacity-blocking
appointments, displays the current proposed slot distinctly, and sends empty-
slot selections to the parent page. Focus changes from preference selection
must update the calendar without persisting state.

**Acceptance criteria:**

- Scheduled and checked-in appointments in the visible range are shown.
- Pending requests are not rendered as occupied events.
- The proposed slot is visually and textually distinguishable from persisted
  appointments.
- Empty-slot selection updates the parent scheduling date/time.

**Files likely touched:**

- `app/Filament/Resources/AppointmentRequests/Widgets/AppointmentRequestScheduleCalendar.php`
- `app/Filament/Resources/AppointmentRequests/Pages/ReviewAppointmentRequestSchedule.php`
- `resources/views/filament/appointment-requests/pages/review-appointment-request-schedule.blade.php`
- `tests/Feature/Filament/AppointmentRequestScheduleReviewTest.php`

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRequestScheduleReviewTest.php tests/Feature/Filament/AppointmentCalendarTest.php`

**Dependencies:** Task 5
**Estimated scope:** Medium (4 files)

### Task 7: Complete atomic acceptance and conflict recovery

Wire the page's final action to `AcceptAppointmentRequest`, redirect to the
created Appointment, and handle a stale-slot conflict by showing the error and
refreshing decisions/calendar state.

**Acceptance criteria:**

- Submitted-preference acceptance creates exactly one Appointment.
- A non-preferred time requires a contact note.
- Concurrent slot loss does not double-book and gives staff a recoverable
  conflict state.

**Files likely touched:**

- `app/Filament/Resources/AppointmentRequests/Pages/ReviewAppointmentRequestSchedule.php`
- `resources/views/filament/appointment-requests/pages/review-appointment-request-schedule.blade.php`
- `tests/Feature/Filament/AppointmentRequestScheduleReviewTest.php`
- `tests/Feature/Appointments/ReviewAppointmentRequestTest.php`

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRequestScheduleReviewTest.php tests/Feature/Appointments/ReviewAppointmentRequestTest.php`

**Dependencies:** Task 6
**Estimated scope:** Medium (4 files)

### Checkpoint 3

- Staff can review preferences, see scheduled appointments, select a time, and
  accept through the locked domain action.
- Conflict and validation errors are actionable.
- Focused Filament and domain suites pass.

## Phase 4: Calendar Interaction Safety and Polish

### Task 8: Repair or disable drag-rescheduling before exposure

Prefer the smallest safe option. If drag remains enabled, collect the same
reason category/details as the table action and pass them to
`RescheduleAppointment`; otherwise explicitly disable drag and retain the
existing Reschedule action from appointment details.

**Acceptance criteria:**

- The exposed calendar cannot invoke clinic rescheduling without the required
  reason category.
- Invalid or stale moves revert and show a useful message.
- Status and availability rules match existing table/edit actions.

**Files likely touched:**

- `app/Filament/Resources/Appointments/Widgets/AppointmentCalendarWidget.php`
- `tests/Feature/Filament/AppointmentCalendarTest.php`
- `tests/Feature/Appointments/RescheduleAppointmentTest.php` if the domain case
  is not already covered

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentCalendarTest.php tests/Feature/Appointments/RescheduleAppointmentTest.php`

**Dependencies:** Task 2
**Estimated scope:** Small (2-3 files)

### Task 9: Responsive, accessibility, and final verification

Polish the request/calendar workspace using existing Filament and Tailwind
tokens. Verify keyboard use, explicit status text, dark mode, responsive layout,
asset compilation, focused regression tests, and formatting. Update backend
context only after implementation is actually shipped.

**Acceptance criteria:**

- Layout works at 320, 768, 1024, and 1440 pixels.
- Interactive controls are keyboard accessible and availability is not conveyed
  by color alone.
- Browser console is clean, assets build, focused tests pass, and PHP is
  formatted.

**Files likely touched:**

- Existing request schedule Blade page
- `resources/css/filament/admin/theme.css` only if package/token styling cannot
  express the required state
- Focused Filament tests
- `docs/BACKEND_CONTEXT.md` after shipping

**Verification:**

- `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentCalendarTest.php tests/Feature/Filament/AppointmentRequestScheduleReviewTest.php tests/Feature/Filament/ViewAppointmentRequestTest.php tests/Feature/Appointments/ReviewAppointmentRequestTest.php tests/Feature/Appointments/SchedulingCharacterizationTest.php`
- `vendor/bin/sail npm run build`
- `vendor/bin/sail bin pint --dirty --format agent`
- Manual browser verification at the four target widths in light and dark mode.

**Dependencies:** Tasks 7 and 8
**Estimated scope:** Medium (3-4 files)

### Checkpoint 4

- Every specification success criterion is met.
- No schema, API, dependency, or navigation-taxonomy change is present.
- Focused tests, build, formatting, and browser verification pass.
- Implementation is ready for review.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Advisory availability becomes stale | High | Recheck through `AcceptAppointmentRequest` under the existing lock and refresh on conflict. |
| Nested Livewire state diverges | High | Keep form state in the resource page; use child events only for calendar selection/focus. |
| Pending requests appear to reserve slots | High | Never render them as occupied events; state the non-binding rule in tests and copy. |
| Expired or concurrent terminal requests are mutated | High | Use `isPending()` semantics, explicit authorization, and row locks/rechecks for terminal actions. |
| Provider requirement differs across old documents and code | High | Treat the living backend context as the proposed rule and require explicit owner approval before implementation. |
| Calendar drag bypasses required reasons | High | Disable it until a tested reason modal delegates to `RescheduleAppointment`. |
| Calendar query causes N+1 or overfetching | Medium | Scope by visible range, select needed fields, and eager-load displayed relations. |
| Timezone or exclusive range errors | Medium | Use app/Filament timezone and test range boundaries explicitly. |
| Small-screen calendar becomes unusable | Medium | Stack the workspace and prefer Day view on narrow screens during browser verification. |
| Custom markup drifts from Filament | Low | Prefer Filament schema/action components and existing theme tokens. |

## Decisions Awaiting Review

1. Use Week as the general calendar default; Day remains available.
2. Initially show scheduled and checked-in events; terminal statuses remain in
   the table unless staff deliberately enables a future history filter.
3. Redirect to the created Appointment after acceptance.
4. Disable drag-rescheduling unless Task 8 can implement the full reason and
   conflict contract cleanly.
5. Enforce the living backend context's assigned-provider requirement during
   request acceptance, superseding the older optional-provider behavior.

## Parallelization

Implementation should remain sequential because the same Livewire state,
calendar components, and focused tests are shared across tasks. Independent
visual review can run after Tasks 6-8, but no parallel code edits are planned.
