# Spec: Patient-Account Link Identity Safety

**Status:** Implemented and verified
**Specification date:** 2026-09-17
**Implementation verified:** 2026-09-19
**Decision owner:** Project owner

## Objective

Prevent a patient mobile account from being linked to a clinic Patient record
whose identity is incompatible with that account. Linking grants access to
clinical resources, so staff judgment, candidate ranking, possession of an
invitation, or a decision note must never override a failed identity check.

The invariant applies when a link is created. Afterward, the relationship
remains active if account or clinic details change. A later incompatibility is
flagged for staff review instead of silently synchronizing records,
automatically unlinking the account, or interrupting patient access.

Success means:

1. every production path that writes `patients.user_id` applies the same
   locked, server-side compatibility check;
2. incompatible or insufficient identity evidence leaves all workflow state
   unchanged;
3. later identity drift creates an auditable staff review without changing
   the active link; and
4. mobile responses never disclose candidate identities or clinic values.

## Approved Product Decisions

1. **Strict at link time.** Required evidence must be compatible immediately
   before `patients.user_id` is set.
2. **No override.** Staff cannot bypass the identity invariant with a note,
   role, confirmation modal, invitation, or alternate link screen.
3. **Relationship survives later drift.** A linked account remains linked and
   keeps clinical API access while identity review is pending.
4. **No silent synchronization.** Account edits never update Patient fields,
   and Patient edits never update account fields.
5. **Explicit resolution.** Staff may clear an identity-review flag only when
   the current account and Patient are compatible. Otherwise staff must
   correct the appropriate source record or explicitly unlink the account.
6. **Linked-name step-up.** An actual first- or last-name change on an already
   linked account requires one valid step-up token. DOB keeps its existing
   unconditional step-up rule. Unlinked name changes and middle-name-only
   changes do not gain a new step-up requirement.
7. **Existing links are reconciled, not automatically severed.** Deployment
   audits current links and marks incompatible ones for review without
   unlinking them.

## Identity Compatibility Policy

Identity compatibility is deterministic. Candidate ranking and fuzzy search
may help staff discover records, but they are not authorization evidence.

| Evidence | Rule |
|---|---|
| First name | Required on both sides; exact after name normalization |
| Last name | Required on both sides; exact after name normalization |
| Date of birth | Required on both sides; exact calendar-date match |
| Middle name | If present on both sides, exact after normalization; missing on either side is neutral |
| Verified contact | At least one verified account phone or email blind index must equal the Patient blind index for the same contact type |

Name normalization reuses the existing behavior: trim, collapse internal
whitespace, and Unicode-safe lowercase for comparison while preserving stored
display values. It does not remove punctuation, strip accents, infer nicknames,
compare initials, reorder names, or use phonetic/edit-distance matching.

A result has three PII-safe lists:

- `matched_fields`;
- `mismatched_fields`; and
- `missing_fields`.

The result is eligible only when both mismatch and missing lists are empty.
Reason codes contain field names only, never raw values.

## Trust Boundaries and Threat Model

### Assets

- clinical demographics;
- appointments and encounter history;
- prescriptions and optical orders;
- conversations, attachments, and notifications; and
- the integrity of the clinic Patient identity.

### Untrusted or fallible inputs

- self-asserted account name and DOB;
- staff-selected Patient IDs;
- appointment-request identity submissions;
- invitation codes and OTP requests;
- stale account, contact, request, invitation, or Patient state; and
- duplicate or concurrent link attempts.

### Required controls

- OTP-verified account contacts remain the possession evidence;
- authorization stays at each API or Filament boundary;
- compatibility is re-evaluated on freshly locked rows;
- one canonical action performs the actual link mutation;
- database uniqueness remains defense in depth for one-to-one cardinality;
- audit metadata excludes PII values; and
- mobile errors remain generic and enumeration-safe.

## Scope

### Link-creation paths

The invariant applies to all current production paths:

1. staff approval of a Patient Link Request;
2. authenticated Patient Invitation acceptance;
3. registration completed with an invitation code;
4. staff resolution of an unlinked Appointment Request;
5. Patient Record → Link Account; and
6. Patient Account → Link Patient Record.

The unused legacy direct-registration controller method that creates a linked
Patient must be removed or refactored so it cannot become a future bypass.

### Post-link drift sources

The current link is re-evaluated after an actual change to:

- account first, middle, or last name;
- account date of birth;
- verified account phone/email ownership;
- Patient first, middle, or last name;
- Patient date of birth; or
- Patient phone/email.

If the current pair becomes incompatible, the Patient link is marked for
review in the same transaction. Returning to a compatible state does not
automatically clear the flag; staff must explicitly resolve it.

