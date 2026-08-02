# Spec: Simplified Optical Orders and Billing

## Status

Approved by the project owner on 2026-08-02. Phase 1 (**Specify**) of the
spec-driven workflow is complete. The Phase 2 (**Plan**) document was approved
on 2026-08-02. Phase 3 task breakdown remains subject to project-owner review
before application-code changes begin.

Once approved and implemented, this specification supersedes the active
quotation-revision workflow, billing-at-dispensing rule, and revision-dependent
patient eyewear aggregation described in older specifications and
`docs/BACKEND_CONTEXT.md`.

## Confirmed Product Decisions

1. The admin panel presents one primary **Optical Orders** workspace. A
   separate Lab Work Queue navigation item is not required.
2. The existing dedicated financial workspace becomes visible as
   **Finance -> Billing & Payments**.
3. Staff can save an editable optical-sale draft, share it as a quotation, or
   confirm it immediately as a direct sale.
4. Quotations do not have immutable versions or numbered revisions.
5. Draft and presented quotations are editable. Confirmed quotations are locked.
6. Confirming a sale creates its Job Order and Billing Record. Billing is no
   longer deferred until dispensing.
7. The Job Order must expose at least:
   - patient name;
   - Job Order number;
   - supplier invoice number;
   - lens type or types;
   - patient-facing item price and order total;
   - operational status;
   - payment status.
8. `supplier_invoice_number` is the invoice or reference supplied by the lens
   supplier or laboratory. It is not the clinic Billing Record Number and is
   never patient-visible.
9. Lens type is represented by the existing `lens_categories` catalog and
   related order items. It is not duplicated as free text on `job_orders`.
10. Price means the patient-facing selling price. Supplier cost and margin are
    outside this feature.
11. A linked patient can track status, items, total, amount paid, remaining
    balance, and payment due date through the existing patient eyewear API.
12. Draft optical sales, supplier references, supplier cost, and staff notes
    are never exposed to patients.
13. An Optical Order may contain every patient-facing product or service sold
    in the transaction: frames, lens categories, coatings, accessories,
    services, and custom charge lines. Lens type is derived from applicable
    line items and does not limit the order to lens products.

## Cost-Minimization Decisions

The implementation must prefer adapting existing working components over
building parallel replacements:

1. Keep the existing persisted Quotation status values (`draft`, `presented`,
   `accepted`, `declined`, and `expired`). Change user-facing labels and allowed
   transitions only where required; do not rename status values merely for UI
   wording.
2. Keep `OpticalOrderResource` anchored on `Quotation`; do not introduce an
   Optical Sale aggregate table or another primary resource.
3. Reuse the existing Job Order, Billing Record, payment, frame-reservation,
   inventory, and patient-eyewear services.
4. Add only one new business field: `billing_records.payment_due_date`.
   Supplier invoice, lens category, prices, balances, and stable eyewear keys
   already exist and must be reused.
5. Keep the current Job Order and patient eyewear progress enums. Filament and
   Android may present friendlier labels without creating new persisted states.
6. Keep existing `/api/v1` routes and response envelopes during this feature.
   Compatibility fields may be composed from the simplified Quotation rather
   than preserving the revision domain model.
7. Do not add a separate Lab Work Queue, notification delivery, quotation PDF,
   expected-ready forecast, supplier-cost module, or payment gateway.
8. Remove revision-specific application code and database structures after the
   deterministic backfill; do not retain unused legacy models, relationships,
   factories, and tests as permanent compatibility layers.

## Objective

Replace the enterprise-style quotation revision flow with the simpler workflow
normally used by optical practices:

```text
Editable optical-sale draft
    -> optionally share quotation with patient
    -> confirm sale
    -> create Job Order + Billing Record
    -> optionally record deposit
    -> start production
    -> ready for pickup
    -> dispense and complete
```

The interface must feel like one optical order even though quotation, Job
Order, Billing Record, payment, reservation, and inventory records remain
separate domain records internally.

### Users

- Clinic staff and administrators using the Filament admin panel.
- Linked patient accounts consuming the read-only `/api/v1/eyewear` contract.

### Desired outcome

- Staff no longer navigate among standalone Quotation, Job Order, and Billing
  Record resources to complete one optical sale.
