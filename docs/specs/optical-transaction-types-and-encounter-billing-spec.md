# Spec: Optical Transaction Types and Encounter Billing

## Status

Approved by the project owner on 2026-08-03. Phases 1 (**Specify**) and 2
(**Plan**) of the spec-driven workflow are complete. Phase 3 (**Tasks**) remains
subject to project-owner review before application-code changes.

Once approved, this specification extends
`simplified-optical-orders-and-billing-spec.md`. It supersedes older service
billing and unified-visit-billing documents wherever they conflict with the
source boundaries below.

## Approved Requirements

1. The existing Optical Order aggregate and shared item-entry form support
   product-only, service-only, and mixed product plus service transactions.
   A standalone service performed and charged from an Encounter does not need
   an Optical Order or Job Order.
2. A generalized Service, including an eye examination, may be charged from an
   Encounter or included as an Optical Order line. A charge does not replace
   required clinical documentation.
3. Encounter and Optical Order charges completed in the same checkout share
   one Billing Record. A later transaction creates a new Billing Record after
   the earlier one has received a payment or been voided. Before the first
   payment or deposit, staff must be warned that posting it finalizes the bill's
   charge set and must explicitly confirm that the charges were reviewed.
4. Billing & Payments presents one itemized receivable regardless of whether
   its charges originated from an Encounter, an Optical Order, or both.
5. Completing an Encounter does not automatically create a bill. Staff must
   explicitly review and issue its clinical charges because some encounters
   and follow-ups may be free.
6. Services are typed transaction lines rather than reusable catalog records.
   Staff enters the service description and price in the Encounter or Optical
   Order where it is charged.
7. Encounter-only Billing Records remain outside the mobile API in this phase.
   When a combined Billing Record is linked to a patient-visible Optical Order,
   the eyewear response remains order-focused and labels its payment figures as
   the overall checkout balance. It exposes only an aggregate **Other clinic
   charges** amount for Encounter-originating charges, without descriptions,
   counts, origin metadata, or clinical data.
8. Existing canonical Quotation, Job Order, Billing Record, payment, and
   patient-eyewear infrastructure will be adapted. The inactive legacy
   `orders`, `billings`, `billing_items`, and `service_records` domains will not
   be revived.

## Objective

Support the three commercial transaction shapes needed by the clinic without
creating three competing workflows:

1. **Product-only** — frames, lenses, accessories, contact lenses, solutions,
   and other retail goods.
2. **Service-only** — examination, frame adjustment, repair, fitting, cleaning,
   or another billed service.
3. **Mixed sale** — products and services on one Optical Order.

Allow staff to add services from an Encounter and consolidate them with a
same-checkout Optical Order into one itemized Billing Record.

The resulting UI must let staff understand what was sold, what operational
work remains, what the patient owes, and whether a charge came from an
Encounter or an Optical Order without navigating through technical resources.

### Desired workflow

```text
Optical transaction
    -> choose patient and optional Encounter/prescription context
    -> add typed product and/or service lines
    -> save/share quote or confirm sale
    -> choose Complete now or Prepare for pickup
    -> create Job Order + create/reuse same-checkout Billing Record
    -> review all charges before posting the first deposit or payment
    -> collect payment according to the existing ledger

Encounter transaction
    -> document the consultation in the Encounter wizard
    -> review Clinical Charges in a separate Encounter card
    -> add charges to a new or same-checkout Billing Record
    -> show services already included by a linked Optical Order
    -> review all charges before posting the first payment
    -> collect payment according to the existing ledger
```

## Scope

### Included

- Explicit product and service classification for new Optical Order lines.
- Product-only, service-only, and mixed Optical Orders in the same form.
- Free-text service transaction lines with explicit prices.
- Immediate-completion and prepare-for-pickup fulfillment choices.
- Conditional supplier/lab invoice requirement only for transactions marked as
  involving external supplier or laboratory work.
- Canonical item snapshots on every Billing Record.
- Encounter-originating service charges.
- Same-checkout consolidation of Encounter and Optical Order charges.
- Itemized Billing Record subtotal, discount, total, payments, balance, and due
  date.
- Unified Billing & Payments list with Encounter, Optical Order, and Combined
  source labels and filters.
- Safe classification and item backfill for existing canonical records.
- Existing Optical Order mobile tracking behavior for product, service, and
  mixed orders.
- A first-payment warning and explicit staff acknowledgement at every admin
  entry point that can post the initial payment or deposit.
