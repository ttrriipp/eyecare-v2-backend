# Spec: Capstone Pilot Deployment Readiness

**Status:** Scope amended on 2026-09-07 — staff/demo-only deployment; participant access remains dormant
**Implementation plan:** `tasks/capstone-pilot-deployment-readiness-plan.md` (approved 2026-09-06)
**Applies to:** EyeCare backend, Filament staff panel, and Android-facing API
**Runtime baseline:** PHP 8.5, Laravel 13, MySQL, Filament 5, Livewire 4, Sanctum 4, Pest 4, and Tailwind CSS 4
**Deployment classification:** Time-limited capstone staff demonstration; no participant use or research-data collection
**Target availability:** September 7, 2026 through October 7, 2026 (`Asia/Manila`)

## Objective

Prepare the application for a one-month, internet-accessible capstone demonstration used by the capstone team and evaluators during demonstrations and final defense. The deployment must be safe for staff-only use while avoiding the cost and operational scope of a permanent clinical production service.

The release must support:

- the capstone team using the Filament panel to administer demonstrations with synthetic data;
- evaluators observing the system during demonstrations and final defense;
- a technical owner deploying, monitoring, exporting, and removing the environment.

Demo readiness is achieved when the reduced launch gates and success criteria in this specification are satisfied. This approval must not be represented as authorization for real clinical operations.

## Assumptions Requiring Confirmation

1. There is no live clinical database or production file store to migrate.
2. No participants will use this deployment and no participant or research data will be collected. Any later participant study requires a new scope and privacy review.
3. Synthetic data is used for clinical records, prescriptions, billing, and demonstrations. No real diagnosis or treatment depends on the application.
4. The Filament panel and team-controlled demonstration workflows are in launch scope; Android participant workflows are out of scope.
5. One validated AR frame model is sufficient; AR is presented as a limited pilot and unavailable products degrade gracefully.
6. The participant-code implementation may remain in the repository as an additive capability, but no participant accounts are provisioned and the capability stays disabled.
7. Demo-only mode disables participant-code, phone registration/login, OTP, recovery, and invitation paths.
8. The hosting provider remains undecided and will be selected for a one-month deployment rather than permanent clinical operations.
9. The capstone team and evaluators are in the Philippines, with business-time interpretation based on `Asia/Manila`.
10. The environment is taken offline on October 7, 2026 unless the owner explicitly approves an extension and repeats the privacy/security review.

If assumption 1, 2, or 3 is false, implementation must stop before deployment. A separate participant-data, clinical-production, or real-patient-data review will be required.

## Scope

### Included

- an explicit demo-only deployment gate that prevents participant and phone/SMS authentication;
- provider-neutral SMS and hosting boundaries;
- secure pilot configuration and secret validation;
- dependency and supply-chain remediation;
- recovery of launch-critical automated tests and resolution of failures that affect exposed demo workflows;
- safe reference/demo-data seeding and secure administrator provisioning;
- a fresh-install migration rehearsal;
- configurable persistent storage, queues, cache, scheduling, and worker supervision;
- basic liveness, error logging, uptime monitoring, daily backups, export, rollback, and teardown;
- a concise demonstration deployment and teardown runbook after the provider is selected.

### Excluded

- new clinic business features or a redesign of existing API contracts;
- Android participant-mode UI or participant-facing workflow work;
- participant-code account provisioning, participant data collection, consent handling, or research exports;
- purchasing or contracting with a hosting or SMS provider;
- real clinical use, medical decision-making, diagnosis, treatment, or permanent patient recordkeeping;
- collecting personal or sensitive data not explicitly required by the approved study instrument;
- multi-region active-active infrastructure;
- formal legal, privacy, or regulatory certification;
- implementing multiple live SMS providers before launch;
- importing legacy clinic data;
- enterprise availability, disaster-recovery, scaling, or long-term operations guarantees.

## Architecture and Release Invariants

