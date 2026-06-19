# Implementation Plan: Billings Rework

## Overview

Rework billing so it auto-generates on order confirmation, starts as `issued` (no draft step), and provides a clean payment recording UI with a 50% downpayment hint. Staff records free-form payment amounts; balance auto-tracks.

## Architecture Decisions

- **Auto-generate in `UpdateOrderStatus`** — billing creation is a side-effect of confirmation, not a separate staff action. Keeps the workflow atomic.
- **Keep `draft` status in seeder** — existing data won't break, but new billings skip it entirely.
- **Payments relation manager on ViewBilling** — inline table is easier to scan than navigating to a separate resource. Void action stays per-row.

## Dependency Graph

```
Task 1: GenerateBillingForOrder starts as issued
    │
    └── Task 2: Auto-generate in UpdateOrderStatus
            │
            └── Task 3: Remove manual generate action from ListBillings
                    │
                    └── Task 4: 50% hint on Record Payment
                            │
                            └── Task 5: Payments RelationManager
                                    │
                                    └── Task 6: Docs + final verification
```

## Task List

### Phase 1: Foundation (billing creation logic)

---

### Task 1: GenerateBillingForOrder starts as `issued`

**Description:** Change the action to create billings with `issued` status and `issued_at = now()` instead of `draft`.

**Acceptance criteria:**
- [ ] New billings have `billing_status_id` pointing to `issued`
- [ ] `issued_at` is set to current timestamp on creation
- [ ] Existing tests updated to assert `issued` instead of `draft`

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact --filter=BillingGeneration`

**Dependencies:** None

**Files likely touched:**
- `app/Actions/Billing/GenerateBillingForOrder.php`
- `tests/Feature/Billing/BillingGenerationTest.php`

**Estimated scope:** S (2 files)

---

### Task 2: Auto-generate billing on order confirmation

**Description:** Call `GenerateBillingForOrder` inside `UpdateOrderStatus` after a successful `confirmed` transition. Wrap in try/catch — billing failure should not block confirmation.

**Acceptance criteria:**
- [ ] Confirming an order creates a billing record automatically
- [ ] Billing `total_amount` matches `order.total_amount`
- [ ] If billing generation fails (edge case), order still confirms — failure is logged
- [ ] Cancelling a confirmed order does NOT delete the billing (billing tracks independently)

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact --filter="BillingGeneration|OrderProcessing|OrderResource"`

**Dependencies:** Task 1

**Files likely touched:**
- `app/Actions/Orders/UpdateOrderStatus.php`
- `tests/Feature/Billing/BillingGenerationTest.php` (add new test)
- `tests/Feature/Api/OrderProcessingTest.php` (assert billing created)

**Estimated scope:** S (3 files)

---

### Checkpoint: After Tasks 1-2
- [ ] All billing + order tests pass
- [ ] Confirming an order in Filament creates a billing automatically

---

### Phase 2: UI cleanup

---

### Task 3: Remove manual "Generate Billing" action from ListBillings

**Description:** Remove the `generate_billing` header action and its tests. Billing is now automatic.

**Acceptance criteria:**
- [ ] No "Generate Billing" button on the billings list page
- [ ] Tests for the manual action are removed
- [ ] ListBillings page still renders correctly

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact --filter=BillingResource`

**Dependencies:** Task 2

**Files likely touched:**
- `app/Filament/Resources/Billings/Pages/ListBillings.php`
- `tests/Feature/Filament/BillingResourceTest.php`

**Estimated scope:** S (2 files)

---

### Task 4: Add 50% downpayment hint to Record Payment

**Description:** On the `record_payment` action modal, show helper text on the amount field: "Suggested downpayment (50%): ₱X.XX" — calculated from `total_amount`. Only show on first payment (when no posted payments exist).

**Acceptance criteria:**
- [ ] Amount field shows 50% hint when no payments recorded yet
- [ ] Hint disappears after first payment is recorded (on page refresh/revisit)
- [ ] Staff can still enter any amount (not enforced)

**Verification:**
- [ ] Manual verification in browser
- [ ] Existing `record_payment` tests still pass

**Dependencies:** Task 3

**Files likely touched:**
- `app/Filament/Resources/Billings/Pages/ViewBilling.php`

**Estimated scope:** XS (1 file)

---

### Checkpoint: After Tasks 3-4
- [ ] All tests pass
- [ ] Manual generate button gone
- [ ] Payment hint visible on fresh billing

---

### Phase 3: Payments visibility

---

### Task 5: Payments RelationManager on ViewBilling

**Description:** Create a PaymentsRelationManager showing payment history inline on the billing view page. Each row shows date, method, amount, status. Posted payments have a "Void" row action.

**Acceptance criteria:**
- [ ] Payment history table renders on ViewBilling page
- [ ] Columns: paid_at, payment method name, amount (₱), status badge, reference
- [ ] "Void" action visible only on `posted` payments
- [ ] Voiding recalculates billing balance

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact --filter=BillingResource`
- [ ] Manual check: view a billing with payments, void one

**Dependencies:** Task 4

**Files likely touched:**
- `app/Filament/Resources/Billings/RelationManagers/PaymentsRelationManager.php` (new)
- `app/Filament/Resources/Billings/BillingResource.php`
- `tests/Feature/Filament/BillingResourceTest.php`

**Estimated scope:** M (3 files)

---

### Task 6: Docs update + final verification

**Description:** Update BACKEND_CONTEXT.md to reflect new billing flow. Run full test suite.

**Acceptance criteria:**
- [ ] `BACKEND_CONTEXT.md` says billing auto-generates on confirmation
- [ ] Status flow documented as `issued → partially_paid → paid` (+ voided)
- [ ] Full test suite passes (352+ tests)

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact`

**Dependencies:** Task 5

**Files likely touched:**
- `docs/BACKEND_CONTEXT.md`

**Estimated scope:** XS (1 file)

---

### Checkpoint: Complete
- [ ] All acceptance criteria met
- [ ] Full test suite green
- [ ] Billing flow works end-to-end in Filament

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Existing tests assert `draft` status | Med | Update in Task 1 before other changes |
| Auto-billing fails silently | Low | Log the error, add monitoring test |
| Void action on RelationManager conflicts with header action | Low | Remove void from header after RelationManager is in place |

## Out of Scope

- Printing/receipts
- Fixed percentage enforcement
- Installment plans beyond downpayment + balance
- Insurance billing, refunds
- Removing `draft` from `billing_statuses` table
