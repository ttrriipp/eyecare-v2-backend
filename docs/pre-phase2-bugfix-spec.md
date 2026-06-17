# Spec: Pre-Phase-2 Bugfixes & UX Corrections

Status: Draft — awaiting review
Phase: Specification

## Assumptions

1. This is a bugfix/UX pass on the existing implementation — not new features.
2. `users.email` is currently NOT NULL with a unique index; `users.password` is NOT NULL. Walk-in customer support requires making both nullable.
3. Walk-in customers are "clinic record" customers — they have a name and phone but no email/password. They cannot log in to the mobile app.
4. The `StoreAppointmentRequest` already validates `'scheduled_at' => ['required', 'date', 'after:now']` — the API is fine. The gap is in the Filament create form (no future-date validation).
5. The `UpdateAppointmentStatus` and `UpdateOrderStatus` actions already create audit logs for every status transition — this is already working.
6. The edit form status dropdown currently allows direct status selection which bypasses the action's transition rules (partially — it goes through the action in `mutateFormDataBeforeSave`, but the UX is confusing and allows attempting invalid transitions that then throw errors).
7. The prescription system already works with any `customer_id` FK — it doesn't check registration status. This just needs verification, not code changes.
8. Existing test suite stays green (adapted where needed).

Correct these assumptions before approval if any are wrong.

## Objective

Fix UX/validation gaps so the admin panel behaves like a real clinic system: staff can serve walk-in patients, status fields only present valid options, and appointments cannot be created in the past.

## Tech Stack

Same as all previous phases. No new dependencies.

## Commands

```
Run tests:          vendor/bin/sail artisan test --compact
Run filtered:       vendor/bin/sail artisan test --compact --filter=SomeName
Fresh seed:         vendor/bin/sail artisan migrate:fresh --seed --no-interaction
Format PHP:         vendor/bin/sail bin pint --dirty --format agent
```

## Boundaries