- A privacy-safe aggregate of Encounter-originating charges for a patient-
  visible Combined checkout.

### Out of scope

- Patient creation of sales, services, or clinical bills.
- Mobile exposure of Encounter-only Billing Records.
- Online payments or payment-gateway integration.
- Supplier purchasing, supplier cost, profit, margin, or accounts payable.
- Service packages, subscriptions, time-based billing, insurance, or claims.
- A reusable Services catalog or automatic service-to-Encounter policy rules.
- Tax computation or representing a Billing Record as a BIR Service Invoice.
- Automatic clinical billing when an Encounter is completed.
- Reintroducing any legacy order, billing, or service-record application model.

## Domain Rules

### Optical Order line types

Every newly created Quotation item must have one of these business types:

| Type | Examples | Allowed references |
|---|---|---|
| `product` | Frame, lens category, accessory, solution | Optional product variant or lens category |
| `service` | Examination, repair, adjustment, fitting, cleaning | No catalog relationship |
| `legacy_other` | Historical unclassified custom line | Backfill only; cannot be selected for a new line |

An Optical Order's transaction type is derived, not stored:

| Lines present | Derived transaction type |
|---|---|
| Products only | Product-only |
| Services only | Service-only |
| Products and services | Mixed |

Derivation avoids an order-level flag drifting out of sync with its items.
Every service line stores its description, quantity, unit price, and amount in
the source transaction. There is no reusable Service ID and no automatic rule
that decides whether an Encounter is required. Once snapshotted, later entries
with the same description do not modify historical lines.

### Service routing

Services are not permanently divided into clinical and optical types. Their
transaction context determines the record path:

- A standalone service performed during an Encounter may be charged directly
  from that Encounter without creating an Optical Order or Job Order.
- A service sold with products, performed as part of an order, or requiring
  preparation, retained work, or pickup may be an Optical Order line.
- An examination bundled with eyewear may be documented in the Encounter and
  priced once in the Optical Order, but the application does not enforce that
  linkage from the service description.
- A non-clinical immediate service without an Encounter, such as a frame
  adjustment, may use an immediate-completion Optical Order.

### Fulfillment

Line type does not determine fulfillment. A product can be handed over
immediately, while a repair service may require later pickup. At confirmation,
staff chooses one order-level fulfillment mode:

1. **Complete sale now** — for an accessory handed over, an adjustment
   completed immediately, or another transaction with no remaining work.
2. **Prepare for pickup** — for corrective eyewear, retained repairs, custom
   preparation, or any transaction requiring operational follow-through.

Rules:

- Corrective eyewear defaults to **Prepare for pickup** and still requires the
  patient's current prescription before confirmation.
- Immediate completion commits applicable inventory once, creates the Billing
  Record, and completes the Job Order without forcing artificial Processing or
  Ready-for-Pickup steps.
- Prepare-for-pickup orders follow the existing staff-facing stages:
  **Confirmed -> Processing -> Ready for Pickup -> Completed**.
- The persisted Job Order status enum may remain unchanged; **Processing** is
  the user-facing label for the existing `in_progress` state.
- Supplier invoice number remains available for all prepared work, but becomes
  required before Ready for Pickup only when staff marks the order as external
  supplier/laboratory work. It is not required for an in-house repair or an
  immediate transaction.
- Job Order status describes the transaction's remaining fulfillment, not the
  completion state of every individual line. An examination may already be
  performed while the same mixed order remains Processing for its eyewear.
- Service-only orders do not create inventory movements. Product and mixed
  orders commit only catalog-backed inventory items, exactly once.

### Billing Record checkout context

A Billing Record is the patient's receivable for one checkout. It may contain
charges from either or both operational sources:

| Context | Relationships | Meaning |
|---|---|---|
| Encounter only | `encounter_id` | Services charged during the visit without an Optical Order |
| Optical Order only | `job_order_id` | Product and/or service sale without an Encounter |
| Combined | `encounter_id` and `job_order_id` | Encounter and Optical Order charges paid in one checkout |

Rules:

- At least one source relationship is required; both are valid.
- Every related Encounter, Job Order, and Billing Record must belong to the
  same Patient.
- A Job Order may retain historical voided Billing Records but has at most one
  non-voided Billing Record.
- An unpaid Billing Record with no posted payment may receive charges from the
  other source during the same checkout.
- Once any payment is posted or the Billing Record is voided, its charges are
  locked. A later sale or service creates a new Billing Record.
