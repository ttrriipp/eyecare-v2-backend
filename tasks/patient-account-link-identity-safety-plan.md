# Implementation Plan: Patient-Account Link Identity Safety

**Specification:** `docs/specs/patient-account-link-identity-safety-spec.md`
**Status:** Proposed; implementation not yet authorized
**Planning date:** 2026-09-17

## Overview

Introduce one deterministic identity matcher and one canonical link mutation
boundary, migrate every existing link workflow to it, and add an operational
review state for identity drift after linking. The rollout preserves active
patient access, prevents staff overrides, reconciles existing links without
automatic unlinking, and keeps candidate details out of mobile responses.

## Architecture Decisions

1. `patients.user_id` remains the authoritative active one-to-one link.
2. Compatibility requires exact normalized first/last name, exact DOB,
   compatible optional middle name, and one exact verified same-type contact.
3. Candidate ranking is discovery only; the matcher is the authorization gate.
4. `LinkPatientAccount` is the only normal non-null writer of
   `patients.user_id`.
5. Post-link incompatibility sets Patient-owned review state without changing
   `link_status` or active-link middleware behavior.
6. Review clears only after a current compatible comparison; unlink remains
   the explicit alternative.
7. Existing links are handled by an idempotent dry-run/mark command rather
   than migration DML.

## Dependency Graph

```text
Approved spec and contract
    -> review-state schema + model contract
        -> deterministic matcher
            -> canonical locked linker
                -> link-request and invitation paths
                -> appointment-request and direct staff paths
                    -> post-link drift + conditional step-up
                        -> staff resolution + reconciliation command
                            -> contract reconciliation and full verification
```

## Phase 1: Foundation

### Task 1: Add Patient identity-review state

Add the reversible schema and model contract without changing runtime behavior.

**Acceptance criteria:**

- `patients` has indexed `identity_review_required` and nullable review time.
- Existing rows default to trusted and no link is modified by the migration.
- Fields are cast/server-controlled and absent from mass assignment.

**Likely files:** migration, `Patient`, Patient model/migration tests.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Patients/PatientModelTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** None. **Scope:** Small.

### Task 2: Implement the deterministic matcher

Build the typed compatibility result and matcher before changing workflows.

**Acceptance criteria:**

- Required/middle/contact rules exactly match the approved specification.
- Results contain only safe field reason codes.
- Matcher tests cover normalization, missing fields, mismatch fields, verified
  phone, verified email, and unverified contacts.

**Likely files:** matcher, result value object, focused Pest test.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/PatientAccounts/PatientAccountIdentityMatcherTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 1. **Scope:** Small.

### Task 3: Add the canonical locked link action

Centralize link eligibility, mutation, conversation association, and audit.

**Acceptance criteria:**

- The action locks User then Patient and rechecks both link states.
- Ineligible linking throws without any mutation or audit.
- Eligible linking clears review state, associates conversation, and records
  matched fields/source without PII.
- Patient link ownership is removed from generic mass assignment.

**Likely files:** link action, `Patient`, audit enum, action test, test factory
state.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/PatientAccounts/LinkPatientAccountTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 1-2. **Scope:** Medium.

## Checkpoint A: Foundation

- Matcher matrix and canonical action tests pass.
- Schema rollback is valid.
- No production workflow calls the new action yet.
- Review matcher policy and audit metadata before integration.

## Phase 2: Dedicated Linking Workflows

### Task 4: Guard Patient Link Request approval

Route approval through the canonical action and remove approval of arbitrary
unranked Patients.

**Acceptance criteria:**

- Existing stale-snapshot protection remains.
- Only eligible candidates can be selected or approved.
- Failure leaves request, Patient, appointment-request backfill, conversation,
  and audit state unchanged.

**Likely files:** review action, review page, focused action and Filament tests.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Patients/ReviewPatientLinkRequestTest.php tests/Feature/Filament/PatientLinkRequestReviewTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 3. **Scope:** Medium.

### Task 5: Guard authenticated invitation acceptance

