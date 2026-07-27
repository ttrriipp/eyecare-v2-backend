# Spec: Appointment Lifecycle and Availability Management

## Status

Approved by the project owner on 2026-07-27. Phase 2 may produce the technical
implementation plan. Task breakdown and application implementation remain
unauthorized until their respective approval gates are completed.

## Approved Assumptions

1. The scope covers appointment and encounter lifecycle remodeling plus
   Filament availability-management pages.
2. Android code is outside this repository, but the patient API contract must
   expose the revised appointment lifecycle consistently.
3. Valid mobile and clinic-created bookings are accepted immediately and begin
   as `scheduled`; there is no clinic-confirmation queue.
4. A walk-in begins as `checked_in`.
5. Check-in does not require an optometrist.
6. Check-in creates one `planned` encounter for the appointment.
7. Starting an encounter requires an optometrist and atomically changes the
   appointment to `fulfilled` and the encounter to `in_progress`.
8. One appointment has at most one encounter. A complaint or repeat
   examination starts a new appointment and a new encounter.
9. Rescheduling is a recorded event, not an appointment status.
10. Cancellation initiator, reason, actor, and time are metadata, not separate
    appointment statuses.
11. The existing clinic-hours, provider-hours, schedule-overrides, and
    authoritative availability engine remain the scheduling foundation.
12. Availability management uses one custom Filament page with focused
    sections rather than three generic CRUD resources.
13. Admins and optometrist-capable users may manage clinic availability.
    Optometrists may manage their own availability; receptionists have
    schedule visibility but cannot change availability.
14. Each optometrist has one continuous recurring availability window per
    weekday. Full-day and partial-day exceptions handle absences. Recurring
    break management is outside this phase.
15. Zero available optometrists means zero bookable capacity.
16. Availability changes never silently cancel or reschedule existing
    appointments.
17. The application is not deployed, so obsolete seeded statuses and
    development-only data do not require legacy compatibility.

## Objective

Replace the ambiguous appointment and encounter lifecycle with a small,
standards-aligned workflow and give the clinic a usable way to manage its
seven-day schedule, early closures, and the availability of its two
optometrists.

Primary users:

- Optometrists who run the clinic and need to manage schedules and conduct
  clinical encounters.
- A possible receptionist who can schedule and check in patients but cannot
  change clinical or availability settings.
- Patients using Android to book, reschedule, cancel, and view appointments.

Success means:

- Appointment planning state is not confused with clinical encounter state.
- Staff cannot bypass lifecycle, rescheduling, cancellation, or audit rules by
  directly editing fields.
- Check-in works while an optometrist is not yet assigned.
- A clinical visit cannot start without an eligible optometrist.
- Android and the Filament panel receive the same authoritative availability.
- Clinic and provider schedule changes are manageable without editing seeders
  or database records manually.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5
- Livewire 4
- Laravel Sanctum 4
- MySQL through Laravel Sail
- Pest 4 and PHPUnit 12
- Laravel Pint 1
- Tailwind CSS 4 and Vite 8

## Commands

- Start services: `vendor/bin/sail up -d`
- Inspect API routes:
  `vendor/bin/sail artisan route:list --except-vendor --path=api`
- Run focused lifecycle tests:
  `vendor/bin/sail artisan test --compact tests/Feature/Appointments tests/Feature/Encounters`
- Run focused Filament tests:
  `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Filament/EncounterResourceTest.php tests/Feature/Filament/AvailabilityPageTest.php`
- Run focused patient API tests:
  `vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentBookingTest.php tests/Feature/Api/AppointmentAvailabilityTest.php tests/Feature/Api/AppointmentRescheduleTest.php`
- Run scheduling tests:
  `vendor/bin/sail artisan test --compact tests/Feature/AppointmentSchedulingTest.php`
- Run the full suite:
  `vendor/bin/sail artisan test --compact`
- Format modified PHP:
  `vendor/bin/sail bin pint --dirty --format agent`
- Build frontend assets when required:
  `vendor/bin/sail npm run build`

Exact focused paths may be reconciled during planning with the test files
already present in the repository. Tests must be created with Pest and Laravel
Artisan rather than custom verification scripts.

