# Spec: Patient Account Self-Service Profile Editing

## Status

**Approved and implemented in the backend on 2026-08-28.**

The project owner approved the implementation plan and task checklist. The
backend implementation is complete and verified; deployment and Android
repository changes remain outside this repository and require their own
authorization.

## Objective

Let an authenticated patient control the identity details that belong to their
mobile account without allowing mobile edits to silently change the clinic's
authoritative Patient record.

Success means:

- the patient can update their account first, middle, and last names;
- the patient can update their account date of birth only after recent step-up
  verification;
- verified email, verified phone, and password continue using their existing
  security-specific workflows;
- address, occupation, gender, and every field under `linked_patient` remain
  read-only in the mobile API;
- account edits never update `patients` or clinical records;
- pending patient-link requests cannot be approved from stale identity data;
- unsupported profile fields fail validation instead of being accepted and
  ignored; and
- the API response and Android UI clearly distinguish account identity from
  the clinic record.

## Context and Current Conformance Gap

`GET /api/v1/me` already separates account fields from an optional
`linked_patient` object. The canonical API contract says only `first_name` and
`last_name` are editable through `PATCH /api/v1/me`, while verified contacts
and password have dedicated endpoints.

The active implementation does not cleanly match that contract:

1. `UpdateMeRequest` validates email, phone, address, date of birth,
   occupation, gender, and clinic contact email even though the controller
   does not persist those values.
2. `AuthController::update()` can persist `middle_name`, although the API
   contract says it is read-only.
3. A payload containing a validated but unsupported field can receive a
   successful response without changing that field.
4. The PATCH response does not consistently reload every relationship needed
   to reproduce the documented `GET /me` representation.
5. Account identity is copied into an encrypted patient-link-request snapshot,
   but later account edits do not currently expire that pending snapshot.
6. Unrouted legacy profile request/controller classes still describe direct
   Patient demographic editing even though `/api/v1/patient/profile` is absent.

This specification resolves those contradictions without making the clinic
Patient record mobile-editable.

## Confirmed Product Decisions

1. The account and the clinic Patient record are separate authorities.
2. `PATCH /api/v1/me` remains the one account-profile update endpoint.
3. Account `first_name`, `middle_name`, and `last_name` are self-service
   fields.
4. Account `date_of_birth` is self-service but security-sensitive because it
   participates in patient-record matching. Updating it requires a recent
   step-up token.
5. Address, occupation, and gender are not self-service fields.
6. Email and phone are account-owned contacts, but they remain editable only
   through the existing verified Contact Management endpoints.
7. Password remains editable only through the existing step-up-protected
   password endpoint.
8. An account edit never copies into, synchronizes, or overwrites the linked
   Patient record.
9. A pending patient-link request represents an immutable point-in-time
   identity submission. An actual account name, DOB, or verified contact value
   change that affects candidate matching expires it.
10. Staff approval must fail closed if a pending link request's identity
    snapshot no longer matches the account identity.
11. Profile-change audit metadata records field names and workflow outcomes,
    not raw names, DOB values, contact values, tokens, or OTP codes.
12. The change is additive for conforming Android clients: existing valid
    `first_name`/`last_name` PATCH requests remain valid.

## Terminology and Source of Truth

| Term | Owner | Meaning |
|---|---|---|
| Account identity | `users` | Self-asserted identity used for login-adjacent presentation and patient-link requests. |
| Verified contact | `patient_account_contacts` | Account-owned email or phone proven through OTP. |
| Clinic Patient record | `patients` | Staff-maintained authoritative demographic and clinical identity. |
| Linked Patient | `linked_patient` in `/me` | Read-only projection of the current Patient associated through `patients.user_id`. |
| Patient-link request snapshot | `patient_link_requests.encrypted_identity_snapshot` | Immutable encrypted identity values used for one staff-reviewed linking attempt. |

Account and Patient values may differ. A difference is not resolved by silent
synchronization. The mobile UI must label the two sections so the patient does
not mistake an account edit for a clinic-record correction.

## Field Ownership Matrix

### `PATCH /api/v1/me`

