# Technical Plan: Mobile Patient Accounts, Linking, Appointment Requests, Encounter Consultation, and Optical Orders

## Status

Phase 2 technical plan approved by the project owner on 2026-07-31 and amended
the same day with the approved date-of-birth, Frame Reservation presentation,
and unified Optical Orders decisions.

This plan implements the approved Phase 1 specification in
`docs/specs/mobile-patient-accounts-and-appointment-requests-spec.md`.
Phase 3 task decomposition is authorized. Phase 4 implementation remains
unauthorized until the project owner separately approves the task list.

## Installed Stack

- PHP 8.5
- Laravel 13.12
- Filament 5.6
- Livewire 4.3
- Laravel Sanctum 4.3
- MySQL through Laravel Sail
- Pest 4.7 and PHPUnit 12.5
- Tailwind CSS 4.3

No package installation or dependency upgrade is required by this plan.

## Outcomes

The implementation will:

1. Separate mobile account identity from clinic patient identity.
2. Replace direct patient registration with OTP-verified hybrid
   authentication.
3. Keep `patients.user_id` as the authoritative one-to-one active link.
4. Limit automatic linking to record-specific clinic invitations.
5. Require staff review for demographic matching.
6. Make all mobile bookings expiring appointment requests.
7. Convert an accepted request into exactly one normal Appointment.
8. Keep one visible Filament **Appointments** destination.
9. Retire the patient-completed intake workflow.
10. Make the checked-in Encounter a resumable clinical consultation wizard.
11. Mark an Appointment fulfilled only when its Encounter is completed.
12. Show Frame Reservations both contextually on Appointments and in their
    dedicated operational queue without duplicating records.
13. Present Quotation, Job Order, Billing Record, and reservation conversion as
    one staff-facing Optical Order workflow without merging their storage.

## Architecture Decisions

### Account and patient remain separate aggregates

`User` remains the authentication identity. `Patient` remains the
clinic-authoritative clinical identity. The nullable unique
`patients.user_id` continues to represent the active link.

Patient-role users gain nullable structured `first_name` and `last_name`
columns. Staff/admin accounts continue using the existing `name`, `email`,
password, and Filament MFA behavior. For a new patient account, `name` is a
derived compatibility value and is not used for patient matching.

Patient accounts do not use `users.email` or `users.phone` as authoritative
login identifiers. Dedicated verified contact records own patient email/phone
login. Clinic contact details remain on `patients` and are not silently
synchronized.

### Searchable contacts use encrypted values and blind indexes

Patient account contact values are encrypted at rest. A keyed HMAC blind index
supports equality lookup and uniqueness without exposing raw values in an
index. The same normalization and blind-index service maintains non-unique
lookup hashes for `patients.contact_email` and `patients.phone`.

An application-specific `CONTACT_LOOKUP_KEY` is required. It is read through
configuration, never directly through `env()` outside a config file, and is
never committed. Key rotation requires a controlled reindex operation and is
not an implicit `APP_KEY` rotation side effect.

Normalization rules are:

- email: trim, Unicode-safe lowercase, preserve the address otherwise;
- phone: accept the approved Philippine input forms and store canonical E.164
  `+63...` output;
- names: trim, collapse internal whitespace, Unicode-safe lowercase for
  matching while preserving the submitted display form;
- date of birth: clinic-local calendar date with no time component.

### OTP is a purpose-bound challenge, not a notification record

OTP challenges have one purpose:

- registration;
- login step-up;
- password recovery;
- add contact;
- replace primary contact;
- invitation acceptance.

The database stores an opaque challenge identifier, encrypted destination,
destination blind index, purpose, attempt counters, delivery state, expiry,
and a keyed digest of the code. It never stores the plaintext code.

Delivery uses dedicated encrypted queued jobs dispatched after commit. OTP
messages never use `sms_notifications.message`. The jobs carry only the
minimum encrypted payload, use explicit provider timeouts and controlled
backoff, and record sanitized delivery status without provider bodies.

### Sanctum tokens are the trusted-device session

A successful password login does not issue a token immediately. It creates a
login step-up challenge. Successful OTP verification issues a device-labelled
Sanctum token.

Possession of a still-valid token is the trusted-device state. There is no
second device-trust table. Once a token is absent, expired, or revoked, a new
password login requires OTP again.

