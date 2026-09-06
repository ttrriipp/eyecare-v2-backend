# Implementation Plan: Capstone Pilot Deployment Readiness

**Status:** Approved on 2026-09-06 — implementation in progress
**Specification:** `docs/specs/capstone-pilot-deployment-readiness-spec.md`
(approved 2026-09-06)
**Decision:**
`docs/decisions/004-use-temporary-participant-code-authentication-for-capstone-pilot.md`
(accepted 2026-09-06)
**Checklist:** `tasks/capstone-pilot-deployment-readiness-todo.md`

## Outcome

Prepare an isolated, one-month capstone pilot for 75 invited participants,
targeting September 7 through October 7, 2026 in `Asia/Manila`. Participants
authenticate with pre-provisioned pseudonymous codes and unique passwords. The
existing phone/OTP system remains intact outside pilot mode but is unavailable
from the pilot participant path. The pilot may launch with one validated AR
model and uses synthetic clinical/demo records only.

This plan is provider-neutral through Checkpoint C. Hosting selection changes
environment configuration and the deployment runbook, not the participant
authentication domain. It is therefore safe to begin implementation before a
host is selected. Hosting, the study-data allowlist, named owners, and the
retention schedule must nevertheless be resolved before public launch.

The Android source is not in this repository. Backend completion alone cannot
make participant-code login usable; the external Android handoff in Task 15 is
on the launch critical path.

## Current Readiness Evidence

The planning audit on 2026-09-06 established:

- Sail services start and the focused patient-login, production-configuration,
  and canonical-seeder checks pass: 26 tests and 196 assertions.
- The last recorded full-suite result predates this plan and contains unrelated
  failures. Task 1 must capture a fresh baseline before implementation; no
  failure may be silently waived.
- Composer audit currently reports high-severity advisories affecting exposed
  packages, including the installed Filament, Guzzle, and CommonMark versions,
  plus medium findings including Livewire and Dompdf.
- npm audit currently reports five high/critical findings through the build
  toolchain, including Vite, PostCSS, NanoID, and `shell-quote`.
- CI uses PHP 8.4 and MySQL 8.0 while the intended runtime is PHP 8.5 and MySQL
  8.4; it does not currently enforce the production frontend build or security
  audits.
- Patient device tokens already support per-token expiry. Laravel Sanctum
  documents the third `createToken` argument for this purpose, so pilot tokens
  can be capped at the earlier of the normal expiry and the pilot end date.
- The existing login limiter keys its account limit with `email`, although the
  patient login accepts a contact value. Participant login needs its own
  enumeration-resistant IP and normalized-code limits.
- Message attachments use a hard-coded `local` disk in three controllers.
  Persistent storage therefore is not provider-pluggable yet.
- The default database seeder includes known demonstration accounts and broad
  scenario data. It is not an acceptable public-pilot bootstrap path.
- There is no secure, tested production administrator bootstrap command.

High or critical advisories affecting an exposed pilot path are release
blockers. A public launch on September 7 is conditional on upgrades, the
external Android change, provider provisioning, and all launch gates passing;
the date is a target, not permission to bypass a gate.

## Recommended Defaults for Open Decisions

| Decision | Recommended default | Latest point to decide |
| --- | --- | --- |
| Study dataset | Collect participant code, consent version/time, study task completion, usability answers/scores, timestamps, device/OS compatibility, and coarse error categories. Exclude names, phone/email, DOB, free-form clinical details, and message contents unless the approved instrument specifically requires them. | Before implementing or accepting any new data-collection field; always before Checkpoint D. |
| Hosting | Use a managed PHP/Laravel platform with a managed MySQL database, worker/scheduler support, HTTPS, logs, daily backups, and a nearby region. Compare Laravel Cloud and suitable managed alternatives only when the one-month budget is known; do not choose shared hosting that lacks workers, scheduler reliability, or controlled deployment. | Task 14, before any public environment is created. |
| Owners | Name one technical operator for deploys, alerts, rollback, and teardown, and one research-data approver for export/deletion. One person may hold both roles, but both responsibilities must be explicit. | Before Checkpoint D. |
| Retention | Export and verify de-identified results, then revoke participant access at pilot close. Delete credentials, code-to-person mapping (if any), direct identifiers, live database rows, files, and provider backups as soon as the consent/institutional rules allow. If no rule exists, obtain one rather than inventing a retention period in code. | Policy before Checkpoint D; provider-specific deletion timing in Task 14. |
| AR model | Select one already validated and published model and treat every other product/device as non-AR with a clear fallback. | Before deployed smoke testing in Task 16. |

