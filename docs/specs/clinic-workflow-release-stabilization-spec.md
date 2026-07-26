# Spec: Clinic Workflow Release Stabilization

## Status

Approved by the project owner on 2026-07-26. Technical planning is authorized;
implementation still requires approval of the Phase 2 plan and Phase 3 task
breakdown.

This is a corrective addendum to
`docs/specs/clinic-workflow-redesign-spec.md`. The approved clinic workflow
remains authoritative. This document does not introduce another workflow; it
defines the clean cutover and evidence required to finish that workflow
truthfully.

The existing Task 40 and Checkpoint 8 completion claims are not accepted as
release evidence until this specification's success criteria pass.

## Current Evidence

The release audit on 2026-07-26 found:

- the full Pest command discovered 973 tests, passed 893, and reported 15
  failures with 2,839 assertions;
- active legacy code still queries removed `customer_id` columns;
- patient-created order and legacy billing routes remain registered;
- `/api/v1` contains only part of the approved patient-mobile contract;
- the route contract test does not reject surviving unversioned direct-order
  routes;
- the release test named for `migrate:fresh --seed` invokes only the seeder and
  does not execute a fresh migration;
- the planned end-to-end clinic-workflow and backup-restore tests are absent;
- `docs/BACKEND_CONTEXT.md` still describes the former customer/order/billing
  system;
- one legacy billing test has an existing uncommitted `patient_id` conversion;
  on 2026-07-26 the project owner authorized retiring that edit with the
  obsolete Billing test after canonical Invoice replacement coverage exists.

These findings make the current build neither technically complete nor ready
for production approval.

## Objective

Finish the approved clinic-first redesign by removing its superseded parallel
system, repairing only canonical workflow regressions, completing the patient
API contract, and producing reproducible release evidence.

The stabilization must:

1. leave one coherent patient-centered domain;
2. remove patient-created ordering and legacy billing rather than adapting them;
3. retain clinic-created job orders, invoices, payments, reservations,
   quotations, catalog, and inventory behavior;
4. make `/api/v1` the sole patient-mobile contract;
5. make every automated test describe supported behavior;
6. make implementation documentation match the application;
7. distinguish technical completion from clinic governance approval.

## Users and Outcomes

### Clinic users

Administrators, optometrists, and receptionists use only the canonical patient,
appointment, encounter, quotation, job-order, invoice, payment, complaint, and
rating workflows. They do not encounter duplicate Orders or Billings resources.

### Patients

Patients use one versioned API for authentication, appointments, intake, frames,
reservations, finalized records, operational statuses, messages, feedback, and
ratings. They cannot create an order, job order, invoice, or payment.

### Developers and reviewers

Developers receive a zero-failure suite, an authoritative route contract, a
repeatable fresh rebuild, and an implemented-context document that can be used
without reconciling contradictory legacy descriptions.

### Clinic governance owner

The clinic receives an explicit list of unresolved production decisions rather
than a misleading claim that technical tests establish legal compliance.

## Confirmed Direction

### Retain

- independent `Patient` identities and optional patient accounts;
- roles `admin`, `staff`, and `patient`;
- `is_optometrist` as an eligible staff/admin capability;
- appointments, intake, encounters, prescriptions, and clinical prints;
- frame-only mobile catalog and fitting reservations;
- quotations and clinic-created job orders;
- invoices, append-only payments/corrections, and dispensing;
- private clinic feedback and verified frame ratings;
- conversations, notifications, catalog, inventory, complaints, audit,
  privacy-rights, retention-review, and incident records where they conform to
  the approved workflow.

### Remove

- patient-created `Order` behavior and its API endpoints;
- legacy `Billing` behavior replaced by invoices and payments;
- legacy Order and Billing Filament resources, actions, notifications, reports,
  factories, seed data, and tests with no canonical consumer;
