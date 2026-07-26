# Clinic Workflow Release Stabilization Manifest

## Purpose

This is the Task 1 evidence and removal manifest for the approved clinic
workflow stabilization. It freezes the baseline before implementation or
legacy deletion. A file listed for retirement is not permission to remove it
early: the replacement gate and task named below must pass first.

Captured on 2026-07-26 from the current workspace and Sail test environment.

## Baseline Verdict

The application is not release-ready:

- the full suite has 973 tests, 893 passing tests, 15 assertion failures, and
  65 errors;
- 46 of 58 API routes are still unversioned, including patient-created Order
  and legacy Billing routes;
- the current schema contains both the canonical clinic workflow and the
  superseded Order/Billing workflow;
- active application code, factories, seeders, and tests still use
  `customer_id`;
- inventory has two uncoordinated deduction paths and its ledger still points
  to legacy Orders.

Task 40 and Checkpoint 8 in the original redesign task list are therefore
reopened.

## Commands and Exact Outcomes

### Full test suite

```bash
vendor/bin/sail artisan test --compact
vendor/bin/sail artisan test --compact --log-junit /tmp/eyecare-baseline.xml
```

Both executions failed. The JUnit run recorded:

```text
tests=973
assertions=2839
passed=893
failures=15
errors=65
skipped=0
time=231.806181 seconds
process exit=2
```

The second command exists only to preserve the exact failing-test inventory;
`/tmp/eyecare-baseline.xml` is temporary evidence and is not a project
artifact.

### API route inventory

```bash
vendor/bin/sail artisan route:list --except-vendor --path=api
vendor/bin/sail artisan route:list --except-vendor --path=api --json
```

Both commands reported the same 58 routes:

- 12 current `/api/v1` routes;
- 46 unversioned routes;
- 4 patient-created Order routes;
- 2 legacy Billing routes;
- 1 legacy staff Order-status route.

### Schema and vocabulary inventory

Laravel Boost schema inspection and the following scans were used:

```bash
rg -l "customer_id|->customer\(|customer\(\)|customer\." app database routes tests -g "*.php"
rg --files app database tests routes
rg -l "InventoryMovement|RecordInventoryMovement|CommitJobOrderInventory|FrameReservation" app database tests -g "*.php"
```

The schema inspection confirms the tables and columns classified below.

## Current Route Classification

The counts in this table total the exact 58-route inventory.

| Current route family | Count | Classification | Replacement task |
|---|---:|---|---|
| `/api/register`, `/api/login`, `/api/logout`, `/api/user` GET/PATCH, `/api/patient/profile` GET/PATCH | 7 | Replace with the five approved `/api/v1` auth and `me` routes; remove duplicate profile contracts | Task 19 |
| `/api/visit-reasons`, appointment availability, CRUD subset, contact note, reschedule, cancel | 8 | Replace with appointment types and the approved `/api/v1` appointment contract; contact note is not a separate target route | Tasks 2, 3, 4, 20 |
| `/api/patient/intakes` collection/member routes, submit, and verify | 5 | Replace patient routes with three appointment-nested intake routes; keep verification staff-only outside the patient group | Tasks 5, 6, 21 |
| `/api/brands`, `/api/categories`, `/api/products` index/show | 4 | Remove after the frame-only `/api/v1` catalog proves replacement coverage | Tasks 10, 22 |
| `/api/v1/frames` and `/api/v1/frame-reservations` | 5 | Retain and repair; these are already canonical route families | Tasks 11–14, 22 |
| `/api/prescriptions` index/show | 2 | Retain behavior, repair ownership, and version | Tasks 7, 23 |
| `/api/v1/quotations`, `/api/v1/job-orders`, `/api/v1/invoices` | 6 | Retain and repair; enforce read-only patient access | Tasks 15, 23 |
| `/api/conversations`, messages, attachment download, and mark-read | 5 | Retain the approved conversation/messages behavior, repair patient ownership, version three approved routes, and remove route-only extras from the patient allow-list | Tasks 7, 24 |
| `/api/feedback` index/store/show | 3 | Retain private feedback submission, repair ownership, version POST, and remove unapproved patient list/show routes | Tasks 7, 24 |
| `/api/notifications` inbox/read routes | 4 | Remove from the exact patient API contract; retain account-resolved notification delivery infrastructure | Tasks 7, 24 |
| `/api/v1/ratings` | 1 | Replace with the purchase-verified job-order-item rating route | Tasks 24, 31 |
| `/api/orders` index/store/show/cancel | 4 | Remove; patients never create or cancel Orders | Tasks 15, 16 |
| `/api/billing/{billing}` and PDF | 2 | Remove; canonical patient invoices replace Billing | Tasks 15, 17, 23 |
| `/api/staff/orders/{order}/status` | 1 | Remove with legacy Order processing | Tasks 15, 16 |
| `/api/staff/appointments/{appointment}/status` | 1 | Retain only until explicit workflow actions replace the generic mutation | Tasks 4–6 |

