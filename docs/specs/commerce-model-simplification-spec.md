# Spec: Commerce Model Simplification

## Status

**Draft 2026-08-12. Approval pending.**

Scope confirmed with the project owner on 2026-08-12 across seven decisions
(patient API, inventory lots, order-creation paths, billing append paths,
rating revisions, quotation lifecycle, item classification). This spec records
those decisions and the reasoning behind them.

## Objective

Reduce the quotation, optical-order, product, billing, and rating model to
capabilities a small optical clinic actually uses, and that a capstone can
defend in a demo. The system currently carries several ERP-grade subsystems
that no clinic of this size operates and no reviewer will exercise.

This is a **subtraction project**. No new capability is added except one bug
fix in an area already being touched. Every change removes code, tables,
endpoints, or concepts.

Success means the commerce surface is meaningfully smaller, every remaining
feature is one a reviewer can be walked through end to end, and nothing that
currently works correctly starts working incorrectly.

## Assumptions

1. Per ADR-002 the system is not deployed. No production data migration is
   required for any table dropped or column removed here. **Legacy is not
   supported** — confirmed by the project owner on 2026-08-12 — so no
   compatibility path, defaulting rule, or tolerated legacy data shape survives
   this change. See section 8.
2. The Android client is not yet released against `GET /quotations`. Removing
   those routes is a contract change the app must follow, not a break.
3. Contact-lens stock in development is disposable, so dropping lot tracking
   does not require reconciling existing lot quantities into aggregate stock.
4. Frame reservations are being simplified in parallel per
   `docs/specs/frame-reservation-simplification-spec.md`. That work removes
   `quotations.frame_reservation_id` and `job_orders.frame_reservation_id`;
   this spec assumes those columns are already gone or will be, and does not
   re-specify them.

> Correct any of these before implementation starts.

## Confirmed Decisions

| # | Area | Decision |
|---|---|---|
| 1 | Patient API | Drop patient-facing quotations; keep patient-facing orders |
| 2 | Inventory | Drop contact-lens lot tracking; contact lenses become ordinary stock |
| 3 | Order creation | Collapse five creation paths to two |
| 4 | Billing | Collapse five charge-append paths to one; keep the payment ledger |
| 5 | Ratings | Keep both rating systems; drop revision history |
| 6 | Quotations | Collapse five statuses to three |
| 7 | Items | Drop `item_type`; `item_kind` is the single classification |
| 8 | Legacy | Remove every legacy accommodation in the commerce path |

---

## 1. Remove Patient-Facing Quotations

### Rationale

Patient-visible quotations are not standard in optical retail. Warby Parker,
Zenni, Lenskart, EyeBuyDirect, and Specsavers all present a cart and an order,
never a quotation to review. Customer-visible estimates are standard in dental
treatment planning, auto repair (where the customer actually approves line
items), US Good Faith Estimates, and home services — all cases where work is
customized, expensive, and needs approval *before* it starts.

The current API sits in the worst position available: patients can see a
quotation and its status (`presented`, `declined`) but cannot act on it. That is
the complexity of an approval workflow with none of the payoff. Either the
patient approves in the app or they should not see it.

Order visibility is kept because it *is* the standard feature — every optical
retailer shows "being made / ready for pickup," and it maps directly to the
existing `job_orders` status flow.

### Changes

- Remove `GET /api/v1/quotations` and `GET /api/v1/quotations/{quotation}`.
- Delete `App\Http\Controllers\Api\QuotationController` and
  `App\Http\Resources\QuotationResource`.
- Remove §14 from `docs/API_CONTRACT.md` and correct the route count.
- Keep `GET /api/v1/optical-orders` and `/optical-orders/{jobOrder}` unchanged,
  including `source_quotation` — an order may still name the quotation it came
  from, since that is provenance on a record the patient legitimately sees.
- Quotations remain fully available in Filament. This removes a client
  surface, not the concept.

---

## 2. Remove Contact-Lens Lot Tracking

### Rationale

`inventory_lots` implements lot numbers, expiry dates, FEFO allocation,
per-lot quantity, cancellation restore-to-source-lot, and admin-only
reconciliation of pre-existing stock. This is pharmacy-grade inventory. A
small optical clinic tracks contact lenses as boxes on a shelf, and no capstone
reviewer will exercise a FEFO path.