- compatibility aliases whose only purpose is preserving `customer`;
- active `customer_id` relationships and queries;
- unversioned patient-mobile routes after their `/api/v1` replacements pass;
- checkpoint assertions that pass by checking less than their names claim.

### Repair rather than remove

- appointment and prescription features that belong to the approved workflow
  but still resolve a legacy `customer` relationship;
- the fragmented appointment/intake presentation, which must become one
  role-aware Patient Health Record workspace while retaining separate
  Appointment, Intake, and Encounter models;
- patient factories that accidentally create two clinical identities for one
  account;
- appointment notification delivery to a patient's linked login account;
- rescheduling tests and callers that omit the required patient-readable reason;
- private feedback and conversations, which remain supported but must use the
  canonical patient/account boundary;
- useful catalog and inventory behavior that supports frames, reservations, and
  job orders.

## Canonical Patient API

All patient-mobile endpoints use `/api/v1`. Public authentication endpoints are
versioned as well as authenticated resources.

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

POST   /api/v1/feedback
POST   /api/v1/job-order-items/{item}/rating
```

The implementation plan may normalize URI parameter spelling, but it may not
add mobile mutation capabilities beyond this contract without a specification
change.

Every patient resource is scoped through the authenticated account's linked
patient identity. List endpoints are paginated. Responses use stable resources
and consistent machine-readable error codes.

The route contract must assert both the complete allow-list and the absence of:

- unversioned patient-mobile resource routes;
- `orders`, `billing`, `checkout`, and `purchase`;
- patient mutation routes for quotations, job orders, invoices, or payments;
- staff-only operations under the patient-mobile route group.

## Data and Migration Direction

The application remains undeployed, so development migration history may be
consolidated into a coherent fresh schema.

- Canonical operational records reference `patient_id`.
- Account-specific delivery resolves through the patient's optional linked
  account; it does not make the account the clinical identity.
- Current schema and application code contain no active `customer_id` contract.
- Legacy order/billing tables are removed after canonical replacement tests
  identify every retained consumer.
- Seed/demo data is disposable; no legacy data backfill is required.
- The authoritative rebuild is the actual command
  `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`.

The implementation must inspect foreign keys, factories, policies, routes,
notifications, reports, and tests before removing each legacy table or model.

## Inventory Integrity

The existing product, variant, stock-adjustment, low-stock, replenishment, and
movement-history foundations are retained. Their legacy Order coupling must be
replaced by the canonical reservation and job-order workflow.

For the initial redesign, `ProductVariant::stock_quantity` represents stock
available for a new allocation or commitment. The panel must label this meaning
clearly. Preparing a frame can therefore reduce available stock even while the
physical frame remains at the clinic. Dispensing does not deduct it again.

Inventory behavior is:

```text
requested reservation
    -> no stock change

prepared reservation
    -> allocate each selected frame once
    -> reduce available stock
    -> record allocation ownership and movement

prepared reservation converted to job order
    -> transfer the existing allocation to the job order
    -> do not deduct that unit again

job-order item without an existing allocation
    -> commit available stock once when the job order is created

reservation released, cancelled, expired, or appointment cancelled/no-show
    -> reverse each unconsumed allocation exactly once

committed undispensed job order cancelled
    -> reverse only its unreversed commitment movements exactly once

job order dispensed
    -> no additional stock deduction