The target allow-list remains exactly the contract approved in the
stabilization specification. No checkout, direct-order, Job Order creation,
Invoice creation, or payment mutation is permitted to patients.

## Current Schema Classification

### Canonical tables to retain and repair

- identity and clinical: `users`, `patients`, `appointments`,
  `patient_intakes`, `encounters`, `prescriptions`, `physical_charts`;
- scheduling: `clinic_hours`, `provider_hours`, `schedule_overrides` and the
  current appointment status/provider foundations;
- catalog and stock: `products`, `product_variants`, `product_categories`,
  `lens_categories`, `inventory_movements`, `inventory_movement_types`;
- commercial workflow: `frame_reservations`, `frame_reservation_items`,
  `quotations`, `quotation_revisions`, `quotation_items`, `job_orders`,
  `job_order_items`, `invoices`, `invoice_items`, `invoice_payments`,
  `dispensing_events`;
- communication and governance: conversations/messages, feedback,
  `frame_ratings`, rating revisions, complaints, privacy, retention, and audit
  tables.

### Legacy tables to remove after Task 15 replacement proof

- `orders`
- `order_items`
- `order_statuses`
- `billings`
- `billing_items`
- `billing_statuses`
- `payments`
- `payment_statuses`
- `service_records`

`payment_methods` and `discount_types` are not deleted merely because old
Billing used them. They remain shared lookup concepts only if the canonical
Invoice/payment contract uses them after Task 15 and Task 18 schema review.

### Active legacy columns to remove or repoint

| Current column | Decision |
|---|---|
| `orders.customer_id` | Remove with `orders` |
| `billings.customer_id` | Remove with `billings` |
| `service_records.customer_id` | Remove with `service_records` |
| `conversations.customer_id` | Repoint to `patient_id`; resolve the optional login account only for authorization/delivery |
| `feedback.customer_id` | Repoint to `patient_id` |
| `inventory_movements.order_id` | Replace with a canonical reservation/job-order source contract |
| `sms_notifications.order_id` | Remove or replace with a canonical Job Order reference only if an approved notification requires it |

Historical migrations containing `customer_id` are not repaired piecemeal.
Because the system is undeployed, Task 18 replaces the development migration
history with a truthful canonical schema and then retires the superseded
migrations in the bounded batches below.

## `customer_id` Consumer Classification

### Remove with legacy Order/Billing

- `app/Actions/Billing/*`
- `app/Actions/Orders/UpdateOrderStatus.php`
- legacy Order/Billing Filament resources, relation managers, reports,
  controllers, requests, API resources, models, notifications, factories, and
  seeders listed in the deletion batches;
- `app/Models/ServiceRecord.php` and its factory;
- legacy-only Order/Billing tests listed in the deletion batches;
- Order/Billing portions of mixed tests and seeders.

### Retain and repair to the Patient/account boundary

- Conversation: its model, API controller/request/resource, Filament
  infolist/table/chat page, factory, message factory, and tests.
- Feedback: its model, API controller/request, Filament infolist/table/widget,
  factory, audit coverage, and tests.
- Appointment, prescription, reminder, SMS, notification, expiry alert,
  printing, patient resource, and global-search consumers.
