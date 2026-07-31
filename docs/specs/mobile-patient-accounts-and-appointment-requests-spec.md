# Spec: Mobile Patient Accounts, Record Linking, Appointment Requests, and Optical Orders

## Status

Phase 1 specification approved by the project owner on 2026-07-31.

Phase 2 technical plan approved by the project owner on 2026-07-31.

Phase 3 task decomposition is authorized. Migrations, routes, application
code, tests, and Android changes remain unauthorized until the Phase 3 task
list is separately approved.

## Objective

Replace the current patient registration flow with a NowServing-inspired
mobile account and contact-verification model while preserving the clinic's
patient record as the authoritative clinical identity.

The feature serves:

- A patient creating a mobile account for themselves.
- A patient whose clinic record already exists.
- A patient who creates a mobile account before the clinic can identify their
  record.
- Clinic staff registering patients and reviewing possible duplicates.
- Clinic staff inviting an existing patient to use the mobile app.
- Clinic staff reviewing mobile appointment requests and converting approved
  requests into confirmed appointments.
- Optometrists conducting a checked-in visit through one resumable Encounter
  wizard for history, examination, prescription context, and completion.

Success means:

- A patient can create an account after proving control of an email address or
  phone number.
- Creating a mobile account never automatically creates a clinical patient
  record.
- A mobile account and a clinic patient record can exist independently.
- One mobile account links to at most one patient, and one patient links to at
  most one mobile account.
- Clinical information remains unavailable until an active link exists.
- Staff duplicate review precedes every new clinical patient record created
  from the mobile workflow.
- Every mobile booking begins as an appointment request requiring clinic
  review.
- Staff manage requests and confirmed appointments through one coherent
  **Appointments** admin destination.
- Appointment pages remain operational scheduling surfaces while the Encounter
  is the canonical clinical consultation workspace.
- Staff manage estimate, fulfillment, and payment through one coherent
  **Optical Orders** admin workflow while preserving separate auditable
  Quotation, Job Order, and Billing Record models.

## Confirmed Decisions and Assumptions

1. The mobile account is for the account owner only.
2. Children, dependents, guardians, family profiles, and delegated access are
   outside this phase.
3. Account-to-patient and patient-to-account cardinality are both one-to-one.
4. A patient may permanently exist without a mobile account.
5. A newly created mobile account may remain unlinked.
6. The clinic patient record remains authoritative for clinical demographics,
   appointments, prescriptions, eyewear, billing, and communication.
7. Updating an account login contact never silently updates the clinic patient
   record.
8. Account registration verifies either email or phone through OTP.
9. Patient authentication is hybrid: the patient creates their own password,
   email or phone OTP verifies contacts and supports recovery, and a new or
   untrusted device requires OTP step-up verification. Staff/admin
   authentication is not part of that change.
10. Verifying one contact is sufficient to create the account.
11. A patient may add and verify the other contact method later.
12. A verified login contact belongs to only one mobile account.
13. Staff never set a patient password, create a temporary password, verify an
    OTP for a patient, or activate a mobile account on a patient's behalf.
14. Staff-created patients do not automatically receive mobile accounts.
15. Staff may send an expiring invitation tied to one patient record and one
    recorded contact method.
16. A mobile booking is an appointment request, not a confirmed appointment.
17. Linked and unlinked accounts both use the appointment-request flow.
18. A linked account's request begins with its authoritative `patient_id`.
19. An unlinked account must be resolved to a patient before staff can accept
    its request.
20. Accepting a request creates a normal `scheduled` appointment.
21. Staff-created and walk-in appointments continue to create appointments
    directly.
22. The Filament panel exposes one **Appointments** navigation destination.
23. Requests and confirmed appointments remain separate domain records even
    when they share one admin experience.
24. Confirmed appointments remain the only normal records shown on the main
    operational calendar and used for check-in and encounters.
25. Android application code is outside this repository. This repository owns
    the versioned API contract consumed by Android.
26. Patient verified contacts use dedicated contact-method records. Existing
    staff/admin email authentication remains on `users` and is not migrated to
    the patient mobile authentication flow.
27. Patient accounts store structured first and last names while retaining a
    derived display name for compatibility. Patient records retain their
    independent clinic-authoritative `full_name`.
28. Proving an already-owned contact during a registration-style flow never
    creates a duplicate account. The eventual sign-in or recovery behavior
    follows the approved long-term authentication strategy. Pre-verification
    responses remain enumeration-safe.
29. Sanctum tokens are device-labelled. Logging out revokes the current token;
    an explicit logout-all action revokes all device tokens.
30. Staff and admins may review matches, approve eligible links, create
    patients after duplicate review, send invitations, and accept/reject
    appointment requests. Unlinking an active account remains admin-only.
31. Initial patient-account registration requires first name, last name, and
    date of birth. Date of birth is self-declared matching information, not
    contact-verification evidence, and never overwrites the clinic Patient's
    authoritative date of birth.
32. A pending appointment request holds its requested capacity for a
    configurable 24-hour clinic response window.