Aggregate `stock_quantity` plus the append-only `inventory_movements` ledger
already demonstrates real inventory control, which is the point worth showing.

### Changes

- Drop the `inventory_lots` table and `App\Models\InventoryLot`.
- Drop `inventory_movements.inventory_lot_id`.
- Remove `ProductVariant::inventoryLots()`.
- `CommitJobOrderInventory`: delete `allocateLot()`, `allocateFromSelectedLot()`,
  `allocateByFEFO()`, and the `$selectedLotIds` parameter. Contact-lens variants
  follow the same aggregate-stock path as every other product.
- `UpdateJobOrderStatus`: cancellation restores aggregate stock only; the
  restore-to-source-lot branch is removed.
- `ReceiveContactLensStock`: either fold into the ordinary restock path or
  delete if that path already covers it. Receiving stock increments
  `stock_quantity` and writes a `restock` movement.
- Delete `ReconcileContactLensLots` and `InventoryLotsRelationManager`.
- `ContactLensAttributeValidator` is **kept** — canonical parameter validation
  (power, base curve, diameter, axis, add, pack size) is optical domain logic
  worth demonstrating and is unrelated to lots.

---

## 3. Collapse Order Creation to Two Paths

### Rationale

Five actions create an optical order: `ConfirmQuotationSale`,
`AcceptAndStartOpticalOrder`, `CompleteImmediateOpticalOrder`,
`CreateDirectOpticalOrder`, and `CreateJobOrder` — roughly 900 lines that all
do the same three things: snapshot the items, create the order, commit
inventory. The variation between them is which source they read from and
whether fulfillment is immediate.

Two paths is the honest number, because there are exactly two real entry
points: a quotation the customer agreed to, and a walk-in purchase with no
quotation.

### Target

| Action | Source |
|---|---|
| `CreateOpticalOrderFromQuotation` | An accepted quotation |
| `CreateDirectOpticalOrder` | A direct walk-in sale |

Both accept a `fulfillment_mode` of `immediate` or `prepared` as a parameter
rather than branching into separate actions. Both share one private
item-snapshot step and one call to `CommitJobOrderInventory`.

### Changes

- Delete `ConfirmQuotationSale`, `AcceptAndStartOpticalOrder`,
  `CompleteImmediateOpticalOrder`, and `CreateJobOrder`; fold their distinct
  behavior into the two surviving actions.
- **Remove `eyewear_key`** from `quotations` and `job_orders`. It is a ULID
  generated on the quotation and copied to the order, left over from a
  `GET /eyewear` endpoint that `docs/API_CONTRACT.md` already lists as removed.
  `job_orders.quotation_id` is unique and nullable, so it already provides both
  the link and the idempotency guard that `eyewear_key` is currently used for
  in `CreateJobOrder`.
- Preserve the existing behavior that a service-only accepted quotation creates
  no optical order, and that billing is still resolved through the shared open
  checkout.
- Preserve idempotency: confirming the same quotation twice must not create a
  second order or commit inventory twice. The guard moves from `eyewear_key` to
  `quotation_id`.

---

## 4. Collapse Billing Charge-Append Paths

### Rationale

Five actions append charges: `AppendJobOrderItemsToBillingRecord`,
`AppendQuotedServicesToBillingRecord`, `AddEncounterChargesToBilling`,
`AddDirectServiceChargesToBilling`, and `BillRemainingQuotedServices`. They
differ only in where the charge lines come from — the record resolution,
totals recalculation, and charge-set lock are identical in all five.

The payment ledger is deliberately **kept**. Append-only payments, posted and
voided status, overpayment rejection under the billing-record row lock, and
first-payment charge-set locking are the parts that read as genuine accounting
and are worth defending in a demo.

### Changes

- Introduce one `AddChargesToBilling` accepting a source and its lines, keyed
  by the existing `BillingItemSourceKind` enum (`optical_order`, `quotation`,
  `encounter`, `direct_service`).