The system:

- replaces an existing token for the same installation identifier;
- permits at most five active patient device tokens;
- revokes the oldest token when issuing beyond that limit;
- supports logout-current and logout-all;
- revokes all other patient tokens after password recovery or primary-contact
  replacement.

### Linking is invitation-safe and staff-reviewed

A clinic invitation contains a high-entropy secret whose digest is stored,
references one patient, and targets one exact contact blind index. OTP proves
control of that destination. Acceptance locks and rechecks the invitation,
account, and patient before activating `patients.user_id`.

Without an invitation, matching only generates staff candidates:

- one exact verified contact + exact normalized name + exact date of birth is
  ranked first but still requires approval;
- weaker or duplicate evidence remains pending;
- no match leaves the account unlinked;
- archived or already-linked candidates fail closed.

Candidate identities and counts never enter mobile responses.

### Appointment requests are schedule blocks, not Appointments

Every mobile booking creates an `AppointmentRequest`. It stores a preferred
start, encrypted free-text reason, a 30-minute provisional duration, a
24-hour expiry, the submitting account, and a nullable resolved patient.

The availability engine is refactored to evaluate generic schedule blocks
produced from:

- non-cancelled/non-no-show Appointments; and
- unexpired pending Appointment Requests.

Clinic/provider working hours and schedule overrides remain staff-configured.
The patient does not free-type availability: the mobile API returns
server-generated slots, and the patient chooses a preferred slot from that
list. If staff adjusts a request, staff uses the same availability rules and
selects another valid slot before immediate confirmation.

Availability ignores `expires_at <= now()` even when the expiry command has
not yet updated the request state.

Acceptance:

1. requires a resolved active Patient;
2. locks the request and schedule date in the canonical order;
3. confirms the internal Appointment Type;
4. rechecks availability using the final duration while excluding the
   request's own provisional hold;
5. creates one scheduled Appointment;
6. stores its unique ID on the request;
7. marks the request accepted;
8. dispatches notifications after commit.

Repeated acceptance returns the same Appointment. It never creates a second
one.

### Encounter owns clinician-authored consultation data

Check-in continues to create exactly one `planned` Encounter. Patient intake
routes and patient-submitted intake states are retired.

The following encrypted fields move to Encounter:

- `chief_complaint`;
- `past_ocular_history`;
- `past_surgical_history`;
- `past_medical_history`;
- `allergies`;
- `medications`;
- existing `findings`;
- existing `remarks`;
- `plan`.

Encounter also stores `last_wizard_step`, `draft_saved_at`, and
`completed_by`. The reason for visit copied to the confirmed Appointment
prefills `chief_complaint` at check-in but the optometrist may correct it.

Starting an Encounter locks the Encounter and Appointment, transitions only
the Encounter to `in_progress`, and leaves the Appointment `checked_in`.
Completing it locks both rows, validates required clinical fields, transitions
the Encounter to `completed`, and transitions the Appointment to `fulfilled`
in one transaction.

Prescription stays a separate related audited record. The Encounter can be
completed without one.

### Optical Order is an aggregate workflow, not a merged table

Quotation remains the aggregate root for new Optical Orders because every new
Job Order is created from an accepted immutable quotation revision and shares
its stable `eyewear_key`. Job Order remains authoritative for committed items
and fulfillment. Its unique Billing Record remains authoritative for the
receivable and payment ledger. The rationale and alternatives are recorded in
`docs/decisions/001-unify-optical-order-admin-workflow.md`.

One `AcceptAndStartOpticalOrder` transaction:

1. locks and accepts the latest eligible quotation revision;
2. creates or returns exactly one queued Job Order;
3. creates or returns exactly one unpaid Billing Record;
4. records an optional deposit against that Billing Record;
5. converts an eligible Frame Reservation when present;
6. dispatches notifications only after commit.

The Job Order receives an optional unique Frame Reservation link. When the
reservation is prepared, conversion records an atomic inventory transfer:
reserved variants selected for the order receive an offsetting reservation
release and one order commitment, producing zero additional net stock change.
Prepared variants not selected for the order are released. An unprepared
reservation uses the normal locked order commitment once. The reservation then
becomes `converted`.

