# Spec: Unified Billing Flow (GetOrCreateBilling)

## Objective

Unify billing creation into a single path: all billable events (order confirmation, service billing) route through `GetOrCreateBilling` before adding line items. This prevents duplicate billings when services and orders occur in the same visit, and adds `appointment_id` to billings as the natural "encounter" grouping key.

**Success criteria:**
- `appointment_id` (nullable FK) on billings links a billing to its visit
- `GetOrCreateBilling` finds an existing billing for the same customer + appointment, or creates one
- Order confirmation uses `GetOrCreateBilling` + `AddOrderItemsToBilling` (replaces current `GenerateBillingForOrder`)
- Service billing uses `GetOrCreateBilling` + `AddServiceToBilling` (replaces `CreateServiceBilling`)
- If a service is billed before an order is confirmed for the same appointment, they share one billing
- All existing tests pass
- Seeders produce correct demo data

## Tech Stack

PHP 8.5 / Laravel 13 / Filament 5 / Pest 4 / MySQL

## Commands

```
Test:   vendor/bin/sail artisan test --compact
Filter: vendor/bin/sail artisan test --compact --filter=Billing
Lint:   vendor/bin/sail bin pint --dirty --format agent
Fresh:  vendor/bin/sail artisan migrate:fresh --seed
```

## Schema Change

### Modified: `billings`

| Change | Detail |
|---|---|
| Add | `appointment_id` FK appointments, nullable, after `order_id` |

No other schema changes. `billing_items`, `service_records`, `payments` — all unchanged.

## Actions (after refactor)

| Action | Behavior |
|---|---|
| `GetOrCreateBilling` | Takes `customer_id`, `appointment_id` (nullable). Finds existing non-voided billing for that customer+appointment pair. If none, creates a new issued billing. Returns the billing. |
| `AddOrderItemsToBilling` | Takes a billing + order. Creates product billing_items from order_items. Sets `order_id` on the billing. Copies discount from order. Recalculates subtotal/total. |
| `AddServiceToBilling` | Unchanged — already adds a service line item and recalculates. |
| `GenerateBillingForOrder` | Refactored to: call `GetOrCreateBilling(customer, appointment)` → `AddOrderItemsToBilling(billing, order)` → notification + audit. |
| `CreateServiceBilling` | Refactored to: call `GetOrCreateBilling(customer, appointment)` → `AddServiceToBilling(billing, data)` → notification + audit. |

## GetOrCreateBilling Logic

```
function handle(customer_id, appointment_id = null):
    if appointment_id is not null:
        billing = find non-voided billing where customer_id + appointment_id match
        if found: return billing
    
    create new billing:
        customer_id, appointment_id, status=issued, issued_at=now
        subtotal=0, discount_amount=0, total_amount=0, amount_paid=0, balance_due=0
    
    return billing
```

**Key rule:** Only groups by appointment. If `appointment_id` is null (walk-in service with no appointment), always creates a new billing — no grouping.

## Filament UI Changes

- "Bill Service" on Appointment page: passes `appointment_id` to the action flow (already does)
- "Bill Service" on Patient page: no appointment → always new billing (unchanged behavior)
- "Add Service" on ViewBilling: adds to that specific billing (unchanged)
- No new UI needed

## Boundaries

**Always:**
- Run full test suite after refactor
- Keep `AddServiceToBilling` as-is (it already works correctly)

**Ask first:**
- Changing the mobile API billing response

**Never:**
- Break order confirmation → billing auto-generation
- Remove `order_id` from billings (still useful for "view order" link)

## Tasks

### Phase 1: Schema + New Actions

- [x] **Task 1: Add `appointment_id` to billings**
  - Description: Migration adds nullable FK. Update Billing model (fillable, relationship). Update BillingFactory.
  - Acceptance:
    - [ ] `appointment_id` nullable FK to appointments exists on billings
    - [ ] `Billing::appointment()` relationship works
    - [ ] Migration runs clean
  - Verify: `vendor/bin/sail artisan migrate`
  - Dependencies: None
  - Files: migration, `app/Models/Billing.php`, `database/factories/BillingFactory.php`
  - Scope: XS

- [x] **Task 2: Create `GetOrCreateBilling` action + tests**
  - Description: Finds existing non-voided billing for customer+appointment, or creates a new issued one.
  - Acceptance:
    - [ ] With appointment_id: reuses existing billing if one exists
    - [ ] With appointment_id: creates new if none exists
    - [ ] With null appointment_id: always creates new billing
    - [ ] Never returns a voided billing
    - [ ] 4 tests pass
  - Verify: `vendor/bin/sail artisan test --compact --filter=GetOrCreateBilling`
  - Dependencies: Task 1
  - Files: `app/Actions/Billing/GetOrCreateBilling.php`, test file
  - Scope: S

