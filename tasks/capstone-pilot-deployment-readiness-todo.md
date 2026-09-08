# Task Checklist: Capstone Pilot Deployment Readiness

**Status:** Scope amended on 2026-09-07 — Tasks 1–13, demo-only scope gate, and Task 15 complete; hosting and demo operating decisions recorded on 2026-09-08; external provisioning/rehearsal remains
**Specification:** `docs/specs/capstone-pilot-deployment-readiness-spec.md`
(approved 2026-09-06)
**Plan:** `tasks/capstone-pilot-deployment-readiness-plan.md` (approved 2026-09-06)

**Security amendment (2026-09-08):** The staff/admin Filament TOTP layer was
removed. The earlier Task 11 evidence mentioning MFA is historical; current
panel access is password-only with role, password-policy, and session controls.

Seventeen dependency-ordered tasks across five phases. Detailed acceptance
criteria, files, risks, and verification are in the plan.

## Execution Rules

- [x] Obtain owner approval of the plan before application code changes.
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

- [x] Launch-critical baseline is green.
- [x] Composer and npm high/critical gates pass.
- [x] Clean production build succeeds.
- [x] Runtime-aligned CI succeeds.

## Phase 1: Participant-Code Access

- [x] Task 5 — add fail-closed pilot configuration, eligibility table/model,
      factory, and User relation.
- [x] Task 6 — implement generic pilot authentication and pilot-bounded Sanctum
      token expiry.
- [x] Task 7 — add the dedicated participant-login request/controller/route and
      dual rate limiter.
- [x] Task 8 — make phone registration/login/OTP/recovery/invitation routes
      unavailable only in pilot mode.
- [x] Task 9 — provision exactly 75 minimal accounts and one-time private
      credentials.
- [x] Task 10 — add secure credential reset, single/bulk revocation, and token
      invalidation.

### Checkpoint B

- [x] Participant login contract, generic failures, throttling, audit, and
      expiry pass focused tests.
- [x] Pilot phone/OTP paths are absent while non-pilot paths remain green.
- [x] Provision/reset/revoke behavior is unique, private, redacted, and
      idempotent.

## Phase 2: Portable Pilot Environment

- [x] Task 11 — add a pilot-only safe seeder and secure MFA-admin bootstrap.
- [x] Task 12 — move message attachments to a configurable private logical
      disk.
- [x] Task 13 — add redacted production preflight and protected readiness.

### Checkpoint C

- [x] Fresh migration and pilot-safe seed pass with no known demo identity.
- [x] One existing validated/published AR model is selectable.
- [x] Focused/full Pest, Pint, build, audits, caches, routes, and CI pass.
- [x] Backend remains provider-neutral.

## Phase 3: External Launch Gates

- [x] Scope amendment — deploy staff-only/demo-only mode with no participant
      accounts or participant/phone/SMS authentication.
- [ ] Task 14 — provision the approved Laravel Cloud environment; record exact
      resource identifiers and complete the provider-specific runbook. The
      application-side Cloud object-storage mapping is implemented; external
      provisioning and rehearsal remain.
- [x] Task 15 — verify participant mode is omitted from the demo release and
      all participant/phone/SMS authentication paths remain unavailable.
- [ ] Task 16 — record the technical operator's name/contact and rehearse every
      deployed workflow and recovery procedure.

### Checkpoint D: Go/No-Go

- [ ] Hosting, synthetic demo data, owner, retention, and one AR model are
      recorded.
- [ ] The deployed immutable revision matches green CI/audit evidence.
- [ ] Staff/backend demo critical path and non-AR fallback pass on devices.
- [ ] Alerts, backups/restores, rollback, export, and teardown are rehearsed.
- [ ] The technical owner approves go-live.

## Phase 4: Operate and Close

- [ ] Task 17 — launch to authorized staff/evaluators, monitor for one month,
      export synthetic results if needed, revoke access, and complete teardown.

## Remaining Checkpoint D Inputs

- [x] Select Laravel Cloud Starter and approve a US$30 billing alert.
- [ ] Record the technical operator's name and contact channel.
- [x] Approve synthetic demo-data deletion after final defense plus seven days,
      never beyond October 7, 2026.
- [x] Select the published tortoise rectangle AR model, SKU
      `FRM-ANTHOS-MB1399A-C4`.
- [x] Map catalog, AR, and message-attachment disks to provider-neutral
      configuration and add the S3 filesystem adapter; retain local defaults
      for development and tests.
