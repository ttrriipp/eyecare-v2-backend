# Implementation Plan: Clinic Workflow Release Stabilization

## Status

Approved by the project owner on 2026-07-26 after appointment workflow review.
Phase 3 task breakdown is authorized; implementation remains blocked until the
task breakdown is approved.

No implementation work is authorized by this document alone.

## Objective

Complete the existing clinic-first redesign without rebuilding it. Preserve the
canonical patient, scheduling, clinical, catalog, reservation, quotation,
job-order, invoice, payment, complaint, rating, privacy, and audit foundations;
repair their integration; remove the superseded Order/Billing/customer system;
and produce reproducible technical-release evidence.

The plan deliberately avoids compatibility layers and avoids repairing tests
for behavior the approved specification removes.

## Delivery Strategy

Use six sequential milestones. Each milestone delivers a working vertical
slice, adds or corrects replacement tests, removes its superseded consumers,
and ends with a focused checkpoint.

```text
Truthful baseline and removal manifest
        ↓
Retained patient/clinical repairs
        ↓
Canonical catalog and inventory flow
        ↓
Legacy Order/Billing clean cutover
        ↓
Complete `/api/v1` patient contract
        ↓
Release evidence and implemented context
```

The full suite is expected to remain red during bounded replacement work.
Focused affected suites must remain green, and the full suite becomes a hard
gate only after obsolete tests and consumers have been removed.

## Architecture Decisions

### One supported commercial workflow

The only commercial path is:

```text
accepted quotation
    -> clinic-created job order
    -> invoice/payment
    -> dispensing
```

Patient-created Orders and legacy Billings are deleted. No adapter maps them to
Job Orders or Invoices.

### Patient boundary

- Clinical and operational records use `patient_id`.
- A login account remains an optional link on `Patient`.
- Conversations and notifications may resolve the linked account for delivery,
  but ownership remains patient-scoped.
- No active application or current schema contract retains `customer_id`.

### Product taxonomy

- `product_type` is a fixed physical-product classification: frame,
  contact_lens, or accessory. The legacy lens Product type represented physical
  lens blanks and is retained only as inactive historical data.
- A product has at most one optional display/reporting category; no
  many-to-many category pivot is introduced.
- Categories group within a type rather than duplicating types with generic
  names such as Frames, Lenses, and Accessories.
- Brand is optional so generic clinic items remain representable.
- Product variants are the physical SKU and available-stock unit.
- No `tracks_inventory` flag is added. Non-stock lens configurations, services,
  and custom charges remain outside physical Product inventory.
- Lens design configuration such as Single Vision, Bifocal, and Progressive is
  represented by LensCategory packages; separately billed treatments use
  LensOption. Neither is a physical Product category.
- Legacy direct-order constants and accessory-inclusive mobile scopes are
  removed; mobile catalog queries return frames only.

### Available-stock and allocations

`product_variants.stock_quantity` represents quantity available for a new
allocation or commitment.

- Requested reservation: no stock change.
- Prepared reservation: creates an owned allocation and reduces availability.
- Conversion: transfers allocation to a job-order item without another
  deduction.
- Unreserved stock item: commits when the job order is created.
- Release/expiry/cancellation: reverses the exact unconsumed allocation or
  commitment once.
- Dispensing: no second deduction.

Allocation/commitment state and inventory movements, not status inference,
determine whether reversal is allowed.

### Inventory ledger

Inventory movements become canonical and append-only:

- source is a reservation allocation, job order, restock, adjustment, or damage
  event—not a legacy Order;
- type comes from a controlled catalog;
- actor, variant, quantity, reliable before/after balance, and source remain
  queryable;
- variants and source records are locked and re-read inside transactions;
- multi-variant locks use deterministic ordering;
- archived variants retain movement history.

The exact schema—explicit nullable reservation/job-order keys versus a
constrained source relationship—will be fixed in Phase 3 after inspecting every
current movement consumer. It must provide database-enforced referential
integrity and straightforward Filament filtering.

### API boundary

`/api/v1` is the only patient-mobile API. The route tests use an exact
method/URI allow-list plus explicit forbidden route checks. Public auth routes
are versioned. Staff-only operations remain outside the patient route group.

The Android client is a separate product-integration program and is not changed
in this repository.

### Release truth

Task 40 and Checkpoint 8 are reopened during implementation. A named test may
claim only behavior it actually executes. Technical completion, Android
integration, and clinic production governance remain separate gates.

## Milestones

### Milestone 1: Establish the truthful baseline

Create a reviewed removal/repair manifest before deleting anything.

