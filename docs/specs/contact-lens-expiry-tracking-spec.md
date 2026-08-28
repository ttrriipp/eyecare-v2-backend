# Spec: Contact-Lens Expiry Tracking

## Status

Approved in conversation on 2026-08-28. Implemented and verified on 2026-08-28.

## Objective

Let clinic staff receive, see, and safely sell contact-lens inventory by real
lot and expiry date without turning the existing Inventory screen into an ERP
workflow. The owner-facing experience adds only lot number and expiry month to
contact-lens receiving; allocation and expiry enforcement remain automatic.

Success means expired contact lenses cannot be committed to an Optical Order,
usable stock is allocated first-expiry-first-out across as many lots as needed,
and cancellation restores the exact source lots. Frames and accessories retain
their existing aggregate-stock behavior.

## Confirmed Decisions

1. The feature is Filament-only. No lot, supplier, quantity, or expiry data is
   added to the patient API.
2. Expiry is enforced, not merely displayed.
3. Existing contact-lens data is disposable. Development rollout may use
   `migrate:fresh --seed`; no reconciliation or backfill UI is built.
4. “Expiring soon” means within 90 days. This is an internal configurable
   default, not another owner-facing setting.
5. Expiry is inclusive in the `Asia/Manila` clinic timezone. A month-only
   printed expiry is stored as the final calendar day of that month.
6. Staff never choose a lot during a sale. The backend allocates usable lots
   automatically using deterministic FEFO and may split one item across lots.
7. Expired physical stock remains visible but unavailable until it is written
   off. The system does not silently mutate stock at midnight.

## Owner Experience

### Receive contact-lens stock

The existing **Receive Stock** action conditionally asks for:

- quantity;
- lot number;
- expiry month; and
- the existing optional reference and notes.

Frames and accessories keep the current form. Receiving the same lot number
again is permitted only when the stored expiry matches.

### Review inventory

The existing Inventory workspace adds contact-lens-only information:

- usable quantity;
- earliest usable expiry;
- Good, Expiring Soon, and Expired visual states;
- Expiring Soon and Expired tabs; and
- a read-only **View batches** action.

There is no separate batch-management resource and no editable lot CRUD table.

### Sell and cancel

Optical Order creation uses non-expired lots in earliest-expiry order. If
usable lot stock is insufficient, confirmation fails even if expired physical
stock remains on hand. Cancellation restores each committed quantity to its
original lot exactly once.

### Write off contact-lens stock

The existing write-off flow identifies the affected lot for contact lenses,
defaulting to the earliest-expiring lot. It never reduces a different lot or
allows the variant aggregate to drift from the sum of lot quantities.

## Data Model

Recreate `inventory_lots` through a new forward migration; do not edit the
historical create/drop migrations.

```text
inventory_lots
    id
    product_variant_id
    lot_number
    expires_on
    received_quantity
    quantity_on_hand
    received_at
    received_by
    source_reference              nullable
    timestamps
```

Constraints and indexes:

- unique `(product_variant_id, lot_number)`;
- index `(product_variant_id, expires_on)` for allocation and display;
- index `(expires_on, quantity_on_hand)` for expiring/expired queues;
- unsigned quantities;
- restricted deletion of referenced variants and receiving users, following
  existing ledger-preservation conventions.

`inventory_movements.inventory_lot_id` is additive and nullable so historical
and non-contact-lens movements remain valid. Lot-aware commitment may write
multiple movement rows for one Optical Order item when allocation spans lots.

For tracked contact-lens variants, this invariant must hold after every stock
operation:

```text
product_variants.stock_quantity
    = SUM(inventory_lots.quantity_on_hand)
```

`stock_quantity` remains physical on-hand quantity. Usable contact-lens stock
is derived from lots where `expires_on >= today()` and
`quantity_on_hand > 0`.

## Domain Rules

- Only variants whose Product has `product_type = contact_lens` may own lots.
- Receipt quantity must be positive.
- Lot number is required, trimmed, and limited to 50 characters.
- Expiry month is required and must not already be expired.
- A lot is usable throughout its stored `expires_on` date and expires the next
  clinic-local day.
