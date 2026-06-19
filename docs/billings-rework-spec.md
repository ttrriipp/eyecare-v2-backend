# Billings Rework Spec

> **Intent:** Billing auto-generates on order confirmation, starts as `issued` (no draft), staff records payments freely (50% downpayment hint), balance auto-tracks.

---

## Current State

- `GenerateBillingForOrder` is triggered manually from ListBillings page action
- Billing starts in `draft` status
- Staff manually transitions to `issued`
- ViewBilling page has Record Payment and Void Payment header actions
- EditBilling allows due_date/notes
- `RecalculateBillingBalance` auto-transitions: `partially_paid` → `paid`

## Target State

- Billing auto-generates when order is confirmed (inside `UpdateOrderStatus`)
- No `draft` status — billing starts as `issued` with `issued_at = now()`
- Remove manual "Generate Billing" action from ListBillings
- Record Payment modal shows 50% hint as helper text
- Payment history visible inline (relation manager or infolist repeatable)
- Void payment remains available

---

## Tasks

### Task 1 — Auto-generate billing on order confirmation

**File:** `app/Actions/Orders/UpdateOrderStatus.php`

**Changes:**
- After successful confirmation (inventory deducted, status saved), call `GenerateBillingForOrder`
- If billing generation fails (shouldn't happen since order is freshly confirmed), log but don't block the confirmation

**Tests to update:**
- `tests/Feature/Billing/BillingGenerationTest.php` — existing tests still valid; add a test that confirming an order creates a billing
- `tests/Feature/Api/OrderProcessingTest.php` — assert billing exists after confirm

### Task 2 — Remove `draft` status, start as `issued`

**File:** `app/Actions/Billing/GenerateBillingForOrder.php`

**Changes:**
- Use `issued` status instead of `draft`
- Set `issued_at = now()` on creation

**File:** `database/seeders/BillingStatusSeeder.php`

**Changes:**
- Keep `draft` in the seeder (don't break existing data) but it won't be used for new billings

**Tests to update:**
- `tests/Feature/Billing/BillingGenerationTest.php` — change assertions from `draft` to `issued`
- `tests/Feature/Filament/BillingResourceTest.php` — update factory calls that use `draft()` state where testing new creation flow

### Task 3 — Remove manual "Generate Billing" action from ListBillings

**File:** `app/Filament/Resources/Billings/Pages/ListBillings.php`

**Changes:**
- Remove `generate_billing` header action entirely (billing is now automatic)

**Tests to update:**
- `tests/Feature/Filament/BillingResourceTest.php` — remove the 3 tests for `generate_billing` action (generate, duplicate blocked, non-confirmed blocked)

### Task 4 — Add 50% payment hint to Record Payment

**File:** `app/Filament/Resources/Billings/Pages/ViewBilling.php`

**Changes:**
- Add `->helperText()` to the amount field showing "50% = ₱{half}" calculated from `balance_due` or `total_amount`
- On first payment (no payments yet): hint shows 50% of total
- On subsequent payments: no special hint (just pay what's owed)

### Task 5 — Payments relation manager on ViewBilling

**File:** `app/Filament/Resources/Billings/RelationManagers/PaymentsRelationManager.php` (new)

**Changes:**
- Table showing: date (paid_at), method, amount, status, reference
- Void action per row (for posted payments only)
- Read-only — payments created via the header "Record Payment" action

**File:** `app/Filament/Resources/Billings/BillingResource.php`

**Changes:**
- Register `PaymentsRelationManager` in `getRelations()`

**Tests:**
- Test that payments table renders on ViewBilling

### Task 6 — Clean up tests and verify

- Run full test suite
- Update `docs/BACKEND_CONTEXT.md`:
  - Remove "Generated manually by staff" from billing conventions
  - Update billing status flow to: `issued → partially_paid → paid` (+ voided)
  - Note auto-generation on confirmation

---

## Summary

| # | Task | Files Modified |
|---|---|---|
| 1 | Auto-generate on confirm | `UpdateOrderStatus`, tests |
| 2 | Remove draft, start as issued | `GenerateBillingForOrder`, tests |
| 3 | Remove manual generate action | `ListBillings`, tests |
| 4 | 50% payment hint | `ViewBilling` |
| 5 | Payments relation manager | New RelationManager, `BillingResource` |
| 6 | Test cleanup + docs | Tests, `BACKEND_CONTEXT.md` |

---

## Out of Scope

- Printing/receipts
- Fixed percentage enforcement (staff enters any amount)
- Installment plans beyond downpayment + balance
- Insurance billing
- Refunds (separate from void)
- Removing `draft` from the `billing_statuses` table (existing data compatibility)