Work:

- reopen Task 40 and Checkpoint 8;
- record the current full-suite failure baseline;
- classify every Order, Billing, `customer_id`, unversioned patient route, and
  failing test as remove, retain-and-repair, or already replaced;
- map every obsolete test to canonical replacement coverage or to an explicitly
  removed requirement;
- inventory all current consumers of stock movements, patient factories,
  notifications, prints, seeders, and route contracts;
- record the authorized future retirement of the modified
  `GetOrCreateBillingTest.php`.

Checkpoint:

- no implementation file has been deleted without a mapped replacement;
- the manifest covers routes, models, policies, actions, resources,
  notifications, reports, factories, seeders, migrations, and tests;
- the project owner can see the bounded cleanup surface.

### Milestone 2: Repair retained patient and clinical behavior

Fix failures that belong to the canonical workflow before deleting legacy
domains.

Work:

- make patient/account factories reuse one clinical identity;
- route appointment notifications through the patient's linked account;
- require and persist reschedule reasons consistently;
- provide one role-aware Filament Patient Health Record workspace containing
  appointment type/referral information, the demographic snapshot, complaints,
  and medical history while keeping Appointment, Intake, and Encounter storage
  separate;
- replace status-only arrival controls with one authorized Check In action in
  Today's Queue and the Appointment detail header that transactionally creates
  exactly one waiting Encounter;
- make optometrist-capable admin/staff accounts inherit receptionist operations
  while keeping encounter and prescription authorship capability-gated;
- keep optometrist assignment clinic-controlled and do not add a
  preferred-optometrist request to the patient contract;
- replace remaining prescription `customer` relationships with patient
  relationships;
- bind prescription print `CX` to cylinder and retain axis separately;
- repoint private feedback and conversations to the canonical patient/account
  boundary;
- add negative ownership and role/capability coverage for repaired paths.

Checkpoint:

- focused patient, appointment, notification, prescription-print, feedback, and
  conversation suites pass;
- optometrist and receptionist workspace tests prove the combined health record
  is complete for its purpose and clinical findings remain capability-gated;
- retained application code in these areas has no `customer_id` query;
- no obsolete Order/Billing logic is added to make the tests pass.

### Milestone 3: Make catalog and inventory canonical

Deliver the complete reservation-to-job-order inventory path.

Work:

- normalize product type/category/brand/lens-type behavior without adding
  many-to-many categories or `tracks_inventory`;
- make mobile catalog scopes frame-only and remove direct-order type constants;
- replace `inventory_movements.order_id` with canonical source attribution;
- make reservation creation appointment-required and transactional;
- implement prepared allocation, expiry, appointment cancellation/no-show
  release, and explicit extension;
- create one conversion/commit workflow that links reservation allocations to
  job-order items;
- combine job-order creation and all required inventory commitments in one
  transaction;
- make job-order cancellation reverse only recorded unreversed commitments;
- update inventory history, adjustment UI, movement types, and low-stock
  reporting to canonical terminology;
- add idempotency, rollback, and concurrent-request tests.

Checkpoint:

- requested, prepared, converted, expired, cancelled, committed, reversed, and
  dispensed paths match the approved stock table;
- conversion never deducts twice;
- unavailable multi-item commitments roll back completely;
- repeated or concurrent transitions change stock once;
- every stock change has an attributable movement;
- focused catalog, reservation, inventory, quotation, and job-order suites
  pass.

### Milestone 4: Remove the parallel Order and Billing system

Delete obsolete behavior after replacement coverage is green.

Work:

- verify Job Order, Invoice, payment, dispensing, and inventory replacements;
- remove patient Order creation/cancellation and staff Order-status routes;
- remove legacy Order/Billing controllers, requests, resources, resources'
  children, actions, notifications, reports, models, factories, and seeders;
- remove legacy order/billing/payment status catalogs and database structures;
- remove obsolete tests in bounded batches;
- remove the authorized uncommitted `GetOrCreateBillingTest.php` edit together
  with its obsolete test;
- retain and repair any useful report or notification by moving it to the
  canonical domain rather than keeping a legacy dependency;
- run a fresh canonical migration and seed after schema cleanup.

Mechanical deletion is performed in batches of at most five files, with
consumer scans and focused tests after every batch.

Checkpoint:

- route and file scans find no patient-created Order or legacy Billing surface;
- current schema contains Job Orders, Invoices, and Invoice Payments but no
  Orders, Billings, or their obsolete status/item tables;
- no supported behavior depends on a customer factory alias;
- `migrate:fresh --seed` succeeds.

