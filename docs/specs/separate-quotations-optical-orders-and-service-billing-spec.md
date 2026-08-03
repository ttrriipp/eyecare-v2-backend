# Spec: Separate Quotations, Optical Orders, and Service Billing

## Status

Approved by the project owner on 2026-08-03. Phase 1 (**Specify**) is complete.
This approval authorizes Phase 2 (**Plan**) only; it does not authorize task
breakdown or application-code changes.

Once approved, this specification supersedes the parts of
`optical-transaction-types-and-encounter-billing-spec.md` that allow new
Service lines to become Job Order items or place Quotations and Optical Orders
in one shared table. It retains that specification's unified checkout,
itemized Billing Record, payment-lock, fulfillment, and privacy requirements
wherever they do not conflict with the boundaries below.

The prior implementation plan and task breakdown must not be executed unchanged
after this specification is approved.

## Approved Assumptions

1. Quotations and Optical Orders remain separate records and database tables.
2. The **Optical Orders** admin workspace contains separate **Orders** and
   **Quotations** sections. Its Orders table contains Job Orders only; the two
   record types are never mixed into one query or status list.
3. A Quotation may propose both products and services so the patient can review
   the expected checkout price before committing.
4. Accepting a Quotation creates an Optical Order from its Product lines only.
5. Quoted Service lines remain proposals until staff explicitly confirms which
   services were performed and should be charged. Confirmed Service lines can
   join the same open Billing Record as the Optical Order without being copied
   into Job Order items.
6. Staff may create a direct product-only Optical Order when a Quotation is not
   needed.
7. New Optical Orders represent physical optical goods and fulfillment. New
   Service lines do not drive Optical Order fulfillment statuses.
8. Encounter services and immediately completed direct services may be billed
   without an Optical Order. Retained service work requiring its own later
   fulfillment, such as a frame left for repair, is deferred to a future Work
   Order workflow.
9. There is no reusable Services catalog in this phase. Service descriptions,
   quantities, and final prices remain transaction snapshots.
10. The application has not been deployed. Existing local/demo data, legacy API
    shapes, compatibility aliases, historical mixed-Order behavior, and stale
    migration paths do not need to be preserved. Development databases may be
    rebuilt from the clean replacement schema and seeders.

## Objective

Align the data model and admin experience with four distinct clinic concepts:

- **Quotation** — a revocable proposal of products, services, prices, discount,
  and validity before commitment.
- **Optical Order** — the committed physical products that the clinic must
  prepare, hand over, or otherwise fulfill.
- **Service charge** — a performed service that staff explicitly decides to
  bill from a Quotation, Encounter, or direct checkout.
- **Billing Record** — the immutable financial checkout that may combine an
  Optical Order with one or more Service charges.

The clinic should never need to interpret a Draft or Presented Quotation as an
active Optical Order, and accepting products should never be treated as proof
that a quoted clinical or non-clinical service was performed.

### Target workflow

```text
Quotation path
    -> create Draft Quotation with Product and optional Service lines
    -> Present to patient
    -> patient agrees; staff opens Confirm Sale
    -> copy Product lines into a new Optical Order
    -> staff selects only performed quoted Services to bill now
    -> create/reuse one Billing Record for products + selected services
    -> review charge set before first payment
    -> fulfill the Optical Order independently from payment collection

Direct product path
    -> create product-only Optical Order
    -> create/reuse its Billing Record
    -> collect payment and fulfill

Encounter service path
    -> document service in the Encounter
    -> explicitly Add charges to billing
    -> reuse the same open checkout when an Optical Order is present

Direct service path
    -> enter an immediately completed Service charge in checkout
    -> create/reuse a Billing Record without creating an Optical Order
```

## Current State and Gaps

1. The database already separates `quotations` from `job_orders`, and a Job
   Order already links optionally to its source Quotation.
2. The Filament `OpticalOrderResource` is currently backed by `Quotation`, so
   its table mixes Quotation and fulfillment statuses.
3. Hidden Quotation and Job Order resources contain stale revision-era
   relationships and are not a reliable replacement workspace as-is.
4. Quotation confirmation currently copies every Product and Service line into
   `job_order_items`.
5. Billing Record items can identify Job Order item or Encounter origin, but
   cannot identify an explicitly charged Quotation Service or a direct Service.