Dispensing requires the existing Billing Record and creates only the
Dispensing Event. It may record a final payment but never creates another
Billing Record. Cancellation reverses the Job Order commitment; unpaid billing
may be voided atomically, while posted payments require explicit authorized
reversal/refund handling so financial history is never erased.

Filament uses Quotation as the resource anchor for new aggregate rows and loads
the related Job Order, Billing Record, and Frame Reservation. A compatibility
audit identifies any active legacy Job Order without a quotation before its
standalone navigation is hidden.

## Data Model

### `users` additions

- `first_name` nullable string;
- `last_name` nullable string.

Existing staff/admin rows remain valid without structured names. New and
fully migrated patient accounts require both plus the existing
`date_of_birth`.

### `patient_account_contacts`

- `id`;
- `user_id` foreign key;
- `type`: `email` or `phone`;
- encrypted `value`;
- unique `lookup_hash`;
- `verified_at`;
- `is_primary`;
- timestamps.

Constraints:

- unique `(user_id, type)`;
- one lookup hash belongs to one patient account;
- application transaction enforces exactly one primary verified contact.

### `otp_challenges`

- opaque public ID;
- nullable `user_id`;
- purpose and channel;
- encrypted destination;
- destination blind index;
- code digest;
- attempts and maximum attempts;
- `expires_at`, `last_sent_at`, `consumed_at`, `invalidated_at`;
- sanitized delivery state;
- timestamps.

Indexes support challenge lookup, expiry pruning, destination/purpose
throttling, and pending-delivery processing.

### `patient_link_requests`

- opaque request number;
- `user_id`;
- encrypted identity snapshot;
- state;
- reviewed patient, reviewer, decision note, and decision timestamps;
- timestamps.

Only one active link request is permitted per account through a transaction
and indexed lookup.

### `patient_link_candidates`

- link-request and patient foreign keys;
- match strength;
- non-clinical reason-code JSON;
- rank;
- timestamps.

Candidate rows are staff-only and never serialized to Android.

### `patient_invitations`

- opaque public ID and secret digest;
- patient and sender foreign keys;
- channel;
- encrypted destination and blind index;
- state;
- expiry, sent, revoked, accepted, and failure timestamps;
- nullable accepting account;
- timestamps.

Only one active invitation per patient and destination is enforced by the
issuing transaction. Resending revokes and replaces the prior secret.

### `appointment_requests`

- opaque request number;
- `user_id`;
- nullable `patient_id`;
- nullable staff-confirmed `appointment_type_id`;
- nullable unique resulting `appointment_id`;
- preferred `scheduled_at`;
- `provisional_duration_minutes` defaulting to 30;
- encrypted `reason_for_visit`;
- encrypted account/contact identity snapshot;
- state and `expires_at`;
- resolution, adjustment, and decision actor/timestamps;
- timestamps.

Required indexes:

- `(state, created_at)`;
- `(state, scheduled_at)`;
- `(state, expires_at)`;
- `(user_id, state)`;
- patient, appointment-type, and appointment foreign keys.

### `patients` additions

- nullable indexed `contact_email_lookup_hash`;
- nullable indexed `phone_lookup_hash`.

These are non-unique because clinic records may contain legitimate or
duplicate shared contact values. Duplicate review remains mandatory.

### `appointments` addition

- encrypted nullable `reason_for_visit`.

Accepted mobile requests copy their reason here so the scheduler and
optometrist retain the submitted context. Staff-created appointments may
enter it directly.

### `encounters` additions

- the encrypted consultation/history fields listed above;
- encrypted nullable `plan`;
- nullable `last_wizard_step`;
- nullable `draft_saved_at`;
- nullable `completed_by` foreign key.

The existing unique `appointment_id` continues to enforce one Encounter per
Appointment.

### `job_orders` addition

- nullable unique `frame_reservation_id` foreign key.

This link makes reservation conversion traceable and idempotent. Quotations,
Job Orders, Billing Records, and their existing `eyewear_key` are otherwise
retained rather than replaced by an Optical Order table.

## Patient Intake Retirement

The old intake workflow is removed with an incremental cutover:

1. Add Encounter clinical fields without dropping anything.
2. Backfill every existing Encounter from its linked `patient_intakes` row.
3. Switch check-in and Encounter UI to write/read Encounter fields.
4. Remove patient intake API routes and update the coordinated Android
   contract.