If the approved study uses only fields already present, resolving the dataset
is an operational allowlist. If it requires new persisted fields or analytics
events, that is a specification change and must be planned before coding them.

## Architecture Decisions

### 1. Keep pilot eligibility separate from normal user identity

Add a `pilot_participant_accounts` table and model with a unique participant
code, one-to-one User relationship, expiry, and nullable revocation timestamp.
The User stores the hashed unique password and the normal patient role. No
phone, email, DOB, or linked Patient is created by default.

This makes pilot eligibility explicit and removable without overloading the
normal phone-based identity model. A Patient link is added only if a later
approved study workflow genuinely requires a clinical route; it must contain
synthetic data.

### 2. Add one dedicated, additive API operation

Add `POST /api/v1/auth/participant-login` with this contract:

```json
{
  "participant_code": "P001",
  "password": "participant-specific-secret",
  "device_name": "Android",
  "installation_id": "app-installation-identifier"
}
```

A successful response reuses the existing direct-login shape:

```json
{
  "data": {
    "step_up_required": false,
    "token": "sanctum-token",
    "user": {}
  }
}
```

Disabled pilot mode returns `404`. Unknown, expired, revoked, inactive,
wrong-role, and wrong-password accounts return the same generic `422` response.
Rate limiting returns `429`. The operation never creates an account, sends an
OTP, reveals account state, or weakens authorization on later routes.

### 3. Make time-boxing fail closed

Add `config/capstone_pilot.php` with disabled defaults, an explicit end time,
participant limit, and named private credential disk. Pilot availability is
the conjunction of enabled configuration and a current time before expiry.
Every pilot token expires at the earlier of the normal patient-token expiry or
the pilot end time. Revocation deletes outstanding tokens immediately.

### 4. Treat generated credentials as one-time secrets

A dedicated idempotent provisioning command creates exactly 75 accounts in a
transaction, refuses ambiguous reruns, and writes the code/password manifest
once to a private disk. It never prints passwords, writes them to logs, commits
them, or uses a shared password. Credential resets and revocation are explicit,
audited administrative commands.

### 5. Keep the deployment portable

Configuration names logical resources: database, cache, queue, session,
message attachments, AR quarantine, and published assets. Provider selection
maps those resources to managed services or persistent volumes. Application
actions and controllers do not import provider SDKs.

### 6. Use pilot-specific bootstrap and preflight

The public pilot never runs the default `DatabaseSeeder`. A dedicated seeder
loads only required reference data and explicitly selected synthetic demo data.
A secure command provisions the initial administrator without a known password.
A preflight command fails on unsafe production settings without printing secret
values.

## Threat Model

