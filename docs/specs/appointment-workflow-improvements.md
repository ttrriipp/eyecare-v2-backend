# Spec: Appointment Workflow Improvements

## Objective

Improve the appointment feature so it matches a small optical clinic workflow: staff-first scheduling, simple walk-in handling, optometrist assignment, safer availability rules, and clear visit lifecycle tracking.

The main users are reception staff/admins who manage the daily clinic flow. Patients may still book through the Android app, but app bookings should be treated as appointment requests that staff can confirm. Optometrists should be able to see and manage their assigned appointments without needing to understand the full admin workflow.

Success means the system behaves like a practical digital appointment book:

- Staff can create scheduled appointments from phone/message/in-person requests.
- Staff can add walk-in patients to a same-day queue.
- App bookings remain simple and start as pending.
- Two optometrists can have separate availability.
- Appointment conflicts are enforced consistently by backend rules.
- The billing relationship remains encounter-based: appointments group services/orders into billings but do not automatically create invoices.

## Assumptions

- Clinic hours will start as configurable application values, defaulting to 9:00 AM-5:00 PM, Monday-Saturday, closed Sunday.
- The exact clinic hours may change later, so appointment logic must not scatter hardcoded time checks across controllers/forms.
- The clinic has two optometrists.
- Optometrists are existing users, not a new role table.
- The existing roles remain `admin`, `staff`, and `customer`.
- A staff/admin user can be marked as an optometrist through a simple capability flag.
- The first version of the walk-in queue can reuse `appointments`, not a separate queue table.
- `rescheduled` should be removed as an appointment status. Rescheduling should be an action/history event that changes the appointment date and keeps the appointment in a meaningful workflow status.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5
- Livewire 4
- Sanctum 4
- MySQL via Laravel Sail
- Pest 4 / PHPUnit 12
- Tailwind CSS 4

## Commands

- Start services: `vendor/bin/sail up -d`
- Run migrations: `vendor/bin/sail artisan migrate`
- Run targeted tests: `vendor/bin/sail artisan test --compact --filter=Appointment`
- Run a specific test file: `vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentBookingTest.php`
- Run formatter after PHP changes: `vendor/bin/sail bin pint --dirty --format agent`
- List routes: `vendor/bin/sail artisan route:list --except-vendor`
- Inspect Artisan commands: `vendor/bin/sail artisan list`

## Project Structure

- `app/Models/Appointment.php` - appointment relationships, casts, conflict helpers or scopes.
- `app/Models/User.php` - optometrist capability and relationships.
- `app/Actions/Appointments/` - central scheduling and status actions.
- `app/Http/Requests/Api/` - API request authorization and validation.
- `app/Http/Controllers/Api/` - thin API controllers for customer/staff endpoints.
- `app/Http/Resources/AppointmentResource.php` - mobile appointment response shape.
- `app/Filament/Resources/Appointments/` - staff/admin appointment UI.
- `app/Filament/Resources/Users/` - user/admin UI for marking optometrists, if present in current structure.
- `database/migrations/` - additive schema migrations.
- `database/seeders/` - appointment status and related seed updates.
- `tests/Feature/Api/` - customer/staff API appointment tests.
- `tests/Feature/Filament/` - Filament appointment workflow tests.
- `tests/Feature/` - domain/action tests.
- `docs/BACKEND_CONTEXT.md` - update after behavior/schema changes are finalized.

## Data Model

Appointments should gain the minimum fields needed to represent real clinic workflow:

- `source` string/enum-like value:
  - `mobile_app`
  - `walk_in`
  - `phone_call`
  - `messenger`
  - `staff_created`
- `optometrist_id` nullable FK to `users.id`.
- `checked_in_at` nullable timestamp.
- `completed_at` nullable timestamp.

Users should gain:

- `is_optometrist` boolean, default false.

Appointment statuses should support:

- `pending` - app request or unconfirmed booking.
- `confirmed` - scheduled and accepted.
- `arrived` - patient is in clinic and waiting.
- `completed` - visit finished.
- `no_show` - patient did not arrive.
- `cancelled` - appointment cancelled.

