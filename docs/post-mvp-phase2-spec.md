# Spec: Post-MVP Phase 2 — Operations & Notifications

Status: In Progress — Tasks A1, A2, A3 complete
Phase: Implementation

## Assumptions

1. This builds on top of the completed MVP (29 tasks) and Post-MVP Polish (17 tasks).
2. The Android mobile app is a separate repo consuming this API; breaking changes are acceptable since the app is not deployed.
3. No new Composer/npm dependencies without approval.
4. The existing `notification_channels` and `notification_templates` tables are empty shells and can be replaced or extended.
5. The `notification_statuses` table and seeder already exist (used by SMS notifications).
6. `payments.method` is currently a free-text varchar — will become an FK to a lookup table.
7. `inventory_movements.type` is currently a free-text varchar — will become an FK to a lookup table.
8. Staff-applied discounts use predefined types (no customer coupon codes).
9. In-app notifications use Laravel's database notification channel (`notifications` table) — not a custom table.
10. Filament staff notifications use Filament's built-in database notification system.
11. Existing test suite stays green throughout — no breaking existing tests without replacement.

Correct these assumptions before approval if any are wrong.

## Objective

Extend the backend to close operational gaps identified in the PROJECT_MASTER_SPEC: user management, staff assignment, lookup table normalization, staff-applied discounts, and a full in-app notification system for both customers and staff.

Primary outcomes:

- Admin can manage users (list, create, edit, assign roles) through Filament.
- Appointments track which staff member is assigned.
- Payment methods and inventory movement types use normalized lookup tables instead of free-text.
- Staff can apply predefined discount types when processing orders; billing reflects discounted totals.
- Customers receive in-app notifications for key workflow events (appointment status, order status, billing issued) and can list/mark-read via API.
- Staff/admin receive Filament notifications for new bookings, new order requests, and new messages.

## Tech Stack

Same as previous phases — no new dependencies.

- PHP 8.5, Laravel 13, Filament 5, Pest 4, MySQL, Sail

## Commands

```
Build frontend:     vendor/bin/sail npm run build
Run tests:          vendor/bin/sail artisan test --compact
Run filtered:       vendor/bin/sail artisan test --compact --filter=SomeName
Fresh seed:         vendor/bin/sail artisan migrate:fresh --seed --no-interaction
Format PHP:         vendor/bin/sail bin pint --dirty --format agent
Route list:         vendor/bin/sail artisan route:list --except-vendor
```

## Project Structure

No new top-level directories. New files follow existing conventions:

- `app/Filament/Resources/Users/` → User management resource
- `app/Models/PaymentMethod.php` → Payment methods lookup
- `app/Models/InventoryMovementType.php` → Movement types lookup
- `app/Models/DiscountType.php` → Predefined discount types
- `app/Notifications/` → Laravel notification classes
- `app/Http/Controllers/Api/NotificationController.php` → Customer notification API
- `database/migrations/` → Schema changes
- `database/seeders/` → Lookup data seeders

## Code Style

Same conventions as MVP and Post-MVP Polish specs. Typed Laravel code, form requests, small controllers, descriptive names, factories for all new models.

## Testing Strategy

Same as previous phases:

- Feature tests for API endpoints and Filament resource operations.
- Unit tests for discount calculation logic.
- Every task includes at least one happy-path test and one validation/authorization failure test.
- External services faked in tests.
- Run affected tests after each task; full suite green at checkpoints.

## Boundaries