6. The patient eyewear aggregate currently joins Quotation, Job Order, and
   Billing behind compatibility keys and aliases. With no released client, this
   aggregate can be replaced by direct Quotation and Optical Order contracts.
7. Revision-era resource code, `legacy_other`, historical mixed-Order display,
   and obsolete commerce tables add no production value and can be removed.

## Domain Boundaries

| Record | Owns | Does not own |
|---|---|---|
| Quotation | Proposed Product and Service lines, validity, proposal notes, checkout-level discount | Inventory commitment, fulfillment status, proof that a Service occurred, payments |
| Optical Order (`job_orders`) | Committed Product snapshots, prescription context, inventory commitment, reservation conversion, preparation/pickup state | Service charge lines, clinical documentation, payment ledger |
| Billing Record | Immutable Product and Service charge snapshots, discount, total, due date, payments, balance | Inventory or clinical workflow state |
| Encounter | Clinical documentation and Encounter-originating Service context | Product fulfillment and automatic billing |

### Quotation rules

- Statuses remain `draft`, `presented`, `accepted`, `declined`, and `expired`.
- Draft and Presented Quotations are editable under the current rule that an
  edited Presented proposal returns to Draft and must be presented again.
- Draft Quotations are staff-only. Presented and terminal Quotations are
  patient-readable through the replacement active-link API.
- Product lines may reference a Product Variant or Lens Category. Service lines
  have no catalog relationship.
- A Quotation may be Product-only, Service-only, or Mixed. That describes the
  proposal, not the resulting Optical Order.
- A checkout-level Quotation discount remains a Billing concern. Service unit
  prices are final transaction prices; no automatic service-discount allocation
  is introduced.
- A Service-only Quotation may be accepted without creating an Optical Order.
  Staff may then explicitly charge its performed Service lines.

### Confirmation and conversion rules

The Confirm Sale transaction must be atomic and idempotent:

1. Lock the Quotation and validate that it is Draft, Presented, or already
   Accepted for a safe retry.
2. Mark it Accepted once.
3. If Product lines exist, create one linked Optical Order and copy only those
   Product lines into `job_order_items`.
4. Do not create an Optical Order when the accepted Quotation has no Product
   lines.
5. Show quoted Service lines in a **Services to bill now** checklist. Nothing is
   selected merely because the Quotation was accepted.
6. Snapshot selected performed Services directly into the open Billing Record
   with Quotation provenance and Encounter provenance when applicable.
7. Snapshot Optical Order Product items into the same Billing Record.
8. Apply the accepted Quotation's checkout-level discount once, recalculate the
   bill, set the due date, then optionally record the deposit last.
9. Convert a selected Frame Reservation and commit catalog inventory only for
   copied Product lines.
10. A retry creates no duplicate Order, Billing item, inventory movement,
    payment, or reservation conversion.

An unselected quoted Service remains visible as **Not billed** on the
Quotation. Staff can add it later from the Quotation, Encounter, or checkout
while the target Billing Record has no posted payment. Staff never retypes the
quoted description or price unless intentionally creating a different direct
Service charge.

### Optical Order rules

- The `job_orders` table and `JobOrder` model remain the canonical fulfillment
  implementation because they accurately represent clinic work to be prepared
  or dispensed. The admin and API terminology is **Optical Order**.
- New Job Order items must have Product type. Domain validation rejects new
  Service items; `legacy_other` is removed from the new schema and enums.
- Corrective Product lines still require the patient's current prescription.
- Direct Optical Orders accept Product lines only and use final entered prices.
- Immediate and prepared fulfillment modes remain supported.
- Prepared orders keep **Confirmed -> Processing -> Ready for Pickup ->
  Completed** user-facing stages. Immediate orders complete without artificial
  intermediate stages.
- Supplier invoice is required before Ready for Pickup only for external
  supplier/laboratory work.
- Inventory is committed once for catalog-backed Product lines. Lens categories
  and non-stock custom Product lines do not create fake inventory movements.

### Service charge rules

- Service lines are financial transaction snapshots, not reusable catalog
  entries.
- A quoted Service becomes chargeable only through explicit staff selection.
- An Encounter Service is added explicitly from the separate Encounter Charges
  section; completing an Encounter never bills it automatically.
- An immediate direct Service may be added to Billing without a Quotation,
  Encounter, or Optical Order. The Billing item itself is its canonical
  transaction snapshot.
- A service requiring later retained-work tracking is outside this phase and
  must not be disguised as a Product or fake Encounter.