Preserve OTP/account-contact checks while delegating the link mutation.

**Acceptance criteria:**

- Compatibility is checked under the canonical lock order.
- Mismatch returns the generic stable error with no Patient values.
- Success and same-account idempotent retry retain their current behavior.

**Likely files:** acceptance action, invitation controller/error mapping,
invitation API tests.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AcceptPatientInvitationTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 3. **Scope:** Medium.

### Task 6: Guard registration-with-invitation linking

Remove the duplicate registration link mutation and reuse the canonical action.

**Acceptance criteria:**

- Registration transaction and contact uniqueness behavior remain atomic.
- Invitation mismatch rolls back account/contact/invitation/link creation.
- Compatible registration links once and returns the existing resource shape.

**Likely files:** registration action, registration API tests, invitation
fixtures/factories.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/PatientRegistrationTest.php tests/Feature/Api/V1/AcceptPatientInvitationTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 3 and 5. **Scope:** Medium.

## Checkpoint B: Dedicated Workflows

- Link request and both invitation paths enforce identical eligibility.
- Mobile mismatch responses are enumeration-safe.
- Retry, race, and rollback tests pass.

## Phase 3: Operational Linking Workflows

### Task 7: Guard Appointment Request resolution

Make Patient creation/resolution and account linking atomic.

**Acceptance criteria:**

- Existing Patient selection validates request snapshot, account, and Patient.
- New Patient creation rolls back if the resulting link is ineligible.
- Failed resolution leaves no orphan Patient and leaves the request pending.

**Likely files:** appointment link action, detail page, table action, focused
appointment/Filament tests.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Appointments/LinkAppointmentRequestToPatientTest.php tests/Feature/Filament/ViewAppointmentRequestTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 3. **Scope:** Medium.

### Task 8: Guard both direct admin link actions

Retain the two entry points but make them authorized, eligible-only clients of
the canonical action.

**Acceptance criteria:**

- Both actions have explicit admin authorization.
- Selectors omit incompatible accounts/Patients.
- Crafted submissions still fail in the server action without side effects.

**Likely files:** Patient edit page, Patient Account view page, Patient policy,
Filament linking tests.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/PatientLinkAccountTest.php tests/Feature/Filament/PatientLinkedRecordsTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 2-3. **Scope:** Medium.

### Task 9: Remove remaining production bypasses

Remove/refactor the unused legacy direct-registration linker and verify that
every non-null Patient account assignment flows through the canonical action.

**Acceptance criteria:**

- No reachable controller, Filament closure, or workflow action directly sets
  a non-null Patient `user_id`.
- Unlink remains explicit and functional.
- Route-contract and link-access characterization tests remain green.

**Likely files:** Auth controller/imports, unlink action if explicit assignment
is needed, route and architecture/characterization tests.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php tests/Feature/Api/V1/PatientLinkAccessCharacterizationTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 4-8. **Scope:** Small.

## Checkpoint C: Link Boundary Complete

- All six production paths have positive and negative tests.
- Direct non-null link writes are eliminated outside the canonical action.
- Existing unlink and active-link access behavior remains green.

## Phase 4: Post-Link Drift and Review

### Task 10: Add race-safe linked-name step-up

Require one proof for actual linked first/last changes while preserving DOB and
unlinked behavior.

**Acceptance criteria:**

- Conditional checks distinguish linked state and normalized actual changes.
- One request changing name and DOB consumes one token once.
- The locked profile action rejects a linked-name race without verified proof.

**Likely files:** profile-specific middleware/action, route, profile action,
`MeEndpointTest`.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/MeEndpointTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 1-2. **Scope:** Medium.

### Task 11: Flag drift from account and contact changes

Re-evaluate linked identity after relevant account/contact mutations.

**Acceptance criteria:**

- Actual incompatible mutations set review state and one PII-safe audit.
- Compatible/no-op mutations do not flag or duplicate audits.
- The active link and clinical route access remain unchanged.