| Threat | Required control and proof |
| --- | --- |
| Participant-code enumeration or password guessing | Generic failures, code normalization, independent IP and hashed-code rate limits, and tests proving indistinguishable responses. |
| Credential disclosure | Random unique passwords, one-time private export, hashed storage, no console/log/audit secret values, and restricted file permissions. |
| Access after expiry or revocation | Server-side eligibility check at login, token expiry capped to pilot end, token deletion on revoke/teardown, and time-travel tests. |
| Reaching phone/OTP or registration paths during pilot | Pilot-mode middleware makes those public routes unavailable; Android exposes no registration/recovery UI. Existing paths remain covered outside pilot mode. |
| Privilege or clinical-data access | Patient role only, no Patient link by default, existing policies retained, endpoint access-matrix tests, and synthetic records only. |
| Seeded demo compromise | Separate pilot seeder, no known credentials, secure admin bootstrap, and a database assertion that prohibited seeded identities are absent. |
| Data/file loss on redeploy | Named persistent disks, daily encrypted backup, restore rehearsal, and persistence smoke test. |
| Secret or PII leakage in diagnostics | Existing audit redaction extended to pilot events; production exception/log checks contain only safe identifiers or hashes. |
| Unsafe or incomplete release | Runtime-aligned CI, deterministic installs, build/audit/test/cache gates, immutable release, health checks, and documented rollback. |

## Dependency Graph

```text
Approved spec + ADR
        |
        +--> Current baseline --> dependency remediation --> CI gate
        |
        +--> Pilot config/schema --> auth core --> API boundary
        |                                  |             |
        |                                  +--> phone-path gate
        |                                  +--> provision/reset/revoke
        |
        +--> Pilot-safe seed/admin --> storage portability --> preflight
                                                           |
Frozen study dataset + named owners ------------------------+
                                                           v
Hosting selection --> environment provisioning --> Android handoff
                                                \          /
                                                 v        v
                                         deployed rehearsal --> go-live
                                                                  |
                                                                  v
                                                         export + teardown
```

## Implementation Phases

### Phase 0: Establish a trustworthy release baseline

#### Task 1: Capture the current test and build baseline

**Description:** Record fresh evidence before behavior changes so new failures
can be distinguished from existing unrelated failures.

**Acceptance criteria:**

- Full Pest, frontend production build, Composer validation/audit, npm audit,
  migration status, schedule list, and route inventory are executed through
  Sail from the current lockfiles.
- Every failure is categorized as launch-critical, dependency-related, or
  unrelated, with no test deleted, skipped, or weakened.
- Files already modified by the user, especially scenario seeding and variant
  tests, are treated as protected unless the owner separately puts them in
  scope.

**Verification:** Save exact commands and outcomes in the task checklist; make
no application-code change.

**Dependencies:** Approved plan.

**Files likely touched:** `tasks/capstone-pilot-deployment-readiness-todo.md`
only for evidence updates.

**Estimated scope:** S (read-only application audit).

#### Task 2: Remediate exposed PHP dependency advisories

**Description:** Upgrade existing Composer packages in reviewable compatible
groups without adding dependencies or using blanket update commands.

**Acceptance criteria:**

- Filament, Guzzle, CommonMark, Livewire, Dompdf, and any transitive packages
  are moved to non-vulnerable versions compatible with Laravel 13 and PHP 8.5.
- `composer validate --strict`, focused framework/package tests, and the full
  suite expose no new regression relative to Task 1.
- `composer audit --locked` has no unmitigated high/critical finding affecting
  an exposed pilot path; any proposed exception returns to owner approval.

**Verification:** Sail Composer validate/audit, focused Filament/auth/file/PDF
tests, full Pest comparison, and Pint if PHP files change.

**Dependencies:** Task 1.

**Files likely touched:** `composer.json`, `composer.lock`.

**Estimated scope:** M (dependency-only change).

#### Task 3: Remediate frontend build-chain advisories

**Description:** Upgrade the existing npm dependency tree in reviewed groups,
without `--force`, and prove the production assets still build.

**Acceptance criteria:**

- Vite, PostCSS, NanoID, `shell-quote`, and affected parents resolve to safe,
  compatible versions.
- `npm ci` and `npm run build` succeed from the committed lockfile.
- `npm audit --audit-level=high` has no unmitigated exposed high/critical
  finding; build-only residual risk, if any, is explicitly reviewed.

**Verification:** Sail npm clean install, production build, audit, and focused
panel smoke tests.

**Dependencies:** Task 1; may proceed beside Task 2 because files do not
overlap.