33. When staff adjusts a requested time and accepts the request, the adjusted
    time becomes the confirmed appointment immediately and the patient is
    notified. A second patient-acceptance step is not required.
34. Mobile appointment booking collects one required free-text **Reason for
    visit**. The patient does not select a reason category.
35. Automatic patient-account link activation is limited to a clinic-issued
    invitation tied to one patient record and the exact destination verified
    by the patient. Demographic matching without an invitation may rank
    candidates for staff but never activates a link by itself.
36. Staff confirms the internal Appointment Type when accepting a mobile
    request. The system may prefill **New Patient** when the resolved patient
    has no fulfilled clinical visit, but staff remains responsible for the
    final type.
37. A pending mobile request provisionally holds 30 minutes. Acceptance
    rechecks availability using the finalized Appointment Type's duration and
    either succeeds at the approved time or requires staff to choose another
    available time.
38. There is no separate patient-completed pre-visit intake workflow. Mobile
    patients submit only the booking's free-text reason for visit.
39. Check-in creates the appointment's single `planned` Encounter. Appointment
    confirmation does not create an Encounter.
40. Starting consultation transitions the Encounter from `planned` to
    `in_progress` while the Appointment remains `checked_in`. Completing the
    Encounter transitions it to `completed` and the Appointment to
    `fulfilled` in the same transaction.
41. The Encounter is a resumable full-page wizard with these ordered stages:
    **Consultation & History**, **Examination**, **Prescription & Plan**, and
    **Review & Complete**.
42. The booking's reason for visit prefills the Encounter chief complaint but
    remains clinician-editable and must be confirmed during consultation.
43. Patient-authored intake status, submission, and verification states are
    retired. The encrypted complaint and history fields move into the
    Encounter as clinician-authored draft clinical data.
44. Prescription remains a separate, audited related record. The wizard may
    create or open it but does not flatten prescription fields into the
    Encounter and does not require a prescription for every completed visit.
45. Each wizard stage persists a draft before navigation. Authorized
    optometrists may revisit stages while the Encounter is `in_progress`;
    completion is a distinct validated and confirmed action.
46. The Appointment page contains scheduling, attendance, assignment, reason
    for visit, and **Open Encounter** behavior only. It contains no intake or
    Patient Health Record section, action, or printable clinical record.
47. A patient-created Frame Reservation begins in `requested` state and
    requires both an active Patient link and a confirmed eligible Appointment.
    An Appointment Request never reserves frames or allocates inventory.
48. Staff see the same Frame Reservation in two places: a contextual summary
    and actions on its Appointment, and the existing dedicated operational
    Frame Reservations queue. These are two views of one record, not duplicate
    reservation storage.
49. Quotation, Job Order, and Billing Record remain separate auditable domain
    records, but staff use one visible **Optical Orders** destination and one
    aggregate detail workflow instead of navigating three independent
    resources.
50. Staff may start an Optical Order contextually from the Appointment,
    Encounter, or Frame Reservation. Patient, Encounter, Prescription, and
    reserved-frame context are prefilled when eligible.
51. The Optical Order workflow supports **Save draft**, **Present estimate**,
    and **Accept & start order**. The last action atomically accepts the latest
    quotation revision, creates exactly one queued Job Order, and creates
    exactly one Billing Record.
52. Billing begins when the accepted order is confirmed rather than when it is
    dispensed. Staff may record an initial deposit or later payments against
    the same Billing Record. Dispensing links its event to that Billing Record
    and does not create a second one.
53. Converting a prepared Frame Reservation to a Job Order transfers its
    existing inventory allocation to the order commitment without decrementing
    stock twice. Converting an unprepared request performs one normal locked
    order commitment.
54. Job Order cancellation reverses its inventory commitment. A Billing Record
    with no payment may be voided in the same workflow; posted deposits require
    an explicit authorized payment reversal or refund record before final
    billing closure.
55. Optical Order progress and payment state remain separate dimensions:
    estimate/production/dispensing communicates fulfillment, while
    unpaid/partially-paid/paid/voided communicates finance.

## Out of Scope

- Dependent, child, guardian, proxy, or caregiver profiles.
- One account linked to multiple patients.
- Multiple accounts linked to one patient.
- Staff-assisted OTP entry.
- Social login or identity-provider login.
- Government-ID or biometric verification.
- Automatically creating a patient because a mobile account or request exists.
- Automatically merging clinical patient records.
- Patient-side editing of authoritative clinic demographics.
- Direct, instantly confirmed mobile appointments.
- Patient-completed pre-visit health-history or intake forms.
- Merging Quotations, Job Orders, and Billing Records into one database table.
- Teleconsultation, marketplace checkout, online payment gateways, HMO,
  consumer wallets, or medicine-delivery behavior copied from NowServing.
- Android screen implementation.

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
- Existing Laravel database queues and cache
- Existing email configuration
- Existing Semaphore SMS provider boundary, subject to the security constraints
  in this specification

No dependency changes are approved by this specification.

