# Tasks: Appointment Lifecycle and Availability Management

## Status

Phase 3 checkbox task breakdown for the approved:

- `appointment-lifecycle-and-availability-spec.md`
- `appointment-lifecycle-and-availability-plan.md`

The project owner must approve this task breakdown before Phase 4
implementation begins. No application code is authorized by this document
alone.

## Execution Rules

- Implement tasks in dependency order.
- Use test-driven and incremental implementation for every behavior change.
- Search version-specific Laravel/Filament documentation before PHP changes.
- Use Laravel Sail for PHP, Artisan, Composer, and Node commands.
- Use existing factories and action conventions.
- Do not add dependencies.
- Do not run destructive fresh-database commands against non-disposable data.
- Run the focused verification listed for each task.
- Run Pint after each task that modifies PHP.
- Update this file's checkboxes only after the corresponding verification
  passes.
- Stop at each checkpoint when the user requests incremental phase approval.

## Overall Tracker

### Phase A — Lifecycle foundation

- [x] Task 1: Establish the appointment status vocabulary
- [ ] Task 2: Add appointment lifecycle metadata and reschedule history
- [ ] Task 3: Reconcile planned encounters and one-to-one cardinality

### Phase B — Creation and clinical start

- [ ] Task 4: Create scheduled appointments with the new lifecycle
- [ ] Task 5: Make check-in idempotently create one planned encounter
- [ ] Task 6: Start and complete encounters through authorized actions

### Phase C — Terminal and schedule-change workflows

- [ ] Task 7: Cancel appointments and mark no-shows explicitly
- [ ] Task 8: Persist immutable reschedule history

### Phase D — Patient API contract

- [ ] Task 9: Publish the patient-safe appointment representation
- [ ] Task 10: Reconcile patient cancel and reschedule mutations

### Phase E — Authoritative availability

- [ ] Task 11: Resolve optometrist eligibility for exact intervals
- [ ] Task 12: Normalize schedule override behavior
- [ ] Task 13: Preview appointments affected by availability changes

### Phase F — Availability management page

- [ ] Task 14: Manage clinic and provider recurring hours
- [ ] Task 15: Manage schedule exceptions with conflict confirmation

### Phase G — Filament workflow surfaces

- [ ] Task 16: Make appointment pages action-driven
- [ ] Task 17: Reconcile appointment calendar and dashboard surfaces
- [ ] Task 18: Make encounter pages action-driven

### Phase H — Cross-cutting reconciliation and release

- [ ] Task 19: Reconcile notifications, reminders, and SMS terminology
- [ ] Task 20: Reconcile reports, feedback eligibility, and complaint restart
- [ ] Task 21: Remove the temporary lifecycle transition bridge
- [ ] Task 22: Finalize documentation and release evidence

---

## Phase A — Lifecycle Foundation

## Task 1: Establish the appointment status vocabulary

**Description:** Introduce the centralized appointment status enum and make the
five approved states available to new code. Obsolete lookup rows remain only as
a temporary implementation bridge until all consumers are migrated in Task 21,
so intermediate checkpoints remain runnable.

**Acceptance criteria:**

- [x] One backed enum defines `scheduled`, `checked_in`, `fulfilled`,
  `cancelled`, and `no_show`, including Filament-friendly labels/colors.
- [x] The appointment status seeder guarantees those five values while clearly
  marking obsolete rows as a temporary bridge scheduled for removal in Task
  21.
- [x] The default appointment factory produces a `scheduled` appointment and
  provides explicit state helpers for terminal states.

**Verification:**

- [x] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Seeders/CanonicalSeederTest.php tests/Feature/AppointmentModelTest.php`
- [x] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`
- [x] Static seed scan finds no obsolete values in the appointment status
  seeder or factory.

**Dependencies:** None.

**Files likely touched:**

- `app/Enums/AppointmentStatusName.php`
- `database/seeders/AppointmentStatusSeeder.php`
- `database/factories/AppointmentFactory.php`
- `tests/Feature/Seeders/CanonicalSeederTest.php`
- `tests/Feature/AppointmentModelTest.php`

**Estimated scope:** Medium — 5 files.

## Task 2: Add appointment lifecycle metadata and reschedule history