```

Required integrity rules:

- A mobile frame reservation requires an eligible appointment belonging to the
  linked patient. A walk-in uses its same-day appointment/queue record.
- A requested reservation never holds stock.
- Preparing a reservation locks and re-reads the reservation, its items, and
  affected variants inside one database transaction.
- Preparation sets an expiry no later than clinic closing on the appointment
  date unless an authorized clinic user explicitly extends it.
- A scheduled command releases expired prepared reservations and records the
  release; repeated execution is idempotent.
- Appointment cancellation or no-show releases any unconsumed prepared
  allocation.
- Job-order creation and inventory commitment occur in the same transaction.
- A job order created from reserved frames links each consumed allocation to
  the resulting job-order item.
- Unreserved stock-managed items, including stock-managed lens variants when
  used, are validated and committed during that same transaction.
- Non-stock services, custom charges, and non-stock lens descriptions do not
  create inventory movements.
- Cancellation reverses recorded allocations or commitments; it must never
  infer a stock increase merely from a status value.
- Every prepare, release, expiry, conversion, commitment, reversal, restock,
  damage, and manual adjustment is attributable to its source and actor.
- Inventory movements no longer depend on a legacy `order_id`. The technical
  plan may use explicit reservation/job-order references or a constrained
  source relationship, but the source must remain queryable and auditable.
- Movement records retain the exact quantity and reliable before/after
  available-stock values.
- Movement types are a controlled catalog; arbitrary request data cannot create
  new types.
- State validation happens again after acquiring locks. Concurrent repeated
  prepare, release, conversion, commitment, or cancellation requests produce
  the same result as one successful request.
- Variants are locked in a consistent order during multi-item transactions to
  reduce deadlock risk.
- Deleting or archiving a product or variant must not delete its inventory
  history.

The technical plan must preserve the existing useful inventory UI while
renaming legacy Order references to Frame Reservation or Job Order as
appropriate.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5
- Livewire 4
- Laravel Sanctum 4
- MySQL through Laravel Sail
- Pest 4 and PHPUnit 12
- Tailwind CSS 4 and Vite 8

No new dependency is required or authorized by this specification.

## Commands

All PHP, Artisan, Composer, and Node commands run through Sail.

```bash
vendor/bin/sail up -d
vendor/bin/sail artisan route:list --except-vendor --path=api
vendor/bin/sail artisan test --compact tests/Feature/Api/V1
vendor/bin/sail artisan test --compact tests/Feature/Patients
vendor/bin/sail artisan test --compact tests/Feature/Appointments
vendor/bin/sail artisan test --compact tests/Feature/Encounters
vendor/bin/sail artisan test --compact tests/Feature/JobOrders
vendor/bin/sail artisan test --compact tests/Feature/Invoices
vendor/bin/sail artisan test --compact
vendor/bin/sail artisan migrate:fresh --seed --no-interaction
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
```

Browser acceptance checks must use the application's resolved local URL and
real seeded roles after the fresh rebuild.

## Project Structure

Use the existing Laravel structure:

```text
app/
  Actions/                 canonical state transitions
  Filament/Resources/      clinic panel resources
  Http/Controllers/Api/    `/api/v1` patient controllers
  Http/Requests/Api/       validation and endpoint authorization
  Http/Resources/          stable patient-safe response contracts
  Models/                  canonical domain models
  Policies/                record and capability authorization
database/
  factories/               valid canonical fixtures
  migrations/              coherent fresh schema
  seeders/                 approved clinic scenario
routes/
  api.php                  sole `/api/v1` patient contract
tests/Feature/
  Api/V1/                  allow-list, ownership, and resource contracts
  EndToEnd/                complete clinic workflow
  Privacy/                 access, rights, recovery, and incident behavior
docs/
  BACKEND_CONTEXT.md       implemented behavior only
  specs/                   approved target and stabilization documents