- Staff can immediately confirm an in-person purchase without first performing
  a formal quotation-presentation step.
- Staff can still save and share a quotation when the patient needs time to
  decide.
- Patients can track the resulting order and financial balance without seeing
  supplier or internal operational data.

## Scope

### Included

- Simplified quotation storage without revisions.
- Unified Optical Orders list, filters, create flow, and detail workflow.
- Direct confirmation and optional quotation sharing.
- Job Order creation and line-item snapshotting at confirmation.
- Billing Record creation at confirmation.
- Optional initial deposit during confirmation.
- Payment due date storage, admin display, overdue indication, and patient API
  exposure.
- Supplier invoice number and derived lens-type visibility in the admin order
  workflow.
- Existing frame-reservation conversion during sale confirmation.
- Existing read-only patient eyewear aggregate updated for the simplified
  source model.
- Dedicated Billing & Payments navigation and operational page.

### Out of scope

- Native Android screen implementation; this backend repository supplies the
  API contract required by that screen.
- Patient-initiated payments.
- Online payment-gateway integration.
- Supplier cost, profit, or margin reporting.
- Supplier purchasing and accounts payable.
- Generating or storing a physical BIR invoice number.
- Email/SMS delivery of a quotation. Sharing in this phase makes the estimate
  visible to the linked patient API; delivery channels require a later spec.
- A separate lab-staff application or dedicated Lab Work Queue page.
- Expected-ready-date forecasting. `ready_at` remains the actual time an order
  is marked ready and must not be presented as a forecast.

## Domain Model

### Optical sale identity

Every optical sale begins as one `quotations` record, including a direct sale.
For a direct sale, the draft is created and confirmed during the same staff
workflow without first being shared.

The existing opaque `eyewear_key` remains stable across the Quotation and Job
Order so the patient API continues to expose one lifecycle item.

### Quotations

The Quotation itself stores the current editable commercial state:

```text
quotations
    id
    quotation_number
    patient_id
    encounter_id                 nullable
    prescription_id              nullable
    status
    valid_until                  nullable
    subtotal
    discount_amount
    total
    notes                        patient-visible when presented
    presented_by                 nullable
    presented_at                 nullable
    confirmed_by                 nullable
    confirmed_at                 nullable
    eyewear_key
    timestamps
    soft delete
```

`quotation_items` belongs directly to `quotations`:

```text
quotation_items
    id
    quotation_id
    description
    quantity
    unit_price
    amount
    product_variant_id           nullable
    lens_category_id             nullable
    timestamps
```

There is no active `quotation_revisions` model, revision number, revision
selector, or revision history in the new workflow.

### Quotation statuses

```text
draft
presented
accepted
declined
expired
```

Allowed transitions:

```text
draft     -> presented | accepted
presented -> draft | accepted | declined | expired
accepted, declined, expired -> terminal
```

Rules:

- `draft` and `presented` are editable.
- Saving commercial changes to a presented quotation returns it to `draft`; staff
  must share it again before patients see the changed estimate.
- `accepted` is the stored domain state behind the staff-facing **Confirmed**
  stage.
- Confirmation locks quotation items, prices, discount, patient,
  prescription, and total.
- A draft is excluded from every patient endpoint.

### Job Orders

`job_orders.quotation_revision_id` is replaced by a direct, unique
`quotation_id` relationship. Confirmation copies the quotation items into
`job_order_items`, which remain the operational snapshot after confirmation.

Required or derived Job Order information:

| Information | Source | Rule |
|---|---|---|
| Patient name | `job_orders.patient_id -> patients` | Required relationship; never duplicated as mutable text. |
| Job Order number | `job_orders.job_order_number` | Generated internal identifier. |
| Supplier invoice number | `job_orders.supplier_invoice_number` | Nullable during queued/in-progress; required before Ready. |
| Lens types | `job_order_items.lens_category_id -> lens_categories` | Display all distinct related lens-category names. |
| Item prices | `job_order_items.unit_price` and `amount` | Patient selling prices copied at confirmation. |
| Total price | `job_orders.total_amount` | Copied from accepted quotation total. |
| Production status | `job_orders.status` | Existing controlled status transitions. |
| Payment status | related active Billing Record | Derived; not duplicated on Job Order. |

