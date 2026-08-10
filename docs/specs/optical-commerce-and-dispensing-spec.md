# Spec: Practical Optical Commerce and Dispensing

## Status

Approved by the project owner on 2026-08-10. Phase 1 (**Specify**) of the
spec-driven workflow is complete. The Phase 2 (**Plan**) document was approved
on 2026-08-10. The Phase 3 (**Tasks**) checklist was approved on 2026-08-10.
Phase 4 (**Implementation**) is complete as of 2026-08-10. All 42 tasks from
the checklist have been implemented across 9 phases with 35+ commits.
Documentation (BACKEND_CONTEXT.md, API_CONTRACT.md) has been reconciled.

This specification refines the implemented Quotation, Optical Order, unified
Billing Record, payment, and inventory workflows described in
`docs/BACKEND_CONTEXT.md`. It preserves the separate-record architecture from
`separate-quotations-optical-orders-and-service-billing-spec.md`.

Where they conflict, this specification supersedes older guidance that:

- treats generic Product lines as a sufficient optical laboratory order;
- stores a patient prescription in quotation descriptions;
- assumes every optical lens is stocked inventory;
- permits overpayments;
- permits routine dispensing with an unpaid balance; or
- omits contact-lens lot and expiration traceability.

`prescription-form-alignment-spec.md` remains authoritative for the clinic's
prescription fields. This specification does not reinterpret its neutral
`value` fields or invent axis, prism, PD, or other clinical meanings.

## Approved Assumptions

1. Quotations, Job Orders, Billing Records, payments, and inventory movements
   remain separate records with separate responsibilities.
2. `JobOrder` and the `job_orders` table remain the internal implementation.
   Clinic-facing interfaces and documentation call the record **Optical
   Order**.
3. One Optical Order supports one primary prescription-eyeglass build: one
   frame or patient-supplied frame, one lens pair, and its treatments. Related
   accessories or contact lenses may accompany that build.
4. Two prescription-eyeglass builds require two Optical Orders. Multi-build
   orders and partial line fulfillment are deferred.
5. The immutable Patient-owned prescription is linked to the Quotation and
   copied by reference to the Optical Order. Prescription values are not
   quotation line items and are not embedded in free-text item descriptions.
6. The existing clinic prescription fields are not changed or reinterpreted
   in this feature. The system must not claim to generate a complete
   standards-based lab prescription while the clinic's neutral field remains
   clinically unidentified.
7. Applicable Optical Orders gain one structured eyewear specification for
   dispensing measurements, selected lens construction, lab instructions,
   approval, and verification.
8. Every physical Product line remains part of the Optical Order in this
   phase. A product-only immediate sale uses an immediate Optical Order and
   completes without artificial production stages.
9. Contact-lens variants remain directly orderable Products. Contact-lens
   inventory gains lot and expiration tracking, but a separate contact-lens
   prescribing/fitting module is not introduced.
10. The existing spectacle Prescription must never be presented as a contact-
    lens prescription.
11. Deposits and partial payments remain supported. Overpayments are rejected,
    the first posted payment locks the charge set, and payment correction is
    append-only.
12. Dispensing normally requires a zero balance. An admin may authorize
    release with a balance only with a reason and payment due date.
13. Admin controls discounts, Billing Record voids, payment corrections, and
    balance-release overrides. Staff and optometrists handle routine commerce
    and fulfillment. Corrective-eyewear specifications require optometrist
    approval.
14. Purchase orders, accounts payable, insurance, official tax documents,
    online payments, automated remakes/returns, multiple inventory locations,
    and lab-system integration are outside this feature.

## Objective

Make the implemented commercial workflow usable as a real small-clinic
optical workflow without turning the application into a laboratory ERP.

Staff must be able to quote a transparent prescription-eyeglass package,
confirm the patient's decision, capture the information needed to prepare or
send the glasses to a laboratory, collect deposits and later payments, verify
the completed eyewear, and dispense it under a clear balance policy.

The target workflow is:

```text
Encounter or existing current prescription
    -> Draft quotation or direct sale
    -> Patient agrees
    -> Confirm Sale
        -> lock commercial snapshots
        -> create Optical Order for Product lines
        -> create or reuse one open Billing Record
        -> optionally record deposit
    -> complete and approve eyewear specification when corrective eyewear exists
    -> prepare locally or send to external laboratory
    -> receive and verify completed eyewear
    -> mark Ready for Pickup
    -> collect remaining payment
    -> dispense
        -> zero balance; or
        -> admin release-with-balance override
```

