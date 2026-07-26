# Spec: Post-Implementation Reconciliation

## Status

Approved by the project owner on 2026-07-26. Phase 1 is complete. Phase 2 may
produce the technical implementation plan; application implementation remains
unauthorized until the later plan and task gates are approved.

## Objective

Reconcile the implemented Laravel backend with the already-approved clinic
workflow after the premature legacy table cutover.

The project remains the existing application. This is not a rebuild and does
not restore the superseded patient-created Order or legacy Billing workflow.
The work will:

1. keep the legacy Order/Billing tables removed;
2. remove or replace every remaining runtime dependency on their deleted
   models, tables, routes, and vocabulary;
3. finish the incomplete Appointment Type cutover;
4. reduce the database to intentional canonical tables;
5. restore meaningful tests for retained behavior;
6. make the patient `/api/v1` routes match one approved exact contract; and
7. regenerate truthful release evidence before deployment is considered.

Primary users remain:

- optometrists, who run the clinic and may perform receptionist operations;
- a possible receptionist, who performs non-clinical front-desk operations;
- patients, who use the mobile API for clinic-approved self-service behavior.

Success means the application can be understood and operated without any
hidden dependency on the deleted customer/Order/Billing system.

## Baseline Findings

The current application is not release-ready even though the stabilization
task boxes are checked:

- the current database no longer contains `orders`, `order_items`,
  `order_statuses`, `billings`, `billing_items`, `billing_statuses`,
  `payments`, `service_records`, or `discount_types`;
- a passing 400-test suite does not execute several remaining broken paths;
- live code still imports or relates to missing `Order`, `Billing`,
  `ServiceRecord`, and legacy Payment behavior;
- `/pdf/billings/{billing}` and `/thermal/billings/{billing}` remain
  registered;
- `visit_reasons` remains the scheduling source while `appointment_types`
  exists beside it;
- `payment_methods` and `payment_statuses` are disconnected from canonical
  `invoice_payments`, which stores validated string values;
- old migrations create the legacy schema and a later irreversible migration
  drops it instead of producing one coherent undeployed schema;
- the implemented route-contract test accepts 51 routes rather than enforcing
  the previously approved patient contract;
- many retained-workflow tests were deleted along with genuinely obsolete
  tests; and
- `BACKEND_CONTEXT.md` contains claims contradicted by the current schema.

This specification replaces checkbox state with executable evidence.

## Assumptions

1. The backend has not been deployed and contains no production data that must
   be migrated.
2. Seed/demo data is disposable.
3. The existing project remains the implementation base.
4. The deleted Order/Billing tables stay deleted.
5. Job Orders, Invoices, Invoice Payments, and Dispensing Events are the only
   canonical fulfillment and finance workflow.
6. Patients never create Orders, Job Orders, Invoices, or payments.
7. The approved clinic workflow and privacy boundaries remain unchanged.
8. No preferred-optometrist selection is added.
9. Test restoration means restoring retained behavior coverage, not restoring
   obsolete Order/Billing expectations.

If any assumption is false, the specification must be revised before Phase 2.

## Tech Stack

- PHP 8.5
- Laravel 13.12
- Filament 5.6
- Livewire 4.3
- Sanctum 4.3
- MySQL through Laravel Sail
- Pest 4.7 / PHPUnit 12.5
- Tailwind CSS 4.3 / Vite

No dependency changes are required or authorized.

## Canonical Domain Decisions

### Patient identity

- `User` is the login/account and authorization identity.
- `Patient` is the clinical identity.
- Clinical, appointment, communication, feedback, fulfillment, and invoice
  ownership uses `patient_id`.
- A Patient may have no User account.
- A patient-facing request resolves the authenticated User's linked Patient.
- No active application or final migration references `customer_id`.

### Appointments

`AppointmentType` replaces `VisitReason` completely.

The only seeded Appointment Types are:

- New Patient
- Follow-up
- Routine Check-up
- Referral

Each Appointment Type owns its default duration, active state, and referral
rule. Appointments store a required `appointment_type_id` and a required
`duration_minutes` snapshot copied from the type at booking; Referral requires
`referring_source`. Availability, booking, rescheduling, walk-ins, overlap
queries, Filament forms, seed data, factories, API resources, and tests use
the Appointment snapshot. Changing an Appointment Type's default duration
affects future bookings only.

Patients cannot change duration. Optometrists/admins configure Appointment Type
defaults. Initial seeded values remain provisional until the clinic confirms
its operational timing:

- New Patient: 30 minutes
- Follow-up: 15 minutes
- Routine Check-up: 30 minutes
- Referral: 30 minutes

After the cutover:

- `visit_reasons` and `appointments.visit_reason_id` do not exist;
- no `VisitReason` model, resource, factory, seeder, route, or test remains;
- each Appointment retains the duration that was booked even if its
  Appointment Type default later changes;
- Patient Intake keeps a system-owned Appointment Type snapshot for the
  printed/physical health record, but the patient cannot edit it.

The two classifications are not retained side by side because the current
Visit Reasons do not add a clean second dimension:

- Follow-up duplicates the Follow-up Appointment Type.
- Eye Exam substantially overlaps Routine Check-up.
- Prescription Check is normally a Follow-up or Routine Check-up whose details
  belong in Chief Complaint.
- Contact Lens Fitting describes a requested service or purpose, not the
  appointment classification printed on the clinic's Patient Health Record.

If the clinic later confirms that it schedules special procedures with
different durations, that requirement should be modeled explicitly as a
service/procedure reservation. It should not preserve a second generic
appointment classifier that patients and staff must reconcile manually.

## Patient Mobile Appointment Experience

The patient-facing flow is intentionally short at booking time and separates
slot reservation from the longer Patient Health Record intake.

### 1. Open Appointments

The patient sees:

- the next appointment and its status;
- upcoming and past appointments;
- Book Appointment; and
- Reschedule or Cancel only when the appointment status permits it.

The patient does not see provider capacity, internal notes, staff actor IDs, or
an option to choose a preferred optometrist.

### 2. Choose Appointment Type

The patient selects one:

- New Patient
- Follow-up
- Routine Check-up
- Referral

Referral reveals a required “Referred by / Referral source” field. The
application may explain each option in patient-friendly language, but the
stored values remain the clinic's four official types.

Chief Complaint is not requested as a second booking classification. It is
collected in the Patient Health Record intake where the clinic expects it.

### 3. Choose Date and Time

The patient selects a date and receives available start times.

Availability accounts for:

- normal 9:00 AM–5:00 PM clinic hours every day;
- clinic closures or early closing;
- Appointment Type duration;
- the number of eligible optometrists working that interval;
- provider absences or shortened schedules; and
- existing appointments and concurrent booking protection.

The clinic assigns the optometrist. If an optometrist is assigned later, the
patient may see the name on the confirmed appointment, but cannot request or
change it.

### 4. Review and Book

The confirmation screen shows:

- Appointment Type;
- referral source when applicable;
- date and time;
- expected duration from the Appointment snapshot;
- clinic address; and
- a reminder that booking is for an eye consultation, not a product order.

Submission creates a patient-owned pending appointment. The patient then sees
its reference number and current status.

### 5. Complete Patient Health Record

Immediately after booking, the patient is prompted to Complete Health Record.
The intake may be saved as a draft and resumed before the visit.

It shows the system-owned Appointment Type and referral source, then collects:

- prefilled patient information that the patient may correct;
- Chief Complaint;
- Past Ocular History;
- Past Surgical History;
- Past Medical History;
- Allergies; and
- Medications.

After submission, the snapshot becomes read-only to the patient according to
the approved verification rules. Clinic users verify it; patients do not call
the verification action.

### 6. Before and During the Visit

For pending or confirmed appointments, the patient may reschedule or cancel
within clinic rules. Status changes and reminders are delivered through
approved notification channels.

The patient does not check themselves in from mobile. On arrival, a
receptionist or optometrist clicks Check In in the web panel, assigns the
available optometrist when needed, and creates exactly one waiting Encounter.

### 7. Frames and After-Visit Records

After an eligible appointment exists, the patient may browse the frame-only
catalog and reserve frames to try at the clinic. This is not checkout and does
not create an Order.

After the clinic visit, patient-safe mobile views may show:

- finalized prescriptions;
- quotations;
- Job Order progress;
- invoices and recorded balance information;
- the private conversation; and
- purchase-verified frame rating eligibility after dispensing.

### Fulfillment and finance

The only supported chain is:

```text
Appointment
  -> Patient Intake
  -> Check In / Encounter
  -> Prescription
  -> Quotation
  -> clinic-created Job Order
  -> Invoice / Invoice Payments
  -> Dispensing
```

Remaining references to Order or Billing are either:

- replaced with Job Order or Invoice when the behavior remains useful; or
- deleted when the behavior belonged only to the obsolete workflow.

Examples:

- Billing PDF/thermal routes become Invoice print routes or are removed if an
  equivalent canonical print route already exists.
- Daily summary counts Job Orders and Invoice Payments.
- conversation context supports canonical Appointment, Product/Frame, and Job
  Order references only when the patient owns them.
- SMS events use Appointment or Job Order references only.
- dashboard demo data uses Patients, Appointment Types, Job Orders, Invoices,
  and Invoice Payments.

### Inventory

Inventory history remains canonical and may reference only:

- a Frame Reservation;
- a Job Order; or
- a manual/restock/damage adjustment.

No inventory model, factory, UI, report, or test references legacy Orders.
Commitment and reversal remain ledger-backed, atomic, and idempotent.

### Payment methods and statuses

Recommended decision:

- delete the orphaned `payment_methods` and `payment_statuses` tables, models,
  factories, and seeders;
- define one PHP enum or equivalent single source of truth for supported Invoice
  payment methods;
- allow Cash, GCash, Bank Transfer, Credit Card, and Check;
- keep Invoice Payment status as the canonical append-only `posted`/`voided`
  value contract;
- validate payment method and status at every write boundary.

The choice avoids a configurable lookup feature that the current canonical
Invoice UI does not use.

### Services

Recommended decision:

- remove the disconnected `services` table, model, Filament resource, factory,
  seeder, and tests in this reconciliation;
- continue representing the actual frame, lens, and service description as
  snapshotted Quotation/Job Order/Invoice line items;
- add a reusable service-price preset catalog later only if the clinic confirms
  that it uses one operationally.

Keeping a settings page that does not feed any approved workflow is not useful.

## Canonical Patient API

All patient-mobile endpoints use `/api/v1`. Staff-only operations are not part
of this patient allow-list.

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

The authenticated attachment-download route is the only proposed addition to
the earlier explicit list. It resolves the earlier contradiction between
requiring attachment access and omitting a route that can deliver private
files.

The final route contract expressly excludes:

- `orders`, `billing`, `checkout`, `purchase`, or patient payment mutations;
- `/api/v1/products`, brands, categories, and visit reasons;
- duplicate `/patient/profile` routes when `/me` owns the patient-safe account
  and linked-profile representation;
- collection/list feedback routes;
- patient intake verification;
- generic staff status mutation under the patient route group;
- unapproved notification-inbox endpoints; and
- unversioned patient resource routes.

Patient notifications remain delivered through approved account-resolved
channels. Adding a mobile notification inbox is a separate future API change.

## Release-Claim Reset

The following claims return to open until final evidence is regenerated:

- stabilization Tasks 17, 18, 20, 21, 23, 24, and 26;
- stabilization Checkpoints D, E, and F;
- original redesign Task 40 and Checkpoint 8; and
- the “verified” banner in `BACKEND_CONTEXT.md`.

Implementation may update checkboxes only after executing their named
verification commands.

## Commands

All PHP, Artisan, Composer, and Node commands run through Sail.

```bash
# Focused tests
vendor/bin/sail artisan test --compact tests/Feature/Appointments
vendor/bin/sail artisan test --compact tests/Feature/Api/V1
vendor/bin/sail artisan test --compact tests/Feature/Encounters
vendor/bin/sail artisan test --compact tests/Feature/Reservations
vendor/bin/sail artisan test --compact tests/Feature/Inventory
vendor/bin/sail artisan test --compact tests/Feature/JobOrders
vendor/bin/sail artisan test --compact tests/Feature/Invoices
vendor/bin/sail artisan test --compact tests/Feature/EndToEnd
vendor/bin/sail artisan test --compact tests/Feature/Privacy
vendor/bin/sail artisan test --compact tests/Feature/Security

# Full verification
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
vendor/bin/sail artisan migrate:fresh --seed --no-interaction
vendor/bin/sail artisan route:list --except-vendor --path=api
vendor/bin/sail artisan route:list --except-vendor
```

Required static scans:

```bash
rg -n "customer_id|App\\\\Models\\\\Order|App\\\\Models\\\\Billing|App\\\\Models\\\\ServiceRecord" app database routes tests
rg -n "visit_reason|VisitReason" app database routes tests
rg -n "orders|billings|checkout|purchase" app routes tests
rg -n "Order|Billing|Customer" app resources database routes tests
```

The final recovery and browser commands must use the already-documented
non-sensitive backup/restore procedure and seeded receptionist/optometrist
journeys. Evidence records exact commands and outcomes rather than inferred
passes.

## Project Structure