Existing `rescheduled` records should be migrated out of the status model. Rescheduling becomes an action/history event, not a destination status. The migration should convert current `rescheduled` appointments to `pending` so staff must reconfirm the changed appointment.

Billing remains unchanged conceptually:

- `billings.appointment_id` continues to group one visit/encounter.
- Completing an appointment does not automatically create a billing.
- Orders and services continue to create or attach billing items.
- Walk-ins can still produce billings through service/order flows.

## Scheduling Rules

Scheduling rules must live in one backend scheduling action/service rather than being duplicated in forms and request validators.

The scheduler must validate:

- Appointment is inside clinic operating hours.
- Appointment is not on a closed day.
- Appointment start is in the future for scheduled bookings.
- Visit reason duration is respected.
- Conflicts are checked against the selected optometrist when `optometrist_id` is present.
- If no optometrist is selected, the system may either find an available optometrist or reject with a clear message depending on the workflow.
- Cancelled and no-show appointments do not block future availability.
- Completed appointments remain historical and should not be moved.

The scheduler should be used by:

- Customer app booking.
- Customer app reschedule.
- Staff appointment create.
- Staff reschedule action.
- Calendar drag-reschedule.
- Any future availability endpoint.

## Workflow

### Mobile App Booking

- Patient selects visit reason and available slot.
- Backend creates appointment with `source = mobile_app`.
- Status starts as `pending`.
- Staff/admin receives database notification.
- Staff confirms, reschedules, or cancels.

### Staff Scheduled Booking

- Staff creates appointment for phone/message/in-person request.
- Staff selects patient, visit reason, date/time, source, and optometrist if known.
- Status defaults to `confirmed`.
- Source defaults to `staff_created`, but staff can choose `phone_call` or `messenger`.

### Walk-In Queue

- Staff clicks "Add Walk-in".
- Staff enters/selects patient, phone, visit reason, and optometrist if known.
- Backend creates same-day appointment with `source = walk_in`.
- Status starts as `arrived`.
- Queue view shows today's `arrived` walk-ins.
- Staff can move a walk-in from `arrived` to `completed`.

### Optometrist View

- Optometrist can filter appointments assigned to them.
- Optometrist can see today's assigned appointments and walk-ins.
- Minimum action: complete visit.
- `in_progress` is intentionally excluded from the MVP because it adds another staff action without being necessary for a small optical clinic's capstone workflow.

### Rescheduling

- Reschedule changes `scheduled_at`.
- Reschedule writes audit metadata with old and new scheduled time.
- Reschedule sends SMS notification.
- Reschedule does not need to remain as a long-term status.
- If the patient initiates reschedule from the app, final status should become `pending` so staff reconfirms.
- If staff reschedules a confirmed appointment, status may remain `confirmed`.

## Code Style

Use Laravel actions for business behavior and keep controllers thin.

Example target style:

```php
public function store(StoreAppointmentRequest $request, ScheduleAppointment $scheduleAppointment): JsonResponse
{
    $appointment = $scheduleAppointment->handle(
        customer: $request->user(),
        visitReasonId: $request->integer('visit_reason_id'),
        scheduledAt: Carbon::parse($request->validated('scheduled_at')),
        source: AppointmentSource::MobileApp,
        status: AppointmentStatusName::Pending,
        contactNotes: $request->validated('contact_notes'),
    );

    return response()->json([
        'data' => AppointmentResource::make($appointment),
    ], 201);
}
```

Conventions:

- Use explicit parameter and return types.
- Use Form Requests for request validation and authorization.
- Use action classes for scheduling/status changes.
- Use descriptive names such as `optometrist_id`, `checked_in_at`, and `is_optometrist`.
- Do not add a new permission package or dynamic role system.
- Follow existing Filament v5 component namespaces and local resource structure.

## Testing Strategy

Use Pest feature tests focused on behavior.

