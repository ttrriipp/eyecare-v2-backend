# Spec: Practical Variable-Duration Appointment Scheduling

Status: Approved
Phase: Specify approved — implementation not started
Date: 2026-08-08
Approved: 2026-08-08

## Objective

Implement a service-based, request-first appointment scheduler that lets
patients request realistic times for different optometry visit types while
keeping clinic review simple, preventing confirmed schedule conflicts, and
using each active optometrist's capacity correctly.

The primary users are:

- patient accounts requesting appointments through the mobile API;
- staff, optometrists, and administrators reviewing requests and managing
  scheduled appointments in Filament; and
- administrators configuring patient-visible appointment types and their
  default durations.

Success means a patient selects a visit type before choosing time preferences,
availability uses that type's duration on a 15-minute start grid, pending
requests are clearly non-binding, and accepting a request atomically creates
one conflict-free appointment with a final provider, start time, type, and
duration snapshot.

## Approved Product Decisions and Assumptions

1. Mobile submissions remain appointment requests that require clinic review;
   automatic confirmation is not part of this release.
2. A patient selects one active, patient-visible appointment type and provides
   a free-text reason for the visit.
3. A request contains one required preferred start and up to two ordered,
   distinct alternative starts.
4. Start times use the clinic's configurable 15-minute grid. Visit duration is
   a separate value and may be 15, 30, 45, or another valid duration.
5. Appointment-type defaults use five-minute increments. Mobile patients
   cannot override duration; reviewing panel users may override it in
   five-minute increments from 5 through 240 minutes.
6. The mobile default is any available optometrist. Patients do not express a
   preferred optometrist; the clinic assigns the final provider. (Deferred:
   patient-preferred optometrist.)
7. Every active optometrist is eligible for every appointment type in this
   release. Type-specific provider eligibility is deferred until the clinic
   demonstrates a real specialization constraint.
8. Capacity is provider-aware and based on active optometrists, provider hours,
   clinic hours, schedule overrides, and confirmed blocking appointments.
9. Pending requests are tentative demand. They never consume or hide public
   schedule capacity.
10. Request acceptance must recheck the selected interval and provider inside
    the existing date-lock transaction before creating the appointment.
11. Staff, optometrists, and administrators may review appointment requests,
    consistent with the approved panel role model. Only administrators may
    manage appointment-type scheduling configuration.
12. The system has one clinic location. Rooms, devices, support staff,
    pretesting, and dilation stages are not schedulable resources in this
    release.
13. Referral remains a clinic-defined appointment type. Selecting a type whose
    `requires_referral` flag is true requires a referring provider or clinic;
    it does not require a document upload.
14. Existing appointment duration snapshots and historical attribution remain
    unchanged when appointment-type defaults or labels change.
15. No new dependencies are required.

### Deferred Features

The following features were considered during specification but are deferred to
a future release:

- **Patient-preferred optometrist.** Patients do not select a preferred
  provider. The clinic assigns the final optometrist at acceptance.
  Provider-aware availability remains for staff use.
- **`review_due_at` queue indicator.** The review queue sorts by `created_at`.
  An elapsed-time display can be derived later without stored data.
- **Mandatory outside-preference contact note.** `contact_notes` remains an
  optional audit field. The validation requiring a note when staff selects a
  time outside all submitted preferences is removed.

## Initial Appointment-Type Catalog

The internal `name` remains the clinic-facing label. A separate patient label
and description make the mobile choices understandable without renaming
historical clinic terminology.

| Internal name | Patient label | Default duration | Referral details |
|---|---|---:|---|
| `New Patient` | First eye examination | 45 minutes | Optional |
| `Routine Check-up` | Regular eye examination | 30 minutes | Optional |
| `Follow-up` | Follow-up requested by the optometrist | 15 minutes | Optional |
| `Problem/Urgent Visit` | New or worsening eye concern | 30 minutes | Optional |
| `Contact Lens Consultation` | Contact lens consultation | 45 minutes | Optional |
| `Referral` | Referral | 45 minutes | Required |