```text
app/
  Actions/                    Canonical workflow mutations
  Enums/                      Status and payment-method value contracts
  Filament/                   Optometrist/receptionist administration
  Http/Controllers/Api/       Exact versioned patient API
  Http/Requests/Api/          Authorization and validation
  Http/Resources/             Patient-safe representations
  Models/                     Canonical Eloquent models only
database/
  factories/                  Canonical test-data builders
  migrations/                 Coherent undeployed schema
  seeders/                    Realistic clinic scenario
resources/views/print/        Health record, prescription, and invoice output
routes/
  api.php                     Exact patient API plus separate staff operations
  web.php                     Canonical private print routes
tests/Feature/
  Api/V1/                     Exact contract and patient isolation
  Appointments/               Scheduling and type behavior
  Encounters/                 Intake/check-in/clinical records
  Reservations/Inventory/     Allocation integrity
  JobOrders/Invoices/         Fulfillment and append-only finance
  Filament/                   Real staff workflows
  EndToEnd/Privacy/Security/   Release acceptance
docs/specs/                   Approved specification, plan, and tasks
```

No new top-level directory or dependency is introduced.

## Code Style

Follow existing Laravel conventions, explicit types, constructor promotion,
single-purpose actions, Form Requests, policies, API Resources, and typed
relationships.

```php
final class RecordInvoicePayment
{
    public function handle(
        Invoice $invoice,
        Money $amount,
        PaymentMethod $paymentMethod,
        User $recordedBy,
    ): InvoicePayment {
        return DB::transaction(
            fn (): InvoicePayment => $this->recordLockedPayment(
                invoice: $invoice,
                amount: $amount,
                paymentMethod: $paymentMethod,
                recordedBy: $recordedBy,
            ),
        );
    }
}
```

Key rules:

- use `patient`, `appointmentType`, `jobOrder`, and `invoice` vocabulary;
- use curly braces for every control structure;
- provide parameter and return types;
- use PHPDoc for non-obvious generic/array shapes, not inline narration;
- use database transactions and row locks for financial/inventory writes;
- avoid compatibility aliases and unused abstractions; and
- follow sibling Filament and Pest patterns.

The illustrative `Money` type is not a dependency commitment. Existing decimal
handling may remain if Phase 2 determines that introducing a value object would
add unnecessary scope.

## Testing Strategy

### Test restoration rule

Deleting an obsolete test is allowed only when it exclusively asserts the
deleted Order/Billing behavior. A test for retained behavior must be restored
from Git history and adapted to the canonical domain or replaced by an
equivalent test with equal or stronger assertions.

Required retained coverage includes:

- appointment availability, booking, rescheduling, cancellation, duration,
  provider capacity, early closing, and concurrency;
- Appointment Type and Referral validation;
- patient intake ownership, immutability, check-in, and Encounter creation;
- patient/account factory uniqueness;
- prescription ownership, expiry, printing, and `CX` cylinder output;
- private conversations, attachments, feedback, and verified ratings;
- appointment and Job Order notifications without deleted Order relations;
- frame-only catalog and reservations;
- inventory commitment/reversal idempotency;
- Job Order, Invoice, Invoice Payment correction, dispensing, and printing;
- Filament receptionist/optometrist authorization and combined health record;
- patient isolation, rate limiting, privacy, audit, and MFA;
- seeded scheduled and account-less walk-in end-to-end journeys; and
- backup/restore and production configuration.

### Quality gates

- focused tests fail for the expected missing behavior before each repair;
- focused tests pass after each repair;
- no test is weakened merely to accept current implementation;
- route tests compare the complete method/URI set, not selected absences;
- schema tests assert both canonical presence and legacy absence;
- the final full suite has zero failures;
- raw test count is not itself the goal, but every deleted retained-behavior
  test has documented replacement coverage; and
- browser checks exercise actual Filament pages and actions with seeded roles.

## Migration Strategy

Because the application is undeployed, the recommended final state is a
coherent fresh migration history rather than a chain that creates and then
drops the obsolete commerce system.

Before consolidation:

1. inventory foreign keys and active consumers;
2. repair application code and tests;
3. prove the canonical replacement paths;
4. create a non-sensitive database backup for recovery verification; and
5. run the current forward path in an isolated test database.

After consolidation:

- fresh migration creates only canonical tables;
- no migration creates Order/Billing/customer columns merely to remove them
  later;
- migration ordering has no dependency on absent legacy tables;
- `migrate:fresh --seed` succeeds;
- the current development database is rebuilt from disposable seed data; and
- production data migration is explicitly out of scope because no production
  data exists.

