# Spec: Single Optical Order Aggregate

## Status

Draft for project-owner review on 2026-08-03. This is Phase 1 (**Specify**) of
the spec-driven workflow. No implementation plan, task execution, destructive
database reset, or application-code change is authorized until this
specification is approved.

Once approved and implemented, this specification supersedes the active
Quotation-to-Job-Order architecture in:

- `simplified-optical-orders-and-billing-spec.md`;
- `patient-eyewear-aggregate-api-spec.md`;
- `optical-transaction-types-and-encounter-billing-spec.md`; and
- `docs/BACKEND_CONTEXT.md` wherever those documents describe Quotations or
  Job Orders as separate aggregates.

## Assumptions Requiring Approval

1. The application and Android client have not been deployed, so there is no
   production data or public API consumer requiring backward compatibility.
2. Non-production databases may be rebuilt with `migrate:fresh` during the
   implementation after an explicit destructive-command checkpoint.
3. The clinic does not need to present, expire, decline, revise, or retain a
   formal price quotation before a sale is confirmed.
4. An editable Draft Optical Order replaces both the Quotation draft and the
   pre-confirmation Job Order boundary.
5. Patients see only confirmed or later Optical Orders. Drafts are staff-only.
6. Existing product-only, service-only, mixed, immediate, prepared, billing,
   payment, reservation, inventory, dispensing, complaint, and patient-
   tracking behavior remains required.
7. Breaking removal of `/api/v1/quotations`, `/api/v1/job-orders`, and their
   detail routes is acceptable. `/api/v1/eyewear` remains the sole patient
   Optical Order tracking contract.

## Objective

Replace the current Quotation plus Job Order chain with one canonical
`OpticalOrder` aggregate that owns the commercial draft and the operational
fulfillment lifecycle.

The clinic workflow becomes:

```text
Create Draft Optical Order
    -> add products and/or services
    -> optionally attach Encounter, prescription, or frame reservation
    -> confirm sale and choose fulfillment
        -> Complete now
        -> Confirmed -> Processing -> Ready for Pickup -> Completed
    -> create Billing Record and commit applicable inventory at confirmation
    -> collect payment using the existing charge-set lock rule
```

There is no quotation presentation or patient-decision stage. Staff creates a
Draft only while assembling a transaction; confirmation means the patient has
agreed to purchase.

### Users

- Clinic staff and administrators operating the Filament panel.
- Linked patients tracking confirmed Optical Orders through the Android API.

### Desired outcome

- One model, item table, status enum, admin resource, and patient tracking
  source represent an Optical Order.
- Staff never has to understand the difference between a Quotation and Job
  Order.
- Draft commercial data becomes immutable at confirmation.
- Billing and fulfillment keep their existing separate responsibilities but
  reference the same Optical Order.
- Quotation and Job Order application code, routes, tables, transitional
  migrations, compatibility services, and obsolete tests are removed.

## Scope

### Included

- A new canonical `optical_orders` table and `OpticalOrder` model.
- A single `optical_order_items` table for product and service lines.
- Draft editing and one confirmation boundary.
- Immediate and prepared fulfillment using one status lifecycle.
- Renaming every canonical `job_order_id` relationship to
  `optical_order_id`.
- Direct integration with Billing Records, inventory movements, frame
  reservations, dispensing events, complaints, ratings, and conversations.
- One Filament Optical Orders workspace with status tabs and contextual entry
  points.
- A direct patient eyewear query sourced only from confirmed Optical Orders.
- Removal of Quotation and Job Order endpoints, resources, models, actions,
  policies, factories, seed data, and superseded tests.
- Clean pre-deployment migration history with no Quotation-to-Job-Order
  compatibility bridge.

### Out of scope

- Formal quotations, estimates, validity periods, patient approval, decline,
  expiry, revision, or PDF/email/SMS quotation delivery.
- Preserving local development records when the schema is rebuilt.
- Production data migration or a dual-read compatibility period.
- Online payment-gateway behavior.
- Supplier purchasing, cost, profit, or accounts payable.
- Changes to Encounter clinical documentation or prescription contents.
- A separate Services catalog.
- A separate Job Order or Lab Work Queue aggregate.

## Domain Model

### Optical Orders

`optical_orders` is the sole sale and fulfillment source:

```text
optical_orders
    id
    order_number                     unique
    eyewear_key                      unique
    patient_id
    encounter_id                     nullable
    prescription_id                  nullable
    frame_reservation_id             nullable, unique
    status
    fulfillment_mode                 nullable while draft
    uses_external_supplier           default false
    subtotal_amount
    discount_amount
    total_amount
    notes                            internal, nullable
    supplier_invoice_number          internal, nullable
    confirmed_by                     nullable
    confirmed_at                     nullable
    processing_started_at            nullable
    ready_at                         nullable
    completed_at                     nullable
    cancelled_by                     nullable
    cancelled_at                     nullable
    cancellation_reason              nullable
    timestamps
    soft delete
```

