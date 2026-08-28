# Task Checklist: Patient Account Self-Service Profile Editing

**Status:** Implementation complete; focused verification recorded 2026-08-28
**Specification:** `docs/specs/patient-account-self-service-profile-spec.md`
(approved 2026-08-28)
**Plan:** `tasks/patient-account-self-service-profile-plan.md`
(implementation complete)

Thirteen dependency-ordered work items across three phases. The plan and
checklist were approved on 2026-08-28; all implementation tasks and scoped
verification are complete. The repository-wide suite retains unrelated
failures and errors documented below.

## Execution Rules

- Implement in order and do not cross a checkpoint until its evidence passes.
- Before each Laravel, Filament, or Pest change, use Laravel Boost
  `search-docs` with version-scoped topic queries.
- Apply `laravel-best-practices` to PHP/Laravel work,
  `test-driven-development` and `pest-testing` to behavior changes,
  `security-and-hardening` to identity and authorization boundaries, and
  `api-and-interface-design` to the public contract.
- Generate supported Laravel/Pest files with Sail Artisan and
  `--no-interaction`; run all PHP, Artisan, Composer, and Node commands through
  Sail.
- Add or update a failing focused Pest expectation before changing behavior.
- Run `vendor/bin/sail bin pint --dirty --format agent` after every task that
  changes PHP.
- Preserve unrelated worktree edits and never delete or weaken an existing
  test.
- Stop and split a task if it exceeds roughly five files, three acceptance
  criteria, or one focused implementation session.
- Stop for approval if implementation needs a migration, dependency, new
  route, new OTP purpose/token behavior, rate-limit change, or Android edit.
- Raw identity/contact values and authentication secrets must never appear in
  logs, audit metadata, validation context, or test failure messages intended
  for production observability.

---

## Phase 1: Secure Account-Update Vertical Slice

### Task 1: Make step-up conditional for DOB payloads

**Description:** Extend the existing middleware contract so specified request
fields trigger step-up while existing routes with no parameters stay
unconditionally protected.

**Acceptance criteria:**

- [x] Name-only PATCH `/me` reaches validation without a step-up header; any
      payload containing `date_of_birth` requires the existing header/token.
- [x] Missing, invalid, expired, and another account's token receive the
      existing failure contract before mutation.
- [x] Password and contact routes already using this middleware remain
      unconditionally step-up-protected.

**Verification:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/MeEndpointTest.php --filter=step-up`
- [x] Existing focused password/contact step-up tests pass.
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** None

**Files likely touched:**

- `app/Http/Middleware/RequireStepUpToken.php`
- `routes/api.php`
- `tests/Feature/Api/V1/MeEndpointTest.php`

**Estimated scope:** M (3 files)

### Task 2: Enforce the exact PATCH profile allowlist

**Description:** Make the request object the strict, normalized boundary for
the four approved account fields.

**Acceptance criteria:**

- [x] First/last names are trimmed and nonblank, middle name is trimmed or
      normalized to `null`, and DOB is a non-null exact `Y-m-d` date before
      today.
- [x] A request requires at least one allowed field and rejects every unknown
      or prohibited top-level key with field-level `422` errors.
- [x] Mixed valid/unsupported payloads fail atomically rather than accepting a
      validated subset.

**Verification:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/MeEndpointTest.php --filter=validation`
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 1

**Files likely touched:**

- `app/Http/Requests/Api/UpdateMeRequest.php`
- `tests/Feature/Api/V1/MeEndpointTest.php`

**Estimated scope:** S (2 files)

### Task 3: Persist account identity transactionally

**Description:** Add the account-owned update action, reusable pending-link
expiry collaborator, and PII-safe auditing; connect the controller only after
the behavior is covered.

**Acceptance criteria:**

- [x] Actual allowed changes update the authenticated `users` row and never a
      `patients` row; normalized no-ops perform no expiry or change audit.
- [x] Account update, at-most-one pending-request expiry, and audit records
      commit or roll back together under documented locks.
- [x] Audit metadata contains only changed field names, safe reason/outcome,
      and non-PII identifiers already permitted by the audit policy.