Required coverage:

- Customer booking creates `pending` appointment with `source = mobile_app`.
- Staff-created appointment defaults to `confirmed`.
- Walk-in action creates same-day `arrived` appointment with `source = walk_in`.
- App reschedule returns appointment to `pending`.
- Staff reschedule can keep appointment `confirmed`.
- Conflict checks are enforced by the central scheduler.
- Two optometrists can hold overlapping appointments at the same time.
- One optometrist cannot hold overlapping appointments.
- Closed-day/outside-hours bookings are rejected.
- `arrived -> completed` works.
- `confirmed -> no_show` works.
- Reminder command is registered in scheduler.
- Existing billing grouping by `appointment_id` still works.

Run the minimum relevant tests during implementation, then run the broader appointment suite before finalizing.

## Boundaries

- Always:
  - Use Sail for PHP, Artisan, Composer, Node, and tests.
  - Use Laravel Boost `search-docs` before code changes.
  - Use additive migrations for schema changes.
  - Keep appointment business rules centralized.
  - Preserve existing appointment, order, billing, and prescription workflows unless explicitly changed.
  - Update tests alongside behavior changes.
  - Run Pint after PHP edits.

- Ask first:
  - Adding a new role beyond `admin`, `staff`, and `customer`.
  - Adding new dependencies.
  - Changing billing generation behavior.
  - Changing Android API contracts in a backward-incompatible way.

- Never:
  - Hardcode clinic hours in multiple controllers/forms.
  - Make appointment completion automatically bill the patient.
  - Force all walk-ins into a future scheduled slot.
  - Treat optometrist assignment as the same thing as receptionist/staff ownership.
  - Delete tests to make the suite pass.
  - Edit vendor files.

## Success Criteria

- Staff can manage the day using scheduled appointments plus a walk-in queue.
- Customer app bookings remain simple and enter staff review as pending.
- Optometrist-specific availability prevents double-booking one optometrist while allowing two optometrists to work in parallel.
- Backend scheduling rules are consistently applied across API, Filament forms, and calendar drag-reschedule.
- Appointment statuses reflect real clinic flow: pending, confirmed, arrived, completed, no-show, cancelled.
- Reminder scheduling is active.
- Billing remains encounter-based and is not made more automatic or restrictive.
- The UI remains understandable for non-technical staff: clear labels, direct actions, minimal required fields.

## Decisions From Review

- Clinic hours default to 9:00 AM-5:00 PM, Monday-Saturday, closed Sunday. They should be centralized so they can become configurable later.
- The clinic has two optometrists.
- Optometrists are staff/admin users marked with `is_optometrist`, not a separate role or table.
- Staff should be allowed to leave `optometrist_id` blank for early/unassigned booking, but confirmed appointment availability is most reliable when an optometrist is selected.
- When no optometrist is selected, overlapping appointments consume the clinic capacity represented by users marked as optometrists; the fallback capacity is one until optometrists are configured.
- App-created bookings start as `pending`.
- Staff-created phone/messenger/in-person bookings default to `confirmed`.
- Walk-ins start as `arrived`.
- `in_progress` is not part of MVP.
- Existing `rescheduled` appointments should be migrated to `pending`, and the `rescheduled` status should be removed from seeded/current statuses.
- Optometrists do not need a separate restricted panel for the first iteration. Filtering the existing appointment list/calendar by assigned optometrist is enough.

## Implementation Plan

### Phase 1: Schema and Seed Foundation

Add the minimum schema needed for the improved workflow:

- Add `users.is_optometrist`.
- Add `appointments.source`.
- Add `appointments.optometrist_id`.
- Add `appointments.checked_in_at`.
- Add `appointments.completed_at`.
- Add appointment statuses: `arrived` and `no_show`.
- Migrate existing `rescheduled` appointments to `pending`.
- Remove `rescheduled` from seeded/current appointment statuses.

Dependencies:

- This must happen before Filament, API resources, and scheduler logic can expose the new fields.

