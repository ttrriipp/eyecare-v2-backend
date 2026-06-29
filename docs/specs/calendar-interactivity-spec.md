# Spec: Appointment Calendar Interactivity

## Objective

Turn the appointment calendar from a read-only display into a working scheduling tool. Staff should manage appointments directly from the calendar instead of going to the table.

**Scope (confirmed): A + B + C**
- **A — Drag-to-reschedule:** drag an event to a new day/time → reschedules it (runs `UpdateAppointmentStatus` as `rescheduled` with the new time). Reverts the drag if the appointment isn't reschedulable or the slot conflicts.
- **B — Click empty date to create:** clicking a day/slot opens the Create Appointment page with `scheduled_at` pre-filled.
- **C — Click event to open:** clicking an appointment navigates to its Edit page (full page with the existing status/reschedule/bill logic).

**User:** Staff/admin using the Filament appointment calendar. **Success:** drag reschedules with validation; clicking empty space pre-fills a new booking; clicking an event opens it.

## Tech Stack

PHP 8.5 / Laravel 13 / Filament 5 / `guava/calendar` (already installed) / Pest 4. No new dependencies.

## Commands

```
Test:  vendor/bin/sail artisan test --compact --filter=Calendar
Lint:  vendor/bin/sail bin pint --dirty --format agent
Build: vendor/bin/sail npm run build
```

## Package Capabilities (verified)

- `HandlesEventDragAndDrop`: `protected bool $eventDragEnabled` + `onEventDrop(EventDropInfo $info, Model $event): bool` (return `false` to revert the drag).
- `HandlesDateClick`: `protected bool $dateClickEnabled` + `onDateClick(DateClickInfo $info)`.
- `HandlesEventClick`: `protected bool $eventClickEnabled` (already true) + `onEventClick(EventClickInfo $info, Model $event, ?string $action)`.
- `EventDropInfo->event` is a `CalendarEvent` exposing the new start; `DateClickInfo` exposes the clicked date.

## Code Style

Reuse existing pages via redirects (full validation preserved) rather than rebuilding forms in calendar modals:

```php
protected function onEventClick(EventClickInfo $info, Model $event, ?string $action = null): void
{
    $this->redirect(EditAppointment::getUrl(['record' => $event->getKey()]));
}
```

Drag logic delegates to a testable method:

```php
protected function onEventDrop(EventDropInfo $info, Model $event): bool
{
    return $this->attemptReschedule($event, $info->event->getStart());
}
```

## Architecture Decisions

- **Redirects to existing pages, not calendar modals.** The Create/Edit pages hold the conflict validation, status-transition handling, walk-in quick-create, and reschedule/bill actions. Rebuilding those in a calendar modal would bypass that logic. Redirects reuse it intact.
- **Drag-reschedule delegates to `attemptReschedule(Appointment, CarbonInterface): bool`** — a single testable method that validates and either reschedules (returns true) or rejects (notifies + returns false, reverting the drag). `onEventDrop` is a thin wrapper extracting the new start.
- **Conflict check extracted to `Appointment::conflictsWith(CarbonInterface $at, ?int $ignoreId = null): bool`.** Drag-reschedule must prevent double-booking. To avoid a third copy of the ±30-minute logic, extract a model helper and use it here. (Refactoring the form + request to use it too is a follow-up; not done now to avoid touching heavily-tested paths.)
- **Create prefill via query param.** Date click redirects to `CreateAppointment` with `?scheduled_at=...`; the page fills the date field in `mount()`.

## Validation Rules (preserved)

- Drag-reschedule only allowed for `pending`, `confirmed`, `rescheduled` (terminal statuses revert with a warning).
- New time must be in the future; conflicting slots (±30 min, excluding self) revert with a warning.
- Successful drag runs `UpdateAppointmentStatus->handle($appt, 'rescheduled', scheduledAt: $newStart)` — same path as the Reschedule action, so the SMS record + audit log fire.

## Testing Strategy

- `attemptReschedule` unit/feature tests: success (pending→rescheduled, time updated), rejected for terminal status, rejected for conflict.
- `Appointment::conflictsWith` test: detects ±30-min overlap, ignores self, ignores cancelled.
- `CreateAppointment` prefill test: `?scheduled_at=...` populates the form's `scheduled_at`.
- Existing appointment tests must stay green.

## Boundaries

- **Always:** run `--filter=Calendar` + appointment tests, Pint, rebuild assets, update BACKEND_CONTEXT.
- **Ask first:** new dependencies, changing the reschedule transition rules.
- **Never:** bypass `UpdateAppointmentStatus` for status changes; break the existing table view or list tabs.

## Tasks

### Task 1 — Conflict helper + create prefill (foundation)
- `Appointment::conflictsWith(CarbonInterface $at, ?int $ignoreId = null): bool`; `CreateAppointment` prefills `scheduled_at` from query param.
- Verify: `--filter=AppointmentBooking` + new conflict test.
- Files: `Appointment.php`, `CreateAppointment.php`, tests.

### Task 2 — Calendar interactions (A + B + C)
- Enable drag + date click; implement `onEventDrop`/`attemptReschedule`, `onDateClick` (redirect to create), `onEventClick` (redirect to edit).
- Verify: `--filter=Calendar`, manual drag check.
- Files: `AppointmentCalendarWidget.php`, tests.

## Success Criteria

1. Dragging a pending/confirmed appointment to a new time reschedules it (status `rescheduled`, time updated, SMS queued).
2. Dragging a completed/cancelled appointment, or into a conflicting slot, snaps back with a warning notification.
3. Clicking an empty day opens Create Appointment with the date pre-filled.
4. Clicking an event opens its Edit page.
5. All appointment + calendar tests green; table view and tabs unaffected.

## Open Questions

None — scope confirmed (A+B+C), redirect-based approach chosen for robustness.