The model name remains specifically `OpticalOrder`, not generic `Order`, to
avoid collision with inactive legacy commerce tables and to make the domain
meaning explicit.

### Optical Order items

```text
optical_order_items
    id
    optical_order_id
    item_type                        product | service
    description
    quantity
    unit_price
    amount
    product_variant_id               nullable
    lens_category_id                 nullable
    timestamps
```

Rules:

- Draft items are editable.
- Confirmation validates and locks the order's items, discount, and totals.
- Confirmed items are never edited in place.
- Billing Record items remain immutable financial snapshots of confirmed
  Optical Order items.
- Product-only, service-only, and mixed transaction type remains derived from
  the item set.
- There is no `legacy_other` value because no production history must be
  classified or preserved.

### Status lifecycle

Persist only clinic-facing Optical Order statuses:

```text
draft
confirmed
processing
ready_for_pickup
completed
cancelled
```

Allowed transitions:

```text
draft            -> confirmed | completed | discarded
confirmed        -> processing | cancelled
processing       -> ready_for_pickup | cancelled
ready_for_pickup -> completed | cancelled
```

- **Discarded** means a Draft is soft-deleted; it is not another persisted
  business status.
- **Complete sale now** confirms and completes the order atomically, records
  the confirmation and completion timestamps, commits applicable inventory,
  creates billing, and creates a Dispensing Event only when physical products
  are handed over.
- **Prepare for pickup** enters `confirmed`, then follows Processing, Ready for
  Pickup, and Completed.
- Corrective eyewear still requires a current Patient-owned prescription before
  confirmation.
- Supplier invoice remains required before Ready for Pickup only for external
  supplier or laboratory work.
- Cancellation preserves billing and inventory audit rules already approved.

### Confirmation boundary

Confirming a Draft Optical Order performs one idempotent transaction:

1. lock and validate the Draft;
2. validate Patient, optional Encounter, prescription, and reservation
   ownership;
3. validate item types, quantities, prices, totals, and fulfillment mode;
4. commit only applicable product inventory once;
5. convert the selected frame reservation once;
6. create or reuse the open same-checkout Billing Record;
7. snapshot Optical Order items into Billing Record items;
8. recalculate billing subtotal, discount, total, paid amount, and balance;
9. record an optional deposit last after the first-payment warning and staff
   acknowledgement;
10. set the confirmed or immediate-completion state and audit it.

Repeated confirmation returns the same result without duplicating billing,
inventory movements, reservation conversion, payments, or dispensing.

### Dependent relationships

Rename operational foreign keys and relationships consistently:

| Current | Replacement |
|---|---|
| `billing_records.job_order_id` | `billing_records.optical_order_id` |
| `billing_record_items.job_order_item_id` | `billing_record_items.optical_order_item_id` |
| `inventory_movements.job_order_id` | `inventory_movements.optical_order_id` |
| `dispensing_events.job_order_id` | `dispensing_events.optical_order_id` |
| `complaints.original_job_order_id` | `complaints.original_optical_order_id` |
| `JobOrder` / `JobOrderItem` relationships | `OpticalOrder` / `OpticalOrderItem` relationships |

Frame ratings continue to originate from a Dispensing Event and eligible
physical Optical Order item. Combined Encounter and Optical Order Billing
Records remain supported.

## Admin Interface

### Navigation

Keep one **Optical Orders** navigation item. Remove Quotations and the hidden
Job Orders resource entirely.

The list provides tabs or filters for:

- Drafts;
- Confirmed;
- Processing;
- Ready for Pickup;
- Completed;
- Cancelled.

The Confirmed, Processing, and Ready tabs are the operational work queue; no
separate Lab Work Queue model or resource is required.

### Create and edit

- Staff selects a Patient and may attach an Encounter, prescription, or frame
  reservation.
- One item repeater supports Catalog Product, Lens Category, Custom Product,
  and Service lines.
- Save creates or updates a Draft.
- Only Drafts are editable.
- Drafts may be discarded without creating billing or inventory movements.

### Confirm and fulfill

The Draft detail page provides **Confirm sale** with:

- Complete sale now or Prepare for pickup;
- External lab/supplier work toggle for prepared orders;
- payment due date;
- optional deposit;
- current item and total review; and
- the approved first-payment charge-finalization warning when a positive
  deposit is entered.