Verification:

- Migration tests or feature tests prove defaults are correct.
- Existing appointment factory still creates valid appointments.
- Existing status transition tests are updated to the new status model.

### Phase 2: Central Scheduling Rules

Introduce a central appointment scheduling action/service used by every create/reschedule path.

Responsibilities:

- Enforce clinic hours through a single configuration source.
- Enforce closed days.
- Enforce visit reason duration.
- Enforce optometrist-specific conflict checks.
- Allow parallel appointments when assigned to different optometrists.
- Preserve existing behavior for unassigned appointments in a predictable way.

Dependencies:

- Requires `optometrist_id` and `source` fields.

Verification:

- One optometrist cannot be double-booked.
- Two optometrists can be booked at the same time.
- Sunday/outside-hours bookings fail.
- API, Filament create, Filament reschedule, and calendar drag-reschedule all use the same scheduler.

### Phase 3: Status Workflow Update

Update appointment transitions to support the small-clinic flow:

- `pending -> confirmed, cancelled`
- `confirmed -> arrived, no_show, cancelled`
- `arrived -> completed, cancelled`
- `completed -> terminal`
- `no_show -> terminal`
- `cancelled -> terminal`
- `rescheduled` is removed from the status model.

Rescheduling behavior:

- Customer reschedule changes the date/time and returns the appointment to `pending`.
- Staff reschedule changes the date/time and keeps the appointment `confirmed` when it was already confirmed.
- Every reschedule sends SMS and audit metadata.

Dependencies:

- Requires central scheduler from Phase 2.

Verification:

- Transition tests cover every allowed and blocked transition.
- SMS/audit tests continue passing.
- Existing `rescheduled` records are migrated to `pending`.

### Phase 4: Filament Staff Workflow

Update staff/admin UI around daily operations:

- Appointment form shows source and optometrist assignment.
- Optometrist selector only lists staff/admin users with `is_optometrist = true`.
- Staff-created appointments default to `confirmed`.
- Add a simple "Add Walk-in" action.
- Add a walk-in queue view/filter for today's `source = walk_in` and `status = arrived`.
- Add actions for `Arrived`, `Complete`, and `No-show`.
- Add assigned optometrist column/filter to tables/calendar.

Dependencies:

- Requires schema, statuses, and transitions.

Verification:

- Filament tests prove staff can create scheduled appointments and walk-ins.
- Staff can move confirmed appointment to arrived.
- Staff can complete arrived appointment.
- Staff can mark confirmed appointment as no-show.
- Appointment table can filter by assigned optometrist.

### Phase 5: API and Mobile Compatibility

Update customer/staff API behavior without breaking current mobile clients unnecessarily:

- Customer booking sets `source = mobile_app`.
- Customer booking starts as `pending`.
- Customer reschedule returns appointment to `pending`.
- Appointment resource returns source and assigned optometrist.
- Add an availability endpoint only if needed for the mobile app scope.

Dependencies:

- Requires scheduler and data model.

Verification:

- API tests cover source defaults, pending status, reschedule behavior, and conflict behavior.
- Existing appointment list/detail responses remain usable.

### Phase 6: Reminder Scheduling and Documentation

Finish operational polish:

- Register `appointments:send-reminders` in Laravel scheduler.
- Use `withoutOverlapping()` for scheduled commands where appropriate.
- Update `docs/BACKEND_CONTEXT.md` after behavior and schema are implemented.

Dependencies:

- Can be done independently, but should be verified before final presentation.

Verification:

- Scheduler contains appointment reminder command.
- Appointment reminder tests remain green.
- Backend context document reflects the final statuses, fields, and workflow.

## Risks and Mitigations

- Risk: Removing `rescheduled` may surprise code paths or tests that still expect it.
  - Mitigation: Update seeders, transitions, API tests, Filament actions, and docs in the same migration phase; migrate existing records to `pending` before deleting the status row.

