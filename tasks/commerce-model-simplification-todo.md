# Task Checklist: Commerce Model Simplification

**Status:** ✅ Implemented 2026-08-13 — all 22 tasks landed
**Specification:** `docs/specs/commerce-model-simplification-spec.md`
**Plan:** `tasks/commerce-model-simplification-plan.md`

22 tasks in 10 phases. Phase 8 is droppable as a unit.

## Execution Rules

- Implement in order; do not cross a checkpoint until its verification passes.
- **Characterization first.** Before deleting a consolidated action, confirm a
  test covers the behavior being folded into its replacement. Write one if not.
- Use Laravel Boost `search-docs` before Laravel, Filament, Livewire, or Pest
  changes.
- Run `vendor/bin/sail bin pint --dirty --format agent` after PHP changes and
  before completing each checkpoint.
- Preserve: append-only inventory movements, money mutations under the
  billing-record row lock, order-creation and payment idempotency.
- Delete obsolete tests; never skip them.
- Do not add capability. This is a subtraction project, with one named
  exception (Task 15).
- Stop and split any task that grows past ~5 files.

---

## Phase 0: De-risk the consolidation

> The riskiest work in this project is Phase 4, and its dependencies force it
> to sit mid-project. This phase front-loads the *discovery* instead: it pins
> down current order-creation behavior before anything is deleted, so a nasty
> surprise surfaces on day one rather than four phases in. These tests are
> required by Task 8 regardless — writing them first costs nothing.

### Task 0: Characterize order-creation behavior

**Description:** Write tests that pin the behavior of all five current order
creation paths, with no production change. If any behavior turns out to be
undocumented, conditional, or surprising, that is the signal to re-scope Phase 4
before committing to it. Treat a discovery here as a reason to stop and report,
not to proceed.

**Acceptance criteria:**
- [ ] A service-only accepted quotation creates no optical order but still
      resolves a billing record
- [ ] Confirming the same quotation twice creates one order and commits
      inventory once
- [ ] Immediate and prepared fulfillment are each asserted end to end,
      including which status stages each skips
- [ ] A direct walk-in order commits inventory correctly
- [ ] All five paths are covered; no production file is modified

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders tests/Feature/Quotations`
- [ ] Every new test passes against the **current** code, before any deletion

**Dependencies:** None

**Files likely touched:**
- `tests/Feature/OpticalOrders/OrderCreationCharacterizationTest.php` (new)
- `tests/Feature/Quotations/` (new characterization cases)

**Estimated scope:** M

---

## Phase 1: Patient API

### Task 1: Remove the patient quotation endpoints and the rating alias

**Description:** Drop the two patient-facing quotation routes and the duplicate
frame-rating route. Quotations remain fully available in Filament.

**Acceptance criteria:**
- [ ] `GET /quotations` and `GET /quotations/{quotation}` are removed
- [ ] `QuotationController` and `App\Http\Resources\QuotationResource` are
      deleted
- [ ] `POST /job-order-items/{item}/rating` is removed;
      `optical-order-items` remains the sole path
- [ ] `GET /optical-orders` is unchanged, including `source_quotation`

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1`
- [ ] `vendor/bin/sail artisan route:list --path=api` shows 54 routes
- [ ] Any test hitting the removed routes asserts 404 or is deleted

**Dependencies:** None

**Files likely touched:**
- `routes/api.php`
- `app/Http/Controllers/Api/QuotationController.php` (deleted)
- `app/Http/Resources/QuotationResource.php` (deleted)
- `tests/Feature/Api/V1/` quotation tests

**Estimated scope:** S

---

## Phase 2: Quotation lifecycle

### Task 2: Collapse `QuotationStatus` to three cases

**Description:** `draft → accepted | declined`. `presented` and `expired` go;
`valid_until` stays and expiry becomes derived.

**Acceptance criteria:**
- [ ] `QuotationStatus` has `Draft`, `Accepted`, `Declined`
- [ ] `PresentQuotation` is deleted; `presented_by` / `presented_at` dropped
- [ ] `RecordQuotationDecision` accepts or declines a draft directly; declining
      still requires `decline_reason`
- [ ] Accepting a past-`valid_until` draft succeeds (warns, does not block)

