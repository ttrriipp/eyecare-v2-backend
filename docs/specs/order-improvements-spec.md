# Spec: Order Management Operational Improvements

## Objective

Three targeted improvements to the order workflow identified in the clinic workflow audit as friction points for real optical clinic operations:

1. **Walk-in Sale fast-track** — allow staff to create an order already in `confirmed` state, triggering inventory deduction and billing immediately. Eliminates the requested → confirm two-step for face-to-face transactions.

2. **"Requires lens cutting?" label** — rename `is_non_prescription` to patient-friendly language in all Filament UI. DB column unchanged. Reduces staff confusion about what the field means.

3. **Inline payment at order completion** — after marking an order as `ready_for_pickup` or `completed`, prompt staff with an optional "Collect payment now?" modal that records a single full or partial payment without leaving the Orders page.

**Users:** Clinic staff and admin operating the Filament panel.
**Success:** Staff can process a walk-in frame purchase from order creation to payment receipt in a single screen without switching to the Billings resource.

---

## Tech Stack

- PHP 8.5, Laravel 13, Filament 5, Pest 4
- No new dependencies required

## Commands

```
Test:    vendor/bin/sail artisan test --compact
Filter:  vendor/bin/sail artisan test --compact --filter=Name
Lint:    vendor/bin/sail bin pint --dirty --format agent
```

## Project Structure

```
app/Filament/Resources/Orders/
  Pages/CreateOrder.php          → Walk-in Sale action + label change
  Pages/EditOrder.php            → Inline payment modal on status advance
  Schemas/OrderForm.php          → Label change for is_non_prescription
  Tables/OrdersTable.php         → Label change in table display
app/Actions/Orders/
  UpdateOrderStatus.php          → No change needed (reused as-is)
tests/Feature/Filament/
  OrderResourceTest.php          → Tests for walk-in sale + inline payment
```

## Code Style

Follow existing patterns:
- Header actions on Pages use `Action::make()` from `Filament\Actions\Action`
- Walk-in Sale uses the existing `CreateOrder` Wizard but with a post-save hook that calls `UpdateOrderStatus::handle($order, 'confirmed')`
- Inline payment modal uses `Action::make()` on `EditOrder` page
- Label changes are UI-only (`->label('...')`) — no DB or model changes

## Testing Strategy

- Pest feature tests for each improvement
- Walk-in Sale: assert order starts as `confirmed` + billing generated + inventory deducted
- Inline payment: assert payment recorded + billing status updated after modal submission
- Label change: assert form renders with new label text
- Full suite must stay green after each task

## Boundaries

- **Always:** Run tests before commits; stage only relevant files; update BACKEND_CONTEXT.md
- **Ask first:** Nothing — all approved
- **Never:** Change `is_non_prescription` column name (Android API uses it); modify existing API response shapes

---

## Features

### F1: Walk-in Sale Fast-Track

**How it works:**
- New header action on `CreateOrder` page: "Walk-in Sale"
- Opens the same 2-step wizard (Order Details → Items) but with a `is_walkin` flag
- On save, instead of creating at `requested` status, immediately calls `UpdateOrderStatus::handle($order, 'confirmed')`
- This triggers: inventory deduction, billing generation, SMS notification (order confirmed)
- The prescription gate and lens gate still apply — if they fail, the order is saved as `requested` with a clear error message ("Lens assignment required before confirming")
- Walk-in Sale action is available to staff + admin

**Alternative considered:** A separate "quick sale" form with fewer fields. Rejected — maintains existing validation and order structure.

**Success criteria:**
- Walk-in Sale creates an order with status `confirmed`
- Inventory is deducted on creation
- A billing is auto-generated
- If prescription gate fails → order saved as `requested` with notification "Prescription required — order saved as pending"
- If lens gate fails → order saved as `requested` with notification "Lens assignment required — order saved as pending"
- Existing "New Order" flow unchanged

---

### F2: "Requires Lens Cutting?" Label

**How it works:**
- Change `is_non_prescription` field label in:
  - `OrderForm.php` (create/edit wizard)
  - `OrdersTable.php` (column header if visible)
  - Any infolist/schema that shows this field
- New label: **"Requires lens cutting?"** with helper text: "Enable if this order includes prescription lenses that need to be cut and fitted."
- Note: The boolean logic is inverted — `is_non_prescription = true` means NO lens cutting needed. The new label reflects this correctly as "Requires lens cutting? No/Yes" where Yes = `is_non_prescription = false`.
- API docs update: note that `is_non_prescription` remains the field name for Android but the semantic meaning is "does NOT require lens cutting"