- Risk: Optometrist assignment makes booking feel slower.
  - Mitigation: Allow unassigned appointments, but make optometrist assignment visible and easy from the staff table/edit page.

- Risk: Clinic hours are uncertain.
  - Mitigation: Centralize default 9:00 AM-5:00 PM rules so changing hours later is low-cost.

- Risk: Walk-in queue becomes overbuilt.
  - Mitigation: Reuse appointments and table filters first; avoid a separate queue table for MVP.

- Risk: Mobile API changes break existing Android work.
  - Mitigation: Add fields rather than removing fields; keep current appointment endpoints stable.

## Task Breakdown

### Phase A: Foundation

#### Task 1: Add appointment workflow schema and status migration

Description: Add the database foundation for appointment source, optometrist assignment, check-in/completion timestamps, and the new status catalog. Migrate existing `rescheduled` appointments to `pending` before removing the `rescheduled` status row.

Acceptance criteria:

- [x] `appointments` has `source`, `optometrist_id`, `checked_in_at`, and `completed_at`.
- [x] `users` has `is_optometrist` defaulting to false.
- [x] Seeded appointment statuses are `pending`, `confirmed`, `arrived`, `completed`, `no_show`, and `cancelled`.
- [x] Existing `rescheduled` appointments are converted to `pending`.

Verification:

- [x] Run `vendor/bin/sail artisan test --compact tests/Feature/StatusCatalogTest.php`.
- [x] Run `vendor/bin/sail artisan test --compact tests/Feature/AppointmentModelTest.php`.
- [x] Run `vendor/bin/sail bin pint --dirty --format agent`.

Dependencies: None.

Files likely touched:

- `database/migrations/*_add_workflow_fields_to_appointments_and_users.php`
- `database/migrations/*_migrate_rescheduled_appointment_status.php`
- `database/seeders/AppointmentStatusSeeder.php`
- `database/factories/AppointmentFactory.php`
- `database/factories/UserFactory.php`
- `tests/Feature/StatusCatalogTest.php`
- `tests/Feature/AppointmentModelTest.php`

Estimated scope: Medium.

Implementation note: Keep the additive schema change and the existing-data status conversion in separate migrations so DDL and DML remain independently reviewable.

#### Task 2: Expose optometrist capability on users

Description: Let admins mark staff/admin users as optometrists from the existing Users resource. Keep optometrist as a capability on `users`, not a new role.

Acceptance criteria:

- [x] Admin can set `is_optometrist` when creating/editing staff/admin users.
- [x] Customer users cannot be treated as optometrists in appointment selectors.
- [x] User list clearly shows which users are optometrists.

Verification:

- [x] Run `vendor/bin/sail artisan test --compact tests/Feature/Filament/UserResourceTest.php`.
- [x] Run `vendor/bin/sail bin pint --dirty --format agent`.

Dependencies: Task 1.

Files likely touched:

- `app/Models/User.php`
- `app/Filament/Resources/Users/Schemas/UserForm.php`
- `app/Filament/Resources/Users/Tables/UsersTable.php`
- `tests/Feature/Filament/UserResourceTest.php`

Estimated scope: Medium.

#### Checkpoint: Foundation

- [x] Schema and seed tests pass.
- [x] User resource tests pass.
- [x] No remaining seed dependency on `rescheduled`.

### Phase B: Scheduling and Status Domain

#### Task 3: Create central appointment scheduler

Description: Introduce a scheduling action/service that owns clinic-hour validation, closed-day validation, duration handling, and optometrist-specific conflict detection. This becomes the backend invariant for appointment creation and rescheduling.

Acceptance criteria:

- [x] Bookings outside 9:00 AM-5:00 PM are rejected.
- [x] Sunday bookings are rejected.
- [x] One optometrist cannot be double-booked for overlapping visits.
- [x] Two different optometrists can be booked for the same time.
- [x] Cancelled and no-show appointments do not block availability.

Verification:

- [x] Run `vendor/bin/sail artisan test --compact --filter=AppointmentScheduling`.
- [x] Run `vendor/bin/sail bin pint --dirty --format agent`.

