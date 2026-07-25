# Implementation Plan: Clinic Workflow Redesign

## Status

Clean-cutover strategy and revised task-sized plan approved on 2026-07-25.

The approved specification remains the source of truth. This plan replaces the
earlier compatibility-oriented plan with a smaller vertical delivery strategy.
The application is not deployed, so obsolete behavior, schema, seeds, routes,
tests, and terminology do not need a compatibility period.

## Delivery Strategy

Build the clinic workflow in eight usable milestones. Each milestone:

1. introduces a complete replacement slice;
2. proves it with focused Pest tests;
3. removes the superseded legacy implementation in the same milestone;
4. runs a broader checkpoint suite; and
5. leaves `migrate:fresh --seed` working.

The system will not carry parallel customer/patient, order/job-order, or
billing/invoice implementations. Existing code is reused only when its
semantics match the approved specification.

## Assumptions

- There is no production data or deployed client to migrate.
- Development data may be discarded and all seed data may be replaced.
- The existing frame catalog and inventory code may be retained where it fits
  frame browsing, reservations, quotations, and clinic-created job orders.
- Direct patient ordering, accessory ordering, legacy billing, and misleading
  `customer` concepts may be removed.
- The web panel is optometrist-first, while authorized non-optometrist staff
  can perform reception and administrative workflow steps.
- Unresolved production-governance items remain launch blockers, not blockers
  to building and testing the development system.

## Architecture Decisions

### Clean domain boundary

- `users` are authentication accounts.
- `patients` are independent clinical identities.
- A patient may optionally be linked to one account.
- Roles remain `admin`, `staff`, and `patient`.
- `is_optometrist` is a capability flag valid only for `admin` or `staff`.

### Canonical workflow

The only supported end-to-end commercial workflow is:

`appointment → intake → encounter → prescription → quotation → job order →
invoice/payment → dispensing`

Frame reservations feed into the in-clinic discussion but never become a
patient-created order. Complaints restart the clinical workflow through a new
appointment/encounter linked to the original transaction.

### State and integrity

- Important transitions use explicit action classes and database transactions.
- Accepted quotations, prescriptions, job orders, invoices, payments,
  dispensing events, rating revisions, and moderation actions retain
  attributable history.
- Corrections are amendments or reversals; finalized records are not silently
  overwritten.
- Audit metadata must not duplicate unnecessary clinical details.

### API and panel

- `/api/v1` is the sole patient-mobile contract.
- Mobile exposes appointments, intake, frame browsing/reservations, patient-safe
  status views, and verified frame ratings.
- Mobile does not expose direct checkout, accessory ordering, internal clinical
  notes, staff-only operations, or editable prescriptions.
- Filament navigation follows the optometrist's daily workflow, with
  authorization limiting receptionist/non-optometrist actions.

### Schema and seeds

- Development migrations may be corrected or replaced because there is no
  deployed database contract.
- Obsolete domain tables are removed once their canonical replacement exists in
  the same milestone.
- Seeders describe the real clinic workflow and contain no fictional legacy
  customer-order flow.

## Dependency Order

```text
Patients and authorization
        ↓
Schedules and appointments
        ↓
Intake, encounters, prescriptions
        ↓
Frame reservations
        ↓
Quotations and job orders
        ↓
Invoices, payments, dispensing
        ↓
Complaints and verified ratings
        ↓
Integrated privacy and release validation
```

## Milestones

### Milestone 1: Patients, accounts, roles, and privacy baseline

Deliver the independent patient identity, optional account link, role/capability
authorization, patient onboarding/profile surfaces, and safe audit vocabulary.
Remove the `customer` role, customer factory aliases, customer-only fields that
have moved to `patients`, and obsolete customer wording/tests.

Checkpoint:

- role and capability matrix passes;
- staff can create a patient without an account;
- a patient account can access only its linked record;
- sensitive access and mutations are auditable;
- a fresh seeded database contains only canonical roles and users.

### Milestone 2: Clinic schedules and appointments

Deliver recurring 9:00–17:00 daily clinic hours, optometrist availability,
date-specific early closures/absences, patient-selected appointment times,
walk-ins, and staff provider assignment/reassignment. Replace hard-coded
availability behavior and obsolete appointment assumptions immediately.

Checkpoint:

- availability combines clinic hours, overrides, provider capacity, and
  existing bookings;
- patients choose a time but not an optometrist;
- staff can assign one of the available optometrists;
- early closures identify affected appointments without silently cancelling
  them.

### Milestone 3: Intake, encounters, prescriptions, and printing