**Verification:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/MeEndpointTest.php --filter=update`
- [x] `vendor/bin/sail artisan test --compact tests/Feature/AuditLogHardeningTest.php`
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 2

**Files likely touched:**

- `app/Actions/PatientAccounts/UpdateAccountProfile.php` (new)
- `app/Actions/PatientAccounts/ExpirePendingPatientLinkRequest.php` (new)
- `app/Enums/AuditEvent.php`
- `app/Http/Controllers/Api/AuthController.php`
- `tests/Feature/Api/V1/MeEndpointTest.php`

**Estimated scope:** M (5 files)

### Task 4A: Centralize the `/me` resource context

**Description:** Keep `PatientAccountResource` query-free while ensuring GET,
PATCH, and core authentication responses load the same required relationships.

**Acceptance criteria:**

- [x] One reusable loader includes role, verified contacts, linked Patient,
      and pending link-request state without loading unrelated history.
- [x] GET and PATCH `/me` return identical shape and accurate `link_status` for
      unlinked, pending-review, and linked accounts.
- [x] Core register/login/recovery responses retain their existing contract and
      use the same loader where they return `PatientAccountResource`.

**Verification:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/MeEndpointTest.php`
- [x] Affected AuthController feature tests pass.
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 3

**Files likely touched:**

- `app/Actions/PatientAccounts/LoadPatientAccountContext.php` (new)
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Resources/PatientAccountResource.php` (only if contract logic needs
  reconciliation)
- `tests/Feature/Api/V1/MeEndpointTest.php`

**Estimated scope:** M (4 files)

### Task 4B: Adopt the resource loader in remaining auth flows

**Description:** Remove response-shape drift from the remaining controllers
that return `PatientAccountResource`.

**Acceptance criteria:**

- [x] OTP challenge and invitation flows use the shared loader.
- [x] Their status codes, tokens, verification behavior, and response keys do
      not otherwise change.
- [x] A dead-reference search finds no direct incomplete resource construction
      at live call sites.

**Verification:**

- [x] Affected OTP challenge and patient invitation feature tests pass.
- [x] `rg -n "new PatientAccountResource|PatientAccountResource::make" app/Http/Controllers`
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 4A

**Files likely touched:**

- `app/Http/Controllers/Api/OtpChallengeController.php`
- `app/Http/Controllers/Api/PatientInvitationController.php`
- relevant existing OTP/invitation feature test files

**Estimated scope:** S (up to 4 files)

### Checkpoint A: Account profile contract

- [x] Tasks 1-4B focused suites pass.
- [x] Linked and unlinked accounts can update allowed account fields.
- [x] DOB requires valid same-account step-up; names do not.
- [x] Unsupported/mixed payloads produce `422` and zero writes.
- [x] Patient data is byte-for-byte unchanged after profile updates.
- [x] GET and PATCH `/me` return accurate, equivalent resource shapes.
- [x] Existing contact/password contracts remain green and Pint is clean.

---

## Phase 2: Link-Request Integrity

### Task 5: Version the identity snapshot shape with middle name

**Description:** Centralize normalized snapshot construction/comparison and
use it for new patient-link submissions without breaking historical snapshots.

**Acceptance criteria:**

- [x] New encrypted snapshots contain normalized first, middle, last, and DOB
      values; candidate ranking remains based on account/verified contacts.
- [x] Comparison treats a missing historical `middle_name` key as `null` and
      never logs decrypted snapshot values.
- [x] Submission tests prove immutable snapshot/candidate behavior.

**Verification:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Patients/SubmitPatientLinkRequestTest.php`
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Checkpoint A

**Files likely touched:**

- `app/Actions/PatientAccounts/PatientLinkIdentitySnapshot.php` (new)
- `app/Actions/PatientAccounts/SubmitPatientLinkRequest.php`
- `tests/Feature/Patients/SubmitPatientLinkRequestTest.php`

**Estimated scope:** M (3 files)

### Task 6: Expire pending requests on relevant contact changes

**Description:** Reuse the expiry collaborator in contact verification and
removal transactions when the set of verified candidate-ranking inputs
actually changes.

**Acceptance criteria:**

- [x] Adding/replacing a verified email or phone value and removing a verified
      contact expire a pending request atomically.
- [x] Failed verification, identical verified values, unverified removal, and
      primary-only changes do not expire a request.
- [x] Contact endpoint contracts remain unchanged and audit metadata contains
      no contact value, OTP, or token.