- FEFO ordering is `(expires_on, id)` for deterministic concurrency behavior.
- Allocation locks the variant and candidate lots in deterministic order.
- An order may consume multiple lots.
- Expired lots are never committed.
- Reversal restores the exact lots once and preserves current audit behavior.
- Frames and accessories never require or receive lot identifiers.
- Patient resources continue to omit internal inventory data.

## Tech Stack and Commands

- PHP 8.5, Laravel 13, Filament 5, MySQL, Pest 4.
- Create framework files with Sail Artisan and `--no-interaction`.
- Focused tests:
  `vendor/bin/sail artisan test --compact <test-file>`
- Full tests:
  `vendor/bin/sail artisan test --compact`
- Format modified PHP:
  `vendor/bin/sail bin pint --dirty --format agent`
- Fresh disposable development database:
  `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`

## Project Structure

- Domain models: `app/Models/`
- Inventory actions: `app/Actions/Inventory/`
- Order allocation/reversal: `app/Actions/JobOrders/`
- Shared Filament stock actions: `app/Filament/Support/StockActions.php`
- Inventory presentation: `app/Filament/Resources/Inventory/`
- Migrations/factories/seeders: `database/`
- Pest coverage: `tests/Feature/Inventory/`, `tests/Feature/JobOrders/`, and
  `tests/Feature/Filament/`

## Code Style

- Follow sibling Laravel and Filament files before introducing a new pattern.
- Use explicit parameter and return types, constructor promotion where needed,
  typed Eloquent relationships, `casts()`, and `#[Fillable]`.
- Keep stock mutations in transaction-scoped actions and use row locks.
- Use local scopes for expiry queries; never globally hide expired lots.
- Use Filament 5 component namespaces and static `make()` constructors.

## Testing Strategy

Use Pest feature tests and factories. Every behavioral slice starts with a
failing focused test. Coverage must include:

- schema constraints and relationships;
- expiry-day and month-end boundaries;
- valid receipt and conflicting repeated-lot expiry;
- aggregate/lot invariant after receipt and write-off;
- multi-lot deterministic FEFO allocation;
- insufficient usable stock when expired physical stock exists;
- concurrent-safe non-negative allocation;
- exact and idempotent source-lot restoration;
- Filament form visibility, tabs, badges, and read-only batch details;
- unchanged frame/accessory behavior; and
- absence of lot/expiry fields from patient responses.

## Boundaries

### Always

- Preserve append-only Inventory History and audit behavior.
- Keep every stock operation atomic and quantity-safe.
- Keep patient API responses unchanged.
- Verify each slice before expanding it.

### Ask first

- Adding scheduled notifications or automatic expiry write-offs.
- Adding purchasing, supplier, or return workflows.
- Exposing inventory data through an API.
- Preserving or migrating non-disposable stock data.

### Never

- Fabricate lot numbers or expiry dates.
- Permit expired stock to satisfy an order.
- Add manual lot selection to the ordinary sales workflow.
- Edit historical migrations that may already have run.
- Add new package dependencies for this feature.

## Not Doing

- Existing-stock reconciliation UI or backfill logic.
- Manual sale-time lot selection.
- Automated notifications or reminder scheduling.
- Automatic midnight stock removal.
- Supplier and purchasing modules.
- Patient/mobile expiry endpoints.
- Editable batch-management screens.

## Success Criteria

1. Staff can receive a contact-lens lot with quantity, lot number, and expiry
   month from the existing stock action.
2. The Inventory workspace clearly surfaces usable, expiring, and expired
   contact-lens stock without adding a separate management area.
3. Optical Orders allocate usable lots automatically across multiple batches
   using deterministic FEFO.
4. Expired lots never satisfy an order.
5. Cancellation restores the exact source lots once.
6. Contact-lens aggregate stock always equals the lot quantity sum.
7. Frames and accessories behave exactly as before.
8. The patient API contract remains unchanged.
9. Disposable demo data seeds valid lot-backed contact-lens inventory.
10. Focused and full tests pass and Pint leaves modified PHP formatted.

## Open Questions

None. Any scope expansion requires renewed approval.