**Files likely touched:** `package.json`, `package-lock.json`.

**Estimated scope:** M (dependency-only change).

#### Task 4: Align and strengthen CI

**Description:** Make CI reflect the deployed PHP/MySQL versions and enforce
the pilot release gates in deterministic order.

**Acceptance criteria:**

- CI uses PHP 8.5 and MySQL 8.4 and installs from Composer/npm lockfiles.
- It runs formatting/quality checks, Pest, the production frontend build,
  Composer audit, npm high-severity audit, fresh migrations, and Laravel/
  Filament cache construction.
- Failures stop the workflow and no secrets or generated credentials are
  printed or stored as artifacts.

**Verification:** Validate workflow syntax and obtain one green run after Tasks
2 and 3 land.

**Dependencies:** Tasks 2 and 3.

**Files likely touched:** `.github/workflows/ci.yml`.

**Estimated scope:** S (1 file).

#### Checkpoint A: Supply-chain and regression floor

- [ ] A fresh baseline is recorded and all pilot-critical failures are fixed.
- [ ] Composer and npm high/critical release gates pass.
- [ ] Production assets build from a clean install.
- [ ] Runtime-aligned CI is green.

### Phase 1: Participant-code access vertical slice

#### Task 5: Add fail-closed pilot configuration and eligibility schema

**Description:** Introduce the isolated pilot-account domain and its safe
configuration defaults.

**Acceptance criteria:**

- The table enforces one pilot account per User, unique participant codes,
  indexed expiry/revocation fields, and referential cleanup.
- The model exposes typed eligibility behavior and a User relationship without
  adding phone/email/DOB requirements.
- Missing, false, malformed, or expired configuration never enables pilot
  authentication.

**Verification:** New model/config tests, fresh migration, rollback/migrate
rehearsal, and schema assertions.

**Dependencies:** Checkpoint A.

**Files likely touched:**

- `config/capstone_pilot.php` (new)
- `database/migrations/*_create_pilot_participant_accounts_table.php` (new)
- `app/Models/PilotParticipantAccount.php` (new)
- `database/factories/PilotParticipantAccountFactory.php` (new)
- `app/Models/User.php`

**Estimated scope:** M (5 files).

#### Task 6: Implement pilot authentication and bounded token expiry

**Description:** Authenticate an eligible participant without OTP and issue a
normal patient token that cannot outlive the pilot.

**Acceptance criteria:**

- Only an active patient-role User with a valid, unrevoked, unexpired pilot
  account and matching password authenticates.
- Every invalid credential/account state produces one generic failure and no
  token, OTP, contact, or state disclosure.
- Existing patient tokens keep their current expiry; pilot-issued tokens use
  the earlier of that expiry and the configured pilot end.

**Verification:** Focused Pest action tests cover valid/invalid states, frozen
time at the boundary, token expiry, audit redaction, and unchanged normal login.

**Dependencies:** Task 5.

**Files likely touched:**

- `app/Actions/Auth/AuthenticatePilotParticipant.php` (new)
- `app/Actions/Auth/IssuePatientDeviceToken.php`
- `app/Enums/AuditEvent.php`
- `tests/Feature/Auth/AuthenticatePilotParticipantTest.php` (new)

**Estimated scope:** M (4 files).

#### Task 7: Expose the dedicated participant-login contract

**Description:** Add the validated HTTP boundary and a limiter keyed separately
by IP and a hash of normalized participant code.

**Acceptance criteria:**

- The route accepts only the documented fields and returns the existing direct
  token/User response shape on success.
- Disabled mode returns `404`; invalid credentials return generic `422`; both
  IP and normalized-code thresholds return `429` without exposing the code.
- The controller remains thin, uses the shared patient account resource
  loader, and never invokes OTP/contact workflows.

**Verification:** API feature tests cover response shape, validation, generic
failures, rate limiting, audit redaction, token use on an allowed API route,
and absence from the route contract when disabled.