```

No new top-level directory is needed.

## Code Style

Retained transitions use typed, single-purpose actions and explicit
authorization. Deleting the parallel system must not move its logic into
controllers.

```php
final class CancelFrameReservation
{
    public function handle(FrameReservation $reservation, User $actor): void
    {
        Gate::forUser($actor)->authorize('cancel', $reservation);

        DB::transaction(function () use ($reservation, $actor): void {
            $reservation->releaseAllocation($actor);
            $reservation->markCancelled($actor);
        });
    }
}
```

- Use explicit parameter and return types.
- Use TitleCase enum cases.
- Use Form Requests, policies, API Resources, and named routes.
- Use transactions for multi-record transitions.
- Preserve immutable clinical and financial history.
- Use `patient`, `jobOrder`, and `invoice` vocabulary.
- Follow existing Filament v5 namespaces and sibling conventions.

## Testing Strategy

### Replacement-first cleanup

Before deleting an obsolete test, identify the canonical test that proves its
still-valid business rule. Tests whose behavior is explicitly removed—such as
patient checkout—are deleted rather than rewritten to pass.

### Focused regression tests

Add or correct tests for:

- patient factory/account uniqueness;
- notification delivery through the linked patient account;
- prescription print relations and authorization;
- required reschedule reasons;
- private feedback and conversation ownership;
- the complete `/api/v1` route allow-list and deny-list.

### Inventory integration tests

Inventory tests must prove:

- requested reservations do not change stock;
- preparation allocates each frame once under concurrent requests;
- release, expiry, appointment cancellation, and no-show restore each
  unconsumed allocation once;
- conversion transfers allocation ownership without another deduction;
- job-order creation commits all unallocated stock-managed items atomically;
- any unavailable item rolls back the complete job order and every stock
  movement;
- job-order cancellation reverses only recorded, unreversed commitments;
- repeated conversion, commitment, release, and cancellation are idempotent;
- movement source, actor, quantity, and before/after values are accurate;
- archived variants retain their movement history;
- no canonical inventory record or test depends on a legacy Order.

### End-to-end workflow

One end-to-end feature test must demonstrate:

```text
patient/account or walk-in
  -> appointment
  -> intake
  -> check-in/encounter
  -> finalized prescription
  -> accepted quotation
  -> job order
  -> invoice/payment
  -> dispensing