### Users

- Staff preparing quotations, receiving stock, recording payments, tracking
  fulfillment, and dispensing completed orders.
- Optometrists selecting or approving corrective products and their relation
  to the current prescription, while retaining access to routine clinic
  operations.
- Admin users controlling commercial and financial exceptions.
- The dual-role owner (`admin + optometrist`), who receives the explicit union
  of administrative and clinical authority.
- Linked patients reading presented Quotations and confirmed Optical Order and
  payment progress through the existing mobile API.

### Desired outcome

- A patient-facing quotation reads like an optical quotation, not a generic
  shopping cart or a copy of the clinical prescription.
- A confirmed corrective-eyewear sale contains enough structured dispensing
  and lab information for the clinic's actual manual fulfillment process.
- Catalog edits cannot silently alter a confirmed Quotation or Optical Order.
- Contact-lens stock can be traced by lot and expiration date.
- Payments and dispensing cannot produce an overpaid or unauthorized
  release-with-balance state.
- Existing working commercial, service-billing, reservation, and patient API
  behavior remains intact unless explicitly refined here.

## Domain Boundaries

| Record | Owns | Must not own |
|---|---|---|
| Prescription | Clinical measurement values, author, patient, encounter, immutable version history | Product price, frame selection, laboratory status, payment |
| Quotation | Proposed items, transaction snapshots, prices, discount, validity, patient-facing notes | OD/OS values, dispensing measurements, inventory movements, fulfillment status, payments |
| Optical Order (`JobOrder`) | Confirmed physical products, one eyewear build, fulfillment, supplier/lab context, verification, dispensing | Service performance, editable prices, payment ledger, clinical amendment |
| Eyewear Specification | Prescription reference, frame source, lens construction, dispensing measurements, lab instructions, optometrist approval, verification | Patient-facing prices, payment status, stock totals |
| Billing Record | Final charge snapshots, discount, due date, payments, balance, void/correction history | Clinical values, product preparation status, stock ownership |
| Inventory | On-hand quantities, movements, contact-lens lots and expiration dates | Quotation pricing, receivables, clinical authorization |

## Commercial Item Model

### Two-level classification

Retain the existing high-level `item_type` values:

```text
product
service
```

Add a stable commercial item kind to transaction snapshots so behavior does
not depend on a mutable description or later catalog changes:

```text
frame
lens_package
lens_option
contact_lens
accessory
custom_product
service
```

Rules:

- Catalog-backed items derive their initial kind from the Product type, but
  the transaction stores the resulting kind as a snapshot.
- A Lens Category selected for pricing becomes `lens_package`.
- A coating, tint, photochromic treatment, or similar separately priced
  enhancement becomes `lens_option`.
- A Service catalog entry or custom Service becomes `service`.
- Free text alone must never determine whether a line requires a prescription,
  stock movement, or optical fulfillment.
- Quotation and Job Order items preserve identifying snapshots needed to
  understand the sale after catalog edits: SKU where applicable, Product and
  variant names, relevant physical attributes, and contact-lens parameters.
- Billing items retain their existing concise financial snapshot and
  provenance; the complete optical specification is not duplicated into
  Billing.

### Prescription-eyeglass quotation shape

A normal quotation presents commercially understandable lines:

```text
Frame: [brand/model/variant]                          qty 1
Prescription lens package: [design/material/index]   qty 1 pair
Lens option: [anti-reflective coating]                qty 1 pair
Lens option: [photochromic treatment]                 qty 1 pair
Optional fitting/dispensing Service                   qty 1
```

The Quotation separately shows the prescription number and prescriber when a
prescription is linked. It does not print or serialize the OD/OS values as
commercial items.

### Item invariants

- A corrective-eyewear Quotation has exactly one `lens_package` line.
- It has at most one catalog `frame` line. A patient-supplied frame is recorded
  in the eyewear specification and does not require a zero-price fake Product.
- Lens options require the same eyewear build's lens package.
- An Optical Order may additionally contain contact lenses, accessories, or
  custom physical products.
