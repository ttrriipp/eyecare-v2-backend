# Tasks: Unlinked Appointment Request Identity Snapshot

## Status

Approved and implemented on 2026-08-03. This is Phase 3 (**Tasks**) for the
expanded identity-snapshot amendment and implementation plan.

## Approved Inputs

- `docs/specs/mobile-patient-accounts-and-appointment-requests-spec.md`
  Confirmed Decisions 56-64 and Success Criteria 33-38.
- `docs/specs/unlinked-appointment-request-identity-snapshot-plan.md`.
- Existing unrelated worktree changes remain user-owned and must be preserved.

## Execution Rules

- Complete tasks sequentially because they share the appointment-request
  contract and currently modified test/documentation files.
- Re-read every overlapping diff immediately before editing it. Never replace,
  restore, or discard existing user changes.
- Search the installed Laravel and Filament documentation before framework code
  changes.
- Start each behavior change with its named Pest assertion, then implement the
  minimum code needed to pass it.
- Use Laravel Sail for PHP, Artisan, tests, formatting, and frontend commands.
- Run `vendor/bin/sail bin pint --dirty --format agent` after PHP changes.
- Do not add a migration, dependency, route, response field, or patient-linking
  workflow under this amendment.
- Never log, notify, audit, or serialize the plaintext identity snapshot or full
  verified contact.

## Phase A: Contract and Submission

### Task 1: Reconcile the Additive Mobile Contract

**Description**

Merge the approved optional `identity` request object into the authoritative
mobile API contract before changing runtime behavior. Preserve all current
in-progress availability and frame-catalog documentation edits.

**Acceptance criteria**

- `POST /api/v1/appointment-requests` documents the optional identity object
  containing phone, optional email, all structured name fields, date of birth,
  gender, occupation, and home address, plus linked/unlinked rules, account
  fallback, and validation failures.
- The successful response remains unchanged and explicitly excludes identity
  and contact snapshots.
- No route count, response field, or unrelated contract section changes.

**Files**

- `docs/API_CONTRACT.md`

**Verification**

```bash
git diff --check -- docs/API_CONTRACT.md
git diff -- docs/API_CONTRACT.md
```

**Dependencies:** None.

**Estimated scope:** Small (1 file).

### Task 2: Submit and Persist the Encrypted Identity Snapshot

**Description**

Implement the complete unlinked submission slice: boundary validation,
effective identity fallback, server-derived verified primary contact, and
transactional encrypted snapshot persistence. Carefully merge the existing
uncommitted availability tests in the shared test file.

**Acceptance criteria**

- Submitted unlinked identity or complete account fallback creates one request
  whose snapshot contains only approved identity/contact metadata and whose raw
  database value does not contain plaintext PII.
- Linked requests reject `identity`, retain authoritative `patient_id`, and
  store no snapshot; client Patient/verification claims, mismatched phones,
  and unknown identity keys return validation errors.
- Missing effective identity, invalid date of birth, or missing/ambiguous
  verified primary contact creates no request and no capacity hold, while the
  existing scheduling, expiry, active-limit, and response behavior remains
  intact.

**Files**

- `app/Actions/Appointments/BuildAppointmentRequestIdentitySnapshot.php`
- `app/Actions/Appointments/SubmitAppointmentRequest.php`
- `app/Http/Requests/Api/StoreAppointmentRequest.php`
- `app/Http/Controllers/Api/AppointmentRequestController.php`
- `tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`

**Verification**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SubmitAppointmentRequestTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 1.

**Estimated scope:** Medium (5 files).

### Task 3: Lock Down Model Encryption and Patient Responses

**Description**

Add focused model/factory support for identity snapshots and strengthen every
owned appointment-request response against accidental sensitive serialization.
Add safe model helpers needed later by staff review without returning the raw
snapshot through mobile endpoints.

**Acceptance criteria**

- A factory state creates a representative unlinked snapshotted request, and
  model tests prove encrypted-at-rest plus correctly cast structured data.
- Submit, list, detail, and cancel responses contain no snapshot keys, full
  contact, candidate information, resolution actor, or raw encrypted attribute
  names.
- Display-name and contact-mask helpers are deterministic, null-safe, and never
  mutate the stored snapshot.

**Files**

- `app/Models/AppointmentRequest.php`
- `database/factories/AppointmentRequestFactory.php`
- `tests/Feature/Appointments/AppointmentRequestModelTest.php`
- `tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php`

**Verification**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentRequestModelTest.php tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 2.

**Estimated scope:** Medium (4 files).