## Boundaries

### Always

- update this specification first if a decision changes;
- preserve Patient Data Privacy Act boundaries and least privilege;
- inspect all consumers before deleting a model, table, route, or test;
- write/restore focused Pest coverage before implementation;
- use Sail for PHP, Artisan, Composer, and Node;
- run Pint after PHP edits;
- keep each implementation task small and reviewable;
- preserve audit history for Invoice Payment corrections and inventory
  movements; and
- record exact verification evidence.

### Ask first

- change the approved patient API capability set;
- retain the Services catalog instead of removing it;
- make payment methods database-configurable;
- add dependencies;
- change authentication, roles, privacy retention, or MFA;
- touch the separate Android repository;
- delete any table not explicitly approved by this specification; or
- perform a destructive rebuild on data that is no longer confirmed disposable.

### Never

- restore patient-created Orders, checkout, legacy Billings, or patient payment
  mutation;
- restore `customer_id` as an active compatibility path;
- silently delete retained-behavior tests to achieve a green suite;
- expose internal notes, costs, stock quantities, provider capacity, or
  optometrist-only clinical fields to patients/receptionists;
- permit receptionists to finalize clinical records;
- mutate posted Invoice Payments instead of correcting them append-only;
- edit vendor files or commit secrets; or
- mark release/browser/recovery gates complete without running them.

## Success Criteria

1. The final schema contains no Order/Billing/service-record/customer tables or
   columns.
2. No active code references missing `Order`, `Billing`, `ServiceRecord`, or
   `customer_id`.
3. All surviving relationships resolve to real canonical models and columns.
4. Billing print routes and services are removed or replaced by verified Invoice
   print behavior.
5. Daily summary, SMS, dashboard demo, conversations, feedback, factories, and
   seeders use canonical records.
6. Appointment Type is the only appointment classification and scheduling
   duration source.
7. Referral requires a referring source in API and Filament workflows.
8. Every Appointment stores its booked duration snapshot, and later
   Appointment Type edits affect future bookings only.
9. `visit_reasons`, `visit_reason_id`, and all VisitReason application files
   are absent.
10. The approved Services decision is implemented with no disconnected admin
   feature.
11. Invoice payment methods/statuses have one validated canonical contract and
    no orphan lookup schema.
12. Inventory has no legacy Order dependency and retains exact idempotent
    allocation history.
13. The exact approved patient API method/URI set passes as a complete equality
    assertion.
14. Patient API routes expose no direct order, checkout, payment mutation,
    staff-only action, internal note, cost, or stock quantity.
15. Every patient-owned resource has positive ownership and negative
    cross-patient tests.
16. Retained appointment, clinical, communication, notification, catalog,
    inventory, finance, Filament, privacy, and security behavior has restored
    regression coverage.
17. `vendor/bin/sail artisan migrate:fresh --seed --no-interaction` succeeds
    using only canonical seed data.
18. The full Pest suite, Pint, and production asset build pass.
19. Real optometrist and receptionist browser journeys pass.
20. Non-sensitive backup and restore validation passes.
21. Static scans find no unintended customer/direct-order/Billing/VisitReason
    implementation vocabulary.
22. `BACKEND_CONTEXT.md`, route inventory, schema inventory, Task 40, and
    release checkpoints match executed evidence.
23. Android integration and production/privacy governance remain explicitly
    separate from backend technical completion.

## Resolved Decisions

The project owner approved the specification on 2026-07-26, including these
recommendations:

1. Remove the disconnected Services catalog.
2. Use a fixed validated Invoice Payment method enum and remove the orphaned
   payment lookup tables.
3. Retain one authenticated private message-attachment download route in the
   exact patient API.
4. Consolidate the undeployed development migrations into a clean canonical
   history after replacement coverage passes.
5. Replace Visit Reason completely with Appointment Type.
6. Prompt mobile patients to complete the secure pre-visit Patient Health
   Record after booking without making it a condition for reserving the slot.
7. Store an Appointment duration snapshot copied from the selected Appointment
   Type so later configuration changes do not rewrite historical schedules.

## Phase 1 Approval Gate

- [x] Objective, commands, structure, code style, testing, and boundaries are
      specified.
- [x] Current contradictions and release-claim reset are explicit.
- [x] Success criteria are concrete and executable.
- [x] The project owner resolves the open questions.
- [x] The project owner approves this specification on 2026-07-26.

Phase 2 may now produce the implementation plan.