**Dependencies:** Task 6.

**Files likely touched:**

- `app/Http/Requests/Api/PilotParticipantLoginRequest.php` (new)
- `app/Http/Controllers/Api/PilotParticipantLoginController.php` (new)
- `app/Providers/AppServiceProvider.php`
- `routes/api.php`
- `tests/Feature/Api/V1/PilotParticipantLoginTest.php` (new)

**Estimated scope:** M (5 files).

#### Task 8: Gate participant-facing phone account routes in pilot mode

**Description:** Make registration, phone login/verification, recovery, and
public invitation operations unavailable in the pilot environment without
deleting or weakening their non-pilot implementation.

**Acceptance criteria:**

- In pilot mode the identified public phone/OTP routes return `404` and do not
  create challenges, messages, accounts, invitations, or audit secrets.
- Outside pilot mode every existing route and test retains its current
  contract.
- Authenticated unrelated API routes are not accidentally hidden.

**Verification:** A route access-matrix Pest test in both configuration modes,
existing registration/login/recovery/invitation suites, and route inventory.

**Dependencies:** Task 7.

**Files likely touched:**

- `app/Http/Middleware/RejectPhoneAuthenticationDuringPilot.php` (new)
- `bootstrap/app.php`
- `routes/api.php`
- `tests/Feature/Api/V1/PilotAuthenticationRouteGateTest.php` (new)

**Estimated scope:** M (4 files).

#### Task 9: Provision participant accounts and one-time credentials

**Description:** Add an idempotent administrative workflow that creates the 75
pseudonymous patient-role accounts and a private, one-time credential manifest.

**Acceptance criteria:**

- A fresh pilot receives exactly 75 unique codes and unique random passwords;
  passwords are hashed and no contacts, DOB, Patient link, or real PII exists.
- Role assignment is compatible with both current role representations, and
  the provisioning workflow intentionally resolves the observer's
  `must_change_password` default for this no-recovery pilot.
- The command refuses unsafe reruns or overwrites, writes credentials only to
  the configured private disk, and never prints/logs secret values.

**Verification:** Command/action Pest tests use a fake private disk and assert
count, uniqueness, hashes, roles, minimal data, file visibility, output/log
redaction, transaction rollback, and rerun behavior.

**Dependencies:** Task 8.

**Files likely touched:**

- `app/Actions/Auth/ProvisionPilotParticipants.php` (new)
- `app/Console/Commands/ProvisionPilotParticipantsCommand.php` (new)
- `tests/Feature/Auth/ProvisionPilotParticipantsTest.php` (new)
- `.env.example`

**Estimated scope:** M (4 files).

#### Task 10: Add auditable reset, revocation, and expiry lifecycle

**Description:** Give the authorized operator safe recovery and teardown tools
without public password recovery.

**Acceptance criteria:**

- A reset replaces one participant password with a unique random value,
  revokes existing tokens, and writes the new credential only to a new private
  one-time file.
- Revocation marks the pilot account revoked and immediately deletes all its
  tokens; bulk expiry/revocation is idempotent for teardown.
- Commands require explicit participant/all targeting, confirmation or a safe
  noninteractive flag, and produce PII/secret-free audit/output.

**Verification:** Focused action/command tests cover reset, single revoke, bulk
teardown, repeated execution, token invalidation, and redaction.

**Dependencies:** Task 9.

**Files likely touched:**

- `app/Actions/Auth/ResetPilotParticipantCredential.php` (new)
- `app/Actions/Auth/RevokePilotParticipants.php` (new)
- `app/Console/Commands/ManagePilotParticipantAccessCommand.php` (new)
- `tests/Feature/Auth/ManagePilotParticipantAccessTest.php` (new)

**Estimated scope:** M (4 files).

#### Checkpoint B: Participant access contract