- [ ] Record the exact Cloud hostname/resource identifiers and complete the
      deployed backup, restore, alert, smoke, rollback, and teardown evidence.

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
- 2026-09-07 Task 6 authentication core: added a reusable pilot participant
  action that fails closed unless enablement and a parseable future expiry are
  both configured, normalizes participant codes, checks only eligible active
  patient-role accounts, and returns one generic validation error for unknown,
  expired, revoked, inactive, wrong-role, and wrong-password states. Unknown
  and malformed stored hashes use a fixed dummy hash without accepting the
  dummy password. Sanctum issuance now accepts an optional expiry cap while
  preserving existing phone-login behavior; pilot tokens are capped at the
  earliest normal token, account, and pilot expiry and retain installation
  binding/max-token handling. Five focused Pest tests passed (20 assertions),
  including code normalization, generic failures, disabled/malformed config,
  and both expiry bounds. Existing phone login, registration, and recovery
  suites also passed (23 tests, 102 assertions); Task 6 PHP files pass Pint.
- 2026-09-07 Task 7 participant-login boundary: added the named additive
  `POST /api/v1/auth/participant-login` route outside the email-keyed legacy
  login limiter. The request fails closed with `404` when pilot mode is
  disabled or its expiry is missing/invalid, rejects undocumented fields, and
  returns the shared direct-login token/resource shape when enabled. The
  limiter applies independent per-IP and SHA-256(normalized-code) thresholds;
  neither response nor rate-limit logs contain the participant code. Successful
  and known-account failed attempts write redacted participant authentication
  audit events without passwords, codes, device names, or installation IDs.
  Focused participant API/auth and existing patient authentication suites
  passed (35 tests, 174 assertions); Pint and PHP syntax checks passed. Route
  inventory confirms the named route and `throttle:participant-login`
  middleware.
- 2026-09-07 Task 8 pilot route gate: added a fail-closed middleware that
  returns `404` for registration, phone login/verification, recovery, and
  invitation OTP/acceptance operations only while `CAPSTONE_PILOT_ENABLED` is
  true. The middleware is prioritized before authentication so even
  unauthenticated invitation requests cannot observe a `401`; it never creates
  users, OTP challenges, invitations, messages, or audit entries. Non-pilot
  validation behavior and unrelated authenticated account routes remain
  available. Focused gate, participant-login, auth, invitation, route-contract,
  and regression suites passed (57 tests, 280 assertions); Pint and PHP syntax
  checks passed.
- 2026-09-07 Task 9 participant provisioning: added the
  `pilot:provision-participants` command and transactional action. The focused
  suite passed 5 tests with 1,235 assertions, and the related participant/auth
  regression suite passed 22 tests with 1,343 assertions. The default run
  creates exactly 75 active patient-role users with null personal/contact
  fields, no Patient or contact records, unique hashed passwords, no tokens,
  and a single private manifest on the configured disk. Reruns, public disks,
  disabled/expired pilot configuration, and manifest-write failures are
  rejected or rolled back; command output and audit metadata contain no
  participant codes or passwords. Pint passed for the Task 9 PHP files and
  tests. Task 9 is committed as `bd8b53ed`.
- 2026-09-07 Task 10 access lifecycle: added the
  `pilot:manage-access reset|revoke` command, private credential reset action,
  and single/bulk revocation action with dedicated audit events. The focused
  suite passed 7 tests with 73 assertions; the combined participant/auth
  regression suite passed 34 tests with 1,436 assertions, and the non-pilot
  authentication suite passed 43 tests with 182 assertions. Reset generates a
  replacement random password only in a new private file, invalidates all
  existing tokens, preserves the account expiry, and leaves no reset secrets
  in command output or audit metadata. Revocation deletes tokens immediately,
  works after pilot expiry/disablement, and repeated bulk teardown is
  idempotent. Commands require one explicit participant code or `--all` and
  require confirmation unless `--yes` is provided. Pint and PHP syntax checks
  passed. Task 10 is committed as `25811ee9`.
- 2026-09-07 Task 11 pilot-safe bootstrap: added the isolated
  `CapstonePilotSeeder`, which calls only approved reference/catalog seeders and
  the selected synthetic AR fixture; it never calls `DatabaseSeeder`, demo
  users, provider hours, or broad scenario coverage. The seeder is idempotent,
  requires a public HTTPS AR base URL, creates no patient/clinical scenario
  rows, and publishes one patient-ready model through the existing reviewed
  workflow using a passwordless synthetic staff actor. Added
  `pilot:provision-administrator`, which accepts a hidden or owner-only file
  password, creates or updates only an existing admin account, emits no
  credentials, preserves the then-current MFA enrollment, and proved that
  production panel MFA was required. Task 11 focused tests passed (7 tests, 62
  assertions), AR / MFA / participant regressions passed (26 tests, 1,360
  assertions), Pint and
  staged diff checks passed. Task 11 is committed as `9c97c07e`.