### Out of scope

- automatic Patient/account field synchronization;
- fuzzy identity authorization;
- household, guardian, dependent, or proxy accounts;
- automatic unlinking or clinical API suspension;
- changing OTP purpose, lifetime, or delivery provider;
- replacing `patients.user_id` with a new link table; and
- exposing candidate details or match reasons to Android.

## Data Model

Add server-controlled state to `patients`:

- `identity_review_required` boolean, default `false`, indexed for the staff
  review filter; and
- `identity_review_required_at` nullable timestamp.

The Patient model mirrors the boolean default and casts both fields. Neither
field is mass assignable.

Existing rows migrate to `identity_review_required = false`. A separate
reconciliation command performs the data audit so schema deployment does not
mix DDL with Patient-data decisions.

The existing `audit_logs` table records:

- when a compatible link is created;
- the link source and source record ID;
- matched field names;
- when review becomes required and which field categories caused it; and
- when review is explicitly resolved.

Audit metadata must not contain names, DOB values, phone numbers, email
addresses, OTPs, invitation codes, tokens, or snapshot values.

## Application Architecture

### Identity matcher

`PatientAccountIdentityMatcher` evaluates a User and Patient and returns a
typed `PatientAccountIdentityMatch` result. The matcher is the single source
of truth for link eligibility and drift detection.

### Canonical link action

`LinkPatientAccount` is the only normal application writer that sets a
non-null `patients.user_id`. It:

1. reloads and locks User before Patient;
2. confirms the account and Patient are not linked elsewhere;
3. evaluates compatibility on locked current state;
4. fails with no mutation when ineligible;
5. assigns `user_id` explicitly rather than through mass assignment;
6. clears stale review state on the newly verified link;
7. associates the account conversation; and
8. records the canonical PII-safe link audit.

Workflow actions retain ownership of their source-specific state changes.
Those changes and the canonical link must share one outer database transaction.
The common lock order is:

```text
User -> workflow row -> Patient -> dependent rows
```

### Candidate discovery

`RankPatientCandidates` may retain strength/reason ranking for staff discovery.
Selectors show only candidates that pass the hard matcher. Removing a record
from the selector is a usability measure; the canonical link action remains
the authoritative security check.

## Workflow Behavior

### Patient Link Request approval

- Preserve the existing immutable-snapshot-versus-current-account stale check.
- Match the locked current account to the selected locked Patient.
- Remove the ability to approve weak or arbitrary “Other Unlinked Patients.”
- A failed match leaves the request pending and the Patient unlinked.
- Successful approval, appointment-request backfill, conversation association,
  and audit commit atomically.

### Invitation acceptance and registration invitation

- Preserve invitation validity, account binding, OTP, and exact invited-contact
  checks.
- Add current demographic/contact compatibility immediately before linking.
- Consolidate the two invitation-link mutation implementations through the
  canonical link action.
- A failed match returns a generic `PATIENT_IDENTITY_MISMATCH` error and does
  not consume/accept workflow state beyond the existing OTP semantics.
- Idempotent retry remains valid only for the same already-linked account.

### Appointment Request resolution

- The immutable request identity and current account must identify the same
  person before resolving the request to a Patient and creating an account
  link.
- Selecting or creating an incompatible Patient fails the entire operation.
- New Patient creation and request/account linking must be atomic so a failed
  match does not leave an orphan Patient.
- The existing Appointment Request remains pending and unlinked on failure.

### Direct Filament linking

- Preserve both admin entry points, but route them through the canonical action.
- Add explicit link authorization rather than relying only on `visible()`.
- Search/select only eligible candidates.
- Server-side revalidation under locks remains mandatory.

## Post-Link Identity Review

When a linked pair becomes incompatible:

1. keep `patients.user_id` unchanged;
2. set `identity_review_required = true` and set the timestamp if the flag was
   previously false;
3. write one deduplicated audit event with reason codes and mutation source;
4. keep patient clinical routes authorized as linked; and
5. show staff a review badge/filter and current field-level reason codes.

Staff resolution has two valid outcomes:

- **Resolve:** available only when a fresh locked comparison is eligible; it
  clears the flag and writes an audit event.
- **Unlink:** reuse the existing admin-only unlink action with a required
  reason. Unlink also clears review state.

There is no “accept mismatch” action.

## API Contract

### `PATCH /api/v1/me`

Existing field validation and response shape remain. Conditional step-up is:

| Request | Step-up required |
|---|---:|
| Any submitted DOB, linked or unlinked | yes |
| Actual first-name change while linked | yes |
| Actual last-name change while linked | yes |
| First/last normalized no-op while linked | no |
| First/last change while unlinked | no |
| Middle-name-only change | no |