## Project Structure

- `app/Actions/Appointments/` — booking, rescheduling, cancellation, no-show,
  lifecycle transitions, availability evaluation, and schedule locking.
- `app/Actions/Encounters/` — check-in, encounter start, completion, and
  cancellation workflows.
- `app/Enums/` — typed lifecycle and scheduling values where appropriate.
- `app/Filament/Pages/` — the custom Availability page.
- `app/Filament/Resources/Appointments/` — appointment list, calendar, create,
  edit, health-record page, and workflow actions.
- `app/Filament/Resources/Encounters/` — encounter queue and clinical workflow.
- `app/Http/Controllers/Api/` — patient appointment and availability endpoints.
- `app/Http/Requests/Api/` — patient request validation and authorization.
- `app/Http/Resources/` — patient-safe appointment serialization.
- `app/Models/` — appointment, encounter, availability, history, and audit
  relationships.
- `database/migrations/` — canonical schema and lifecycle constraints.
- `database/factories/` and `database/seeders/` — approved statuses and
  seven-day clinic/provider schedules.
- `tests/Feature/` — lifecycle, authorization, API, availability, and Filament
  regression coverage.
- `docs/API_CONTRACT.md` — Android-facing request, response, enum, and error
  contract.
- `docs/BACKEND_CONTEXT.md` — living backend architecture and workflow context.

No new top-level application directories or dependencies are required.

## Code Style

Lifecycle changes must go through single-purpose actions with typed arguments
and explicit authorization. Filament pages and API controllers must invoke the
same actions.

```php
$encounter = app(StartEncounter::class)->handle(
    encounter: $encounter,
    optometrist: $optometrist,
    actor: $request->user(),
);
```

Additional conventions:

- Use explicit parameter and return types.
- Use PHP backed enums or one centralized lifecycle definition rather than
  duplicating transition arrays in forms, tables, requests, and actions.
- Use database transactions and row locks for check-in, start-visit, booking,
  and rescheduling mutations.
- Use API Resources for patient responses.
- Use policies or workflow authorization for availability and clinical
  actions.
- Use named Filament actions for transitions; do not expose a generic editable
  status field.

## Domain Model

### Appointment lifecycle

Approved stored statuses:

| Status | Meaning | Terminal |
|---|---|---:|
| `scheduled` | A valid clinic or mobile booking exists for a future time. | No |
| `checked_in` | The patient arrived and administrative check-in is complete. | No |
| `fulfilled` | The scheduled visit proceeded into its clinical encounter. | Yes |
| `cancelled` | The appointment will not proceed. | Yes |
| `no_show` | The patient did not attend the scheduled appointment. | Yes |

Allowed transitions:

```text
scheduled ──→ checked_in ──→ fulfilled
    │              │
    ├──────────────┴──────→ cancelled
    └─────────────────────→ no_show
```

Rules:

- Mobile and clinic-created scheduled appointments start as `scheduled`.
- Walk-ins are created as `checked_in`.
- Only `scheduled` appointments can be marked `no_show`.
- Only `scheduled` appointments can be rescheduled.
- A `checked_in` appointment may be cancelled if the patient leaves before the
  clinical encounter starts.
- A `fulfilled`, `cancelled`, or `no_show` appointment cannot be rescheduled,
  checked in, or advanced again.
- `pending`, `confirmed`, `arrived`, and appointment `completed` are retired.
- `rescheduled`, `cancelled_by_patient`, and `cancelled_by_clinic` are never
  appointment statuses.

Data reconciliation for development and tests:

| Existing status | Replacement |
|---|---|
| `pending` | `scheduled` |
| `confirmed` | `scheduled` |
| `arrived` | `checked_in` |
| `completed` | `fulfilled` |
| `cancelled` | `cancelled` |
| `no_show` | `no_show` |

The appointment records:

- `scheduled_at`
- `checked_in_at` and `checked_in_by`
- the time it became fulfilled
- cancellation metadata when cancelled
- no-show metadata when marked no-show

The exact column names and whether obsolete timestamp columns are renamed or
replaced are implementation-plan decisions. Their meaning must remain
unambiguous.

