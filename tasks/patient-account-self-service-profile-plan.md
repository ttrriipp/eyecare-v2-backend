# Implementation Plan: Patient Account Self-Service Profile Editing

**Status:** Implemented and verified on 2026-08-28
**Specification:** `docs/specs/patient-account-self-service-profile-spec.md`
(approved 2026-08-28)
**Checklist:** `tasks/patient-account-self-service-profile-todo.md`
(implementation complete)

## Outcome

Allow an authenticated patient to edit account-owned names and, after step-up
verification, account date of birth through `PATCH /api/v1/me`. Keep verified
contacts and password on their existing dedicated workflows, reject every
unsupported field, never write the clinic-owned `patients` record, and prevent
staff from approving a patient-link request built from stale identity data.

No schema change, new route, dependency, OTP purpose, or Android-repository
change is planned. Discovery of a need for any of those returns the work to the
specification's Ask First gate.

## Architecture Decisions

### 1. Enforce the profile contract at the request boundary

`UpdateMeRequest` will accept exactly `first_name`, `middle_name`, `last_name`,
and `date_of_birth`. Its preparation step will trim names and normalize a blank
middle name to `null`; its post-validation hook will reject unknown and
prohibited top-level keys with `422`. At least one supported field is required.

This prevents Laravel's validated subset from turning a mixed payload into a
partial success. It also keeps email, phone, address, occupation, gender,
clinic fields, ownership identifiers, and server state out of this endpoint.

### 2. Reuse step-up verification conditionally

Extend the existing `require.step-up` middleware so route parameters may name
fields that trigger verification. With no field parameters, the middleware
continues to require step-up unconditionally for existing password and contact
routes. `PATCH /me` will declare `date_of_birth` as its trigger.

The header, token validation, error shape, issuer, lifetime, and current token
semantics remain unchanged. A request containing DOB is rejected before the
controller unless its `X-Step-Up-Token` is valid for the authenticated account;
name-only requests do not require the header.

### 3. Perform profile mutation as one account-owned transaction

A dedicated application action will lock the authenticated `User`, calculate
actual dirty values, and update only the approved account columns. If no value
changes after normalization, it returns successfully without expiring a link
request or writing a change audit.

For an actual identity change, the same transaction expires the account's
pending link request and records PII-safe audit metadata containing field names
and workflow outcomes only. The action does not accept a client-supplied User
or Patient ID and never accesses a Patient for mutation.

### 4. Centralize response relation loading

A small query/action boundary will load the relationships required by
`PatientAccountResource`: role, verified contacts, linked Patient, and pending
link request state. GET and PATCH `/me`, plus other authentication flows that
return the same resource, will use it so the resource stays query-free and all
responses report the same `link_status` and `linked_patient` shape.

### 5. Treat link identity snapshots as immutable evidence

A shared snapshot value/helper will build and compare normalized account
identity. New submissions include `middle_name` alongside first name, last
name, and DOB. Existing snapshots without the key are interpreted as a null
middle name, preserving readable historical data.

Actual account identity changes and verified contact-set changes used by
candidate ranking change a pending request to `expired`; the encrypted
snapshot and ranked candidates remain untouched.

### 6. Fail closed during staff approval

Approval will re-read and lock authoritative rows inside its transaction using
one documented order: User, pending PatientLinkRequest, then target Patient or
contact row as applicable. It will compare the locked User identity to the
stored snapshot before linking.

If stale, the transaction commits the request's `expired` status and audit
outcome, then the action reports a validation failure after commit. This avoids
rolling the expiry back with the exception. A concurrent account/contact
change and approval therefore cannot create a link from inconsistent data.

### 7. Keep staff-visible expiry explicit but PII-safe

The existing status column supports `expired`, so no migration is required.
The model/factory and Filament review surface will recognize it, expose a
read-only reason category or audit-derived explanation, exclude review actions,
and avoid displaying raw changed values.

## Dependency Graph

```text
Approved specification
        |
        v
Conditional step-up boundary
        |
        v
Strict PATCH request contract
        |
        v
Transactional account update + pending expiry + audit
        |
        +--------------------+
        v                    v
Consistent /me resource   Snapshot middle-name contract
context                     |
        |                    v
        |              Locked stale-approval guard
        |                    ^
        v                    |
Verified-contact expiry ----+
        |
        v
Expired staff state + legacy profile cleanup
        |
        v
Canonical docs, Android handoff, full verification
```

## Implementation Phases

### Phase 1: Secure account-update vertical slice

1. Make the existing step-up middleware optionally field-triggered and attach
   the DOB trigger to `PATCH /api/v1/me`, while proving all existing
   routes already using the middleware remain protected.
2. Reconcile `UpdateMeRequest` to the exact four-field allowlist, normalization,
   date rules, at-least-one rule, and atomic rejection of mixed or unknown
   payloads.