- Delete the five existing append actions.
- **Keep unchanged:** `ResolveOpenCheckoutBillingRecord`,
  `RecordBillingPayment`, `CorrectBillingPayment`, `VoidBillingRecord`,
  `RecalculateBillingRecordTotals`, `DispenseJobOrder`, overpayment rejection,
  and the admin outstanding-balance override.

---

## 5. Drop Rating Revision History

### Rationale

Both rating systems version every edit into a separate table
(`frame_rating_revisions`, `visit_rating_revisions`) with a
`current_revision_id` pointer. A clinic does not audit how a patient reworded a
review. Both systems themselves are kept — they answer different questions
(this frame vs. this visit) and both feed real app features.

### Changes

- Drop the `frame_rating_revisions` and `visit_rating_revisions` tables and
  their models.
- Drop `frame_ratings.current_revision_id` and
  `visit_ratings.current_revision_id`.
- `SaveFrameRating` and `SaveVisitRating` update the rating row in place.
- **Keep moderation** (`is_hidden`, `moderation_reason`, and the moderate
  actions). Hiding an abusive comment is a real requirement.

### Included bug fix

`FrameController` eager-loads `ratings` filtered to `where('is_hidden', false)`
in both `index()` and `show()`, so hiding a comment also erases its star from
the product's `average_rating` and `rating_count`. `docs/BACKEND_CONTEXT.md`
already flags this as a moderation-integrity problem, and the original spec
required the opposite: hiding suppresses the *comment* only, the star still
counts.

Since this code is already being touched, the fix is in scope: the aggregate
includes every rating; only comment text is suppressed for hidden rows. This is
the single exception to the subtraction-only rule.

---

## 6. Collapse the Quotation Lifecycle

### Rationale

`presented` meant "shown to the patient in the app." With decision 1 removing
that surface, it stops being a system event — staff turn the monitor around or
hand over a printout, which the system has no way to observe. `expired` is
derivable from `valid_until` and does not need to be a stored state.

### Target

```text
draft → accepted | declined
```

### Changes

- `QuotationStatus` reduces to `Draft`, `Accepted`, `Declined`.
- Delete `PresentQuotation`.
- `RecordQuotationDecision` accepts or declines a draft directly; declining
  still requires `decline_reason`.
- `valid_until` is retained and surfaced in Filament; a quotation past it is
  displayed as expired without a stored status. Accepting a past-`valid_until`
  quotation warns but does not block — staff routinely honor a stale quote.
- Filament quotation tabs and filters follow the three statuses.

---

## 7. Drop `item_type`

### Rationale

Every `quotation_items` and `job_order_items` row carries three overlapping
classification fields: `item_type` (product/service), `item_kind` (7 values),
and `item_snapshot` (JSON). `item_kind` already includes a `Service` case, so
`item_type` is fully derivable and is a redundant third field.

`item_kind` and `item_snapshot` both stay — the snapshot does real work keeping
catalog data immutable on confirmed records.

### Scope warning

`item_type` has roughly **77 references**, including real query scopes
(`Quotation::productItems()`, `Quotation::serviceItems()`, and filters in
`CreateJobOrder` and `CompleteImmediateOpticalOrder`). This is a mechanical
change across many files rather than a two-line delete. It is specified as its
own phase so it can be cut without disturbing decisions 1–6.

### Changes

- Add `CommercialItemKind::isProduct(): bool` and
  `CommercialItemKind::productKinds(): array` so callers stop hand-rolling the
  six-value list.
- Rewrite `productItems()` / `serviceItems()` scopes against `item_kind`.
- Drop `item_type` from `quotation_items` and `billing_record_items`, and from
  `job_order_items` if present.
- Delete `App\Enums\TransactionItemType`.
- `billing_record_items.item_type` is dropped alongside; `source_kind` and the
  description already carry what billing needs.

---

## 8. Remove Legacy Accommodations

### Rationale

The project owner confirmed on 2026-08-12 that **legacy is not supported**.
Combined with ADR-002 (not deployed, no production data), there is nothing for
a compatibility path to protect. Every accommodation below exists to tolerate
data shapes or callers that predate a refactor, and each one costs a branch, a
nullable column, or a defaulting rule that has to be reasoned about forever.

### Changes