These are initial operational defaults, not universal clinical durations. The
clinic should review staff duration overrides and scheduling patterns after
2-4 weeks and change the defaults when evidence supports it.

## User Workflows

### Patient request

1. The patient retrieves the active patient-visible appointment types.
2. The patient chooses a type and optionally chooses a preferred optometrist;
   the default remains any available optometrist.
3. The patient requests availability for a date. The API returns starts on the
   15-minute grid using the selected type's current default duration.
4. The patient submits one preferred start and zero to two ordered alternatives,
   a required reason for visit, conditional referral source details, and the
   existing linked/unlinked identity data.
5. All submitted starts must be distinct, future, grid-aligned, fit clinic and
   provider hours, and be currently available for the provisional duration.
6. The response clearly identifies the request as pending and states that the
   submitted times are preferences rather than reservations.

Multiple patients may request the same currently available time. This is an
intentional consequence of not creating long-lived capacity holds.

### Clinic review and confirmation

1. A panel user resolves an unlinked request to a Patient using the existing
   identity-review workflow.
2. The review page shows the requested type, provisional duration, all ordered
   time preferences, reason, referral details, and request age.
3. The reviewer may reclassify the appointment type. Changing the type resets
   the proposed duration to that type's default; the reviewer may then enter an
   explicit five-minute-increment override.
4. The reviewer selects the final optometrist and one submitted time.
5. Acceptance locks the request row and schedule date, rechecks the final
   interval and provider, creates the scheduled appointment, and resolves the
   request in one transaction with deadlock retries.
6. If the final interval no longer fits, the request remains pending and the UI
   presents a validation error instead of partially resolving it.

Request-created confirmed appointments must have an assigned optometrist.
Existing manually created appointments may retain the current nullable provider
workflow and still consume general provider capacity while unassigned.

### Request review target and expiration

- The review queue sorts by `created_at`. An elapsed-time display can be
  derived later without stored data.
- `expires_at` is the latest submitted preferred or alternative start.
- The existing expiry command changes a pending request to `expired` only when
  every requested start has passed.
- Expiration releases no capacity because pending requests never reserve it.

### Staff-created scheduled appointments

- Staff-created scheduled appointments use the same active appointment types,
  type defaults, conditional referral validation, provider-aware availability,
  and 15-minute start grid.
- The form displays `duration_minutes`; selecting a type applies its default,
  and staff may intentionally override it in five-minute increments.
- Changing type, duration, time, or provider before check-in must revalidate the
  complete interval before saving.
- Checked-in appointments retain the current restrictions on schedule-defining
  fields.
- Walk-ins remain exempt from the future-time and slot-grid requirements and
  continue through the existing immediate check-in workflow.
- Arbitrary off-grid scheduled appointments are not supported in this MVP. If
  the clinic later demonstrates a need, a reasoned and audited override can be
  specified separately.

## Data Model

### `appointment_types`

Retain:

- `name`
- `duration_minutes`
- `requires_referral`
- `is_active`

Add:

- `patient_label` — nullable string; API display falls back to `name` for
  safely migrated legacy rows;
- `patient_description` — nullable text; and
- `is_patient_visible` — boolean, default `true` for existing canonical types.

Rules:

- `duration_minutes` is an integer from 5 through 240 and divisible by 5.
- Patient endpoints return only types that are both active and patient-visible.
- Staff appointment forms return active types, including active internal-only
  types.
- Deactivation hides a type from new selection but does not alter historical
  requests or appointments.
- Existing foreign-key restrictions continue to prevent destructive deletion
  of a type used by an appointment.

### `appointment_requests`

Reuse:

- nullable `appointment_type_id` for legacy compatibility;
- `scheduled_at` as the primary preferred start;
- `provisional_duration_minutes` as the request-time duration snapshot;
- `expires_at` with its revised latest-preference meaning; and
- existing identity, reason, status, review actor, resolution, rejection, and
  resulting-appointment fields.

Add:

- `alternative_scheduled_times` — nullable JSON array containing at most two
  normalized ISO-8601 timestamps in patient preference order; and
- `encrypted_referring_source` — nullable encrypted text for the referring
  provider or clinic supplied with the request.

Deferred:

- `preferred_optometrist_id` — patients do not select a provider.
- `review_due_at` — queue sorts by `created_at`.

New requests require `appointment_type_id` at the application boundary, but the
database column remains nullable so historical requests created by the retired
contract remain readable. Existing pending legacy requests without a type keep
the current staff-classification path and do not receive invented data.

### `appointments`

No new scheduling columns are required. Continue using:

- `appointment_type_id`;
- `duration_minutes` as the confirmed historical snapshot;
- `scheduled_at`, deriving the end from start plus duration;
- nullable `optometrist_id` for existing manual workflows;
- `referring_source` for confirmed referral context;
- `reason_for_visit` and `contact_notes`; and
- existing lifecycle and audit fields.

On request acceptance, copy the final appointment type, duration, start,
provider, reason, and decrypted referring source into the appointment. A final
time outside the submitted preferences also copies the required reviewer
contact note.

## Availability and Concurrency Rules

1. Slot cadence comes from `ClinicSchedule::slotIntervalMinutes`, currently 15.
2. Slot end is always candidate start plus the selected provisional or final
   duration; duration never determines the cadence between candidate starts.
3. Eligibility and capacity are evaluated for each candidate interval, not
   once for the entire clinic day. This preserves partial provider shifts and
   partial-day absences.
4. Eligibility and capacity are evaluated for each candidate interval, not
   once for the entire clinic day. This preserves partial provider shifts and
   partial-day absences.
5. Confirmed appointments in non-terminal blocking statuses consume capacity.
   Pending requests do not.
6. A specific provider cannot have overlapping confirmed appointments.
7. Unassigned confirmed appointments consume general capacity so they cannot
   cause overbooking merely because their provider is still null.
8. Request submission checks all preferences for current availability but does
   not lock or reserve them after the transaction completes.
9. Request acceptance uses the existing `appointment_schedule_locks` row for
   the final date, a request-row `lockForUpdate()`, one database transaction,
   and up to three deadlock retries.
10. The same lock-and-recheck invariant applies to staff creation and any edit
    that changes start, duration, type, or provider.

## API Contract

All routes use the existing `/api/v1` prefix, Sanctum authentication, snake_case
fields, ISO-8601 timestamps with offsets, and existing ownership/error-shape
conventions.

### Restore `GET /appointment-types`

Account-only; an active patient link is not required.

Response:

```json
{
  "data": [
    {
      "id": 1,
      "name": "First eye examination",
      "description": "For your first examination at the clinic.",
      "duration_minutes": 45,
      "requires_referral": false
    }
  ]
}
```

Only active, patient-visible types are returned. Internal clinic labels,
inactive types, and administrative fields are not exposed.

### Add `GET /appointment-optometrists`

Account-only; an active patient link is not required.

Response items contain only the active optometrist's stable user ID and display
name. No account contact, role, schedule, or other staff information is
returned.

```json
{
  "data": [
    {
      "id": 8,
      "name": "Dr. Ana Santos"
    }
  ]
}
```

This endpoint remains available for staff-facing UI but is not consumed by the
patient mobile app (patients do not select a preferred optometrist).

### Modify `GET /appointment-request-availability`

Query parameters:

| Field | Rules |
|---|---|
| `date` | Required `Y-m-d`, today or later |
| `appointment_type_id` | Required; active and patient-visible type |

The response retains the existing envelope and slot shape, with these semantic
changes:

- `interval_minutes` is the clinic cadence, normally 15;
- `slot_duration_minutes` is replaced by additive
  `visit_duration_minutes` while the former field remains during the
  coordinated client cutover;
- `appointment_type_id` is returned;
- `ends_at` uses the selected type duration; and
- pending requests are no longer included in capacity.

### Modify `POST /appointment-requests`

Request:

```json
{
  "appointment_type_id": 1,
  "scheduled_at": "2026-08-20T09:15:00+08:00",
  "alternative_scheduled_times": [
    "2026-08-20T10:30:00+08:00",
    "2026-08-21T09:00:00+08:00"
  ],
  "reason_for_visit": "Blurred distance vision",
  "referring_source": null,
  "identity": null
}
```

Validation:

- `appointment_type_id` is required and must reference an active,
  patient-visible type;
- `scheduled_at` remains the required primary preference;
- `alternative_scheduled_times` is optional, contains at most two values, and
  every value must be a distinct future ISO-8601 timestamp on the slot grid;
- the primary and alternatives must all currently fit the chosen type's
  duration;
- `referring_source` is required, trimmed, and limited to 255 characters when
  the selected type requires referral details; otherwise it is optional;
- existing reason, linked/unlinked identity, verified-contact, active-request,
  per-account rate limit, and per-IP throttle rules remain; and
- unknown nested identity keys remain rejected.

The response keeps all existing fields and adds:

- `appointment_type` with patient label and duration;
- `provisional_duration_minutes`;
- `alternative_scheduled_times` in preference order;
- nullable `referring_source`; and
- a stable `time_preferences_are_reserved: false` flag.

Identity snapshots and staff-only data remain excluded.

### Existing request list, detail, and cancellation routes

`GET /appointment-requests`, `GET /appointment-requests/{id}`, and
`POST /appointment-requests/{id}/cancel` retain their URLs, ownership behavior,
pagination, and status semantics. They use the expanded response shape above.

### Error semantics

- Validation remains HTTP 422 using the repository's existing error envelope.
- A stale time at submission or confirmation uses `SLOT_UNAVAILABLE` without
  exposing another patient's appointment.
- Inactive or hidden appointment types and invalid provider preferences fail
  validation without exposing private staff or catalog details.
- Cross-account request access remains an enumeration-safe 404.
- No error includes identity snapshots, referral details, SQL, or stack traces.

### Coordinated compatibility decision

Restoring `GET /appointment-types` is additive, but requiring
`appointment_type_id` changes the current v1 request contract. This feature
therefore requires a coordinated backend/API-contract/Android cutover. The
backend will not silently default a missing type to `New Patient`, because that
would preserve the incorrect 30-minute assumption the redesign is intended to
remove. Approving this specification approves that coordinated contract change.

## Filament Contract

### Appointment-type configuration

Add an administrator-only Appointment Types resource under the Availability
cluster. It supports list, create, and edit/deactivate workflows for:

- internal name;
- patient label and description;
- default duration with five-minute step and 5-240 bounds;
- referral-detail requirement;
- patient visibility; and
- active state.

Do not expose destructive deletion for types used by historical records.

### Appointment-request queue and review

- Show patient label/internal type, provisional duration, primary preference,
  number of alternatives, preferred provider, and overdue state in the queue.
- Show all scheduling, identity-resolution, reason, and referral context on the
  review page.
- Restrict acceptance to the review page so the reviewer cannot bypass patient
  resolution, duration, provider assignment, referral, time-choice, and contact
  rules through an underspecified quick action.
- The acceptance form includes active type, duration, provider, final date/time,
  referral source when required, and conditional contact note.
- A failed availability recheck leaves the request pending and preserves form
  context for correction.

### Scheduled appointment form

- Filter appointment types to active types.
- Apply the selected type's duration reactively and expose an editable duration
  field using a five-minute step.