## Commands

- Start services: `vendor/bin/sail up -d`
- Inspect relevant routes:
  `vendor/bin/sail artisan route:list --except-vendor --path=api/v1`
- Run focused authentication tests:
  `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/Auth`
- Run focused patient-link tests:
  `vendor/bin/sail artisan test --compact tests/Feature/Patients`
- Run focused appointment-request tests:
  `vendor/bin/sail artisan test --compact tests/Feature/Appointments`
- Run focused Filament tests:
  `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Filament/PatientResourceTest.php`
- Run the full suite: `vendor/bin/sail artisan test --compact`
- Format modified PHP:
  `vendor/bin/sail bin pint --dirty --format agent`
- Build frontend assets when required:
  `vendor/bin/sail npm run build`

Exact focused test paths may be reconciled in the implementation plan with
tests already present in the repository. New Pest tests must be created with
Artisan and Sail rather than custom verification scripts.

## Project Structure

- `app/Actions/Auth/` — requesting and consuming contact-verification or
  recovery challenges and issuing patient Sanctum tokens through the approved
  authentication strategy.
- `app/Actions/PatientAccounts/` — contact verification, matching, link
  activation, unlinking, and invitation acceptance.
- `app/Actions/Appointments/` — appointment-request submission, resolution,
  acceptance, rejection, cancellation, expiry, and conversion to appointments,
  alongside the existing appointment actions.
- `app/Contracts/` — provider boundaries when an existing framework contract
  is not sufficient.
- `app/Enums/` — typed channel, challenge purpose, invitation, link-request,
  and appointment-request states.
- `app/Filament/Resources/Appointments/` — the unified request, list, calendar,
  review, and confirmed appointment experience.
- `app/Filament/Resources/OpticalOrders/` — the aggregate staff queue and
  detail workflow over Quotations, Job Orders, Billing Records, and contextual
  Frame Reservations.
- `app/Filament/Resources/Patients/` — duplicate-safe creation and app-access
  invitation/link status.
- `app/Http/Controllers/Api/` — thin versioned mobile API controllers.
- `app/Http/Middleware/` — active patient-link enforcement for clinical mobile
  routes.
- `app/Http/Requests/Api/` — command-specific validation and authorization.
- `app/Http/Resources/` — stable patient-safe response contracts.
- `app/Models/` — account contacts, challenges, invitations, link requests,
  and appointment requests.
- `app/Notifications/` and `app/Jobs/` — secure after-commit OTP and invitation
  delivery.
- `database/migrations/` — focused, reversible schema additions and indexes.
- `database/factories/` — explicit linked, unlinked, pending, expired, and
  resolved workflow states.
- `tests/Feature/Api/V1/` — public contract, authentication, authorization,
  anti-enumeration, and ownership coverage.
- `tests/Feature/Appointments/` — request lifecycle, capacity, and conversion
  coverage.
- `tests/Feature/Filament/` — staff queue, duplicate review, invitation, and
  request actions.
- `docs/API_CONTRACT.md` — authoritative Android request/response contract.
- `docs/BACKEND_CONTEXT.md` — living schema, role, route, and workflow context.

No new top-level application directories are required.

## Code Style

Public controllers and Filament actions must delegate workflow mutations to
the same single-purpose action classes. Actions use explicit parameter and
return types and own transaction boundaries.

```php
$appointment = $acceptAppointmentRequest->handle(
    appointmentRequest: $appointmentRequest,
    actor: $request->user(),
    scheduledAt: $scheduledAt,
);
```

Additional conventions:

- Use descriptive names such as `hasActivePatientLink` and
  `verifiedContactMethod`; avoid ambiguous names such as `profile`.
- Use PHP backed enums with TitleCase keys for finite workflow values.
- Use constructor property promotion for dependencies.
- Use Form Requests for API boundary validation.
- Persist only validated data.
- Use Eloquent relationships with explicit generic return types.
- Use API Resources for mobile response bodies.
- Use transactions, row locks, and idempotent actions for one-time
  verification and conversion workflows.
- Use named Filament actions such as **Accept request**, **Link patient**, and
  **Send app invitation**.
- Do not expose generic status or raw foreign-key inputs when a workflow action
  should control the change.

## Domain Language and Model

### Mobile account

A `User` with the `patient` role that may authenticate through verified contact
methods. It is an access identity, not a clinical record.

### Verified contact method

An email address or phone number proven through a single-use OTP challenge.
It may be used for patient registration, authentication or recovery,
invitation acceptance, and notification routing according to its verified
state and purpose.

Dedicated contact-method records are authoritative for patient mobile
identifiers.
Email addresses are normalized by trimming and canonical case handling. Phone
numbers are normalized to one canonical international representation before
uniqueness checks, throttling, or matching.

### Patient

The clinic-authoritative clinical identity. It owns the patient number and all
clinical and fulfillment relationships. It may exist without an account.

### Active patient link

The approved one-to-one association between one mobile account and one patient.
The existing nullable unique `patients.user_id` remains the authoritative
active link unless the implementation plan demonstrates that a separate active
link table is required.

