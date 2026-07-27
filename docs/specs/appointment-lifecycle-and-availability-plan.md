# Plan: Appointment Lifecycle and Availability Management

## Status

Approved by the project owner on 2026-07-27. Phase 3 may create the checkbox
task breakdown. Application implementation remains unauthorized until the
Phase 3 tasks are separately approved.

## Outcome

Deliver one consistent scheduling and visit lifecycle where:

- appointments use `scheduled`, `checked_in`, `fulfilled`, `cancelled`, and
  `no_show`;
- encounters use `planned`, `in_progress`, `completed`, and `cancelled`;
- check-in creates the appointment's single planned encounter without
  requiring an optometrist;
- Start Visit requires an optometrist and atomically starts the encounter;
- rescheduling, cancellation, and no-show changes are explicit, auditable
  actions;
- clinic and provider availability can be managed from Filament;
- the availability endpoint and final mutations apply the same interval-level
  rules; and
- the revised patient contract is documented before Android integration.

## Current-State Findings

Repository and database inspection established:

- `appointments` uses an `appointment_status_id` lookup-table foreign key.
- `encounters.status` is already cast to a PHP enum.
- the database already has a unique index on `encounters.appointment_id`;
  duplicate encounters are prevented at the database level;
- `Appointment::encounters()` is incorrectly modeled as `HasMany` despite that
  unique index;
- check-in already uses a transaction and row lock;
- `clinic_hours`, `provider_hours`, and `schedule_overrides` already exist;
- provider hours already enforce one row per optometrist and weekday;
- the current capacity calculation counts provider rows for a date but does
  not prove that the proposed interval fits each provider's start and end
  times;
- current capacity incorrectly falls back to at least one provider;
- the current Filament panel has no availability-management page;
- appointment lifecycle strings are duplicated across actions, forms, tables,
  API requests, notifications, reports, widgets, factories, seeders, and
  tests; and
- the patient API already omits preferred-optometrist selection, which remains
  correct.

## Architecture Decisions

### 1. Preserve the appointment status lookup table

Keep `appointment_statuses` and `appointments.appointment_status_id` to avoid a
needless schema rewrite across existing foreign keys and queries.

Add one backed PHP enum, tentatively `AppointmentStatusName`, containing the
five approved names. It becomes the source for:

- allowed names;
- transition comparisons;
- labels and badge colors;
- seeder values;
- factories;
- API assertions; and
- Filament filters.

The lookup model remains responsible for persisted IDs. Code resolves IDs by
enum value through one reusable model method or service rather than scattered
string queries.

### 2. Retire the generic appointment status updater

Remove `UpdateAppointmentStatus` as a user-facing mutation path. Replace it
with dedicated actions that express the business event:

- `CheckInAppointment`
- `CancelAppointment`
- `MarkAppointmentNoShow`
- `RescheduleAppointment`
- `StartEncounter`
- `CompleteEncounter`

Dedicated actions make required reason, actor, timestamp, notification, audit,
and cross-record behavior impossible to bypass accidentally.

### 3. Keep encounter status as a backed enum

Rename `EncounterStatus::Waiting` to `EncounterStatus::Planned`. Retain
`InProgress`, `Completed`, and `Cancelled`.

The encounter column remains a string cast to the enum. No lookup table is
introduced.

### 4. Reconcile one-to-one encounter modeling

Change `Appointment::encounters()` to `Appointment::encounter(): HasOne`.

The existing database unique index remains authoritative. Check-in becomes
idempotent under duplicate delivery:

- `scheduled` with no encounter creates the planned encounter;
- `checked_in` with its existing planned encounter returns that encounter;
- every other state returns a state-conflict error.

Walk-in creation creates a scheduled appointment and invokes the same check-in
workflow inside one transaction, producing `checked_in + planned` without a
parallel encounter-creation implementation.

### 5. Use a direct undeployed schema

The system is not deployed and development data is disposable. Update
canonical creation migrations rather than introducing compatibility-only
transition migrations.

The canonical appointment schema will:

- replace `completed_at` with `fulfilled_at`;
- remove `last_reschedule_reason`;
- add cancellation initiator, actor, reason category, reason details, and
  timestamp fields;
- add no-show actor and timestamp fields; and
- retain existing check-in fields.

Create a canonical `appointment_reschedules` table containing:

- appointment foreign key;
- previous and new scheduled timestamps;
- initiator type;
- nullable actor;
- nullable reason category and details;
- rescheduled timestamp; and
- nullable notification timestamp or reference where the existing
  notification implementation can provide it reliably.

Update the canonical encounter migration default from `waiting` to `planned`.
Make `appointment_id` required and retain its unique index. Use restrictive
deletion behavior because an encounter is a clinical record and its appointment
must not be physically deleted out from under it.

### 6. Preserve one recurring provider window per weekday

Keep the current `provider_hours` structure and unique
`user_id + weekday` constraint. Do not add recurring breaks or multiple
windows.

Normalize schedule override semantics:

- `closed`: clinic-level, no time fields;
- `early_close`: clinic-level, `end_time` is the temporary closing time;
- `provider_absence`: provider required; both times null means full day,
  otherwise both start and end are required for a partial absence.

Introduce a `ScheduleOverrideType` enum and centralized validation. The
availability page and evaluator use the same semantics.

### 7. Calculate eligibility for the exact interval

Replace date-only capacity counting with an interval-based operation:

```text
eligibleOptometrists(startsAt, endsAt)
```

An optometrist is eligible only when:

- the account has optometrist capability;
- an enabled provider-hour row exists for the weekday;
- the full candidate interval fits within the provider's start/end time; and
- no full-day or overlapping partial absence exists.

Unassigned capacity is the count of that collection. A specifically assigned
staff booking must contain that provider in the collection. An empty
collection means capacity zero.

The existing appointment-overlap algorithm remains the base for consuming
capacity, with approved blockers:

- `scheduled`;
- `checked_in`; and
- `fulfilled` while its linked encounter is `in_progress`.

Cancelled, no-show, and completed historical intervals do not consume new
capacity.

### 8. Keep preview and mutation under one scheduler

`GET /appointment-availability`, mobile booking, staff scheduled creation, and
both patient/staff rescheduling continue through the same evaluator and
clinic-date lock.

The availability page does not directly mutate scheduling tables. It invokes
single-purpose actions that validate permissions, normalize input, inspect
affected future appointments, and persist only after explicit confirmation.

### 9. Use one custom Filament Availability page

Add `App\Filament\Pages\Availability` under a new `Schedule` navigation group.
Move Appointments into that group and keep Encounters under
`Patients & Clinical`.

Use Filament schemas and actions rather than three generic CRUD resources:

- Clinic Hours schema: seven fixed weekday rows.
- Optometrist Hours schema: seven fixed weekday rows and an authorized provider
  selector for admins.
- Exceptions table/actions: closures, early closing, and provider absences.

The page is accessible to panel users. Mutating controls are disabled or hidden
for receptionists, but action classes also enforce authorization server-side.
Custom page access and action authorization receive dedicated Livewire tests,
following Filament 5 guidance.

### 10. Preview affected appointments before availability changes

Add a read-only impact evaluator for proposed clinic hours, provider hours, or
exceptions.

If future appointments would fall outside the new availability:

1. return the affected appointment IDs and display data;
2. show count, patient, current schedule, appointment type, and a link to the
   appointment;
3. require a second explicit confirmation action; and
4. save the availability change without mutating those appointments.

The appointment's existing Reschedule action remains the only way to move it.

### 11. Make appointment editing action-driven

Remove direct status mutation from the appointment edit form.
`scheduled_at` and appointment time remain read-only after creation.

Available actions are determined by lifecycle:

| State | Available staff actions |
|---|---|
| `scheduled` | Check In, Reschedule, Cancel, Mark No-show, Assign Optometrist |
| `checked_in` | Cancel before visit starts, Assign Optometrist, open Encounter |
| `fulfilled` | Open Encounter/Health Record |
| `cancelled` | View history |
| `no_show` | View history |

Remove Confirm, generic Advance/Complete, and bulk confirmation actions.

### 12. Make encounter editing action-driven

Start Visit:

- requires a `planned` encounter and `checked_in` appointment;
- requires an actor with optometrist capability;
- requires a selected user with optometrist capability;
- defaults to the current optometrist actor when appropriate;
- updates both appointment and encounter optometrist assignment;
- records `fulfilled_at` and `started_at`; and
- atomically transitions appointment and encounter.

Complete Visit:

- requires `in_progress`;
- normally requires the assigned optometrist;
- permits an optometrist-capable admin override with an explicit audit entry;
- records `completed_at`; and
- does not mutate the already fulfilled appointment.