- `Patient`, `User`, `UserFactory`, `RoleFactory`, and the canonical clinic
  workflow seeder.
- Useful dashboard, reporting, audit, catalog, and inventory files that
  currently import a legacy model.

### Replace through migration consolidation

All historical migration references are retired only in Task 18 after their
canonical replacement migrations and fresh-seed proof exist. This includes the
appointment/prescription transition migrations: they are evidence of the
unfinished cutover, not active compatibility contracts to preserve.

## Failing-Test Classification

Every one of the 80 non-passing tests is covered by the following exact
test-class inventory. “Remove” means remove obsolete assertions/tests only
after Task 15; useful mixed files are repaired instead of deleted.

| Test class | Failures | Errors | Decision |
|---|---:|---:|---|
| `Api\FeedbackTest` | 0 | 5 | Repair patient ownership and approved private feedback contract; Tasks 7/24 |
| `Api\MessagingTest` | 0 | 14 | Repair patient ownership; remove Order context cases; Tasks 7/24 |
| `Api\OrderProcessingTest` | 1 | 2 | Remove after Job Order/Invoice/inventory replacement gate; Tasks 15/16 |
| `Api\OrderRequestTest` | 0 | 2 | Remove direct-order contract; Tasks 15/16 |
| `Api\PrescriptionTest` | 0 | 2 | Repair and version canonical prescriptions; Tasks 7/23 |
| `AppointmentReminderTest` | 0 | 3 | Repair notification delivery through patient account; Task 7 |
| `AppointmentSmsMessageTest` | 0 | 1 | Repair patient/contact lookup; Task 7 |
| `AppointmentStaffAuditTest` | 1 | 0 | Repair canonical creator/check-in audit expectation; Tasks 5/7 |
| `AuditLogRecordingTest` | 0 | 1 | Repair feedback subject ownership; Tasks 7/26 |
| `DemoWorkflowSeedTest` | 4 | 2 | Replace Order/Billing demo assertions with canonical workflow; Task 25 |
| `Filament\AppointmentResourceTest` | 0 | 1 | Remove Billing relation assertion and test canonical PHR workspace; Tasks 9/17 |
| `Filament\BillingResourceTest` | 0 | 10 | Remove after canonical Invoice resource coverage; Tasks 15/17 |
| `Filament\CalendarInteractivityTest` | 0 | 1 | Supply required patient-readable reschedule reason; Task 4 |
| `Filament\CatalogResourceTest` | 1 | 0 | Repair current frame catalog/image expectation; Task 10 |
| `Filament\ConversationResourceTest` | 0 | 2 | Repair appointment context factories to patient ownership; Task 7 |
| `Filament\CreateBillingTest` | 2 | 0 | Remove after canonical Invoice creation coverage; Tasks 15/17 |
| `Filament\DatabaseNotificationTest` | 1 | 1 | Repair appointment case; remove Order case; Tasks 7/17 |
| `Filament\GlobalSearchTest` | 2 | 0 | Repair patient/appointment search and remove legacy Order assumptions; Task 9 |
| `Filament\OrderCreationTest` | 0 | 1 | Remove legacy Order creation test; Tasks 15/17 |
| `Filament\OrderResourceTest` | 0 | 1 | Remove legacy Order resource test; Tasks 15/17 |
| `Filament\PatientResourceTest` | 0 | 2 | Repair duplicate patient factory and replace Order count with canonical history; Tasks 7/9 |
| `Filament\PrescriptionPrintTest` | 0 | 2 | Repair `Prescription->patient`; Task 7 |
| `Filament\PrescriptionResourceTest` | 1 | 1 | Repair patient/appointment creation paths; Tasks 7/9 |
| `GetOrCreateBillingTest` | 0 | 1 | Authorized retirement only after Task 15; Task 17 |
| `Notifications\CustomerNotificationTest` | 0 | 4 | Repair recipient resolution through `Patient->user`; Task 7 |
| `Notifications\StaffNotificationTest` | 0 | 1 | Repair appointment factory ownership; Task 7 |
| `PrescriptionExpiryAlertTest` | 0 | 2 | Repair prescription/patient relationship; Task 7 |
| `ProductTypeTest` | 1 | 0 | Replace accessory-mobile expectation with frame-only scope; Task 10 |
| `RolePermissionsTest` | 0 | 2 | Remove Billing assertions and retain canonical role/capability checks; Tasks 8/17 |
| `UpdateOrderStatusTest` | 1 | 1 | Remove after canonical Job Order/inventory replacement gate; Tasks 15/17 |
| **Total** | **15** | **65** | **80 non-passing tests** |