### Milestone 5: Complete and lock `/api/v1`

Move every approved patient-mobile operation behind one stable versioned
contract.

Work:

- version registration, login, logout, and profile endpoints;
- version appointment types, availability, booking, viewing, rescheduling, and
  cancellation;
- version appointment-linked intake draft/submission;
- retain frame browsing and appointment-linked reservation endpoints;
- version prescription, quotation, job-order, and invoice reads;
- version conversation/messages, private feedback, and verified ratings;
- paginate every list and use patient-safe API Resources;
- enforce linked-patient ownership and consistent error codes;
- replace partial prefix tests with an exact route allow-list and deny-list;
- remove replaced unversioned patient routes and unused API classes.

Checkpoint:

- exact contract tests cover every method and URI in the approved specification;
- cross-patient identifier substitution returns no data;
- patient routes expose no Order, checkout, payment, or clinic-only mutation;
- unversioned patient-mobile resource routes are absent;
- focused API suites pass.

### Milestone 6: Produce release evidence

Validate the system instead of marking expected outcomes complete.

Work:

- add one end-to-end scheduled-patient workflow and one account-less walk-in
  workflow;
- prove patient isolation and receptionist/optometrist capability boundaries;
- implement or document and execute a real non-sensitive backup
  dump-and-restore check;
- replace misleading production-configuration assertions with tests of actual
  behavior;
- update `BACKEND_CONTEXT.md` from verified route/schema inventories;
- run browser journeys for an optometrist/admin and receptionist;
- run the actual fresh rebuild, full Pest suite, Pint, production asset build,
  route scan, and legacy vocabulary scan;
- record exact evidence and only then complete Task 40 and Checkpoint 8.

Checkpoint:

- zero failing Pest tests;
- fresh canonical database and seed pass;
- Pint and frontend production build pass;
- browser, recovery, route, schema, and terminology evidence exists;
- technical completion is recorded without claiming Android or clinic
  production approval.

## Verification Cadence

Each implementation task follows:

1. search version-specific Laravel/Filament documentation;
2. load only its relevant specification and consumers;
3. write or correct the focused Pest test first;
4. implement the smallest vertical replacement;
5. remove superseded code only after replacement coverage passes;
6. run the affected Sail suite;
7. run Pint after PHP changes;
8. inspect the staged diff before committing.

Run the full suite at the end of Milestones 2–5 to measure progress. It becomes
a blocking pass/fail gate only at Milestone 6 because earlier runs still include
known obsolete tests awaiting their approved replacement milestone.

## Removal Controls

- Never use broad recursive deletion or `git add -A`.
- Resolve exact consumers before removing a route, class, table, or test.
- Keep changes atomic and reviewable by domain.
- Preserve unrelated user changes.
- The only pre-authorized dirty-file retirement is the current
  `GetOrCreateBillingTest.php` edit when its obsolete test is deliberately
  removed.
- Do not preserve dead behavior with aliases, duplicate API versions, feature
  flags, or compatibility migrations.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Deleting legacy code removes a still-useful clinic rule | High | Consumer/replacement manifest and replacement-first tests |
| Stock is deducted or restored twice | High | Recorded allocation ownership, row locks, deterministic order, idempotency tests |
| Inventory audit balances are unreliable under concurrency | High | Lock and refresh before calculating before/after values |
| API migration exposes another patient's records | High | Linked-patient policies, scoped binding, negative identifier tests |
| Taxonomy cleanup overcomplicates the catalog | Medium | Fixed type, one optional category, no tracking flag or tag pivot |
| Full suite stays red from obsolete tests | Medium | Delete only after canonical replacement; measure by milestone |
| Documentation again overstates readiness | High | Evidence-linked checkpoints and separate technical/product/production gates |
| External Android work delays release | Medium | Freeze backend contract; treat Android as a separate integration gate |
| DPO or retention decisions remain unavailable | High for production | Permit technical work but keep production deployment blocked |

## Out of Scope

- Android repository implementation;
- family/dependent accounts;
- mobile payments or quotation approval;
- system issuance of the official BIR Service Invoice;
- structured autorefractor imports;
- real patient-data migration;
- automated retention purge before clinic governance approval;
- production deployment.

## Phase 2 Approval Gate

- [x] The dependency order is approved.
- [x] Product taxonomy and inventory architecture are approved.
- [x] Legacy removal boundaries and batch controls are approved.
- [x] `/api/v1` completion strategy is approved.
- [x] Release evidence and external launch gates are approved.
- [x] The project owner authorizes Phase 3 task breakdown.