3. Add the transactional account-profile action, pending-request expiry
   collaborator, PII-safe audit events, and controller persistence for actual
   account changes only.
4A. Centralize `PatientAccountResource` relation loading and adopt it across
    GET and PATCH `/me` plus core authentication responses.
4B. Adopt the loader in the remaining OTP-challenge and invitation responses
    that return the same resource.

#### Checkpoint A: Account profile contract

- authenticated linked and unlinked accounts can update allowed names;
- any DOB-bearing payload requires a valid same-account step-up token;
- unsupported or mixed payloads return `422` with zero writes;
- no-op normalized updates create no expiry or change audit;
- actual changes update only `users`, never `patients`;
- GET and PATCH `/me` return the complete, accurate resource shape; and
- existing password and contact step-up behavior remains green.

### Phase 2: Stale link-request prevention

5. Introduce the normalized link-identity snapshot helper, include middle name
   in new submissions, and cover compatibility with historical snapshots.
6. Integrate pending-request expiry into actual profile changes and verified
   contact additions, replacements, and removals that alter candidate inputs;
   primary-contact changes alone do not expire a request.
7. Refactor staff approval to lock and revalidate the account, request, and
   Patient in a consistent order; commit an expired state before surfacing a
   stale-request validation response.
8A. Add explicit expired model/factory and staff-table behavior.
8B. Add staff-facing, PII-safe expiry provenance and ensure expired requests
    have no approve or reject action.

#### Checkpoint B: Link lifecycle integrity

- new snapshots contain normalized middle name;
- name, DOB, and relevant verified-contact changes atomically expire pending
  requests while retaining snapshot/candidate evidence;
- no-op profile updates, failed contact changes, and primary-only changes do
  not expire requests;
- stale or concurrently changed snapshots cannot produce a Patient link;
- expired requests cannot be reviewed and staff can identify the safe reason
  category; and
- audit metadata contains no names, DOB values, contacts, OTPs, tokens, or
  passwords.

### Phase 3: Contract cleanup and reconciliation

9. Remove the unrouted legacy Patient-profile controller and request classes
   only after route-contract tests prove `/api/v1/patient/profile` remains
   absent and no live reference exists.
10. Update `docs/API_CONTRACT.md`, `docs/BACKEND_CONTEXT.md`, and the Android
    handoff with the exact field ownership, step-up, error, response, and
    expired-link behavior. External Android implementation remains outside
    this repository and requires separate authorization.
11. Run focused and full regression verification, dead-reference and route
    checks, formatting, and security/API/code-quality review; reconcile every
    success criterion against evidence.

#### Checkpoint C: Ready for handoff

- focused profile, step-up, contact, link-review, audit, and route-contract
  tests pass;
- no live class or route advertises direct mobile Patient demographic editing;
- canonical documentation matches the implemented API and ownership boundary;
- Pint is clean;
- full-suite results are compared with the known unrelated baseline rather
  than concealed or weakened; and
- no schema, dependency, route, token-purpose, or external-client change was
  made without separate approval.

## Verification Strategy

Use test-driven vertical slices and the smallest affected Pest files during
each task, then broaden at checkpoints. All executable project commands run
through Sail.

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/MeEndpointTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/PatientContactTest.php
vendor/bin/sail artisan test --compact tests/Feature/Patients/SubmitPatientLinkRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/Patients/ReviewPatientLinkRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/AuditLogHardeningTest.php
vendor/bin/sail artisan route:list --path=api/v1 --except-vendor
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail artisan test --compact
```

The known repository baseline currently contains unrelated full-suite failures.
Implementation must report baseline-versus-new failures explicitly and must not
delete or weaken tests to manufacture a green result.

## Sequencing and Safe Parallelism

- Tasks 1-3 are sequential because they form the security and persistence
  boundary.
- Tasks 4A and 4B follow Task 3 so the new state is represented consistently.
- After Checkpoint A, snapshot work (Task 5) and resource-context regression
  hardening may proceed independently if separate agents are explicitly
  authorized and their files do not overlap.
- Tasks 6 and 7 are sequential because they share link-request transactions
  and lock ordering.
- Tasks 8A and 8B follow Task 7 so staff state reflects the final lifecycle.
- Cleanup and documentation wait for both implementation checkpoints.

## Rollback and Deployment Notes

There is no schema or data conversion. A code rollback restores the previous
API behavior, but rolling back after an actual request has been marked
`expired` must not resurrect that request automatically; the patient submits a
fresh request. Deployment should be atomic across request validation,
conditional step-up, profile persistence, and stale-approval protection so no
partial contract is exposed.

## Approval Gate

This plan and its checklist were approved by the project owner on 2026-08-28.
Implementation is complete in this backend repository. Deployment, external
Android changes, database changes, new dependencies, and scope changes remain
separate approval gates.
