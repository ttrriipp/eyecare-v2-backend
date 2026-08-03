# Implementation Plan: Unlinked Appointment Request Identity Snapshot

## Status

Approved by the project owner on 2026-08-03. Phase 2 (**Plan**) and the
expanded Phase 3 implementation are complete.

## Overview

Complete the existing appointment-request contract so an unlinked mobile
account can review or correct its structured identity during submission, while
the server supplies the verified primary contact and stores one encrypted,
staff-only snapshot. Staff will see the snapshot and snapshot-based candidate
matches in the existing Appointment Request review experience. Linked requests
continue using the authoritative clinic Patient record and store no account
identity snapshot.

The implementation is deliberately additive and small:

- keep `POST /api/v1/appointment-requests` and its response envelope;
- add one optional `identity` request object;
- reuse the existing `appointment_requests.encrypted_identity_snapshot` text
  column and Laravel `encrypted:array` cast;
- reuse the existing verified contact records and patient-candidate ranking
  rules;
- reuse the existing Appointment Requests Filament resource;
- add no migration, dependency, route, response field, or new navigation item.

## Approved Input

- Approved specification amendment: Confirmed Decisions 56-64, the Unlinked
  Identity Snapshot contract, and Success Criteria 33-38 in
  `docs/specs/mobile-patient-accounts-and-appointment-requests-spec.md`.
- Current database inspection on 2026-08-03 confirms
  `appointment_requests.encrypted_identity_snapshot` is nullable `TEXT`.
- `AppointmentRequest` already casts the field as `encrypted:array`.
- Existing unrelated in-progress work currently overlaps
  `routes/api.php`, `docs/API_CONTRACT.md`, `docs/BACKEND_CONTEXT.md`, and
  `tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`. Implementation must
  preserve and merge those changes rather than replace them.

## Current Conformance Gap

The storage foundation exists, but the active request path does not use it:

- `AppointmentRequestController::store()` performs inline validation and
  accepts only `scheduled_at` and `reason_for_visit`.
- `StoreAppointmentRequest` exists but contains stale fields and is not used by
  the controller.
- `SubmitAppointmentRequest` never builds or persists an identity snapshot.
- The patient-safe response correctly omits the snapshot and must stay that
  way.
- The Filament review form shows only account/patient labels, requested time,
  reason, status, and resolution metadata.
- `RankPatientCandidates` reads mutable account fields and contacts; it cannot
  rank from an immutable appointment-request snapshot.

This amendment corrects those gaps. It does not redesign appointment capacity,
request lifecycle, authentication, or Patient creation.

## Architecture Decisions

### 1. Extend the existing endpoint instead of adding another route

`POST /api/v1/appointment-requests` gains one optional nested object:

```json
{
  "scheduled_at": "2026-08-10T10:00:00+08:00",
  "reason_for_visit": "Blurred distance vision",
  "identity": {
    "phone": "09171234567",
    "email": "ana@example.com",
    "first_name": "Ana",
    "middle_name": "Santos",
    "last_name": "Reyes",
    "date_of_birth": "1990-05-15",
    "gender": "female",
    "occupation": "Teacher",
    "address": "123 Main St, Manila"
  }
}
```

The successful response does not change. The optional transport field keeps
older mobile clients compatible: an unlinked account that omits `identity`
uses its current structured name, date of birth, verified phone, optional email,
and address.

### 2. Make the existing Form Request the only API validation boundary

Reconcile `StoreAppointmentRequest` with the active contract and type-hint it
in the controller. Remove duplicate inline validation.

Boundary rules include:

- exact ISO-8601 requested time and existing future-slot rules;
- trimmed free-text reason with the existing maximum length;
- optional `identity` restricted to the approved phone, email, structured-name,
  date-of-birth, gender, occupation, and address keys;
- required non-email identity fields, bounded names, and a past date of birth;
- no raw `patient_id` or verification fields; a submitted phone is checked
  against the verified account phone;
- `identity` prohibited when the authenticated account is already linked.

Unknown nested identity keys and explicit client identity/verification claims
fail with the existing Laravel validation response semantics. A submitted phone
is accepted only as a server-checked confirmation, while an optional email is
captured without a verification claim. No new API error envelope is introduced
by this correction.

### 3. Build effective identity and verified contact server-side

Add one focused action that returns the internal snapshot array or throws a
validation exception. It will:

1. determine the account's link state at submission;
2. return no snapshot for a linked account;
3. use the submitted unlinked identity when present, otherwise the account
   profile;