### Encounter lifecycle

Approved stored statuses:

| Status | Meaning | Terminal |
|---|---|---:|
| `planned` | Check-in created the encounter, but clinical care has not started. | No |
| `in_progress` | An assigned optometrist is examining the patient. | No |
| `completed` | The clinical encounter ended normally. | Yes |
| `cancelled` | A planned encounter was abandoned before clinical care started. | Yes |

Allowed transitions:

```text
planned ──→ in_progress ──→ completed
   │
   └─────────────────────→ cancelled
```

Rules:

- `waiting` is replaced by `planned`.
- Only a `planned` encounter can be cancelled.
- An `in_progress` encounter cannot transition to `cancelled`.
- `discontinued`, `on_hold`, and `discharged` are outside this clinic's current
  workflow.
- If prematurely ended in-progress visits become a real requirement, a future
  specification may add `discontinued`.

### Appointment and encounter cardinality

```text
Appointment 1 ── 0..1 Encounter
```

Rules:

- An appointment may have no encounter before check-in.
- Check-in creates exactly one encounter.
- Repeated or concurrent check-in must never create duplicates.
- The database must enforce one encounter per appointment.
- An encounter belongs to exactly one appointment for this workflow.
- Complaints and follow-up examinations create a new appointment and encounter
  rather than appending another encounter to the original appointment.

### Reschedule history

Rescheduling changes `scheduled_at` while the appointment remains `scheduled`.
Every successful reschedule records an immutable history entry containing:

- appointment
- previous start time
- new start time
- initiator type: `patient` or `clinic`
- authenticated actor when available
- reason category
- optional reason details
- rescheduled timestamp
- patient-notification outcome or reference where available

Rules:

- Staff-initiated rescheduling requires a reason category.
- Selecting `other` requires reason details.
- Patient-initiated rescheduling may omit a reason.
- Reasons are operational notes and must not contain unnecessary medical
  information.
- General appointment notes and chief complaint are not used as reschedule
  reasons.
- Date and time remain disabled in ordinary appointment editing.
- All reschedule entry points use the same authoritative action and
  availability check.

Recommended clinic reason categories:

- `patient_requested`
- `optometrist_unavailable`
- `clinic_schedule_change`
- `clinic_emergency_or_closure`
- `scheduling_conflict`
- `data_entry_correction`
- `other`

### Cancellation and no-show metadata

A cancelled appointment records:

- initiator type: `patient` or `clinic`
- authenticated actor when available
- reason category
- optional reason details
- cancellation timestamp

Rules:

- Clinic/staff cancellation requires a reason category.
- Selecting `other` requires details.
- Patient cancellation is allowed only while the appointment is `scheduled`
  and may omit a reason.
- Cancellation of a checked-in appointment also cancels its `planned`
  encounter atomically.
- Cancellation never deletes the appointment, intake, encounter, history, or
  audit record.

A no-show records:

- the timestamp it was marked
- the staff actor
- optional operational notes

No-show must not be available before the scheduled start time.

## Workflow

### Mobile booking

1. Patient selects an appointment type and an available time.
2. The server revalidates availability inside the protected booking
   transaction.
3. The appointment is created as `scheduled`, with no optometrist required.
4. Clinic notification remains informational; staff confirmation is not
   required.

### Clinic-created scheduled appointment

1. Staff selects or creates the patient.
2. Staff selects appointment type, date, and available time.
3. Optometrist assignment is optional.
4. The appointment is created as `scheduled`.

### Walk-in

1. Staff selects or creates the patient and appointment type.
2. The system creates a `checked_in` appointment and its single `planned`
   encounter atomically.
3. Optometrist assignment remains optional until Start Visit.

### Scheduled patient check-in

1. Staff invokes Check In on a `scheduled` appointment.
2. The system locks the appointment, changes it to `checked_in`, records the
   actor and time, and creates its `planned` encounter.
3. No optometrist selection is required.
4. Repeated or concurrent attempts return the existing result or fail safely
   without creating a second encounter.

### Start visit

1. An optometrist-capable user opens the planned encounter.
2. An eligible optometrist must be assigned. If none is assigned, the action
   asks for one; an optometrist actor may default to themselves.