Cancelling a checked-in appointment atomically cancels its planned encounter.
An in-progress encounter cannot be cancelled in this phase.

### 13. Freeze the revised Android contract before implementation handoff

No routes are added. The patient route count remains 34.

The appointment response retains existing snake_case conventions and uses this
contract fragment:

```json
{
  "id": 123,
  "appointment_number": "APT-2026-000123",
  "appointment_type": "Routine Check-up",
  "duration_minutes": 30,
  "referring_source": null,
  "status": "scheduled",
  "scheduled_at": "2026-08-01T01:00:00.000000Z",
  "checked_in_at": null,
  "fulfilled_at": null,
  "contact_notes": null,
  "source": "mobile",
  "assigned_optometrist": null,
  "latest_reschedule": null,
  "cancellation": null,
  "no_show_at": null
}
```

When present:

```json
{
  "latest_reschedule": {
    "previous_scheduled_at": "2026-08-01T01:00:00.000000Z",
    "new_scheduled_at": "2026-08-02T02:00:00.000000Z",
    "initiated_by": "clinic",
    "reason_category": "optometrist_unavailable",
    "reason_details": "The assigned optometrist is unavailable.",
    "rescheduled_at": "2026-07-30T04:00:00.000000Z"
  },
  "cancellation": {
    "initiated_by": "patient",
    "reason_category": null,
    "reason_details": null,
    "cancelled_at": "2026-07-31T03:00:00.000000Z"
  }
}
```

Rules:

- `latest_reschedule` is derived from immutable history.
- `last_reschedule_reason` is retired.
- staff-only notes, actor IDs, inventory data, and internal history IDs are not
  exposed.
- patient cancellation and reschedule bodies may accept optional
  `reason_category` and `reason_details`;
- staff forms require their approved reason category; and
- selecting `other` requires details.

Error semantics:

- `401`: unauthenticated;
- `404`: appointment is absent or belongs to another patient;
- `409`: lifecycle state prevents the requested mutation, using:

  ```json
  {
    "message": "The appointment cannot be changed from its current status.",
    "code": "APPOINTMENT_STATE_CONFLICT",
    "errors": {
      "appointment": ["This appointment cannot be rescheduled."]
    }
  }
  ```

- `422`: field validation or slot availability fails, preserving the existing
  structured `SLOT_UNAVAILABLE` response where applicable; and
- `429`: existing rate-limit envelope.

`docs/API_CONTRACT.md` becomes authoritative for the exact successful and error
JSON before Android work resumes.

### 14. Keep patient-visible and internal reasons separate

Reason details stored in reschedule or cancellation records are
patient-readable operational explanations. Internal discussion stays in
`staff_notes`.

Form helper text warns users not to enter medical history in operational
reasons. API Resources expose only patient-readable reason data.

## Component Map

| Area | Target | Main locations |
|---|---|---|
| Status definitions | Central appointment enum; revised encounter enum | `app/Enums`, status seeder |
| Canonical schema | Lifecycle metadata and reschedule history | appointment/encounter migrations |
| Relationships | Appointment `hasOne` Encounter; `hasMany` reschedules | Appointment, Encounter, AppointmentReschedule |
| Appointment workflow | Dedicated check-in/cancel/no-show/reschedule actions | `app/Actions/Appointments`, `app/Actions/Encounters` |
| Encounter workflow | Start/complete actions with optometrist enforcement | `app/Actions/Encounters` |
| Scheduling | Exact interval provider eligibility and capacity | availability actions, ClinicSchedule |
| Availability mutations | Authorized hours/override actions and impact preview | `app/Actions/Appointments` or a schedule-focused subnamespace following existing conventions |
| Filament scheduling | Availability page and Schedule navigation group | `app/Filament/Pages`, AdminPanelProvider |
| Appointment UI | Action-driven table/edit/calendar | Appointment resource |
| Encounter UI | Planned queue and clinical actions | Encounter resource |
| Patient API | Revised status, reasons, timestamps, and errors | controller, requests, resource |
| Side effects | Scheduled/reminder/cancel/reschedule wording and audit | notifications, SMS, audit |
| Reporting | Appointment fulfillment vs encounter completion metrics | reports and widgets |
| Fixtures | Exact statuses and two-provider default hours | factories and seeders |
| Verification | Lifecycle, authorization, API, scheduling, UI, regression | `tests/Feature` |
| Documentation | Android contract and implemented backend truth | API contract, backend context |

