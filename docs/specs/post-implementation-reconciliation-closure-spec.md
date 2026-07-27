# Spec: Post-Implementation Reconciliation Closure

## Status

Approved by the project owner on 2026-07-27. Phase 1 is complete. Phase 2 may
produce the technical closure plan, but implementation remains unauthorized
until the plan and task breakdown are separately approved.

## Objective

Close the remaining gap between the approved clinic workflow and the
implementation that was marked complete prematurely.

This is not another redesign, a new project, or a feature expansion. It repairs
only the contradictions found by the 2026-07-27 completion audit:

1. remaining Visit Reason consumers and dead compatibility files;
2. transitional rather than canonical development migrations;
3. a route-contract test that codifies paths different from the approved API;
4. retained-behavior coverage that is claimed but not mapped as complete; and
5. release evidence and context documents that contradict checked tasks.

Primary users remain:

- optometrists, who operate the clinic and may perform front-desk work;
- a possible receptionist, who performs non-clinical operations in Filament;
  and
- patients, who consume only the approved patient-safe mobile API.

Success means the implementation, tests, schema, routes, UI vocabulary, and
release documents all agree with the already-approved parent specification.

## Assumptions

1. The parent reconciliation specification remains authoritative.
2. The application is undeployed and contains no production data.
3. Development and seeded data remain disposable.
4. Appointment Type fully replaces Visit Reason.
5. Patient identity uses `patient_id`; no final migration or application
   consumer retains `customer_id`.
6. The patient API uses singular `/conversation` routes and the nested private
   attachment route approved in the parent specification.
7. Clinic staff use Filament. The current `/api/v1/staff/...` mutation is
   removed unless a separately specified staff API is approved later.
8. Existing green tests are useful baseline evidence but do not override the
   specification or static/runtime contradictions.
9. Previously checked tasks and checkpoints may be reopened when their named
   evidence is absent or contradicted.

If any assumption is false, revise this document before Phase 2.

## Audit Baseline

The independent 2026-07-27 audit established:

- the worktree was clean;
- the full Pest suite passed with 422 tests and 1,058 assertions;
- the current rebuilt database contains canonical tables and no
  `visit_reasons`, Orders, Billings, Services, or legacy Payment lookup tables;
- active Appointment and Filament code still references `visitReason`;
- Visit Reason, Order, and Billing factories/seeders remain;
- Appointment foreign keys required by the specification are nullable;
- early migrations still create `customer_id` and `order_id` before later
  transition migrations remove them;
- the route equality test approves plural Conversation paths, a top-level
  attachment path, and a staff mutation absent from the approved patient
  allow-list;
- the recovery map's two completion gates remain open;
- Task 39 is an empty claim-only commit;
- Task 40 changes only tracker documentation and records no browser,
  backup/restore, build, or context reconciliation evidence; and
- `BACKEND_CONTEXT.md` remains explicitly provisional and lists obsolete
  schema, routes, navigation, and terminology.

The closure starts from these facts rather than the checked tracker state.

## Required Corrections

### 1. Reset completion claims

- Reopen reconciliation Tasks 13, 16–17, 24–28, 35–36, and 39–40.
- Reopen Checkpoints B, D, E, and F where their evidence is contradicted.
- Keep unaffected completed work and commit references intact.
- Do not rewrite Git history or delete prior claim-only commits.

### 2. Complete the Appointment Type cutover

- Remove `visit_reason_id` from Appointment fillable state.
- Remove `Appointment::visitReason()`.
- Delete the VisitReason model, factory, and seeder.
- Replace `visitReason` eager loads, columns, labels, and widget data with
  `appointmentType`.
- Patient-facing and staff-facing labels use “Appointment Type.”
- Add records to Filament/widget tests so a blank legacy relationship cannot
  pass unnoticed.

### 3. Remove dead legacy application support

- Delete Order, Order Item, Order Status, Billing, Billing Item, and Billing
  Status factories and seeders that reference removed models/tables.
- Remove any remaining missing-model imports from `app/`, `database/`,
  `routes/`, and retained tests.
- Preserve canonical Job Order, Invoice, Invoice Payment, and inventory
  behavior.

### 4. Produce direct canonical migrations