```

It must also prove that a patient cannot create a job order or access another
patient's records.

### Recovery validation

Backup validation must exercise a real documented dump-and-restore procedure
against non-sensitive test data, or the release documentation must explicitly
identify the external infrastructure step and provide reproducible evidence.
A test that merely asserts configuration exists is insufficient.

### Final gates

Technical completion requires:

- zero failing Pest tests;
- a successful fresh migration and seed;
- formatted PHP;
- a successful production asset build;
- a route-manifest comparison;
- scans showing no active legacy user-facing vocabulary or direct-order API;
- browser journeys for administrator/optometrist, receptionist, and patient
  boundaries.

## Boundaries

### Always

- Treat the approved clinic workflow specification as authoritative.
- Remove superseded code instead of maintaining parallel domains.
- Preserve useful frame catalog and inventory behavior.
- Write replacement coverage before deleting still-valid tests.
- Protect patient ownership and optometrist-only clinical authorship.
- Keep financial and clinical corrections attributable.
- Preserve user changes except for the specifically authorized retirement of
  the obsolete Billing-test edit after its replacement gate passes.
- Update `BACKEND_CONTEXT.md` only with behavior verified in the implementation.
- Record exact commands and outcomes for the final release gate.

### Ask first

- Adding dependencies or new infrastructure.
- Retaining any legacy patient-order or Billing behavior.
- Changing the approved `/api/v1` capabilities.
- Changing state machines or financial/clinical integrity rules.
- Making changes in the Android repository.
- Enabling production deployment or real patient data.

### Never

- Repair obsolete tests solely to make the failure count smaller.
- Reintroduce `customer_id` into canonical records.
- Let patients create orders, job orders, invoices, or payments.
- Claim `migrate:fresh` passed when only a seeder ran.
- Mark a browser, recovery, build, or full-suite gate complete without running
  it.
- Treat passing technical tests as proof of Philippine Data Privacy Act
  compliance.
- Deploy while clinic governance blockers remain unresolved.
- Delete clinical or financial history to simplify the cutover.
- Edit vendor files or add a compatibility API version.

## Success Criteria

### Technical completion

1. The full Pest suite passes with zero failures.
2. `migrate:fresh --seed` succeeds using only canonical clinic seed data.
3. Pint and the production frontend build pass.
4. `/api/v1` exactly exposes the approved patient contract.
5. No patient-created order, checkout, or legacy Billing route is registered.
6. No active application query references a removed `customer_id` column.
7. Filament exposes Job Orders and Invoices, not parallel Orders and Billings.
8. Canonical appointment, notification, prescription-print, patient-factory,
   feedback, and conversation behavior passes focused tests.
9. The Filament Patient Health Record workspace presents appointment type,
   referral source when applicable, the demographic snapshot, complaints, and
   medical history together without exposing optometrist-only data to a
   receptionist.
10. Requested reservations do not change stock; prepared reservations allocate
   stock once and release it once on cancellation, expiry, cancellation of the
   appointment, or no-show.
11. Reservation conversion transfers allocation to the job order without a
    second deduction, while unreserved items commit once.
12. Job-order cancellation reverses only recorded, unreversed commitments.
13. Every canonical stock change has an attributable movement with an accurate
    source, actor, quantity, and before/after balance.
14. Inventory concurrency and idempotency tests pass.
15. Prescription print tests prove that `CX` binds to cylinder while axis
    remains separate.
16. End-to-end and patient-isolation tests pass.
17. Backup restoration has reproducible evidence.
18. `docs/BACKEND_CONTEXT.md`, route inventory, and schema inventory describe
    the implemented system.
19. Task 40 and Checkpoint 8 are reopened and may be completed only after
    criteria 1–18 have recorded evidence.

### Product integration completion

20. The Android client uses only `/api/v1` and has no checkout/direct-order
    behavior.
21. Optometrist and receptionist acceptance journeys pass against a fresh
    seeded environment.

### Production approval

22. A DPO and privacy-request/incident owner are formally designated.
23. The PIA and applicable processing-system registration are approved.
24. Category-specific retention and lawful-basis decisions are recorded.
25. Hosting, backups, TLS, MFA, queue workers, monitoring, and recovery
    procedures are approved for the production environment.

Technical completion does not imply criteria 20–25 or authorize deployment.

## Resolved Decisions

- The Filament panel will provide one combined Patient Health Record workspace
  composed from Appointment and Intake data; it will not merge those domain
  records or expose optometrist-only findings to reception staff.
- Receptionists check patients in from Today's Queue or the Appointment detail
  header. That single authorized action verifies the intake and assigned
  optometrist, marks the appointment arrived, and creates exactly one waiting
  Encounter transactionally; a generic status-only `Mark Arrived` action is
  forbidden.
- Optometrist-capable admin/staff accounts inherit normal receptionist
  operations and add clinical capabilities. Non-optometrist staff cannot start
  or complete Encounters or author prescriptions.
- Preferred-optometrist requests are excluded. Patients select clinic
  availability, while the clinic controls the actual optometrist assignment.
- The Android client is maintained in a separate repository whose location is
  not yet known. Backend technical completion provides the tested `/api/v1`
  contract; Android adoption is a separate product-integration gate before
  release.
- The clinic confirmed on 2026-07-26 that `CX` means cylinder. Prescription
  print binding must use the O.D./O.S. cylinder values and keep axis separate.
- On 2026-07-26, the project owner authorized removal of the uncommitted
  `patient_id` conversion in `tests/Feature/GetOrCreateBillingTest.php` when
  canonical Invoice tests replace the obsolete Billing behavior. It does not
  require a preservation commit.

## Open Questions

The following do not block technical planning but must remain visible:

1. Who is the clinic's DPO and governance owner?
2. What retention periods and lawful bases apply to each data category?

## Phase 1 Approval Gate

- [x] The current evidence is accurate.
- [x] The retain/remove/repair boundaries are approved.
- [x] The complete `/api/v1` contract is approved.
- [x] The testing and release-evidence requirements are approved.
- [x] Technical, product-integration, and production gates are correctly
      separated.
- [x] The project owner authorizes Phase 2 technical planning.