Job Order statuses remain:

```text
queued -> in_progress -> ready_for_dispensing -> dispensed
```

`cancelled` remains terminal from an active state. The admin labels are
**Confirmed**, **In Production**, **Ready for Pickup**, **Completed**, and
**Cancelled**.

The supplier invoice number is required by the transition to
`ready_for_dispensing`, as it is today. It is hidden from patient serialization.

### Billing Records

A Billing Record is created atomically when the sale is confirmed, not when it
is dispensed. It remains the internal receivable/payment ledger and does not
claim to be a BIR invoice.

Add:

```text
billing_records.payment_due_date     nullable date
```

Rules:

- `payment_due_date` is required when confirmation leaves a positive balance.
- A fully paid sale may have no payment due date.
- The date cannot precede the confirmation date.
- The date remains recorded after payment for history.
- A record is **overdue** when it is non-voided, has a positive balance, and
  its payment due date is before the clinic's current date.
- Overdue is derived and is not another stored Billing Record status.
- Staff may update the due date on an active, unpaid or partially paid record;
  the change must be audited.
- Payments remain append-only and corrections retain the existing explicit
  reversal/correction behavior.

### Confirmation transaction

**Confirm Sale** must atomically:

1. lock and validate the editable Quotation;
2. require a patient and at least one valid item;
3. require a current prescription when the order contains corrective eyewear;
4. mark the Quotation accepted and store confirmer/time;
5. create exactly one Job Order linked directly to the Quotation;
6. copy Quotation items into Job Order items;
7. commit applicable inventory exactly once;
8. convert an attached frame reservation when selected;
9. create exactly one Billing Record with the accepted total and due date;
10. optionally record one initial deposit/payment;
11. calculate amount paid, balance due, and payment status;
12. preserve one `eyewear_key` across the lifecycle;
13. create audit events for confirmation, Billing Record creation, and any
    initial payment.

Any validation or persistence failure rolls back the entire operation.

## Admin UI and Navigation

### Navigation

```text
Optical
|- Optical Orders
|- Frame Reservations

Finance
|- Billing & Payments
```

Standalone Quotation, Job Order, and Billing Record technical resources remain
available only as internal implementation surfaces where required. They do not
appear as duplicate primary navigation items.

### Optical Orders list

The one list uses tabs or equivalent query filters:

```text
All | Drafts | Awaiting Decision | Confirmed | In Production |
Ready for Pickup | Completed
```

Mapping:

| Tab | Source state |
|---|---|
| Drafts | Quotation `draft` |
| Awaiting Decision | Quotation `presented` without Job Order |
| Confirmed | Job Order `queued` |
| In Production | Job Order `in_progress` |
| Ready for Pickup | Job Order `ready_for_dispensing` |
| Completed | Job Order `dispensed` |

Declined, expired, and cancelled records remain available through **All** and a
status filter without adding more primary tabs.

The table shows:

- current reference number;
- patient full name;
- workflow stage;
- distinct lens types;
- supplier invoice number;
- total price;
- payment status;
- remaining balance;
- payment due date;
- last activity date;
- one context-appropriate primary action.

The supplier invoice, balance, and due-date columns may use responsive hiding
on narrow screens, but remain searchable/viewable from the record.

### Create and edit experience

The page label is **New Optical Order**, not **New Quotation**.

Sections:

1. **Patient and Prescription**
   - Patient is required.
   - Prescription may be prefilled from an Encounter.
   - Corrective eyewear cannot be confirmed without a current prescription.
2. **Items**
   - Catalog frame/general item, lens category, or permitted custom service.
   - Description, quantity, unit price, and line amount.
3. **Pricing**
   - Subtotal, discount, and total.
4. **Notes**
   - Patient-visible quotation notes only.
5. **Sticky Summary**
   - Item count, subtotal, discount, and total.

Primary actions:

```text
Save Draft | Share Estimate | Confirm Sale
```

`Share Estimate` changes the status to `presented` and exposes it through the
linked patient eyewear API. It does not create a revision.

### Confirm Sale modal

Fields:

- payment due date, required when a balance will remain;
- optional initial payment amount;
- payment method and reference when an initial payment is entered;
- optional frame reservation;
- optional operational notes.