1. Application workflows depend on internal contracts, not provider SDKs. Provider-specific code is confined to adapters and configuration.
2. An SMS operation must never report success when delivery was skipped, disabled, rejected, or not accepted by the provider.
3. Secrets, access tokens, OTP values, full SMS bodies, and unnecessary participant-identifying data must not be written to logs.
4. OTP values must not be stored as plaintext in database records or serialized queue payloads. Queued sensitive jobs must be encrypted or use an equivalently reviewed mechanism.
5. Required demonstration assets and any approved operational records must use persistent storage and survive application rebuilds and restarts during the demonstration.
6. Rate limits, distributed locks, scheduler locks, and job deduplication must use a shared cache when more than one application instance can run.
7. The demo is deployed from an identifiable revision that passed every required demo quality gate.
8. The fresh-install migration is rehearsed before launch; no legacy clinic database is imported.
9. A provider selection may add configuration and an adapter, but must not require rewriting authentication, invitation, storage, queue, or deployment workflows.
10. The deployed environment is explicitly marked demo-only, contains no pilot participant accounts, and does not expose participant or phone/SMS authentication.

## SMS and Participant Authentication Boundary

SMS and participant authentication are not enabled in the demo-only deployment. Existing phone-registration, phone-login-on-new-installation, recovery, step-up, and invitation paths must remain unavailable. The adapter and participant-code implementation remain provider-neutral and dormant for any separately approved future pilot.

### Functional behavior

- If phone authentication or invitations are enabled, all OTP and patient-invitation messages are sent through the application `SmsGateway` contract.
- Jobs are dispatched only after the database transaction that created the challenge or invitation has committed.
- The gateway returns a structured result that distinguishes provider acceptance from failure and can retain a provider message identifier without exposing message contents.
- A missing, disabled, or invalid production SMS configuration fails closed and produces an actionable operational error.
- Transient failures use bounded retries with backoff and jitter. Permanent failures are not retried indefinitely.
- Every outbound HTTP request has explicit connection and response timeouts.
- Jobs are idempotent or atomically claimed so a retry, overlapping scheduler run, or worker restart cannot create an unintended duplicate message.
- Failed jobs update the application record consistently and surface enough non-sensitive context for diagnosis.
- Delivery receipts are recorded when supported by the selected provider. Provider acceptance must not be labeled as handset delivery.
- Development and automated tests use a fake adapter. Pre-launch verification includes a controlled real-provider smoke test to team-owned Philippine numbers.
- Phone-based registration, recovery, step-up, and invitation paths are disabled for the demo. A fake OTP or public bypass is never deployed.

### Provider selection criteria

The SMS decision must compare at least:

- delivery reliability and supported Philippine networks;
- sender-name or sender-number requirements;
- authentication and secret-rotation support;
- delivery receipts and status webhooks;
- idempotency support, rate limits, retry guidance, and documented error categories;
- sandbox or test-mode support;
- data handling, retention, privacy terms, and available agreements;
- pricing, account funding, operational support, and incident history.

The selected provider and fallback policy must be recorded in an ADR before provider-specific implementation begins.

## Dormant Participant-Code Authentication Contract

The participant-code implementation is retained as an additive, tested
capability for a possible future study. It is not part of this deployment and
must remain disabled by configuration; no participant accounts or credentials
are created.

- The Android pilot login accepts a participant identifier and password instead of applying phone-number validation.
- The backend exposes a dedicated participant-login operation rather than weakening the existing patient phone/OTP operation.
- Only accounts explicitly provisioned for the capstone pilot can use the participant-login operation.
- No participant accounts are created for the demo-only deployment. If a future study is approved, its participant count and credential procedure must be specified separately.
- Participant identifiers contain no encoded name, contact detail, birthdate, or clinical fact.
- Successful login returns the same scoped Sanctum token used by existing Android API authorization. It does not bypass policies or role checks after authentication.
- The operation is enabled only by an explicit pilot configuration value that defaults to disabled and fails closed when the pilot expiry is reached.
- Participant login is rate-limited and audited without logging passwords or tokens.
- Public participant registration, OTP, and automated password recovery are unavailable in pilot mode. The authorized research administrator performs an auditable credential reset when needed.
- Any dormant pilot tokens/accounts must remain absent or disabled. The temporary route and Android login mode must remain unavailable before any later clinical deployment.

