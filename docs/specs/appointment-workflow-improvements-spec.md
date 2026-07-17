# Spec: Appointment Workflow Improvements

## Assumptions

1. Filament admin/staff appointment workflows are the primary scope; Android API behavior changes only where appointment notifications or status rules require it.
2. The current encounter billing model remains valid: appointments are grouping points, and billings are invoices that may optionally link to an appointment.
3. Staff-created scheduled appointments should start as `confirmed`; app-created appointments should remain `pending`.
4. Walk-ins should always have `source = walk_in`; existing null walk-in-like records may need a safe backfill only if the database contains them.
5. Staff may reschedule `pending` and `confirmed` appointments; customer-facing notification should be created by the existing `RescheduleAppointment` action.
6. Staff rescheduling should capture a customer-readable reason so app users understand why their appointment changed.
7. We should not add dependencies. A schema change is allowed for storing the latest reschedule reason because the user approved proceeding after that recommendation.

## Objective

Fix appointment creation/filtering defects and make appointment, billing, cancellation, rescheduling, and notification rules explicit enough that staff workflows behave predictably.

Users:

- Staff/admin using the Filament panel.
- Customers booking through the Android app, indirectly affected by notifications and reschedules.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5
- Livewire 4
- Laravel Sanctum 4
- MySQL via Laravel Sail
- Pest 4 / PHPUnit 12
- Laravel Pint 1

## Commands

- Start services: `vendor/bin/sail up -d`
- Run focused appointment tests: `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php`
- Run focused billing tests: `vendor/bin/sail artisan test --compact tests/Feature/Filament/BillingResourceTest.php tests/Feature/Filament/CreateBillingTest.php tests/Feature/GetOrCreateBillingTest.php`
- Run full test suite if touched behavior is broad: `vendor/bin/sail artisan test --compact`
- Format dirty PHP files: `vendor/bin/sail bin pint --dirty --format agent`

## Project Structure

- `app/Filament/Resources/Appointments/` — appointment panel pages, forms, tables, relation managers.
- `app/Actions/Appointments/` — single-purpose appointment workflow actions.
- `app/Actions/Billing/` — billing creation and mutation workflow actions.
- `app/Notifications/` — database notification classes.
- `app/Http/Controllers/Api/` — mobile/staff API appointment endpoints.
- `tests/Feature/Filament/AppointmentResourceTest.php` — Filament appointment workflow tests.
- `tests/Feature/Api/*Appointment*Test.php` — API appointment workflow tests.
- `tests/Feature/Notifications/` — notification behavior tests.
- `docs/BACKEND_CONTEXT.md` — living backend context to update if decisions change.

## Code Style

Follow existing Laravel/Filament conventions: typed methods, descriptive names, actions for workflow side effects, and no direct lifecycle mutation where an action exists.

```php
app(RescheduleAppointment::class)->handle(
    appointment: $appointment,
    scheduledAt: AppointmentTime::combine(
        $data['scheduled_at'],
        $data['appointment_time'],
    ),
    customerInitiated: false,
);
```

## Product Decisions

### Staff-created appointment creation

Staff-created scheduled appointments must create a persisted appointment when the Filament create action is submitted with valid data.

Acceptance:

- Valid staff create form submission creates an appointment.
- The created appointment has `source` from the form, defaulting to `staff_created`.
- The created appointment has `created_by` set to the authenticated staff/admin user.
- Scheduling validation errors are shown as form/action errors, not silent failures.

### Walk-in booking source

Walk-ins created from the `Add Walk-in` action must always store and display `source = walk_in`.

Acceptance:

- New walk-ins created through `CreateWalkInAppointment` persist `source = walk_in`.
- The appointment table source column renders walk-ins as `Walk-in`.
- If existing records are discovered with a missing source and walk-in semantics, decide whether to backfill them in a migration.

### Today's walk-in filter

The "Today's walk-ins" filter should show today's active walk-in queue.

Acceptance:

