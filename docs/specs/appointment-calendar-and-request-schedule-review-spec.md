# Spec: Appointment Calendar and Request Schedule Review

**Status:** Proposed for review
**Planning date:** 2026-08-21

## Assumptions

1. Staff, administrators, and optometrists use the Filament panel to manage
   appointments; this feature does not change the Android application.
2. The existing **Appointments** navigation item remains the only sidebar entry.
   List and Calendar are two views inside that resource.
3. A pending appointment request remains non-binding and does not consume
   appointment capacity until staff accepts it.
4. Staff must link an unlinked request to a patient before scheduling it, as the
   current workflow already requires.
5. The submitted primary time and up to two alternative times remain ordered
   patient preferences, not reservations.
6. The current living backend context requires staff to assign an active
   optometrist when accepting a request. The implementation will reconcile the
   current nullable action parameter with that documented rule.
7. Existing appointment, clinic-hours, provider-hours, schedule-override, and
   capacity rules remain authoritative.
8. No database migration, public API change, or new dependency is required.
9. The existing `guava/calendar` package and Appointment calendar event mapping
   will be reused.

## Objective

Give clinic staff one clear scheduling workflow where they can:

- review a patient's preferred appointment times;
- see whether each preference is currently available;
- compare those preferences against existing scheduled appointments;
- select a submitted preference or another available time;
- assign an active optometrist; and
- atomically accept the request and create the appointment.

The broader Appointments resource will also expose a Calendar view for daily and
weekly schedule management without removing the existing searchable table.

## Users and User Stories

### Clinic staff reviewing a request

- As clinic staff, I can open **Review & Schedule** from a linked pending request.
- I can see the primary and alternative preferences with explicit availability
  labels.
- I can see existing capacity-blocking appointments on a calendar beside the
  request.
- I can select a preferred time with one action.
- I can choose another open slot when none of the submitted preferences works.
- I am told when a selected slot becomes unavailable before acceptance.

### Clinic staff managing the schedule

- As clinic staff, I can switch between List and Calendar without seeing another
  sidebar module.
- I can use Month, Week, and Day calendar views.
- I can open an appointment from the calendar and create an appointment from an
  empty time slot.

## Scope

### Appointment Calendar

- Add a Calendar resource page under the existing Appointments resource.
- Add List and Calendar header controls that navigate between the two views.
- Keep the existing appointment table for search, filters, history, and bulk
  workflows.
- Default to Week view on normal desktop widths, with Month and Day available.
- Present scheduled and checked-in appointments by default; do not treat
  cancelled or no-show appointments as capacity blocks.
- Show appointment time, patient, appointment type, status, duration, and
  optometrist through the event and quick-view details.
- Use text or icons together with status color so color is never the only signal.

### Request Review and Scheduling

- Replace the linked pending request's **Accept Request** entry point with
  **Review & Schedule**.
- Open a record-scoped Filament resource page rather than a second sidebar item
  or an oversized nested action modal.
- Use a responsive two-column workspace on larger screens and a stacked layout
  on small screens:
  - request preferences and scheduling form;
  - existing appointment calendar and selected-slot preview.
- Label each submitted preference as one of:
  - Available;
  - Clinic closed;
  - Outside clinic hours;
  - Provider unavailable / capacity reached;
  - Elapsed.
- Selecting a preferred time updates the scheduling form and focuses the
  calendar on that date.
- Clicking an open calendar time updates the selected date and time.
- Changing appointment type, duration, or optometrist recalculates preference
  availability.
- Optometrist selection is required before acceptance, matching the current
  backend context's request-review contract.
- A time outside the submitted preferences requires the existing contact note.
- The final action calls `AcceptAppointmentRequest`; it does not create or update
  an Appointment directly from the page.
- After success, redirect staff to the newly created Appointment record.

### Availability Presentation

- Use `EvaluateAppointmentAvailability` for live decision labels so clinic
  hours, provider hours, absences, overrides, capacity, duration, and grid rules
  are evaluated consistently.
- Show only accepted appointments as occupied calendar events.
- Other pending requests may later be summarized as non-blocking demand, but
  they are not included in this implementation.
- Always re-run the authoritative acceptance validation inside the existing
  transaction and schedule-date lock.

## Out of Scope

- A new sidebar navigation item.
- Changing appointment-request expiration or capacity semantics.
- Making pending requests reserve or hold slots.
- Database schema or migration changes.
- Public/mobile API contract changes.
- SMS or notification workflow changes.
- A new calendar package or dependency.
- Provider-column/resource timeline view.
- Bulk acceptance of requests.
- Automatically accepting the first available preference.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5
- Livewire 4
- Guava Calendar 3
- Tailwind CSS 4 through the existing Filament theme
- Pest 4 / PHPUnit 12
- Laravel Sail

## Commands