**Description:** Make the canonical appointment schema represent fulfillment,
cancellation, no-show, and immutable rescheduling without overloaded notes or
the obsolete latest-reason column.

**Acceptance criteria:**

- [ ] The canonical appointment migration uses `fulfilled_at`, cancellation
  metadata, and no-show metadata, and no longer creates `completed_at` or
  `last_reschedule_reason`.
- [ ] A canonical `appointment_reschedules` table stores old/new times,
  initiator, actor, patient-readable reason, and event time.
- [ ] Appointment relationships and casts expose lifecycle metadata and
  ordered reschedule history correctly.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/AppointmentModelTest.php tests/Feature/AppointmentRescheduleModelTest.php`
- [ ] Migration status can be inspected:
  `vendor/bin/sail artisan migrate:status`
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 1.

**Files likely touched:**

- `database/migrations/2026_06_06_020917_create_appointments_table.php`
- `database/migrations/*_create_appointment_reschedules_table.php`
- `app/Models/Appointment.php`
- `app/Models/AppointmentReschedule.php`
- `tests/Feature/AppointmentRescheduleModelTest.php`

**Estimated scope:** Medium — 5 files.

## Task 3: Reconcile planned encounters and one-to-one cardinality

**Description:** Introduce encounter `planned`, require an appointment for
every encounter, and align Eloquent with the unique appointment foreign key
already enforced by the database. The old enum case remains only until Task 21
so untouched consumers continue to compile during incremental migration.

**Acceptance criteria:**

- [ ] Encounter status provides `planned`, `in_progress`, `completed`, and
  `cancelled`, with factories defaulting to `planned`; the temporary `waiting`
  bridge is explicitly scheduled for Task 21 removal.
- [ ] The canonical encounter schema requires a unique appointment and
  restricts physical appointment deletion.
- [ ] Appointment exposes one nullable encounter through `hasOne`, and a second
  encounter for the same appointment is rejected by the database.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/AppointmentModelTest.php tests/Feature/Encounters/EncounterCheckInTest.php`
- [ ] Database schema confirms a non-null unique appointment foreign key.
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 1–2.

**Files likely touched:**

- `app/Enums/EncounterStatus.php`
- `database/migrations/2026_07_25_200000_create_encounters_table.php`
- `database/factories/EncounterFactory.php`
- `app/Models/Appointment.php`
- `tests/Feature/AppointmentModelTest.php`

**Estimated scope:** Medium — 5 files.

## Checkpoint A: Lifecycle foundation

- [ ] Tasks 1–3 are checked complete.
- [ ] Model, schema, factory, and canonical seeder tests pass.
- [ ] The approved five appointment and four encounter statuses are available
  to new code, and obsolete bridge values are confined to the documented
  Task 21 cutover.
- [ ] One appointment cannot persist two encounters.
- [ ] No application implementation proceeds past this checkpoint if schema
  assumptions differ from the approved specification.

---

## Phase B — Creation and Clinical Start

## Task 4: Create scheduled appointments with the new lifecycle

**Description:** Reconcile mobile and clinic-created scheduled bookings so
they start as `scheduled`, use the authoritative scheduler, and do not require
an optometrist or confirmation queue.

**Acceptance criteria:**

- [ ] Mobile booking creates a `scheduled` appointment with `source = mobile`.
- [ ] Filament scheduled creation creates `scheduled` with `source = manual`
  and optional optometrist assignment.
- [ ] Both entry points use the same locked availability validation and no
  Confirm action is required afterward.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentContractTest.php tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/AppointmentSchedulingTest.php`
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 1–3.

**Files likely touched:**

- `app/Actions/Appointments/CreateScheduledAppointment.php`
- `app/Filament/Resources/Appointments/Pages/CreateAppointment.php`
- `tests/Feature/Api/V1/AppointmentContractTest.php`
- `tests/Feature/Filament/AppointmentResourceTest.php`
- `tests/Feature/AppointmentSchedulingTest.php`

**Estimated scope:** Medium — 5 files.

## Task 5: Make check-in idempotently create one planned encounter

**Description:** Update the check-in transaction to accept scheduled
appointments, require no optometrist, create one planned encounter, and safely
handle duplicate delivery.

**Acceptance criteria:**

- [ ] Check-in atomically changes `scheduled → checked_in`, records actor/time,
  and creates the single `planned` encounter.
- [ ] Repeating check-in for an already checked-in appointment returns its
  existing planned encounter without duplicating audit or encounter records.
- [ ] Cancelled, no-show, and fulfilled appointments cannot be checked in.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Encounters/CheckInTransactionTest.php tests/Feature/Encounters/EncounterCheckInTest.php`
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 1–4.

**Files likely touched:**

- `app/Actions/Encounters/CheckInAppointment.php`
- `tests/Feature/Encounters/CheckInTransactionTest.php`
- `tests/Feature/Encounters/EncounterCheckInTest.php`

**Estimated scope:** Medium — 3 files.

## Task 6: Start and complete encounters through authorized actions

**Description:** Add dedicated clinical actions that assign an eligible
optometrist, atomically fulfill the appointment when a visit starts, and
complete only the encounter when clinical work ends.

**Acceptance criteria:**

- [ ] Start Visit requires optometrist capability and an eligible optometrist,
  then atomically changes appointment `checked_in → fulfilled` and encounter
  `planned → in_progress`.
- [ ] Complete Visit accepts only an `in_progress` encounter and records
  `completed_at` without changing the fulfilled appointment.
- [ ] Invalid actors, mismatched states, and unauthorized completion are
  rejected and audited appropriately.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Encounters/EncounterLifecycleTest.php`
- [ ] Transaction rollback tests prove neither record changes on failure.
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 3 and 5.

**Files likely touched:**

- `app/Actions/Encounters/StartEncounter.php`
- `app/Actions/Encounters/CompleteEncounter.php`
- `app/Enums/AuditEvent.php`
- `tests/Feature/Encounters/EncounterLifecycleTest.php`

**Estimated scope:** Medium — 4 files.

## Checkpoint B: Booking through clinical completion

- [ ] Tasks 4–6 are checked complete.
- [ ] Mobile booking, clinic booking, check-in, Start Visit, and Complete Visit
  focused suites pass.
- [ ] Check-in requires no optometrist; Start Visit requires one.
- [ ] End-to-end lifecycle transitions are atomic.

---

## Phase C — Terminal and Schedule-Change Workflows

## Task 7: Cancel appointments and mark no-shows explicitly

**Description:** Replace generic status mutation with dedicated cancellation
and no-show actions that enforce state, actor, reason, timestamp, encounter,
notification, and audit rules.

**Acceptance criteria:**

- [ ] Clinic cancellation requires an approved reason; patient cancellation
  may omit one; both record initiator, actor when present, and time.
- [ ] Cancelling `checked_in` atomically cancels its `planned` encounter, while
  an in-progress or fulfilled visit cannot be cancelled.
- [ ] No-show is available only for a scheduled appointment after its start
  time and records the staff actor/time.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentTerminationTest.php`
- [ ] Invalid transition tests assert no partial metadata or encounter changes.
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 2–6.

**Files likely touched:**

- `app/Actions/Appointments/CancelAppointment.php`
- `app/Actions/Appointments/MarkAppointmentNoShow.php`
- `app/Enums/AuditEvent.php`
- `tests/Feature/Appointments/AppointmentTerminationTest.php`

**Estimated scope:** Medium — 4 files.

## Task 8: Persist immutable reschedule history

**Description:** Extend the shared reschedule action so every successful
patient or clinic change records immutable old/new schedule history and
preserves `scheduled`.

**Acceptance criteria:**

- [ ] Rescheduling is allowed only from `scheduled` and never creates a
  rescheduled status.
- [ ] Staff changes require a category, `other` requires details, and patient
  reasons remain optional.
- [ ] Schedule mutation, history, audit, and notification side effects commit
  atomically after the authoritative slot check.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentRescheduleTest.php`
- [ ] A failed or racing reschedule preserves the original time and creates no
  history or notification.
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 2, 4, and 7.

**Files likely touched:**

- `app/Actions/Appointments/RescheduleAppointment.php`
- `app/Notifications/AppointmentRescheduled.php`
- `app/Models/AppointmentReschedule.php`
- `tests/Feature/Appointments/AppointmentRescheduleTest.php`

**Estimated scope:** Medium — 4 files.

## Checkpoint C: Terminal and reschedule integrity

- [ ] Tasks 7–8 are checked complete.
- [ ] Cancellation, no-show, and reschedule tests pass.
- [ ] No generic direct appointment status mutation is needed by completed
  domain actions.
- [ ] History and notification side effects are atomic and auditable.

---

## Phase D — Patient API Contract

## Task 9: Publish the patient-safe appointment representation

**Description:** Update the appointment API Resource and lifecycle conflict
handling to expose the exact approved Android representation without internal
IDs or staff-only data.

**Acceptance criteria:**

- [ ] Responses use the five approved statuses and include nullable
  `checked_in_at`, `fulfilled_at`, `latest_reschedule`, `cancellation`, and
  `no_show_at` with exact documented shapes.
- [ ] `last_reschedule_reason`, staff notes, actor IDs, and internal history IDs
  are absent.
- [ ] Invalid lifecycle mutations use HTTP 409 with
  `APPOINTMENT_STATE_CONFLICT`; validation and slot failures preserve their
  approved 422 envelopes.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentContractTest.php`
- [ ] Cross-patient show returns 404 and no private fields appear in JSON.
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 1–8.

**Files likely touched:**

- `app/Http/Resources/AppointmentResource.php`
- `app/Exceptions/AppointmentStateConflictException.php`
- `bootstrap/app.php`
- `app/Http/Controllers/Api/AppointmentController.php`
- `tests/Feature/Api/V1/AppointmentContractTest.php`

**Estimated scope:** Medium — 5 files.

## Task 10: Reconcile patient cancel and reschedule mutations

**Description:** Route Android cancellation and rescheduling through the new
domain actions with consistent patient ownership, optional reason validation,
and exact status/error behavior.

**Acceptance criteria:**

- [ ] Patient cancellation and rescheduling are authorized only for the
  authenticated patient's own scheduled appointment.
- [ ] Optional reason fields validate approved categories and require details
  when `other` is selected.
- [ ] Successful mutations return the Task 9 representation; cross-patient,
  state-conflict, and slot-conflict responses match the contract.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentMutationContractTest.php tests/Feature/Api/V1/AppointmentContractTest.php`
- [ ] API route equality remains 34:
  `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php`
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 7–9.

**Files likely touched:**

- `app/Http/Requests/Api/CancelAppointmentRequest.php`
- `app/Http/Requests/Api/RescheduleAppointmentRequest.php`
- `app/Http/Controllers/Api/AppointmentController.php`
- `tests/Feature/Api/V1/AppointmentMutationContractTest.php`

**Estimated scope:** Medium — 4 files.

## Checkpoint D: Android appointment cutoff

- [ ] Tasks 9–10 are checked complete.
- [ ] Exact response, error, ownership, booking, cancellation, and reschedule
  tests pass.
- [ ] Patient route equality remains exactly 34.
- [ ] Android can depend on one documented appointment enum and JSON shape.

---

## Phase E — Authoritative Availability

## Task 11: Resolve optometrist eligibility for exact intervals

**Description:** Replace date-only provider counting with exact start/end
eligibility and remove the fallback that invents capacity when nobody is
available.

**Acceptance criteria:**

- [ ] An optometrist is eligible only if the complete appointment interval fits
  enabled provider hours and no absence overlaps.
- [ ] Unassigned capacity equals the eligible-provider count; zero eligible
  providers yields zero slots.
- [ ] Scheduled, checked-in, and fulfilled-with-active-encounter appointments
  consume capacity correctly for assigned and unassigned bookings.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php tests/Feature/AppointmentSchedulingTest.php`
- [ ] Boundary tests cover touching intervals, shortened hours, two providers,
  and zero providers.
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 1, 4, and 6.

**Files likely touched:**

- `app/Actions/Appointments/EvaluateAppointmentAvailability.php`
- `app/Actions/Appointments/ListAvailableAppointmentSlots.php`
- `tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php`
- `tests/Feature/AppointmentSchedulingTest.php`

**Estimated scope:** Medium — 4 files.

## Task 12: Normalize schedule override behavior

**Description:** Give closures, early closing, and provider absences one typed,
validated meaning shared by the evaluator and future Filament page.

**Acceptance criteria:**

- [ ] `closed`, `early_close`, and `provider_absence` use a backed enum and
  enforce their required user/time combinations.
- [ ] Early closing uses the temporary end time, and provider absence supports
  full-day or overlapping partial intervals.
- [ ] Preview and final scheduling reject intervals blocked by the same
  override rules.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ClinicHoursTest.php tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php`
- [ ] Invalid override combinations fail validation tests.
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 11.

**Files likely touched:**

- `app/Enums/ScheduleOverrideType.php`
- `app/Models/ScheduleOverride.php`
- `app/Actions/Appointments/ClinicSchedule.php`
- `tests/Feature/Appointments/ClinicHoursTest.php`
- `tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php`

**Estimated scope:** Medium — 5 files.

## Task 13: Preview appointments affected by availability changes

**Description:** Add a read-only impact evaluator that reports future
appointments made invalid by proposed clinic hours, provider hours, closures,
early closing, or provider absence.

**Acceptance criteria:**

- [ ] Proposed changes return only future affected appointments with patient,
  type, schedule, and appointment-link identifiers.
- [ ] Assigned-provider and generic-capacity effects are evaluated using the
  same interval rules as booking.
- [ ] Impact evaluation never mutates availability or appointments.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Appointments/AvailabilityChangeImpactTest.php`
- [ ] Query-count assertions or inspection show bounded eager-loaded queries
  for representative schedules.
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 11–12.

**Files likely touched:**

- `app/Actions/Appointments/EvaluateAvailabilityChangeImpact.php`
- `app/Actions/Appointments/AvailabilityChangeImpact.php`
- `tests/Feature/Appointments/AvailabilityChangeImpactTest.php`

**Estimated scope:** Medium — 3 files.

## Checkpoint E: Availability engine

- [ ] Tasks 11–13 are checked complete.
- [ ] Preview, booking, staff creation, and rescheduling use the same rules.
- [ ] Zero provider capacity, partial absence, early closing, and stale-preview
  cases pass.
- [ ] Impact preview is read-only and identifies affected appointments.

---

## Phase F — Availability Management Page

## Task 14: Manage clinic and provider recurring hours

**Description:** Add the Schedule navigation group and custom Availability page
with seven-day Clinic Hours and Optometrist Hours schemas backed by authorized,
tested mutation actions.

**Acceptance criteria:**

- [ ] Clinic and provider recurring hours can be viewed in one Availability
  page with clear seven-day rows and time validation.
- [ ] Optometrists can edit their own hours and clinic hours; admins can select
  either provider; receptionists are read-only.
- [ ] Saves use server-authorized actions and refresh availability immediately.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Filament/AvailabilityPageTest.php`
- [ ] Production assets build:
  `vendor/bin/sail npm run build`
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 11–13.

**Files likely touched:**

- `app/Filament/Pages/Availability.php`
- `resources/views/filament/pages/availability.blade.php`
- `app/Actions/Appointments/UpdateClinicHours.php`
- `app/Actions/Appointments/UpdateProviderHours.php`
- `tests/Feature/Filament/AvailabilityPageTest.php`

**Estimated scope:** Medium — 5 files.

## Task 15: Manage schedule exceptions with conflict confirmation

**Description:** Complete the Availability page with closure, early-closing,
and provider-absence actions that show affected appointments and require
explicit confirmation without moving them.

**Acceptance criteria:**

- [ ] Authorized users can add valid upcoming schedule exceptions with a
  required operational reason.
- [ ] Conflicting changes display affected appointment count/details and do not
  save until the user explicitly confirms.
- [ ] Confirmed changes persist availability only; linked appointments remain
  unchanged and offer Reschedule navigation.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Filament/AvailabilityPageTest.php tests/Feature/Appointments/AvailabilityChangeImpactTest.php`
- [ ] Production assets build:
  `vendor/bin/sail npm run build`
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 13–14.

**Files likely touched:**

- `app/Filament/Pages/Availability.php`
- `resources/views/filament/pages/availability.blade.php`
- `app/Actions/Appointments/SaveScheduleOverride.php`
- `tests/Feature/Filament/AvailabilityPageTest.php`

**Estimated scope:** Medium — 4 files.

## Checkpoint F: Availability management

- [ ] Tasks 14–15 are checked complete.
- [ ] Clinic hours, provider hours, and exceptions work for the role matrix.
- [ ] Receptionist direct action calls are denied server-side.
- [ ] Conflicts require confirmation and never silently move appointments.
- [ ] Filament assets build successfully.

---

## Phase G — Filament Workflow Surfaces

## Task 16: Make appointment pages action-driven

**Description:** Remove direct date/time/status editing and replace old Confirm,
Advance, Complete, and bulk-confirm controls with state-aware scheduling
actions.

**Acceptance criteria:**

- [ ] Appointment form keeps schedule and status read-only after creation, and
  date changes occur only through Reschedule.
- [ ] List/edit actions match the approved state matrix for check-in,
  reschedule, cancel, no-show, assignment, encounter, and health record.
- [ ] Direct Livewire action calls still enforce domain authorization and
  transitions.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Filament/CheckInActionTest.php`
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 4–10.

**Files likely touched:**

- `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`
- `app/Filament/Resources/Appointments/Tables/AppointmentsTable.php`
- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `app/Filament/Resources/Appointments/Pages/ListAppointments.php`
- `tests/Feature/Filament/AppointmentResourceTest.php`

**Estimated scope:** Medium — 5 files.

## Task 17: Reconcile appointment calendar and dashboard surfaces

**Description:** Update calendar colors, drag-rescheduling, queue filters,
counts, and dashboard labels to the new lifecycle and interval availability
rules.

**Acceptance criteria:**

- [ ] Calendar, Today's Schedule, and appointment stats use only approved
  appointment/encounter states and correct user-facing labels.
- [ ] Calendar drag remains a Reschedule action with reason/availability
  enforcement rather than a direct date mutation.
- [ ] Waiting and active counts derive from checked-in/planned and in-progress
  records respectively.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Filament/CalendarInteractivityTest.php tests/Feature/Filament/AppointmentResourceTest.php`
- [ ] Production assets build:
  `vendor/bin/sail npm run build`
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 11–16.

**Files likely touched:**

- `app/Filament/Resources/Appointments/Widgets/AppointmentCalendarWidget.php`
- `app/Filament/Resources/Appointments/Widgets/AppointmentStatsWidget.php`
- `app/Filament/Widgets/TodaysScheduleWidget.php`
- `app/Filament/Widgets/StatsOverviewWidget.php`
- `tests/Feature/Filament/CalendarInteractivityTest.php`

**Estimated scope:** Medium — 5 files.

## Task 18: Make encounter pages action-driven

**Description:** Present planned encounters as the clinical queue and route
Start Visit and Complete Visit through the dedicated authorized actions.

**Acceptance criteria:**

- [ ] Encounter table/filter labels use `planned`, `in_progress`, `completed`,
  and `cancelled`.
- [ ] Start Visit collects/defaults an optometrist and is inaccessible to a
  receptionist; Complete Visit follows assigned-practitioner rules.
- [ ] Generic encounter status editing and direct record updates are removed.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterResourceTest.php tests/Feature/Encounters/EncounterLifecycleTest.php`
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 6 and 16.

**Files likely touched:**

- `app/Filament/Resources/Encounters/Pages/EditEncounter.php`
- `app/Filament/Resources/Encounters/Schemas/EncounterForm.php`
- `app/Filament/Resources/Encounters/Tables/EncountersTable.php`
- `tests/Feature/Filament/EncounterResourceTest.php`

**Estimated scope:** Medium — 4 files.

## Checkpoint G: Filament clinic workflow

- [ ] Tasks 16–18 are checked complete.
- [ ] Appointment, calendar, queue, dashboard, and encounter UI tests pass.
- [ ] Receptionist and optometrist workflow permissions match the approved
  matrix.
- [ ] No generic lifecycle or direct date/time mutation control remains.

---

## Phase H — Cross-Cutting Reconciliation and Release

## Task 19: Reconcile notifications, reminders, and SMS terminology

**Description:** Replace confirmation/arrival/completion assumptions in
patient communications and scheduled jobs with scheduled, checked-in,
fulfilled, and encounter-completed semantics.

**Acceptance criteria:**

- [ ] Appointment reminders target `scheduled`; booking language says
  scheduled rather than awaiting confirmation.
- [ ] Reschedule/cancellation notifications contain patient-readable reasons
  without staff notes.
- [ ] SMS event labels and daily operational summaries use the correct
  appointment versus encounter completion source.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/SmsProcessingTest.php tests/Feature/Filament/SmsNotificationResourceTest.php`
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 1 and 7–10.

**Files likely touched:**

- `app/Notifications/AppointmentStatusChanged.php`
- `app/Console/Commands/SendAppointmentRemindersCommand.php`
- `app/Console/Commands/SendDailySummaryCommand.php`
- `app/Filament/Resources/SmsNotifications/Tables/SmsNotificationsTable.php`
- `tests/Feature/SmsProcessingTest.php`

**Estimated scope:** Medium — 5 files.

## Task 20: Reconcile reports, feedback eligibility, and complaint restart

**Description:** Update retained consumers that distinguish whether an
appointment occurred, whether clinical care completed, and how complaint
workflows create a new planned encounter.

**Acceptance criteria:**

- [ ] Appointment reports count `fulfilled`, while clinical completion metrics
  count encounter `completed`.
- [ ] Patient feedback eligibility uses fulfilled visits without reintroducing
  retired Order behavior.
- [ ] Complaint restart creates a new appointment/encounter pair using the
  approved scheduled/check-in/planned workflow.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/FeedbackTest.php tests/Feature/EndToEnd/ClinicWorkflowTest.php`
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 1, 3, and 6–10.

**Files likely touched:**

- `app/Filament/Pages/Reports/AppointmentsReport.php`
- `app/Http/Requests/Api/StoreFeedbackRequest.php`
- `app/Actions/Complaints/RestartComplaintWorkflow.php`
- `tests/Feature/FeedbackTest.php`
- `tests/Feature/EndToEnd/ClinicWorkflowTest.php`

**Estimated scope:** Medium — 5 files.

## Task 21: Remove the temporary lifecycle transition bridge

**Description:** After every runtime slice is migrated, delete obsolete
appointment lookup values and the encounter `waiting` enum case, then prove
that canonical seeders, factories, and fixtures contain only the approved
lifecycle.

**Acceptance criteria:**

- [ ] Appointment seeding produces exactly `scheduled`, `checked_in`,
  `fulfilled`, `cancelled`, and `no_show`.
- [ ] Encounter code contains exactly `planned`, `in_progress`, `completed`,
  and `cancelled`.
- [ ] Static scans find no executable use of `pending`, `confirmed`, `arrived`,
  appointment `completed`, or encounter `waiting`.

**Verification:**

- [ ] Tests pass:
  `vendor/bin/sail artisan test --compact tests/Feature/Seeders/CanonicalSeederTest.php tests/Feature/Seeders/ClinicWorkflowSeederTest.php tests/Feature/AppointmentModelTest.php tests/Feature/Encounters/EncounterCheckInTest.php`
- [ ] Static scan output is reviewed:
  `rg -n "'(pending|confirmed|arrived|waiting)'|\"(pending|confirmed|arrived|waiting)\"" app database tests routes config`
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 1–20.

**Files likely touched:**

- `app/Enums/EncounterStatus.php`
- `database/seeders/AppointmentStatusSeeder.php`
- `database/factories/AppointmentFactory.php`
- `tests/Feature/Seeders/CanonicalSeederTest.php`
- `tests/Feature/Seeders/ClinicWorkflowSeederTest.php`

**Estimated scope:** Medium — 5 files.

## Task 22: Finalize documentation and release evidence

**Description:** Remove every remaining retired lifecycle reference, reconcile
the Android contract and backend context, and produce reproducible release
evidence.

**Acceptance criteria:**

- [ ] `API_CONTRACT.md` documents exact appointment/availability inputs,
  successful JSON, enum values, nullability, and applicable error bodies.
- [ ] `BACKEND_CONTEXT.md` documents implemented lifecycle, cardinality,
  availability page, roles, schema, and action flow.
- [ ] Static scans find no executable `pending`, `confirmed`, `arrived`,
  appointment `completed`, or encounter `waiting` behavior, except historical
  specification text explicitly labeled obsolete.

**Verification:**

- [ ] Focused regression passes:
  `vendor/bin/sail artisan test --compact tests/Feature/Appointments tests/Feature/Encounters tests/Feature/Api/V1/AppointmentContractTest.php tests/Feature/Api/V1/AppointmentMutationContractTest.php tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Filament/AvailabilityPageTest.php tests/Feature/Filament/EncounterResourceTest.php`
- [ ] Route equality passes:
  `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php`
- [ ] Full regression passes:
  `vendor/bin/sail artisan test --compact`
- [ ] Formatting passes:
  `vendor/bin/sail bin pint --dirty --format agent`
- [ ] Production assets build:
  `vendor/bin/sail npm run build`
- [ ] Fresh schema and canonical seed are verified only against an approved
  disposable database.
- [ ] Browser verification covers scheduled creation, check-in, Start Visit,
  completion, reschedule reason, cancellation, no-show, availability edits,
  exception conflict confirmation, and receptionist denial.

**Dependencies:** Tasks 1–21.

**Files likely touched:**

- `docs/API_CONTRACT.md`
- `docs/BACKEND_CONTEXT.md`
- `docs/specs/appointment-lifecycle-and-availability-spec.md`
- `docs/specs/appointment-lifecycle-and-availability-plan.md`
- `docs/specs/appointment-lifecycle-and-availability-tasks.md`

**Estimated scope:** Medium — 5 documentation files plus verification-only
commands.

## Checkpoint H: Ready for implementation handoff

- [ ] Tasks 1–22 are checked complete.
- [ ] Every task's acceptance and verification checkboxes are complete.
- [ ] All focused and full automated gates pass.
- [ ] Browser workflow evidence passes for every role.
- [ ] Android contract and backend context match observed implementation.
- [ ] The final implementation commit hash is recorded for Android handoff.

---

## Dependency Graph

```text
Tasks 1–3: Lifecycle foundation
        |
        v
Tasks 4–6: Booking, check-in, clinical encounter
        |
        v
Tasks 7–8: Cancellation, no-show, rescheduling
        |
        v
Tasks 9–10: Patient API contract
        |
        +--------------------+
        |                    |
        v                    v
Tasks 11–13            Tasks 16–18
Availability engine    Filament workflow
        |
        v
Tasks 14–15
Availability page
        \                    /
         +---------+--------+
                   |
                   v
             Tasks 19–22
        Reconciliation and release
```

## Parallelization Guidance

No sub-agent or parallel-edit workflow is authorized by this task breakdown.

If separate implementation sessions are later approved:

- Tasks 9–10 and Tasks 11–13 may proceed independently only after Tasks 1–8
  are merged and stable.
- Tasks 16–18 may begin after their explicit dependencies, but they share
  appointment and encounter tests with other work and require coordination.
- Tasks 14–15 must remain sequential.
- Tasks 19–22 wait for all functional slices.

The default execution strategy is one incremental stream to minimize conflicts
in shared factories, seeders, appointment resources, and end-to-end tests.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---:|---|
| Status cutover breaks distant consumers | High | Central enum, slice-specific tests, final static scan |
| Fulfilled visit frees provider too early | High | Count active encounter in interval tests |
| Check-in creates duplicate encounter | High | Row lock, unique FK, idempotency tests |
| Availability UI bypasses server rules | High | Mutation actions and direct-call authorization tests |
| Existing appointments become invalid silently | High | Impact preview and explicit confirmation |
| Android depends on an accidental response | High | Literal contract tests and API documentation before handoff |
| Operational reason leaks clinical data | Medium | Separate patient-readable reason fields and Resource allow-list |
| Task scope expands into recurring breaks | Medium | Explicitly defer multiple daily windows and recurring breaks |
| Canonical migration rewrite affects local data | Medium | Use only approved disposable databases for fresh migration |

## Open Questions

None. Status, lifecycle, cardinality, roles, availability, page structure,
patient API boundary, and undeployed-data assumptions were approved in Phases
1 and 2.