Pending, rejected, expired, and historical link attempts must not be encoded
in `patients.user_id`.

### Patient-link request

An auditable attempt to associate an unlinked account with a patient. It
records the outcome and review evidence without exposing candidate records to
the mobile client.

### Patient invitation

A single-use, expiring invitation created by staff from one patient record and
addressed to one recorded email address or phone number.

### Appointment request

A patient's requested appointment details before clinic acceptance. It may
reference an authoritative patient immediately when the account is linked, or
remain without a patient while staff resolves identity.

### Appointment

A clinic-approved scheduled event attached to one authoritative patient. It
continues using the existing appointment lifecycle:

```text
scheduled -> checked_in -> fulfilled
    |             |
    +-------------+-----> cancelled
    +-------------------> no_show
```

An appointment request is never checked in and never creates an Encounter.

### Encounter consultation

Check-in creates one `planned` Encounter for the confirmed Appointment.
Starting consultation changes only the Encounter to `in_progress`; the
Appointment remains `checked_in` so that it is not represented as fulfilled
before care is complete. Completing consultation atomically changes the
Encounter to `completed` and the Appointment to `fulfilled`.

```text
Appointment: scheduled -> checked_in ---------------------> fulfilled
                                |                               ^
                                v                               |
Encounter:                    planned -> in_progress -> completed
```

The in-progress Encounter page is a resumable full-page wizard:

1. **Consultation & History**
   - read-only patient and appointment context;
   - reason for visit prefilled as chief complaint;
   - ocular, surgical, and medical history;
   - allergies and medications.
2. **Examination**
   - encrypted clinical findings;
   - encrypted examination remarks.
3. **Prescription & Plan**
   - create or open the separately audited prescription;
   - record the visit plan without requiring a prescription.
4. **Review & Complete**
   - read-only clinical summary;
   - missing-data and workflow validation;
   - explicit confirmed completion action.

Each navigated step saves a draft. Wizard navigation alone never completes the
Encounter. Completed Encounters are read-only except through the existing
audited amendment workflows for related records.

### Optical order

An Optical Order is the staff-facing aggregate workflow for one eyewear sale.
It is not a replacement database model. The existing stable `eyewear_key`
joins its Quotation and resulting Job Order, while the Job Order owns its
unique Billing Record.

```text
Draft estimate -> Presented -> Accepted
                                |
                                v
                         Queued Job Order
                                |
                 In progress -> Ready -> Dispensed

Payment:           Unpaid -> Partially paid -> Paid
```

Staff see one timeline and context-sensitive actions. Quotation revisions
remain immutable after presentation, Job Orders remain authoritative for
committed items and fulfillment, and Billing Records remain authoritative for
the receivable and append-only payment history.

## Account Registration and Login

### Registration

The patient:

1. Selects **Continue with email** or **Continue with phone**.
2. Supplies the chosen contact.
3. Receives a generic response whether or not the contact is already known.
4. Receives an OTP through the selected channel when allowed.
5. Reviews and accepts the current privacy notice.
6. Supplies first name, last name, and date of birth for the account owner.
7. Creates their own password.
8. Submits the OTP.
9. On successful verification, the system atomically:
   - consumes the challenge;
   - creates the patient-role mobile account if the contact is not already
     owned;
   - does not create a duplicate when the contact is already owned;
   - creates the verified primary contact method;
   - stores the self-declared date of birth for later matching;
   - stores only the framework-hashed password;
   - records the accepted privacy-notice version and timestamp;
   - issues a device-labelled Sanctum token.

No `Patient` is created by registration.

An already-owned contact must never create a second account. Before OTP
verification, the response must not reveal whether the result will create an
account or continue an existing-account authentication/recovery flow.

### Login and recovery

Normal patient login requires:

- a verified email address or phone number;
- the patient-created password; and
- a device label.

A new or untrusted device requires an OTP step-up before a Sanctum token is
issued. An existing authenticated device is not challenged on every app
launch. Password recovery requires proof of a verified contact through OTP and
revokes the account's other patient device tokens when completed.

Registration, login, and recovery responses remain enumeration-safe and use a
coordinated API and Android cutover. Staff and admin Filament password/MFA
authentication are not changed.

Sensitive account recovery and primary-contact replacement revoke other
patient device tokens. Adding a secondary contact does not revoke otherwise
valid devices. Unlinking does not depend on token revocation for safety: active
link authorization must deny clinical access immediately, even to an existing
token.

### Additional contact method

An authenticated patient may add the missing email or phone contact. The new
contact:

- remains unverified until its own OTP challenge succeeds;
- cannot be used for login before verification;
- cannot already belong to another mobile account;
- does not update `Patient.contact_email` or `Patient.phone`;
- may become the primary login/notification contact through an explicit
  account action.

Removing or replacing the last verified login contact is prohibited.

## OTP Challenge Security

Every OTP challenge must:

- have an opaque public identifier;
- have one explicit purpose and channel;
- store no plaintext OTP;
- store a cryptographic one-way code digest;
- expire;
- be single-use;
- limit verification attempts;
- enforce resend cooldowns;
- enforce limits by normalized contact, IP address, challenge, and authenticated
  account when available;
- use separate rate-limit policies for request, resend, and verification
  operations;
- invalidate superseded challenges for the same purpose and contact;
- use generic request responses to prevent account and patient enumeration;
- avoid logging the OTP, full destination, invitation token, or Sanctum token;
- be consumed atomically with the resulting account/contact/link mutation.

The current `sms_notifications.message` storage must not be used for OTP
delivery because it persists message bodies. If OTP delivery is queued, the
queued payload must be encrypted or the OTP must be provider-managed.

External SMS and email delivery must:

- occur after the creating transaction commits;
- use explicit timeouts and controlled retries;
- report delivery failure without revealing provider internals;
- never roll back already committed account, invitation, link, or appointment
  domain state solely because a delivery provider failed;
- be replaceable through an injected provider boundary;
- be faked in tests with stray external requests prevented.

## Patient Matching and Linking

### Self-service matching

After account creation, an unlinked patient may provide the approved matching
identity fields and request linkage.

The matcher searches active clinic patients using normalized, indexed,
non-clinical identity fields. Encrypted clinical narrative fields are never
matching inputs.

Outcomes without a record-specific clinic invitation:

| Result | System behavior |
|---|---|
| Exactly one strong eligible candidate | Create a pending staff verification request and rank that candidate first. |
| Uncertain or duplicate candidates | Create a pending staff verification request. |
| No match | Keep the account unlinked and direct the patient to contact or visit the clinic. |
| Candidate already linked elsewhere | Fail closed and create no link. |
| Candidate archived | Fail closed and create no link. |

Only acceptance of a valid clinic-issued invitation tied to one patient and
the exact verified destination may activate a link automatically. Staff
approval remains required for every demographic-match path.

The mobile response may expose only a coarse state:

- `linked`
- `pending_review`
- `unlinked`

It must never expose candidate patient names, patient numbers, contact values,
match scores, duplicate counts, or whether a specific clinic record exists.

### Staff verification

Authorized staff may:

- review submitted identity information;
- see candidate patients and non-clinical match reasons;
- open the candidate patient records they are authorized to view;
- approve one eligible candidate;
- reject the request;
- register a new patient only through duplicate-safe patient creation;
- record an operational decision note when required.

Approval must recheck both sides of the one-to-one relationship under lock.

### Active-link access

The patient mobile API is split conceptually into:

**Authenticated account access**

- logout;
- account profile;
- verified contact management;
- privacy acknowledgement state;
- link status and link-request state;
- appointment-request submission and status;
- patient-safe catalogue and appointment availability.

**Active patient-link access**

- confirmed appointments;
- prescriptions;
- quotations, job orders, billing records, and eyewear;
- frame reservations and ratings;
- conversation, messages, and attachments;
- any future patient-specific clinical or fulfillment resource.

Authentication alone is insufficient for active-link access. A dedicated
authorization boundary must enforce the patient role and active link before a
controller loads clinical data.

## Staff-Created Patients and Invitations

### Duplicate-safe patient registration

All Filament entry points that can create a patient must use the same
duplicate-search workflow, including:

- the Patients resource;
- staff-created appointments;
- walk-in appointment creation;
- resolution of an unlinked appointment request;
- any inline patient creation option.

The workflow:

1. Searches existing patients before creation.
2. Displays possible duplicates to authorized staff.
3. Allows staff to select an existing patient.
4. Requires explicit confirmation before creating a new patient when
   candidates exist.
5. Creates only the clinical patient record.
6. Optionally offers **Send app invitation** after successful creation.

### Invitation lifecycle

Staff may send an invitation only when:

- the patient is active;
- the patient is not already linked;
- the selected destination is present on the patient record;
- no conflicting active invitation or link makes the send invalid.

The invitation:

- is tied to the patient and exact normalized destination;
- stores no plaintext acceptance secret;
- expires;
- is single-use;
- can be revoked by authorized staff;
- records sender, channel, sent time, expiry, acceptance, and failure state;
- does not create an account at send time.

On acceptance, the patient:

1. Opens the invitation in the app.
2. Reviews the current privacy notice.
3. Creates or signs into a mobile account.
4. Proves control of the invited destination through OTP.
5. Confirms their self-only identity.

The system then locks the invitation, account, and patient and activates the
link only if all one-to-one and invitation invariants still hold.

If an existing account does not yet own the invited destination, that contact
must be added and verified before the link can activate.

## Appointment Request Workflow

### Submission

Every mobile booking creates an appointment request.

For a linked account:

- `user_id` identifies the submitting account;
- `patient_id` is copied from the active link;
- clinic demographics are not overwritten by account data;
- duplicate matching is skipped.

For an unlinked account:

- `user_id` identifies the submitting account;
- `patient_id` remains null;
- the request stores the minimum submitted identity and contact snapshot needed
  for staff resolution;