- Shows today's `walk_in` appointments in `arrived` status.
- Does not show scheduled non-walk-ins.
- Does not show completed, cancelled, no-show, archived, or non-today walk-ins.
- Works regardless of the active status tab, or the UI clearly communicates that status tabs further constrain the filter.

### Arrived appointment cancellation

Default decision: keep `arrived → cancelled` allowed.

Reasoning: an arrived patient can still leave before service is completed, so cancellation is valid if the appointment has not reached `completed`.

Acceptance:

- Staff/admin can cancel `pending`, `confirmed`, and `arrived`.
- Customers can cancel only `pending` and `confirmed` through the API.
- Cancellation creates SMS/database notification records where the current action already does so.

### Appointment and billing relationship

Default decision: keep `Appointment hasMany Billing`.

Reasoning: appointments are encounter grouping points; billings are invoices. Multiple invoices may exist over time due to void/reissue, manual standalone billing, or exceptional billing splits. The relation manager is useful as a read-only navigation surface from the appointment.

Acceptance:

- Appointment edit page shows linked billings read-only.
- Creating billings remains primarily through the Billings resource.
- No automatic billing is created merely by completing an appointment.
- Optional future improvement: add a "Create Billing" shortcut from an appointment only if it routes through the same billing creation action and preserves billing rules.

### Billing creation from appointment

Default decision: do not add appointment edit billing creation in this pass.

Reasoning: the current documented workflow says staff creates standalone billings from the Billings resource, then adds services on the billing page. Adding a second creation surface risks duplicating validation and confusing invoice ownership unless explicitly designed.

Acceptance:

- Billings can still be created manually with optional `appointment_id`.
- Orders reaching `processing` still generate or attach to appointment-linked billings through `GetOrCreateBilling`.
- Appointment page remains a linked-record view, not a billing mutation surface.

### Multiple billings per appointment

Default decision: support multiple billings, but prefer one active non-voided billing per customer+appointment through `GetOrCreateBilling`.

Acceptance:

- Existing non-voided billing for customer+appointment is reused by order/service billing actions.
- Voided billings remain visible in the relation manager for audit/history.
- The UI does not imply that only one billing can ever exist.

### Staff rescheduling pending appointments

Default decision: staff may reschedule `pending` appointments.

Acceptance:

- Staff rescheduling a `pending` appointment preserves `pending`.
- Staff rescheduling a `confirmed` appointment preserves `confirmed`.
- Staff reschedules require a customer-readable reason.
- The latest staff-entered reason is stored on the appointment for API/mobile display.
- Rescheduling creates customer-facing SMS/database notification through `RescheduleAppointment`.
- Customer-facing reschedule notifications include the reason.
- Mobile app users see updated appointment details through `GET /appointments`.

### Billing relation visibility on pending/confirmed appointments

Default decision: show the Billings relation manager on all appointment statuses if billings exist.

Reasoning: a billing can be manually linked before arrival, or an order may be attached to the appointment before the visit completes. Hiding the relation by status would obscure real linked financial records.

Acceptance:

- Pending and confirmed appointments may show billings if they exist.
- Empty relation state should not suggest billing is required.

### Staff/admin notification on app booking

Default decision: staff/admin should be notified when a customer books through the app.

Reasoning: app-created appointments start `pending`, so operational staff need a queue signal to confirm or handle the request.

Acceptance:

- Mobile booking creates a database notification for staff/admin recipients.
- Notification content identifies patient, appointment number, date/time, and visit reason.
- No SMS to staff/admin is required unless separately requested.

## Testing Strategy

- Use Pest feature tests.
- Prefer existing Filament and API test files; do not create verification scripts.
- Add regression tests for each fixed bug before or alongside implementation:
  - Staff create persists appointment and shows success.
  - Staff create records `created_by` and `source`.
  - Walk-in action records `source = walk_in`.
  - Today's walk-in filter shows the intended records under relevant tabs/filter combinations.
  - Staff rescheduling pending/confirmed appointments requires and persists a reason.
  - Appointment API responses expose the latest reschedule reason.
  - If notification behavior is changed, assert database notifications for staff/admin on mobile booking.