- [x] **Task 3: Create `AddOrderItemsToBilling` action**
  - Description: Takes billing + order. Creates product billing_items, sets order_id, copies discount, recalculates totals.
  - Acceptance:
    - [ ] Creates one billing_item per order_item (type: product)
    - [ ] Sets `order_id` on the billing
    - [ ] Copies `discount_type_id` + `discount_amount` from order
    - [ ] Recalculates `subtotal`, `total_amount`, `balance_due`
    - [ ] Test passes
  - Verify: `vendor/bin/sail artisan test --compact --filter=AddOrderItems`
  - Dependencies: Task 1
  - Files: `app/Actions/Billing/AddOrderItemsToBilling.php`, test file
  - Scope: S

### ✓ Checkpoint: New actions work independently
- [ ] `vendor/bin/sail artisan test --compact --filter=Billing` — new tests pass
- [ ] Existing tests unaffected (haven't changed old actions yet)

---

### Phase 2: Refactor existing actions

- [x] **Task 4: Refactor `GenerateBillingForOrder`**
  - Description: Replace inline billing creation with `GetOrCreateBilling` → `AddOrderItemsToBilling`. Pass `$order->appointment_id` as the appointment.
  - Acceptance:
    - [ ] Uses `GetOrCreateBilling` to find/create billing
    - [ ] Uses `AddOrderItemsToBilling` to populate items
    - [ ] Notification + audit still fire
    - [ ] Duplicate guard: if order already has items on this billing, throws
    - [ ] All existing `BillingGeneration` tests pass unchanged
  - Verify: `vendor/bin/sail artisan test --compact --filter=BillingGeneration`
  - Dependencies: Tasks 2, 3
  - Files: `app/Actions/Billing/GenerateBillingForOrder.php`
  - Scope: S

- [x] **Task 5: Refactor `CreateServiceBilling`**
  - Description: Replace inline billing creation with `GetOrCreateBilling(customer_id, appointment_id)` → `AddServiceToBilling`. Forward `appointment_id` from data.
  - Acceptance:
    - [ ] Uses `GetOrCreateBilling` with appointment_id from data
    - [ ] If billing already exists for that appointment, adds service there
    - [ ] Notification + audit still fire (only on new billing creation)
    - [ ] All existing `ServiceBillingActions` tests pass
  - Verify: `vendor/bin/sail artisan test --compact --filter=ServiceBillingActions`
  - Dependencies: Task 2
  - Files: `app/Actions/Billing/CreateServiceBilling.php`
  - Scope: S

- [x] **Task 6: Update Filament "Bill Service" to pass `appointment_id`**
  - Description: EditAppointment already passes appointment_id in its action. Ensure it flows through CreateServiceBilling → GetOrCreateBilling. EditPatient passes null (no change needed).
  - Acceptance:
    - [ ] Appointment "Bill Service" passes `appointment_id` into `CreateServiceBilling`
    - [ ] If appointment has existing billing, service is added there (not a new billing)
    - [ ] Patient "Bill Service" still creates new billing (no appointment)
  - Verify: `vendor/bin/sail artisan test --compact --filter=Appointment`
  - Dependencies: Task 5
  - Files: `app/Filament/Resources/Appointments/Pages/EditAppointment.php` (verify only — likely already correct)
  - Scope: XS

### ✓ Checkpoint: All billing flows unified
- [ ] `vendor/bin/sail artisan test --compact` — full suite, same 3 pre-existing failures only
- [ ] `vendor/bin/sail artisan migrate:fresh --seed` — clean

---

### Phase 3: Cleanup

- [x] **Task 7: Update seeders + full regression**
  - Description: Set `appointment_id` on demo billings in ClinicWorkflowSeeder. Verify idempotency.
  - Acceptance:
    - [ ] Demo prescription order billing has `appointment_id` set
    - [ ] Demo service billing has `appointment_id` set
    - [ ] Seeder idempotent (run twice, no duplicates)
    - [ ] Full test suite: 3 pre-existing failures only
    - [ ] Pint clean
  - Verify: `vendor/bin/sail artisan migrate:fresh --seed` + `vendor/bin/sail artisan test --compact`
  - Dependencies: Task 4, 5
  - Files: `database/seeders/ClinicWorkflowSeeder.php`
  - Scope: XS

### ✓ Final Checkpoint
- [ ] Unified billing flow works end-to-end
- [ ] Service billed before order → same billing
- [ ] Order confirmed before service → service adds to order's billing
- [ ] No-appointment services → always new billing

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Duplicate guard changes break order confirmation tests | Med | Task 4 runs BillingGeneration tests immediately |
| `CreateServiceBilling` notification fires on reused billing | Low | Only notify if billing was newly created (check `wasRecentlyCreated`) |
| Seeder appointment_id mismatch | Low | Seeder already has the appointment object in scope |