- Before any admin action posts the first payment or deposit, it shows the
  current itemized total and warns: **Recording this payment will finalize the
  charges on this bill. Add all expected Encounter and Optical Order charges
  first.** Staff must explicitly acknowledge the review. The warning is not
  required for later payments because the charge set is already locked.
- Encounter completion alone does not create a bill. The first explicit charge
  action or Optical Order confirmation creates the Billing Record; the other
  source reuses it only while it remains open for charges.
- The UI derives the source label as **Encounter**, **Optical Order**, or
  **Combined**.

### Billing Record items

Introduce canonical immutable charge snapshots:

```text
billing_record_items
    id
    billing_record_id
    item_type              product | service | legacy_other
    description
    quantity
    unit_price
    amount
    job_order_item_id      nullable
    encounter_id           nullable
    timestamps
```

Add these summary fields to `billing_records`:

```text
subtotal_amount
discount_amount
```

Rules:

- `total_amount = max(subtotal_amount - discount_amount, 0)`.
- Optical confirmation snapshots all Job Order items and the accepted
  Quotation discount into the Billing Record.
- `discount_amount` is one checkout-level value copied from an accepted
  Quotation when present. Encounter-originating services use their entered
  transaction prices and do not overwrite that discount.
- Clinical issuance snapshots the selected services and entered prices
  directly into the same-checkout Billing Record.
- A Billing Record item identifies its operational origin through either its
  Job Order item or Encounter relationship. Its snapshotted description
  identifies what was charged.
- The Encounter charges card shows service lines already included by a linked
  Optical Order so staff can avoid charging the same performed service twice.
  With no catalog identity, the application does not guess that two differently
  entered descriptions represent the same service.
- Existing item snapshots are read-only. The system may append the other
  source's confirmed items only while the Billing Record has no posted payment;
  corrections use void-and-reissue rather than silently rewriting history.
- Payments remain append-only and continue to determine amount paid, balance,
  and the existing unpaid/partially-paid/paid states.
- A positive remaining balance requires a payment due date. The form may
  default it according to the clinic's existing policy, but staff confirms it
  before issuance.
- Voiding a Billing Record does not erase its items.

### Encounter charges

- Clinical Charges is a separate card or panel on the Encounter page, outside
  the clinical documentation wizard.
- It is available while an Encounter is in progress and after completion to
  authorized staff.
- Staff enters one or more service descriptions, quantities, and prices,
  reviews the financial summary, sets the due date when needed, and explicitly
  chooses **Add Charges to Billing**. The Encounter context identifies their
  origin.
- The action requires at least one positive or intentionally zero-priced
  service line and creates or reuses the open same-checkout Billing Record with
  its items atomically.
- Encounter completion never triggers the action automatically.
- Service lines already included by the linked Optical Order are shown for
  comparison. Staff must not enter the same performed service again.
- An examination may be included in an Optical Order. The Optical Order line
  records its commercial charge while a related Encounter, when applicable,
  stores the clinical documentation.
- If the existing Billing Record has a posted payment or is voided, subsequent
  charges create a new Billing Record instead of reopening it.

## Admin UI and Navigation

### Optical Orders

Keep one primary Optical Order form. The Items repeater offers:

- Catalog Product;
- Lens Category;
- Custom Product;
- Service.

Each row shows its Product or Service badge. The form and detail page show the
derived transaction type as secondary context, not as another required field.

The Confirm Sale action adds a compact Fulfillment section:

- **Complete sale now**;
- **Prepare for pickup**;
- **External lab/supplier work** toggle, visible for prepared orders;
- existing due date and optional deposit fields.

When a positive deposit is entered, the confirmation modal shows the current
charge summary and first-payment warning, and requires staff to acknowledge
that all expected charges were reviewed. The same safeguard applies if the
first payment is collected during immediate completion or pickup.

No separate Service Order, Product Sale, or Mixed Sale resource is added.
Contextual entry points may prefill the same form from an Encounter, Patient,
Prescription, or Frame Reservation.

### Encounter

The Encounter page keeps the consultation/examination wizard as its own main
section. A separate **Charges** card shows:

- services already included in a linked Optical Order;
- **Add charges to billing** with a concise service-line form for remaining
  charges; and
- the current same-checkout Billing Record number, grouped item summary, total,
  paid, balance, due date, status, and **View billing** link.

Billing is not another clinical wizard step and does not block saving clinical
documentation.

### Billing & Payments

The existing dedicated workspace remains the only financial navigation item.

The list shows:

- Billing number;
- Patient;
- Source: **Optical Order**, **Encounter**, or **Combined**;
- Encounter and/or Optical Order references;
- short item summary;
- total, paid, balance, due date, and status.

Filters include source and payment status. The detail page groups charges by
origin, reads them from `billing_record_items`, and retains the existing payment
and void actions. It must not assume every bill has only one source.

The Record Payment action shows the itemized total and the charge-set warning
before the first posted payment. Its acknowledgement is required only for that
first payment; later payments use the normal concise payment form.

### Settings

No Services resource or new Settings navigation item is added. Service names
and prices are entered in the transaction where they are charged.

## API Behavior

- Existing `/api/v1/eyewear` routes and response envelopes remain stable.
- Product, service, and mixed order items use the same patient-visible
  item serialization already used for Optical Orders.
- Immediate orders appear as completed history; prepared orders retain normal
  tracking stages.
- `total_amount` remains the Optical Order total derived from its accepted
  Quotation or Job Order, not the Combined Billing Record total.
- Payment status, amount paid, remaining balance, and due date come from the
  related Billing Record. For a Combined bill, they represent the **overall
  checkout**, not an allocated eyewear-only balance.
- Internal item classification may be added additively if the Android client
  needs it; existing clients must continue to render description, quantity,
  unit price, and amount without requiring the new field.
- For a Combined bill, `payment_summary` includes the additive scope value
  `overall_checkout`, the Combined billing total, and
  `other_clinic_charges_amount` so the mobile client can label and reconcile
  the balance accurately. Optical-only summaries do not need the aggregate.
- `other_clinic_charges_amount` is the sum of Encounter-originating Billing
  Record items. Under the approved discount rules it must reconcile as
  `order total + other clinic charges = checkout total`.
- The aggregate does not include descriptions, item counts, origin identifiers,
  or clinical metadata. Encounter-originating item descriptions remain absent.
- The mobile client labels the existing top-level total as **Eyewear order
  total**, the new aggregate as **Other clinic charges**, and the Billing Record
  total and balance as **Overall checkout total** and **Overall checkout
  balance**.
- Patient-visible items remain limited to lines deliberately included in the
  Optical Order. A service such as Eye Examination is visible only when staff
  placed it on that order.
- Clinical findings, histories, prescriptions, and examination measurements
  remain excluded.
- Encounter-only Billing Records are not exposed through the mobile API in
  this phase.
- Supplier invoice numbers and internal notes remain hidden from the eyewear
  API.

## Migration and Compatibility

1. Add item classification to canonical Quotation and Job Order items.
2. Backfill rows with a product variant or lens category as `product`.
3. Backfill unreferenced historical custom rows as `legacy_other`; never guess
   from their description whether they were products or services.
4. Add fulfillment metadata to Job Orders. Existing Job Orders retain their
   current status and are treated as prepared-work orders for compatibility.
5. Make `billing_records.job_order_id` nullable and replace its unique
   constraint with a normal index. This permits a voided record to be reissued
   for the same Job Order while application logic keeps at most one non-voided
   Billing Record for that Job Order.
6. Backfill canonical Billing Record items from their Job Order item snapshots.
7. Copy each accepted Quotation's subtotal and discount into its related
   Billing Record summary.
8. Preserve both existing `job_order_id` and `encounter_id` relationships.
   Current Optical Billing Records already model valid combined context when
   both are present.
9. Add validation and a database constraint requiring at least one Billing
    Record source where supported by the application's MySQL version; do not
    add an exclusive-or constraint.
10. Do not read, migrate into, or reactivate inactive legacy commerce tables
    unless a reconciliation check proves they contain canonical records that
    are otherwise missing.

Every data migration must fail safely when reconciliation counts do not match;
it must not silently drop or reclassify ambiguous financial history.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5 / Livewire 4 / Tailwind CSS 4
- Pest 4 / PHPUnit 12
- MySQL through Laravel Sail
- No new package dependency

## Commands

```text
Inspect routes:  vendor/bin/sail artisan route:list --except-vendor
Migrate:         vendor/bin/sail artisan migrate --no-interaction
Rollback check:  vendor/bin/sail artisan migrate:rollback --step=1 --no-interaction
Focused tests:   vendor/bin/sail artisan test --compact --filter=OpticalTransaction
Billing tests:   vendor/bin/sail artisan test --compact tests/Feature/BillingRecords
Filament tests:  vendor/bin/sail artisan test --compact --filter=BillingRecordResource
Full tests:      vendor/bin/sail artisan test --compact
Format PHP:      vendor/bin/sail bin pint --dirty --format agent
Build assets:    vendor/bin/sail npm run build
```