- Require referral source when the selected type requires it.
- Use the clinic slot cadence for scheduled time selection and validation.
- Revalidate availability on create and on any pre-check-in schedule-defining
  edit.
- Preserve current walk-in and checked-in behavior outside these rules.

## Security and Privacy

Trust boundaries are the patient API, Filament forms, and persisted request
snapshots. Relevant assets are patient identity, reason for visit, referral
context, schedule privacy, and privileged confirmation actions.

- Validate and normalize all external IDs, timestamps, arrays, and text at the
  Form Request or Filament action boundary.
- Preserve patient-account authentication and request ownership checks.
- Preserve the encrypted identity and reason fields; store request referral
  source encrypted and never include it in logs or staff notifications.
- Return only patient-safe appointment-type and provider fields.
- Authorize appointment-type configuration server-side as administrator-only;
  navigation visibility is not the security boundary.
- Authorize request review server-side for panel roles even when Filament hides
  unavailable actions.
- Retain existing per-account request limits, rate limiting, and route throttle.
- Use generic conflict errors that do not reveal another patient's presence,
  provider schedule details, or appointment contents.
- Do not add document upload, external integrations, or new sensitive-data
  categories in this release.

## Tech Stack

- PHP 8.5
- Laravel 13.12
- Filament 5.6
- Livewire 4.3
- Laravel Sanctum 4.3
- MySQL through Laravel Sail
- Pest 4.7 / PHPUnit 12.5
- Laravel Pint 1.29
- No new dependencies

## Commands

```text
Start services: vendor/bin/sail up -d
Inspect migration options: vendor/bin/sail artisan make:migration --help
Create migrations: vendor/bin/sail artisan make:migration add_variable_scheduling_fields_to_appointment_types_and_requests --no-interaction
Inspect model generation: vendor/bin/sail artisan make:model --help
Inspect Filament generators: vendor/bin/sail artisan list --format=txt
Inspect API routes: vendor/bin/sail artisan route:list --except-vendor --path=api/v1
Run focused request API tests: vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SubmitAppointmentRequestTest.php tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php tests/Feature/Api/V1/AppointmentContractTest.php
Run focused scheduling tests: vendor/bin/sail artisan test --compact tests/Feature/AppointmentSchedulingTest.php tests/Feature/AppointmentScheduleLockTest.php tests/Feature/Appointments/ProviderAvailabilityTest.php tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php tests/Feature/Appointments/ScheduleBlockAvailabilityTest.php tests/Feature/Appointments/ReviewAppointmentRequestTest.php tests/Feature/Appointments/ExpireAppointmentRequestsTest.php
Run focused Filament tests: vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRequestResourceTest.php tests/Feature/Filament/ViewAppointmentRequestTest.php tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Filament/Availability
Run full suite: vendor/bin/sail artisan test --compact
Format modified PHP: vendor/bin/sail bin pint --dirty --format agent
Build frontend assets when required: vendor/bin/sail npm run build
```

## Project Structure

```text
app/Actions/Appointments/                         -> Request submission, availability, locking, acceptance, and expiration rules
app/Filament/Clusters/Availability/               -> Admin-only appointment-type scheduling configuration
app/Filament/Resources/AppointmentRequests/       -> Request queue and full review workflow
app/Filament/Resources/Appointments/              -> Manual scheduled appointment form and schedule-defining edits
app/Http/Controllers/Api/                         -> Thin type, provider, availability, and request endpoints
app/Http/Requests/Api/                            -> Patient API authorization and boundary validation
app/Http/Resources/                               -> Stable patient-safe response contracts
app/Models/AppointmentType.php                    -> Type defaults and patient visibility
app/Models/AppointmentRequest.php                 -> Ordered preferences, provisional snapshot, referral, and review timing
app/Models/Appointment.php                        -> Existing confirmed schedule snapshot
config/appointments.php                           -> Clinic-wide slot cadence
config/patient_accounts.php                       -> Request limits and retention, not slot duration or capacity holds
database/migrations/                              -> Additive, reversible schema changes and safe data transition
database/factories/ and database/seeders/         -> Canonical types and explicit request states
tests/Feature/Api/V1/                             -> Mobile contract, authorization, ownership, and validation
tests/Feature/Appointments/                       -> Duration, capacity, concurrency, review, and expiry behavior
tests/Feature/Filament/                           -> Admin configuration and review UI authorization/workflows
docs/API_CONTRACT.md                              -> Authoritative Android contract
docs/BACKEND_CONTEXT.md                           -> Implemented-system context after delivery
docs/specs/appointment-scheduling-redesign-spec.md -> This specification
```