- A Product-only immediate sale may omit an eyewear specification when no
  corrective-eyewear build exists.
- A Service-only accepted Quotation creates no Optical Order, preserving the
  current explicit service-billing workflow.
- Confirmed item descriptions, kinds, quantities, unit prices, amounts, and
  identifying snapshots are immutable.

## Quotation Workflow

The existing statuses remain:

```text
draft -> presented -> accepted | declined | expired
draft ----------------> accepted
```

Rules:

- Staff may confirm an in-person verbal decision directly from Draft.
- Presentation remains optional and makes the proposal available through the
  patient contract.
- Editing a Presented Quotation returns it to Draft and requires presentation
  again.
- `valid_until` is optional. When supplied, expiration behavior remains.
- A nonzero discount may be entered or changed only by an admin.
- The discount is a checkout-level amount represented once on Billing.
- The selected Prescription must belong to the Quotation's Patient and be the
  current non-superseded version at confirmation.
- Corrective-eyewear lines require a linked Prescription before confirmation.
- The Prescription requirement does not apply to frames without corrective
  lenses, accessories, or ordinary non-corrective immediate goods.
- The spectacle Prescription link must not be used as proof of a contact-lens
  prescription.

## Confirmation Boundary

`ConfirmQuotationSale` remains the atomic and idempotent boundary. It must:

1. lock the Quotation;
2. validate Patient, Prescription, item classification, totals, discount
   authority, and single-build invariants;
3. accept the Quotation once;
4. create at most one linked Optical Order when Product lines exist;
5. copy Product item and identifying snapshots into immutable Job Order items;
6. create the empty eyewear-specification shell only when corrective-eyewear
   lines exist;
7. convert a selected Frame Reservation without double-committing stock;
8. commit applicable catalog inventory, including a contact-lens lot when
   required;
9. create or reuse the Patient visit's open Billing Record;
10. append Optical Order Products and explicitly selected performed Services;
11. apply the Quotation discount once and recalculate totals;
12. reject a deposit greater than the locked balance;
13. record an optional valid deposit last; and
14. return the same records on a safe retry without duplicate items, payments,
    stock movements, lots, or reservation conversion.

If any required step fails, none of the confirmation changes persist.

## Eyewear Specification

### Applicability

An eyewear specification is required when an Optical Order contains a
prescription `lens_package`. It is absent for ordinary immediate Product-only
orders.

Use one one-to-one specification record attached to `job_orders`. The Phase 2
plan may refine physical column names, but it must preserve these semantics:

```text
job_order_id                       unique
prescription_id                    current immutable version
frame_source                       catalog | patient_supplied
frame_job_order_item_id            nullable
lens_package_job_order_item_id     required

lens_design_snapshot               required
lens_material_snapshot             nullable
refractive_index_snapshot          nullable
lens_options_snapshot              list

distance_pd_mode                   binocular | monocular
distance_pd_binocular              nullable, encrypted
distance_pd_od                     nullable, encrypted
distance_pd_os                     nullable, encrypted
near_pd_binocular                  nullable, encrypted
near_pd_od                         nullable, encrypted
near_pd_os                         nullable, encrypted
fitting_height_od                  nullable, encrypted
fitting_height_os                  nullable, encrypted
segment_height_od                  nullable, encrypted
segment_height_os                  nullable, encrypted

lab_instructions                   nullable, encrypted
approved_by                        nullable optometrist
approved_at                        nullable
verified_by                        nullable panel user
verified_at                        nullable
verification_notes                 nullable, encrypted
timestamps
```

### Measurement rules

- Staff records either one binocular distance PD or both monocular distance PD
  values; the form does not require both representations.
- Near PD is optional and recorded only when the selected lens/use requires it.
- Fitting or segment heights are required only when the selected lens design
  requires them.
- The UI uses millimetres and validates plausible positive values without
  silently rounding to whole numbers.
- Blank optional measurements remain `null`; the system never fabricates zero
  values.
- These are dispensing measurements. They do not mutate the clinical
  Prescription and are not printed as if authored during the Encounter.

### Approval and fulfillment rules

- Staff may prepare the specification, but only an active optometrist may
  approve a corrective-eyewear specification.
- A non-clinical admin cannot approve unless the same account also holds the
  optometrist role.