- Appointments are created directly with required `patient_id`, required
  `appointment_type_id`, and required `duration_minutes`.
- `appointment_type_id` uses a restrictive foreign-key behavior; deleting a
  type must not null historical appointments.
- Prescriptions, Conversations, and Feedback are created directly with
  `patient_id`.
- No migration creates `customer_id`, Order, Billing, Service Record,
  Visit Reason, or orphan Payment lookup structures merely to remove them
  later.
- Delete superseded transition migrations after their final structures are
  incorporated into canonical creation migrations.
- Preserve all required foreign keys, indexes, encryption-compatible columns,
  soft deletes, and canonical audit/history tables.

### 5. Enforce the exact patient API

The patient allow-list remains exactly:

```text
POST   /api/v1/register
POST   /api/v1/login
POST   /api/v1/logout
GET    /api/v1/me
PATCH  /api/v1/me

GET    /api/v1/appointment-types
GET    /api/v1/appointment-availability
GET    /api/v1/appointments
POST   /api/v1/appointments
GET    /api/v1/appointments/{appointment}
POST   /api/v1/appointments/{appointment}/reschedule
POST   /api/v1/appointments/{appointment}/cancel

GET    /api/v1/appointments/{appointment}/intake
PUT    /api/v1/appointments/{appointment}/intake
POST   /api/v1/appointments/{appointment}/intake/submit

GET    /api/v1/frames
GET    /api/v1/frames/{frame}
GET    /api/v1/frame-reservations
POST   /api/v1/frame-reservations
POST   /api/v1/frame-reservations/{reservation}/cancel

GET    /api/v1/prescriptions
GET    /api/v1/prescriptions/{prescription}
GET    /api/v1/quotations
GET    /api/v1/quotations/{quotation}
GET    /api/v1/job-orders
GET    /api/v1/job-orders/{jobOrder}
GET    /api/v1/invoices
GET    /api/v1/invoices/{invoice}

GET    /api/v1/conversation
GET    /api/v1/conversation/messages
POST   /api/v1/conversation/messages
GET    /api/v1/conversation/attachments/{attachment}

POST   /api/v1/feedback
POST   /api/v1/job-order-items/{item}/rating
```

The equality test derives from this approved list, not from current routes.
It excludes staff mutations, plural Conversation aliases, top-level attachment
aliases, notifications, products, Visit Reasons, patient profile duplicates,
Orders, Billings, checkout, and payments.

### 6. Finish retained-coverage recovery

- Every “Restore required” recovery-map row names one or more current tests
  that cover each retained assertion.
- Missing coverage is added before the relevant implementation correction.
- Mixed legacy tests restore only canonical behavior.
- Empty or claim-only commits do not satisfy a coverage task.
- Test count is informational; behavior-to-test mapping is the gate.

### 7. Regenerate release evidence

- Run every focused and full verification command.
- Execute real seeded Filament journeys for an optometrist and receptionist.
- Execute the documented non-sensitive dump-and-restore validation.
- Inspect actual routes, schema, foreign keys, indexes, and nullable columns.
- Rewrite `BACKEND_CONTEXT.md` from observed implementation.
- Close recovery/release checkboxes only in the commit containing or directly
  referencing the exact evidence.

## Tech Stack

- PHP 8.5
- Laravel 13.12
- Filament 5.6
- Livewire 4.3
- Sanctum 4.3
- MySQL through Laravel Sail
- Pest 4.7 / PHPUnit 12.5
- Tailwind CSS 4.3 / Vite

No dependency change is required or authorized.

## Commands

All PHP, Artisan, Composer, and Node commands run through Sail.

```bash
# Focused application verification
vendor/bin/sail artisan test --compact tests/Feature/Appointments
vendor/bin/sail artisan test --compact tests/Feature/AppointmentModelTest.php
vendor/bin/sail artisan test --compact tests/Feature/AppointmentSchedulingTest.php
vendor/bin/sail artisan test --compact tests/Feature/Filament
vendor/bin/sail artisan test --compact tests/Feature/Api/V1
vendor/bin/sail artisan test --compact tests/Feature/Seeders
vendor/bin/sail artisan test --compact tests/Feature/EndToEnd
vendor/bin/sail artisan test --compact tests/Feature/Privacy
vendor/bin/sail artisan test --compact tests/Feature/Security

# Full technical gates
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
vendor/bin/sail artisan migrate:fresh --seed --no-interaction
vendor/bin/sail artisan route:list --except-vendor --path=api
vendor/bin/sail artisan route:list --except-vendor
```