- **Always:** Run affected tests after each task. Run pint after PHP edits. Keep existing tests green (adapt, don't delete). Update seeders if schema changes. Update factories if columns change.
- **Ask first:** Adding dependencies. Changing auth flow. Removing existing API endpoints. Changing the SMS notification structure.
- **Never:** Break the demo seed flow. Store biometrics. Remove tests without replacement. Hard-delete business records. Send real notifications in tests.

## Decisions

1. Payment methods are a lookup table: Cash, GCash, Bank Transfer, Credit Card, Check. Extensible by admin.
2. Inventory movement types are a lookup table: Restock, Sale, Adjustment, Return. Extensible by admin.
3. Staff-applied discounts use predefined types: Senior Citizen (20%), PWD (20%), Loyalty (10%), Custom (staff enters amount). No customer coupon codes.
4. Discount is applied at order level, not per-item. `discount_amount` already exists on orders; we add `discount_type_id` FK.
5. In-app notifications for customers use Laravel's `notifications` table (database driver). No custom notifications table.
6. Filament staff notifications use Filament's built-in database notification system (same underlying `notifications` table, different recipient).
7. Staff assignment is a nullable FK on appointments — not all appointments need a staff member assigned.
8. User management is admin-only (not staff).

## Success Criteria

- [ ] Admin can list, create, edit users and assign roles in Filament.
- [ ] Appointments have optional `staff_id`; shown in API response and Filament form/table.
- [ ] Staff can filter appointments assigned to them.
- [ ] `payments.method` replaced with `payment_method_id` FK to `payment_methods` lookup table.
- [ ] `inventory_movements.type` replaced with `inventory_movement_type_id` FK to `inventory_movement_types` lookup table.
- [ ] Staff can apply a predefined discount type when confirming/processing an order.
- [ ] Discount recalculates `total_amount` = `subtotal` - `discount_amount`; billing reflects the discounted total.
- [ ] Customer receives in-app notifications for: appointment confirmed/rescheduled/cancelled, order status change, billing issued.
- [ ] Customer API: `GET /notifications` (paginated list), `POST /notifications/mark-read` (mark one or all as read), unread count.
- [ ] Staff/admin receive Filament notifications for: new appointment booking, new order request, new message received.
- [ ] All new behavior has Pest tests.
- [ ] Existing test suite remains green (adapted where needed).
- [ ] Demo seed data updated to include discount examples and notification records.

## Open Questions

None — all resolved during discussion.

## Implementation Plan

### Architecture Decisions

- Payment methods and movement types follow the same lookup table pattern as existing statuses (id, name, timestamps).
- Discount types table stores: name, type (percentage/fixed), value, is_active. Staff selects from active types.
- Customer notifications use `$user->notify(new OrderStatusChanged($order))` pattern with database channel.
- Filament notifications use `Filament\Notifications\Notification::make()->sendToDatabase($recipients)`.
- Staff assignment adds `staff_id` nullable FK to appointments; Filament form gets a staff select field; API response includes assigned staff.

### Dependency Graph

```
Lookup Table Migrations (payment methods, movement types)
    │
    ├── Update Payment model (FK instead of varchar)
    │
    └── Update InventoryMovement model (FK instead of varchar)

Staff Assignment Migration
    │
    └── Update Appointment model, API, Filament

Discount Types
    │
    └── Order discount application logic
            │
            └── Billing recalculation

User Management Resource (independent)

Notifications Schema (Laravel notifications table)
    │
    ├── Customer notification classes + triggers
    │       │
    │       └── Customer notification API
    │
    └── Staff Filament notifications + triggers

Seeder & test adaptation (depends on all above)
```

### Task List

#### Phase A: Lookup Tables & Staff Assignment

##### Task A1: Payment Methods Lookup Table

**Description:** Add `payment_methods` lookup table and migrate `payments.method` varchar to FK.

**Acceptance criteria:**
- [ ] `payment_methods` table with id, name, is_active, timestamps.
- [ ] Seeded with: Cash, GCash, Bank Transfer, Credit Card, Check.
- [ ] `payments.payment_method_id` FK replaces `payments.method` varchar.
- [ ] Payment model updated with `paymentMethod()` relationship.
- [ ] Existing payment-related tests adapted.
- [ ] Filament billing/payment forms use select from lookup.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=Payment`
- [ ] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`

**Dependencies:** None

**Files likely touched:**
- New migration (add `payment_methods` table, add `payment_method_id` to payments, drop `method` column)
- `app/Models/PaymentMethod.php`
- `app/Models/Payment.php`
- `database/seeders/PaymentMethodSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- `database/factories/PaymentFactory.php`
- Filament billing form/resource
- Existing payment tests

**Estimated scope:** S

---

##### Task A2: Inventory Movement Types Lookup Table

**Description:** Add `inventory_movement_types` lookup table and migrate `inventory_movements.type` varchar to FK.

**Acceptance criteria:**
- [ ] `inventory_movement_types` table with id, name, timestamps.
- [ ] Seeded with: Restock, Sale, Adjustment, Return.
- [ ] `inventory_movements.inventory_movement_type_id` FK replaces `inventory_movements.type` varchar.
- [ ] InventoryMovement model updated with `movementType()` relationship.
- [ ] Existing inventory movement tests adapted.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=Inventory`
- [ ] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`

**Dependencies:** None

**Files likely touched:**
- New migration (add `inventory_movement_types` table, add FK to movements, drop `type` column)
- `app/Models/InventoryMovementType.php`
- `app/Models/InventoryMovement.php`
- `app/Actions/Inventory/RecordInventoryMovement.php`
- `database/seeders/InventoryMovementTypeSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- `database/factories/InventoryMovementFactory.php`
- Existing inventory tests

**Estimated scope:** S

---

##### Task A3: Staff Assignment on Appointments

**Description:** Add nullable `staff_id` FK to appointments so staff members can be assigned.

**Acceptance criteria:**
- [ ] `appointments.staff_id` nullable FK to users exists.
- [ ] Appointment model has `staff()` BelongsTo relationship.
- [ ] API `AppointmentResource` response includes assigned staff (id, name) when present.
- [ ] Filament appointment form includes staff select (filtered to staff/admin roles).
- [ ] Filament appointment table shows assigned staff column.
- [ ] Staff can filter appointments table by "assigned to me."

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=Appointment`
- [ ] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`

**Dependencies:** None

**Files likely touched:**
- New migration (add `staff_id` to appointments)
- `app/Models/Appointment.php`
- `app/Http/Resources/AppointmentResource.php`
- `database/factories/AppointmentFactory.php`
- Filament appointment form/table schemas
- Existing appointment tests (adapt assertions)

**Estimated scope:** S

---

#### Checkpoint: Phase A

- [ ] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] Payment methods, movement types use lookup tables
- [ ] Appointments support staff assignment