| Field | Account-readable | Editable here | Security rule | Patient side effect |
|---|---:|---:|---|---|
| `first_name` | yes | yes | authenticated account owner | none |
| `middle_name` | yes | yes | authenticated account owner | none |
| `last_name` | yes | yes | authenticated account owner | none |
| `date_of_birth` | yes | yes | valid `X-Step-Up-Token` required when present | none |
| `email` | yes | no | Contact Management only | none |
| `phone` | yes | no | Contact Management only | none |
| `address` | no account projection | no | clinic-managed when shown under `linked_patient` | none |
| `occupation` | no account projection | no | clinic-managed under `linked_patient` | none |
| `gender` | no account projection | no | clinic-managed under `linked_patient` | none |
| `contact_email` | no | no | clinic-managed under `linked_patient` | none |
| `name` | derived | no | response compatibility field | none |
| `role` | yes | no | staff/admin authority | none |
| `link_status` | yes | no | server-derived | none |
| privacy acceptance fields | yes | no | consent workflow only | none |
| `linked_patient` | yes when linked | no | read-only clinic projection | none |

The existing nullable `users.address` column is outside this mobile contract.
This specification does not remove the column, expose it from `/me`, or allow
the mobile client to write it.

### Existing dedicated self-service workflows

| Data | Endpoint family | Required protection |
|---|---|---|
| Email and phone | `/api/v1/account/contacts/*` | contact OTP; step-up where already required |
| Password | `POST /api/v1/auth/password` | current password plus step-up token |
| Saved frames and other account preferences | their existing account-owned endpoints | authenticated account ownership |

This feature must not introduce a second way to mutate contacts or passwords.

## API Contract

### Endpoint

```text
PATCH /api/v1/me
Authorization: Bearer <sanctum-token>
X-Step-Up-Token: <required only when date_of_birth is present>
Content-Type: application/json
```

The route remains authenticated and under the existing `api-account` rate
limit. It does not require an active Patient link.

### Request

All fields are optional individually, but at least one allowed field is
required:

```json
{
  "first_name": "Ana",
  "middle_name": "Santos",
  "last_name": "Reyes",
  "date_of_birth": "1990-05-15"
}
```

Partial-update semantics apply: omitted fields remain unchanged. Explicit
`null` is accepted only for `middle_name`. A blank middle name is normalized
to `null`.

### Validation

| Field | Rules |
|---|---|
| `first_name` | sometimes; string; trimmed; non-blank; maximum 255 characters |
| `middle_name` | sometimes; nullable; string; trimmed; maximum 255 characters |
| `last_name` | sometimes; string; trimmed; non-blank; maximum 255 characters |
| `date_of_birth` | sometimes; non-null; exact `Y-m-d` date; before today |

Names retain the patient's submitted casing and Unicode characters. Only
leading/trailing whitespace is removed. No title-casing or ASCII-only rule is
introduced.

The request is a strict allowlist. Known prohibited fields and unknown
top-level fields produce the standard Laravel `422` validation response. The
server must not return `200` for an unsupported-only payload or silently ignore
an unsupported field mixed with valid fields.

### Conditional step-up behavior

- A request that changes only names does not require step-up.
- Any request containing `date_of_birth` requires a valid user-bound recent
  step-up token in `X-Step-Up-Token`, even when the submitted DOB equals the
  current value.
- A missing token returns the existing `STEP_UP_REQUIRED` error.
- An invalid or expired token returns the existing
  `INVALID_STEP_UP_TOKEN` error.
- If step-up validation fails, no profile field changes and no link request is
  expired.
- Contact and password step-up behavior is unchanged.

This specification reuses the existing step-up system; it does not introduce
a new OTP purpose, token type, expiry, or delivery channel.

### Successful response

The response is `200` with the same complete `PatientAccountResource` envelope
as `GET /api/v1/me`:

```json
{
  "data": {
    "id": 1,
    "name": "Ana Santos Reyes",
    "first_name": "Ana",
    "middle_name": "Santos",
    "last_name": "Reyes",
    "email": "ana@example.com",
    "phone": "+639171234567",
    "role": "patient",
    "date_of_birth": "1990-05-15",
    "link_status": "linked",
    "privacy_policy_version": "2026-08",
    "privacy_accepted_at": "2026-08-01T10:00:00+08:00",
    "linked_patient": {
      "patient_number": "PAT-2026-000001",
      "full_name": "Ana Reyes",
      "date_of_birth": "1990-05-14",
      "gender": "female",
      "occupation": "Teacher",
      "address": "123 Main St, Manila",
      "phone": "09171234567",
      "contact_email": "ana@example.com"
    }
  }
}
```

The differing account and clinic DOB values in this example are intentional.
The API does not imply that one was synchronized to the other.

The PATCH response must load role, verified contacts, current Patient link, and
pending link requests using the same resource rules as GET `/me`; it must not
temporarily report a linked account as unlinked because a relationship was not
loaded.

## Patient-Link Request Behavior

Names and DOB are inputs to identity matching, so a pending request cannot
remain actionable after those account fields actually change.

Within the same database transaction as the account update:

1. lock the authenticated account and its pending patient-link request;
2. normalize and compare the requested values with the persisted account;
3. update only fields whose normalized values changed;
4. when at least one identity field changed, set the pending link request to
   `expired`;
5. preserve its encrypted snapshot and candidate rows as historical evidence;
6. do not set a reviewer, reviewed Patient, staff decision note, or approval
   timestamp for the automatic expiry; and
7. return `link_status: unlinked`, allowing the patient to submit a fresh link
   request with a new snapshot and newly ranked candidates.

A no-op PATCH does not expire a pending link request.

The same expiry invariant applies when the existing Contact Management flow
changes or removes a verified email or phone used by candidate ranking. Merely
changing which already-verified contact is labelled primary does not expire a
request when the set of candidate-matching contact hashes is unchanged. This
does not make contacts writable through `/me`.

New link-request snapshots include nullable `middle_name` in addition to first
name, last name, and DOB. Historical snapshots without `middle_name` remain
valid for display and history. For approval-time comparison, a missing
`middle_name` key is equivalent to `null`; it is stale when the current account
has a non-null middle name.

Staff approval must lock and re-read the link request and account. Before
linking a Patient, it compares the normalized current account identity with
the encrypted snapshot. If they differ, approval expires the stale request and
fails closed without linking a Patient. This is required even though the
profile-update path normally expires the request, because concurrent requests
must not bypass the invariant.

## Linked Account Behavior

When the account is already linked:

- account fields update according to this contract;
- `patients.first_name`, `middle_name`, `last_name`, `date_of_birth`, address,
  occupation, gender, phone, and contact email remain unchanged;
- prescriptions, appointments, encounters, optical orders, billing records,
  and other clinical/operational records remain unchanged; and
- the response continues returning the authoritative clinic values under
  `linked_patient`.

Correcting the clinic record remains a staff workflow. A future patient-facing
correction-request feature requires its own approved specification and must
not be inferred from this account-edit endpoint.

## Audit Contract

Every successful PATCH that changes at least one value creates one account
profile audit event. Metadata may contain:

- `changed_fields`: an allowlisted list drawn from `first_name`,
  `middle_name`, `last_name`, and `date_of_birth`;
- `pending_link_request_expired`: boolean; and
- the expired link request ID when applicable.

Audit metadata must not contain old or new names, DOB values, contact values,
the step-up token, OTP code, access token, or request body.

Automatic link-request expiry creates or includes a categorical audit event so
staff can determine why the request left the pending queue without exposing
the changed PII values.

A successful no-op PATCH may return `200` but does not create an audit event or
expire a link request.

## Threat Model and Security Controls

| Threat | Control |
|---|---|
| A stolen authenticated session changes DOB to influence patient matching | DOB requires recent user-bound step-up verification. |
| A client writes clinic demographics through mass assignment | Strict request allowlist; controller/action allowlist; no Patient write path. |
| A client bypasses contact verification through `/me` | Email, phone, and clinic contact fields are rejected. |
| Staff approves a stale link snapshot during a concurrent account edit | Transactional expiry plus approval-time locked revalidation. |
| Sensitive identity values leak through logs | Audit stores field names and outcomes only, never raw before/after values. |
| A malformed payload receives false success | Unsupported and unknown fields return `422`; unsupported-only payload cannot return `200`. |
| One account edits another account | Target account comes only from the authenticated Sanctum principal; no client account ID is accepted. |