- no clinical patient or appointment is created.

Each request records:

- opaque request number;
- submitting account;
- resolved patient when available;
- preferred date and time;
- one required, trimmed, free-text `reason_for_visit` within the approved
  length limit;
- the provisional appointment duration used for the capacity hold;
- state and expiry;
- resolution actor and timestamps;
- resulting appointment when accepted.

The mobile client does not submit an `appointment_type_id` or select a reason
category. The internal Appointment Type and its authoritative duration are
resolved before acceptance according to the final classification rule.

Appointment-request submission has its own authenticated account/IP throttle
and a configurable per-account active-request limit in addition to the general
API limiter.

### Request lifecycle

```text
pending -> accepted
    |----> rejected
    |----> cancelled
    +----> expired
```

Identity readiness is derived:

- `needs_patient_resolution` when `patient_id` is null;
- `ready_for_schedule_review` when an eligible patient is resolved.

It is not a second request status.

Rules:

- Only the owning account may view or cancel its request.
- Only staff/admin may accept, reject, or adjust the requested schedule.
- A patient may cancel only a pending request.
- Accepted, rejected, cancelled, and expired requests are terminal.
- A terminal request cannot create another appointment.
- Repeated acceptance is idempotent and returns the same appointment.
- Acceptance requires a non-null, active, eligible patient.
- Acceptance creates one `scheduled` appointment and stores its ID on the
  request in the same transaction.
- Rejection and expiry never create a patient or appointment.
- Request records are retained for audit and are not patient clinical history.

### Capacity and soft reservation

A verified pending request temporarily consumes the requested appointment
capacity until it is accepted, rejected, cancelled, or expires.

Rules:

- Availability reads include unexpired pending request capacity.
- Submission uses the existing schedule-date lock and authoritative
  availability engine.
- Acceptance locks the request and schedule date and rechecks availability
  using the request's own reservation as eligible capacity.
- Expired requests release capacity automatically and idempotently.
- Rate limits and per-account pending-request limits prevent slot hoarding.
- The patient sees **Requested — awaiting clinic confirmation**, never
  **Confirmed**, before acceptance.

The hold duration defaults to 24 hours and remains configurable. Reconfiguring
it affects newly submitted requests; it does not silently extend or shorten
the expiry already recorded on an existing request.

### Linked-account handling

A linked request goes directly to schedule review. Staff sees:

- verified link status;
- patient name and patient number;
- reason for visit and requested time;
- current availability;
- actions to accept, adjust time, or reject.

Staff does not repeat matching or duplicate review.

### Unlinked-account handling

An unlinked request enters identity resolution first. Staff may:

- link the account to an eligible existing patient;
- register a new patient after duplicate review and link it;
- reject the request without creating either record.

Schedule acceptance remains disabled until patient resolution succeeds.

## Filament Admin Experience

### Navigation

The panel retains one **Appointments** navigation destination. There is no
separate top-level **Appointment Requests** navigation item.

The Appointments experience provides:

- **Requests** with an actionable pending badge;
- **Upcoming** confirmed appointments;
- **Today** operational appointments;
- **History** terminal appointments;
- **Calendar** for confirmed appointments plus a visually distinct indication
  of pending capacity holds.

The implementation may use an Appointment Resource custom page or an
internally hidden request resource, but staff must experience one coherent
destination.

### Requests table

The request list shows only fields staff need to act:

- request number;
- submitted time;
- patient/account-owner name;
- masked contact;
- link readiness;
- preferred appointment time;
- reason for visit;
- request state;
- expiry or response deadline;
- action label: **Review** or **Resolve patient**.

It supports:

- tabs for **Needs patient**, **Ready for review**, and **Resolved**;
- search by request number and authorized patient/contact fields;
- filters for state, requested date, and link readiness;
- pagination and newest-first default ordering;
- eager loading and indexed queue queries.

### Request review

The review screen communicates two ordered stages:

```text
Identity resolution -> Schedule approval
```

For linked requests, identity resolution is already complete.

For unlinked requests, the screen displays:

- submitted identity snapshot;
- staff-only candidate list;
- match reasons;
- **Link existing patient**;
- **Register new patient**;
- **Reject request**.

Schedule acceptance is unavailable until a patient is resolved.

### Patient resource

The raw editable `user_id` field is removed from the patient form.

An **App access** section displays:

- `Not invited`;
- `Invitation sent`;
- `Invitation expired`;
- `Pending verification`;
- `Linked`.

Available actions depend on state and authorization:

- **Send app invitation**;
- **Resend invitation**;
- **Revoke invitation**;
- **View linked account**;
- **Unlink account** for admins only.

Unlinking requires confirmation, authorization, an audit entry, and immediate
loss of clinical mobile access. It preserves the clinical patient record and
the mobile account. The unlink action requires an operational reason. Pending
requests retain their history but cannot be accepted until identity is
resolved again.

## Mobile API Contract Direction

The existing `/api/v1` namespace remains the sole Android contract.