**Verification:**
- [ ] Quotation tests updated for the three-status lifecycle
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations`

**Dependencies:** Task 1

**Files likely touched:**
- `app/Enums/QuotationStatus.php`
- `app/Actions/Quotations/RecordQuotationDecision.php`
- `app/Actions/Quotations/PresentQuotation.php` (deleted)
- `database/migrations/*_drop_presented_fields_from_quotations.php`
- `tests/Feature/Quotations/`

**Estimated scope:** M

---

### Task 3: Update Filament quotation tabs, filters, and columns

**Description:** Bring the staff-facing quotation surfaces in line with the three-status lifecycle. Nothing behavioural changes here — this is the UI half of Task 2, split out so the domain change lands and is verified on its own.

**Acceptance criteria:**
- [ ] `ListQuotations` has three tabs, not five
- [ ] No status filter or column references `presented` or `expired`
- [ ] A past-`valid_until` quotation displays as expired without a stored status
- [ ] The Present action is gone from every quotation surface

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/QuotationResourceTest.php`

**Dependencies:** Task 2

**Files likely touched:**
- `app/Filament/Resources/Quotations/Pages/ListQuotations.php`
- `app/Filament/Resources/Quotations/Tables/`
- `app/Filament/Resources/Quotations/Pages/EditQuotation.php`
- `tests/Feature/Filament/QuotationResourceTest.php`

**Estimated scope:** M

---

### ✅ Checkpoint: Quotations

- [ ] `/quotations` returns 404; `/optical-orders` unchanged
- [ ] A draft can be accepted or declined; a stale draft can still be accepted
- [ ] Full suite green, Pint clean

---

## Phase 3: Inventory lots

### Task 4: Remove lot allocation from commit and cancellation

**Description:** Strip FEFO and lot selection out of the two actions that move stock. Contact-lens variants stop being special-cased and follow the same aggregate-stock path as frames and accessories. The table and model stay until Task 6.

**Acceptance criteria:**
- [ ] `CommitJobOrderInventory::handle()` takes only `$jobOrder`
- [ ] `allocateLot()`, `allocateFromSelectedLot()`, `allocateByFEFO()` deleted
- [ ] Contact-lens variants follow the same aggregate-stock path as everything
      else
- [ ] `UpdateJobOrderStatus` cancellation restores aggregate stock only

**Verification:**
- [ ] Committing and cancelling a contact-lens order round-trips
      `stock_quantity` with no lot reference
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Inventory tests/Feature/OpticalOrders`

**Dependencies:** Task 3

**Files likely touched:**
- `app/Actions/JobOrders/CommitJobOrderInventory.php`
- `app/Actions/JobOrders/UpdateJobOrderStatus.php`
- `tests/Feature/Inventory/`

**Estimated scope:** M

---

### Task 5: Remove lot receiving, reconciliation, and the relation manager

**Description:** Decide `ReceiveContactLensStock`'s fate by reading the generic
restock path first — fold if it already covers the contact-lens form, delete
outright if so.

**Acceptance criteria:**
- [ ] `ReconcileContactLensLots` and `InventoryLotsRelationManager` deleted
- [ ] Receiving contact-lens stock increments `stock_quantity` and writes one
      `restock` movement, with no lot number or expiry fields
- [ ] The Receive Stock modal no longer shows lot inputs
- [ ] `ContactLensAttributeValidator` is untouched

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Inventory tests/Feature/Filament`

**Dependencies:** Task 4

**Files likely touched:**
- `app/Actions/Inventory/ReceiveContactLensStock.php`
- `app/Actions/Inventory/ReconcileContactLensLots.php` (deleted)
- `app/Filament/Resources/Products/RelationManagers/InventoryLotsRelationManager.php` (deleted)
- `app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php`

**Estimated scope:** M

---

### Task 6: Drop `inventory_lots` and `inventory_lot_id`

**Description:** The contract half of the lot removal, safe only because Tasks 4 and 5 removed every reader and writer first.

**Acceptance criteria:**
- [ ] `inventory_lots` table and `App\Models\InventoryLot` deleted
- [ ] `inventory_movements.inventory_lot_id` dropped
- [ ] `ProductVariant::inventoryLots()` removed
- [ ] `InventoryLotFactory` and lot-only tests deleted

**Verification:**
- [ ] `vendor/bin/sail artisan migrate:fresh --seed`
- [ ] `grep -rn "InventoryLot\|inventory_lot" app/ database/ tests/` returns
      nothing

**Dependencies:** Task 5

**Files likely touched:**
- `database/migrations/*_drop_inventory_lots.php` (new)
- `app/Models/InventoryLot.php` (deleted)
- `app/Models/ProductVariant.php`
- `app/Models/InventoryMovement.php`

**Estimated scope:** S

---

### ✅ Checkpoint: Inventory

- [ ] Contact-lens commit and cancel round-trip aggregate stock correctly
- [ ] `migrate:fresh --seed` succeeds
- [ ] Full suite green, Pint clean

---

## Phase 4: Order creation

### Task 7: Extract the shared order-building collaborator

**Description:** One private collaborator doing what all five paths currently
duplicate: snapshot the items, create the order, commit inventory. Build it
first so the consolidation tasks are thin.

**Acceptance criteria:**
- [ ] A single collaborator creates a `JobOrder` with snapshotted items and
      commits inventory once
- [ ] It accepts `fulfillment_mode` as a parameter
- [ ] No existing action changes behavior yet — this task only adds the shared
      piece

**Verification:**
- [ ] New unit/feature test covering the collaborator directly
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders`

**Dependencies:** Task 6

**Files likely touched:**
- `app/Actions/OpticalOrders/BuildOpticalOrder.php` (new)
- `tests/Feature/OpticalOrders/`

**Estimated scope:** M

---

### Task 8: Build `CreateOpticalOrderFromQuotation`

**Description:** Replaces `ConfirmQuotationSale` and `CreateJobOrder`. Write
the characterization tests for service-only quotations and double-confirmation
**before** deleting anything.

**Acceptance criteria:**
- [ ] Creates an order from an accepted quotation via the Task 7 collaborator
- [ ] A service-only quotation creates no order but still resolves billing
- [ ] Confirming twice creates one order and commits inventory once
- [ ] `ConfirmQuotationSale` and `CreateJobOrder` deleted

**Verification:**
- [ ] Idempotency and service-only tests pass
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations tests/Feature/OpticalOrders`

**Dependencies:** Task 7, Task 0 (characterization must exist first)

**Files likely touched:**
- `app/Actions/OpticalOrders/CreateOpticalOrderFromQuotation.php` (new)
- `app/Actions/Quotations/ConfirmQuotationSale.php` (deleted)
- `app/Actions/JobOrders/CreateJobOrder.php` (deleted)
- `tests/Feature/Quotations/`, `tests/Feature/OpticalOrders/`

**Estimated scope:** M

---

### Task 9: Fold the fulfillment variants in

**Description:** Immediate and prepared fulfillment become a parameter rather than two separate actions. This is where the largest single line reduction in the project happens, so verify both modes explicitly rather than trusting the shared path.

**Acceptance criteria:**
- [ ] `AcceptAndStartOpticalOrder` and `CompleteImmediateOpticalOrder` deleted
- [ ] Immediate and prepared fulfillment both work through
      `CreateOpticalOrderFromQuotation` via `fulfillment_mode`
- [ ] Immediate orders still skip the stages they skip today
- [ ] Filament call sites updated

**Verification:**
- [ ] Both fulfillment modes covered by tests
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders tests/Feature/Filament`

**Dependencies:** Task 8

**Files likely touched:**
- `app/Actions/OpticalOrders/{AcceptAndStart,CompleteImmediate}*.php` (deleted)
- `app/Actions/OpticalOrders/CreateOpticalOrderFromQuotation.php`
- `app/Filament/Resources/OpticalOrders/Pages/ListOpticalOrders.php`
- `tests/Feature/OpticalOrders/`

**Estimated scope:** M

---

### Task 10: Rework `CreateDirectOpticalOrder` onto the collaborator

**Description:** Point the walk-in path at the Task 7 collaborator. Staff-visible behaviour must not change — this is internal restructuring only, and it is what leaves exactly two creation actions standing.

**Acceptance criteria:**
- [ ] Direct walk-in creation uses the Task 7 collaborator
- [ ] Behavior is unchanged for staff
- [ ] Exactly two creation actions remain in `app/Actions/OpticalOrders/`

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders tests/Feature/Filament`

**Dependencies:** Task 9

**Files likely touched:**
- `app/Actions/OpticalOrders/CreateDirectOpticalOrder.php`
- `tests/Feature/OpticalOrders/`

**Estimated scope:** M

---

### Task 11: Remove `eyewear_key`

**Description:** The idempotency guard moves to `quotation_id` **before** the
column drops, never after.

**Acceptance criteria:**
- [ ] Idempotency is enforced by the unique nullable `job_orders.quotation_id`
- [ ] `eyewear_key` dropped from `quotations` and `job_orders`, with its model
      boot hooks
- [ ] The double-confirmation test still passes

**Verification:**
- [ ] `vendor/bin/sail artisan migrate:fresh --seed`
- [ ] `grep -rn "eyewear_key" app/ database/ tests/` returns nothing

**Dependencies:** Task 10

**Files likely touched:**
- `database/migrations/*_drop_eyewear_key.php` (new)
- `app/Models/Quotation.php`, `app/Models/JobOrder.php`
- `app/Actions/OpticalOrders/CreateOpticalOrderFromQuotation.php`

**Estimated scope:** S

---

### ✅ Checkpoint: Orders

- [ ] Exactly two creation actions remain
- [ ] Confirming twice creates one order, commits inventory once
- [ ] Service-only quotation creates no order
- [ ] Immediate and prepared both work
- [ ] Full suite green, Pint clean

---

## Phase 5: Billing

### Task 12: Build `AddChargesToBilling`

**Description:** One append path keyed by source kind, replacing five actions that differ only in where their charge lines come from. Record resolution, totals recalculation, and the charge-set lock are lifted across unchanged, not rewritten.

**Acceptance criteria:**
- [ ] One action appends charges, keyed by `BillingItemSourceKind`
      (`optical_order`, `quotation`, `encounter`, `direct_service`)
- [ ] Totals recalculation and the charge-set lock behave exactly as before
- [ ] The five existing append actions are deleted

**Verification:**
- [ ] Each of the four source kinds covered by a test
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Billing`

**Dependencies:** Task 11

**Files likely touched:**
- `app/Actions/BillingRecords/AddChargesToBilling.php` (new)
- `app/Actions/BillingRecords/{Append*,Add*Charges*,BillRemaining*}.php` (deleted)
- `tests/Feature/Billing/`

**Estimated scope:** M

---

### Task 13a: Rewire the order-path billing call sites

**Description:** Point the two order-creation paths at `AddChargesToBilling`.
Split from 13b because "every call site" spans two unrelated subsystems and
would exceed the five-file rule as one task. This half changes only *where
charge lines come from* — it must not touch the payment ledger, the lock, or
totals logic. If a payment test needs editing, something has gone wrong; stop
and re-read.

**Acceptance criteria:**
- [ ] `CreateOpticalOrderFromQuotation` and `CreateDirectOpticalOrder` append
      through `AddChargesToBilling` with source kind `optical_order`
- [ ] Quoted services append with source kind `quotation`
- [ ] `ResolveOpenCheckoutBillingRecord`, `RecordBillingPayment`,
      `CorrectBillingPayment`, `VoidBillingRecord`, and `DispenseJobOrder` are
      **unchanged**

**Verification:**
- [ ] Overpayment rejection and first-payment locking pass their existing tests
      **untouched**
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Billing tests/Feature/OpticalOrders`

**Dependencies:** Task 12

**Files likely touched:**
- `app/Actions/OpticalOrders/CreateOpticalOrderFromQuotation.php`
- `app/Actions/OpticalOrders/CreateDirectOpticalOrder.php`
- `tests/Feature/Billing/`, `tests/Feature/OpticalOrders/`

**Estimated scope:** S

---

### Task 13b: Rewire the encounter and direct-service call sites

**Description:** The clinical and counter-charge half of the billing rewire.
Separate from 13a because it touches encounters and Filament rather than the
order actions, and can be verified independently.

**Acceptance criteria:**
- [ ] Encounter charges append with source kind `encounter`
- [ ] Direct service charges append with source kind `direct_service`
- [ ] All five original append actions are now deleted with no remaining
      references

**Verification:**
- [ ] All four source kinds are each covered by a passing test
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Billing tests/Feature/Filament`
- [ ] `grep -rn "AppendJobOrderItems\|AppendQuotedServices\|AddEncounterCharges\|AddDirectServiceCharges\|BillRemainingQuotedServices" app/ tests/`
      returns nothing

**Dependencies:** Task 13a

**Files likely touched:**
- Encounter charge call sites
- `app/Filament/Resources/BillingRecords/`
- `tests/Feature/Billing/`

**Estimated scope:** M

---

### ✅ Checkpoint: Billing

- [ ] All four source kinds append through one action
- [ ] Charge set locks on first payment; overpayment rejected
- [ ] Full suite green, Pint clean

---

## Phase 6: Ratings *(independent — may run at any point)*

### Task 14: Drop rating revision history

**Description:** Ratings become editable in place instead of versioned. Both rating systems survive; only the revision plumbing goes. Moderation is deliberately untouched — hiding an abusive comment stays a real requirement.

**Acceptance criteria:**
- [ ] `frame_rating_revisions` and `visit_rating_revisions` tables and models
      deleted
- [ ] `current_revision_id` dropped from both rating tables
- [ ] `SaveFrameRating` and `SaveVisitRating` update in place
- [ ] Moderation (`is_hidden`, `moderation_reason`, moderate actions) untouched

**Verification:**
- [ ] Editing a rating updates the row and creates no revision
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Ratings tests/Feature/Api/V1`

**Dependencies:** None

**Files likely touched:**
- `database/migrations/*_drop_rating_revisions.php` (new)
- `app/Models/{Frame,Visit}RatingRevision.php` (deleted)
- `app/Actions/Ratings/Save{Frame,Visit}Rating.php`
- `tests/Feature/Ratings/`

**Estimated scope:** M

---

### Task 15: Fix the hidden-rating aggregate bug

**Description:** The one non-subtractive change in this spec.
`FrameController` eager-loads `ratings` filtered to `where('is_hidden', false)`
in both `index()` and `show()`, so hiding a comment also erases its star from
`average_rating` and `rating_count`. Hiding must suppress the *comment* only.

**Acceptance criteria:**
- [ ] `average_rating` and `rating_count` include hidden ratings
- [ ] Hidden ratings' comment text is not exposed
- [ ] The existing assertion encoding the old behavior is updated deliberately,
      with a comment noting it was the bug

**Verification:**
- [ ] New test: hiding a 1-star rating leaves the average unchanged and the
      comment absent
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1`

**Dependencies:** Task 14

**Files likely touched:**
- `app/Http/Controllers/Api/FrameController.php`
- `app/Http/Resources/` frame resources
- `tests/Feature/Api/V1/`

**Estimated scope:** S

---

## Phase 7: Legacy *(independent of Phase 8)*

### Task 16: Drop `products.lens_category_id` and the `lens` product type

**Description:** `products.lens_category_id` is fillable and referenced nowhere
else. `lens` products were deactivated rather than removed by
`2026_08_10_193536`; with legacy unsupported, the type ceases to exist.

**Acceptance criteria:**
- [ ] `products.lens_category_id` dropped, including the fillable entry
- [ ] Remaining `product_type = 'lens'` rows deleted; permitted types are
      `frame`, `contact_lens`, `accessory`
- [ ] `quotation_items.lens_category_id` and `job_order_items.lens_category_id`
      are **untouched** — they are live lens-package selections

**Verification:**
- [ ] `vendor/bin/sail artisan migrate:fresh --seed`
- [ ] Quotation creation with a lens package still works

**Dependencies:** None (Phase 6 or earlier may precede)

**Files likely touched:**
- `database/migrations/*_drop_legacy_lens_product_support.php` (new)
- `app/Models/Product.php`
- `tests/Feature/`

**Estimated scope:** S

---

## Phase 8: `item_type` *(droppable as a unit)*

> Skipping this phase strands nothing. Everything depending on `item_type` —
> including `legacy_other` and the `QuotationItem` boot defaulting — is here.

### Task 17a: Add `CommercialItemKind` helpers and rewrite the model scopes

**Description:** The enum gains the helpers so callers stop hand-rolling the
six-value product-kind list, and the model-level scopes switch to `item_kind`.
Split from 17b because `item_type` has ~77 references — doing the models and
the callers as one task would far exceed the five-file rule.

**Acceptance criteria:**
- [ ] `CommercialItemKind::isProduct()` and `::productKinds()` exist and are
      unit tested
- [ ] `Quotation::productItems()` / `serviceItems()` query `item_kind`
- [ ] `QuotationItem` and `JobOrderItem` no longer read `item_type` internally
- [ ] `item_type` still exists — this task removes model readers only

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact` (full suite must stay green)

**Dependencies:** Task 16

**Files likely touched:**
- `app/Enums/CommercialItemKind.php`
- `app/Models/Quotation.php`, `QuotationItem.php`, `JobOrderItem.php`
- `tests/Unit/` enum test

**Estimated scope:** M

---

### Task 17b: Rewrite the remaining `item_type` readers

**Description:** Everything outside the models — actions, resources, and tests
that still filter or emit `item_type`. Expect this to touch more files than any
other task in the project; split it further by directory if it grows unwieldy.

**Acceptance criteria:**
- [ ] No action, resource, or test reads `item_type`, including
      `SaveVisitRating`'s `where('item_type', 'service')` filter
- [ ] API responses that exposed `item_type` now derive it from `item_kind` or
      omit it, matching the documented contract
- [ ] `item_type` is written but never read

**Verification:**
- [ ] `grep -rn "item_type" app/ | grep -v "migrations"` shows writes only
- [ ] `vendor/bin/sail artisan test --compact` (full suite)

**Dependencies:** Task 17a

**Files likely touched:**
- `app/Actions/OpticalOrders/`, `app/Actions/Ratings/SaveVisitRating.php`
- `app/Http/Resources/`
- `tests/Feature/`

**Estimated scope:** M

---

### Task 18: Drop `item_type`, `TransactionItemType`, and legacy defaulting

**Description:** The contract half of Phase 8. Drops the column across three tables and takes the `legacy_other` value and the `QuotationItem` boot-hook defaulting with it, since both exist only to classify pre-refactor rows.

**Acceptance criteria:**
- [ ] `item_type` dropped from `quotation_items`, `job_order_items`, and
      `billing_record_items`
- [ ] `App\Enums\TransactionItemType` deleted
- [ ] The `QuotationItem` boot hook inferring `item_type` is removed
- [ ] `legacy_other` cannot exist anywhere

**Verification:**
- [ ] `vendor/bin/sail artisan migrate:fresh --seed`
- [ ] `grep -rn "item_type\|TransactionItemType\|legacy_other" app/ database/ tests/`
      returns nothing

**Dependencies:** Task 17b

**Files likely touched:**
- `database/migrations/*_drop_item_type.php` (new)
- `app/Enums/TransactionItemType.php` (deleted)
- `app/Models/QuotationItem.php`, `JobOrderItem.php`
- `app/Http/Resources/`

**Estimated scope:** M

---

## Phase 9: Documentation

### Task 19: Update canonical documentation

**Description:** Bring both living documents in line with what shipped. Neither should describe a removed capability, and the route count must reconcile against `route:list`.

**Acceptance criteria:**
- [ ] `API_CONTRACT.md`: §14 removed, `job-order-items` alias removed, route
      count corrected to 54
- [ ] `BACKEND_CONTEXT.md`: quotation statuses, order creation, billing
      actions, inventory (no lots), ratings (no revisions), and product types
      all match the implementation
- [ ] A Shipped note is added
- [ ] No doc describes a removed capability

**Verification:**
- [ ] Read-through against implemented behavior
- [ ] `vendor/bin/sail artisan route:list --path=api` matches the documented
      count

**Dependencies:** Task 18 (or Task 16 if Phase 8 is dropped)

**Files likely touched:**
- `docs/API_CONTRACT.md`
- `docs/BACKEND_CONTEXT.md`

**Estimated scope:** S

---

### ✅ Checkpoint: Complete

- [ ] `grep -ri "eyewear_key\|InventoryLot\|TransactionItemType\|legacy_other\|job-order-items"`
      returns only historical files under `docs/specs/`
- [ ] `vendor/bin/sail artisan migrate:fresh --seed` succeeds
- [ ] `vendor/bin/sail artisan test --compact` fully green
- [ ] `vendor/bin/sail bin pint --dirty --format agent` reports no changes
- [ ] Ready for review