- The UI shows already billed and already included quoted services before staff
  adds another charge. It does not use free-text similarity to guess duplicates.

### Billing and payment rules

- Billing Records remain the only receivable and payment ledger.
- A bill may contain only Optical Order products, only Services, or both.
- Admin source labels become **Optical Order**, **Services**, or **Combined**.
  Service lines may additionally show **Quotation**, **Encounter**, or
  **Direct** provenance.
- Billing Record items are immutable snapshots. Corrections remain
  void-and-reissue operations.
- The charge set stays open only while the bill is non-voided and has no posted
  payment.
- Before the first payment or deposit, every admin entry point shows the current
  charge summary and requires acknowledgement of this warning:

  > Recording this payment will finalize the charges on this bill. Add all
  > expected Optical Order and Service charges first.

- After a payment is posted, a later Service creates a new Billing Record rather
  than reopening the paid or partially paid checkout.
- Quotation-level discount is represented once on Billing. The financial
  reconciliation is:

```text
Optical Order Product charges
+ Service charges
- checkout discount
= Billing Record total
```

## Proposed Data Contract

The implementation plan may refine names, but it must preserve these semantics:

### Clean canonical tables

- `quotations`
- `quotation_items`
- `job_orders`
- `job_order_items`
- `billing_records`
- `billing_record_items`
- `billing_payments`

### Additive Billing provenance

Add nullable Quotation provenance and a controlled item-source classification:

```text
billing_records.quotation_id               nullable
billing_record_items.quotation_item_id     nullable
billing_record_items.source_kind           required

source_kind:
    optical_order
    quotation
    encounter
    direct_service
```

Rules:

- `optical_order` requires `job_order_item_id`.
- `quotation` requires `quotation_item_id` and is valid only for a Service
  charge that staff explicitly selected.
- `encounter` requires `encounter_id` and a Service item type.
- `direct_service` has no operational source foreign key and requires a Service
  item type.
- A unique Billing Record plus Quotation Item constraint makes selected quoted
  Service snapshotting idempotent.
- A Billing Record may use its Quotation link without an Optical Order for an
  accepted Service-only proposal.
- Application validation requires a new Billing Record to contain at least one
  item before the transaction commits.

## Admin UI and Navigation

Keep one sidebar entry: **Optical Orders**.

The workspace has two distinct routes or page-level sections presented as
tabs:

```text
Optical Orders
[ Orders ] [ Quotations ]
```

### Orders section

- Default section on entry.
- Queries `JobOrder` only.
- Filters: Confirmed, Processing, Ready for Pickup, Completed, Cancelled.
- Shows Order number, Patient, Product summary, fulfillment status, source
  Quotation when present, balance, due date, and scheduled/updated time.
- Provides **New direct order** for product-only transactions.
- Order details group Product items, fulfillment, linked records, and Billing.

### Quotations section

- Queries `Quotation` only.
- Filters: Draft, Presented, Accepted, Declined, Expired.
- Provides **New quotation**.
- Shows Product and Service proposal lines together.
- Accepted rows link to their generated Optical Order when one exists and to
  their Billing Record.
- Mixed or Service-only rows show which quoted Services are billed versus not
  billed without implying that acceptance performed them.

### Confirm Sale

The confirmation UI presents separate groups:

```text
Products to order
    Frame
    Progressive lenses

Services to bill now
    [ ] Eye examination
    [ ] Frame fitting

Checkout
    Product charges
    Selected service charges
    Discount
    Total
    Due date
    Optional deposit
```

Product rows are copied automatically because they are the accepted goods.
Service checkboxes require an explicit staff choice. A positive deposit also
requires the existing reviewed-charge acknowledgement.

## Patient API Behavior

- The unreleased mobile contract is replaced rather than versioned in parallel.
- The canonical read routes are:

```text
GET /api/v1/quotations
GET /api/v1/quotations/{quotation}
GET /api/v1/optical-orders
GET /api/v1/optical-orders/{opticalOrder}
```

- The old `/api/v1/job-orders`, `/api/v1/eyewear`, and patient
  `/api/v1/billing-records` routes, `eyw_*` keys, and `jo_{id}` aliases are
  removed. No adapter or deprecation window is required.
- Draft Quotations remain hidden. Presented, Accepted, Declined, and Expired
  Quotations remain read-only to the linked patient.