After confirmation, the same detail page presents fulfillment, Billing Record,
payment, reservation, and dispensing context appropriate to the current status.

## Patient API

### Canonical routes

Retain:

```text
GET /api/v1/eyewear
GET /api/v1/eyewear/{eyewearKey}
```

Remove:

```text
GET /api/v1/quotations
GET /api/v1/quotations/{quotation}
GET /api/v1/job-orders
GET /api/v1/job-orders/{jobOrder}
```

Rename the rating command to reference the canonical item:

```text
POST /api/v1/optical-order-items/{item}/rating
```

### Visibility and response behavior

- Draft Optical Orders return no patient data and cannot be retrieved by key.
- Confirmed, Processing, Ready for Pickup, Completed, and Cancelled orders are
  patient-visible only to the linked owner.
- The response is built directly from `OpticalOrder`; there is no dual-source
  Quotation/Job Order aggregate resolution.
- Patient progress values are `confirmed`, `processing`, `ready_for_pickup`,
  `completed`, and `cancelled`.
- Items, order total, payment status, amount paid, balance, due date,
  fulfillment, and dispensing remain available.
- A Combined bill keeps `scope: overall_checkout` and the privacy-safe
  `other_clinic_charges_amount` aggregate.
- Encounter charge descriptions, clinical records, supplier invoice, internal
  notes, and Drafts remain hidden.
- Estimate-specific sections and `estimate_available`, `estimate_declined`, and
  `estimate_expired` progress values are removed.

## Migration and Removal Strategy

Because no environment is deployed and no production data must survive, use a
clean pre-deployment replacement rather than a compatibility migration:

1. Replace Quotation and Job Order creation migrations with one final
   `optical_orders` and `optical_order_items` schema.
2. Fold later eyewear-key, supplier, reservation, item-type, fulfillment, and
   billing-source schema into the canonical creation migrations.
3. Update all dependent creation migrations to use `optical_order_id` names.
4. Remove transitional Quotation-revision and Quotation-to-Job-Order migrations.
5. Remove Quotation and Job Order models, enums, actions, resources,
   controllers, policies, factories, seed paths, and behavior-specific tests
   after their Optical Order replacements pass.
6. Rebuild the non-production database with `vendor/bin/sail artisan
   migrate:fresh --seed --no-interaction` only after the destructive reset is
   explicitly approved at implementation time.
7. Audit application code, active API documentation, and current context with
   `rg` until no runtime Quotation or Job Order dependency remains.

Historical specification documents may remain as decision history but must be
marked superseded where they would otherwise appear current. Inactive legacy
`orders`, `billings`, `billing_items`, and `service_records` tables are outside
this refactor and remain subject to their own cleanup decision.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5 / Livewire 4 / Tailwind CSS 4
- Laravel Sanctum 4
- Pest 4 / PHPUnit 12
- MySQL through Laravel Sail
- No new dependency

## Commands

```text
Inspect routes:       vendor/bin/sail artisan route:list --except-vendor
Inspect migrations:   vendor/bin/sail artisan migrate:status
Reset non-production: vendor/bin/sail artisan migrate:fresh --seed --no-interaction
Focused tests:        vendor/bin/sail artisan test --compact --filter=OpticalOrder
API tests:            vendor/bin/sail artisan test --compact tests/Feature/Api/EyewearApiTest.php
Full tests:           vendor/bin/sail artisan test --compact
Format PHP:           vendor/bin/sail bin pint --dirty --format agent
Build assets:         vendor/bin/sail npm run build
```

## Project Structure

```text
app/Models/OpticalOrder.php
app/Models/OpticalOrderItem.php
app/Enums/OpticalOrderStatus.php
app/Actions/OpticalOrders/
app/Filament/Resources/OpticalOrders/
app/Services/Eyewear/
app/Http/Controllers/Api/EyewearController.php
app/Http/Resources/EyewearSummaryResource.php
app/Http/Resources/EyewearDetailResource.php
database/factories/OpticalOrderFactory.php
database/factories/OpticalOrderItemFactory.php
database/migrations/
tests/Feature/OpticalOrders/
tests/Feature/Eyewear/
tests/Feature/Api/
```

## Code Style

Use enum-backed status and explicit domain actions:

```php
enum OpticalOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case ReadyForPickup = 'ready_for_pickup';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}

final class OpticalOrder extends Model
{
    public function isEditable(): bool
    {
        return $this->status === OpticalOrderStatus::Draft;
    }
}
```

- Use TitleCase enum keys and explicit scalar values.
- Use typed parameters and return values everywhere.
- Use Eloquent relationships rather than raw foreign-key queries in consumers.
- Keep Filament schemas declarative; actions own domain mutations.
- Use patient-facing **Completed**, not **Dispensed**, as the final Order label.