- Start services: `vendor/bin/sail up -d`
- Focused request tests: `vendor/bin/sail artisan test --compact tests/Feature/Filament/ViewAppointmentRequestTest.php tests/Feature/Filament/AppointmentRequestScheduleReviewTest.php`
- Focused calendar tests: `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentCalendarTest.php tests/Feature/Filament/DashboardTest.php`
- Appointment domain tests: `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRequestTest.php tests/Feature/Appointments/SchedulingCharacterizationTest.php`
- Build frontend assets: `vendor/bin/sail npm run build`
- Format changed PHP: `vendor/bin/sail bin pint --dirty --format agent`

## Project Structure

- `app/Filament/Resources/Appointments/` — List and Calendar resource pages,
  shared appointment calendar widget, event presentation.
- `app/Filament/Resources/AppointmentRequests/` — Request detail page and new
  record-scoped Review & Schedule page.
- `app/Actions/Appointments/` — Existing availability and atomic acceptance
  actions; any presentation-oriented slot-decision collaborator belongs here
  only if it is reusable outside Filament.
- `resources/views/filament/` — Responsive page composition using existing
  Filament/Tailwind conventions.
- `tests/Feature/Filament/` — Filament and Livewire workflow tests.
- `tests/Feature/Appointments/` — Domain availability and acceptance tests.

## Code Style

Business state changes remain in the existing action:

```php
$appointment = $acceptAppointmentRequest->handle(
    request: $this->getRecord(),
    reviewer: auth()->user(),
    appointmentType: $appointmentType,
    durationMinutes: $this->durationMinutes,
    scheduledAt: $this->selectedScheduledAt(),
    optometrist: $optometrist,
    referringSource: $this->referringSource,
    contactNote: $this->contactNote,
);
```

- Use typed properties, parameters, and return values.
- Keep page methods focused on presentation state and delegation.
- Eager-load calendar relationships; never query from Blade.
- Escape user-provided patient and note text.
- Use Filament components and semantic color names before custom markup.

## Testing Strategy

- Write focused Pest tests before each behavior change.
- Test preference availability as observable labels and selectable state.
- Test that pending requests do not appear as occupied appointments.
- Test that scheduled/checked-in appointments appear in the relevant calendar
  range and cancelled/no-show records do not block availability.
- Test submitted-preference selection versus the required contact-note rule.
- Test acceptance success and the race case where the chosen slot becomes
  unavailable before confirmation.
- Test that unlinked, terminal, and expired requests cannot access scheduling
  mutations.
- Test that only authorized panel roles can review or mutate requests.
- Test that acceptance requires an active eligible optometrist and rechecks the
  request, patient link, provider, and slot after locking.
- Test accept/reject races so one terminal transition cannot overwrite another.
- Test calendar event/date interactions through Livewire where supported.
- Build the Filament theme and manually verify 320, 768, 1024, and 1440 pixel
  layouts, keyboard focus, dark mode, and browser console output.

## Boundaries

### Always

- Authorize resource-page access and each record mutation.
- Reject expired requests at both the presentation and domain layers.
- Use `EvaluateAppointmentAvailability` for advisory decisions.
- Use `AcceptAppointmentRequest` for final acceptance.
- Revalidate availability at confirmation time.
- Preserve the non-binding meaning of pending requests.
- Run focused Pest tests and Pint before each implementation commit.

### Ask First

- Add a database column or migration.
- Add or replace a package.
- Change the mobile API response or request contract.
- Make pending requests consume capacity.
- Change appointment status or expiration rules.
- Add automated patient contact or notification behavior.

### Never

- Create the accepted Appointment directly from the Filament page.
- Trust a client-provided request, appointment, patient, or optometrist ID
  without resolving and authorizing it server-side.
- Treat the calendar's availability display as the final concurrency check.
- Hide an unavailable state using color alone.
- Remove the appointment table or Appointment Requests resource.

## Success Criteria

- The Appointments resource provides List and Calendar views without adding a
  sidebar navigation item.
- A linked pending request exposes **Review & Schedule**.
- Expired, unlinked, terminal, and unauthorized requests cannot be scheduled.
- All submitted preferences show a current, explicit availability decision.
- The schedule-review page shows existing capacity-blocking appointments and a
  distinct proposed selection.
- Staff can select a preference or another calendar slot.
- Choosing a non-preferred time requires a contact note.
- Acceptance produces exactly one Appointment through the existing atomic
  action and redirects to it.
- A concurrent conflict does not double-book; the page reports the conflict and
  refreshes its availability state.
- No database schema, public API, or dependency changes are introduced.
- Focused tests, asset build, and formatting pass.

## Review Questions

1. Is Week the desired default for the general calendar, with Day available for
   front-desk use?
2. Should fulfilled appointments remain visible as history on the calendar, or
   should the initial calendar show only scheduled and checked-in records?
3. After accepting a request, should staff land on the new Appointment record
   (recommended) or return to the pending Requests list?
4. The latest backend context requires an assigned provider at request
   acceptance, while an older lifecycle spec and the current action allow an
   unassigned booking. Should implementation enforce the latest documented rule
   (recommended)?