**Likely files:** profile action, contact mutation action(s), drift action,
focused API tests.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/MeEndpointTest.php tests/Feature/Api/V1/PatientContactManagementTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 1-2 and 10. **Scope:** Medium.

### Task 12: Flag and resolve drift from Patient edits

Add staff visibility and a non-overridable resolution action.

**Acceptance criteria:**

- Relevant linked Patient edits mark review when incompatible.
- Patient list/record surfaces review status and safe current reasons.
- Resolve clears only when a fresh locked match passes; unlink clears state.

**Likely files:** Patient edit page/action, Patient table/form, unlink action,
Filament tests.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/PatientIdentityReviewTest.php tests/Feature/Patients/ReviewPatientLinkRequestTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 1-3 and 11. **Scope:** Medium.

## Checkpoint D: Lifecycle Complete

- Account, contact, and clinic-record drift is covered.
- Review never suspends clinical access or silently clears itself.
- Staff can resolve a corrected pair or explicitly unlink it.

## Phase 5: Rollout and Reconciliation

### Task 13: Add existing-link reconciliation command

Provide aggregate dry-run evidence and an explicit idempotent mark mode.

**Acceptance criteria:**

- Default execution is read-only and prints only counts/reason codes.
- `--mark-review` marks incompatible links in chunks without unlinking.
- Repeated execution is idempotent and auditable.

**Likely files:** Artisan command, command tests, audit enum/action if needed.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/PatientAccounts/AuditPatientLinksCommandTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 1-2 and 11-12. **Scope:** Small.

### Task 14: Reconcile contracts and run release verification

Update canonical documentation only after implementation behavior is proven.

**Acceptance criteria:**

- API contract documents mismatch error and linked-name step-up behavior.
- Backend context documents invariant, review state, command, and actions.
- Focused suites, full suite, Pint, and diff checks pass.

**Likely files:** `docs/API_CONTRACT.md`, `docs/BACKEND_CONTEXT.md`, this spec,
plan/checklist status.

**Verification:**

```text
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
git diff --check
```

**Dependencies:** Tasks 1-13. **Scope:** Small.

## Checkpoint E: Ready to Deploy

- All success criteria in the specification are checked.
- Dry-run reconciliation output has been reviewed before mark mode.
- Migration precedes application writers during deployment.
- No dependency or external service change is required.
- Rollback preserves existing links and removes only unused review columns
  after application rollback.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| A link path remains outside the canonical action | High | Enumerate all current writers, remove mass assignment, add boundary characterization tests, and repeat source search at final review. |
| False negatives from inconsistent clinic data | High | Deterministic policy, eligible-only UI, correction-first workflow, no fuzzy authorization or override. |
| Deadlocks from mixed lock order | High | Standardize User → workflow → Patient ordering and add concurrent/replay tests. |
| OTP proof is consumed twice | High | Use one profile-specific conditional proof boundary and pass verified state to the locked action. |
| Failed new-Patient resolution leaves orphan data | High | Move creation and link resolution into one transaction and assert rollback. |
| Identity review unexpectedly blocks the patient | High | Keep active-link middleware based only on `patients.user_id`; add explicit access tests. |
| Audit or command output leaks PII | High | Store/print field reason codes and aggregate counts only. |
| Existing mismatches are silently trusted | Medium | Ship explicit dry-run and mark mode; make production execution a deployment checkpoint. |
| Large fixture churn obscures regressions | Medium | Add deliberate compatible factory states and update tests slice by slice. |

## Parallelization

Implementation is intentionally sequential through Task 3 because every later
slice depends on the matcher and canonical action. After Checkpoint A, Tasks
4, 5, 7, and 8 are independently testable but share link-action call sites;
parallel work requires separate worktrees and coordinated ownership. Tasks
10-12 must remain ordered because they share profile and review-state logic.

## Approval Gate

Implementation must not resume until the project owner approves this plan and
the companion checklist. Any change to match fields, override policy, active
access behavior, or reconciliation behavior returns to specification review.
