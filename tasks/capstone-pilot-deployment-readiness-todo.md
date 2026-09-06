# Task Checklist: Capstone Pilot Deployment Readiness

**Status:** Proposed on 2026-09-06 — awaiting plan approval
**Specification:** `docs/specs/capstone-pilot-deployment-readiness-spec.md`
(approved 2026-09-06)
**Plan:** `tasks/capstone-pilot-deployment-readiness-plan.md` (proposed)

Seventeen dependency-ordered tasks across five phases. Detailed acceptance
criteria, files, risks, and verification are in the plan.

## Execution Rules

- [ ] Obtain owner approval of the plan before application code changes.
- [ ] Use Laravel Boost version-specific documentation before each framework
      change.
- [ ] Activate Laravel, Pest/TDD, API, security, CI, and launch skills when
      their tasks begin.
- [ ] Write or update a focused failing Pest test before behavior changes.
- [ ] Run every PHP, Artisan, Composer, and Node command through Sail.
- [ ] Run Pint after every PHP task and never delete or weaken a test.
- [ ] Preserve unrelated worktree changes, including current scenario seeder
      and variant test edits.
- [ ] Stop at each checkpoint and record exact evidence before continuing.
- [ ] Do not create public resources, incur cost, handle participant data, or
      run destructive production commands without their explicit approvals.

## Phase 0: Trustworthy Release Baseline

- [ ] Task 1 — capture fresh full test/build/audit/migration/schedule/route
      evidence and classify every failure.
- [ ] Task 2 — remediate exposed PHP dependency advisories in reviewed groups.
- [ ] Task 3 — remediate frontend build-chain advisories without force fixes.
- [ ] Task 4 — align CI to PHP 8.5/MySQL 8.4 and enforce test/build/audit/cache
      gates.

### Checkpoint A

- [ ] Launch-critical baseline is green.
- [ ] Composer and npm high/critical gates pass.
- [ ] Clean production build succeeds.
- [ ] Runtime-aligned CI succeeds.

## Phase 1: Participant-Code Access

- [ ] Task 5 — add fail-closed pilot configuration, eligibility table/model,
      factory, and User relation.
- [ ] Task 6 — implement generic pilot authentication and pilot-bounded Sanctum
      token expiry.
- [ ] Task 7 — add the dedicated participant-login request/controller/route and
      dual rate limiter.
- [ ] Task 8 — make phone registration/login/OTP/recovery/invitation routes
      unavailable only in pilot mode.
- [ ] Task 9 — provision exactly 75 minimal accounts and one-time private
      credentials.
- [ ] Task 10 — add secure credential reset, single/bulk revocation, and token
      invalidation.

### Checkpoint B

- [ ] Participant login contract, generic failures, throttling, audit, and
      expiry pass focused tests.
- [ ] Pilot phone/OTP paths are absent while non-pilot paths remain green.
- [ ] Provision/reset/revoke behavior is unique, private, redacted, and
      idempotent.

## Phase 2: Portable Pilot Environment

- [ ] Task 11 — add a pilot-only safe seeder and secure MFA-admin bootstrap.
- [ ] Task 12 — move message attachments to a configurable private logical
      disk.
- [ ] Task 13 — add redacted production preflight and protected readiness.

### Checkpoint C

- [ ] Fresh migration and pilot-safe seed pass with no known demo identity.
- [ ] One existing validated/published AR model is selectable.
- [ ] Focused/full Pest, Pint, build, audits, caches, routes, and CI pass.
- [ ] Backend remains provider-neutral.

## Phase 3: External Launch Gates

- [ ] Task 14 — select hosting after budget approval; record ADR/runbook and
      provision the isolated environment.
- [ ] Task 15 — implement and test participant mode in the external Android
      repository.
- [ ] Task 16 — freeze study fields/consent, owners, retention, and AR choice;
      rehearse every deployed workflow and recovery procedure.

### Checkpoint D: Go/No-Go

- [ ] Hosting, study data, owners, retention, and one AR model are recorded.
- [ ] The deployed immutable revision matches green CI/audit evidence.
- [ ] Android/backend critical path and non-AR fallback pass on devices.
- [ ] Alerts, backups/restores, rollback, export, and teardown are rehearsed.
- [ ] Technical and research-data owners approve go-live.

## Phase 4: Operate and Close

- [ ] Task 17 — launch to the 75 invitees, monitor for one month, export
      approved de-identified results, revoke access, and complete teardown.

## Decisions Still Required Before Checkpoint D

- [ ] Approve the exact study-field/event allowlist and consent version.
- [ ] Select a managed host and approve the one-month spending cap.
- [ ] Name the technical operator and research-data export approver.
- [ ] Approve deletion timing and de-identified-results retention.
- [ ] Select the single validated/published AR model used for the pilot.

## Evidence Log

- 2026-09-06 pre-plan focused baseline: 26 tests and 196 assertions passed for
  patient login, production configuration, and canonical seeding.
- 2026-09-06 Composer audit: release-blocking high advisories currently affect
  installed exposed packages; Task 2 is required.
- 2026-09-06 npm audit: five high/critical build-chain advisories; Task 3 is
  required.
- Fresh full-suite and clean-build evidence: pending Task 1.