The contract must provide operations for:

- requesting and verifying a registration OTP;
- password login with new-device OTP step-up;
- OTP-backed password recovery;
- logging out and managing device tokens;
- reading the account and active-link state;
- adding and verifying a second contact;
- requesting patient linkage and reading its coarse state;
- accepting a patient invitation;
- listing, creating, reading, and cancelling owned appointment requests;
- listing confirmed appointments only after an active link.

Before implementation, `docs/API_CONTRACT.md` must define for every operation:

- exact method and route;
- request fields and validation;
- successful response shape;
- machine-readable error codes;
- authentication and active-link requirements;
- idempotency behavior;
- enumeration-safe behavior;
- timestamp and enum serialization.

Existing response field names must be extended rather than silently repurposed
where compatibility is possible. The current registration/login contract may
be changed only through the approved coordinated Android cutover.

## Error Semantics

API errors must use one consistent JSON shape and must not expose exception
messages, SQL details, provider responses, or match candidates.

Required machine-readable conflict/error categories include:

- invalid or expired OTP challenge;
- OTP attempt limit reached;
- contact already owned;
- invitation invalid, expired, revoked, or consumed;
- account already linked;
- patient already linked;
- link pending staff review;
- request not owned;
- request already terminal;
- patient resolution required;
- requested slot unavailable;
- active patient link required.

Authorization failures should prefer `404` where revealing the existence of
another patient's resource would create an enumeration risk.

## Audit and Privacy Requirements

Audit events include:

- mobile account creation;
- contact verification, addition, primary change, and removal;
- privacy notice acknowledgement;
- patient-link request submission, candidate ranking, staff approval, and
  rejection;
- invitation creation, send, resend, revocation, expiry, and acceptance;
- account unlinking;
- appointment-request creation, patient resolution, schedule adjustment,
  acceptance, rejection, cancellation, and expiry.

Audit metadata must contain identifiers and operational state transitions, not:

- OTP values or digests;
- invitation secrets;
- Sanctum tokens;
- full email addresses or phone numbers;
- unnecessary demographics;
- clinical narratives.

Account deletion or unlinking never deletes the clinic patient record.
Existing privacy-request workflows remain the mechanism for formal access,
correction, objection, and erasure requests.

Expired OTP challenges are pruned after a short configurable security-retention
window. Invitations, link decisions, and appointment requests follow an
approved operational/audit retention policy rather than being silently
deleted. Exact retention periods are implementation-plan configuration
decisions and must be documented before release.

## Testing Strategy

Use Pest feature tests with factories and faked delivery providers.

### Authentication and contacts

- Email and phone registration happy paths.
- Account does not exist before successful OTP verification.
- Wrong, expired, superseded, exhausted, consumed, and replayed challenges.
- Generic responses for known and unknown contacts.
- Contact normalization and uniqueness.
- Concurrent verification creates at most one account/contact.
- Password login accepts verified contacts only.
- New or untrusted device login requires OTP step-up.
- Password recovery revokes other patient device tokens.
- Adding and verifying the second contact.
- Replacing/removing contacts cannot remove the last verified login method.
- Staff/admin Filament authentication remains password/MFA based.

### Linking and invitations

- One strong candidate remains pending for staff review; ambiguous match, no
  match, archived match, and already-linked match fail closed as specified.
- Candidate details never appear in mobile responses.
- Concurrent link approval cannot violate one-to-one uniqueness.
- Invitation destination, expiry, revocation, replay, and contact mismatch.
- Existing-account and new-account invitation acceptance.
- Staff cannot create or activate an account for a patient.
- Unlinked accounts are denied every clinical/fulfillment resource.
- Unlinking immediately removes access without deleting either record.

### Appointment requests

- Linked and unlinked submission.
- Unlinked submission creates neither patient nor appointment.
- Linked submission copies the authoritative patient.
- Soft reservation participates in availability.
- Request expiry/cancellation releases capacity.
- Only the owner sees or cancels a request.
- Staff authorization for patient resolution and scheduling decisions.
- Acceptance rechecks capacity and creates one appointment.
- Concurrent or repeated acceptance never creates duplicates.
- Rejection creates no appointment.
- Existing appointment check-in and encounter behavior ignores request rows.

### Filament

- One Appointments navigation destination.
- Pending badge and request tabs.
- Linked request bypasses duplicate matching.
- Unlinked request disables acceptance until resolution.
- Duplicate review precedes patient creation.
- Patient App access actions respect role and state.
- Raw account foreign-key editing is absent.

### Provider integration

- Email and SMS delivery are faked.
- Stray HTTP requests are prevented.
- Notifications dispatch after commit.
- Failed delivery does not consume an otherwise unused OTP without an explicit
  retry path.
- Queued sensitive payloads are encrypted where applicable.

All focused tests must pass before the full suite. The full suite, Pint, and
frontend build must pass before the implementation is considered complete.

## Boundaries

### Always