4. normalize whitespace without mutating the account;
5. require effective phone, first name, last name, past date of birth, gender,
   occupation, and address when identity is submitted;
6. load the account's one primary verified contact and verified phone;
7. reject missing or ambiguous primary verified contacts;
8. compose only the approved snapshot keys, with optional email;
9. validate any submitted phone against the server-derived verified phone.

The client never supplies verified-contact metadata or verification state. The
action reads the verified phone and contact metadata from
`PatientAccountContact`, then uses decrypted values only long enough to assign
the encrypted snapshot cast. A submitted email is optional and is not treated
as verified.

### 4. Persist the snapshot inside the request transaction

`SubmitAppointmentRequest` receives the already validated optional identity
array and delegates effective snapshot construction to the focused action.
Snapshot construction and `AppointmentRequest::create()` occur within the same
transaction as the request write. The action retains the existing capacity,
active-request-limit, expiry, rate-limit, and linked `patient_id` behavior.

If effective identity/contact validation fails, no Appointment Request exists
and therefore no capacity hold exists. A request created while the account is
unlinked retains that point-in-time snapshot even if the account profile or
link state changes later.

### 5. Keep encryption and patient serialization unchanged

No schema change is needed. The current encrypted cast is the persistence
boundary. Because encrypted fields are not queryable, staff matching decrypts
only the selected request in application memory and performs indexed Patient
lookups using normalized contact hashes, name, and date of birth.

The existing appointment-request formatter remains an allowlist and must not
gain identity fields. Model array/JSON serialization is not used as the public
contract for this sensitive data.

### 6. Extend candidate ranking to accept immutable identity context

Reuse the scoring and reason-code rules in `RankPatientCandidates` rather than
creating another duplicate matcher. Add an explicit snapshot-based entry point
that accepts the approved internal identity/contact shape. Preserve the
existing account-based entry point for Patient Link Requests by adapting it to
the same normalized ranking core.

For an Appointment Request, candidate matches are computed on demand from the
stored snapshot and current eligible unlinked Patient records. Candidate rows
are not copied into the appointment request and no new candidate table is
introduced. This keeps demographic input immutable while allowing the clinic's
current Patient data and link eligibility to remain authoritative.

This amendment supplies trustworthy candidate information but does not add a
second patient-linking mechanism. The separately specified patient-link and
appointment-request resolution workflows remain responsible for changing
`patients.user_id` and resolving `appointment_requests.patient_id`.

Repository inspection found that the current Appointment Request review does
not yet expose that complete resolution workflow. Implementing link approval,
duplicate-safe Patient creation, and request resolution is an existing broader
conformance gap, not silently added to this identity-snapshot amendment.

### 7. Add one staff-only identity section to the existing review page

The current Appointment Request review page gains a **Submitted identity**
section for unlinked requests showing:

- structured full name;
- date of birth;
- masked phone and optional masked email;
- gender, occupation, and home address;
- verified contact type;
- masked verified contact;
- submission time;
- current candidate matches with match-strength/reason labels.

The section is absent for linked requests. It is read-only, does not expose the
raw encrypted attribute name, and does not add identity columns to the main
request table. This keeps sensitive details in the intentional review context
rather than increasing queue density or search exposure.

Masking is centralized behind an Appointment Request model/helper method so
Filament closures do not duplicate contact-masking rules or accidentally render
the full value.

## Dependency Graph

```text
Approved API contract amendment
        |
        v
Form Request validation + snapshot builder
        |
        v
Transactional request persistence
        |
        +-----------------------+
        |                       |
        v                       v
Patient-safe API tests   Snapshot-based candidate ranking
                                |
                                v
                       Filament review section
                                |
                                v
                 Contract/context reconciliation
```

## Implementation Sequence

### Phase A: Contract and submission path

- Carefully merge the approved additive request contract into the currently
  modified `docs/API_CONTRACT.md`.
- Characterize the current linked/unlinked request behavior and patient-safe
  response before changing writes.
- Reconcile `StoreAppointmentRequest` with the approved nested identity rules.
- Type-hint the Form Request in `AppointmentRequestController::store()` and
  remove its inline validator.
- Add the focused snapshot builder.
- Pass validated identity into `SubmitAppointmentRequest` and persist the
  encrypted snapshot transactionally.
- Add factory support for an unlinked request with a valid snapshot.

Checkpoint A:

- Submitted identity is stored encrypted and can be read through the model
  cast.