- 2026-09-07 Task 12 attachment storage: replaced every patient/API and
  Filament staff attachment upload, download, and preview use of the hard-coded
  `local` disk with the configured logical disk. The default
  `message_attachments` disk is explicitly private, writes force private
  visibility, and its local-volume root or S3-compatible driver is selected by
  environment configuration without domain-code changes. Storage tests cover
  upload placement, private visibility, non-default disk mapping, authorized
  patient/staff access, missing objects, and the existing route/resource
  contract. Focused conversation/UI/storage tests passed (54 tests, 145
  assertions); adjacent API/notification/migration/route tests passed (34
  tests, 156 assertions); Pint, config inspection, and optimize/clear passed.
  Task 12 is committed as `95f1fcc9`.
- 2026-09-07 Checkpoint B review: the participant-login contract, generic
  failure and dual-limit behavior, pilot route gate, provisioning, reset,
  revocation, token invalidation, and audit redaction all pass their focused
  suites. Existing phone registration/login/recovery/invitation and step-up
  authentication suites remain green outside pilot mode. Checkpoint B is
  complete; the later full-suite and dependency/build evidence closes
  Checkpoint A as well.
- 2026-09-07 Task 13 deployment preflight: added the provider-neutral
  `pilot:preflight` command, cached trusted-host/proxy configuration,
  explicit production CORS settings, and endpoint/timeout/retry controls for
  the supported SMS adapters. The preflight checks production/debug/HTTPS/key
  separation, the 75-participant bound and expiry, cookie/network boundaries,
  database/cache/queue/session/mail/logging/scheduler configuration, private
  and public logical disks, release/readiness/alert identifiers, policy URLs,
  route gating, and adapter settings. It reports stable check names and
  generic failure messages only. The protected
  `GET /internal/readiness` endpoint requires a dedicated header token and
  returns only `ready`/`unready`; `/up` remains the framework liveness route.
  Disabled SMS is accepted only with all phone/SMS routes gated. Focused
  preflight, route-gate, and SMS suites passed (47 tests, 145 assertions);
  the preflight-only suite passed 31 tests with 66 assertions. SMS disabled
  logs were also redacted and configured adapter endpoints/retries are now
  honored. Pint and route/schedule inspections passed. Task 13 is committed as
  `56c3a8d1`.
- 2026-09-07 contract and isolation checkpoints: aligned stale tests with the
  account-owned conversation, six-type appointment catalog, current Filament
  labels/assets, renamed quotation action, nullable conversation ownership,
  and required commerce item kind. Hardened Brand/ProductCategory fixtures and
  scoped catalog, AR, and inventory assertions against schema-test state
  leakage. Focused checkpoints passed (99 tests/568 assertions and 97
  tests/388 assertions); commits `5cc99d51`, `f64a568f`, and `aaef910e`.
- 2026-09-07 full regression verification: a clean Sail Pest run passed 2,067
  tests and 8,784 assertions in 12m 06s. Checkpoints A–C are complete. The
  remaining work is the demo-only scope gate and external launch gating:
  hosting, named technical owner, synthetic-data retention/deletion policy,
  and the selected AR model before Checkpoint D.
- 2026-09-07 demo-only scope gate: added `DEPLOYMENT_MODE=demo`, which fails
  closed unless `CAPSTONE_PILOT_ENABLED=false`, no participant accounts exist,
  participant login is unavailable, and every phone/SMS route remains gated.
  The focused preflight run passed 34 tests/72 assertions and the route/auth
  run passed 17 tests/130 assertions. Pint passed. Commit `dc37fbbc`.
- 2026-09-07 post-scope full regression: a clean Sail Pest run passed 2,072
  tests and 8,794 assertions. Composer validation/audit, npm audit, production
  build, optimize/cache rehearsal, readiness route, and scheduler inventory
  all passed.
- 2026-09-08 operational decisions: the team approved Laravel Cloud Starter in
  Asia Pacific (Singapore when available), a US$30 billing alert, the capstone
  technical lead as owner role, synthetic-data retention through final defense
  plus seven days (hard stop October 7, 2026), and the published tortoise
  rectangle AR asset `FRM-ANTHOS-MB1399A-C4`. ADR-006 and the provider-specific
  runbook profile record the decisions; the owner's name/contact and deployed
  evidence remain required before Checkpoint D.
- 2026-09-08 storage portability checkpoint: catalog image consumers now use a
  configurable public logical disk, AR quarantine/published disks use
  provider-configurable roots and visibility, and the S3 Flysystem adapter is
  committed for Cloud object storage. The catalog preflight resolves its
  configured alias and fails closed for missing/private disks. Focused storage,
  preflight, AR, catalog, and seeder tests passed (149 tests, 638 assertions);
  `composer validate --strict`, `composer audit --locked`, and Pint passed.
  A clean full Sail Pest run passed 2,133 tests and 9,172 assertions. The
  Cloud account, exact resources, owner identity, and deployed rehearsal are
  still external Checkpoint D inputs.
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