## Project Structure

```text
app/Models/
    BillingRecordItem.php
    QuotationItem.php
    JobOrderItem.php
    BillingRecord.php

app/Actions/OpticalOrders/
    Existing confirmation and fulfillment actions

app/Actions/BillingRecords/
    Item snapshot, same-checkout resolution, and Encounter charge actions

app/Filament/Resources/OpticalOrders/
    Shared product/service/mixed transaction form and workflow

app/Filament/Resources/Encounters/
    Contextual Charges card/action

app/Filament/Resources/BillingRecords/
    Unified financial list, details, payments, and source filters

database/migrations/
    Additive schema changes and guarded canonical backfills

database/factories/
    BillingRecordItem factory and updated related factories

tests/Feature/OpticalOrders/
tests/Feature/BillingRecords/
tests/Feature/Encounters/
tests/Feature/Filament/
    Domain, migration, API-regression, and admin workflow coverage
```

## Code Style

Use existing action classes for state changes, explicit types, database
transactions for multi-record issuance, and enum-backed values where the
project uses enums.

```php
final class AddEncounterChargesToBilling
{
    /**
     * @param  array<int, array{description: string, quantity: int, unit_price: float}>  $items
     */
    public function handle(
        Encounter $encounter,
        array $items,
        float $discountAmount,
        ?Carbon $paymentDueDate,
        User $recorder,
    ): BillingRecord {
        return DB::transaction(function () use (
            $encounter,
            $items,
            $discountAmount,
            $paymentDueDate,
            $recorder,
        ): BillingRecord {
            // Domain validation and persistence belong here, not in Filament.
        });
    }
}
```

- Classes and enum cases use TitleCase; methods and variables use camelCase.
- Use descriptive relationship names such as `billingRecordItems()` and
  `openCheckoutBillingRecord()`.
- Filament schemas remain declarative and delegate financial state changes to
  actions.
- PHP files are formatted with Pint; no inline comments unless the algorithm
  is unusually difficult to understand.

## Testing Strategy

### Domain and migration tests

- Product-only, service-only, and mixed classification is derived
  correctly.
- New records cannot create `legacy_other` lines.
- Free-text Service lines can originate from either an Encounter or an Optical
  Order and retain the correct source relationship.
- Service descriptions and prices are snapshotted without a catalog record.
- Immediate completion and prepared-work paths create exactly one Job Order and
  set of item snapshots, then create or reuse the open same-checkout Billing
  Record.
- Corrective orders still require a current patient-owned prescription.
- Supplier invoice is required only when external supplier/lab work is marked.
- Service-only transactions create no inventory movement.
- Mixed transactions commit only catalog-backed product inventory once.
- A Billing Record supports Encounter-only, Optical-Order-only, and Combined
  source context.
- Same-checkout charges reuse an unpaid Billing Record with no posted payment.
- Posted payment or voiding locks the charge set and forces later charges into
  a new Billing Record.
- Every admin entry point that can post the first payment or deposit displays
  the finalization warning and requires an explicit review acknowledgement.
- Linked Optical Order service lines are visible from the Encounter Charges
  card so staff can avoid duplicate manual entry.
- Encounter charge addition is explicit and atomic; Encounter completion does
  not trigger it.
- Billing totals and item snapshots reconcile exactly.
- Existing canonical item backfill is deterministic and ambiguous lines become
  `legacy_other`.
- Existing Optical Billing Records retain balances, payments, source access,
  and Encounter context after migration.

### Filament tests

- Optical Order item-type field behavior and contextual defaults.
- Confirm Sale fulfillment choices and conditional supplier fields.
- Encounter Charges card, included-order-services display, and Add Charges to
  Billing action.
- Billing & Payments source columns, filters, item details, and payment actions
  for Encounter, Optical Order, and Combined context.
- First-payment warnings and acknowledgement behavior in Confirm Sale, pickup,
  and Billing & Payments; later payments remain concise.

### API regression tests

- Existing patient eyewear endpoints retain their route, envelope, ownership,
  privacy, items, balances, and due-date behavior.
- Service-only and mixed Optical Orders serialize without exposing supplier or
  clinical-record data.
- Optical Order items and total remain order-scoped, while Combined payment
  values are explicitly identified as overall-checkout figures.