## Hosting Contract

The selected platform must provide or integrate with:

- PHP 8.5 and a supported MySQL version compatible with the tested production image;
- HTTPS termination, certificate renewal, trusted-proxy configuration, and correct secure URL generation;
- independently supervised web and queue-worker processes;
- a reliable once-per-minute Laravel scheduler trigger;
- deploy hooks that run preflight checks, migrations, cache construction, and graceful worker restarts in the approved order;
- shared cache and queue services when the application can scale beyond one instance;
- persistent private storage and public/object storage appropriate to each logical application disk;
- encrypted secrets separated from local development values;
- accessible error logs, uptime checks, daily database backups, file backups or object versioning, and export tooling;
- a region and network path with acceptable latency for Philippine users;
- identifiable releases, a supported application rollback mechanism, and a confirmed teardown process.

For this one-month demonstration, prefer a managed, usage-based platform over a self-managed VPS. The hosting decision must compare runtime support, worker and scheduler support, region and data handling, deployment and rollback behavior, backups, teardown/export behavior, operational burden, and one-month total cost. Record the selection before creating the public environment.

## Demo Deployment Configuration Contract

Demo startup or deployment preflight must fail when required settings are missing or unsafe. Validation must cover at least:

- `APP_ENV=production`, `APP_DEBUG=false`, an HTTPS application URL, and a stable production `APP_KEY`;
- `DEPLOYMENT_MODE=demo` and `CAPSTONE_PILOT_ENABLED=false`;
- secure cookies, trusted hosts/proxies, allowed frontend origins, and production CORS behavior;
- database, cache, queue, session, mail, and scheduler settings required by enabled demo workflows;
- SMS adapter selection, endpoint, credentials, sender identity, timeout, retry, and webhook secret where applicable;
- logical filesystem disks for private attachments, AR quarantine, and published/catalog assets;
- logging destination, uptime alert destination, release identifier, and demo teardown date;
- real privacy-policy and terms URLs where exposed to clients;
- a dedicated, stable `CONTACT_LOOKUP_KEY` that is not the application key;
- previous encryption keys when an approved key rotation requires them.

The demo preflight must also verify that no pilot participant accounts are
present and that every participant/phone/SMS route is unavailable.

Secrets must exist only in secret storage or local ignored environment files. They must not appear in source control, build artifacts, health responses, logs, exception messages, or CI output.

## Data, Migrations, and Bootstrap

- The demo uses explicit, idempotent seeders for required reference data and clearly marked synthetic demonstration data.
- Seeded identities must be fictional and must not reuse real patient information.
- Demonstration credentials are shared only with the capstone team/evaluators and are disabled at teardown.
- The administrator is provisioned through a secure command or platform mechanism and uses MFA.
- A fresh database must migrate, seed approved data, and provision an administrator without manual database editing.
- No legacy clinic database or frame-reservation data is imported into the pilot.
- A database backup is taken before any post-launch migration.
- No participant identity, consent record, participant-to-code mapping, or research event is created by the demo bootstrap.

## Filesystem and Asset Storage

- Application code targets named logical disks rather than hard-coded local paths.
- Private message attachments remain private and are served only after authorization.
- AR quarantine assets remain isolated from published assets.
- Published catalog and AR assets have explicitly configured visibility and URL behavior.
- The selected hosting adapter may use a persistent volume or an S3-compatible service without changing domain workflows.
- Upload size, MIME type, content validation, retention, deletion, and orphan cleanup rules are tested.