The supplier invoice number is not required at confirmation because the clinic
may not receive it until the supplier processes the order.

### Optical Order detail

The same detail page follows the record through its lifecycle. It contains:

- stage indicator;
- patient and prescription context;
- item and lens-type table;
- total, paid, balance, and payment due date;
- Job Order production information;
- supplier invoice number for staff;
- payment summary and contextual **Record Payment** action;
- activity/audit summary;
- a single primary next-step action based on the stage.

Commercial fields are editable only before confirmation. After confirmation,
staff may update operational fields such as supplier reference and notes.
Changing confirmed items or prices requires cancellation and a new optical sale
rather than silent rewriting.

### Billing & Payments page

Make the existing Billing Record resource visible under **Finance** with the
label **Billing & Payments**.

Recommended tabs:

```text
All | Outstanding | Overdue | Paid | Voided
```

The table displays Billing Record number, patient, Job Order, total, amount
paid, balance, due date, status, and recorded date. The detail page retains the
read-only linked Job Order items and the append-only payment history.

This phase changes optical-generated Billing Records. It does not introduce a
new general-purpose service-billing model.

## Patient Eyewear API

The existing endpoints remain the canonical mobile contract:

```text
GET /api/v1/eyewear?filter=current|history
GET /api/v1/eyewear/{key}
```

Only an authenticated account with an active patient link may access them.
Every query remains scoped to that linked patient.

### Patient-visible progress

Existing API progress values remain stable where possible:

| Source state | API progress | Mobile label |
|---|---|---|
| Shared quotation without Job Order | `estimate_available` | Estimate Available |
| Queued or in-progress Job Order | `in_preparation` | In Preparation |
| Ready Job Order | `ready_for_pickup` | Ready for Pickup |
| Dispensed Job Order | `dispensed` | Completed |
| Cancelled Job Order | `cancelled` | Cancelled |
| Declined quotation | `estimate_declined` | Estimate Declined |
| Expired quotation | `estimate_expired` | Estimate Expired |

Draft quotations are excluded.

### Patient-visible fields

The summary and detail resources provide:

```json
{
  "key": "eyw_01...",
  "progress": "in_preparation",
  "total_amount": "10000.00",
  "amount_paid": "3000.00",
  "balance_due": "7000.00",
  "payment_due_date": "2026-08-15"
}
```

The detail resource also includes patient-facing item snapshots:

```json
{
  "items": [
    {
      "description": "Progressive lenses",
      "quantity": 1,
      "unit_price": "6000.00",
      "amount": "6000.00"
    }
  ]
}
```

Rules:

- Money remains a two-decimal string.
- `amount_paid`, `balance_due`, and `payment_due_date` are null before a
  Billing Record exists.
- Fully paid records return `balance_due = "0.00"` and may return a null due
  date.
- Voided Billing Records are not used as the active payment summary.
- Supplier invoice number, internal notes, supplier cost, staff identities,
  and audit metadata are excluded.
- Job Order items are authoritative after confirmation; direct Quotation items
  are authoritative only for an estimate without a Job Order.

Legacy read-only Quotation, Job Order, and Billing Record endpoints remain
available during this change. Where the existing Quotation response expects a
single `revision` object, the resource may compose that compatibility object
from the Quotation's direct totals and items with `revision_number = 1`. This
does not retain revision storage or revision behavior. The aggregate endpoint
is the mobile screen's preferred contract.

## Migration and Compatibility

Implementation must use new migrations; deployed migration files are not
edited.

The migration will:

1. add direct totals and share/confirmation metadata to `quotations`;
2. add `quotation_id` to `quotation_items` and backfill it through the current
   revision relationship;
3. copy the latest revision totals and lifecycle metadata to each Quotation;
4. add direct `quotation_id` to `job_orders` and backfill it through
   `quotation_revision_id`;
5. add `payment_due_date` to `billing_records`;
6. switch application reads and writes to the direct relationships;
7. verify all migrated Job Orders and items resolve to the same patient,
   Quotation, amounts, and `eyewear_key`;
8. remove `quotation_revisions`, `quotation_revision_id`, the
   `QuotationRevision` model/factory, and all active revision-specific code and
   tests in the same implementation after that verification passes.