3. The system atomically changes:
   - appointment `checked_in → fulfilled`
   - encounter `planned → in_progress`
4. The system records the optometrist and start time.
5. A receptionist cannot start the clinical encounter.

### Complete visit

1. The assigned optometrist completes the `in_progress` encounter.
2. The encounter becomes `completed` and records its completion time.
3. The appointment remains `fulfilled`.
4. Any admin override of the assigned practitioner rule must require
   optometrist capability and create an audit entry.

## Availability Rules

Bookable availability is the intersection of:

```text
enabled clinic hours
 eligible optometrist recurring hours
- clinic closures or early-closing overrides
- full-day or partial provider absences
- capacity consumed by blocking appointments
= bookable appointment slots
```

Rules:

- Clinic defaults are enabled every day from 9:00 AM to 5:00 PM.
- Clinic hours can differ by weekday.
- An appointment must fit completely within clinic hours.
- An optometrist is eligible only when the whole proposed appointment fits
  inside that optometrist's enabled hours.
- A provider absence may cover a whole day or a specific time interval.
- A clinic closure removes all slots for that date.
- Early closing shortens the closing boundary for that date.
- If no optometrist is eligible for an interval, capacity is zero; the system
  must not fall back to a minimum capacity of one.
- For an unassigned booking, capacity equals the number of eligible
  optometrists for that exact interval.
- An assigned staff booking must validate that specific optometrist.
- `scheduled` and `checked_in` appointments block their reserved intervals.
- A `fulfilled` appointment with an `in_progress` encounter keeps its assigned
  optometrist unavailable for the active interval; fulfillment must not
  accidentally free a provider while the examination is underway.
- Completed historical intervals and `cancelled` or `no_show` appointments do
  not block future scheduling capacity.
- Preview and final mutation use the same evaluator, date lock, overlap rules,
  clinic timezone, and appointment-duration snapshot.
- Existing scheduled appointments are never silently changed when availability
  is edited.

## Filament Pages and Actions

### Navigation

Add a `Schedule` navigation group containing:

1. Appointments
2. Availability

The existing clinical records remain under `Patients & Clinical`.

### Availability page

Provide one custom `Availability` page with these sections or tabs:

#### Clinic Hours

- Seven weekday rows.
- Open/closed toggle for each day.
- Opening and closing time.
- Defaults to every day, 9:00 AM–5:00 PM.
- Opening time must be earlier than closing time.
- Editing requires admin or optometrist capability.

#### Optometrist Hours

- Defaults to the signed-in optometrist.
- Admins can select and edit either optometrist.
- Non-admin optometrists can edit only their own recurring hours.
- Seven weekday rows with available toggle, start time, and end time.
- Provider hours must fit inside clinic hours.
- A receptionist may view but cannot mutate these settings.

#### Exceptions

- Upcoming clinic closures.
- Date-specific early closing.
- Full-day or partial-day optometrist absence.
- Required operational reason for clinic or provider exceptions.
- Past exceptions remain available as read-only history rather than being
  silently overwritten.

When an exception or recurring-hour change overlaps future appointments:

1. Show the count and list of affected appointments.
2. Require explicit confirmation before saving the availability change.
3. Preserve the appointments unchanged.
4. Provide navigation to each appointment's dedicated Reschedule action.

### Appointment list and calendar

The appointment UI must:

- Use the approved statuses and user-facing labels.
- Provide explicit Check In, Reschedule, Cancel, Mark No-show, Assign
  Optometrist, and Health Record actions only when valid.
- Remove Confirm and generic Advance/Complete appointment actions.
- Remove bulk confirmation.
- Prevent date/time and status editing through the ordinary edit form.
- Show whether a fulfilled appointment's encounter is in progress or completed
  when that context is available.
- Keep appointment source implicit: Mobile app, Scheduled by clinic, or
  Walk-in.

### Encounter list and edit page

The encounter UI must:

- Use `planned`, `in_progress`, `completed`, and `cancelled`.
- Present the planned encounters as the waiting clinical queue.
- Offer Start Visit only to optometrist-capable users.
- Require or collect the optometrist before Start Visit succeeds.
- Offer Complete Visit only for `in_progress` encounters.
- Never expose a generic editable encounter-status field.