The endpoint keeps the existing account rate limit. This scope does not change
CORS, token storage, OTP delivery, throttling, or session lifetime.

## Android Contract and UX

Android should render two visually distinct sections when an account is
linked:

1. **My account details** — editable account name and DOB.
2. **Clinic record** — read-only values from `linked_patient`.

The edit form must:

- show first, middle, and last name plus DOB;
- omit address, occupation, and gender from account editing;
- route email and phone changes to the verified-contact flow;
- request step-up only when the outgoing PATCH contains DOB;
- preserve unchanged fields by sending a partial PATCH;
- explain that account changes do not modify the clinic record;
- refresh `/me` after success; and
- return the patient to the unlinked flow when an identity change expires a
  pending link request.

Android must not submit fields copied from `linked_patient` to PATCH `/me`.

## Scope

### Included

- reconcile `PATCH /api/v1/me` validation and persistence with this allowlist;
- make account middle name officially editable;
- make account DOB conditionally step-up protected and editable;
- reject address, occupation, gender, contacts, clinic fields, derived fields,
  server state, and unknown fields;
- guarantee a complete and correct `PatientAccountResource` PATCH response;
- audit changed field names without raw PII values;
- expire pending patient-link requests after actual identity changes;
- expire pending patient-link requests when verified contact values used by
  candidate ranking change;
- add middle name to new encrypted link-request snapshots;
- fail closed during stale/concurrent link approval;
- remove or reconcile unrouted legacy profile classes that contradict the
  single `/me` contract;
- update canonical API/backend documentation; and
- provide an Android handoff contract.

### Not Doing

- direct mobile editing of any `patients` column;
- address, occupation, or gender self-service;
- a clinic-record correction-request workflow;
- automatic account-to-Patient or Patient-to-account synchronization;
- email or phone mutation through `/me`;
- changes to contact verification, password changes, OTP purposes, or token
  lifetime;
- avatar, notification-preference, pronoun, accessibility-preference, or other
  new profile fields;
- database removal of legacy nullable columns from `users`;
- changes to staff/admin Filament profile editing;
- deployment or changes in the separate Android repository.

## Expected Implementation Surface

The implementation plan should inspect and, where required, update:

- `app/Http/Requests/Api/UpdateMeRequest.php`;
- `app/Http/Controllers/Api/AuthController.php`;
- a focused account-profile action if needed to keep the controller thin;
- `app/Http/Resources/PatientAccountResource.php` only if response-loading
  consistency requires it;
- `app/Actions/Auth/VerifyStepUpOtp.php` or a focused reusable verifier without
  changing existing step-up semantics;
- `app/Actions/PatientAccounts/SubmitPatientLinkRequest.php`;
- `app/Actions/PatientAccounts/ReviewPatientLinkRequest.php`;
- the existing verified-contact mutation path only where needed to preserve
  the pending-link expiry invariant;
- `app/Enums/AuditEvent.php` and the existing audit action/observer boundary;
- obsolete duplicate profile request/controller classes after confirming they
  are unrouted;
- focused Pest feature tests;
- `docs/API_CONTRACT.md` and `docs/BACKEND_CONTEXT.md`; and
- the Android handoff notes in this specification.

No migration or dependency change is expected. Discovery during planning must
stop for approval if a schema or dependency change becomes necessary.

## Project Structure and Style

- Laravel/PHP behavior remains under `app/`; API routes remain under
  `routes/api.php`; tests remain under `tests/Feature/`.
- Use a Form Request as the HTTP validation boundary and validated data only.
- Keep controller methods thin and put transactional identity/link behavior in
  one focused Action when extraction improves clarity.
- Use explicit parameter and return types, constructor property promotion, and
  curly braces for all control structures.
- Follow existing structured API error envelopes and resource serialization.
- Use Eloquent/query-builder parameter binding and row locks for the
  concurrency boundary.
- Do not query in resources or views.

## Testing Strategy

Pest feature tests must prove at minimum:

1. first, middle, and last name can be partially updated;
2. whitespace normalization and nullable middle name behave as specified;
3. DOB updates fail without step-up and with invalid/expired/cross-account
   tokens;
4. a valid step-up token permits a DOB update;
5. address, occupation, gender, email, phone, contact email, Patient fields,
   derived/server fields, and unknown keys return `422` and remain unchanged;
6. mixed valid and prohibited payloads are atomic and change nothing;
7. linked Patient values and clinical records remain unchanged;
8. the PATCH response has the same account/link projection as GET `/me`;
9. an actual identity change expires a pending link request and preserves its
   encrypted snapshot/candidates;
10. a verified contact value change used for candidate ranking also expires a
    pending request;
11. a no-op update leaves the pending request active;
12. a fresh link request includes middle name and reranks candidates;
13. stale or concurrent staff approval cannot create a Patient link;
14. audit metadata contains changed field names/outcomes but no raw PII or
    authentication secrets;
15. unlinked and linked accounts can update the allowed account fields; and
16. unauthenticated callers remain rejected.

Primary verification commands:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/MeEndpointTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/PatientContactTest.php
vendor/bin/sail artisan test --compact tests/Feature/Patients/SubmitPatientLinkRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/Patients/ReviewPatientLinkRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/AuditLogHardeningTest.php
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail artisan test --compact
```

The known unrelated full-suite baseline must be reported separately from
failures caused by this change. Tests must not be deleted or weakened to make
the suite green.

## Boundaries

### Always

- validate the strict field allowlist at the HTTP boundary;
- authorize the authenticated account as the only update target;
- require step-up before processing any payload containing DOB;
- make account update, pending-link expiry, and audit recording transactional;
- revalidate link-request identity at staff approval;
- retain immutable historical snapshots and candidate rows;
- keep raw PII and secrets out of audit metadata; and
- update the canonical API contract with implementation.

### Ask First

- any database migration or new dependency;
- a new route, OTP purpose, token type, or rate-limit change;
- allowing address, occupation, gender, or any Patient field to be edited;
- synchronizing account and Patient values;
- deleting historical link requests or candidates; or
- changing the external Android repository.

### Never

- trust a client-supplied account ID, Patient ID, contact-verification state,
  role, link state, or consent timestamp;
- write `patients` from PATCH `/me`;
- accept email or phone through PATCH `/me`;
- silently ignore unsupported fields;
- log raw DOB, names, contacts, OTP codes, step-up tokens, access tokens, or
  passwords; or
- approve a link request whose snapshot is stale.

## Success Criteria

1. The documented PATCH allowlist is exactly `first_name`, `middle_name`,
   `last_name`, and `date_of_birth`.
2. Name-only updates require authentication but no step-up token.
3. Any DOB-bearing update is atomic and requires valid step-up verification.
4. Email, phone, address, occupation, gender, clinic demographics, derived
   fields, server state, and unknown fields receive `422` from PATCH `/me`.
5. Valid account updates never change a Patient or clinical record.
6. GET and PATCH `/me` return the same complete resource shape and accurate
   link state.
7. Actual identity changes expire pending link requests atomically; no-op
   updates do not.
8. Verified contact value changes used by candidate ranking expire pending
   link requests without exposing contact values in audit metadata.
9. New link requests capture middle name, and stale snapshots cannot be
   approved even during concurrent operations.
10. Audit history identifies changed field names and link-expiry outcomes
   without storing raw identity values.
11. Existing contact and password endpoint contracts remain unchanged and
    green.
12. Focused profile, step-up, link-request, audit, route-contract, and
    cross-account tests pass.
13. Canonical backend/API documentation and the Android handoff match the
    implementation.

## Open Questions

No product question blocks approval. The implementation plan must confirm that
conditional step-up validation can reuse the current verifier without changing
existing contact/password behavior. If it cannot, planning must stop and
surface the smallest contract-safe alternative before implementation.

## Approval Gate

The project owner approved this specification on 2026-08-28 and subsequently
approved the dependency-ordered implementation plan and task checklist. The
backend implementation is complete and verified. Deployment, database or
dependency changes, and external Android changes remain separate approval
gates.