---

#### Phase B: User Management & Discounts

##### Task B1: User Management Filament Resource

**Description:** Admin can list, create, and edit users with role assignment.

**Acceptance criteria:**
- [ ] Filament resource with list, create, edit pages for users.
- [ ] Admin-only access (staff cannot manage users).
- [ ] Create form: name, email, phone, password, role select.
- [ ] Edit form: name, email, phone, role select (password only if changing).
- [ ] Table shows name, email, role, created date.
- [ ] Table filters by role.
- [ ] Cannot delete users (soft delete not on users; just disable or leave as-is).

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=UserResource`

**Dependencies:** None

**Files likely touched:**
- `app/Filament/Resources/Users/UserResource.php`
- `app/Filament/Resources/Users/Pages/ListUsers.php`
- `app/Filament/Resources/Users/Pages/CreateUser.php`
- `app/Filament/Resources/Users/Pages/EditUser.php`
- `app/Filament/Resources/Users/Schemas/UserForm.php`
- `app/Filament/Resources/Users/Tables/UsersTable.php`
- `tests/Feature/Filament/UserResourceTest.php`

**Estimated scope:** S

---

##### Task B2: Discount Types Schema and Seeder

**Description:** Add predefined discount types for staff-applied discounts.

**Acceptance criteria:**
- [ ] `discount_types` table: id, name, type (enum: `percentage`, `fixed`), value (decimal), is_active, timestamps.
- [ ] Seeded with: Senior Citizen (percentage, 20), PWD (percentage, 20), Loyalty (percentage, 10), Custom (fixed, 0 — staff enters amount manually).
- [ ] `orders.discount_type_id` nullable FK added.
- [ ] Order model has `discountType()` BelongsTo relationship.
- [ ] DiscountType model with factory.

**Verification:**
- [ ] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=DiscountType`

**Dependencies:** None