- Processing cannot begin until required specification data is present and an
  optometrist has approved it.
- Changing an approved specification clears approval and requires a new
  approval before Processing resumes. The audit log records the change.
- The approved specification becomes immutable once the order is marked Ready
  for Pickup. Later corrections require an explicit administrative workflow
  defined in a future remake/correction specification; silent edits are never
  allowed.

### Existing prescription limitation

The clinic's current prescription form intentionally has no confirmed axis,
prism, base, or PD fields. This feature must not guess that the neutral `value`
field means axis or another clinical measurement. The eyewear specification
adds only dispensing and product-construction data.

Until the clinic confirms the meaning and completeness of its paper
prescription, the application supports the clinic's manual fulfillment
workflow but must not advertise a standards-complete electronic lab-order
integration.

## Optical Order Workflow

The existing persisted statuses and patient-facing labels remain:

```text
queued                -> Confirmed
in_progress           -> Processing
ready_for_dispensing  -> Ready for Pickup
dispensed             -> Completed
cancelled              -> Cancelled
```

### Prepared corrective eyewear

```text
Confirmed
    -> complete and approve eyewear specification
    -> Processing
    -> receive completed work when externally fulfilled
    -> verify frame, lenses, and configuration
    -> Ready for Pickup
    -> settle balance or obtain admin override
    -> Completed
```

Rules:

- External work records a supplier/laboratory name snapshot and external
  invoice or job reference. A full supplier master and purchasing workflow are
  not required.
- External supplier reference remains internal and patient-hidden.
- Ready for Pickup requires completed verification and, for external work, the
  required supplier/lab reference.
- Verification records who checked the completed eyewear, when, and optional
  notes. It confirms the clinic checked the prepared work against the approved
  specification; it is not a new clinical prescription.

### Immediate orders

- A Product-only immediate order may move directly from Confirmed to Completed
  through the existing immediate-completion action.
- It does not require an eyewear specification unless it contains a corrective
  lens package.
- Inventory and Billing are still created at confirmation.

### Cancellation

- Cancelling an active Optical Order restores each unreversed stock commitment
  exactly once, including the original contact-lens lot.
- Cancellation never deletes the Quotation, Billing, payment, specification,
  inventory, or audit history.
- Financial cancellation or refund behavior remains a separate admin action;
  an Optical Order cancellation must not silently reverse posted payments.

## Inventory and Contact-Lens Traceability

### General inventory

- Frames, accessories, and stocked contact lenses remain Product Variants with
  integer on-hand quantities.
- Custom prescription spectacle lenses and their treatments are externally
  fulfilled commercial items by default and do not create fake stock.
- Lens blank inventory and laboratory-production inventory are outside this
  feature.
- Every stock change goes through the inventory-movement action under a row
  lock. Direct edits to `stock_quantity` remain prohibited.

### Contact-lens variant parameters

Contact-lens variants continue using the existing `attributes` JSON, but the
Filament form and domain validation use canonical keys where applicable:

```text
power
base_curve
diameter
cylinder
axis
add
color
pack_size
```

Only parameters relevant to a particular lens are required. The confirmed Job
Order item snapshots the stored parameters so a later catalog edit cannot
change what was sold.

### Lots

Add lot-level inventory for contact-lens variants with these semantics:

```text
product_variant_id
lot_number
expires_on
received_quantity
quantity_on_hand
received_at
received_by
source_reference                  nullable
timestamps
```

Rules:

- Receiving contact-lens stock requires a nonblank lot number and expiration
  date.
- A variant may have multiple active lots.
- `(product_variant_id, lot_number)` is unique.
- Expired lots cannot be committed to a new sale.
- The default allocation is first-expiry-first-out among non-expired lots;
  staff may select another eligible physical lot when the actual box differs.
- An inventory movement for a lot-tracked variant references the exact lot.
- Cancellation restores quantity to the same lot that supplied the order.
- For a lot-tracked variant, `product_variants.stock_quantity` equals the sum
  of its lots' `quantity_on_hand`. Both values are updated atomically and
  tested as an invariant.
- Existing nonzero contact-lens stock must not receive fabricated lot numbers
  or expiration dates. Before that stock can be sold under the new rules, an
  admin must allocate its existing aggregate quantity across real physical
  lots in a one-time audited reconciliation. The allocations must sum exactly
  to the existing variant quantity and must not create a second stock increase.