## Dependency Sequence

```text
Contract tests and status definitions
                |
                v
Canonical schema and model relationships
                |
                v
Dedicated lifecycle actions
          /                 \
         v                   v
Patient API contract    Interval availability engine
         |                   |
         v                   v
Appointment/Encounter UI   Availability page
          \                 /
           v               v
      Reports, notifications, seeders
                |
                v
      Full regression and documentation
```

The API and availability branches can be reasoned about independently after
the lifecycle primitives exist. Implementation should still proceed
incrementally because both branches touch appointment factories, seeders, and
end-to-end tests.

## Milestone 1: Contract and lifecycle primitives

### Changes

1. Add failing contract tests for the exact appointment and encounter status
   sets.
2. Add failing relationship/schema tests for appointment `hasOne` encounter
   behavior and reschedule history.
3. Introduce the centralized appointment status enum.
4. Rename Encounter `waiting` to `planned`.
5. Update canonical migrations, models, casts, relationships, factories, and
   seeders.
6. Require the unique encounter appointment foreign key and use restrictive
   deletion behavior.
7. Replace development demo statuses and seed both optometrists with default
   9:00 AM–5:00 PM availability for all seven days.

### Checkpoint A

- Fresh test schema contains the canonical lifecycle fields.
- Exactly five appointment and four encounter statuses exist.
- Encounter appointment uniqueness remains enforced.
- Model and canonical seeder tests pass.

## Milestone 2: Dedicated lifecycle actions

### Changes

1. Update Check In to accept only `scheduled`, create/return the one planned
   encounter, and require no optometrist.
2. Route walk-in creation through the same check-in behavior.
3. Add Start Encounter and Complete Encounter actions.
4. Add Cancel Appointment and Mark No-show actions.
5. Extend Reschedule Appointment to create immutable history.
6. Centralize transition, authorization, audit, and timestamp behavior.
7. Remove the generic status update action after all callers move.

### Checkpoint B

- Normal and invalid transitions have focused tests.
- Check-in is race-safe and does not create duplicate encounters.
- Start Visit requires an optometrist and changes both records atomically.
- Cancellation and rescheduling capture required history.
- No direct lifecycle mutation caller remains.

## Milestone 3: Patient API contract

### Changes

1. Update booking, show, list, reschedule, and cancel behavior.
2. Add boundary validation for optional patient reason fields.
3. Scope every patient appointment mutation consistently.
4. Update `AppointmentResource` to the approved response fragment.
5. Standardize lifecycle conflict and cross-patient errors.
6. Update patient notifications from confirmation wording to scheduled
   wording.
7. Add exact API contract tests before Android handoff.

### Checkpoint C

- Route count remains exactly 34.
- Mobile bookings and reschedules remain `scheduled`.
- Cross-patient access returns no resource information.
- Status, timestamp, latest-reschedule, and cancellation response shapes match
  documentation exactly.
- API contract tests pass.

## Milestone 4: Authoritative interval availability

### Changes

1. Add failing tests for provider start/end boundaries, partial absence,
   full-day absence, zero providers, and active encounters.
2. Introduce exact interval eligible-provider resolution.
3. Remove the minimum-capacity fallback.
4. Reconcile schedule override semantics and validation.
5. Apply the same evaluator to preview, booking, staff creation, and
   rescheduling.
6. Add impact evaluation for proposed availability changes.

### Checkpoint D

- Preview and final mutations agree for all tested intervals.
- Zero eligible providers produces no slots.
- A stale preview cannot bypass a newly saved closure or absence.
- Provider-specific and generic clinic capacity are correct with two
  optometrists.

## Milestone 5: Filament workflow and Availability page

### Changes

1. Add the Schedule navigation group and Availability page.
2. Build Clinic Hours, Optometrist Hours, and Exceptions sections.
3. Add authorized save actions and affected-appointment confirmation.
4. Replace direct appointment status/date controls with dedicated actions.
5. Update list tabs, calendar, filters, badges, and walk-in queue.
6. Update encounter list/edit actions for planned/start/complete behavior.
7. Test receptionist, optometrist, and admin behavior through Livewire.

### Checkpoint E

- Receptionists can schedule and check in but cannot mutate availability or
  clinical state.
- Optometrists can manage their own hours and clinic exceptions.
- Admins can manage either provider.
- Availability changes show affected appointments without moving them.
- Appointment and encounter pages expose only valid workflow actions.