## Dependency and Supply-Chain Gates

- Composer and npm lockfiles are committed and used for deterministic installation.
- The deployed Composer installation excludes development dependencies and optimizes autoloading.
- No critical or high-severity Composer or npm advisory affecting an internet-exposed pilot path remains unmitigated at release time. Any exception requires evidence that the affected path is unreachable plus an expiry no later than pilot teardown.
- Medium-severity findings are reviewed and recorded before launch.
- Dependency upgrades are applied in reviewable groups and verified by focused tests followed by the full suite.
- Automated force-fix commands must not make unreviewed dependency changes.

## CI/CD Contract

CI must use the deployed PHP and database major/minor versions and enforce, in order:

1. deterministic dependency installation;
2. configuration and lockfile validation;
3. PHP formatting and static quality gates already adopted by the project;
4. focused launch-critical Pest tests plus a recorded full-suite run, with explicit review of any unrelated failure proposed for pilot-only deferral;
5. frontend production build;
6. Composer and npm security audits;
7. fresh migration and deployment-cache construction rehearsal.

Deployments must:

- originate from a clean, immutable revision with required approvals;
- prevent concurrent pilot deployments;
- retain the prior deployable application artifact;
- execute preflight before making the release live;
- place the application in maintenance mode only when the migration strategy requires it;
- restart long-running workers gracefully after the new release and configuration are active;
- run post-deploy health and critical-path smoke checks;
- automatically halt promotion on failure and follow the documented recovery path.

## Health, Observability, and Operations

- `/up` remains a minimal liveness endpoint and does not disclose infrastructure or secret details.
- A protected or internally consumed readiness check covers the database and any queue, cache, storage, worker, or scheduler required by enabled demo workflows without exposing credentials or participant data.
- Logs include a release identifier and redact secrets, OTPs, authorization headers, SMS content, and unnecessary participant identifiers.
- At minimum, uptime failure and unhandled application errors notify the technical owner. Queue/SMS failure visibility is required when those paths are enabled.
- The team actively observes logs and critical flows during demonstrations and final defense.

## Backup, Export, Rollback, and Teardown

- The database and required files are backed up daily with encryption and access controls during the demonstration, giving a target RPO of 24 hours.
- The target RTO is one working day; a restore rehearsal is completed before the environment is exposed.
- Application rollback restores the previous known-good revision and compatible configuration.
- If demonstration results are exported, the technical owner verifies that they contain only synthetic data.
- On October 7, 2026, public access, workers, schedulers, credentials, and billable resources are disabled unless an extension is explicitly approved.
- Synthetic records, credentials, and cloud backups are deleted according to the demo teardown checklist and provider deletion behavior.

## Testing Strategy

### Automated tests

- Add or update Pest feature tests for deployment configuration validation, SMS adapter behavior, OTP delivery, invitation delivery, retry classification, idempotency, redaction, secure bootstrap, pilot-safe seeding, storage authorization, and migration preflight.
- Use Laravel HTTP fakes for provider-adapter tests and prevent unintended external requests.
- Run provider-independent contract tests against the fake adapter and the selected real adapter.
- Preserve and repair existing tests; tests are not deleted or weakened to obtain a green build.
- Run all launch-critical tests and the full suite. Any unrelated pre-existing failure proposed for deferral must be documented and shown not to affect exposed demo workflows; security tests cannot be deferred.

### Deployment rehearsals

- Rehearse a fresh installation; no historical clinic data upgrade is in scope.
- Build production frontend assets and all required Laravel/Filament caches.
- Test only enabled demo flows end to end: staff login with MFA, the one AR asset, authorized file behavior, synthetic records, and any required queue/scheduler work.
- Verify that participant and phone/SMS paths remain unavailable.
- Rehearse backup restore, application rollback, de-identified export, and teardown.

## Commands

All local commands run through Laravel Sail.