- [ ] Exactly 75 provisioned accounts can use only the additive login path.
- [ ] Generic failures, dual rate limits, audits, and token expiry are proven.
- [ ] Pilot phone/OTP/registration/recovery paths are unavailable.
- [ ] Existing phone authentication remains green outside pilot mode.
- [ ] Credentials can be privately issued/reset and access can be revoked.

### Phase 2: Portable, safe pilot environment

#### Task 11: Create pilot-safe data and administrator bootstrap

**Description:** Separate public-pilot bootstrap from broad development/demo
seeding and known credentials.

**Acceptance criteria:**

- A dedicated `CapstonePilotSeeder` invokes only approved reference data and
  explicitly synthetic demo data; it never invokes `DatabaseSeeder`, known
  demo users, or broad scenario coverage.
- A secure administrator command creates or updates the named administrator
  without a source-controlled/default password and leaves Filament MFA
  enrollment required before participant access.
- A clean database contains no prohibited known identity or credential and can
  identify one selected validated/published AR asset for smoke testing.

**Verification:** Fresh-database seeder and command tests, prohibited-data
assertions, idempotency, and one-model AR eligibility assertion.

**Dependencies:** Checkpoint B. Selection of the exact existing AR asset may
remain an environment input until Task 16.

**Files likely touched:**

- `database/seeders/CapstonePilotSeeder.php` (new)
- `app/Console/Commands/ProvisionPilotAdministratorCommand.php` (new)
- `tests/Feature/Seeders/CapstonePilotSeederTest.php` (new)
- `tests/Feature/Auth/ProvisionPilotAdministratorTest.php` (new)

**Estimated scope:** M (4 files). Existing user-edited scenario seeder/tests
remain untouched.

#### Task 12: Make message attachment storage provider-neutral

**Description:** Replace hard-coded disk selection with a named private message
attachment disk while preserving authorization and response behavior.

**Acceptance criteria:**

- Upload, API download, staff preview, and staff download use the same named
  configured disk.
- Missing/private/unauthorized files retain safe `404`/authorization behavior
  and no direct public URL is introduced.
- A disk mapping can switch between a persistent volume and S3-compatible
  storage using configuration only.

**Verification:** Storage fakes cover upload/download/preview authorization,
missing files, non-default disk selection, and persistence contract.

**Dependencies:** Checkpoint B; may proceed beside Task 11.

**Files likely touched:**

- `config/filesystems.php`
- `app/Http/Controllers/Api/ConversationController.php`
- `app/Http/Controllers/MessageAttachmentPreviewController.php`
- `app/Http/Controllers/MessageAttachmentDownloadController.php`
- relevant existing message-attachment feature test file

**Estimated scope:** M (5 files).

#### Task 13: Add production preflight and readiness evidence

**Description:** Fail deployment before exposure when pilot configuration or
required infrastructure is unsafe, and provide a non-sensitive readiness
signal for operators.

**Acceptance criteria:**

- Preflight validates production environment/debug/HTTPS, key and contact
  lookup separation, pilot dates/count, secure cookies/proxies/origins,
  database/cache/queue/session/storage, release identifier, and required
  policy URLs without printing their secret values.
- A protected/internal readiness check verifies only required dependencies;
  `/up` remains minimal and public.
- Disabled SMS is accepted only while every phone/SMS-dependent pilot route is
  gated; enabling it without valid reviewed configuration fails preflight.

**Verification:** Production-configuration and command feature tests cover each
failure category, a valid provider-neutral configuration, redaction, and
readiness authorization/failure behavior.

**Dependencies:** Tasks 11 and 12.

**Files likely touched:**

- `app/Console/Commands/PilotDeploymentPreflightCommand.php` (new)
- `app/Http/Controllers/PilotReadinessController.php` (new)
- `routes/web.php`
- `tests/Feature/Security/PilotDeploymentPreflightTest.php` (new)
- `.env.example`

**Estimated scope:** M (5 files).

#### Checkpoint C: Provider-neutral backend ready

- [ ] Pilot participant auth, lifecycle, bootstrap, storage, and preflight
      focused suites pass.