- Run the narrowest affected tests first, then broader tests if shared actions change.

## Boundaries

- Always:
  - Use existing action classes for appointment status and rescheduling side effects.
  - Run affected Pest tests.
  - Run Pint after PHP changes.
  - Update `docs/BACKEND_CONTEXT.md` if a documented rule changes.
- Ask first:
  - Database schema changes or data backfill migrations.
  - Adding appointment-page billing creation shortcuts.
  - Changing allowed status transitions.
  - Changing customer notification channels.
- Never:
  - Mutate appointment status directly outside `UpdateAppointmentStatus`.
  - Reschedule by directly editing `scheduled_at` outside `RescheduleAppointment`.
  - Remove existing tests without approval.
  - Add dependencies without approval.

## Success Criteria

- The reported staff create button issue is reproduced or covered by a regression test and fixed.
- Walk-in appointments consistently have a booking source.
- The "Today's walk-ins" filter shows active same-day walk-ins.
- Staff reschedules of pending/confirmed appointments collect a reason and expose it to the customer.
- Product decisions above are reflected in tests and, if changed, in `docs/BACKEND_CONTEXT.md`.
- Focused affected tests pass.
- Dirty PHP files are formatted with Pint.

## Resolved Decisions

1. Keep `arrived → cancelled` allowed for staff/admin.
2. Do not add an appointment-page "Create Billing" shortcut in this pass.
3. Keep appointment billings read-only from the appointment page.
4. Support multiple billings per appointment, while normal billing actions reuse one active non-voided billing.
5. Staff may reschedule `pending` appointments.
6. Staff rescheduling requires a reason visible to the customer.
7. Show billing relation on pending and confirmed appointments when billings exist.
8. Notify staff/admin via database notification when a customer books through the app; no staff SMS/email for now.

## Phase 2 Plan

### Components and dependencies

1. **Appointment schema/model/API resource**
   - Add nullable `last_reschedule_reason` to `appointments`.
   - Add the field to `Appointment` fillable attributes.
   - Return the field in `AppointmentResource` so Android can show the reason.
   - Dependency: migration must exist before tests can assert persistence.

2. **Reschedule workflow action**
   - Extend `RescheduleAppointment` with an optional reason parameter.
   - Persist the reason for staff-initiated reschedules.
   - Include the reason in notification/audit metadata and SMS text where applicable.
   - Dependency: schema/model change.

3. **Filament appointment reschedule UI**
   - Add required reason input to edit-page and table-row reschedule actions.
   - Pass the reason into `RescheduleAppointment`.
   - Dependency: reschedule action accepts reason.

4. **Appointment creation and walk-in fixes**
   - Reproduce the create button issue with a regression test if current coverage misses the actual failure path.
   - Ensure staff scheduled creation reliably sets `created_by` and defaults source.
   - Ensure walk-in source displays and persists consistently.
   - Dependency: can be done independently of reschedule reason.

5. **Today's walk-in filter**
   - Adjust filter/tab interaction so "Today's walk-ins" works from the user’s expected entry point.
   - Preferred implementation: make the filter itself constrain records to today + `source = walk_in` + `arrived`, and add coverage for the interaction that currently fails.
   - Dependency: existing table tests.

6. **Mobile booking staff notification**
   - Existing `AppointmentController::store` already sends Filament database notifications to staff/admin, and `StaffNotificationTest` covers it.
   - Preserve this behavior while changing appointment resources/rescheduling.
   - Dependency: rerun existing notification/API booking tests after appointment resource changes.

7. **Documentation/context update**
   - Update `docs/BACKEND_CONTEXT.md` with any changed persisted field or workflow rule.
   - Dependency: final implementation choices.

### Implementation order

1. Add failing tests for the bug reports and approved reschedule reason behavior.
2. Implement `last_reschedule_reason` persistence and API exposure.
3. Wire Filament reschedule forms to require/pass the reason.
4. Fix appointment creation/source/filter defects covered by tests.
5. Preserve existing staff/admin app-booking database notification behavior.
6. Update backend context.
7. Run focused tests and Pint.