No new top-level application directories are required.

## Code Style

Follow existing Laravel conventions: typed parameters and returns, descriptive
names, Form Requests at API boundaries, thin controllers, and single-purpose
actions owning workflow transactions. Never hardcode a canonical type ID or
branch on the string `Referral`; use persisted type configuration.

```php
$appointment = $acceptAppointmentRequest->handle(
    request: $appointmentRequest,
    reviewer: $reviewer,
    appointmentType: $appointmentType,
    durationMinutes: $durationMinutes,
    scheduledAt: $scheduledAt,
    optometrist: $optometrist,
    referringSource: $referringSource,
    contactNote: $contactNote,
);
```

Use explicit PHPDoc array shapes for validated structured input, PHP 8
constructor property promotion, TitleCase enum cases, braces for all control
structures, and the existing Eloquent/action patterns in sibling files.

## Testing Strategy

Use Pest feature tests and factories. Add regression tests before changing
behavior, run the narrowest affected files after every vertical slice, and run
the full suite before final handoff because availability actions are shared by
API, Filament, and rescheduling.

### API contract and abuse cases

- Patient-visible types expose only the documented fields and exclude inactive
  or internal-only records.
- Provider listing exposes only active optometrists and patient-safe fields.
- Availability uses the selected type duration and 15-minute cadence.
- Preferred-provider availability is constrained to that provider.
- Submission rejects missing/hidden/inactive type IDs, non-optometrist IDs,
  duplicate or excessive alternatives, off-grid times, elapsed times, and
  conditional missing referral source.
- Cross-account request access remains 404 and identity snapshots remain absent
  from responses.
- Existing rate and active-request limits still apply.

### Scheduling and concurrency

- A 45-minute visit can start on each valid 15-minute boundary and blocks its
  complete interval.
- Concurrent appointments remain possible across two eligible optometrists,
  while overlap for the same assigned optometrist is rejected.
- Partial provider hours and partial absences affect only overlapping candidate
  intervals.
- Pending requests do not reduce availability.
- Two concurrent accept attempts cannot overbook capacity or resolve one
  request twice.
- Changing type, duration, start, or provider rechecks availability under the
  date lock.

### Request lifecycle

- Provisional duration snapshots from the selected type.
- Ordered alternatives round-trip through the API and Filament.
- Review due time resolves to the next open clinic day's closing time.
- Expiration occurs when the latest preference passes, not 24 hours after
  creation.
- Reclassification resets the proposed duration and allows an explicit valid
  override.
- Referral source is required and copied only when configured by the type.
- An outside-preference confirmation requires and copies a contact note.
- Legacy requests without a type remain readable and reviewable.

### Filament and authorization

- Only administrators can view or mutate appointment-type configuration.
- All three panel roles can review requests and perform existing shared
  appointment operations.
- The request review action requires patient resolution, final provider,
  interval availability, and referral/contact fields when applicable.
- Manual scheduled appointments use active types, duration defaults, and the
  slot grid; walk-ins remain exempt.

No arbitrary coverage percentage is required. Behavioral and authorization
branches listed above must be covered.

## Data Migration and Rollback