```bash
vendor/bin/sail up -d
vendor/bin/sail composer validate --strict --no-interaction
vendor/bin/sail artisan test --compact tests/Feature/Auth tests/Feature/PatientAccounts tests/Feature/Security tests/Feature/SmsProcessingTest.php
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm ci
vendor/bin/sail npm run build
vendor/bin/sail composer audit --locked --no-interaction
vendor/bin/sail npm audit --audit-level=high
vendor/bin/sail artisan migrate:status
vendor/bin/sail artisan schedule:list
vendor/bin/sail artisan optimize
vendor/bin/sail artisan icons:cache
vendor/bin/sail artisan optimize:clear
```

Provider-specific deployment, backup, restore, export, and teardown commands will be added to the demo runbook only after provider selection. Commands that target the public demo require explicit operator confirmation and a resolved environment identifier.

## Project Structure

Implementation should preserve the existing Laravel structure and place responsibilities near the current code:

```text
app/
  Console/Commands/                 # preflight, secure bootstrap, and export commands
  Jobs/                             # encrypted, idempotent delivery jobs
  Services/SmsGateway.php           # provider-neutral SMS contract
  Services/*SmsService.php          # provider adapters
config/
  filesystems.php                   # logical persistent disks
  services.php                      # provider configuration
database/
  migrations/                       # guarded schema changes
  seeders/                          # reference data and explicitly synthetic pilot data
routes/
  api.php                           # versioned Android API
  web.php                           # health and panel routes where applicable
tests/
  Feature/Auth/                     # OTP workflows
  Feature/PatientAccounts/          # invitation workflows
  Feature/Security/                 # production configuration and hardening
  Feature/Seeders/                  # production-safe bootstrap
  Feature/SavedFrames/              # migration prerequisite behavior
docs/
  decisions/                        # approved SMS and hosting ADRs
  specs/                            # this specification and later plan/tasks
```

No new top-level source directory is introduced without approval.

## Code Style and Contract Example

Implementation follows existing Laravel conventions, uses constructor property promotion, explicit parameter and return types, descriptive names, and braces for every control structure. A provider boundary should resemble this shape; exact naming may be refined during planning without weakening the behavior:

```php
<?php

namespace App\Services;

interface SmsGateway
{
    public function send(string $recipient, string $message): SmsDeliveryResult;
}

final readonly class SmsDeliveryResult
{
    public function __construct(
        public bool $accepted,
        public ?string $providerMessageId = null,
        public ?string $failureCode = null,
    ) {}
}
```

Controllers and jobs remain thin; validation, authorization, transactions, and provider behavior use the framework mechanisms and existing application conventions.

## Boundaries

### Always

- preserve existing user changes and public API compatibility unless a separately approved change requires otherwise;
- search the installed-version Laravel documentation before implementation;
- use tests to characterize current behavior before changing it;
- redact secrets and sensitive participant data;
- use named configuration entries, logical storage disks, dependency injection, and provider contracts;
- verify focused tests, the full suite, build, audits, caches, fresh migration, and pilot behavior;
- document launch, backup, export, rollback, expiry, and teardown actions.

### Ask First

- select or purchase an SMS, hosting, database, cache, queue, storage, monitoring, or backup service;
- add or remove dependencies;
- change a public API contract or Android client requirement;
- add provider webhooks that change externally reachable routes;
- change database schemas in a way that can destroy or reinterpret existing data;
- access, collect, copy, retain, or delete identifiable participant data outside the approved study procedure;
- create public resources, credentials, domains, or billing commitments;
- accept a security advisory, reduce a test assertion, or waive a release gate;
- change consent, privacy, retention, or study-data requirements.

### Never