### Risks and mitigations

- **Schema change risk:** Adding a nullable column is low-risk, but it affects migrations and model/API output. Mitigate with focused migration/resource tests and no destructive data changes.
- **Notification duplication:** App booking may already notify staff/admin. Mitigate by testing expected count/recipient behavior before adding notification dispatch.
- **SMS wording drift:** Existing SMS tests may assert exact messages. Mitigate by updating tests only for the new reason-bearing reschedule behavior.
- **Tab/filter interaction ambiguity:** Filament tabs and table filters naturally combine. Mitigate by explicitly testing the current failing interaction and choosing the smallest predictable behavior.

### Parallelization

- Tests for appointment creation/filtering and reschedule reason can be written in parallel conceptually, but implementation should be sequential because shared appointment tests may overlap.
- Billing relation decisions require no implementation in this pass unless tests reveal a regression.

### Verification checkpoints

1. After tests are added: confirm at least one new test fails for unimplemented behavior.
2. After migration/model/resource changes: run API appointment tests touching resources.
3. After Filament reschedule changes: run `AppointmentResourceTest`.
4. After appointment resource or booking-adjacent changes: run notification/API booking tests.
5. Before final handoff: run Pint and focused affected tests.

## Phase 3 Tasks

### Phase 1: High-risk regression coverage

#### Task 1: Prove current appointment create/walk-in behavior with focused tests

**Description:** Tighten existing Filament appointment tests around the reported create button/source/filter defects before changing implementation.

**Acceptance criteria:**

- [x] Staff create test asserts `created_by`, selected/default `source`, and persisted schedule/status.
- [x] Walk-in test asserts `source = walk_in` and source column displays `Walk-in`.
- [x] Today's walk-in filter test covers the failing interaction, including active tab behavior if that is the root cause.

**Verification:**

- [x] Run: `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php`
- [x] At least one added assertion/test should fail if the reported bug is currently reproducible.

**Result:** Red as expected. `WalkIn queue filter shows todays waiting patients even after another status tab was active` fails because the active `confirmed` tab continues to constrain the table while the walk-in filter is enabled.

**Dependencies:** None.

**Files likely touched:**

- `tests/Feature/Filament/AppointmentResourceTest.php`

**Estimated scope:** S.

#### Task 2: Add failing tests for staff reschedule reason behavior

**Description:** Define expected behavior for staff rescheduling pending/confirmed appointments with a customer-readable reason.

**Acceptance criteria:**

- [x] Staff rescheduling `pending` stores `last_reschedule_reason` and keeps status `pending`.
- [x] Staff rescheduling `confirmed` stores `last_reschedule_reason` and keeps status `confirmed`.
- [x] Customer-initiated reschedule does not require a reason and does not accidentally set a staff reason.
- [x] Customer appointment API response includes `last_reschedule_reason`.

**Verification:**

- [x] Run: `vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentRescheduleTest.php`
- [x] New tests fail before implementation because the column/field does not exist yet.

**Result:** Red as expected. Failures are limited to missing `rescheduleReason` support and missing `appointments.last_reschedule_reason`.

**Dependencies:** None.

**Files likely touched:**

- `tests/Feature/Api/AppointmentRescheduleTest.php`
- `tests/Feature/Filament/AppointmentResourceTest.php`
- `tests/Feature/Api/AppointmentBookingTest.php` if API resource coverage is more appropriate there.

**Estimated scope:** M.

### Checkpoint: Regression contract

- [ ] New/updated tests describe all intended behavior.
- [ ] No production code has been changed yet.
- [ ] Failing tests are understood and map to implementation tasks.

### Phase 2: Reschedule reason vertical slice

#### Task 3: Add appointment storage/API support for latest reschedule reason

**Description:** Add the nullable appointment field and expose it through the customer appointment API.

**Acceptance criteria:**

- [x] `appointments.last_reschedule_reason` nullable text/string column exists.
- [x] `Appointment` allows the field for mass assignment.
- [x] `AppointmentResource` returns `last_reschedule_reason`.
- [x] Existing appointment API responses remain backward-compatible except for the additive field.