- The inventory UI identifies expired and near-expiry lots. The Phase 2 plan
  may select a fixed, configurable near-expiry window; no notification delivery
  is required.

### Receive Stock workflow

Provide one simple Receive Stock action:

- select Product Variant;
- enter positive quantity;
- require lot and expiration for contact lenses;
- optionally record supplier/source reference and notes;
- record recipient and timestamp automatically; and
- create the inventory movement and lot update atomically.

This is not a purchase order, supplier invoice, or accounts-payable workflow.

## Billing and Payments

### Billing boundary

- The Billing Record remains the sole receivable and payment ledger.
- The system record is internal and is not a BIR invoice or official receipt.
- One open checkout may combine Optical Order Product charges and explicitly
  performed Services for the same Patient visit.
- Billing item descriptions, quantities, prices, amounts, and provenance are
  immutable after the first posted payment.
- A later charge after payment creates or uses a separate open Billing Record;
  it never reopens the locked charge set.

### Deposits and partial payments

- A deposit is an ordinary posted payment recorded during confirmation.
- Any positive amount up to the current balance is allowed.
- A payment greater than the locked current balance is rejected; the system
  never clamps the balance to zero while allowing `amount_paid` to exceed the
  total.
- The balance comparison and payment write occur under the Billing Record row
  lock so concurrent requests cannot overpay.
- The first payment requires charge-review acknowledgement and locks the
  charge set.
- Posted payments are never edited or deleted. Admin correction reverses the
  original and records the corrected replacement with attribution and reason.

### Dispensing balance policy

- The normal Dispense action requires the active Billing Record balance to be
  zero after any payment collected in the action.
- A payment entered within Dispense must be sufficient to clear the balance;
  otherwise staff records it separately as a partial payment before attempting
  to dispense.
- An admin may release an order with a remaining balance only when:
  - a future or current payment due date is stored;
  - a nonblank override reason is supplied; and
  - the override actor is recorded.
- A non-clinical staff or optometrist account cannot perform the override.
- The dual-role owner can perform it through the admin role.
- The Dispensing Event stores the remaining balance at release, override actor,
  reason, and due date so the exception is auditable.

## Roles and Authorization

| Capability | Staff | Optometrist | Admin |
|---|---:|---:|---:|
| Create, edit, present, and confirm ordinary Quotations | Yes | Yes | Yes |
| Apply or change a nonzero discount | No | No, unless also admin | Yes |
| Prepare eyewear specification and measurements | Yes | Yes | Yes |
| Approve corrective-eyewear specification | No | Yes | No, unless also optometrist |
| Advance routine Optical Order fulfillment | Yes | Yes | Yes |
| Receive stock and select contact-lens lot | Yes | Yes | Yes |
| Record ordinary payment | Yes | Yes | Yes |
| Correct payment or void Billing Record | No | No | Yes |
| Release with outstanding balance | No | No | Yes |

Authorization must be enforced in policies and domain actions, not only by
hiding Filament controls.

## Admin Interface

### Quotations

- Keep the existing Quotations section.
- Group Product lines into readable optical choices without showing clinical
  prescription values inside the item repeater.
- Show linked Prescription number and author separately.
- Display lens quantity as one pair in human-facing text while retaining the
  existing integer quantity contract.
- Show a clear corrective-eyewear configuration summary before Confirm Sale.
- Require admin authorization before saving a nonzero discount.

### Optical Orders

- Continue using the **Optical Orders** resource; do not expose Job Order as a
  competing clinic-facing term.
- Show a dedicated Eyewear Specification section only when applicable.
- Keep commercial Product lines read-only after confirmation.
- Separate preparation, optometrist approval, lab progress, verification,
  payment, and dispensing actions so one action does not silently perform an
  unrelated responsibility.
- Show the Prescription number and current/superseded status without exposing
  ambiguous clinical labels.

### Inventory

- Add Receive Stock from the existing inventory workspace.
- Show lot and expiration fields conditionally for contact-lens variants.
- Show per-lot remaining quantity and clear expired/near-expiry indicators.
- Do not add purchase-order, vendor-balance, or accounts-payable pages.

### Billing

