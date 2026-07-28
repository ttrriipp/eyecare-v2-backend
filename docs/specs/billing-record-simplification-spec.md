# Spec: Internal Billing Record Simplification

## Status

Approved by the project owner on 2026-07-28. Phase 1 of the spec-driven
workflow is complete. Implementation remains gated on approval of the Phase 2
plan and Phase 3 task breakdown.

Once approved and implemented, this specification supersedes references to a
digital Service Invoice in the clinic workflow specification, backend context,
API contract, and user interface.

## Confirmed Assumptions

1. Padilla Optical Clinic continues issuing its physical BIR-authorized Service
   Invoice entirely outside the system.
2. The system does not issue, reproduce, print, verify, or reference that
   physical document.
3. The physical Service Invoice number is not stored.
4. BIR-specific identity, sale-type, tax, discount-verification, printing
   authority, and customer/business fields are not stored in the internal
   billing record.
5. The system needs only an internal financial ledger for each fulfilled Job
   Order.
6. The accepted Job Order is the authoritative item and price snapshot. Billing
   does not copy its line items into a second table.
7. A Billing Record is created at dispensing, in accordance with the approved
   clinic workflow.
8. Payment entries are append-only ledger records. Corrections are explicit,
   attributed, and auditable rather than silent edits.
9. Existing data is development/seed data and may be replaced. No production
   migration compatibility is required.

## Objective

Replace the current invoice replica with a minimal **Billing Record** that helps
the clinic track how much a patient owes and has paid without implying that the
software creates or maintains the official BIR Service Invoice.

The target financial flow is:

```text
Accepted Quotation Revision
    -> Job Order
    -> ready for dispensing
    -> dispense
    -> create internal Billing Record
    -> record payments and balance
```

The Billing Record exists only for clinic operations and patient account
visibility. It is not a tax invoice, receipt, or substitute for the clinic's
physical documents.

## Terminology

Use these terms in the web panel, API documentation, mobile application, and
notifications:

- `Billing Record`
- `Billing Record Number`
- `Total Amount`
- `Amount Paid`
- `Balance Due`
- `Payment`
- `Payment Correction`
- `Void Billing Record`

Do not use:

- `Service Invoice` for a system-generated record;
- `Official Invoice Number`;
- `Issue Invoice`;
- `BIR Invoice`;
- wording that claims the system document is valid for tax or receipt purposes.

Internal PHP and database names should also migrate from `Invoice` to
`BillingRecord` so the code does not preserve a misleading domain boundary.

## Data Model

### Billing records

One Billing Record belongs to exactly one Job Order and one patient. It may
retain the encounter link for navigation and reporting.

```text
billing_records
    id
    billing_record_number       unique internal identifier
    patient_id
    job_order_id                unique
    encounter_id                nullable
    status
    total_amount
    amount_paid
    balance_due
    notes                       nullable
    recorded_by
    recorded_at
    voided_by                   nullable
    voided_at                   nullable
    void_reason                 nullable
    created_at
    updated_at
```

Statuses:

```text
unpaid
partially_paid
paid
voided
```

Rules:

- `total_amount` is copied once from the accepted Job Order total when the
  Billing Record is created.
- `amount_paid` is derived from posted, unreversed payment entries.
- `balance_due = max(total_amount - amount_paid, 0)`.
- Normal status is derived from payment totals.
- `voided` is an explicit terminal document state and requires an actor, time,
  and reason.
- A voided record remains visible in authorized history and is never reused for
  another Job Order.
- There is no draft or issued status because the system is not preparing or
  issuing an official document.

### Billing payments

```text
billing_payments
    id
    billing_record_id
    amount
    payment_method
    reference_number            nullable
    status                      posted or reversed
    recorded_by
    recorded_at
    notes                       nullable
    reversed_by                 nullable
    reversed_at                 nullable
    reversal_reason             nullable
    created_at
    updated_at
```

Rules:

- Payment amounts must be positive.
- A payment cannot cause the posted total to exceed the Billing Record total
  unless a future approved overpayment/refund specification allows it.
- Posted payments are not edited or deleted.
- A correction reverses the original entry and creates a replacement payment
  when appropriate.
- Each record and correction stores the responsible clinic user and timestamp.