The principal SQL failures are missing legacy `customer_id` columns,
factories trying to write both `patient_id` and `customer_id`, missing
`customer` relationships, and obsolete Order/Billing UI contracts. Passing
legacy tests are still obsolete and are included in the file batches below;
green does not imply retained behavior.

## Inventory Consumer Manifest

### Retain

- `Product`, `ProductVariant`, `InventoryMovement`, and movement types;
- manual adjustment, restock, damage, low-stock alerting, stock display, and
  reporting behavior;
- Frame Reservation preparation/release/expiry;
- Job Order item commitments;
- focused reservation, allocation, Job Order, inventory, audit, and Filament
  inventory tests.

### Repair

- `RecordInventoryMovement` accepts a legacy `orderId` and writes `order_id`;
- `InventoryMovement` and its factory expose an `order` relationship/state;
- the inventory table links to the legacy Order resource;
- `PrepareFrameReservation` and `ReleaseFrameReservation` change stock without
  ledger entries;
- `CommitJobOrderInventory` changes stock without ledger entries or an
  idempotency marker;
- `CreateJobOrder` does not call the commitment action;
- the current reservation and Job Order paths can deduct the same frame twice;
- reversal is status-based rather than tied to exact unreversed movements.

Task 11 must choose and persist one allocation owner. Tasks 12–14 then make
each deduction/reversal atomic, ledger-backed, and idempotent before the legacy
Order inventory path is removed.

## Bounded Application Deletion Batches

No batch contains more than five files. Files marked “edit” remain and lose
only obsolete wiring. Batches execute only after Task 15 passes.

### Task 16: Direct Order API

**16-A**

1. `routes/api.php` — edit
2. `app/Http/Controllers/Api/OrderController.php`
3. `app/Http/Controllers/Api/StaffOrderController.php`
4. `app/Http/Requests/Api/UpdateOrderStatusRequest.php`
5. `tests/Feature/Api/OrderCancelTest.php`

**16-B**

1. `app/Http/Resources/OrderResource.php`
2. `app/Http/Resources/OrderItemResource.php`
3. `tests/Feature/Api/OrderProcessingTest.php`
4. `tests/Feature/Api/OrderRequestTest.php`

### Task 17: Legacy Order application

**17-O1**

1. `app/Models/Order.php`
2. `app/Models/OrderItem.php`
3. `app/Models/OrderStatus.php`
4. `app/Actions/Orders/UpdateOrderStatus.php`
5. `app/Notifications/OrderStatusChanged.php`

**17-O2**

1. `app/Filament/Resources/Orders/OrderResource.php`
2. `app/Filament/Resources/Orders/Pages/CreateOrder.php`
3. `app/Filament/Resources/Orders/Pages/EditOrder.php`
4. `app/Filament/Resources/Orders/Pages/ListOrders.php`
5. `app/Filament/Resources/Orders/Schemas/OrderForm.php`

**17-O3**

1. `app/Filament/Resources/Orders/RelationManagers/ItemsRelationManager.php`
2. `app/Filament/Resources/Orders/Tables/OrdersTable.php`
3. `app/Filament/Resources/Orders/Widgets/OrderStatsWidget.php`
4. `app/Filament/Resources/Patients/RelationManagers/OrdersRelationManager.php`
5. `app/Filament/Pages/Reports/OrdersReport.php`

**17-O4**

1. `database/factories/OrderFactory.php`
2. `database/factories/OrderItemFactory.php`
3. `database/factories/OrderStatusFactory.php`
4. `database/seeders/OrderStatusSeeder.php`
5. `app/Notifications/NewOrderRequest.php`