- Show total, paid, balance, due date, and immutable payment history.
- Disable or reject payment amounts above the current balance.
- Show release-with-balance only to admins and require explicit confirmation,
  due date, and reason.

## Patient API and Privacy

- Preserve the existing versioned Quotation and Optical Order routes and
  ownership checks.
- Presented Quotations expose commercial descriptions, quantities, prices,
  totals, status, validity, and patient-visible notes.
- Confirmed Optical Orders expose patient-facing Product snapshots,
  fulfillment status, total, payment status, due date, and dispensing status.
- Do not expose supplier invoice/reference, supplier cost, internal notes,
  inventory lots, approval metadata, verification notes, or release-override
  reason.
- Do not expose eyewear dispensing measurements through the patient API in
  this phase.
- Prescription access remains governed by the existing patient-owned,
  read-only Prescription endpoint.
- New measurement and lab-note fields are encrypted at rest and excluded from
  audit metadata and logs.

## Proposed Data Contract

The implementation plan may refine physical names, but it must preserve these
relationships and invariants:

```text
quotations
    prescription_id                         existing

quotation_items
    item_kind                               new snapshot classification
    item_snapshot                           new nullable JSON snapshot

job_orders                                 existing; UI name Optical Order
    prescription_id                         existing immutable reference
    external_supplier_name                  new nullable snapshot

job_order_items
    item_kind                               new snapshot classification
    item_snapshot                           new nullable JSON snapshot

job_order_eyewear_specifications            new one-to-one table
    job_order_id                            unique
    prescription_id
    frame/lens item references
    lens construction snapshots
    encrypted dispensing measurements
    encrypted lab/verification notes
    approval and verification attribution

inventory_lots                              new
    product_variant_id
    lot_number
    expires_on
    received_quantity
    quantity_on_hand
    receiving attribution

inventory_movements
    inventory_lot_id                        new nullable reference

dispensing_events
    released_balance_amount                 new default zero
    balance_override_by                     new nullable admin reference
    balance_override_reason                 new nullable encrypted text
    balance_due_date                        new nullable date snapshot
```

Database constraints and action validation must enforce unique one-to-one
relationships, nonnegative lot quantities, valid item references, and required
override attribution. MySQL check constraints may supplement but must not
replace Laravel domain validation.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5 and Livewire 4
- Laravel Sanctum 4
- MySQL through Laravel Sail
- Pest 4 and PHPUnit 12
- Tailwind CSS 4 through the existing Filament panel
- No new Composer or npm dependency

## Commands

```text
Start services:
vendor/bin/sail up -d

Inspect affected routes:
vendor/bin/sail artisan route:list --path=quotations --except-vendor
vendor/bin/sail artisan route:list --path=optical-orders --except-vendor

Run focused commerce tests:
vendor/bin/sail artisan test --compact tests/Feature/Quotations tests/Feature/OpticalOrders tests/Feature/JobOrders

Run focused financial tests:
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords

Run focused inventory tests:
vendor/bin/sail artisan test --compact tests/Feature/Inventory

Run focused panel tests:
vendor/bin/sail artisan test --compact tests/Feature/Filament/QuotationResourceTest.php tests/Feature/Filament/OpticalOrderResourceTest.php tests/Feature/Filament/InventoryResourceTest.php tests/Feature/Filament/BillingRecordResourceTest.php

Format changed PHP files:
vendor/bin/sail bin pint --dirty --format agent

Run the complete regression suite:
vendor/bin/sail artisan test --compact
```

Any new Laravel, Filament, or Pest implementation must use Laravel Boost
`search-docs` first and must be created with the appropriate Sail-prefixed
Artisan generator where one exists.

## Project Structure