- Quotation responses may show both proposed Product and Service descriptions
  because they are part of the proposal intentionally presented to the patient.
- Optical Order responses show Product items and fulfillment only.
- A Quotation response links to its generated Optical Order when one exists;
  an Optical Order response links back to its source Quotation when present.
- An accepted Service-only Quotation remains a Quotation/Billing transaction and
  does not appear as a fabricated Optical Order. Its Billing Record remains
  staff-facing in this phase.
- Optical Order payment summaries remain order-focused. For a Combined bill,
  they expose only aggregate `other_clinic_charges_amount`, checkout discount,
  overall total, paid amount, balance, and due date—not Service descriptions
  copied from Encounter, Direct, or Billing provenance.
- Quotation endpoints may show a quoted Service description, but the Optical
  Order response must not imply it was performed or billed unless the payment
  summary reflects an actual immutable Billing item.
- Encounter findings, histories, prescriptions, measurements, supplier invoice
  numbers, and internal notes remain hidden.

## Replacement and Reset Strategy

1. Treat every current database as a disposable local, testing, or demo
   database. No production migration or historical-data backfill is required.
2. Replace the migration history with one coherent schema path for Quotations,
   product-only Job Orders, Billing provenance, and payments.
3. Remove `legacy_other`, revision-era columns/tables, obsolete `orders`,
   `billings`, `billing_items`, and `service_records` schema, and their unused
   application code rather than retaining adapters.
4. Remove old Job Order/eyewear API routes, resources, aggregate services,
   compatibility keys, aliases, tests, and documentation in the same change.
5. Rebuild factories and seeders for the replacement schema.
6. Recreate local databases with `migrate:fresh --seed` after the replacement
   migration set is ready and reviewed.
7. The replacement must still preserve unrelated patient, clinical,
   appointment, inventory, authentication, and privacy behavior.
8. Do not run a destructive reset against any database unless its environment
   is verified as local or testing.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5 / Livewire 4 / Tailwind CSS 4
- Pest 4 / PHPUnit 12
- MySQL through Laravel Sail
- No new package dependencies

## Commands

```text
Inspect routes:  vendor/bin/sail artisan route:list --except-vendor
Reset database:  vendor/bin/sail artisan migrate:fresh --seed --no-interaction
Focused tests:   vendor/bin/sail artisan test --compact --filter=OpticalOrder
Billing tests:   vendor/bin/sail artisan test --compact --filter=BillingRecord
API tests:       vendor/bin/sail artisan test --compact --filter=OpticalOrder
Full tests:      vendor/bin/sail artisan test --compact
Format PHP:      vendor/bin/sail bin pint --dirty --format agent
Build assets:    vendor/bin/sail npm run build
```

## Project Structure

```text
app/Actions/Quotations/                 Quotation lifecycle and confirmation
app/Actions/OpticalOrders/              Direct Order and fulfillment actions
app/Actions/BillingRecords/             Charge snapshot and payment actions
app/Filament/Resources/OpticalOrders/   Orders workspace backed by JobOrder
app/Filament/Resources/Quotations/      Quotation section within the workspace
database/migrations/                    Clean replacement schema
tests/Feature/Quotations/               Proposal and conversion behavior
tests/Feature/OpticalOrders/            Product fulfillment behavior
tests/Feature/BillingRecords/           Unified checkout and payment behavior
tests/Feature/Filament/                 Admin workspace behavior
tests/Feature/Api/                       Mobile contract regression coverage
docs/specs/                              Specification, plan, and tasks
```

## Code Style

Use typed actions for state transitions and keep Filament declarative:

```php
final class ConfirmQuotationSale
{
    /**
     * @param  array<int, int>  $performedServiceItemIds
     * @return array{quotation: Quotation, optical_order: ?JobOrder, billing_record: BillingRecord}
     */
    public function handle(
        Quotation $quotation,
        User $confirmer,
        array $performedServiceItemIds,
    ): array {
        // Transactional orchestration belongs here, not in the Filament page.
    }
}
```

- Use explicit return types and parameter types.
- Use constructor property promotion for injected dependencies.
- Use TitleCase enum cases and descriptive method names.
- Use Eloquent relationships and action classes rather than duplicating domain
  mutations in Filament callbacks.
- Use PHPDoc for array shapes; avoid inline comments except for unusually
  complex reconciliation logic.

## Testing Strategy

### Schema replacement

- A fresh database creates only the approved canonical commerce tables and
  constraints.