Dependencies: Task 1.

Files likely touched:

- `app/Actions/Appointments/ScheduleAppointment.php`
- `config/appointments.php`
- `app/Models/Appointment.php`
- `tests/Feature/AppointmentSchedulingTest.php`

Estimated scope: Medium.

#### Task 4: Update appointment status transitions

Description: Rewrite appointment status transitions around the simplified small-clinic flow and remove `rescheduled` as a valid target status.

Acceptance criteria:

- [x] Allowed transitions are `pending -> confirmed/cancelled`, `confirmed -> arrived/no_show/cancelled`, and `arrived -> completed/cancelled`.
- [x] `completed`, `no_show`, and `cancelled` are terminal.
- [x] Confirm/cancel still create SMS records where appropriate.
- [x] Invalid transition attempts return validation errors.

Verification:

- [x] Run `vendor/bin/sail artisan test --compact tests/Feature/Api/StaffAppointmentTest.php`.
- [x] Run `vendor/bin/sail artisan test --compact tests/Feature/AppointmentSmsMessageTest.php`.
- [x] Run `vendor/bin/sail bin pint --dirty --format agent`.

Dependencies: Task 1.

Files likely touched:

- `app/Actions/Appointments/UpdateAppointmentStatus.php`
- `app/Http/Requests/Api/UpdateAppointmentStatusRequest.php`
- `tests/Feature/Api/StaffAppointmentTest.php`
- `tests/Feature/AppointmentSmsMessageTest.php`

Estimated scope: Medium.

#### Task 5: Add reschedule action without rescheduled status

Description: Move rescheduling out of status transitions. A dedicated action changes `scheduled_at`, validates through the scheduler, writes audit metadata, creates SMS, and sets the final status based on who initiated the change.

Acceptance criteria:

- [x] Customer reschedule changes date/time and sets status to `pending`.
- [x] Staff reschedule of a confirmed appointment can keep status `confirmed`.
- [x] Reschedule creates `appointment_rescheduled` SMS record.
- [x] Reschedule audit metadata includes old and new scheduled time.

Verification:

- [x] Run `vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentRescheduleTest.php`.
- [x] Run `vendor/bin/sail artisan test --compact --filter=AppointmentReschedule`.
- [x] Run `vendor/bin/sail bin pint --dirty --format agent`.

Dependencies: Tasks 3 and 4.

Files likely touched:

- `app/Actions/Appointments/RescheduleAppointment.php`
- `app/Http/Controllers/Api/AppointmentController.php`
- `app/Http/Requests/Api/RescheduleAppointmentRequest.php`
- `tests/Feature/Api/AppointmentRescheduleTest.php`
- `tests/Feature/AppointmentSmsMessageTest.php`

Estimated scope: Medium.

#### Checkpoint: Domain

- [ ] All appointment status and scheduling tests pass.
- [ ] No production code still uses `statusName: 'rescheduled'`.
- [x] Rescheduling works as an action, not as a status transition.

### Phase C: API Workflows

#### Task 6: Update customer appointment API

Description: Make customer booking use the central scheduler and set the correct source/status defaults.

Acceptance criteria:

- [ ] `POST /api/appointments` creates `source = mobile_app`.
- [ ] Customer bookings start as `pending`.
- [ ] Booking rejects outside-hours, closed-day, and optometrist conflict violations.
- [ ] Appointment resource includes source and assigned optometrist.

Verification:

- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentBookingTest.php`.
- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/AppointmentStaffAssignmentTest.php`.
- [ ] Run `vendor/bin/sail bin pint --dirty --format agent`.

Dependencies: Tasks 3 and 4.

Files likely touched:

- `app/Http/Controllers/Api/AppointmentController.php`
- `app/Http/Requests/Api/StoreAppointmentRequest.php`
- `app/Http/Resources/AppointmentResource.php`
- `tests/Feature/Api/AppointmentBookingTest.php`
- `tests/Feature/AppointmentStaffAssignmentTest.php`