**`legacy_other` item type.** The `2026_08_03_*` backfill migrations introduced
`legacy_other` as a third `item_type` value for rows that could not be
classified. It is not a case in `TransactionItemType`, so any surviving row
would fail the enum cast. This dies with `item_type` in Phase 7; no separate
work, but the phase must not be skipped while such rows can exist.

**`QuotationItem` legacy defaulting.** The model's boot hook infers `item_type`
when null by inspecting `product_variant_id` / `lens_category_id` /
`lens_option_id`. This exists only to classify pre-refactor rows. Removed with
Phase 7.

**`products.lens_category_id`.** Retained "temporarily for historical
compatibility" after the product-type expansion. It is fillable on `Product`
and referenced nowhere else — dead weight. Drop the column and the fillable
entry.

Note the distinction: `quotation_items.lens_category_id` and
`job_order_items.lens_category_id` are **live** — they carry the lens-package
selection and are read by `BuildQuotationItemSnapshot`, the quotation creation
form, and `EditQuotation`. Only the column on `products` is legacy.

**Deactivated legacy `lens` products.** `2026_08_10_193536_deactivate_legacy_lens_products`
set `is_active = false` on every `product_type = 'lens'` row rather than
removing them, and its `down()` deliberately does nothing. With legacy
unsupported, `lens` ceases to be a permitted `product_type` at all: delete the
remaining rows and constrain the column to `frame`, `contact_lens`, and
`accessory`.

**`QuotationResource` compatibility composition.** Documented as maintaining
backward compatibility for a prior response shape. Deleted outright in Phase 1.

### Out-of-scope legacy (reported, not actioned)

The same audit found legacy accommodations outside the commerce path. They are
listed here so they are not lost, but are **not** part of this spec:

| Location | Accommodation |
|---|---|
| `users.role_id`, `users.is_optometrist` | `@deprecated` columns still written by `CreateUser` / `EditUser` after the `role_user` pivot landed |
| `encounters.patient_intake_id` | Nullable legacy link, plus `AuditLegacyPatientIntakes` and the `encounters:audit-legacy-intakes` command |
| `appointment_requests.appointment_type_id` | Nullable "for legacy", required for new requests |
| `DispatchOtpChallenge` | Kept for backward compatibility with existing controller signatures |
| `EvaluateAppointmentAvailability` | Backward-compatible signature for callers passing just a date |
| `2026_07_17_*_guard_against_legacy_general_product_type` | Deploy-time guard migration for a taxonomy change that has since completed |

These need their own decision — several touch authentication and encounters,
which are outside a commerce refactor's blast radius.

## Explicitly Out of Scope

Kept as-is, deliberately:

- `ValidateOpticalQuotation`'s single-build rules (exactly one lens package, at
  most one frame, lens options require a package). These are real optical
  domain rules and are the strongest evidence in the codebase that the system
  models its domain rather than generic e-commerce.
- The billing payment ledger, dispensing events, and the admin balance override.
- `ContactLensAttributeValidator`.
- Prescriptions, encounters, appointments, and the reservation work specified
  separately.
- Lens categories and lens options as catalog tables.
- Both rating systems themselves.

## Technical Context

- PHP 8.5, Laravel 13, Filament 5, MySQL via Sail, Pest 4 / PHPUnit 12.
- No new dependency. Schema changes are drops only, plus the removal of two
  tables.

## Project Structure

```text
app/Actions/Quotations/            → 4 actions (from 7)
app/Actions/OpticalOrders/         → 2 creation paths (from 4, + CreateJobOrder)
app/Actions/JobOrders/             → CommitJobOrderInventory, UpdateJobOrderStatus
app/Actions/BillingRecords/        → 7 actions (from 11)
app/Actions/Inventory/             → restock only
app/Actions/Ratings/               → 4 actions, no revision writes
app/Enums/                         → QuotationStatus (3), no TransactionItemType
app/Http/Controllers/Api/          → no QuotationController
database/migrations/               → drops + 2 table drops
docs/API_CONTRACT.md, docs/BACKEND_CONTEXT.md
```

## Code Style

Unchanged from the repository: explicit types, constructor promotion, curly
braces, domain actions for every mutation, row locks for stock and money.
Consolidated actions take an explicit source rather than branching on nulls:

```php
public function handle(
    BillingRecord $record,
    BillingItemSourceKind $source,
    Collection $lines,
): BillingRecord {
    // One append path; the source kind decides provenance columns only.
}
```

## Testing Strategy

Pest feature tests with `RefreshDatabase`. This is a subtraction project, so
the dominant risk is **removing behavior that something still depends on**. The
testing approach is therefore characterization-first:

1. Before deleting a consolidated action, confirm an existing test covers the
   behavior being folded into its replacement; write one if not.
2. After consolidation, the surviving action's tests must cover every case the
   deleted actions covered — especially service-only quotations creating no
   order, immediate vs. prepared fulfillment, and double-confirmation
   idempotency.
3. Inventory: committing and cancelling a contact-lens order moves aggregate
   stock correctly with no lot reference.
4. Billing: each of the four source kinds appends correctly through the single
   action, and the charge set still locks on first payment.
5. Ratings: editing a rating updates in place; hidden ratings still count
   toward `average_rating` and `rating_count` while their comment is
   suppressed.
6. Quotations: a draft can be accepted or declined; a past-`valid_until`
   quotation can still be accepted.
7. API: `/quotations` returns 404; `/optical-orders` is unchanged, including
   `source_quotation`.
8. A grep proves `eyewear_key`, `InventoryLot`, `TransactionItemType`, and the
   revision tables are gone.

Deleted tests must be deleted, never skipped.

## Commands

```bash
vendor/bin/sail artisan test --compact tests/Feature/Quotations
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders
vendor/bin/sail artisan test --compact tests/Feature/Billing
vendor/bin/sail artisan test --compact tests/Feature/Inventory
vendor/bin/sail artisan test --compact tests/Feature/Api/V1
vendor/bin/sail artisan test --compact tests/Feature/Filament
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail artisan test --compact
```

## Boundaries

### Always

- keep inventory movements append-only;
- keep money mutations under the billing-record row lock;
- preserve idempotency on order creation and payment posting;
- delete obsolete tests rather than skipping them;
- update `docs/API_CONTRACT.md` and `docs/BACKEND_CONTEXT.md` in the same
  change as the behavior;
- run through Sail: focused tests, Pint, then the full compact suite.

### Ask first

- removing anything listed under Explicitly Out of Scope;
- changing the optical-order status flow or dispensing rules;
- changing the patient-facing optical-order contract;
- adding any capability (this is a subtraction project).

### Never

- relax overpayment rejection or the first-payment charge-set lock;
- allow a quotation to create two optical orders;
- delete or rewrite inventory movement rows;
- leave a dropped concept half-removed — a column without its code, or code
  without its column.

## Success Criteria

1. `GET /quotations` and `/quotations/{id}` return 404; the optical-order
   endpoints are unchanged.
2. `inventory_lots` and `InventoryLot` are gone; contact lenses commit and
   restore through aggregate stock.
3. Exactly two actions create an optical order; confirming a quotation twice
   creates one order.
4. `eyewear_key` is absent from both tables and all code.
5. Exactly one action appends charges to a billing record; the payment ledger,
   overpayment rejection, and charge-set lock are unchanged.
6. Rating revision tables are gone and ratings update in place; a hidden rating
   still counts toward the product average while its comment is suppressed.
7. `QuotationStatus` has three cases; `PresentQuotation` is gone.
8. `item_type` and `TransactionItemType` are gone; `item_kind` is the sole
   classification.
9. No commerce-path legacy accommodation remains: `products.lens_category_id`
   is dropped, `lens` is no longer a permitted `product_type`, `legacy_other`
   cannot exist, and no model infers a value to tolerate an old row shape.
10. Nothing in Explicitly Out of Scope changed behavior.
11. Full suite green; Pint reports no changes; both canonical docs match the
    implementation.

## Open Questions

1. **Phase 7 (`item_type`) cost/benefit.** 77 references for a derivable field.
   Worth doing for consistency, but it is the least valuable phase and the most
   mechanical. *Recommendation: sequence it last so it can be dropped without
   affecting anything else.*
2. **`ReceiveContactLensStock` disposition.** Whether it folds into the generic
   restock path or is deleted outright depends on whether that path already
   handles the contact-lens form. To be settled during planning by reading both.