### Checkpoint A: Submission and Privacy

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SubmitAppointmentRequestTest.php tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php tests/Feature/Appointments/AppointmentRequestModelTest.php
vendor/bin/sail bin pint --dirty --format agent
```

Confirm one linked request and both explicit/fallback unlinked submissions in
the database. The encrypted column must be unreadable plaintext while the
patient response shapes remain unchanged.

## Phase B: Snapshot-Based Matching and Staff Review

### Task 4: Rank Candidates from the Immutable Snapshot

**Description**

Refactor the existing candidate ranker around one normalized scoring core and
add a snapshot entry point for Appointment Requests. Preserve the existing
User-based Patient Link Request entry point and its behavior.

**Acceptance criteria**

- Snapshot matching uses only the stored structured identity and server-derived
  contact; later account-profile changes do not alter its input.
- Exact contact/name/date-of-birth, multiple-candidate, no-match, and already
  linked Patient cases produce the approved ranking/reason behavior.
- Existing Patient Link Request candidate creation and mobile response-safety
  tests continue to pass without a new matcher or candidate table.

**Files**

- `app/Actions/PatientAccounts/RankPatientCandidates.php`
- `tests/Feature/Appointments/AppointmentRequestIdentityMatchingTest.php`
- `tests/Feature/Patients/SubmitPatientLinkRequestTest.php`

**Verification**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentRequestIdentityMatchingTest.php tests/Feature/Patients/SubmitPatientLinkRequestTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 3.

**Estimated scope:** Medium (3 files).

### Task 5: Show Submitted Identity in Staff Review

**Description**

Add one read-only **Submitted identity** section to the existing Appointment
Request review experience. Show the expanded identity, submission time, masked
phone/email contact details, and on-demand candidates only when the request was
submitted while unlinked.

**Acceptance criteria**

- Staff/admin review shows structured name, date of birth, masked phone/email,
  gender, occupation, home address, contact type, submission time, and
  candidate strength/reasons for a snapshotted request; linked requests omit
  the section.
- Raw contact values and encrypted attribute names are absent from rendered
  output, table columns, search, exports, notifications, and unauthorized
  responses.
- Existing accept/reject visibility, authorization, request list layout, and
  navigation remain unchanged.

**Files**

- `app/Filament/Resources/AppointmentRequests/Schemas/AppointmentRequestForm.php`
- `app/Filament/Resources/AppointmentRequests/Pages/ViewAppointmentRequest.php`
- `tests/Feature/Filament/AppointmentRequestResourceTest.php`

**Verification**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRequestResourceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 4.

**Estimated scope:** Medium (3 files).

### Checkpoint B: Matching and Staff Privacy

```bash
vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentRequestIdentityMatchingTest.php tests/Feature/Patients/SubmitPatientLinkRequestTest.php tests/Feature/Filament/AppointmentRequestResourceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

Use a real browser to verify the staff review at desktop and narrow widths,
confirm the full verified contact is absent from rendered HTML, and inspect
recent console/network logs.

## Phase C: Reconciliation and Release Gate

### Task 6: Reconcile Context and Run the Full Gate

**Description**

Update the living backend context only after runtime behavior is proven, then
run the complete regression, formatting, build, static-diff, and worktree
preservation checks. Fix only defects revealed by these gates; do not expand
scope.

**Acceptance criteria**

- `docs/BACKEND_CONTEXT.md` describes the encrypted unlinked snapshot,
  server-derived verified contact, unchanged patient responses, and staff-only
  review without disturbing current route/frame edits.
- Focused and full tests, Pint, frontend build, and diff checks pass with no
  migration, dependency, route-count, or response-field change.
- Final diff preserves all unrelated existing modifications and contains no
  plaintext test PII outside factories/fixtures/assertions where required.

**Files**

- `docs/BACKEND_CONTEXT.md`
- Defect-specific files only if a verification gate fails.

**Verification**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SubmitAppointmentRequestTest.php tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php tests/Feature/Appointments/AppointmentRequestModelTest.php tests/Feature/Appointments/AppointmentRequestIdentityMatchingTest.php tests/Feature/Patients/SubmitPatientLinkRequestTest.php tests/Feature/Filament/AppointmentRequestResourceTest.php
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
git diff --check
git status --short
```

**Dependencies:** Checkpoints A and B.

**Estimated scope:** Small (1 planned documentation file; defect-specific fixes
only after a failing gate).

## Completion Definition

The amendment is complete only when all six tasks and both checkpoints pass,
unlinked explicit and fallback identities are encrypted and usable for staff
matching, linked requests remain authoritative, full contacts and snapshots are
absent from patient-facing output, and unrelated worktree changes remain
preserved.