- A Combined response exposes only the reconciled
  `other_clinic_charges_amount` aggregate for Encounter-originating charges.
- Encounter-originating charge descriptions remain absent from the eyewear
  response.
- Encounter-only Billing Records cannot be retrieved through the eyewear
  endpoints.

Each implementation task adds or updates a failing Pest test before behavior is
changed. Run focused tests after each task and the full suite at phase
checkpoints.

## Boundaries

### Always

- Search installed-version Laravel and Filament documentation before code
  changes.
- Use Laravel Sail for PHP, Artisan, Composer, and Node commands.
- Use action classes and database transactions for confirmation, issuance,
  inventory, and financial mutations.
- Preserve payment history and use guarded reconciliation for financial data.
- Enforce source context, patient ownership, prescription, and inventory rules
  in the domain layer rather than only in Filament.
- Add Pest coverage for every behavior change and run Pint after PHP edits.

### Ask first

- Exposing Encounter-only bills to mobile patients.
- Adding tax, official invoice, refund, insurance, or payment-gateway behavior.
- Changing persisted Job Order or Billing Record status values.
- Adding dependencies or replacing existing canonical commerce aggregates.
- Deleting legacy tables or historical records.

### Never

- Create a fake Appointment or Encounter for a retail or service sale that does
  not require clinical documentation.
- Treat an Optical Order charge as a substitute for the Encounter required to
  document a performed examination or other clinical service.
- Create a fake Job Order for an Encounter-sourced clinical bill.
- Infer an ambiguous historical line's type from free text.
- Edit snapshotted Billing Record items, append charges after a posted payment,
  or rewrite payment history silently.
- Expose supplier references, internal notes, findings, histories, or
  examination measurements in the patient eyewear API.
- Modify deployed migrations or remove failing tests to make a build pass.

## Success Criteria

1. Staff can use one Optical Order form for product-only, service-only, and
   mixed transactions without forcing Encounter-originating service charges
   through an order.
2. Every new Optical Order line is explicitly a product or service;
   the transaction type is derived correctly.
3. Staff can complete an immediate sale without artificial production/pickup
   steps, or choose the existing prepared-work lifecycle when work remains.
4. Prescription, inventory, and supplier-reference requirements apply only
   when relevant to the selected items and fulfillment.
5. Staff can add itemized charges from an Encounter without creating an Optical
   Order or Job Order when none is needed.
6. Encounter completion does not automatically bill the patient.
7. Same-checkout Encounter and Optical Order charges appear in one Combined
   Billing Record with one total, payment ledger, balance, and due date. Before
   the first payment, staff reviews the current charges and explicitly confirms
   the warning that payment locks the charge set.
8. Billing detail pages show immutable charge items whose subtotal, discount,
   total, payments, and balance reconcile.
9. Existing Optical Billing Records and payments migrate without financial or
   source-link loss.
10. Linked patients continue to track Optical Order items, status, and order
    total. Combined payment status, balance, and due date are clearly labeled
    as overall-checkout figures, with only a reconciled **Other clinic charges**
    aggregate and no Encounter charge descriptions, counts, clinical records,
    or supplier data.
11. Focused tests, the full Pest suite, Pint, migration checks, and the frontend
    build pass.

## Resolved Decisions

The project owner approved the following on 2026-08-03:

1. Services are free-text typed transaction lines. There is no Services
   catalog and no line-level `requires_encounter` flag.
2. Staff enters or adjusts each service line's price before its source
   transaction snapshots it; the snapshot is then immutable.
3. An eye examination may be an Optical Order line. Clinical documentation
   remains a staff workflow responsibility rather than a software gate.
4. Same-checkout Encounter and Optical Order charges use one combined Billing
   Record.
5. Encounter-only Billing Records remain outside the mobile API in this phase.
6. An explicit **External lab/supplier work** toggle controls whether supplier
   invoice number is required before Ready for Pickup.
7. Incorrect snapshotted charges use void-and-reissue rather than editable
   Billing Record items.
8. The patient eyewear API exposes only Optical Order line descriptions and
   order total. Combined payment figures are labeled as overall-checkout values;
   Encounter-originating charge descriptions remain hidden.
9. Every admin entry point that posts the first payment or deposit shows the
   charge-set finalization warning and requires explicit staff acknowledgement.
10. A patient-visible Combined checkout exposes
    `other_clinic_charges_amount` as a monetary aggregate only; it exposes no
    Encounter item descriptions, counts, origin identifiers, or clinical data.