- Add all new columns without deleting or renaming existing request fields.
- Backfill patient labels/descriptions/visibility for canonical types.
- Change `New Patient` and `Referral` from 30 to 45 minutes only when their
  stored duration still equals the old canonical 30-minute value; preserve a
  clinic-customized value.
- Add `Problem/Urgent Visit` and `Contact Lens Consultation` idempotently.
- Do not update `appointments.duration_minutes` or rewrite historical request
  types, providers, referral data, or timestamps.
- Existing requests keep their current `expires_at`; only newly submitted
  requests receive the latest-preference expiration rule.
- The down migration removes only newly added columns and records created solely
  by the migration when safely identifiable; it never deletes clinic-created
  types or historical appointments.

## Boundaries

### Always

- Update the spec before changing an approved behavior.
- Use Sail for PHP, Artisan, Composer, Node, tests, and formatting.
- Search version-specific Laravel/Filament documentation before code changes.
- Create framework files with the appropriate Artisan or Filament generator.
- Validate API and Filament input at their trust boundaries.
- Keep workflow transactions and authorization in reusable server-side actions.
- Preserve historical duration snapshots and patient ownership boundaries.
- Add focused Pest coverage and run Pint after PHP changes.
- Update `docs/API_CONTRACT.md` and `docs/BACKEND_CONTEXT.md` with implemented
  behavior.

### Ask First

- Any schema or behavior change beyond the fields and rules in this spec.
- A non-coordinated API compatibility period or a new API version.
- Appointment-type-specific provider eligibility.
- Multi-location, room, equipment, or staged clinical-resource scheduling.
- Immediate mobile confirmation, waitlists, or recurring appointments.
- File uploads or new categories of patient data.
- New dependencies, CI changes, notification channels, or rate-limit changes.

### Never

- Treat a pending request as confirmed capacity.
- Default a missing mobile appointment type silently.
- Hardcode appointment-type IDs or identify referral behavior by its name.
- Derive candidate start cadence from visit duration.
- Confirm without a final availability recheck and date lock.
- Overlap two confirmed appointments for the same assigned optometrist.
- Rewrite historical appointment duration snapshots when defaults change.
- Expose identity snapshots, private staff data, or another patient's schedule.
- Log patient reason, referral source, identity snapshot, or authentication data.
- Delete tests, historical records, or clinic-created types without approval.

## Success Criteria

1. Authenticated patient accounts can retrieve the six active patient-visible
   appointment types with their current default durations.
2. Request availability requires a type, uses its duration, returns starts on
   the 15-minute cadence, and optionally honors a preferred optometrist.
3. Patients can submit one primary and up to two alternative starts with a
   required type, and every submitted interval is validated.
4. Referral requests require a referring provider or clinic; non-referral
   requests may omit it.
5. New requests snapshot the type duration, expire at their latest preference,
   and expose a next-open-day review target.
6. Pending requests never hide public availability or consume confirmed
   provider capacity.
7. Reviewers can reclassify, override duration in valid increments, assign a
   provider, and select a submitted time.
8. Confirming outside the submitted choices requires a contact note.
9. Acceptance is atomic, idempotent, provider-aware, and cannot overbook under
   concurrent requests.
10. Request-created appointments store the final type, duration, provider,
    start, referral source, reason, and request relationship.
11. Admins can configure types; staff and optometrists cannot access that
    configuration but can review requests.
12. Staff-created scheduled appointments use active types, variable durations,
    conditional referral details, provider-aware capacity, and the same slot
    grid; walk-ins retain their existing workflow.
13. Existing appointments and legacy requests remain readable without invented
    history or changed duration snapshots.
14. The mobile API contract and backend context describe the implemented rules,
    focused and full tests pass, and dirty PHP files pass Pint formatting.

## Open Questions

No product questions remain blocking. Approval of this specification also
confirms the coordinated Android/API cutover required by the new mandatory
`appointment_type_id` request field. Android implementation itself remains
outside this repository.
