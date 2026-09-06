# Task Checklist: Capstone Pilot Deployment Readiness

**Status:** Plan approved on 2026-09-07 — Tasks 1–5 complete; Checkpoint A open
**Specification:** `docs/specs/capstone-pilot-deployment-readiness-spec.md`
(approved 2026-09-06)
**Plan:** `tasks/capstone-pilot-deployment-readiness-plan.md` (approved 2026-09-06)

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

- [x] Task 1 — capture fresh full test/build/audit/migration/schedule/route
      evidence and classify every failure (recorded below; application code
      untouched).
- [x] Task 2 — remediate exposed PHP dependency advisories in reviewed groups.
- [x] Task 3 — remediate frontend build-chain advisories without force fixes.
- [x] Task 4 — align CI to PHP 8.5/MySQL 8.4 and enforce test/build/audit/cache
      gates.

### Checkpoint A

- [ ] Launch-critical baseline is green.
- [ ] Composer and npm high/critical gates pass.
- [ ] Clean production build succeeds.
- [ ] Runtime-aligned CI succeeds.

## Phase 1: Participant-Code Access

- [x] Task 5 — add fail-closed pilot configuration, eligibility table/model,
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
- 2026-09-06 fresh full Pest baseline: 1,988 tests, 1,964 passed, 14 failed,
  10 errors, and 7,076 assertions in about 18 minutes. No pilot files were
  changed during the run.
- Full-suite failure classification: dependency/advisory blockers are recorded
  separately below; quotation-action/missing-class, commerce guard, duplicate
  Faker category, canonical conversation schema, and unlinked-conversation
  authorization failures are pre-existing non-pilot findings unless those
  workflows are included in the frozen study/demo path. Appointment and
  optical-order Filament rendering failures are conditional launch blockers:
  they must be fixed or explicitly excluded from the staff demonstration scope
  before Checkpoint D. No test was deleted, skipped, or weakened.
- 2026-09-06 Composer validation: passed with `composer validate --strict`.
- 2026-09-06 Composer audit: failed with 31 advisories across six packages,
  including high findings in Filament MFA, Guzzle, and CommonMark; Dompdf and
  Livewire also have findings. Task 2 is required before an exposed release.
- 2026-09-06 PHP dependency remediation: reviewed groups upgraded Dompdf to
  3.1.6, CommonMark to 2.10.0, Guzzle to 7.15.5, PSR-7 to 2.13.1, promises to
  2.5.3, Filament to 5.7.8, and Livewire to 4.4.3. The Filament upgrade also
  published its version-matched CSS, JavaScript, and Inter font assets.
  `composer validate --strict` passed, `composer audit --locked` reports no
  advisories, and focused patient-login/configuration tests passed (20 tests,
  63 assertions). The Filament suite passed 355 of 358 tests; the three
  remaining rendering assertions are the same pre-existing appointment,
  optical-order, and prescription findings recorded in the baseline and were
  not weakened or deleted. The production Vite build passed with Vite 8.0.14.
- 2026-09-06 npm baseline: `npm ci` completed; `npm run build` passed with Vite
  8.0.14; `npm audit --audit-level=high` failed with five findings (three high,
  two critical) in NanoID, PostCSS, `shell-quote`/concurrently, and Vite.
  Task 3 is required before an exposed release.
- 2026-09-06 frontend dependency remediation: upgraded the direct build-chain
  packages to `@tailwindcss/vite` 4.3.3, `concurrently` 9.2.4,
  `laravel-vite-plugin` 3.2.0, `tailwindcss` 4.3.3, and Vite 8.2.2. The
  resolved vulnerable paths now use `shell-quote` 1.9.0, PostCSS 8.5.28, and
  NanoID 3.3.18. `npm ci` completed, `npm audit --audit-level=high` reports
  zero vulnerabilities, and `npm run build` passed. No force audit fix was
  used; the build emits only the optional `fontaine` optimization notice.
- 2026-09-07 CI alignment: `.github/workflows/ci.yml` now uses PHP 8.5,
  MySQL 8.4, Node 24, deterministic Composer/npm installs, Composer and npm
  high-severity audits, Pint, the production frontend build, database
  migration, the full Pest suite, and the Laravel optimized-cache gate. Read
  permissions are limited to repository contents, and the MySQL health check
  is credentialed and retried. The workflow was syntax-checked locally.
  Running the same clean post-upgrade Pest suite after clearing local caches
  produced 1,988 tests: 1,968 passed, 13 failed, and 7 errors. The 20
  non-passing cases overlap the Task 1 baseline categories (conversation
  authorization, seeded appointment types, commerce guard/schema, three
  quotation action gaps, duplicate Faker categories, and conditional Filament
  rendering assertions); no dependency-introduced increase was observed.
  Pint passed after four pre-existing formatting violations were corrected,
  the focused touched-test suites passed (14 tests, 40 assertions), and
  `artisan optimize` passed. Checkpoint A remains open until the known
  launch-critical tests are fixed or explicitly removed from the demo scope.
- 2026-09-07 Checkpoint A review: correctness, security, maintainability,
  performance, and testability review found no regression in the dependency or
  CI changes. Composer and npm audits are clean, the production build and
  Pint pass, and the clean full-suite delta is better than the recorded
  baseline (20 versus 24 non-passing cases). The full-suite gate remains
  intentionally red because the documented pre-existing failures have not
  been silently waived; appointment/optical-order rendering cases remain
  conditional staff-demo blockers, while the other gaps remain outside the
  participant pilot path unless the frozen study uses those workflows.
- 2026-09-07 Task 5 foundation: added the disabled-by-default
  capstone_pilot configuration with an explicit expiry requirement, a
  75-account provisioning limit, and a private credential-disk default. Added
  the pilot_participant_accounts table with unique participant code and user
  foreign key, expiry/revocation timestamps, and supporting indexes; schema
  inspection confirmed the user index is unique and cascades on deletion. The
  model exposes typed relationships, datetime casts, and an eligibleAt() scope
  requiring an active patient role before expiry. The default factory creates a
  pseudonymous patient-role user without a Patient record. Six focused Pest
  tests passed (13 assertions), including duplicate-code and duplicate-user
  constraints, and the Task 5 PHP files pass Pint.
- 2026-09-06 migration status: every listed migration ran successfully;
  fresh-install rehearsal remains a later checkpoint task.
- 2026-09-06 scheduler inventory: five scheduled commands are registered,
  including per-minute SMS processing and appointment expiry; SMS remains
  disabled/gated for the pilot unless separately approved.
- 2026-09-06 route inventory: 59 non-vendor `api/v1` routes; the dedicated
  participant-login route does not yet exist, as expected before Phase 1.
- 2026-09-06 Laravel/Filament cache rehearsal: `artisan optimize` passed;
  caches were cleared afterward with `artisan optimize:clear`.
- Protected worktree edits remain unstaged in the four user-modified files:
  `VariantsRelationManager.php`, `ScenarioCoverageSeeder.php`,
  `VariantFormVisibilityTest.php`, and `CanonicalSeederTest.php`.