**Success criteria:**
- Form shows "Requires lens cutting?" instead of "Non-prescription"
- Toggle ON = requires lens cutting (`is_non_prescription = false`)
- Toggle OFF = no lens cutting (`is_non_prescription = true`)
- No DB change, no API change
- API docs note the semantic clarification

---

### F3: Inline Payment at Order Completion

**How it works:**
- After staff advances an order to `ready_for_pickup` or `completed` via the status toggle, a Filament action modal appears: "Collect payment?"
- Modal fields: Amount (pre-filled with `balance_due`), Payment Method (select: Cash/GCash/Bank Transfer/etc.), Reference Number (optional)
- On submit: calls `RecordPayment::handle($billing, [...])` which records the payment and updates billing status
- "Skip" closes the modal without recording (billing remains as-is)
- If no billing exists for the order yet (edge case), the modal is suppressed
- The modal is triggered from the status toggle buttons on the Edit Order page

**Alternative considered:** Auto-redirect to Billing page after status advance. Rejected — forces navigation away from the order context.

**Success criteria:**
- After advancing to `ready_for_pickup` or `completed`, modal appears with balance pre-filled
- Submitting records a payment against the order's billing
- Billing status updates correctly (issued → partially_paid → paid)
- Skipping leaves billing unchanged
- If order has no billing, no modal appears
- Full payment (amount = balance_due) marks billing as `paid`

---

## Implementation Order

F2 (label change) → F1 (walk-in sale) → F3 (inline payment)

Rationale: F2 is pure UI with no logic. F1 is independent of F3. F3 depends on understanding how billing links to orders (confirm understanding first with F1 implementation).

---

## Open Questions

None — all decisions made above.

---

## Phase 2: Implementation Plan

### Architecture Decisions

- **F1 (Walk-in Sale):** A new header action on `CreateOrder` that runs the existing wizard flow but overrides `handleRecordCreation` to set status `confirmed` after creation. Uses the existing `UpdateOrderStatus::handle($order, 'confirmed')` — this single call deducts inventory, generates billing, creates SMS, fires audit log. On gate failure (ValidationException), the order remains `requested` and staff gets a clear notification.
- **F2 (Label change):** Pure UI — `->label()` change in 3 places: `CreateOrder.php`, `OrderForm.php` (edit), `OrdersTable.php`. The boolean semantics are inverted: `is_non_prescription = false` = "requires lens cutting = true". The toggle visual maps ON → requires cutting (is_non_prescription=false), OFF → no cutting (is_non_prescription=true). Implement with `->onIcon`/`->offIcon` and `->onColor`/`->offColor` to reinforce the meaning.
- **F3 (Inline payment):** A new `Action::make('collect_payment')` on `EditOrder` page. Triggered manually by staff as a header action button (not auto-popup after status change — auto-popups are intrusive). Pre-fills `amount` with `billing.balance_due`. Calls `RecordPayment::handle($billing, $data)`. Visible only when the order has a billing with balance > 0.

### Dependency Graph

```
F2 (label change)        → standalone, no deps
F1 (walk-in sale)        → depends on UpdateOrderStatus (already built)
F3 (inline payment)      → depends on RecordPayment action (already built)
                           depends on order having a billing (from F1 or existing flow)
```

All three features are independent of each other. F2 first (lowest risk), then F1, then F3.

### Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Walk-in gate failure leaves order in wrong state | High | Catch ValidationException, keep order as `requested`, show descriptive notification |
| Boolean inversion on label change confuses existing orders | Medium | Test that existing `is_non_prescription=true` orders still display correctly |
| Inline payment fires on already-paid billing | Low | Gate `visible()` on `balance_due > 0` |
| Walk-in Sale and normal Create button both visible confuses staff | Medium | Walk-in Sale as a distinct button with different color (primary vs gray) and clear icon |

---

## Phase 3: Tasks

### Phase 1: Label Change (F2)

#### Task 1: Update is_non_prescription label in CreateOrder, OrderForm, and OrdersTable
**Description:** Change the Toggle label from "Non-Prescription Order" to "Requires lens cutting?" in all three locations. Update helper text to explain the field. Verify boolean logic renders correctly (existing orders with `is_non_prescription=true` show toggle OFF = "no lens cutting required").