Static scans:

```bash
rg -n "visit_reason|VisitReason" app database routes tests
rg -n "customer_id|App\\\\Models\\\\Order|App\\\\Models\\\\Billing|App\\\\Models\\\\ServiceRecord" app database routes tests
rg -n "OrderFactory|OrderItemFactory|OrderStatusFactory|BillingFactory|BillingItemFactory|BillingStatusFactory" database tests
rg -n "orders|billings|checkout|purchase" app routes tests
```

The fresh rebuild is destructive and runs only against confirmed disposable
development/test data. Backup/restore validation uses a specifically named
non-sensitive restore-check database and the environment-provided MySQL
credentials:

```bash
vendor/bin/sail exec -T mysql sh -lc 'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > /tmp/eyecare-closure.sql
vendor/bin/sail exec -T mysql sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS eyecare_restore_check; CREATE DATABASE eyecare_restore_check"'
vendor/bin/sail exec -T mysql sh -lc 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" eyecare_restore_check' < /tmp/eyecare-closure.sql
vendor/bin/sail exec -T mysql sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '\''eyecare_restore_check'\''"'
```

Before those commands run, the operator must confirm that
`eyecare_restore_check` is not used by any real environment.

## Project Structure

```text
app/
  Actions/                         Canonical mutations
  Filament/                        Optometrist/receptionist UI
  Http/Controllers/Api/            Exact patient API
  Http/Requests/Api/               Patient authorization and validation
  Http/Resources/                  Patient-safe responses
  Models/                          Canonical models only
database/
  factories/                       Canonical test data only
  migrations/                      Direct undeployed canonical schema
  seeders/                         Canonical clinic scenarios only
routes/
  api.php                          Exact patient allow-list
  web.php                          Private staff/print routes
tests/Feature/
  Api/V1/                          Exact routes and patient isolation
  Appointments/Filament/           Type-driven staff workflows
  Seeders/EndToEnd/                Rebuild and clinic journeys
  Privacy/Security/                Release protection
docs/
  BACKEND_CONTEXT.md               Verified implemented context
  specs/                           Parent and closure specifications
```

No new top-level directory is introduced.

## Code Style

Use explicit typed canonical relationships and existing Laravel conventions:

```php
/**
 * @return BelongsTo<AppointmentType, $this>
 */
public function appointmentType(): BelongsTo
{
    return $this->belongsTo(AppointmentType::class);
}
```

Rules:

- use `patient`, `appointmentType`, `jobOrder`, and `invoice` vocabulary;
- use explicit parameter and return types;
- use curly braces for every control structure;
- use Form Requests for input validation and API Resources for patient output;
- use typed Eloquent relationships and descriptive names;
- follow sibling Filament and Pest patterns;
- do not add compatibility aliases; and
- do not add dependencies.

## Testing Strategy

### Test first

Each correction begins with a focused failing Pest assertion that exposes the
audited mismatch. Deletion happens only after canonical replacement coverage
passes.

### Required coverage

- Appointment model and Filament widgets/tables use Appointment Type records.
- Appointment creation cannot persist null Patient, Appointment Type, or
  duration.
- Referral source and duration snapshot behavior remain correct.
- Fresh schema contains direct Patient foreign keys and no transition-only
  legacy columns.
- Exact patient route equality uses the approved 34-route allow-list.
- Private attachments verify Conversation ownership and attachment membership.
- Patient resources have positive ownership and cross-patient negatives.
- Dead factories/seeders are absent by file and class scan.
- Scheduled and account-less walk-in journeys still pass.
- Optometrist and receptionist browser journeys exercise real Filament pages.
- Recovery-map rows name passing tests for every retained behavior.

### Evidence rule

A passing full suite is necessary but insufficient. Completion also requires:

- static scans;
- fresh schema inspection;
- production asset build;
- real browser checks;
- non-sensitive backup/restore;
- accurate context documentation; and
- no open technical recovery gate.