**Verification:**

- [x] Run: `vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentBookingTest.php tests/Feature/Api/AppointmentRescheduleTest.php`

**Result:** Storage/API support is in place. Remaining failures are expected Task 4 failures for the unimplemented `rescheduleReason` action parameter.

**Dependencies:** Task 2.

**Files likely touched:**

- `database/migrations/*_add_last_reschedule_reason_to_appointments_table.php`
- `app/Models/Appointment.php`
- `app/Http/Resources/AppointmentResource.php`
- API tests touched in Task 2.

**Estimated scope:** M.

#### Task 4: Persist and communicate staff reschedule reasons through the action

**Description:** Extend `RescheduleAppointment` so staff reschedules can include a reason that is stored, included in SMS, and recorded in audit metadata.

**Acceptance criteria:**

- [x] `RescheduleAppointment::handle()` accepts an optional `rescheduleReason`.
- [x] Staff-initiated reschedules persist a trimmed reason to `last_reschedule_reason`.
- [x] Customer-initiated reschedules preserve current behavior and reset status to `pending`.
- [x] Reschedule SMS includes the reason when one is provided.
- [x] Audit metadata includes the reason when one is provided.

**Verification:**

- [x] Run: `vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentRescheduleTest.php tests/Feature/AppointmentSmsMessageTest.php`

**Result:** Passed, 26 tests / 68 assertions.

**Dependencies:** Task 3.

**Files likely touched:**

- `app/Actions/Appointments/RescheduleAppointment.php`
- `tests/Feature/Api/AppointmentRescheduleTest.php`
- `tests/Feature/AppointmentSmsMessageTest.php`

**Estimated scope:** M.

#### Task 5: Require reason in Filament staff reschedule actions

**Description:** Add a required reason field to both staff reschedule entry points in the Filament appointment UI and pass it to the action.

**Acceptance criteria:**

- [x] Edit page reschedule action requires `reschedule_reason`.
- [x] Table row reschedule action requires `reschedule_reason`.
- [x] Both actions pass the reason to `RescheduleAppointment`.
- [x] Existing status-preservation behavior remains intact.

**Verification:**

- [x] Run: `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php --filter=reschedule`

**Result:** Passed, 12 tests / 48 assertions. Full `AppointmentResourceTest` still has the known Task 7 walk-in filter failure.

**Dependencies:** Task 4.

**Files likely touched:**

- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `app/Filament/Resources/Appointments/Tables/AppointmentsTable.php`
- `tests/Feature/Filament/AppointmentResourceTest.php`

**Estimated scope:** M.

### Checkpoint: Reschedule reason slice

- [ ] Staff can reschedule pending/confirmed only with a reason.
- [ ] Customers can see the latest reason via API.
- [ ] SMS/audit behavior is covered.
- [ ] Appointment API and Filament appointment tests pass.

### Phase 3: Appointment create/source/filter fixes

#### Task 6: Fix staff appointment creation reliability and source defaults

**Description:** Apply the smallest change required by Task 1 tests so staff-created scheduled appointments persist consistently with correct audit/source fields.

**Acceptance criteria:**

- [x] Create action persists a valid appointment from the Filament form.
- [x] `created_by` is reliably set to the authenticated staff/admin.
- [x] `source` defaults to `staff_created` when omitted and preserves selected values when provided.
- [x] Scheduling validation failures are visible through form/action errors.

**Verification:**

- [x] Run: `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php --filter="staff can create"`

**Result:** Passed, 2 tests / 17 assertions. Strengthened tests showed existing implementation already satisfies this task, so no production change was needed.

**Dependencies:** Task 1.

**Files likely touched:**

- `app/Filament/Resources/Appointments/Pages/CreateAppointment.php`
- `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`
- `tests/Feature/Filament/AppointmentResourceTest.php`

**Estimated scope:** M.

#### Task 7: Fix today's walk-in queue filter behavior

**Description:** Make the "Today's walk-ins" filter show the active same-day walk-in queue in the scenario that currently shows nothing.