**Acceptance criteria:**
- [ ] CreateOrder wizard shows "Requires lens cutting?" with helper text
- [ ] OrderForm (edit) shows "Requires lens cutting?" with helper text
- [ ] OrdersTable column shows a meaningful label (or is hidden by default)
- [ ] Toggle ON = requires cutting (`is_non_prescription = false`)
- [ ] Toggle OFF = no cutting (`is_non_prescription = true`)
- [ ] Existing order tests still pass

**Verification:** `vendor/bin/sail artisan test --compact --filter=OrderResource`

**Dependencies:** None

**Files:**
- `app/Filament/Resources/Orders/Pages/CreateOrder.php`
- `app/Filament/Resources/Orders/Schemas/OrderForm.php`
- `app/Filament/Resources/Orders/Tables/OrdersTable.php`

**Size:** S (3 files, pure label changes)

---

### ✅ Checkpoint 1 — After Task 1
- [ ] OrderResource tests pass
- [ ] Full suite green
- [ ] Confirm toggle semantics are visually correct

---

### Phase 2: Walk-in Sale (F1)

#### Task 2: Walk-in Sale header action on CreateOrder
**Description:** Add a "Walk-in Sale" header action button to the `CreateOrder` page that runs the same wizard but immediately attempts to confirm the order after creation. On success: order is `confirmed`, inventory deducted, billing generated. On gate failure: order saved as `requested` with a descriptive notification. Existing "New Order" flow unchanged.

**Acceptance criteria:**
- [ ] "Walk-in Sale" button visible to staff + admin on the orders list page
- [ ] Creates order at `confirmed` status when no gates block
- [ ] Inventory deducted on confirmed walk-in order
- [ ] Billing auto-generated on confirmed walk-in order
- [ ] Prescription gate fails gracefully → order stays `requested`, notification shown
- [ ] Lens gate fails gracefully → order stays `requested`, notification shown
- [ ] Normal "New Order" button still creates at `requested` status

**Verification:** `vendor/bin/sail artisan test --compact --filter=WalkInSale`

**Dependencies:** Task 1 (for consistent labels)

**Files:**
- `app/Filament/Resources/Orders/Pages/CreateOrder.php`
- `app/Filament/Resources/Orders/Pages/ListOrders.php` (add Walk-in Sale header action)
- `tests/Feature/Filament/WalkInSaleTest.php`

**Size:** M (3 files)

---

### ✅ Checkpoint 2 — After Task 2
- [ ] WalkInSaleTest passes
- [ ] OrderResource tests still pass
- [ ] Full suite green

---

### Phase 3: Inline Payment (F3)

#### Task 3: "Collect Payment" header action on EditOrder
**Description:** Add a "Collect Payment" header action button on the EditOrder page. Visible when the order has a billing with balance_due > 0. Opens a modal pre-filled with the balance amount. On submit: calls RecordPayment action, updates billing status. On cancel: no change.

**Acceptance criteria:**
- [ ] "Collect Payment" button visible when billing exists with balance > 0
- [ ] Button hidden when no billing, or balance_due = 0 (fully paid)
- [ ] Modal pre-fills amount with balance_due
- [ ] Selecting payment method is required
- [ ] Submit records payment against the billing
- [ ] Full payment (amount = balance_due) marks billing as `paid`
- [ ] Partial payment leaves billing as `partially_paid`
- [ ] Cancel leaves billing unchanged

**Verification:** `vendor/bin/sail artisan test --compact --filter=InlinePayment`

**Dependencies:** None (uses existing RecordPayment action)

**Files:**
- `app/Filament/Resources/Orders/Pages/EditOrder.php`
- `tests/Feature/Filament/InlinePaymentTest.php`

**Size:** S (2 files)

---

### ✅ Checkpoint 3 — After Task 3
- [ ] InlinePaymentTest passes
- [ ] BillingResource tests still pass
- [ ] Full suite green

---

### Phase 4: Documentation

#### Task 4: Update BACKEND_CONTEXT + spec registration
**Acceptance criteria:**
- [ ] BACKEND_CONTEXT updated: Walk-in Sale in Orders description, label clarification for is_non_prescription
- [ ] Spec registered in completed specs table
- [ ] Full suite passes (536+ tests)

**Verification:** `vendor/bin/sail artisan test --compact`

**Files:**
- `docs/BACKEND_CONTEXT.md`
- `docs/specs/order-improvements-spec.md`

**Size:** XS