- No revision, `legacy_other`, inactive commerce, eyewear-key, or compatibility
  schema remains.
- Factories and seeders create valid Product Orders, Service charges, Combined
  bills, payments, and fulfillment records on the fresh schema.

### Domain tests

- Product-only Quotation acceptance creates one product-only Optical Order and
  one bill.
- Mixed Quotation acceptance copies only Products into the Optical Order.
- Selected performed quoted Services join the same bill exactly once.
- Unselected quoted Services remain unbilled and can be added later before the
  first payment.
- Service-only Quotation acceptance creates no Optical Order.
- Direct product Order and direct Service checkout work without fake records.
- Corrective Products still require a current prescription.
- Only Product items commit inventory.
- Confirmation is atomic and idempotent under retries and concurrency.
- The first payment locks further charges and requires the reviewed-charge
  acknowledgement.

### Filament tests

- Orders and Quotations sections query only their respective models.
- Orders is the default section and contains no Draft/Presented records.
- Confirm Sale separates Product rows from explicit Service checkboxes.
- Direct Order accepts Products only.
- Quotation and Order detail links preserve traceability.
- Billing groups Product and Service charges and shows correct provenance.

### API tests

- Replacement routes enforce ownership, pagination, and stable response
  envelopes.
- Draft Quotations remain hidden.
- Optical Orders serialize Product items only.
- Service-only accepted Quotations do not fabricate Optical Order fulfillment.
- Combined payment summaries reconcile aggregate Service charges and discount
  without exposing clinical or internal data.
- Removed routes return 404 and no compatibility alias resolves.

Every implementation task must begin with a failing or characterization Pest
test and run the smallest relevant Sail test command. Full Pest, Pint, migration,
and frontend build gates run before completion.

## Boundaries

### Always

- Search installed-version Laravel and Filament documentation before code
  changes.
- Use Laravel Sail for PHP, Artisan, Composer, and Node commands.
- Validate Product/Service separation in domain actions, not only in forms.
- Keep confirmation, inventory, billing, and optional deposit in one database
  transaction.
- Require explicit staff confirmation before a quoted Service becomes a charge.
- Add Pest coverage for every behavior change and run Pint after PHP edits.

### Ask first

- Introducing a reusable Services catalog or Service/Work Order resource.
- Automatically billing a quoted Service on proposal acceptance.
- Renaming persisted Job Order tables, identifiers, or status values.
- Changing discount allocation, tax, refund, or official-invoice behavior.
- Resetting any database not verified as local or testing.

### Never

- Put a Service line into `job_order_items`.
- Treat Quotation acceptance as proof that a Service was performed.
- Create a fake Product, Encounter, Appointment, or Optical Order to source a
  Service charge.
- Append charges after a posted payment or edit immutable Billing items.
- Leak clinical, supplier, or internal data through patient Optical Order
  responses.
- Run `migrate:fresh`, truncate, or delete data in a production or unidentified
  environment.
- Keep dead compatibility code or tests after the replacement consumers are
  complete.

## Success Criteria

1. The Optical Orders admin table contains only real Job Orders and fulfillment
   statuses.
2. Quotations remain accessible in a separate section of the same workspace and
   retain their proposal lifecycle.
3. New Optical Orders contain Product items only.
4. Mixed Quotation confirmation creates one product-only Optical Order while
   billing only the Service lines explicitly confirmed as performed.
5. Product and Service charges can share one Billing Record without making the
   Service part of Order fulfillment.
6. Service-only and direct Service billing create no fake Optical Order.
7. Inventory, prescription, reservation, fulfillment, and supplier requirements
   apply only where operationally relevant.
8. First-payment charge locking and acknowledgement remain enforced.
9. No revision-era, `legacy_other`, inactive commerce, eyewear aggregate, or
   compatibility API implementation remains.
10. Patient APIs distinguish proposals, Product fulfillment, and aggregate
    Service charges without exposing protected clinical or internal details.
11. Focused tests, the full Pest suite, Pint, migration checks, and frontend
    build pass.

## Open Questions

No unresolved product question remains for Phase 1. Phase 2 may refine internal
class and column names, but it may not change the approved record boundaries or
automatic-versus-explicit charging rule without returning to this approval gate.

## Phase 1 Approval Gate

Project-owner approval of this specification authorizes Phase 2 (**Plan**) only.
It does not authorize task breakdown or application-code implementation.