Deliver patient health-record intake, receptionist verification, encounter
creation at check-in, prescription finalization/amendment, physical-chart
tracking (including the autorefractor machine's paper result), and compact
print layouts. Remove any standalone prescription flow that bypasses a patient
and encounter. Raw autorefractor output is not uploaded in this redesign.

Checkpoint:

- scheduled and walk-in patients reach the same encounter workflow;
- only an optometrist can finalize clinical findings and prescriptions;
- finalized clinical records preserve history;
- Patient Health Record prints in A5 landscape with an A4 fallback;
- Prescription prints in A5 portrait with an A4 fallback;
- print and chart access are authorized and audited.

### Milestone 4: Frame catalog and fitting reservations

Deliver a frame-only mobile catalog, appointment-linked fitting reservations,
reversible frame allocation, expiry/cancellation handling, and a staff queue.
Remove direct order/checkout endpoints, accessory ordering, and obsolete
patient-order tests as soon as reservation coverage passes.

Checkpoint:

- only frames appear as mobile products;
- a reservation requires an eligible appointment;
- patients cannot create an order or job order;
- stock is never permanently deducted by an abandoned reservation;
- staff can convert the patient's selected frames into the in-clinic workflow.

### Milestone 5: Quotations, job orders, and inventory commitment

Deliver versioned quotations with snapshot items and totals, in-person
acceptance/decline, clinic-created job orders, lens/frame details, status
tracking, and inventory commitment. Replace the legacy `orders` meaning and
panel surfaces rather than adapting them indefinitely.

Checkpoint:

- quotation revisions remain historically visible;
- acceptance identifies the staff member and patient decision;
- only clinic users can create a job order from an accepted quotation;
- inventory commitment is transactional and reversible on valid cancellation;
- patient APIs expose status only, never creation controls.

### Milestone 6: Invoices, payments, and dispensing

Deliver the internal Service Invoice record, snapshot line items/tax summary,
deposits, installments, corrections, dispensing-time issuance, balance
tracking, and printable clinic copy. Remove legacy billing concepts and tests
once this flow passes.

Checkpoint:

- deposits may be recorded before dispensing;
- invoice issuance occurs transactionally at dispensing;
- amount paid and remaining balance are derived from payment history;
- corrections are attributable and do not erase original payments;
- the printout contains the approved useful invoice fields without pretending
  to be a replacement for the clinic's BIR-authorized physical booklet.

### Milestone 7: Complaints and transparent frame ratings

Deliver complaint intake linked to the prior transaction, workflow restart,
verified-purchase frame ratings, revisions, and transparent moderation.

Checkpoint:

- a complaint creates or links a new appointment/encounter without overwriting
  the original history;
- only patients with a dispensed frame can rate it;
- one current rating per eligible dispensed frame is enforced;
- inappropriate text can be hidden while its star value remains in aggregates;
- revisions and moderation reason/actor/timestamps remain auditable.

### Milestone 8: Integrated panel, API, seeds, and privacy release gate

Finish optometrist-first navigation/dashboard, receptionist permissions,
notifications, `/api/v1` contract tests, realistic workflow seed data, reports,
privacy-rights workflows, retention/legal holds, incident register, MFA release
gate, and end-to-end verification. Remove all remaining obsolete vocabulary,
files, routes, configuration, and tests.

Checkpoint:

- `migrate:fresh --seed` succeeds with the new clinic scenario;
- the full Pest suite and production asset build pass;
- browser checks cover the critical optometrist and receptionist journeys;
- route and text scans find no unintended customer/direct-order/legacy-billing
  surface;
- privacy, authorization, audit, backup/restore, and launch checklists pass;
- DPO identity, PIA approval, retention schedule, and unresolved optical-field
  terminology are resolved before production deployment.

## Verification Cadence

For each task:

1. search version-specific Laravel/Filament documentation before code changes;
2. write or update the focused Pest test first;
3. implement the smallest complete slice;
4. run only affected tests;
5. run Pint after PHP changes;
6. remove superseded implementation and tests;
7. run the milestone regression suite.

Run the full test suite, production asset build, and fresh seeded rebuild at
Milestones 4, 6, and 8 rather than after every small task.

## Removal Rules

- Replacement and removal occur in the same milestone, not dozens of tasks
  apart.
- Tests that assert obsolete behavior may be deleted after replacement tests
  cover the approved behavior; the user approved this clean cutover.
- Do not add adapters, feature flags, `/api/v2`, legacy read paths, data
  backfills, or dual terminology solely for hypothetical compatibility.
- Do not remove unrelated catalog/inventory improvements that still serve the
  approved frame workflow.
- Before deleting a file or table, verify its consumers and replacement within
  the milestone.

## Risk Controls

| Risk | Control |
|---|---|
| Clinical or financial history becomes editable | Finalization, amendments, append-only payment/correction history, actor attribution |
| Patient data is exposed to the wrong account | Independent patient boundary, policies, scoped API resources, negative authorization tests |
| Early closure creates hidden appointment conflicts | Preview affected appointments and require deliberate staff handling |
| Reservation and job-order stock drift | Transactional allocation/commit/release actions with concurrency tests |
| Legacy removal breaks a useful catalog feature | Classify each touchpoint against the spec before removal and retain aligned frame/inventory code |
| Plan grows again | Keep 8 milestones, split only tasks that exceed one focused session or roughly five files, and require a spec change for new scope |

## Authorization to Proceed

- [x] Specification approved.
- [x] Clean cutover approved.
- [x] Seed-data replacement approved.
- [x] Obsolete legacy behavior and tests may be removed after replacement
      coverage exists.
- [x] Revised task breakdown approved; implementation resumed at Task 02.