Database inspection on 2026-08-02 found one Quotation, one revision, one linked
Job Order, and no Quotation with more than one revision. If additional
development records exist by implementation time, the highest revision number
is the active value migrated to the simplified Quotation. The project owner's
cost-minimization approval authorizes removing older revision rows after the
specified migration verification succeeds.

The migration must not change existing Billing Record numbers, Job Order
numbers, patient ownership, payment entries, or eyewear keys.

## Tech Stack

- PHP 8.5
- Laravel 13.12
- Filament 5.6
- Livewire 4.3
- Laravel Sanctum 4.3
- MySQL through Laravel Sail
- Pest 4.7 and PHPUnit 12.5
- Tailwind CSS 4.3 through the existing Filament theme
- No new dependency is expected

## Commands

```text
Start:       vendor/bin/sail up -d
Migrate:     vendor/bin/sail artisan migrate --no-interaction
Test file:   vendor/bin/sail artisan test --compact tests/Feature/Path/To/Test.php
Test filter: vendor/bin/sail artisan test --compact --filter=descriptiveTestName
Full test:   vendor/bin/sail artisan test --compact
Format PHP:  vendor/bin/sail bin pint --dirty --format agent
Build UI:    vendor/bin/sail npm run build
Dev UI:      vendor/bin/sail npm run dev
```

## Project Structure

```text
app/Actions/OpticalOrders/        -> atomic share, confirm, cancel workflows
app/Actions/JobOrders/            -> production transitions and inventory
app/Actions/BillingRecords/       -> payment, correction, void, due-date logic
app/Enums/                        -> quotation, order, billing, patient progress
app/Filament/Resources/OpticalOrders/
                                  -> unified staff order list/detail/builder
app/Filament/Resources/BillingRecords/
                                  -> dedicated Billing & Payments workspace
app/Models/                       -> direct relationships and casts
app/Services/Eyewear/             -> patient lifecycle aggregation
app/Http/Resources/               -> patient-safe API serialization
database/migrations/              -> additive transition and approved cleanup
database/factories/               -> valid lifecycle test data
tests/Feature/OpticalOrders/       -> commercial and confirmation behavior
tests/Feature/JobOrders/           -> production and supplier-reference rules
tests/Feature/BillingRecords/      -> due date, payments, and overdue behavior
tests/Feature/Eyewear/             -> linked-patient aggregate contract
tests/Feature/Filament/            -> navigation, list, forms, and actions
docs/BACKEND_CONTEXT.md            -> authoritative context updated last
```

## Code Style

Use typed action classes for domain transitions and keep Filament closures as
thin adapters. Monetary and lifecycle mutations occur inside transactions.

```php
final class ConfirmOpticalSale
{
    public function handle(
        Quotation $quotation,
        User $confirmer,
        CarbonImmutable $paymentDueDate,
        ?InitialPaymentData $initialPayment = null,
    ): JobOrder {
        return DB::transaction(function () use (
            $quotation,
            $confirmer,
            $paymentDueDate,
            $initialPayment,
        ): JobOrder {
            // Validate and persist through focused collaborators.
        });
    }
}
```

Conventions:

- Explicit parameter and return types.
- PHP 8 constructor property promotion where dependencies are injected.
- Curly braces for every control structure.
- TitleCase enum cases and snake-case persisted values.
- Descriptive action names such as `ConfirmOpticalSale`, not `process()`.
- Eloquent relationships instead of duplicated patient or lens-type text.
- Form validation and authorization are repeated in domain actions.
- No business mutations directly inside table-page closures.

## Testing Strategy

Use Pest feature tests and Filament Livewire tests. Write or update tests before
each behavior change during implementation.

### Domain and database tests

- Draft creation and in-place item editing.
- Shared quotation editing returns it to Draft.
- Direct Draft-to-Accepted confirmation.
- Shared-to-Accepted confirmation.
- Confirmation atomically creates one Job Order and one Billing Record.
- Confirmation copies every item and total exactly once.
- Duplicate confirmation is idempotently rejected or returns the existing
  aggregate without duplicating inventory, billing, or payments.
