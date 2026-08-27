# Task Checklist: Patient Account Self-Service Profile Editing

**Status:** Awaiting project-owner approval
**Specification:** `docs/specs/patient-account-self-service-profile-spec.md`
(approved 2026-08-28)
**Plan:** `tasks/patient-account-self-service-profile-plan.md` (awaiting
approval)

Thirteen dependency-ordered work items across three phases. No implementation task is
authorized until this checklist and its plan receive explicit approval.

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

- [ ] Name-only PATCH `/me` reaches validation without a step-up header; any
      payload containing `date_of_birth` requires the existing header/token.
- [ ] Missing, invalid, expired, and another account's token receive the
      existing failure contract before mutation.
- [ ] Password and contact routes already using this middleware remain
      unconditionally step-up-protected.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/MeEndpointTest.php --filter=step-up`
- [ ] Existing focused password/contact step-up tests pass.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

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

- [ ] First/last names are trimmed and nonblank, middle name is trimmed or
      normalized to `null`, and DOB is a non-null exact `Y-m-d` date before
      today.
- [ ] A request requires at least one allowed field and rejects every unknown
      or prohibited top-level key with field-level `422` errors.
- [ ] Mixed valid/unsupported payloads fail atomically rather than accepting a
      validated subset.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/MeEndpointTest.php --filter=validation`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

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

- [ ] Actual allowed changes update the authenticated `users` row and never a
      `patients` row; normalized no-ops perform no expiry or change audit.
- [ ] Account update, at-most-one pending-request expiry, and audit records
      commit or roll back together under documented locks.
- [ ] Audit metadata contains only changed field names, safe reason/outcome,
      and non-PII identifiers already permitted by the audit policy.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/MeEndpointTest.php --filter=update`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/AuditLogHardeningTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

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

- [ ] One reusable loader includes role, verified contacts, linked Patient,
      and pending link-request state without loading unrelated history.
- [ ] GET and PATCH `/me` return identical shape and accurate `link_status` for
      unlinked, pending-review, and linked accounts.
- [ ] Core register/login/recovery responses retain their existing contract and
      use the same loader where they return `PatientAccountResource`.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/MeEndpointTest.php`
- [ ] Affected AuthController feature tests pass.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

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

- [ ] OTP challenge and invitation flows use the shared loader.
- [ ] Their status codes, tokens, verification behavior, and response keys do
      not otherwise change.
- [ ] A dead-reference search finds no direct incomplete resource construction
      at live call sites.

**Verification:**

- [ ] Affected OTP challenge and patient invitation feature tests pass.
- [ ] `rg -n "new PatientAccountResource|PatientAccountResource::make" app/Http/Controllers`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 4A

**Files likely touched:**

- `app/Http/Controllers/Api/OtpChallengeController.php`
- `app/Http/Controllers/Api/PatientInvitationController.php`
- relevant existing OTP/invitation feature test files

**Estimated scope:** S (up to 4 files)

### Checkpoint A: Account profile contract

- [ ] Tasks 1-4B focused suites pass.
- [ ] Linked and unlinked accounts can update allowed account fields.
- [ ] DOB requires valid same-account step-up; names do not.
- [ ] Unsupported/mixed payloads produce `422` and zero writes.
- [ ] Patient data is byte-for-byte unchanged after profile updates.
- [ ] GET and PATCH `/me` return accurate, equivalent resource shapes.
- [ ] Existing contact/password contracts remain green and Pint is clean.

---

## Phase 2: Link-Request Integrity

### Task 5: Version the identity snapshot shape with middle name

**Description:** Centralize normalized snapshot construction/comparison and
use it for new patient-link submissions without breaking historical snapshots.

**Acceptance criteria:**

- [ ] New encrypted snapshots contain normalized first, middle, last, and DOB
      values; candidate ranking remains based on account/verified contacts.
- [ ] Comparison treats a missing historical `middle_name` key as `null` and
      never logs decrypted snapshot values.
- [ ] Submission tests prove immutable snapshot/candidate behavior.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Patients/SubmitPatientLinkRequestTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

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

- [ ] Adding/replacing a verified email or phone value and removing a verified
      contact expire a pending request atomically.
- [ ] Failed verification, identical verified values, unverified removal, and
      primary-only changes do not expire a request.
- [ ] Contact endpoint contracts remain unchanged and audit metadata contains
      no contact value, OTP, or token.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/PatientContactTest.php`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/AuditLogHardeningTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

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

- [ ] Approval locks User, pending request, then target Patient consistently
      and fails closed if status, snapshot, account link, or target eligibility
      changed.
- [ ] A stale request commits `expired` plus a safe audit outcome before a
      validation failure is surfaced; it never sets `patients.user_id`.
- [ ] Repeat/concurrency-oriented tests prove at most one terminal outcome and
      no link from stale identity.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Patients/ReviewPatientLinkRequestTest.php`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/AuditLogHardeningTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

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