Estimated scope: Medium.

#### Task 7: Add optional availability endpoint for mobile booking

Description: Add a small endpoint that returns available slots for a date and visit reason, optionally filtered by optometrist. This supports a future mobile slot picker while keeping current booking endpoints stable.

Acceptance criteria:

- [ ] Endpoint returns only slots inside clinic hours.
- [ ] Endpoint excludes slots conflicting with the selected optometrist.
- [ ] Endpoint can show availability when no optometrist is selected.

Verification:

- [ ] Run `vendor/bin/sail artisan test --compact --filter=AppointmentAvailability`.
- [ ] Run `vendor/bin/sail artisan route:list --path=appointments --except-vendor`.
- [ ] Run `vendor/bin/sail bin pint --dirty --format agent`.

Dependencies: Task 3.

Files likely touched:

- `routes/api.php`
- `app/Http/Controllers/Api/AppointmentAvailabilityController.php`
- `app/Actions/Appointments/ListAvailableAppointmentSlots.php`
- `tests/Feature/Api/AppointmentAvailabilityTest.php`

Estimated scope: Medium.

#### Checkpoint: API

- [ ] Customer booking/reschedule tests pass.
- [ ] Appointment API remains backward-compatible except for documented additive fields.
- [ ] Availability endpoint is route-listed and tested if included in this implementation pass.

### Phase D: Filament Staff Workflow

#### Task 8: Update appointment form for source and optometrist assignment

Description: Update the Filament appointment form to expose source and optometrist assignment while keeping the form simple for staff.

Acceptance criteria:

- [ ] Appointment form includes source and assigned optometrist.
- [ ] Optometrist selector lists only staff/admin users with `is_optometrist = true`.
- [ ] Staff-created scheduled appointments default to `confirmed`.
- [ ] Existing staff assignment remains separate from optometrist assignment.

Verification:

- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php`.
- [ ] Run `vendor/bin/sail bin pint --dirty --format agent`.

Dependencies: Tasks 1, 2, 3, and 4.

Files likely touched:

- `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`
- `app/Filament/Resources/Appointments/Pages/CreateAppointment.php`
- `tests/Feature/Filament/AppointmentResourceTest.php`

Estimated scope: Medium.

#### Task 9: Add walk-in queue workflow

Description: Add a staff-facing walk-in path that creates same-day `arrived` appointments and makes them easy to find from the appointment list.

Acceptance criteria:

- [ ] Staff can add a walk-in with patient, phone, visit reason, and optional optometrist.
- [ ] Walk-ins are created with `source = walk_in` and `status = arrived`.
- [ ] Appointment table can filter/show today's walk-in queue.

Verification:

- [ ] Run `vendor/bin/sail artisan test --compact --filter=WalkIn`.
- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php`.
- [ ] Run `vendor/bin/sail bin pint --dirty --format agent`.

Dependencies: Tasks 3, 4, and 8.

Files likely touched:

- `app/Filament/Resources/Appointments/Pages/ListAppointments.php`
- `app/Filament/Resources/Appointments/Tables/AppointmentsTable.php`
- `app/Actions/Appointments/CreateWalkInAppointment.php`
- `tests/Feature/Filament/AppointmentResourceTest.php`

Estimated scope: Medium.

#### Task 10: Update Filament appointment actions, table, and calendar

Description: Replace old rescheduled-status UI with the new lifecycle actions and optometrist-aware display/filtering.

Acceptance criteria:

- [ ] Table actions support confirm, mark arrived, complete, no-show, cancel, and reschedule.
- [ ] Reschedule action uses `RescheduleAppointment`, not `UpdateAppointmentStatus` with `rescheduled`.
- [ ] Calendar drag-reschedule uses the central scheduler.
- [ ] Tables/calendar show assigned optometrist.

Verification:

- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php`.
- [ ] Run `vendor/bin/sail artisan test --compact --filter=AppointmentCalendar`.
- [ ] Run `vendor/bin/sail bin pint --dirty --format agent`.

Dependencies: Tasks 3, 4, 5, and 8.

Files likely touched:

- `app/Filament/Resources/Appointments/Tables/AppointmentsTable.php`
- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `app/Filament/Resources/Appointments/Widgets/AppointmentCalendarWidget.php`
- `tests/Feature/Filament/AppointmentResourceTest.php`

Estimated scope: Medium.

#### Checkpoint: Filament

- [ ] Staff can demo app booking confirmation, walk-in creation, arrival/completion, no-show, and optometrist assignment.
- [ ] No visible appointment UI offers `rescheduled` as a status.
- [ ] Filament appointment tests pass.

### Phase E: Cleanup and Presentation Readiness

#### Task 11: Clean up remaining rescheduled references

Description: Update widgets, relation managers, notifications, SMS log display, reports, tests, and context that still assume `rescheduled` is a current status.

Acceptance criteria:

- [ ] `rg "rescheduled"` returns only reschedule event/SMS/history references, not status-flow references.
- [ ] Dashboard/today widgets use the new active statuses.
- [ ] Reports and relation managers display new status colors/labels.

Verification:

- [ ] Run `rg "rescheduled" app database tests docs/BACKEND_CONTEXT.md`.
- [ ] Run `vendor/bin/sail artisan test --compact --filter=Appointment`.
- [ ] Run `vendor/bin/sail bin pint --dirty --format agent`.

Dependencies: Tasks 4, 5, and 10.

Files likely touched:

- `app/Filament/Widgets/TodaysScheduleWidget.php`
- `app/Filament/Resources/VisitReasons/RelationManagers/AppointmentsRelationManager.php`
- `app/Notifications/AppointmentStatusChanged.php`
- `app/Filament/Resources/SmsNotifications/Tables/SmsNotificationsTable.php`
- Relevant appointment/widget tests.

Estimated scope: Medium.

#### Task 12: Register reminder scheduling

Description: Make appointment reminders operational by registering the existing command in Laravel's scheduler.

Acceptance criteria:

- [ ] `appointments:send-reminders` is scheduled.
- [ ] Scheduled command uses `withoutOverlapping()`.
- [ ] Existing reminder command behavior remains unchanged.

Verification:

- [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/AppointmentReminderTest.php`.
- [ ] Run `vendor/bin/sail bin pint --dirty --format agent`.

Dependencies: None, but best done after appointment status cleanup.

Files likely touched:

- `routes/console.php`
- `tests/Feature/AppointmentReminderTest.php`

Estimated scope: Small.

#### Task 13: Update backend context after implementation

Description: Update the global backend context document only after implementation is complete, so future sessions see the real current system rather than the planning state.

Acceptance criteria:

- [ ] `docs/BACKEND_CONTEXT.md` lists the final appointment statuses.
- [ ] It documents appointment source, optometrist assignment, walk-in queue, and reschedule-as-action.
- [ ] It preserves the billing explanation that appointments are encounter grouping points, not automatic invoices.

Verification:

- [ ] Review `docs/BACKEND_CONTEXT.md` for consistency with implemented schema and tests.
- [ ] Run `rg "rescheduled" docs/BACKEND_CONTEXT.md` and confirm remaining references only describe SMS/event history if needed.

Dependencies: Tasks 1-12.

Files likely touched:

- `docs/BACKEND_CONTEXT.md`

Estimated scope: Small.

#### Checkpoint: Complete

- [ ] Run `vendor/bin/sail artisan test --compact --filter=Appointment`.
- [ ] Run any touched non-appointment tests, especially user, feedback, billing, and notification tests.
- [ ] Run `vendor/bin/sail bin pint --dirty --format agent`.
- [ ] Confirm the capstone demo path works: app booking pending, staff confirm, assign optometrist, add walk-in, mark arrived/completed, mark no-show, prevent same-optometrist double booking.

## Plan Approval Gate

Do not implement until this plan is reviewed and approved.