## Authorization

- Admin and staff roles retain Filament access according to existing rules.
- `is_optometrist` remains the capability used for clinical and
  availability-management actions.
- Receptionists can create appointments, create walk-ins, check in patients,
  reschedule, cancel with a reason, mark no-show, and assign an eligible
  optometrist.
- Receptionists cannot start or complete encounters.
- Receptionists cannot modify clinic hours, provider hours, or schedule
  exceptions.
- Optometrists can manage their own provider hours and clinic-level hours or
  exceptions.
- Admins can additionally manage either optometrist's provider hours.
- Patient API actions remain scoped to the authenticated patient's own
  appointment.
- Authorization is enforced server-side and not inferred from hidden buttons.

## API Contract

The existing patient appointment routes remain; this feature does not add a
new patient route.

Required contract changes:

- Appointment status values become:
  `scheduled`, `checked_in`, `fulfilled`, `cancelled`, `no_show`.
- Mobile booking returns `scheduled`.
- Mobile rescheduling is allowed only from `scheduled` and preserves
  `scheduled`.
- Mobile cancellation is allowed only while cancellation remains clinically
  valid and records the patient as initiator.
- Appointment availability reflects clinic hours, provider time ranges,
  partial/full absences, early closing, capacity, and exact appointment
  duration.
- Patient responses expose customer-appropriate reschedule or cancellation
  information without internal staff-only notes.
- Validation and conflict responses retain the established API error envelope.
- `docs/API_CONTRACT.md` must document enum changes, request rules, response
  fields, nullability, and relevant `403`, `409`, and `422` behavior before
  Android relies on the revised contract.

The approved API route count remains 34 unless a later specification explicitly
adds a route.

## Notifications, Reports, and Audit

- Rename confirmation-oriented notification language to scheduled-booking
  language.
- Appointment reminders target `scheduled` appointments.
- Reschedule and cancellation notifications use patient-appropriate reasons
  and never expose internal-only notes.
- Reports count appointment `fulfilled` as the scheduled visit having
  proceeded.
- Clinical completion metrics use encounter `completed`.
- Waiting counts use appointment `checked_in` and encounter `planned`.
- Active-visit counts use encounter `in_progress`.
- Calendar colors, dashboard widgets, filters, factories, and seeders use only
  approved statuses.
- All lifecycle, assignment, reschedule, cancellation, no-show, and
  availability changes create appropriate audit records.

## Privacy and Integrity

- Appointment reasons are operational data and must not duplicate clinical
  history or invite unnecessary medical detail.
- Clinical information remains in patient intake, encounter, and prescription
  records.
- Patient-visible reasons must be separated from staff-only notes.
- Availability and appointment histories are append-only or auditable.
- Existing clinical records are not deleted when workflow state changes.
- Server-side authorization and transition checks are authoritative.
- Direct database-style status manipulation is not exposed in the UI.

## Testing Strategy

Use Pest feature tests and existing factories. Write failing behavioral tests
before implementation.

### Lifecycle coverage

- Mobile and clinic bookings start as `scheduled`.
- Walk-ins start as `checked_in` with one `planned` encounter.
- Check-in does not require an optometrist.
- Check-in creates exactly one encounter under repeated and concurrent calls.
- Start Visit fails without an eligible optometrist.
- Start Visit atomically produces appointment `fulfilled` and encounter
  `in_progress`.
- Complete Visit changes only the encounter to `completed`.
- Invalid and terminal transitions are rejected.
- A started encounter cannot be cancelled.
- One appointment cannot persist two encounters.

### Reschedule, cancellation, and no-show coverage

- Staff reschedule requires a reason category.
- `other` requires details.
- Patient reschedule may omit a reason.
- Reschedule preserves `scheduled` and immutable old/new schedule history.
- Direct date/time editing cannot bypass the Reschedule action.
- Clinic cancellation requires a reason; patient cancellation may omit one.
- Checked-in cancellation also cancels its planned encounter.
- No-show is rejected before the scheduled start and from invalid statuses.
- Actor, initiator, timestamp, and audit metadata are persisted.