### Removed structures and fields

Remove the `invoice_items` table. The linked Job Order and its items already
provide the immutable item descriptions, quantities, unit prices, and amounts.

Remove these invoice fields and concepts:

- physical or official invoice number;
- registered name;
- TIN;
- business address;
- sold-to snapshot;
- sale type;
- subtotal duplicated from the Job Order;
- discount duplicated from the accepted quotation;
- tax amount and BIR sales breakdown;
- issued status and issued timestamp;
- official invoice PDF or print layout.

## Workflow

### At Job Order creation

- No Billing Record is created.
- Inventory-linked variants are committed according to the Job Order workflow.
- The accepted quotation and Job Order preserve the commercial item snapshots.

### At dispensing

An authorized clinic user:

1. opens a Job Order in `ready_for_dispensing`;
2. confirms the recipient and dispensing notes;
3. completes dispensing;
4. the system atomically marks the Job Order `dispensed`, records the dispensing
   event, and creates the one internal Billing Record;
5. the Billing Record starts as `unpaid` unless a payment is recorded in the
   same workflow;
6. the clinic independently completes its physical BIR Service Invoice without
   entering its number or details into this system.

If the Billing Record already exists, dispensing must not create another one.

### Payments

- Authorized clinic users can record payments against a non-voided Billing
  Record.
- The system recalculates amount paid, balance due, and status atomically.
- Patients may view their Billing Record and posted payment history through the
  patient API/mobile application.
- Patients cannot create, edit, reverse, or void financial entries.

### Corrections and voiding

- Normal staff may record payments.
- Payment reversal/correction and Billing Record voiding require the existing
  approved elevated authorization.
- Every correction and void operation creates an audit event.
- Voiding does not delete the Job Order, dispensing event, or payment history.

## Patient API

Replace read-only `/invoices` terminology with versioned read-only Billing
Record endpoints:

```text
GET /api/v1/billing-records
GET /api/v1/billing-records/{billingRecord}
```

The response contains only operational fields:

```json
{
  "id": 17,
  "billing_record_number": "BR-2026-000017",
  "job_order_id": 9,
  "status": "partially_paid",
  "total_amount": "12500.00",
  "amount_paid": "5000.00",
  "balance_due": "7500.00",
  "recorded_at": "2026-07-28T17:30:00+08:00",
  "payments": [
    {
      "id": 31,
      "amount": "5000.00",
      "payment_method": "cash",
      "reference_number": null,
      "status": "posted",
      "recorded_at": "2026-07-28T17:30:00+08:00"
    }
  ]
}
```

The patient API does not expose:

- internal notes;
- staff identities;
- correction reasons;
- audit metadata;
- any BIR or physical document field;
- duplicate Job Order item details already available through the Job Order
  resource.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5 / Livewire 4
- Laravel Sanctum 4
- Pest 4 / PHPUnit 12
- Laravel Sail

No new dependency is required.

## Commands

```bash
# Start development services
vendor/bin/sail up -d

# Inspect affected routes
vendor/bin/sail artisan route:list --path=billing-records --except-vendor
vendor/bin/sail artisan route:list --path=invoices --except-vendor

# Rebuild disposable development data
vendor/bin/sail artisan migrate:fresh --seed --no-interaction

# Run focused financial and dispensing tests
vendor/bin/sail artisan test --compact tests/Feature/Invoices
vendor/bin/sail artisan test --compact tests/Feature/JobOrders
vendor/bin/sail artisan test --compact tests/Feature/Api/V1

# Format changed PHP files
vendor/bin/sail bin pint --dirty --format agent

# Run the complete regression suite
vendor/bin/sail artisan test --compact
```

## Project Structure

```text
app/Models/BillingRecord.php
app/Models/BillingPayment.php
    Internal financial ledger models

app/Actions/Billing/
    Dispensing-linked creation, payment, correction, and void actions

app/Filament/Resources/BillingRecords/
    Clinic-user billing and payment history interface

app/Http/Controllers/Api/BillingRecordController.php
app/Http/Resources/BillingRecordResource.php
    Patient-owned read-only API

database/migrations/
    Canonical billing record and payment schema

database/factories/ and database/seeders/
    Coherent unpaid, partial, paid, and voided development scenarios

tests/Feature/Billing/
tests/Feature/Api/V1/
tests/Feature/Filament/
    Lifecycle, authorization, API, and UI coverage

docs/
    Approved workflow and Android API contracts
```