- **Always:** Run affected tests after each task. Run pint after PHP edits. Keep existing tests green (adapt, don't delete).
- **Ask first:** Changing the mobile API contract. Making email unique constraint conditional.
- **Never:** Break the demo seed flow. Remove existing tests without replacement.

## Decisions

1. Walk-in customers get `email` = null, `password` = null. The unique constraint on email remains (unique among non-null values — MySQL allows multiple NULLs in unique indexes).
2. Staff can quick-create a customer inline in the appointment/order create forms (name + phone required, email optional, no password).
3. On create forms: status field is hidden/removed — system auto-assigns the correct initial status (`pending` for appointments, `requested` for orders).
4. On edit forms: the status select dropdown is removed entirely. Status changes happen only through table row actions and page header actions (which already enforce valid transitions).
5. Filament `scheduled_at` field validates `after:now` on create. On edit (reschedule), it validates `after:now` as well.
6. Audit logs for status changes: already implemented in both actions — no code change needed. Verification only.
7. Prescriptions for walk-in customers: already works since it's just an FK to users — verify with a test.

## Success Criteria

- [ ] Staff can create a walk-in customer (name + phone, no email/password) from within the appointment and order create forms.
- [ ] Walk-in customers cannot log in to the API (no credentials).
- [ ] Appointment create form has no status field; appointment is always created as `pending`.
- [ ] Order create form has no status field; order is always created as `requested`.
- [ ] Appointment edit form has no status select — status shown as read-only text or badge.
- [ ] Order edit form has no status select — status shown as read-only text or badge.
- [ ] Filament appointment create form validates `scheduled_at` must be in the future.
- [ ] Filament reschedule action validates new datetime must be in the future.
- [ ] Prescriptions can be created for walk-in customers (no email/password) — verified by test.
- [ ] Every appointment status change creates an audit log entry (verified — already done).
- [ ] Every order status change creates an audit log entry (verified — already done).
- [ ] All existing tests remain green (adapted for schema changes).

## Implementation Plan

### Task List

#### Task 1: Make Email and Password Nullable for Walk-In Customers

**Description:** Alter `users` table to allow null email and password so staff can create walk-in customer records.

**Acceptance criteria:**
- [ ] `users.email` nullable (unique index still works — MySQL allows multiple NULLs).
- [ ] `users.password` nullable.
- [ ] Existing users with email/password are unaffected.
- [ ] API registration still requires email + password (form request validation unchanged).
- [ ] API login still requires email + password.
- [ ] User factory still creates users with email/password by default.
- [ ] User factory gains a `walkIn()` state: name + phone only, no email, no password.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=Auth`
- [ ] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`

**Dependencies:** None

**Files likely touched:**
- New migration (alter `users`: email nullable, password nullable)
- `database/factories/UserFactory.php` (add `walkIn` state)
- Existing auth tests (verify unchanged behavior)

**Estimated scope:** S

---

#### Task 2: Inline Walk-In Customer Creation in Filament Forms

**Description:** Appointment and order create forms allow staff to quick-create a customer record (name + phone) instead of selecting only existing customers.

**Acceptance criteria:**
- [ ] Appointment create form: customer select has a "Create customer" option that opens a modal with name (required) + phone (required) + email (optional).
- [ ] Order create form: same quick-create capability.
- [ ] Quick-created customer is assigned the `customer` role automatically.
- [ ] Quick-created customer has no password (cannot log in).
- [ ] After quick-create, the new customer is auto-selected in the form.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=AppointmentResource`
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=OrderResource`

**Dependencies:** Task 1

**Files likely touched:**
- `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`
- `app/Filament/Resources/Orders/Schemas/OrderForm.php`
- `tests/Feature/Filament/AppointmentResourceTest.php` (add walk-in test)
- `tests/Feature/Filament/OrderResourceTest.php` (add walk-in test)

**Estimated scope:** S

---

#### Task 3: Lock Status on Create Forms

**Description:** Remove status field from appointment and order create forms; system assigns initial status automatically.

**Acceptance criteria:**
- [ ] Appointment create form: no `appointment_status_id` field. The `CreateAppointment` page hardcodes status to `pending` during creation.
- [ ] Order create form: no `order_status_id` field. The `CreateOrder` page hardcodes status to `requested` during creation.
- [ ] Existing create tests adapted (no longer pass status in form data).

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=AppointmentResource`
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=OrderResource`

**Dependencies:** None

**Files likely touched:**
- `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`
- `app/Filament/Resources/Appointments/Pages/CreateAppointment.php`
- `app/Filament/Resources/Orders/Schemas/OrderForm.php`
- `app/Filament/Resources/Orders/Pages/CreateOrder.php`
- Existing Filament tests (adapt form fill data)

**Estimated scope:** S

---

#### Task 4: Remove Status Dropdown from Edit Forms

**Description:** Replace editable status select with read-only display on edit forms. Status changes happen exclusively through table/page actions.

**Acceptance criteria:**
- [ ] Appointment edit form: status shown as disabled/read-only placeholder or removed entirely. Not editable.
- [ ] Order edit form: same — status not editable through the form.
- [ ] `EditAppointment` page no longer needs `mutateFormDataBeforeSave` status handling (simplify).
- [ ] `EditOrder` page no longer needs `mutateFormDataBeforeSave` status handling (simplify).
- [ ] Table row actions remain the only way to change status (already working).

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=AppointmentResource`
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=OrderResource`

**Dependencies:** None

**Files likely touched:**
- `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`
- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `app/Filament/Resources/Orders/Schemas/OrderForm.php`
- `app/Filament/Resources/Orders/Pages/EditOrder.php`
- Existing Filament tests (adapt)

**Estimated scope:** S

---

#### Task 5: Future Date Validation on Appointment Creation and Reschedule

**Description:** Filament appointment create form and reschedule action validate that `scheduled_at` is in the future.

**Acceptance criteria:**
- [ ] Appointment create form: `scheduled_at` field has `minDate(now())` or equivalent validation preventing past dates.
- [ ] Reschedule action form: same validation on the new datetime picker.
- [ ] Test confirms past-date appointment creation fails validation in Filament.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=AppointmentResource`

**Dependencies:** None

**Files likely touched:**
- `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`
- `app/Filament/Resources/Appointments/Tables/AppointmentsTable.php` (reschedule action)
- `tests/Feature/Filament/AppointmentResourceTest.php` (add past-date test)

**Estimated scope:** S

---

#### Task 6: Verify Prescriptions Work for Walk-In Customers

**Description:** Confirm (via test) that staff can create a prescription for a walk-in customer (no email/password).

**Acceptance criteria:**
- [ ] A Pest test creates a walk-in customer (using factory `walkIn` state), then creates a prescription for them via Filament.
- [ ] Test passes without code changes (just verification).
- [ ] If it fails, fix the blocker.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=Prescription`

**Dependencies:** Task 1

**Files likely touched:**
- `tests/Feature/Filament/PrescriptionResourceTest.php` (add walk-in test)

**Estimated scope:** S

---

#### Task 7: Verify Audit Logs for All Status Transitions

**Description:** Confirm (via test) that every appointment and order status transition creates an audit log.

**Acceptance criteria:**
- [ ] A test transitions an appointment through all states and asserts audit logs exist for each.
- [ ] A test transitions an order through all states and asserts audit logs exist for each.
- [ ] If any transition is missing an audit entry, fix it.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=AuditLog`

**Dependencies:** None

**Files likely touched:**
- `tests/Feature/AuditLogRecordingTest.php` (add/verify status transition audit tests)

**Estimated scope:** S

---

#### Task 8: Record Payment Action in Filament Billing

**Description:** Add a Filament action on the billing view/list page that lets staff record a manual payment against a billing, triggering balance recalculation.

**Acceptance criteria:**
- [ ] Billing view page or table row has a "Record Payment" action.
- [ ] Action form: amount (required, numeric, > 0, ≤ balance_due), method (text for now — Phase 2 replaces with lookup FK), reference number (optional), notes (optional), paid_at (defaults to now).
- [ ] Payment is created with `posted` status.
- [ ] `RecalculateBillingBalance` is called after creation — updates amount_paid, balance_due, and billing status.
- [ ] Action is hidden when billing is fully paid (`balance_due` = 0) or voided.
- [ ] Audit log records the payment.
- [ ] A "Void Payment" action exists on the billing view page for individual payments — sets payment status to `voided` and recalculates.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=Payment`
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=BillingResource`

**Dependencies:** None

**Files likely touched:**
- `app/Filament/Resources/Billings/Pages/ViewBilling.php` (add record payment action + payment list)
- `app/Filament/Resources/Billings/Tables/BillingsTable.php` (add record payment table action)
- `app/Actions/Billing/RecordPayment.php` (new action: create payment + recalculate)
- `tests/Feature/Filament/BillingResourceTest.php` (add payment recording tests)

**Estimated scope:** M

---

### Checkpoint: Complete

- [ ] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] Walk-in customer can be created and served
- [ ] Status fields locked on create/edit forms
- [ ] Past-date appointments rejected in Filament
- [ ] Audit trail complete for all status changes
- [ ] Staff can record and void payments against billings

---

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Making email nullable breaks unique constraint behavior | Low | MySQL allows multiple NULLs in unique indexes — verify with test |
| Making password nullable breaks auth middleware or Sanctum | Low | Auth routes validate email+password in form requests before reaching the model |
| Removing status from edit forms breaks existing Filament tests that fill status | Med | Adapt tests in same task — remove status from `fillForm` calls |
| Quick-create modal may not be straightforward in Filament 5 | Low | Filament Select has `->createOptionForm()` built-in |

## Summary

| Task | Description | Effort |
|------|-------------|--------|
| 1 | Email/password nullable for walk-ins | S |
| 2 | Inline walk-in customer creation | S |
| 3 | Lock status on create forms | S |
| 4 | Remove status from edit forms | S |
| 5 | Future date validation | S |
| 6 | Verify prescriptions for walk-ins | S |
| 7 | Verify audit logs complete | S |
| 8 | Record/void payment action in Filament | M |
| **Total** | **8 tasks** | |

## Impact on Phase 2 Spec

The Phase 2 spec (`docs/post-mvp-phase2-spec.md`) requires the following adjustments after this bugfix pass:

1. **Task B1 (User Management):** Must account for walk-in customers (no email/password) in the user list and edit forms. Add a "registered" vs "walk-in" indicator in the table.
2. **Task A3 (Staff Assignment):** No changes needed — works independently.
3. **Task C4 (Staff Filament Notifications):** Walk-in customers won't receive in-app notifications (they don't use the app). Only registered customers get notified. Add a guard: `if ($customer->password !== null)` before dispatching customer notifications.

These are minor adjustments — I'll update the Phase 2 spec after this bugfix pass is approved and implemented.

## Review Gate

Plan awaiting approval. All 7 tasks are small and independent (except Task 2 depends on Task 1). Could be completed in one focused session.