### Availability coverage

- Clinic open/close times and all seven weekdays affect generated slots.
- Early closing and full clinic closure affect preview and final mutation.
- Provider recurring start/end times affect capacity for the exact interval.
- Full and partial provider absence affect capacity.
- Zero eligible providers returns no available slots.
- Assigned and unassigned capacity behave correctly with two optometrists.
- A newly added exception cannot be bypassed by a stale Android preview.
- Availability edits with affected appointments require explicit confirmation
  and do not mutate those appointments.

### Filament and authorization coverage

- Availability sections render for authorized users.
- Optometrists can change their own hours.
- Receptionists can view schedules but cannot change availability.
- Receptionists can check in but cannot start or complete encounters.
- Status, date, and time are not directly editable.
- Actions appear only for valid states and still reject unauthorized direct
  calls.

### API and regression coverage

- Appointment API resources expose only approved statuses and patient-safe
  metadata.
- Ownership and lifecycle validation protect reschedule and cancellation.
- Availability response and booking mutation use the same rules.
- Existing intake, prescription, quotation, job-order, feedback, reminders,
  reports, and end-to-end clinic tests are updated and remain passing.
- Run focused suites first, Pint after PHP changes, then the full regression
  suite before completion.

## Boundaries

### Always

- Keep lifecycle transitions in centralized actions.
- Use transactions and locking for multi-record workflow changes.
- Enforce authorization and state transitions server-side.
- Preserve audit and scheduling history.
- Keep the 34-route patient API stable unless separately approved.
- Update `docs/API_CONTRACT.md` and `docs/BACKEND_CONTEXT.md` with implemented
  enum, schema, role, page, and workflow changes.
- Replace obsolete factories and seeded data with the approved workflow.
- Run affected Pest tests and Laravel Pint.

### Ask first

- Adding `discontinued`, `on_hold`, `arrived`, or any other lifecycle status.
- Supporting recurring provider breaks or multiple recurring windows per day.
- Adding dependencies or new top-level directories.
- Adding patient API routes.
- Changing role names or replacing the `is_optometrist` capability model.
- Automatically cancelling or rescheduling appointments after availability
  changes.
- Purging historical appointment, encounter, cancellation, reschedule, or
  availability data.

### Never

- Reintroduce a pending-confirmation queue without a new approved
  specification.
- Treat rescheduling or cancellation initiator as an appointment status.
- Require an optometrist merely to check in a patient.
- Start clinical care without an eligible assigned optometrist.
- Allow more than one encounter for an appointment.
- Fall back to capacity one when no optometrist is available.
- Permit ordinary form edits to bypass lifecycle or reschedule actions.
- Silently change existing appointments when availability changes.
- Expose staff-only notes or unnecessary sensitive data through the patient
  API.
- Remove failing tests to make the suite pass.

## Success Criteria

- Exactly five appointment statuses are seeded and used:
  `scheduled`, `checked_in`, `fulfilled`, `cancelled`, `no_show`.
- Exactly four encounter statuses are used:
  `planned`, `in_progress`, `completed`, `cancelled`.
- No runtime code, test factory, report, widget, API validator, or notification
  relies on retired lifecycle values.
- The database prevents multiple encounters for one appointment.
- Check-in succeeds without an optometrist and creates one planned encounter.
- Start Visit requires an optometrist and performs both lifecycle transitions
  atomically.
- Rescheduling, cancellation, and no-show rules are centralized, authorized,
  and auditable.
- Staff cannot directly edit appointment date/time or either lifecycle status.
- Authorized users can manage seven-day clinic hours, optometrist hours,
  closures, early closing, and provider absences from Filament.
- Receptionists cannot mutate availability or clinical encounter state.
- Availability returns no slots when zero optometrists are eligible and uses
  exact provider time ranges.
- Schedule changes surface affected appointments without mutating them.
- Patient API documentation and resources expose the revised contract.
- Focused tests, the full Pest suite, frontend build when required, and Pint
  pass.

## Open Questions

None. The lifecycle, cardinality, role, availability, and page assumptions were
approved before this specification was written.