5. Stop creating or editing `patient_intakes`.
6. During the compatibility window, check-in may copy a legacy verified
   appointment intake into the new Encounter fields.
7. Add a read-only audit query/test proving no active future Appointment
   depends on an unmigrated intake.
8. Only then drop `encounters.patient_intake_id`, the Intake Filament page,
   obsolete actions/controllers/resources/tests, and finally the
   `patient_intakes` table in a later cleanup migration.

Deployed migration files are never edited. Additive schema, data backfill, code
cutover, and destructive cleanup remain separate migrations/checkpoints.

## Proposed `/api/v1` Contract

Exact JSON schemas and error examples must be written to
`docs/API_CONTRACT.md` before implementation begins.

### Public authentication

- `POST /api/v1/auth/registration/otp`
- `POST /api/v1/auth/registration/verify`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/login/verify`
- `POST /api/v1/auth/password-recovery/otp`
- `POST /api/v1/auth/password-recovery/verify`

Registration verification receives the challenge/code, first and last name,
date of birth, password confirmation, accepted privacy-notice version, and
device installation/name. It creates no Patient.

Password login returns a generic step-up result and never issues a token until
OTP verification succeeds.

### Authenticated account-only access

- `POST /api/v1/logout`
- `POST /api/v1/logout-all`
- `GET /api/v1/me`
- `PATCH /api/v1/me`
- `GET /api/v1/account/contacts`
- `POST /api/v1/account/contacts/otp`
- `POST /api/v1/account/contacts/verify`
- `PATCH /api/v1/account/contacts/{contact}/primary`
- `DELETE /api/v1/account/contacts/{contact}`
- `GET /api/v1/account/link`
- `POST /api/v1/patient-link-requests`
- `GET /api/v1/patient-link-requests/current`
- `POST /api/v1/patient-invitations/acceptance/otp`
- `POST /api/v1/patient-invitations/accept`
- `GET /api/v1/appointment-request-availability`
- `GET /api/v1/appointment-requests`
- `POST /api/v1/appointment-requests`
- `GET /api/v1/appointment-requests/{appointmentRequest}`
- `POST /api/v1/appointment-requests/{appointmentRequest}/cancel`

An unlinked account may use these routes. Link and appointment requests use
the self-profile date of birth collected during registration.

### Active-link access

Existing confirmed-appointment, prescription, quotation, job-order, billing,
eyewear, reservation, rating, and conversation routes remain behind
`auth:sanctum` plus a dedicated active-patient-link boundary.

Breaking changes in the coordinated Android cutover:

- remove direct mobile `POST /api/v1/appointments`;
- remove patient-selectable `GET /api/v1/appointment-types`;
- remove the three `/appointments/{appointment}/intake` routes;
- appointment-request availability uses the server-owned provisional
  duration;
- confirmed Appointment rescheduling derives duration and type from the owned
  Appointment.

### Error and idempotency contract

All errors use:

```json
{
  "error": {
    "code": "MACHINE_READABLE_CODE",
    "message": "Patient-safe message",
    "details": {}
  }
}
```

Ownership-sensitive misses use `404`. Validation uses `422`, unauthenticated
requests use `401`, authorization uses `403` only when existence disclosure is
safe, state conflicts use `409`, and throttling uses `429`.

Registration verification, OTP consumption, invitation acceptance, link
approval, and appointment-request acceptance are idempotent at their
challenge/request boundary.

## Proposed Security and Operations Values

Plan approval includes these defaults:

### Password

- minimum length: 12 characters;
- confirmation required at creation/reset;
- no forced composition rule;
- compromised-password check enabled in production;
- framework `hashed` cast used for storage.

### OTP

- format: six numeric digits;
- lifetime: 10 minutes;
- maximum verification attempts: 5 per challenge;
- resend cooldown: 60 seconds;
- resend invalidates all earlier challenges for the same purpose/destination;
- issue limit: 3 per 15 minutes per destination;
- issue limit: 10 per 15 minutes per IP address;
- daily limit: 10 per destination;
- verification limit: 10 per 15 minutes per destination and 20 per 15 minutes
  per IP, in addition to the five-attempt challenge cap;
- exhausted challenges are invalidated immediately;
- terminal challenge records are pruned after 30 days.

Limiter keys contain purpose-prefixed blind indexes, never raw contacts.

### Patient tokens

- expiry: 30 days;
- maximum active tokens: 5;
- same-installation issuance replaces the prior token;
- expired token rows pruned daily after a 24-hour grace period.

### Invitations and link requests

- invitation lifetime: 7 days;
- invitation resend cooldown: 5 minutes;
- resend revokes the prior secret;
- pending invitation, link-request, and decision history retention: 2 years;
- invitation secrets never appear in logs or audit metadata.

### Appointment requests

- provisional hold: 30 minutes of capacity;
- expiry: 24 hours after submission;
- maximum active pending requests: 2 per account;
- terminal request retention: 2 years;
- expiry command: every minute, idempotent;
- availability ignores expired holds independently of command timing.

These retention values are operational defaults, not a claim of statutory
minimum or maximum retention. Production policy review may override them
through configuration before launch.

## Laravel Service Boundaries

Thin controllers and Filament actions share typed application actions.

### Authentication and contacts

- request/consume OTP challenge;
- register patient account;
- password login and step-up;
- recover password;
- add/verify/remove/set-primary contact;
- issue/revoke device token.

### Linking and invitations

- normalize and rank patient candidates;
- submit/review/approve/reject link request;
- issue/revoke/resend invitation;
- accept invitation;
- unlink account with admin reason.

### Patients

- search possible duplicates;
- confirm patient creation;
- use the same actions from Patients, Appointments, walk-in, and request
  resolution entry points.

### Appointment requests

- submit/cancel/expire request;
- resolve patient;
- adjust schedule and confirm type;
- accept/reject request;
- convert request to Appointment.

### Scheduling

- introduce a schedule-block value object;
- produce blocks from Appointments and active request holds;
- preserve the existing schedule-date lock;
- use one evaluator for availability listing, request submission, acceptance,
  direct booking, and rescheduling.

### Encounters

- check in and create one planned Encounter;
- start Encounter under row locks;
- save each wizard stage;
- complete Encounter and fulfill Appointment atomically.

Policies/Form Requests authorize every boundary. Eloquent API Resources own
all mobile serialization.

## Filament Admin Plan

### Appointments

One visible **Appointments** navigation destination contains:

- Requests;
- Upcoming;
- Today;
- History;
- Calendar.

Appointment Requests retain their own model, policy, query, table, and review
page. An internally hidden Resource or custom Appointment Resource page may
host them, but the implementation must not union unrelated models into one
`ListRecords` query.

Requests show:

- request number and age;
- account/patient identity appropriate to staff authorization;
- masked contact;
- link readiness;
- free-text reason;
- preferred time and expiry;
- current schedule capacity;
- review/resolve actions.

### Patients

Patient creation begins with duplicate search at every entry point. Patient
pages show app-access state and actions to send/revoke/resend invitations.
Raw `user_id` editing is removed.

### Appointments form

The form retains only operational scheduling fields, reason for visit,
assignment, lifecycle actions, and **Open Encounter** after check-in. It has no
Patient Health Record or intake page/action.

When a confirmed Appointment has a Frame Reservation, the page also shows a
compact operational reservation card with its status, requested variants, and
authorized prepare/release/open actions. Appointment list rows may show a
reservation badge. The dedicated Frame Reservations resource remains the
cross-appointment preparation queue; both surfaces operate on the same record.
Appointment Requests never display or create a Frame Reservation.

### Optical Orders

One visible **Optical Orders** destination replaces separate primary
navigation entries for Quotations, Job Orders, and Billing Records. It
provides:

- Draft Estimates;
- Awaiting Decision;
- In Preparation;
- Ready for Pickup;
- Payment Due;
- Completed.

The aggregate detail page shows one timeline with fulfillment stage and
payment status as separate badges. Context-sensitive actions include **Save
draft**, **Present estimate**, **Accept & start order**, **Start preparation**,
**Mark ready**, **Record payment**, **Dispense**, and **Cancel**.

Appointment, Encounter, and Frame Reservation pages expose a contextual
**Create/Open Optical Order** action with eligible patient, Encounter,
Prescription, and reserved-frame context prefilled. The underlying Quotation,
Job Order, and Billing Record resources remain policy-protected implementation
surfaces and are hidden from primary navigation only after the legacy
compatibility audit passes.

### Encounter wizard

The in-progress Encounter uses a full-page Filament Wizard:

1. Consultation & History;
2. Examination;
3. Prescription & Plan;
4. Review & Complete.

The current step is represented in the query string and persisted to
`last_wizard_step` after a successful step save. Steps become freely
revisitable only after their first valid save. Each transition validates and
saves its own fields; the last step does not rely on one unsaved form payload.

The prescription step links to the separate prescription create/view flow.
Completed Encounters render a read-only clinical summary rather than an
editable wizard.

## Implementation Order

### Stage 1: Contract and regression baseline

- Freeze exact `/api/v1` request/response/error schemas.
- Record the coordinated Android cutover.
- Add characterization tests for staff auth, patient auth, direct
  appointments, availability, linking, check-in, Encounter, prescription, and
  fulfillment behavior.

**Checkpoint:** Existing behavior is reproducible and the intended breaking
contract is explicit.

### Stage 2: Additive schema and compatibility

- Add contact, OTP, link, invitation, and appointment-request foundations.
- Add blind indexes and structured patient-account names.
- Add Appointment reason and Encounter clinical/wizard fields.
- Backfill existing Encounter data without dropping intake structures.

**Checkpoint:** Migrations run forward/backward where safe, existing tests
pass, and no existing clinical history is lost.

### Stage 3: Authentication and active-link boundary

- Implement provider-safe OTP delivery.
- Implement registration, hybrid login, recovery, contacts, and token
  management.
- Add active-link middleware/policies before exposing any linked data.
- Migrate existing patient accounts through the approved compatibility path.

**Checkpoint:** Account-only and active-link access matrices pass, including
concurrency and enumeration tests.

### Stage 4: Linking, invitations, and duplicate-safe patient creation

- Implement staff-reviewed matching.
- Implement record-specific invitations.
- Centralize duplicate search and confirmed patient creation.
- Retrofit each Filament patient-creation entry point.

**Checkpoint:** One-to-one link constraints and all invitation race cases pass.

### Stage 5: Schedule blocks and appointment requests

- Refactor availability around generic schedule blocks.
- Add request submission/status/cancellation/expiry.
- Add staff resolution/adjustment/acceptance/rejection.
- Convert accepted requests through the existing Appointment lifecycle.

**Checkpoint:** Request holds and confirmed Appointments cannot overbook or
double-convert under concurrency.

### Stage 6: Unified Filament appointment experience

- Add request pages under the existing Appointments destination.
- Remove raw patient-account linkage inputs.
- Add invitation/link/request review actions and badges.
- Add the contextual Frame Reservation card and list badge while preserving
  the dedicated operational queue.
- Preserve existing calendar, direct staff booking, and walk-in behavior.

**Checkpoint:** Filament policy, table, action, and navigation tests pass.

### Stage 7: Unified Optical Orders

- Add the traceable Frame Reservation-to-Job Order link.
- Transfer prepared reservation allocation into order commitment without
  double-decrementing stock.
- Create the accepted Job Order and Billing Record atomically, with optional
  deposit.
- Change dispensing to use the existing Billing Record.
- Add the aggregate Optical Orders queue/detail workflow and contextual entry
  actions.
- Hide separate primary resource navigation after legacy compatibility checks.

**Checkpoint:** One staff workflow covers estimate through dispensing and
payment; retries create no duplicate records and reservation conversion
preserves exact inventory.

### Stage 8: Encounter consultation cutover

- Switch check-in to initialize Encounter clinical draft fields.
- Move history editing into the Encounter wizard.
- Leave Appointment checked in during an active Encounter.
- Fulfill Appointment only during Encounter completion.
- Remove Appointment intake UI and patient intake API operations.

**Checkpoint:** The full check-in-to-completion workflow passes with resumable
drafts and optional prescription.

### Stage 9: Cleanup and release verification

- Verify no active consumer or future Appointment depends on legacy intake.
- Remove deprecated intake code and later drop legacy schema.
- Run full backend, Filament, API contract, security, build, and formatting
  verification.
- Update `docs/BACKEND_CONTEXT.md` and `docs/API_CONTRACT.md` to the delivered
  state.

**Checkpoint:** No legacy route/runtime references remain and all approved
success criteria pass.

## Sequential and Parallel Work

Must remain sequential:

- API contract before endpoint implementation;
- additive schema before model/actions;
- authentication before invitation acceptance;
- active-link middleware before clinical access;
- schedule-block refactor before request holds;
- reservation preparation before allocation transfer;
- quotation acceptance before Job Order and Billing Record creation;
- legacy optical-order audit before hiding standalone resource navigation;
- Encounter backfill before intake cleanup.

May proceed independently after foundations stabilize:

- Filament linking/invitation pages;
- OTP email and SMS provider tests;
- Encounter wizard presentation;
- Optical Order aggregate list/detail presentation;
- appointment-request Android contract fixtures;
- documentation reconciliation.

The Phase 3 task list converts these stages into focused tasks of approximately
five files or fewer. Parallel implementation is not authorized by this plan
alone.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Existing patient accounts lose access | High | Compatibility contacts, explicit first-login verification, regression fixtures, coordinated Android cutover |
| Duplicate contact/account under concurrent OTP verification | High | Blind-index uniqueness, challenge/account row locks, idempotent transaction |
| Invitation links wrong clinical patient | High | Record-specific patient/destination, OTP, live eligibility recheck under locks |
| Raw PII leaks through logs/cache/queue | High | Blind-index limiter keys, encrypted casts/jobs, sanitized audit metadata |
| Request holds disagree with booking acceptance | High | One schedule-block evaluator, date lock, expiry checked in query and transaction |
| Request acceptance creates duplicate Appointment | High | Request row lock, unique `appointment_id`, terminal-state idempotency |
| Optical Order retry creates duplicate fulfillment or billing | High | Quotation/revision row locks, unique quotation and billing links, idempotent aggregate action |
| Prepared reservation deducts frame stock twice | High | Atomic reservation-release/order-commitment transfer with net-zero stock and ledger assertions |
| Order cancellation erases deposit history | High | Append-only payment reversal/refund records before billing closure |
| Legacy intake history is lost | High | Additive Encounter fields, tested backfill, compatibility read, delayed drop |
| Encounter and Appointment states diverge | High | Canonical lock order and atomic start/complete actions |
| Filament mixes two Eloquent models unsafely | Medium | Separate hidden request resource/custom page under one visible navigation destination |
| Existing dirty Appointment UI changes are overwritten | Medium | Treat current Appointment form/test/theme edits as user-owned and reconcile at implementation time |
| Unified admin hides legacy unanchored Job Orders | Medium | Compatibility audit and fallback access before hiding old navigation |
| OTP provider outage blocks onboarding | Medium | Committed challenge state, queued retries, failure state, resend path, provider abstraction |
| Encrypted narrative becomes unsearchable | Low | Intentional; do not promise clinical full-text search |

## Verification Strategy

Every implementation slice receives focused Pest coverage before broader
regression.

Required suites include:

- API route and JSON contract tests;
- OTP expiry, replay, throttling, delivery failure, and concurrency;
- password login/step-up/recovery and token revocation;
- active-link route matrix and cross-account ownership;
- invitation and staff-link race conditions;
- duplicate patient search/override authorization;
- appointment-request lifecycle, expiry, capacity, and idempotency;
- Filament request/link/invitation actions and navigation;
- Optical Order aggregate lifecycle, deposit, cancellation, and idempotency;
- reservation-to-order inventory transfer and reversal;
- Encounter wizard validation, draft persistence, authorization, and
  completion;
- intake backfill and zero-loss migration;
- existing appointment, prescription, quotation, fulfillment, and walk-in
  regressions.

Final verification commands remain Sail-only:

```text
vendor/bin/sail artisan test --compact [focused paths]
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
```

No custom verification scripts replace Pest coverage.

## Phase 2 Approval

The project owner approved this plan on 2026-07-31, confirming:

- the architecture and implementation order;
- the proposed API surface direction;
- the OTP/password/token/invitation/request defaults;
- the additive intake-to-Encounter migration and delayed cleanup;
- the full-page Encounter wizard;
- date of birth during mobile-account registration;
- contextual plus operational Frame Reservation views;
- the unified Optical Orders workflow, order-confirmation billing, deposits,
  and inventory-allocation transfer;
- no dependency additions.

The Phase 3 task list now contains discrete tasks with acceptance criteria,
verification commands, dependencies, and likely files. No implementation
begins until that task list is separately approved.