- use real patient identities or clinical records as demonstration data;
- log or expose OTPs, secrets, tokens, complete SMS bodies, or unnecessary participant identifiers;
- mark an outbound message successful after a no-op or failed provider call;
- hard-code provider SDK behavior into authentication or patient-account workflows;
- depend on ephemeral local storage for collected pilot data;
- deploy with failing security/launch-critical tests, builds, caches, fresh-migration rehearsal, or an unmitigated critical/high advisory affecting an exposed path;
- deploy a fake OTP, authentication bypass, public demonstration password, or debug mode;
- retain the public environment beyond its approved expiry without review;
- commit secrets or execute a public-environment-changing command without explicit authorization.

## Release Gates

### Gate 0 — Specification Approval

- The owner confirms the assumptions, scope, boundaries, and success criteria in this document.

### Gate 1 — Demo Scope and Provider Decisions

- The team confirms that the deployment is staff-only, uses synthetic data, and will not be used for participant care or research-data collection.
- The hosting provider is selected.
- `DEPLOYMENT_MODE=demo` is enabled, `CAPSTONE_PILOT_ENABLED=false`, no participant accounts are provisioned, and participant/phone/OTP paths are unavailable.
- Budget, technical owner, and teardown date are recorded.

### Gate 2 — Internet-Exposure Readiness

- Security and launch-critical tests pass.
- No critical/high dependency advisory affects an exposed path.
- Debug mode is off; HTTPS, secrets, administrator MFA, rate limits, authorized storage, and production-style caches are verified.
- The fresh migration, approved synthetic seeding, build, backup, restore, and rollback checks pass.

### Gate 3 — Demo Go-Live

- Staff demonstration workflows pass end-to-end on the deployed environment.
- The single AR model loads and fails gracefully on unsupported devices or products.
- Phone/SMS paths remain unavailable; any enabled staff email path is verified using team-owned accounts.
- Uptime checks, error notification, daily backup, and the one-month expiry reminder are active.

### Gate 4 — Demo Closure

- Public access ends on October 7, 2026 unless an extension was approved before that date.
- Any synthetic demonstration export is verified.
- Credentials and billable resources are revoked or removed.
- Identifiable records and provider backups follow the approved deletion/retention procedure.

## Success Criteria

The system is demo-ready when all of the following are evidenced:

1. Security and launch-critical Pest tests pass on the deployed runtime versions, and any unrelated deferred test is documented with evidence that it cannot affect demo workflows.
2. Composer and npm audits have no unmitigated critical/high finding affecting an internet-exposed path.
3. The frontend build and Laravel/Filament deployment caches succeed from a clean checkout.
4. Deployment preflight rejects unsafe critical configuration and never exposes secret values.
5. Participant-code, phone registration/login, OTP, recovery, and invitation operations are unavailable in the deployed demo environment.
6. No pilot participant accounts, credentials, consent records, or research events exist in the demo database.
7. A fresh environment migrates, receives only reference and synthetic demonstration data, and provisions an MFA-protected administrator.
8. The one supported AR model works on a supported test device; all other frames/devices show a clear non-AR fallback.
9. Collected data and private files remain authorized and persistent across a redeploy.
10. Uptime and unhandled errors notify the technical owner, with queue failures visible when enabled.
11. Daily backup, restore, application rollback, synthetic export (if used), and teardown procedures are verified.
12. The application and demonstration materials state that this is a capstone prototype and not a clinical service.

## Open Questions

1. Which managed hosting provider and one-month spending cap will be used?
2. Who is the technical owner for deploys, alerts, rollback, and teardown?
3. Which synthetic demo records and assets must be retained until teardown?
4. Which single validated/published AR model will be demonstrated?

## Deferred Decision Records

After the owner approves this specification and the short provider evaluations are complete, record:

- the one-month hosting choice and teardown consequences;
- any live SMS provider choice, only if SMS is later added to the pilot.

No provider is selected by this specification.

The participant-code authentication history is recorded in [ADR-004](../decisions/004-use-temporary-participant-code-authentication-for-capstone-pilot.md) and is dormant for this demo-only scope. The current scope decision is recorded in [ADR-005](../decisions/005-use-staff-only-demo-deployment-for-capstone.md).