```text
app/Enums/
    Transaction item-kind and optical-specification state enums

app/Models/
    Existing Quotation, JobOrder, BillingRecord, ProductVariant, and
    InventoryMovement models; proposed eyewear-specification and lot models

app/Actions/Quotations/
    Draft validation and atomic confirmation

app/Actions/JobOrders/
    Eyewear-specification approval, fulfillment transitions, inventory commit

app/Actions/BillingRecords/
    Payment validation, correction, dispensing, balance override

app/Actions/Inventory/
    Receive Stock and lot-aware movement recording

app/Filament/Resources/Quotations/
app/Filament/Resources/OpticalOrders/
app/Filament/Resources/BillingRecords/
app/Filament/Resources/InventoryMovements/
app/Filament/Resources/Products/
    Existing staff interfaces refined in place; no parallel commerce panel

app/Http/Resources/
    Existing patient-safe Quotation and Optical Order serialization

app/Policies/
    Server-side authorization for discounts, approvals, corrections, and
    release overrides

database/migrations/
    Forward-only additive schema changes and safe snapshot backfill

database/factories/ and database/seeders/
    Optical specification, contact-lens lot, payment, and dispensing states

tests/Feature/Quotations/
tests/Feature/OpticalOrders/
tests/Feature/JobOrders/
tests/Feature/BillingRecords/
tests/Feature/Inventory/
tests/Feature/Filament/
tests/Feature/Api/V1/
    Behavior, authorization, concurrency, privacy, and regression coverage

docs/BACKEND_CONTEXT.md
    Updated only after implementation changes the canonical system state
```

No new top-level application directory or parallel sales aggregate is
required.

## Code Style

Follow existing Laravel conventions, explicit parameter and return types,
PHP 8 constructor promotion, backed enums, focused actions, transactions for
multi-record invariants, and policies plus action-level authorization.

Example domain shape:

```php
final class ApproveEyewearSpecification
{
    public function handle(
        JobOrderEyewearSpecification $specification,
        User $approver,
    ): JobOrderEyewearSpecification {
        if (! $approver->isOptometrist()) {
            throw ValidationException::withMessages([
                'approver' => ['Only an active optometrist may approve corrective eyewear.'],
            ]);
        }

        return DB::transaction(function () use ($specification, $approver): JobOrderEyewearSpecification {
            $lockedSpecification = JobOrderEyewearSpecification::query()
                ->lockForUpdate()
                ->findOrFail($specification->id);

            $lockedSpecification->update([
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $lockedSpecification->fresh();
        });
    }
}
```

Use descriptive optical terms in code. Do not encode clinical behavior in
Filament closures when it belongs in a reusable action. Do not infer item
semantics from descriptions.

## Testing Strategy

Use Pest feature tests and existing factories. Write failing tests before each
behavioral implementation change.

### Quotation and confirmation

- Corrective-eyewear validation requires exactly one lens package and a
  Patient-owned current Prescription.
- Patient-supplied frames do not require a fake Product line.
- Lens options cannot exist without a lens package.
- Catalog changes do not alter saved transaction snapshots.
- Only admin can apply a nonzero discount.
- Confirmation is atomic and idempotent under retry and concurrency.
- Product, Service, reservation, Billing, and inventory behavior continues to
  match the existing unified checkout contract.

### Eyewear specification

- Applicable orders get exactly one specification; ordinary immediate orders
  get none.
- Measurement conditional validation accepts binocular or monocular PD without
  fabricating values.
- Required heights depend on lens design.
- Staff can prepare but cannot approve.
- Only an active optometrist can approve.
- Editing clears approval.
- Processing and Ready transitions enforce approval and verification.
- Sensitive values are encrypted at rest and omitted from patient resources
  and audit metadata.

### Inventory

- Contact-lens receipt requires lot and expiration.
- Ordinary frame/accessory receipt does not require a lot.
- Existing contact-lens stock cannot be sold until its real lots are reconciled
  without changing the aggregate quantity.
- Expired lots cannot be allocated.
- Default allocation is FEFO and remains race-safe.
- A selected eligible lot overrides FEFO.
- Confirmation and cancellation update variant quantity, lot quantity, and
  movements atomically.
- Concurrent allocations cannot make variant or lot quantity negative.
- The lot sum equals aggregate contact-lens stock after receive, sale,
  cancellation, and rollback.

### Billing and dispensing

- Deposits and later partial payments update balances correctly.
- Zero, negative, and over-balance payments are rejected.
- Concurrent payments cannot overpay.
- First payment locks charges.
- Corrections preserve the original entry and attribution.
- Staff and optometrist cannot dispense with a balance.
- Admin override requires reason and due date and is snapshotted on the
  Dispensing Event.
- A sufficient pickup payment and dispense operation completes atomically.

### API and regression

- Patients see only their own presented Quotations and confirmed Optical
  Orders.