**Verification:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/PatientContactTest.php`
- [x] `vendor/bin/sail artisan test --compact tests/Feature/AuditLogHardeningTest.php`
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 5; expiry collaborator from Task 3

**Files likely touched:**

- `app/Http/Controllers/Api/AuthController.php`
- `app/Actions/PatientAccounts/ExpirePendingPatientLinkRequest.php`
- `tests/Feature/Api/V1/PatientContactTest.php`
- `tests/Feature/AuditLogHardeningTest.php`

**Estimated scope:** M (4 files)

### Task 7: Reject stale or racing staff approval

**Description:** Move approval decisions inside the locked transaction and
revalidate the immutable identity snapshot before linking.

**Acceptance criteria:**

- [x] Approval locks User, pending request, then target Patient consistently
      and fails closed if status, snapshot, account link, or target eligibility
      changed.
- [x] A stale request commits `expired` plus a safe audit outcome before a
      validation failure is surfaced; it never sets `patients.user_id`.
- [x] Repeat/concurrency-oriented tests prove at most one terminal outcome and
      no link from stale identity.

**Verification:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Patients/ReviewPatientLinkRequestTest.php`
- [x] `vendor/bin/sail artisan test --compact tests/Feature/AuditLogHardeningTest.php`
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 5 and 6

**Files likely touched:**

- `app/Actions/PatientAccounts/ReviewPatientLinkRequest.php`
- `app/Actions/PatientAccounts/PatientLinkIdentitySnapshot.php`
- `tests/Feature/Patients/ReviewPatientLinkRequestTest.php`
- `tests/Feature/AuditLogHardeningTest.php`

**Estimated scope:** M (4 files)

### Task 8A: Model and list the expired state

**Description:** Make auto-expiry explicit in domain helpers, factories, and
the Filament list surface.

**Acceptance criteria:**

- [x] Model/factory helpers recognize `expired`, and the staff table can filter
      and label it distinctly.
- [x] Existing pending/approved/rejected labels, counts, and filters do not
      regress.

**Verification:**

- [x] Affected Patient Link Request model and Filament feature tests pass.
- [x] Manual: an expired request is readable and clearly labelled in the list.
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 7

**Files likely touched:**

- `app/Models/PatientLinkRequest.php`
- `database/factories/PatientLinkRequestFactory.php`
- `app/Filament/Resources/PatientLinkRequests/Tables/PatientLinkRequestsTable.php`
- `tests/Feature/Filament/PatientLinkRequestReviewTest.php`

**Estimated scope:** M (4 files)

### Task 8B: Make expired request details safe and read-only

**Description:** Show PII-safe expiry provenance on the staff detail surface
and exclude terminal requests from all review actions.

**Acceptance criteria:**

- [x] Staff sees a safe expiry reason category or audit-backed explanation,
      while encrypted changed values remain protected.
- [x] Approve and reject actions are unavailable for expired requests.
- [x] Pending request review behavior and terminal approved/rejected details do
      not regress.

**Verification:**

- [x] `vendor/bin/sail artisan test --compact tests/Feature/Filament/PatientLinkRequestReviewTest.php`
- [x] Manual: an expired request is clearly explained and has no review action.
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 8A

**Files likely touched:**

- `app/Filament/Resources/PatientLinkRequests/Schemas/PatientLinkRequestForm.php`
- `app/Filament/Resources/PatientLinkRequests/Pages/ViewPatientLinkRequest.php`
- `tests/Feature/Filament/PatientLinkRequestReviewTest.php`

**Estimated scope:** S (3 files)

### Checkpoint B: Link lifecycle integrity

- [x] Tasks 5-8B focused suites pass.
- [x] Actual profile and candidate-relevant contact changes expire pending
      requests atomically; no-ops do not.
- [x] Historical evidence remains encrypted and immutable.
- [x] Stale/concurrent approval cannot link a Patient.
- [x] Expired requests are read-only and understandable to staff.
- [x] Audit hardening assertions find no raw PII or authentication secrets.
- [x] Pint is clean.

---

## Phase 3: Cleanup, Documentation, and Final Evidence

### Task 9: Remove unreachable direct-Patient profile code

**Description:** Delete only the confirmed-unrouted profile controller/request
surface after locking the negative route contract with tests.

**Acceptance criteria:**

- [x] `/api/v1/patient/profile` remains absent and authenticated callers cannot
      reach direct Patient demographic mutation.
- [x] The three legacy profile classes have no live references before deletion.
- [x] No supported account, contact, password, or Patient-link route regresses.

**Verification:**

- [x] Focused route/API contract tests pass.
- [x] `vendor/bin/sail artisan route:list --path=api/v1 --except-vendor`
- [x] Dead-reference `rg` checks return no legacy class or route reference.
- [x] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Checkpoint B

**Files likely touched:**

- `app/Http/Controllers/Api/PatientProfileController.php` (delete)
- `app/Http/Requests/Api/UpdatePatientProfileRequest.php` (delete)
- `app/Http/Requests/Api/UpdateProfileRequest.php` (delete)
- relevant route-contract/API test file