## Milestone 6: Reconcile side effects and release evidence

### Changes

1. Update SMS events, reminders, database notifications, audit events, reports,
   dashboard widgets, and calendar colors.
2. Update complaint restart and any retained workflow that creates encounters.
3. Replace retired status references in all tests and fixtures.
4. Update `docs/API_CONTRACT.md` and `docs/BACKEND_CONTEXT.md`.
5. Run static scans for retired status strings and direct lifecycle mutation.
6. Run focused suites, full Pest regression, Pint, asset build when required,
   fresh schema/seed verification, route equality, and browser checks for the
   new page and modified workflow.

### Checkpoint F

- No executable reference to retired lifecycle terms remains.
- Reports distinguish fulfilled appointments from completed encounters.
- Seeded users can exercise the complete scheduling and visit workflow.
- Documentation matches observed routes, schema, resources, and permissions.
- All required verification gates pass.

## Risk Register

### Widespread status-string drift

Risk: old values remain in a report, notification, filter, or test and create
contradictory behavior.

Mitigation:

- centralized enum;
- literal static scans for `pending`, `confirmed`, `arrived`, and encounter
  `waiting`;
- focused consumer tests before full regression.

### Appointment fulfillment frees capacity too early

Risk: Start Visit changes the appointment to `fulfilled`, and a simplistic
query stops counting its active optometrist.

Mitigation:

- explicitly include fulfilled appointments whose encounter is
  `in_progress`;
- test assigned and unassigned overlap behavior.

### Schedule changes strand existing appointments

Risk: closing early or marking absence makes future appointments impossible
without telling staff.

Mitigation:

- impact evaluator;
- explicit confirmation;
- linked affected-appointment list;
- never mutate appointments automatically.

### Availability preview and mutation diverge

Risk: Android sees a slot that final booking evaluates differently.

Mitigation:

- one interval evaluator;
- existing date lock;
- stale-preview race tests;
- no UI-only eligibility logic.

### Authorization relies on hidden controls

Risk: a receptionist calls a Livewire action directly.

Mitigation:

- authorization inside workflow actions;
- custom page and action authorization tests for every role;
- visibility only as a UX layer.

### Historical reason leakage

Risk: internal discussion or medical information appears in Android.

Mitigation:

- patient-readable reason fields;
- staff notes remain separate;
- API Resource allow-list;
- contract tests asserting excluded fields.

### Canonical migration rewrite disrupts local data

Risk: developers with existing disposable data cannot migrate incrementally.

Mitigation:

- this undeployed project explicitly uses canonical fresh migrations;
- no production compatibility layer;
- fresh-schema verification occurs only against an approved disposable
  database;
- document the required reseed.

### Filament custom-page complexity

Risk: one page with multiple schemas and a table becomes difficult to test.

Mitigation:

- keep three bounded sections;
- move mutations and impact calculation into actions;
- keep page methods thin;
- use Filament 5 custom-page, schema, action, and authorization patterns from
  version-specific documentation.

## Parallelization

After Milestone 1:

- patient API contract work and interval-availability work are technically
  independent;
- appointment/encounter Filament work depends on lifecycle actions;
- the Availability page depends on the interval evaluator and schedule
  mutation actions; and
- final fixtures, reports, documentation, and end-to-end coverage wait for all
  branches.

Because this repository has a dense shared appointment test surface, the
recommended execution remains one incremental stream rather than concurrent
edits to the same factories, seeders, and resources.

## Verification Checkpoints

1. **Schema checkpoint:** migration structure, enum values, relationships,
   factories, and seeders.
2. **Lifecycle checkpoint:** action tests, transactions, authorization,
   timestamps, audit, and one-to-one behavior.
3. **API checkpoint:** exact JSON, errors, ownership, route equality, and
   Android documentation.
4. **Availability checkpoint:** exact interval capacity, override behavior,
   stale-preview safety, and impact detection.
5. **Filament checkpoint:** real Livewire action tests and role matrix.
6. **Release checkpoint:** static scans, focused suites, full suite, Pint,
   assets, fresh seed, browser verification, and context reconciliation.

## Phase Boundary

This plan does not authorize implementation.

After approval, Phase 3 will convert each milestone into checkbox tasks. Each
task will:

- touch a bounded file set;
- begin with failing or revised tests;
- state exact acceptance criteria;
- include a focused Sail verification command; and
- end at a checkpoint that can be reviewed before the next implementation
  slice.