- [ ] Model/factory helpers recognize `expired`, and the staff table can filter
      and label it distinctly.
- [ ] Existing pending/approved/rejected labels, counts, and filters do not
      regress.

**Verification:**

- [ ] Affected Patient Link Request model and Filament feature tests pass.
- [ ] Manual: an expired request is readable and clearly labelled in the list.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

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

- [ ] Staff sees a safe expiry reason category or audit-backed explanation,
      while encrypted changed values remain protected.
- [ ] Approve and reject actions are unavailable for expired requests.
- [ ] Pending request review behavior and terminal approved/rejected details do
      not regress.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/PatientLinkRequestReviewTest.php`
- [ ] Manual: an expired request is clearly explained and has no review action.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 8A

**Files likely touched:**

- `app/Filament/Resources/PatientLinkRequests/Schemas/PatientLinkRequestForm.php`
- `app/Filament/Resources/PatientLinkRequests/Pages/ViewPatientLinkRequest.php`
- `tests/Feature/Filament/PatientLinkRequestReviewTest.php`

**Estimated scope:** S (3 files)

### Checkpoint B: Link lifecycle integrity

- [ ] Tasks 5-8B focused suites pass.
- [ ] Actual profile and candidate-relevant contact changes expire pending
      requests atomically; no-ops do not.
- [ ] Historical evidence remains encrypted and immutable.
- [ ] Stale/concurrent approval cannot link a Patient.
- [ ] Expired requests are read-only and understandable to staff.
- [ ] Audit hardening assertions find no raw PII or authentication secrets.
- [ ] Pint is clean.

---

## Phase 3: Cleanup, Documentation, and Final Evidence

### Task 9: Remove unreachable direct-Patient profile code

**Description:** Delete only the confirmed-unrouted profile controller/request
surface after locking the negative route contract with tests.

**Acceptance criteria:**

- [ ] `/api/v1/patient/profile` remains absent and authenticated callers cannot
      reach direct Patient demographic mutation.
- [ ] The three legacy profile classes have no live references before deletion.
- [ ] No supported account, contact, password, or Patient-link route regresses.

**Verification:**

- [ ] Focused route/API contract tests pass.
- [ ] `vendor/bin/sail artisan route:list --path=api/v1 --except-vendor`
- [ ] Dead-reference `rg` checks return no legacy class or route reference.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

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

- [ ] API contract lists the exact PATCH allowlist, normalization, conditional
      step-up header, `422` behavior, and complete response shape.
- [ ] Backend context distinguishes account fields, verified contacts, and
      clinic-owned Patient fields and explains pending-request expiry.
- [ ] Android handoff specifies editable/read-only sections, dedicated contact
      workflows, DOB step-up retry, and expired-link refresh behavior.

**Verification:**

- [ ] Documentation examples match focused API test fixtures and route output.
- [ ] `rg` finds no canonical statement that address, occupation, gender, or a
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

- [ ] All focused suites, route/dead-reference checks, and Pint pass.
- [ ] Full-suite failures are absent or proven unchanged from the recorded
      unrelated baseline; no test is deleted or weakened.
- [ ] Security, API compatibility, transaction/locking, performance/query,
      documentation, and maintainability review finds no unresolved critical or
      high issue.

**Verification:**

- [ ] Run every command in the plan's Verification Strategy.
- [ ] Record focused/full results and any unchanged baseline failures in this
      checklist during implementation.
- [ ] Confirm `git diff --check` and review the final scoped diff.

**Dependencies:** Task 10

**Files likely touched:**

- focused existing test files only if review finds a genuine missing assertion
- this checklist and plan for final status/evidence

**Estimated scope:** M (verification/review)

### Checkpoint C: Ready for human review

- [ ] All thirteen work items and three checkpoints are complete.
- [ ] Every specification success criterion has linked evidence.
- [ ] No Patient/clinical write is reachable through account self-service.
- [ ] Conditional step-up and stale-approval protections are demonstrated.
- [ ] Canonical docs match runtime behavior and Android has an actionable
      handoff.
- [ ] Focused tests pass, Pint is clean, and full-suite baseline is reported.

## Implementation Approval Gate

- [ ] Project owner explicitly approves this plan and checklist.
- [ ] No application-code implementation begins before approval.
- [ ] Approval covers only the tasks and boundaries above; deployment,
      database/dependency changes, and external Android changes remain separate
      decisions.