- Use Sail for PHP, Artisan, Composer, Node, tests, and formatting.
- Validate every API and Filament input at the boundary.
- Authorize every patient-data read and workflow mutation.
- Use generic OTP and matching responses that resist enumeration.
- Hash one-time secrets and enforce expiry, attempt limits, and single use.
- Use database constraints for one-to-one links and unique verified contacts.
- Add focused, reversible migrations rather than modifying migrations that may
  already have run.
- Add indexes for normalized contact lookup, state/created-time queues,
  expirations, foreign keys, and unique request-to-appointment conversion.
- Use transactions and locks for verification, linking, invitation acceptance,
  capacity reservation, and appointment conversion.
- Reuse the existing availability engine and appointment lifecycle.
- Update `docs/API_CONTRACT.md` and `docs/BACKEND_CONTEXT.md` with the approved
  implementation.
- Add focused Pest coverage for every state transition and denial path.

### Ask first

- Add or replace an email, SMS, OTP, or identity provider.
- Add Composer or Node dependencies.
- Change the one-account-one-patient cardinality.
- Allow dependents, guardians, or delegated access.
- Change CORS, token lifetime, OTP limits, or rate-limit policy.
- Change pending-request capacity behavior or expiry policy.
- Permit automatic patient creation or automatic record merging.
- Break the `/api/v1` contract without a coordinated Android cutover.

### Never

- Store or log plaintext OTPs, invitation tokens, or Sanctum tokens.
- Store OTP messages in the existing plaintext SMS notification log.
- Reveal whether a specific clinic patient record exists to a mobile caller.
- Expose match candidates, scores, patient numbers, or contact values to the
  mobile app.
- Create a clinical patient merely because an account registered.
- Create a clinical patient from an unreviewed mobile request.
- Let staff choose an arbitrary raw `user_id` on a patient form.
- Let staff create, know, or enter a patient credential.
- Let account-profile edits silently update clinic patient demographics.
- Allow an unlinked account to read clinical or fulfillment information.
- Treat a pending request as a confirmed appointment.
- Delete or modify existing tests to make the feature pass without approval.
- Modify vendor files or commit secrets.

## Success Criteria

The specification is successfully implemented when all of the following are
programmatically verified:

1. Email OTP and phone OTP can each verify the contact used to create one
   patient account.
2. Registration creates no patient record.
3. A second contact remains unusable until independently verified.
4. Verified contacts cannot ambiguously authenticate multiple accounts.
5. One account and one patient cannot participate in multiple active links.
6. A clinic-issued, record-specific invitation can link only its eligible
   patient after destination verification; all demographic matches require
   staff review and no match remains unlinked.
7. Mobile matching responses reveal no candidate identity information.
8. Staff patient creation always presents duplicate-search results first.
9. Staff can issue a single-use expiring invitation from a patient record.
10. Invitation acceptance proves the invited contact and creates or uses the
    patient's own account without staff credentials.
11. Unlinked accounts receive no appointment, prescription, Encounter,
    quotation, job-order, billing, eyewear, reservation, rating, conversation,
    message, or attachment data.
12. Linked and unlinked accounts can submit appointment requests.
13. Unlinked requests create neither patients nor appointments.
14. Pending verified requests consume capacity only while active.
15. Staff cannot accept a request until an authoritative patient is resolved.
16. Acceptance creates exactly one normal scheduled appointment.
17. Rejected, cancelled, and expired requests never enter clinical appointment
    history.
18. Staff manage requests and confirmed appointments from one Appointments
    destination.
19. Existing staff-created, walk-in, check-in, encounter, prescription, and
    fulfillment workflows continue to pass their regression tests.
20. Focused tests, the full Pest suite, Pint, and the frontend build pass.
21. Mobile appointment requests require free-text reason for visit and accept
    neither an appointment type nor a reason category from the patient.
22. Check-in creates exactly one planned Encounter and no earlier appointment
    or request state creates one.
23. Starting consultation leaves the Appointment checked in; completing the
    Encounter atomically marks the Appointment fulfilled.
24. Encounter history and findings save as encrypted resumable drafts across
    wizard navigation.
25. The Encounter wizard keeps prescriptions as separate audited records and
    permits completion without one.
26. Appointment pages expose no Patient Health Record or intake workflow.
27. Appointment pages show any linked Frame Reservation context without
    replacing the operational Frame Reservations queue.
28. Pending Appointment Requests and unlinked accounts cannot reserve or
    allocate frame inventory.
29. Staff can move from estimate through production, payment, and dispensing
    from one Optical Order workflow without losing the separate audit records.
30. Accepting and starting an Optical Order creates one Job Order and one
    Billing Record even under retries or concurrent actions.
31. A prepared Frame Reservation converted to an Optical Order does not deduct
    the same frame stock twice.
32. Deposits can be recorded before dispensing, and cancellation cannot
    silently discard or erase posted payment history.

## Open Questions

No product-flow or security-configuration questions remain open. The approved
Phase 2 plan records the OTP, password, token, invitation, request, and
retention defaults and the tests required for them.