**Estimated scope:** S (4 files)

### Task 10: Reconcile canonical contract and Android handoff

**Description:** Document the shipped ownership boundary and exact client
behavior; do not edit the external Android repository under this task.

**Acceptance criteria:**

- [x] API contract lists the exact PATCH allowlist, normalization, conditional
      step-up header, `422` behavior, and complete response shape.
- [x] Backend context distinguishes account fields, verified contacts, and
      clinic-owned Patient fields and explains pending-request expiry.
- [x] Android handoff specifies editable/read-only sections, dedicated contact
      workflows, DOB step-up retry, and expired-link refresh behavior.

**Verification:**

- [x] Documentation examples match focused API test fixtures and route output.
- [x] `rg` finds no canonical statement that address, occupation, gender, or a
      Patient field is mobile-editable.

**Dependencies:** Task 9

**Files likely touched:**

- `docs/API_CONTRACT.md`
- `docs/BACKEND_CONTEXT.md`
- `docs/specs/patient-account-self-service-profile-spec.md` (implementation
  status/evidence only)

**Estimated scope:** S (3 files)

### Task 11: Complete regression and adversarial review

**Description:** Reconcile every approved success criterion with automated or
manual evidence and separate new failures from the known repository baseline.

**Acceptance criteria:**

- [x] All focused suites, route/dead-reference checks, and Pint pass.
- [x] Full-suite failures and errors are recorded; feature-specific suites have
      no new failures, and unrelated baseline issues are reported without
      deleting or weakening tests.
- [x] Security, API compatibility, transaction/locking, performance/query,
      documentation, and maintainability review finds no unresolved critical or
      high issue.

**Verification:**

- [x] Run every command in the plan's Verification Strategy.
- [x] Record focused/full results and any unchanged baseline failures in this
      checklist during implementation.
- [x] Confirm `git diff --check` and review the final scoped diff.

**Dependencies:** Task 10

**Files likely touched:**

- focused existing test files only if review finds a genuine missing assertion
- this checklist and plan for final status/evidence

**Estimated scope:** M (verification/review)

### Checkpoint C: Ready for human review

- [x] All thirteen work items and three checkpoints are complete.
- [x] Every specification success criterion has linked evidence.
- [x] No Patient/clinical write is reachable through account self-service.
- [x] Conditional step-up and stale-approval protections are demonstrated.
- [x] Canonical docs match runtime behavior and Android has an actionable
      handoff.
- [x] Focused tests pass, Pint is clean, and full-suite baseline is reported.

## Verification Evidence (2026-08-28)

- Profile and step-up coverage: `MeEndpointTest.php` 16/16 passed (164
  assertions); focused `step-up`, `validation`, and `update` filters passed
  3/3, 1/1, and 3/3 respectively.
- Contact and OTP coverage: `PatientContactTest.php` 7/7, `VerifyOtpChallengeTest.php`
  12/12, `PatientAccountContactTest.php` 16/16, and `AuditLogHardeningTest.php`
  2/2 passed. The contact suite includes failed-OTP attempt persistence and
  proves no account/link mutation on a wrong code.
- Link lifecycle and staff UI coverage: `SubmitPatientLinkRequestTest.php` 9/9,
  `ReviewPatientLinkRequestTest.php` 10/10, `PatientLinkRequestModelTest.php`
  11/11, and `Filament/PatientLinkRequestReviewTest.php` 5/5 passed.
- Auth and route compatibility: `AcceptPatientInvitationTest.php` 8/8,
  `AuthContractTest.php` 4/4, and `RouteContractTest.php` 6/6 passed.
- `vendor/bin/sail bin pint --dirty --format agent` and `git diff --check`
  passed. The API route list contains 59 routes and no
  `/api/v1/patient/profile` route; `app` and `routes` contain no references to
  the deleted legacy profile classes.
- Repository-wide `vendor/bin/sail artisan test --compact` ran 1,776 tests:
  1,752 passed, 14 failed, and 10 errored. The reported failures/errors are in
  unrelated appointment, catalog, commerce, and Filament areas. The recorded
  pre-feature baseline was 1,755 tests (1,734 passed, 14 failed, 7 errors);
  all 21 feature tests added by this work pass in focused runs, so no feature
  failure was concealed or attributed to the unrelated baseline.

## Implementation Approval Gate

- [x] Project owner explicitly approves this plan and checklist.
- [x] No application-code implementation begins before approval.
- [x] Approval covers only the tasks and boundaries above; deployment,
      database/dependency changes, and external Android changes remain separate
      decisions.