- New internal specification, lot, supplier, approval, verification, and
  override data never leaks.
- Existing appointment, encounter, prescription, reservation, service,
  complaint, rating, and messaging tests remain green.

## Boundaries

### Always

- Preserve the Prescription, Quotation, Optical Order, Billing, and Inventory
  boundaries.
- Keep clinic-facing terminology as **Optical Order**.
- Use transaction snapshots for confirmed commercial facts.
- Validate authorization inside actions as well as policies/UI.
- Lock rows for confirmation, stock, payment, cancellation, and dispensing
  invariants.
- Encrypt new patient-linked measurements and free-text lab/override notes.
- Add or update tests for every changed behavior and run focused tests through
  Sail.
- Run Pint after modifying PHP.

### Ask first

- Changing the clinic Prescription field meanings or printed form.
- Supporting multiple eyewear builds in one Optical Order.
- Introducing partial line fulfillment.
- Adding a contact-lens clinical prescription/fitting module.
- Adding a supplier master, purchase orders, or accounts payable.
- Adding a new dependency, top-level directory, persisted status, or public API
  route.
- Changing official tax-document responsibilities.
- Allowing non-admin balance-release overrides or discounts.

### Never

- Copy prescription values into quotation descriptions.
- Treat the existing spectacle Prescription as a contact-lens prescription.
- Infer axis or another meaning for the clinic's neutral prescription field.
- Deduct custom externally fulfilled lens packages from fake inventory.
- Sell expired contact-lens stock.
- Permit a posted payment greater than the locked balance.
- Silently edit or delete posted payments, confirmed Product snapshots, or
  completed dispensing history.
- Expose internal supplier, lot, approval, verification, or override data to a
  patient.
- Call the internal Billing Record an official invoice or receipt.
- Implement deferred ERP, insurance, remake, return, or lab-integration scope
  inside this feature.

## Success Criteria

1. A quotation for prescription glasses clearly separates frame, one lens
   pair, options, and Services while referencing rather than duplicating the
   Prescription.
2. Corrective-eyewear confirmation requires one current Patient-owned
   Prescription and creates exactly one Optical Order and one eyewear
   specification.
3. Each Optical Order represents at most one corrective-eyewear build and may
   include related physical Product lines.
4. Confirmed commercial and Product-parameter snapshots remain unchanged after
   catalog edits.
5. Staff can capture practical dispensing measurements and lab instructions
   without changing the clinical Prescription.
6. Only an active optometrist can approve a corrective-eyewear specification,
   and unapproved or unverified work cannot advance improperly.
7. Custom prescription lenses and treatments do not create inventory
   movements unless a future explicit lens-stock feature is approved.
8. Contact-lens receipts and sales identify non-expired lots, preserve variant
   parameters, and maintain the lot/aggregate stock invariant.
9. Confirmation remains atomic and retry-safe across Quotation, Optical Order,
   Billing, payment, reservation, and inventory records.
10. Deposits and partial payments work, but no single or concurrent request can
    overpay a Billing Record.
11. Routine users cannot dispense with a remaining balance; admin overrides
    are reasoned, dated, and auditable.
12. Patient APIs remain ownership-scoped and expose only patient-relevant
    commercial, fulfillment, and payment information.
13. Existing working service billing, appointment, encounter, Prescription,
    frame reservation, complaint, rating, and messaging behavior remains
    functional.
14. Focused tests, the full regression suite, and Pint complete successfully
    before implementation is considered done.

## Deferred Scope

- Multiple prescription-eyeglass builds in one Optical Order
- Partial fulfillment or per-line dispensing
- Separate contact-lens prescription and fitting records
- Optical laboratory API/EDI integration
- Lens blank or manufacturing inventory
- Supplier catalog, purchasing, accounts payable, and margin accounting
- Insurance claims and benefit adjudication
- Online patient payments and refunds
- Automated remake, warranty, return, exchange, and store-credit workflows
- Multi-location stock transfer
- Quotation PDF, SMS, or email delivery
- Official BIR invoice or receipt generation

## Open Questions

There are no unresolved product questions for Phase 1. The Phase 2 plan may
refine physical table, enum, action, and form-component names, but it must not
change the approved behavior or add deferred scope without returning to the
project owner.