## Boundaries

### Always

- use the parent specification as the source of truth;
- write or restore focused tests before changing behavior;
- use Sail for PHP, Artisan, Composer, and Node;
- search installed Laravel/Filament documentation before PHP changes;
- inspect all consumers before deleting files;
- keep changes incremental and reviewable;
- run Pint after PHP edits;
- preserve patient privacy, least privilege, audit, and append-only finance;
- record exact command results; and
- keep Android and production governance explicitly separate.

### Ask first

- change any approved patient route or capability;
- introduce or retain a staff API;
- retain any Visit Reason or legacy compatibility artifact;
- change roles, MFA, privacy retention, or authorization policy;
- add dependencies or top-level directories;
- touch the separate Android repository;
- operate on data that is no longer confirmed disposable; or
- execute backup/restore against a database name other than the approved
  non-sensitive restore-check target.

### Never

- adapt the specification to make accidental current routes appear correct;
- restore patient-created Orders, checkout, Billing, or payment mutation;
- retain `customer_id` or Visit Reason as a compatibility path;
- delete retained tests merely to keep the suite green;
- expose internal notes, costs, stock counts, provider capacity, or clinical
  fields outside their authorized role;
- permit receptionists to finalize clinical records;
- mutate posted Invoice Payments instead of recording corrections;
- mark a task complete with an empty/claim-only commit;
- close browser, build, backup, recovery, or context gates without executing
  them; or
- edit vendor files or commit secrets.

## Success Criteria

1. All contradicted tasks and checkpoints are visibly reopened before repair.
2. Appointment and all Filament consumers contain no Visit Reason relationship,
   field, label, filter, or eager load.
3. No VisitReason model, factory, seeder, resource, route, migration, or test
   remains.
4. No Order/Billing/ServiceRecord model import or legacy factory/seeder remains.
5. Appointments require `patient_id`, `appointment_type_id`, and
   `duration_minutes` at the database and validation boundaries.
6. Appointment Type deletion cannot erase an appointment's historical type
   reference.
7. Prescriptions, Conversations, and Feedback are created directly with
   Patient foreign keys.
8. No final migration creates `customer_id`, Orders, Billings, Visit Reasons,
   Services, Service Records, or orphan Payment lookups.
9. Fresh migration ordering has no transition dependency on a removed table.
10. The patient route equality test contains exactly the approved 34 routes.
11. Conversation and attachment paths match the singular nested contract.
12. No staff mutation is included in the patient route allow-list.
13. Every patient-owned resource has positive and cross-patient tests.
14. Every restore-required recovery-map behavior names passing coverage.
15. The recovery map has no open technical completion gate.
16. Full Pest, Pint, production build, and fresh seed pass.
17. Static scans return only intentional historical documentation references.
18. Real optometrist and receptionist Filament journeys pass.
19. Non-sensitive backup and restoration validation passes.
20. `BACKEND_CONTEXT.md` exactly matches observed schema, routes, roles,
    navigation, seed accounts, and verification results.
21. Reconciliation Task 40 and release checkpoints close only after criteria
    1–20 have reproducible evidence.
22. Android integration and clinic production/privacy governance remain
    separate follow-up gates.

## Out of Scope

- new clinic features;
- new role types;
- preferred-optometrist selection;
- Android implementation;
- production deployment;
- choosing final clinic retention periods;
- DPO/PIA approval;
- configurable payment-method catalogs; and
- reintroducing a Services preset catalog.

## Open Questions

No technical product decision is blocking this closure if the assumptions are
accepted.

External follow-ups remain:

1. Android repository integration after the API is frozen.
2. Clinic validation of provisional Appointment Type durations.
3. DPO/PIA, retention, hosting, monitoring, key management, and production
   backup governance before deployment.

## Phase 1 Approval Gate

- [x] Objective and audited baseline are explicit.
- [x] Commands and project structure are specified.
- [x] Code style and testing strategy are specified.
- [x] Always/Ask First/Never boundaries are defined.
- [x] Success criteria are concrete and executable.
- [x] The project owner confirmed the assumptions on 2026-07-27.
- [x] The project owner approved this closure specification on 2026-07-27.

Phase 2 may now produce the technical closure plan.