## Testing Strategy

### Schema and removal tests

- A fresh migration creates only the canonical Optical Order commerce schema.
- Every dependent foreign key uses `optical_order_id` and resolves correctly.
- No `quotations`, `quotation_items`, `quotation_revisions`, `job_orders`, or
  `job_order_items` table exists.
- Active routes and runtime classes contain no Quotation or Job Order domain.

### Domain tests

- Draft creation, editing, item recalculation, and discard create no financial
  or inventory effects.
- Product-only, service-only, and mixed Drafts confirm correctly.
- Confirmation locks commercial data and is idempotent.
- Immediate and prepared fulfillment follow the approved status transitions.
- Prescription, external supplier, inventory, reservation, cancellation,
  dispensing, complaint, and rating rules still work.
- Optical-only, Encounter-only, and Combined Billing Records still reconcile.
- First-payment and deposit charge-set warnings remain enforced in admin entry
  points.

### Filament tests

- One Optical Orders resource supports all lifecycle tabs.
- Only Drafts expose edit and discard actions.
- Confirmation and fulfillment actions appear only in valid statuses.
- Contextual entry from Patient, Encounter, prescription, and frame reservation
  pre-fills the same Draft form.
- Billing and operational context remain visible without a Job Orders resource.

### API tests

- Drafts are never listed or retrievable.
- Each patient can access only their confirmed or later Optical Orders.
- Items, status, total, billing, due date, fulfillment, and dispensing serialize
  from one Optical Order source.
- Combined checkout scope and Other clinic charges remain privacy-safe.
- Removed Quotation and Job Order routes are absent.
- Rating authorization uses an eligible patient-owned Optical Order item.

Every implementation task must add or update a failing Pest test before
behavior changes. Run focused Sail tests at task boundaries and the full suite,
Pint, fresh migration with seeders, and asset build at the final checkpoint.

## Boundaries

### Always

- Search installed Laravel and Filament documentation before code changes.
- Use Laravel Sail for PHP, Artisan, Composer, and Node commands.
- Use the test-driven and incremental implementation workflows.
- Preserve the approved billing, payment, privacy, inventory, reservation, and
  clinical ownership rules.
- Keep Drafts staff-only and confirmed commercial data immutable.
- Run the fresh-schema, full test, Pint, and frontend build gates.

### Ask first

- Running `migrate:fresh` against any local database with data.
- Expanding the refactor into inactive legacy `orders` or billing tables.
- Adding formal estimates or patient approval back into the Order lifecycle.
- Changing payment, refund, tax, or official-invoice behavior.
- Adding dependencies.

### Never

- Keep Quotation or Job Order runtime adapters after the replacement is proven.
- Maintain dual-write or dual-read commerce paths in this pre-deployment app.
- Expose Drafts, supplier references, internal notes, or clinical data through
  the patient eyewear API.
- Create billing or inventory movements while an Order remains Draft.
- Edit confirmed Order items or immutable Billing Record snapshots.
- Delete tests merely to hide a regression; replace obsolete behavior tests
  with tests for the approved single-aggregate behavior.

## Success Criteria

1. Staff manages the entire transaction from one Optical Orders workspace and
   never sees a Quotation or Job Order resource.
2. One Optical Order and item table represent Draft through Completed or
   Cancelled without duplicate commercial item snapshots.
3. Drafts are editable and operationally inert; confirmation is transactional,
   immutable, and idempotent.
4. Product-only, service-only, mixed, immediate, and prepared workflows retain
   their approved billing, inventory, reservation, and fulfillment behavior.
5. Billing Records, Billing Record items, inventory movements, dispensing
   events, complaints, and ratings reference Optical Orders directly.
6. Patients track only confirmed or later Orders from one source and receive no
   estimate-specific or private clinical/supplier data.
7. Quotation and Job Order routes, models, tables, actions, resources, enums,
   factories, seed paths, and runtime references are absent.
8. A fresh seeded database, focused and full Pest suites, Pint, route audit,
   documentation audit, and frontend build all pass.

## Trade-offs Accepted by Approval

- Staff cannot save a price proposal that a patient can review before agreeing
  to purchase.
- There is no quotation validity, decline, expiry, or revision history.
- Draft deletion may create gaps in Order numbers; sequential numbers are
  identifiers, not proof of a completed sale.
- Removing direct Quotation and Job Order API routes requires the undeployed
  Android client to use only the eyewear contract.
- Local development data is disposable and will be rebuilt from migrations and
  seeders.

## Open Questions

None. Approval of this specification confirms all assumptions and trade-offs
above and authorizes Phase 2 (**Plan**) only.