**Files likely touched:**
- New migration (create `discount_types`, add `discount_type_id` to orders)
- `app/Models/DiscountType.php`
- `app/Models/Order.php`
- `database/factories/DiscountTypeFactory.php`
- `database/seeders/DiscountTypeSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- `tests/Feature/DiscountTypeTest.php`

**Estimated scope:** S

---

##### Task B3: Staff-Applied Discount Workflow

**Description:** Staff applies a discount when processing an order; billing reflects the discounted total.

**Acceptance criteria:**
- [ ] Staff can apply a discount type when moving an order to `confirmed` (via API or Filament action).
- [ ] For percentage types, `discount_amount` = `subtotal` × (value / 100).
- [ ] For "Custom" type, staff provides the amount manually.
- [ ] `total_amount` recalculates as `subtotal` - `discount_amount`.
- [ ] Billing generation uses the discounted `total_amount`.
- [ ] Discount cannot exceed subtotal.
- [ ] Filament order confirm action includes optional discount type select and custom amount field.
- [ ] API staff order status endpoint accepts optional `discount_type_id` and `custom_discount_amount` when confirming.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=Discount`
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=BillingGeneration`

**Dependencies:** Task B2

**Files likely touched:**
- `app/Actions/Orders/UpdateOrderStatus.php`
- `app/Actions/Orders/ApplyDiscount.php` (new)
- `app/Http/Controllers/Api/StaffOrderController.php`
- `app/Http/Requests/Api/UpdateOrderStatusRequest.php`
- Filament order table actions (confirm action schema)
- `tests/Feature/Api/DiscountApplicationTest.php`
- `tests/Feature/Filament/OrderDiscountTest.php`

**Estimated scope:** M

---

#### Checkpoint: Phase B

- [ ] `vendor/bin/sail artisan test --compact`
- [ ] Admin can manage users in Filament
- [ ] Staff can apply discounts when confirming orders
- [ ] Billing reflects discounted totals

---

#### Phase C: In-App Notifications

##### Task C1: Notification Infrastructure

**Description:** Set up Laravel database notifications table and base notification classes.

**Acceptance criteria:**
- [ ] Laravel `notifications` table exists (via `vendor/bin/sail artisan notifications:table` or manual migration).
- [ ] Base notification classes created for: `AppointmentStatusChanged`, `OrderStatusChanged`, `BillingIssued`, `NewAppointmentBooking`, `NewOrderRequest`, `NewMessageReceived`.
- [ ] All notifications use the `database` channel.
- [ ] Notification data structure includes: `type` (human-readable event name), `title`, `body`, `action_url` (nullable), `related_type`, `related_id`.

**Verification:**
- [ ] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`

**Dependencies:** None

**Files likely touched:**
- New migration (create `notifications` table if not exists)
- `app/Notifications/AppointmentStatusChanged.php`
- `app/Notifications/OrderStatusChanged.php`
- `app/Notifications/BillingIssued.php`
- `app/Notifications/NewAppointmentBooking.php`
- `app/Notifications/NewOrderRequest.php`
- `app/Notifications/NewMessageReceived.php`

**Estimated scope:** M

---

##### Task C2: Customer Notification Triggers

**Description:** Trigger in-app notifications to customers at key workflow events.

**Acceptance criteria:**
- [ ] Customer is notified when their appointment is confirmed, rescheduled, or cancelled.
- [ ] Customer is notified when their order status changes (any transition).
- [ ] Customer is notified when billing is issued for their order.
- [ ] Notifications are dispatched inline (not queued) for simplicity in MVP.
- [ ] Existing SMS notification records still created alongside in-app notifications.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=CustomerNotification`

**Dependencies:** Task C1

**Files likely touched:**
- `app/Actions/Appointments/UpdateAppointmentStatus.php`
- `app/Actions/Orders/UpdateOrderStatus.php`
- `app/Actions/Billing/GenerateBillingForOrder.php`
- `tests/Feature/Notifications/CustomerNotificationTest.php`

**Estimated scope:** M

---

##### Task C3: Customer Notification API

**Description:** API endpoints for customers to list and manage their notifications.

**Acceptance criteria:**
- [ ] `GET /notifications` returns paginated notifications for the authenticated customer (newest first).
- [ ] Response includes: id, type, title, body, action_url, related_type, related_id, read_at, created_at.
- [ ] `GET /notifications/unread-count` returns count of unread notifications.
- [ ] `POST /notifications/{id}/mark-read` marks a single notification as read.
- [ ] `POST /notifications/mark-all-read` marks all notifications as read.
- [ ] Only the notification owner can access/mark their notifications.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=NotificationApi`