**17-O5**

1. `tests/Feature/Filament/OrderCreationTest.php`
2. `tests/Feature/Filament/OrderResourceTest.php`
3. `tests/Feature/UpdateOrderStatusTest.php`

### Task 17: Legacy Billing application

**17-B1**

1. `app/Models/Billing.php`
2. `app/Models/BillingItem.php`
3. `app/Models/BillingStatus.php`
4. `app/Models/Payment.php`
5. `app/Models/PaymentStatus.php`

**17-B2**

1. `app/Models/PaymentMethod.php`
2. `app/Models/ServiceRecord.php`
3. `app/Notifications/BillingIssued.php`
4. `app/Actions/Billing/AddOrderItemsToBilling.php`
5. `app/Actions/Billing/AddServiceToBilling.php`

**17-B3**

1. `app/Actions/Billing/GenerateBillingForOrder.php`
2. `app/Actions/Billing/GetOrCreateBilling.php`
3. `app/Actions/Billing/RecalculateBillingBalance.php`
4. `app/Actions/Billing/RecordPayment.php`
5. `app/Http/Resources/BillingResource.php`

**17-B4**

1. `app/Filament/Resources/Billings/BillingResource.php`
2. `app/Filament/Resources/Billings/Pages/CreateBilling.php`
3. `app/Filament/Resources/Billings/Pages/EditBilling.php`
4. `app/Filament/Resources/Billings/Pages/ListBillings.php`
5. `app/Filament/Resources/Billings/Pages/ViewBilling.php`

**17-B5**

1. `app/Filament/Resources/Billings/RelationManagers/PaymentsRelationManager.php`
2. `app/Filament/Resources/Billings/Schemas/BillingInfolist.php`
3. `app/Filament/Resources/Billings/Tables/BillingsTable.php`
4. `app/Filament/Resources/Billings/Widgets/BillingStatsWidget.php`
5. `app/Filament/Resources/Appointments/RelationManagers/BillingsRelationManager.php`

**17-B6**

1. `app/Http/Controllers/Api/BillingController.php`
2. `routes/api.php` — edit to remove Billing routes
3. `routes/web.php` — edit
4. `app/Services/PdfService.php` — edit, retaining canonical print methods
5. `app/Filament/Pages/Reports/SalesReport.php` — edit to canonical invoices

**17-B7**

1. `database/factories/BillingFactory.php`
2. `database/factories/BillingItemFactory.php`
3. `database/factories/BillingStatusFactory.php`
4. `database/factories/PaymentFactory.php`
5. `database/factories/PaymentStatusFactory.php`

**17-B8**

1. `database/factories/ServiceRecordFactory.php`
2. `database/seeders/BillingStatusSeeder.php`
3. `database/seeders/PaymentStatusSeeder.php`
4. `database/seeders/PaymentMethodSeeder.php`
5. `database/seeders/DashboardDemoSeeder.php` — edit to canonical workflow

**17-B9**

1. `tests/Feature/AddOrderItemsToBillingTest.php`
2. `tests/Feature/Billing/BillingGenerationTest.php`
3. `tests/Feature/Billing/PaymentTest.php`
4. `tests/Feature/BillingReceiptTest.php`
5. `tests/Feature/ServiceBillingActionsTest.php`

**17-B10**

1. `tests/Feature/Filament/BillingResourceTest.php`
2. `tests/Feature/Filament/CreateBillingTest.php`
3. `tests/Feature/Filament/InlinePaymentTest.php`
4. `tests/Feature/GetOrCreateBillingTest.php`

`tests/Feature/GetOrCreateBillingTest.php` currently contains a user-owned
uncommitted edit. The project owner explicitly authorized retiring that file
with its obsolete behavior, but only after Task 15 proves canonical Invoice
replacement coverage. It must not be edited, staged, reverted, or deleted
before that gate.

### Mixed consumers: edit, do not delete wholesale