- [ ] Fresh migration and pilot seeding work without default demo/scenario
      accounts.
- [ ] One existing validated/published AR model can be selected.
- [ ] Full Pest, Pint, frontend build, audits, caches, route checks, and CI pass.
- [ ] No host or SMS provider SDK is embedded in domain/application code.

### Phase 3: Resolve external launch gates

#### Task 14: Select hosting and provision the isolated environment

**Description:** Compare suitable managed providers using the approved one-month
budget, record the decision, and map platform services to the provider-neutral
configuration.

**Acceptance criteria:**

- The owner approves provider, region, one-month cap, database/cache/queue/
  storage choices, backup/restore behavior, logs/alerts, rollback, and teardown
  costs before resources are created.
- An ADR and deployment runbook record exact build/release, scheduler, worker,
  HTTPS/domain, secrets, backup, restore, rollback, export, and destruction
  steps.
- The public environment is isolated, uses no local/dev secrets or data, and
  passes preflight before DNS or participant distribution.

**Verification:** Provider console/config review, clean deployment, health and
worker/scheduler checks, encrypted backup plus restore rehearsal, rollback
rehearsal, and cost/expiry alerts.

**Dependencies:** Checkpoint C and a hosting decision. This is the first task
blocked by the currently unresolved provider choice.

**Files likely touched:**

- `docs/decisions/005-select-capstone-pilot-hosting.md` (new)
- `docs/DEPLOYMENT.md`
- provider deployment configuration files only if the selected platform needs
  them and after approval

**Estimated scope:** M; external state changes require explicit authorization.

#### Task 15: Implement and verify the Android participant mode

**Description:** Apply the approved additive login contract in the separate
Android repository and remove phone/registration/recovery affordances only in
pilot builds.

**Acceptance criteria:**

- The pilot screen labels and validates an alphanumeric participant code,
  calls the dedicated endpoint, securely stores the returned Sanctum token,
  and reuses the existing authenticated navigation.
- Pilot builds hide phone registration, OTP, and recovery; non-pilot builds
  retain them.
- The client handles `404`, generic `422`, `429`, expiry/revocation, offline
  failure, nullable personal fields, and non-AR fallback without exposing
  account state.

**Verification:** Android unit/UI tests plus a release-build device smoke test
against the staging pilot environment.

**Dependencies:** Checkpoint B for the stable API contract and Task 14 for an
end-to-end environment. May begin against local/staging after Checkpoint B.

**Files likely touched:** External Android repository only; exact files must be
planned there. No backend file is modified by this task.

**Estimated scope:** M, separately authorized repository work.

#### Task 16: Freeze operations/data policy and rehearse the deployed pilot

**Description:** Resolve the remaining human decisions and prove the complete
enabled workflow before inviting participants.

**Acceptance criteria:**

- The approved study-field allowlist, consent version, technical owner,
  research-data approver, retention/deletion rule, and selected AR model are
  recorded and match the deployed behavior.
- Staff MFA, participant login, approved study flow, one AR model and fallback,
  file authorization/persistence, queue/scheduler work, uptime/error alerts,
  backup/restore, rollback, export, and teardown all pass on deployed staging.
- Pilot notices clearly say capstone prototype/not clinical service; no real
  patient or unapproved participant data is present.

**Verification:** Signed launch checklist with test evidence, controlled device
smoke test, restored-environment comparison, sample de-identified export, and
teardown rehearsal.

**Dependencies:** Tasks 14 and 15 plus resolved study/owner/retention decisions.

**Files likely touched:** Approved runbook and existing canonical contract/
context documents; application files only if a newly approved study field
requires a separately specified change.

**Estimated scope:** M (mostly operational verification).

#### Checkpoint D: Go/no-go

- [ ] All four formerly open operational decisions are recorded.
- [ ] The immutable deployed revision matches green CI and audit evidence.
- [ ] Android and backend pass the full participant/staff critical path.
- [ ] Backup/restore, rollback, alerts, export, and teardown are rehearsed.
- [ ] The technical and research-data owners explicitly approve go-live.