- Omitted identity falls back to complete account data.
- Linked requests retain `patient_id` and store no identity snapshot.
- Invalid identity/contact input creates no request or capacity hold.
- Existing response fields are byte-for-byte compatible in shape.

### Phase B: Snapshot matching and staff review

- Refactor candidate ranking around one normalized ranking core.
- Preserve existing Patient Link Request ranking behavior.
- Add snapshot-based ranking for Appointment Requests using server-derived
  contact data.
- Add safe model helpers for display name, masked phone/email, and the expanded
  snapshot fields.
- Add the read-only Submitted identity section and on-demand candidate list to
  the existing Filament review page.
- Confirm staff/admin authorization remains unchanged and patient endpoints
  cannot reach the staff representation.

Checkpoint B:

- Changing the account profile after submission does not change displayed
  identity or candidate ranking input.
- Matching contact/name/date-of-birth candidates and no-match cases are shown
  correctly.
- Full contact values never appear in Filament HTML, API JSON, notifications,
  audit metadata, or logs.
- Existing Patient Link Request candidate tests still pass.

### Phase C: Security and regression reconciliation

- Add explicit response-safety assertions for list, detail, submit, cancel, and
  validation responses.
- Add raw-database assertions proving the identity snapshot is encrypted.
- Test unknown identity keys, client `patient_id`, contact/verification claims,
  missing verified primary contact, ambiguous primary contacts, linked-account
  identity submission, and legacy payload fallback.
- Reconcile `docs/BACKEND_CONTEXT.md` after behavior is verified, preserving its
  current in-progress edits.
- Run focused API, Appointment, Patient Link, and Filament tests, followed by
  formatting and the complete regression suite.
- Perform a real-browser staff review check for masked rendering and recent
  console/network errors.

Checkpoint C:

- All approved amendment success criteria are programmatically verified.
- No migration or route-count change exists.
- No unrelated worktree change is overwritten.
- Full test suite, Pint, and required frontend build pass.

## Verification Commands

All implementation commands run through Sail:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SubmitAppointmentRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentRequestModelTest.php
vendor/bin/sail artisan test --compact tests/Feature/Patients/SubmitPatientLinkRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRequestResourceTest.php
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
git diff --check
```

Focused implementation tasks may introduce one specifically named matching or
Filament test file; Phase 3 will state its exact command and file ownership.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Identity PII leaks through generic model serialization | High | Keep the existing response allowlist, add negative contract assertions, and never return the snapshot. |
| Client claims a contact is verified | High | Do not accept any contact fields; derive the contact only from a verified primary account-contact row. |
| Encrypted data cannot be searched | Medium | Decrypt one authorized request in memory, derive lookup hashes, and query indexed Patient hashes/DOB. |
| Account profile changes alter later matching | Medium | Candidate ranking receives only the stored snapshot for Appointment Requests. |
| More than one primary verified contact exists in legacy data | Medium | Fail closed with validation and add a corrective test rather than selecting arbitrarily. |
| Conditional linked/unlinked validation races with linking | Low | Re-evaluate link state during transactional submission; define snapshot semantics at request-creation time. |
| Existing Patient Link candidate behavior regresses during reuse | Medium | Preserve its public entry point and run its existing ranking tests at Checkpoint B. |
| Staff page reveals full contact in rendered HTML | High | Centralize masking, test rendered output for absence of the raw value, and verify in a real browser. |
| Dirty overlapping files are overwritten | High | Re-read and merge each overlapping diff immediately before editing; never restore or replace user changes. |

## Parallelization

The work is small and shares the request contract, model, and tests, so it
should remain sequential. Parallel implementation would increase merge risk,
especially because current uncommitted work overlaps the API contract and
submission test.

## Out of Scope for This Amendment

- New database tables, columns, indexes, or migrations.
- A second appointment-request route or response version.
- Editing account-profile or Patient demographics from the request.
- Accepting client-supplied verification state or unvalidated contact claims.
- Returning identity snapshots to Android.
- Changing rate limits, capacity holds, request expiry, or request statuses.
- Automatically linking an account, creating a Patient, or accepting an
  Appointment because a candidate matches.
- Replacing the existing staff patient-link approval or duplicate-safe Patient
  creation workflows.
- Native Android screen implementation.

## Open Questions

No technical choice remains open if this plan is approved. Phase 3 will split
the sequence into small corrective tasks, each touching no more than roughly
five files and carrying its own Pest verification command.