## Code Style

Use domain language that makes the internal-only boundary explicit:

```php
class CreateBillingRecordAtDispensing
{
    public function handle(JobOrder $jobOrder, User $recorder): BillingRecord
    {
        // The action derives patient, total, status, and linkage from the
        // accepted Job Order; callers cannot supply those trusted values.
    }
}
```

Use constructor injection, explicit parameter and return types, Eloquent
relationships, enums for statuses, and transaction-backed action classes.
Follow existing Laravel, Filament, and Pest conventions.

## Testing Strategy

Use Pest feature tests with real factories and database transactions.

Required coverage:

1. Dispensing atomically creates one Billing Record linked to the patient, Job
   Order, encounter, recorder, and dispensing event.
2. The Billing Record total is derived from the Job Order and cannot be supplied
   by the form.
3. Repeated or concurrent dispensing cannot create duplicate Billing Records.
4. No BIR, physical invoice, tax, business identity, sale-type, or copied item
   fields exist in the canonical schema or runtime response.
5. Recording a payment recalculates amount paid, balance, and status.
6. Overpayment, negative payment, unauthorized mutation, and payment on a
   voided record are rejected without partial writes.
7. Payment correction preserves the original entry and records actor, time, and
   reason.
8. Voiding preserves the complete Billing Record, payment, Job Order, and
   dispensing history.
9. Patient APIs are ownership-scoped and hide internal/correction/audit fields.
10. Filament uses Billing Record language and contains no official/BIR invoice
    labels or print actions.
11. Seed data represents unpaid, partially paid, paid, and voided examples
    without physical invoice data.

Run focused suites after each increment and the full regression suite before
commit.

## Boundaries

### Always

- Derive patient, Job Order, encounter, and total linkage server-side.
- Create the Billing Record at dispensing in the same transaction.
- Keep payment and correction history append-only and auditable.
- Scope patient reads to the authenticated patient's records.
- Use Billing Record terminology in every user-facing surface.
- Update seed data and living API/backend documentation after implementation.
- Run focused tests, Pint, and the full regression suite.

### Ask first

- Adding any physical Service Invoice or BIR field.
- Adding tax calculation or tax-reporting behavior.
- Allowing Billing Records before dispensing.
- Supporting overpayments, refunds, credits, or write-offs.
- Changing which roles may void records or reverse payments.
- Adding dependencies or external accounting integrations.

### Never

- Store a physical Service Invoice number.
- Generate or print a document represented as the clinic's official Service
  Invoice.
- Duplicate Job Order item snapshots into Billing Record items.
- Allow patients to mutate Billing Records or payments.
- Silently edit or delete posted payments.
- Recalculate the historical Job Order or quotation from later product prices.
- Delete failed tests to make the suite pass.

## Success Criteria

1. The web panel and mobile API use Billing Record terminology exclusively for
   the internal ledger.
2. Exactly one Billing Record is created when an eligible Job Order is
   dispensed.
3. The record contains only internal linkage, total, balance, lifecycle,
   recorder, timestamps, and notes.
4. There is no physical invoice number, BIR field, copied invoice item, or
   official invoice print behavior anywhere in the runtime system.
5. The accepted quotation and Job Order remain the authoritative item and price
   history.
6. Payment entries accurately derive amount paid, balance due, and unpaid /
   partially paid / paid status.
7. Corrections and voiding preserve immutable, attributable history.
8. Patient access is read-only, ownership-scoped, and privacy-safe.
9. Canonical migrations, factories, seeders, Filament, actions, API resources,
   routes, tests, and documentation agree on the simplified model.
10. Focused suites and the complete regression suite pass.

## Resolved Decisions

1. The initial payment methods are `cash`, `gcash`, `bank_transfer`, and
   `card`, with an optional reference number for non-cash methods.
2. Patients can see individual posted payment entries for transparency.
3. An optional initial payment can be recorded during dispensing. Later
   installment payments remain available from the Billing Record page.