### Phase 4: Operate and close the one-month pilot

#### Task 17: Launch, monitor, export, and tear down

**Description:** Run the time-limited pilot under the approved operating and
data-handling procedure.

**Acceptance criteria:**

- Access is distributed only to the 75 invited participants; the operator
  monitors uptime, application errors, worker/scheduler health, backups, and
  cost during data gathering and demonstrations.
- The verified de-identified export is produced before closure; revocation is
  executed and tested at the approved end time unless an extension has already
  passed a new review.
- Public access, credentials, scheduled work, databases/files/backups, DNS, and
  billable resources are disabled or deleted according to the approved policy,
  with provider deletion state recorded.

**Verification:** Daily operational evidence during the pilot and a signed
closure record showing export verification, revocation, deletion/retention,
and billing shutdown.

**Dependencies:** Checkpoint D.

**Files likely touched:** Operational records/runbook only; production state
changes require explicit operator authorization.

**Estimated scope:** One month of bounded operations.

## Verification Commands

Exact focused test paths will be confirmed as tasks generate files. All local
commands run through Sail.

```bash
vendor/bin/sail composer validate --strict --no-interaction
vendor/bin/sail composer audit --locked --no-interaction
vendor/bin/sail npm ci
vendor/bin/sail npm run build
vendor/bin/sail npm audit --audit-level=high
vendor/bin/sail artisan test --compact tests/Feature/Auth
vendor/bin/sail artisan test --compact tests/Feature/Api/V1
vendor/bin/sail artisan test --compact tests/Feature/Security
vendor/bin/sail artisan test --compact tests/Feature/Seeders
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail artisan migrate:fresh --seed --seeder=CapstonePilotSeeder --no-interaction
vendor/bin/sail artisan route:list --path=api/v1 --except-vendor
vendor/bin/sail artisan schedule:list
vendor/bin/sail artisan optimize
vendor/bin/sail artisan icons:cache
vendor/bin/sail artisan optimize:clear
```

Migration-fresh commands must target an isolated disposable database only.
Public-environment commands, account provisioning, credential generation,
backups/restores, DNS, deployment, and teardown always require a resolved exact
environment and explicit operator authorization.

## Sequencing and Checkpoints

- Tasks 2 and 3 may run independently after the baseline; Task 4 waits for
  both lockfiles to settle.
- Tasks 5 through 10 are sequential vertical slices because schema, token,
  route, and credential lifecycle contracts build on one another.
- Tasks 11 and 12 may proceed independently after Checkpoint B; Task 13 joins
  them in preflight.
- No public environment is created before Checkpoint C and the Task 14 hosting
  approval.
- Android work may begin against the stable local API after Checkpoint B, but
  deployed end-to-end acceptance waits for Task 14.
- Checkpoint D is a hard go/no-go. A calendar deadline does not waive it.

## Rollback and Data Safety

- Dependency groups and each checkpoint land as separate reviewable changes so
  the last green revision remains identifiable.
- Before participant data exists, application rollback may revert code and
  schema together using the rehearsed clean bootstrap.
- After participant data exists, destructive migration rollback is prohibited;
  restore the prior application artifact only if schema-compatible, otherwise
  restore the last verified encrypted backup in an isolated recovery process.
- Participant provisioning is transactional and rerun-safe. Credential files
  are never regenerated over an existing manifest.
- Revocation is recoverable only by an explicit new credential issuance before
  teardown. Deletion of production data/backups follows the approved policy and
  provider procedure, never an unscoped shell command.

## Approval Gate

The specification, plan, checklist, and ADR are approved. Approval authorizes
local repository implementation and verification only. It does
not authorize package exceptions, a hosting purchase, public resource creation,
Android-repository changes, participant-data collection, production deployment,
credential distribution, or destructive teardown; those remain at their named
gates.