- `app/Enums/AuditEvent.php`
- `app/Console/Commands/SendDailySummaryCommand.php`
- `app/Filament/Resources/Conversations/Pages/ConversationChatPage.php`
- `app/Http/Requests/Api/StoreFeedbackRequest.php`
- `database/factories/InventoryMovementFactory.php`
- `database/seeders/ClinicWorkflowSeeder.php`
- tests for audit, bulk actions, daily summary, dashboard workflow, database
  notifications, global search, patient resources, reports, role permissions,
  soft-delete visibility, status catalogs, and inventory.

Each mixed file loses only legacy assertions/imports after equivalent canonical
coverage exists.

`tests/Feature/Invoices/PaymentLifecycleTest.php` is explicitly retained: its
name matched the broad Payment scan, but it tests canonical Invoice Payments,
not the legacy `payments` table.

## Bounded Migration-History Retirement Batches

These files are retired only as part of Task 18 migration consolidation. Mixed
files first receive a canonical replacement for any still-needed table,
foreign key, index, soft delete, lens category, payment-method, or discount
behavior.

**18-M1**

1. `2026_06_07_090015_create_order_statuses_table.php`
2. `2026_06_07_090016_create_billing_statuses_table.php`
3. `2026_06_07_090018_create_payment_statuses_table.php`
4. `2026_06_09_070740_create_orders_table.php`
5. `2026_06_10_034636_create_billings_table.php`

**18-M2**

1. `2026_06_10_053030_create_payments_table.php`
2. `2026_06_11_082421_add_notes_to_orders_table.php`
3. `2026_06_16_034419_add_soft_deletes_to_business_models.php`
4. `2026_06_16_034951_add_billing_number_to_billings_table.php`
5. `2026_06_16_072858_add_due_date_to_billings_table.php`

**18-M3**

1. `2026_06_17_114636_add_payment_methods_table_and_fk_to_payments.php`
2. `2026_06_17_130457_create_discount_types_and_add_fk_to_orders.php`
3. `2026_06_18_064943_add_price_to_lens_types_and_lens_type_price_to_order_items.php`
4. `2026_06_18_082907_add_lens_product_variant_id_to_order_items.php`
5. `2026_06_19_001941_make_lens_type_nullable_on_order_items.php`

**18-M4**

1. `2026_06_19_082252_drop_notes_and_due_date_from_billings_table.php`
2. `2026_06_22_033146_rename_preparing_to_processing_in_order_statuses.php`
3. `2026_06_24_093246_create_service_records_table.php`
4. `2026_06_24_093335_make_billings_polymorphic.php`
5. `2026_06_24_115254_create_billing_items_table.php`

**18-M5**

1. `2026_06_24_120924_refactor_billings_to_encounter_model.php`
2. `2026_06_24_121404_simplify_service_records_drop_billing_fields.php`
3. `2026_06_25_054855_add_appointment_id_to_billings.php`
4. `2026_06_29_214656_add_performance_indexes.php`
5. `2026_06_30_160001_add_or_number_to_billings.php`

**18-M6**

1. `2026_07_04_102646_rename_lens_types_to_lens_categories.php`
2. `2026_07_04_112454_remove_discount_from_orders.php`
3. `2026_07_04_112951_add_billing_id_to_orders.php`
4. `2026_07_04_122327_remove_or_number_add_billing_notes.php`

Task 18 must also consolidate the `customer_id` transition migrations into
clean canonical Patient foreign keys. The batch list may gain newly discovered
historical migration files, but no execution batch may exceed five files and
the manifest must be amended before that batch runs.

## Replacement Gates

1. Tasks 2–14 repair the canonical workflow and inventory integrity.
2. Task 15 proves Job Order, Invoice, payment, dispensing, print, notification,
   report, and inventory replacement coverage.
3. Tasks 16–17 execute the bounded application batches.
4. Task 18 executes bounded migration consolidation and fresh canonical seed.
5. Tasks 19–24 replace and lock the patient `/api/v1` contract.
6. Tasks 25–26 prove fresh seed, terminology, full suite, browser, privacy,
   recovery, and final route/schema scans.

No legacy test is “fixed” by restoring a removed `customer_id`, Order, Billing,
checkout, or patient payment behavior.