- Corrective eyewear prescription gate.
- Supplier invoice is optional before Ready and required at Ready.
- Payment due-date validation and overdue derivation.
- Initial deposit updates amount paid, balance, and status atomically.
- Confirmation rollback on inventory, payment, reservation, or validation
  failure.
- Cancellation preserves the existing explicit payment correction rules.

### Filament tests

- Optical Orders is visible once and technical resources stay hidden.
- Billing & Payments is visible under Finance.
- Every Optical Orders tab returns the correct records.
- Required list columns and responsive detail fields render.
- Draft, Share Estimate, Confirm Sale, production, ready, payment, and dispense
  actions obey policy and status gates.
- Supplier invoice and due-date validation appear as form errors rather than
  unhandled exceptions.

### API tests

- Unlinked and other-patient accounts cannot access an order.
- Draft quotations remain invisible.
- Shared estimates expose current items without revision history.
- Confirmed orders expose status and Job Order items.
- Summary/detail expose total, amount paid, remaining balance, and due date.
- Supplier invoice and internal fields never appear.
- Existing canonical eyewear keys and pagination behavior remain stable.

During implementation, run the smallest affected test file first. Run the full
suite, Pint, and the frontend build before final completion.

## Boundaries

### Always

- Use Laravel Sail for PHP, Artisan, Composer, and Node commands.
- Search version-specific Laravel/Filament documentation before code changes.
- Use new migrations rather than editing deployed migrations.
- Preserve patient scoping and the active-link authorization boundary.
- Keep monetary changes transactional and auditable.
- Keep payment entries append-only.
- Preserve unrelated user changes in the working tree.
- Update this specification first if the approved behavior changes.
- Update `docs/BACKEND_CONTEXT.md` after implementation and verification.
- Remove superseded revision models, factories, relationships, and tests once
  their replacement behavior is covered and migration verification passes.

### Ask first

- Remove or version-break any existing `/api/v1` endpoint or response field.
- Add a dependency.
- Introduce supplier cost, accounts-payable, or purchasing behavior.
- Expand Billing Records into a general non-optical service-billing model.
- Add patient write/payment endpoints.

### Never

- Expose drafts, supplier invoices, supplier costs, internal notes, staff IDs,
  or audit metadata to patients.
- Treat the supplier invoice number as the clinic Billing Record number.
- Duplicate patient name or lens type as unsynchronized text on Job Orders.
- Create Billing Records only at dispensing after this workflow is active.
- Generate multiple Job Orders or Billing Records for one confirmed sale.
- Infer a payment due date or expected-ready date from `ready_at`.
- Silently edit posted payments or confirmed commercial terms.
- Remove failing tests merely to make the suite pass.

## Success Criteria

- [ ] One Optical Orders navigation item supports Draft through Completed stages.
- [ ] No separate Lab Work Queue, Quotation, or Job Order item duplicates the
      primary navigation.
- [ ] Billing & Payments is visible as the dedicated finance workspace.
- [ ] Staff can save a draft, share it, or confirm it directly.
- [ ] Sharing and editing do not create quotation revisions.
- [ ] No active quotation-revision table, model, relationship, factory, or UI
      remains after the verified migration.
- [ ] Confirmation creates exactly one linked Job Order and Billing Record.
- [ ] A deposit can be recorded during confirmation.
- [ ] A positive remaining balance requires a valid payment due date.
- [ ] Optical Orders display patient name, supplier invoice, lens types, price,
      production status, payment status, balance, and due date.
- [ ] Supplier invoice remains optional until the order is marked Ready.
- [ ] Corrective eyewear cannot be confirmed without a current prescription.
- [ ] Patient eyewear responses include status, items, total, paid amount,
      remaining balance, and payment due date.
- [ ] Drafts and internal/supplier fields are absent from patient responses.
- [ ] Existing payments, identifiers, patient ownership, and eyewear keys survive
      migration.
- [ ] A failed confirmation leaves no partial order, inventory, billing, or
      payment changes.
- [ ] Relevant Pest, Filament, API, migration, and end-to-end tests pass.
- [ ] Pint and the frontend production build pass.
- [ ] Authoritative documentation describes the implemented workflow.

## Open Questions

None for Phase 1. Any newly discovered conflict must be added here and brought
back to the project owner before the implementation plan is approved.