A request changing name and DOB consumes one step-up token once. The
race-safe profile action rechecks linked state and actual dirty fields after
locking the account. Middleware success alone is not the authorization
boundary.

`link_status` remains `linked` during identity review. The review flag is
staff-operational state and is not added to the mobile PatientAccountResource
in this scope.

### Link failure

Mobile invitation/registration flows use:

```json
{
  "error": {
    "code": "PATIENT_IDENTITY_MISMATCH",
    "message": "The account details do not match the patient record. Contact the clinic for assistance."
  }
}
```

The response is HTTP 422 and contains no Patient values, candidate IDs,
candidate counts, match strengths, or mismatch field names.

## Existing-Data Reconciliation

Add `patient-links:audit-identity`:

- default mode is read-only and reports aggregate counts by safe reason code;
- `--mark-review` locks and marks incompatible current links in chunks;
- it never unlinks, synchronizes, or prints PII;
- repeated execution is idempotent; and
- production rollout runs dry-run first, reviews totals, then explicitly runs
  mark mode.

## Testing Strategy

Use Pest feature tests and existing MySQL/Sail infrastructure.

Required coverage includes:

- matcher normalization, missing evidence, every mismatch, and both contact
  types;
- all six link paths succeeding with compatible fixtures;
- all six paths failing atomically with incompatible fixtures;
- concurrent/replayed link attempts and consistent lock-time rechecks;
- candidate selectors excluding ineligible records;
- linked versus unlinked conditional step-up, normalized no-ops, combined
  name/DOB requests, invalid/reused/other-account proofs;
- drift flags for account, contact, and Patient changes;
- deduplicated PII-safe audits;
- review resolution and unlink behavior;
- reconciliation dry-run, mark mode, and idempotency; and
- unchanged active-link middleware behavior while review is pending.

## Installed Stack and Commands

- PHP 8.5, Laravel 13, MySQL
- Filament 5, Livewire 4, Sanctum 4
- Pest 4 / PHPUnit 12
- Laravel Sail for every command

Commands:

```text
Focused tests: vendor/bin/sail artisan test --compact <test-files>
Full tests:    vendor/bin/sail artisan test --compact
Format PHP:    vendor/bin/sail bin pint --dirty --format agent
Routes:        vendor/bin/sail artisan route:list --except-vendor
```

## Project Structure and Code Style

- Domain workflow classes: `app/Actions/PatientAccounts/`
- API boundary: `app/Http/Controllers/Api/`, requests, middleware, resources
- Staff UI: `app/Filament/Resources/`
- Schema changes: additive files in `database/migrations/`
- Feature tests: existing matching directories under `tests/Feature/`
- Contract/context: `docs/API_CONTRACT.md`, `docs/BACKEND_CONTEXT.md`

Use constructor injection, explicit PHP parameter/return types, promoted
properties, array-shape PHPDoc where needed, Eloquent relationships, and
curly braces for every control structure. Linking logic belongs in actions,
not controllers, Livewire closures, model observers, or raw queries.

## Boundaries

### Always

- re-evaluate compatibility on locked current data;
- authorize each staff/API entry point;
- keep source workflow changes atomic with the link;
- use verified account contacts only;
- preserve generic mobile errors and PII-safe audits; and
- add a failing Pest test before each behavioral slice.

### Ask first

- suspending clinical access during identity review;
- adding guardian/dependent linking;
- changing the approved compatibility fields;
- auto-unlinking or auto-synchronizing records; or
- adding dependencies or an external identity service.

### Never

- allow a staff mismatch override;
- use fuzzy matching as authorization;
- expose candidate or clinic identity details to mobile clients;
- log raw identity/contact values; or
- mix reconciliation data mutation into the schema migration.

## Success Criteria

- [x] Exactly one production action can establish a Patient account link.
- [x] Every link entry point fails closed for mismatch or missing evidence.
- [x] Link failures leave source, Patient, account, conversation, audit, and
      downstream request state unchanged.
- [x] Later drift flags review without unlinking or denying clinical access.
- [x] Staff cannot clear review while current details remain incompatible.
- [x] Linked first/last changes and every DOB submission have race-safe
      single-consumption step-up protection.
- [x] Current incompatible links can be identified and marked without PII
      output or automatic unlinking.
- [x] Focused test suites pass and Pint reports no remaining changes.
- [ ] Full test suite passes before merge.
- [x] Backend context and API contract match shipped behavior.

## Open Questions

None. Changes to the approved policy require updating and re-approving this
specification before implementation continues.