**Dependencies:** Task C2

**Files likely touched:**
- `routes/api.php`
- `app/Http/Controllers/Api/NotificationController.php`
- `app/Http/Resources/NotificationResource.php`
- `tests/Feature/Api/NotificationApiTest.php`

**Estimated scope:** M

---

##### Task C4: Staff Filament Notifications

**Description:** Staff and admin receive Filament database notifications for key customer actions.

**Acceptance criteria:**
- [ ] All staff/admin users are notified when a customer books a new appointment.
- [ ] All staff/admin users are notified when a customer submits a new order request.
- [ ] The assigned staff member (or all staff if unassigned) is notified when a customer sends a new message.
- [ ] Notifications appear in Filament's notification bell/panel.
- [ ] Notification includes a link to the relevant Filament resource page.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=StaffNotification`

**Dependencies:** Tasks C1, A3 (staff assignment for message routing)

**Files likely touched:**
- `app/Http/Controllers/Api/AppointmentController.php` (trigger on store)
- `app/Http/Controllers/Api/OrderController.php` (trigger on store)
- `app/Http/Controllers/Api/ConversationController.php` (trigger on storeMessage)
- `app/Notifications/NewAppointmentBooking.php`
- `app/Notifications/NewOrderRequest.php`
- `app/Notifications/NewMessageReceived.php`
- `tests/Feature/Notifications/StaffNotificationTest.php`

**Estimated scope:** M

---

#### Checkpoint: Phase C

- [ ] `vendor/bin/sail artisan test --compact`
- [ ] Customer receives in-app notifications for appointment/order/billing events
- [ ] Customer can list and mark-read notifications via API
- [ ] Staff sees notification bell in Filament for new bookings/orders/messages

---

#### Phase D: Seeder & Finalization

##### Task D1: Seeder & Test Adaptation

**Description:** Update seeders and fix any tests broken by schema changes across all phases.

**Acceptance criteria:**
- [ ] `migrate:fresh --seed` succeeds with all new columns, lookup tables, discount types, and notifications.
- [ ] Demo workflow seed includes: staff-assigned appointments, payment methods used, discount applied on a demo order, sample notifications.
- [ ] All tests pass.
- [ ] Pint clean.

**Verification:**
- [ ] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** All previous tasks

**Files likely touched:**
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/ClinicWorkflowSeeder.php`
- `database/seeders/PaymentMethodSeeder.php`
- `database/seeders/InventoryMovementTypeSeeder.php`
- `database/seeders/DiscountTypeSeeder.php`
- `database/factories/*.php` (as needed)
- Any test files needing adaptation

**Estimated scope:** M

---

#### Checkpoint: Complete

- [ ] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `vendor/bin/sail npm run build`
- [ ] `vendor/bin/sail artisan route:list --except-vendor`
- [ ] Full seeded defense flow works without manual database edits

---

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Replacing `payments.method` varchar breaks existing tests | Med | Adapt tests in same task; seed lookup data before payment factories run |
| Replacing `inventory_movements.type` varchar breaks existing action logic | Med | Update `RecordInventoryMovement` action to accept type ID; adapt all callers |
| Discount calculation edge cases (rounding, exceeding subtotal) | Low | Unit test edge cases; cap discount at subtotal |
| Laravel notifications table conflicts with existing notification_* tables | Low | They're separate concerns — Laravel `notifications` is for in-app; `sms_notifications` stays as-is |
| Staff notification volume (all staff get all notifications) | Low | Acceptable for single-clinic MVP; can scope later |
| Filament notification rendering for custom data | Low | Use Filament's built-in `->sendToDatabase()` with standard title/body |

## Summary

| Phase | Tasks | Effort |
|-------|-------|--------|
| A: Lookup Tables & Staff Assignment | 3 | S each |
| B: User Management & Discounts | 3 | S-M |
| C: In-App Notifications | 4 | M each |
| D: Seeder & Finalization | 1 | M |
| **Total** | **11** | |

## Review Gate

Plan awaiting approval. Implementation executes one task at a time with listed tests and checkpoints. Split tasks further if they grow beyond five files or one focused session.