**Acceptance criteria:**

- [x] Filter shows today’s `source = walk_in` + `arrived` appointments.
- [x] Filter excludes non-walk-ins, non-today walk-ins, completed/cancelled/no-show walk-ins, and archived records.
- [x] If tabs still combine with filters, tests document the expected combination; if not, implementation intentionally overrides or resets tab interaction.

**Verification:**

- [x] Run: `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php`

**Result:** Passed, 41 tests / 212 assertions. When the walk-in queue filter is active, status tabs no longer additionally constrain the query.

**Dependencies:** Task 1.

**Files likely touched:**

- `app/Filament/Resources/Appointments/Tables/AppointmentsTable.php`
- `app/Filament/Resources/Appointments/Pages/ListAppointments.php` only if tab interaction needs explicit handling.
- `tests/Feature/Filament/AppointmentResourceTest.php`

**Estimated scope:** S-M.

### Checkpoint: Appointment operations

- [ ] Staff scheduled creation works.
- [ ] Walk-ins have/display booking source.
- [ ] Today’s walk-in filter works in the reported path.
- [ ] `AppointmentResourceTest` passes.

### Phase 4: Preserve notifications and document final behavior

#### Task 8: Preserve mobile booking staff/admin notification behavior

**Description:** Run and adjust only if necessary the existing notification tests to ensure app bookings still notify staff/admin after appointment resource changes.

**Acceptance criteria:**

- [x] Customer app booking creates one pending appointment.
- [x] Staff and admin receive database notifications.
- [x] No staff SMS/email channel is introduced.

**Verification:**

- [x] Run: `vendor/bin/sail artisan test --compact tests/Feature/Notifications/StaffNotificationTest.php tests/Feature/Api/AppointmentBookingTest.php`

**Result:** Passed, 18 tests / 61 assertions.

**Dependencies:** Tasks 3 and 6.

**Files likely touched:**

- Ideally none beyond tests if API response expectations need the additive field.
- `app/Http/Controllers/Api/AppointmentController.php` only if tests reveal a regression.

**Estimated scope:** XS-S.

#### Task 9: Update backend context and final verification

**Description:** Update the living backend context with the new reschedule reason rule and any actual implementation choices, then run formatting/tests.

**Acceptance criteria:**

- [x] `docs/BACKEND_CONTEXT.md` documents `appointments.last_reschedule_reason`.
- [x] Rescheduling section documents required staff reason and customer visibility.
- [x] Dirty PHP files are formatted.
- [x] Focused affected tests pass.

**Verification:**

- [x] Run: `vendor/bin/sail bin pint --dirty --format agent`
- [x] Run: `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Api/AppointmentRescheduleTest.php tests/Feature/Api/AppointmentBookingTest.php tests/Feature/Notifications/StaffNotificationTest.php tests/Feature/AppointmentSmsMessageTest.php`

**Result:** Pint passed. Focused affected tests passed, 85 tests / 341 assertions.

**Dependencies:** Tasks 3-8.

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`
- PHP files already touched by implementation, via Pint formatting only.

**Estimated scope:** S.

## Dependency Graph

```text
Task 1 regression tests ─┬─> Task 6 create/source fix
                         └─> Task 7 walk-in filter fix

Task 2 reschedule tests ─> Task 3 schema/model/API
                         └─> Task 4 action persistence/SMS/audit
                             └─> Task 5 Filament reason UI

Tasks 3 + 6 ─> Task 8 notification preservation
Tasks 3-8 ──> Task 9 context + final verification
```

## Parallelization Opportunities

- Task 1 and Task 2 can be prepared independently because they touch different behavior areas, though both may edit `AppointmentResourceTest` and should be coordinated.
- Task 6 and Task 7 can be implemented independently after Task 1.
- Tasks 3-5 must be sequential because schema/API/action/UI depend on each other.
- Task 9 must be last.

## Open Questions

- None blocking. If Task 1 reveals existing null-source walk-in records in the development database, ask before adding a data backfill migration.
