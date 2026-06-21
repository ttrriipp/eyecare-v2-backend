# Spec: Optical Clinic Journey MVP

Status: Complete — all 29 tasks done
Phase: Tasks 1–29 complete (29 tasks total after splits)

## Assumptions I'm Making

1. This repository is the Laravel backend and Filament admin panel only; the Android mobile app is a separate client that consumes this API.
2. Mobile authentication uses Laravel Sanctum bearer tokens, not browser session cookies.
3. The database is MySQL in Laravel Sail.
4. The first backend milestone is the complete appointment-to-order journey, not isolated modules.
5. The existing scaffolded clinic files are not production-locked yet; current empty migrations, requests, controllers, factories, and seeders may be completed or replaced through normal new migrations before deployment.
6. Fixed roles are `admin`, `staff`, and `customer`; dynamic permission management is out of scope.
7. Online payment is out of scope; billing and payments are tracked manually by staff.
8. Native AR is owned by the Android app; the backend stores only product/frame metadata and AR asset references.
9. The backend must never store face geometry, facial landmarks, biometric identifiers, or AR analytics.
10. Semaphore SMS is used only for appointment confirmation, reschedule, and cancellation messages in the MVP; reminders are deferred until scheduling is introduced.
11. Customers choose from a fixed lens type catalog during order request, and staff assigns final lens details after review.
12. The optimized capstone demo starts with the mobile AR try-on hook, then order request and appointment booking, then admin review, appointment approval, and SMS confirmation.

Correct these assumptions before approval if any are wrong.

## Objective

Build a connected optical clinic backend that supports the capstone demo journey:

Customer uses the mobile app to try a frame in AR, selects the frame, requests lens preferences, books an eye exam appointment, and submits an order request. Staff uses the Filament admin panel to review the request, assign prescription or lens details, approve the appointment, trigger appointment SMS, process billing manually, and maintain clinic records.

Primary users:

- Customer: books appointments, browses frames, launches AR from eligible products, submits order requests, views prescriptions, tracks order and billing status, messages staff, and submits feedback.
- Staff: confirms appointments, records prescriptions, manages products and inventory, processes order requests, creates billings, records manual payments, replies to messages, and reviews feedback.
- Admin: manages operational data, staff access, dashboard summaries, audit visibility, and demo seed data.

Success means the backend and admin panel can support a complete defense demo without manual database edits.

## Tech Stack

- PHP 8.5
- Laravel 13.12
- Laravel Sail 1.61
- Laravel Sanctum 4.3 for mobile API tokens
- Filament 5.6 for admin and staff workflows
- Livewire 4.3 through Filament
- Pest 4.7 and PHPUnit 12.5 for tests
- Laravel Pint 1.29 for PHP formatting
- Tailwind CSS 4.3 and Vite 8 for frontend assets
- MySQL through Sail

## Commands

All project commands must run through Sail.

- Start services: `vendor/bin/sail up -d`
- Stop services: `vendor/bin/sail stop`
- Dev server: `vendor/bin/sail composer run dev`
- Frontend dev only: `vendor/bin/sail npm run dev`
- Build frontend assets: `vendor/bin/sail npm run build`
- Run migrations: `vendor/bin/sail artisan migrate --no-interaction`
- Fresh demo database: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- List routes: `vendor/bin/sail artisan route:list --except-vendor`
- Run tests: `vendor/bin/sail artisan test --compact`
- Run filtered tests: `vendor/bin/sail artisan test --compact --filter=Appointment`
- Format changed PHP files: `vendor/bin/sail bin pint --dirty --format agent`
- Create feature test: `vendor/bin/sail artisan make:test --pest AppointmentBookingTest --no-interaction`
- Create model and migration: `vendor/bin/sail artisan make:model Product -mfs --no-interaction`
- Create form request: `vendor/bin/sail artisan make:request Api/StoreAppointmentRequest --no-interaction`

## Project Structure

- `app/Models` -> Eloquent models for clinic entities.
- `app/Http/Controllers/Api` -> Mobile API controllers.
- `app/Http/Requests/Api` -> API form request validation and authorization.
- `app/Filament` -> Filament resources, pages, widgets, and admin workflows.
- `app/Services` -> External integrations and domain services when logic does not belong in controllers or models.
- `app/Actions` -> Single-purpose workflow actions when a use case spans multiple models.
- `database/migrations` -> Schema changes, one concern per migration.
- `database/factories` -> Test data factories for all persisted clinic entities.
- `database/seeders` -> fixed roles, statuses, demo catalog, demo users, and defense dataset.
- `routes/api.php` -> Mobile API routes protected by `auth:sanctum` where required.
- `routes/web.php` -> Web routes; Filament panel registration remains provider-driven.
- `tests/Feature` -> API, Filament workflow, integration, and happy-path tests.
- `tests/Unit` -> Isolated value object, action, service, and calculation tests.
- `docs/ideas` -> Initial idea and roadmap notes.
- `docs/optical-clinic-journey-mvp-spec.md` -> This living specification.

## Code Style

Use typed Laravel code, form requests for validation, small controllers, descriptive names, and model relationships with explicit return types.

```php
use App\Http\Requests\Api\StoreAppointmentRequest;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;

class AppointmentController
{
    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $appointment = Appointment::query()->create([
            ...$request->validated(),
            'customer_id' => $request->user()->id,
            'status' => AppointmentStatus::Pending,
        ]);

        return response()->json([
            'data' => AppointmentResource::make($appointment),
        ], 201);
    }
}
```

Conventions:

- Use singular model names and plural table names.
- Use enum-like status names consistently in code and seed data.
- Use form requests for API validation and authorization.
- Use Eloquent API Resources for mobile responses unless an existing local convention says otherwise.
- Use Filament static `make()` component initialization.
- Use `route()` and named routes when generating links.
- Avoid raw SQL with user input.
- Avoid querying in Blade templates.
- Keep controller methods thin; move multi-step workflows to actions or services.

Recommended fixed status names:

- Appointments: `pending`, `confirmed`, `rescheduled`, `cancelled`, `completed`
- Orders: `requested`, `under_review`, `confirmed`, `preparing`, `ready_for_pickup`, `completed`, `cancelled`
- Billing: `draft`, `issued`, `partially_paid`, `paid`, `voided`
- Payments: `posted`, `voided`, `reversed`
- SMS notifications: `queued`, `sent`, `failed`, `cancelled`
- Inventory movements: `initial`, `manual_adjustment`, `order_commitment`, `order_reversal`

## Testing Strategy

Use Pest for backend tests.

Feature tests should cover:

- Customer registration, login, and Sanctum token issuance.
- Customer appointment booking validation and authorization.
- Staff appointment status changes.
- SMS notification records created for appointment confirmation, reschedule, and cancellation.
- Product catalog API filtering active products and AR-eligible variants.
- Order request submission with item snapshots.
- Staff order processing and status transitions.
- Billing generation and manual payment balance updates.
- Messaging authorization between customer and staff.
- Message attachments, if included.
- Feedback submission after an appointment or order.
- Audit log creation for key staff actions.

Filament tests should cover:

- Staff/admin can list, filter, create, and update operational records.
- Edit pages call `save`.
- Create pages call `create`.
- Table filters for status/date/low stock work.
- Actions notify and persist expected changes.

Unit tests should cover:

- Status transition rules.
- Billing balance calculations.
- Inventory deduction or reversal rules.
- SMS payload construction without making real HTTP requests.

Coverage expectation:

- Every MVP workflow task must include at least one happy-path feature test and one validation or authorization failure test when user input or permissions are involved.
- External services must be faked in tests.

## Boundaries

Always:

- Run implementation commands through Sail.
- Use version-specific Laravel documentation before changing Laravel, Filament, Sanctum, Livewire, or Pest code.
- Validate API inputs with form requests.
- Authorize customer, staff, and admin actions.
- Use factories and seeders for test and demo data.
- Use Pest tests for critical backend behavior.
- Run `vendor/bin/sail bin pint --dirty --format agent` after PHP edits.
- Keep the demo workflow runnable from seed data.

Ask first:

- Adding Composer or npm dependencies.
- Changing database technology or Sail services.
- Changing CI, deployment, or environment configuration.
- Introducing queues, scheduled jobs, or cache infrastructure beyond the existing Laravel defaults.
- Changing fixed status names after approval.
- Expanding SMS beyond appointment-related messages.
- Adding online checkout or payment gateway support.
- Creating new top-level directories.

Never:

- Commit secrets or real clinic/customer data.
- Edit `vendor`, `node_modules`, or generated lock files without dependency approval.
- Store biometric data, face geometry, facial landmarks, or AR analytics.
- Remove failing tests without approval.
- Let mobile-only AR logic leak into the backend.
- Build dynamic permission management before the core journey is complete.
- Send real SMS from automated tests.

## Success Criteria

Phase 1 foundation:

- Fixed roles and statuses are seeded.
- Customer can register and log in through the API.
- Staff/admin can log in to the Filament panel.
- Appointment booking API creates a pending appointment for the authenticated customer.
- Staff can confirm, reschedule, cancel, and complete appointments.
- Appointment SMS notification records are created for approved appointment events.
- Feature tests cover auth, booking, status changes, and SMS record creation.

MVP journey:

- Customer can browse active products and identify AR-eligible frames through the API.
- Backend returns AR asset references without storing biometric data.
- Staff can manage products, variants, inventory basics, and AR asset references in Filament.
- Staff can record prescriptions linked to customers and optionally appointments.
- Customer can submit an order request with selected frame, lens preference, appointment context, and item price snapshot.
- Staff can review, confirm, prepare, complete, or cancel order requests.
- Billing can be generated from confirmed orders.
- Manual payments update billing balance correctly.
- Customer and staff can exchange messages at any time, with optional order or appointment context.
- Message attachments are supported in the first implementation plan.
- Customer can submit feedback and rating after an appointment or order.
- Audit logs capture important appointment, product, inventory, order, billing, payment, and feedback actions.
- Dashboard shows appointments, pending orders, low stock, unpaid billings, and recent feedback.
- Demo seed data can recreate a complete defense dataset.
- The recommended demo script can be completed in under 10 minutes without manual database edits.

## Decisions

1. MVP SMS is limited to appointment confirmation, reschedule, and cancellation. Appointment reminders are deferred until a scheduling implementation exists.
2. Inventory deducts when an order is `confirmed`.
3. Customers choose from a fixed lens type catalog during order request.
4. Staff can review frame availability before prescription details are complete, but final order confirmation and billing require either a completed prescription or an explicitly non-prescription order path.
5. Messaging is available anytime, with optional order or appointment context.
6. Message attachments are included in the first implementation plan.
7. Feedback is allowed after completed appointments and completed orders.

## Open Questions

None.

## Implementation Plan

### Major Components

1. Foundation data and access
   - Roles: `admin`, `staff`, `customer`
   - Status catalogs for appointments, orders, billings, payments, SMS, and inventory movements
   - User role enforcement for API and Filament access

2. Customer API foundation
   - Sanctum token registration, login, logout, and current user endpoint
   - Stable JSON response convention
   - Form requests for validation and authorization

3. Appointment workflow
   - Appointment schema with customer, visit reason, scheduled date/time, status, and staff notes
   - Customer booking API
   - Staff status update API or Filament action
   - Filament appointment management with filters by status and date
   - SMS notification records for confirmation, reschedule, and cancellation

4. Product catalog and AR asset feed
   - Categories, brands, products, variants, product images, lens types, and AR asset references
   - Basic inventory fields on variants
   - Public/customer catalog API for active products
   - AR-eligible product metadata endpoint
   - Filament catalog management

5. Prescription workflow
   - Prescriptions linked to customers and optionally appointments
   - OD/OS values, PD, prescribed date, expiration date, and notes
   - Customer prescription history API
   - Filament prescription management

6. Order request workflow
   - Order request and item snapshot schema
   - Customer order request API with fixed lens type selection
   - Staff review, confirm, prepare, complete, and cancel actions
   - Inventory deduction on `confirmed`
   - Confirmation gate requiring prescription or non-prescription order path

7. Billing and manual payments
   - Billing generated from confirmed orders
   - Manual payment records with `posted`, `voided`, and `reversed`
   - Balance calculation service/action
   - Customer billing status API
   - Filament billing and payment management

8. Messaging with attachments
   - Conversations available without an appointment or order
   - Optional appointment/order context
   - Message records and attachment records
   - File upload validation for MIME type, extension, size, and storage visibility
   - Customer API and Filament staff view

9. Feedback, audit logs, and dashboard
   - Feedback after completed appointments and completed orders
   - Staff reply in Filament
   - Audit logs for important staff actions
   - Dashboard cards for appointments, pending orders, low stock, unpaid billings, and recent feedback

10. Demo seed data
    - Admin, staff, and customer accounts
    - Visit reasons, statuses, brands, categories, products, variants, lens types, AR references, appointments, orders, billings, messages, and feedback
    - Data shaped around the 10-minute defense script

### Implementation Order

1. Complete foundation data, role enforcement, and API auth first.
2. Complete appointment booking, staff status workflow, and SMS notification records.
3. Add products, variants, inventory basics, lens types, images, and AR asset references.
4. Add prescriptions and customer prescription history.
5. Add order requests, item snapshots, staff processing, inventory deduction, and prescription gate.
6. Add billing and manual payment tracking.
7. Add messaging, context links, and attachments.
8. Add feedback, audit logs, dashboard cards, and full demo seed data.
9. Run final hardening pass against the demo script and test suite.

This order keeps the app demoable after each vertical slice and avoids building billing, messaging, or dashboard behavior before the operational records exist.

### Sequential Dependencies

- Roles and auth must exist before protected API routes and Filament access tests.
- Status seeders must exist before appointment, order, billing, payment, SMS, and inventory workflows.
- Products, variants, and lens types must exist before order requests.
- Prescriptions must exist before enforcing final order confirmation rules.
- Confirmed orders must exist before billing generation and inventory deduction tests.
- Conversations can be built after users exist, but order/appointment context links require those tables.
- Dashboard cards depend on appointments, orders, inventory, billings, and feedback.

### Work That Can Run In Parallel

- Filament resource polishing can run in parallel with mobile API implementation after the schema is stable.
- Demo seed data can expand alongside each completed module.
- Unit tests for billing calculations and status transitions can be written while API and Filament tests are being added.
- Android API contract review can happen after each backend endpoint group is drafted.

### Risks And Mitigations

- Risk: Empty scaffolded migrations and models may drift from final schema.
  Mitigation: Review migration history before implementation; if not deployed, complete scaffolded migrations directly, otherwise add forward-only migrations.

- Risk: SMS integration could slow progress or send real messages during tests.
  Mitigation: First persist SMS notification records and fake external HTTP calls; add real Semaphore sending behind configuration after workflow tests pass.

- Risk: Attachments increase file storage, validation, and visibility complexity.
  Mitigation: Keep attachment support limited to validated uploads on messages only; use private storage unless public access is explicitly required.

- Risk: Inventory deduction on confirmation can produce incorrect stock if orders are cancelled.
  Mitigation: Add inventory movement records and reversal behavior for cancelled confirmed orders.

- Risk: Prescription gate can block demo orders.
  Mitigation: Support explicit non-prescription order path and seed demo prescriptions.

- Risk: Dashboard work can become broad reporting.
  Mitigation: Limit dashboard to the five approved demo cards.

### Verification Checkpoints

1. Foundation checkpoint
   - `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
   - `vendor/bin/sail artisan test --compact --filter=Auth`
   - Confirm seeded admin, staff, and customer accounts exist.

2. Appointment checkpoint
   - `vendor/bin/sail artisan test --compact --filter=Appointment`
   - Confirm booking creates `pending`; staff status changes create SMS notification records for confirmation, reschedule, and cancellation.

3. Catalog and AR checkpoint
   - `vendor/bin/sail artisan test --compact --filter=Product`
   - Confirm active products and AR-eligible variants are returned by API without biometric fields.

4. Prescription and order checkpoint
   - `vendor/bin/sail artisan test --compact --filter=Order`
   - Confirm order item snapshots, fixed lens type selection, prescription gate, and inventory deduction on confirmation.

5. Billing checkpoint
   - `vendor/bin/sail artisan test --compact --filter=Billing`
   - Confirm generated billings and posted/voided/reversed payments update balances correctly.

6. Messaging checkpoint
   - `vendor/bin/sail artisan test --compact --filter=Message`
   - Confirm anytime conversations, optional context links, and attachment validation.

7. Final MVP checkpoint
   - `vendor/bin/sail bin pint --dirty --format agent`
   - `vendor/bin/sail artisan test --compact`
   - `vendor/bin/sail npm run build`
   - Run the defense demo path from seeded data without manual database edits.

## Task Breakdown

This plan is backend-only: Laravel API, database, seeders, tests, services/actions, and Filament admin workflows. Android screens and native AR implementation are outside this task list.

`.context/database schema.md` is exploratory reference only — not authoritative. Implement schema from task acceptance criteria and existing migrations in this repository.

### Phase 1: Foundation

#### Task 1: Role Catalog And User Role Column

**Description:** Add the fixed role catalog and connect users to one role so later API and Filament access checks have a stable foundation.

**Acceptance criteria:**
- [x] Roles `admin`, `staff`, and `customer` are seeded idempotently.
- [x] Users belong to one role through a typed Eloquent relationship.
- [x] User factories can create admin, staff, and customer users.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=Role`
- [x] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`

**Dependencies:** None

**Files likely touched:**
- `database/migrations/2026_06_06_020817_create_roles_table.php`
- `database/migrations/0001_01_01_000000_create_users_table.php`
- `app/Models/Role.php`
- `app/Models/User.php`
- `database/seeders/RoleSeeder.php`

**Estimated scope:** M

#### Task 2: Status Catalog Foundation

**Description:** Define reusable fixed statuses for appointments, SMS notifications, orders, billings, payments, and inventory movements without business workflows yet.

**Acceptance criteria:**
- [x] Appointment and SMS statuses match the approved names.
- [x] Order, billing, payment, and inventory status values are seeded for upcoming modules.
- [x] Seeders can run repeatedly without duplicate rows.

**Verification:**
- [x] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=StatusCatalog`

**Dependencies:** Task 1

**Files likely touched:**
- `database/migrations/2026_06_06_020850_create_appointment_statuses_table.php`
- `database/migrations/2026_06_06_021011_create_notification_statuses_table.php`
- `database/migrations/2026_06_07_090015_create_order_statuses_table.php`
- `database/migrations/2026_06_07_090016_create_billing_statuses_table.php`
- `database/migrations/2026_06_07_090018_create_payment_statuses_table.php`
- `database/migrations/2026_06_07_090019_create_inventory_movement_statuses_table.php`
- `database/seeders/AppointmentStatusSeeder.php`
- `database/seeders/NotificationStatusSeeder.php`
- `database/seeders/OrderStatusSeeder.php`
- `database/seeders/BillingStatusSeeder.php`
- `database/seeders/PaymentStatusSeeder.php`
- `database/seeders/InventoryMovementStatusSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- `app/Models/AppointmentStatus.php`
- `app/Models/NotificationStatus.php`
- `app/Models/OrderStatus.php`
- `app/Models/BillingStatus.php`
- `app/Models/PaymentStatus.php`
- `app/Models/InventoryMovementStatus.php`
- `tests/Feature/StatusCatalogTest.php`

**Estimated scope:** M

#### Task 3: Filament Access Gate

**Description:** Restrict the admin panel to staff and admin users while keeping customers API-only.

**Acceptance criteria:**
- [x] Staff and admin users can access Filament.
- [x] Customer users are denied Filament access.
- [x] Access behavior is covered by a feature test.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=FilamentAccess`

**Dependencies:** Task 1

**Files likely touched:**
- `app/Models/User.php`
- `tests/Feature/Filament/FilamentAccessTest.php`

**Estimated scope:** S

#### Task 4: Customer Sanctum Authentication API

**Description:** Implement mobile API authentication for customer registration, login, logout, and current-user lookup using Sanctum bearer tokens.

**Acceptance criteria:**
- [x] Customers can register and receive an API token.
- [x] Customers can log in, log out, and fetch their profile.
- [x] Invalid credentials and duplicate registration data return validation errors.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=Auth`

**Dependencies:** Tasks 1, 2

**Files likely touched:**
- `routes/api.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Requests/Api/RegisterCustomerRequest.php`
- `app/Http/Requests/Api/LoginRequest.php`
- `app/Http/Resources/UserResource.php`
- `app/Models/User.php`
- `tests/Feature/Api/AuthTest.php`

**Estimated scope:** M

### Checkpoint: Foundation

- [x] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [x] `vendor/bin/sail artisan test --compact --filter=Role`
- [x] `vendor/bin/sail artisan test --compact --filter=Auth`
- [x] Admin/staff/customer demo accounts can be created from factories or seeders.

### Phase 2: Appointments And SMS Records

#### Task 5: Appointment Schema And Relationships

**Description:** Complete visit reason and appointment data structures so customers can book appointments and staff can manage status.

**Acceptance criteria:**
- [x] Appointments store customer, visit reason, status, scheduled date/time, contact notes, and staff notes.
- [x] Appointment, visit reason, and status relationships are typed.
- [x] Appointment factory creates valid records.

**Verification:**
- [x] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=AppointmentModel`

**Dependencies:** Tasks 1, 2

**Files likely touched:**
- `database/migrations/2026_06_06_020841_create_visit_reasons_table.php`
- `database/migrations/2026_06_06_020917_create_appointments_table.php`
- `app/Models/Appointment.php`
- `app/Models/VisitReason.php`
- `database/factories/AppointmentFactory.php`
- `database/factories/VisitReasonFactory.php`
- `database/seeders/VisitReasonSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- `tests/Feature/AppointmentModelTest.php`

**Estimated scope:** M

#### Task 6: Customer Appointment Booking API

**Description:** Let authenticated customers create appointments and list only their own appointment records.

**Acceptance criteria:**
- [x] Authenticated customers can create pending appointments.
- [x] Customers can list and view only their own appointments.
- [x] Invalid schedule, visit reason, or contact data returns JSON validation errors.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=AppointmentBooking`

**Dependencies:** Tasks 4, 5

**Files likely touched:**
- `routes/api.php`
- `app/Http/Controllers/Api/AppointmentController.php`
- `app/Http/Requests/Api/StoreAppointmentRequest.php`
- `app/Http/Resources/AppointmentResource.php`
- `tests/Feature/Api/AppointmentBookingTest.php`

**Estimated scope:** M

#### Task 7: Staff Appointment Status Workflow

**Description:** Add the backend workflow action for staff appointment status changes and record SMS notification rows for approved SMS events.

**Acceptance criteria:**
- [x] Staff can confirm, reschedule, cancel, and complete appointments.
- [x] Confirm, reschedule, and cancel create SMS notification records.
- [x] Tests prove no real SMS is sent.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=StaffAppointment`

**Dependencies:** Tasks 5, 6

**Files likely touched:**
- `routes/api.php`
- `database/migrations/2026_06_06_021117_create_sms_notifications_table.php`
- `app/Http/Controllers/Api/StaffAppointmentController.php`
- `app/Http/Requests/Api/UpdateAppointmentStatusRequest.php`
- `app/Actions/Appointments/UpdateAppointmentStatus.php`
- `app/Models/SmsNotification.php`
- `database/factories/SmsNotificationFactory.php`
- `tests/Feature/Api/StaffAppointmentTest.php`

**Estimated scope:** M

#### Task 8: Filament Appointment Resource

**Description:** Build the admin appointment workflow for staff and admin users.

**Acceptance criteria:**
- [x] Staff/admin can list and edit appointments.
- [x] Table filters include appointment status and scheduled date.
- [x] Status changes through Filament use the same workflow action as the API.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=AppointmentResource`

**Dependencies:** Tasks 3, 7

**Files likely touched:**
- `app/Filament/Resources/Appointments/AppointmentResource.php`
- `app/Filament/Resources/Appointments/Pages/ListAppointments.php`
- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`
- `app/Filament/Resources/Appointments/Tables/AppointmentsTable.php`
- `tests/Feature/Filament/AppointmentResourceTest.php`

**Estimated scope:** M

### Checkpoint: Appointments

- [x] `vendor/bin/sail artisan test --compact --filter=Appointment`
- [x] Customer booking produces `pending`.
- [x] Staff confirmation creates an SMS notification record.
- [x] Filament appointment filters work in tests.

### Phase 3: Catalog, AR Metadata, And Prescriptions

#### Task 9: Product Catalog Schema

**Description:** Add backend catalog tables for brands, categories, lens types, products, variants, images, and AR asset references.

**Acceptance criteria:**
- [x] Products and variants support active state, pricing, dimensions, stock quantity, and low stock threshold.
- [x] AR references are metadata only and contain no biometric fields.
- [x] Catalog seed data creates demo frame products and lens types.

**Verification:**
- [x] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=CatalogSchema`

**Dependencies:** Task 2

**Files likely touched:**
- `database/migrations/2026_06_06_030000_create_catalog_tables.php`
- `app/Models/Brand.php`
- `app/Models/Category.php`
- `app/Models/LensType.php`
- `app/Models/Product.php`
- `app/Models/ProductVariant.php`
- `app/Models/ProductImage.php`
- `database/factories/ProductFactory.php`
- `database/factories/ProductVariantFactory.php`
- `database/seeders/CatalogSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- `tests/Feature/CatalogSchemaTest.php`

**Estimated scope:** M

#### Task 10: Product Catalog And AR API

**Description:** Expose customer product browsing and AR-eligible variant metadata to the mobile app.

**Acceptance criteria:**
- [x] Customers can list active products and view product details.
- [x] AR-eligible variants expose asset references.
- [x] API responses contain no biometric fields or face data.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=ProductCatalog`

**Dependencies:** Tasks 4, 9

**Files likely touched:**
- `routes/api.php`
- `app/Http/Controllers/Api/ProductController.php`
- `app/Http/Resources/ProductResource.php`
- `app/Http/Resources/ProductVariantResource.php`
- `tests/Feature/Api/ProductCatalogTest.php`

**Estimated scope:** M

#### Task 11: Filament Catalog Management

**Description:** Add staff/admin catalog management for products, variants, lens types, stock basics, images, and AR asset references.

**Acceptance criteria:**
- [x] Staff/admin can create and edit catalog records.
- [x] Low stock state is visible in the product table.
- [x] Upload fields use explicit safe visibility and validation.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=CatalogResource`

**Dependencies:** Tasks 3, 9

**Files likely touched:**
- `app/Filament/Resources/Products/ProductResource.php`
- `app/Filament/Resources/Products/Pages/ListProducts.php`
- `app/Filament/Resources/Products/Pages/EditProduct.php`
- `app/Filament/Resources/LensTypes/LensTypeResource.php`
- `tests/Feature/Filament/CatalogResourceTest.php`

**Estimated scope:** M

#### Task 12: Prescription Records And Customer History

**Description:** Add staff-managed prescriptions and a customer API for prescription history.

**Acceptance criteria:**
- [x] Staff can record prescriptions linked to customers and optionally appointments.
- [x] Customers can view only their own prescription history.
- [x] Prescription dates and OD/OS/PD fields are validated.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=Prescription`

**Dependencies:** Tasks 5, 6

**Files likely touched:**
- `database/migrations/*_create_prescriptions_table.php`
- `app/Models/Prescription.php`
- `app/Http/Controllers/Api/PrescriptionController.php`
- `app/Http/Resources/PrescriptionResource.php`
- `app/Filament/Resources/Prescriptions/PrescriptionResource.php`
- `tests/Feature/Api/PrescriptionTest.php`
- `tests/Feature/Filament/PrescriptionResourceTest.php`

**Estimated scope:** M

### Checkpoint: Catalog And Prescriptions

- [x] `vendor/bin/sail artisan test --compact --filter=ProductCatalog`
- [x] `vendor/bin/sail artisan test --compact --filter=Prescription`
- [x] Active products and AR metadata are available through API.
- [x] Prescription history is customer-scoped.

### Phase 4: Orders, Inventory, And Billing

#### Task 13: Order Request Schema And Customer API

**Description:** Let customers submit order requests with item snapshots, selected variants, fixed lens type selection, optional appointment context, and non-prescription flag.

**Acceptance criteria:**
- [x] Order items snapshot product, variant, lens type, and price at request time.
- [x] Customers can create and list only their own orders.
- [x] Invalid variant, lens type, or appointment ownership is rejected.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=OrderRequest`

**Dependencies:** Tasks 9, 10

**Files likely touched:**
- `database/migrations/*_create_orders_table.php`
- `app/Models/Order.php`
- `app/Models/OrderItem.php`
- `app/Http/Controllers/Api/OrderController.php`
- `app/Http/Requests/Api/StoreOrderRequest.php`
- `app/Http/Resources/OrderResource.php`
- `app/Http/Resources/OrderItemResource.php`
- `tests/Feature/Api/OrderRequestTest.php`

**Estimated scope:** M

### Checkpoint: Order Requests (Customer)

- [x] `vendor/bin/sail artisan test --compact --filter=OrderRequest`
- [x] Customers can submit orders with frozen item snapshots.
- [x] Staff can process order status via API (Task 14).
- [x] Filament order management (Task 15).

#### Task 14: Staff Order Status Action And API

**Description:** Add the shared order status workflow action and staff API for moving orders through approved statuses, including the prescription/non-prescription confirmation gate.

**Acceptance criteria:**
- [x] Staff can move orders through `under_review`, `confirmed`, `preparing`, `ready_for_pickup`, `completed`, and `cancelled` states.
- [x] Orders with `is_non_prescription = false` cannot be confirmed unless the customer has at least one prescription on record.
- [x] Orders with `is_non_prescription = true` can be confirmed without prescription data.
- [x] Status changes use a single `UpdateOrderStatus` action (no duplicated transition logic).

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=OrderProcessing`

**Dependencies:** Tasks 12, 13

**Files likely touched:**
- `routes/api.php`
- `app/Actions/Orders/UpdateOrderStatus.php`
- `app/Models/Order.php`
- `app/Http/Controllers/Api/StaffOrderController.php`
- `app/Http/Requests/Api/UpdateOrderStatusRequest.php`
- `tests/Feature/Api/OrderProcessingTest.php`

**Estimated scope:** M

#### Task 15: Filament Order Resource

**Description:** Build the admin order review workflow for staff and admin users, reusing the same status action as the staff API.

**Acceptance criteria:**
- [x] Staff/admin can list and edit order requests.
- [x] Table filters include order status and customer.
- [x] Status changes through Filament use the same `UpdateOrderStatus` action as the API.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=OrderResource`

**Dependencies:** Tasks 3, 14

**Files likely touched:**
- `app/Filament/Resources/Orders/OrderResource.php`
- `app/Filament/Resources/Orders/Pages/ListOrders.php`
- `app/Filament/Resources/Orders/Pages/EditOrder.php`
- `app/Filament/Resources/Orders/Schemas/OrderForm.php`
- `app/Filament/Resources/Orders/Tables/OrdersTable.php`
- `tests/Feature/Filament/OrderResourceTest.php`

**Estimated scope:** M

#### Task 16: Inventory Movements On Order Confirmation

**Description:** Deduct variant stock when an order is confirmed and create reversal movements when a confirmed order is cancelled.

**Acceptance criteria:**
- [x] Confirmation deducts inventory once.
- [x] Cancellation of confirmed orders restores stock through a reversal movement.
- [x] Low stock calculations remain correct after movements.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=InventoryMovement`

**Dependencies:** Tasks 9, 14

**Files likely touched:**
- `database/migrations/*_create_inventory_movements_table.php`
- `app/Models/InventoryMovement.php`
- `app/Actions/Inventory/RecordInventoryMovement.php`
- `app/Actions/Orders/UpdateOrderStatus.php`
- `tests/Feature/Inventory/InventoryMovementTest.php`

**Estimated scope:** M

#### Task 17: Billing Schema And Generation

**Description:** Add billing records and generate one billing per confirmed order from order item snapshots.

**Acceptance criteria:**
- [x] Confirmed orders can generate one billing record.
- [x] Billing totals and initial balance match order snapshots.
- [x] Duplicate billing generation is prevented.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=BillingGeneration`

**Dependencies:** Tasks 13, 14

**Files likely touched:**
- `database/migrations/*_create_billings_table.php`
- `app/Models/Billing.php`
- `app/Actions/Billing/GenerateBillingForOrder.php`
- `tests/Feature/Billing/BillingGenerationTest.php`

**Estimated scope:** M

#### Task 18: Filament Billing Resource

**Description:** Add staff/admin billing visibility and billing generation triggers in Filament.

**Acceptance criteria:**
- [x] Staff/admin can list billings linked to orders.
- [x] Staff/admin can generate billing from a confirmed order when none exists.
- [x] Duplicate billing generation is blocked in the UI.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=BillingResource`

**Dependencies:** Tasks 3, 17

**Files likely touched:**
- `app/Filament/Resources/Billings/BillingResource.php`
- `app/Filament/Resources/Billings/Pages/ListBillings.php`
- `app/Filament/Resources/Billings/Pages/ViewBilling.php`
- `tests/Feature/Filament/BillingResourceTest.php`

**Estimated scope:** M

#### Task 19: Manual Payments And Billing Balance

**Description:** Add manual payment records and balance recalculation for posted, voided, and reversed payments.

**Acceptance criteria:**
- [x] Posted payments reduce billing balance.
- [x] Voided and reversed payments update balance and billing status correctly.
- [x] Customers can view only their own billing status and payment history.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=Payment`

**Dependencies:** Task 17

**Files likely touched:**
- `database/migrations/*_create_payments_table.php`
- `app/Models/Payment.php`
- `app/Actions/Billing/RecalculateBillingBalance.php`
- `app/Http/Controllers/Api/BillingController.php`
- `tests/Feature/Billing/PaymentTest.php`

**Estimated scope:** M

### Checkpoint: Orders And Billing

- [x] `vendor/bin/sail artisan test --compact --filter=Order`
- [x] `vendor/bin/sail artisan test --compact --filter=Billing`
- [x] Confirming an order deducts stock.
- [x] Billing and payment balances are correct.

### Phase 5: Messaging, Feedback, Audit, And Dashboard

#### Task 20: Conversation And Message API

**Description:** Add anytime customer-staff conversations with optional appointment or order context.

**Acceptance criteria:**
- [x] Customers and staff can create conversations without an order or appointment.
- [x] Conversations can optionally link to an appointment or order.
- [x] Participants can view only conversations they are authorized to access.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=Messaging`

**Dependencies:** Tasks 1, 4

**Files likely touched:**
- `database/migrations/*_create_conversations_table.php`
- `app/Models/Conversation.php`
- `app/Models/Message.php`
- `app/Http/Controllers/Api/ConversationController.php`
- `tests/Feature/Api/MessagingTest.php`

**Estimated scope:** M

#### Task 21: Message Attachments

**Description:** Support validated private attachments on messages.

**Acceptance criteria:**
- [x] Attachments are validated by MIME type, extension, and size.
- [x] Attachments are private by default.
- [x] Only authorized conversation participants can access attachment metadata.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=MessageAttachment`

**Dependencies:** Task 20

**Files likely touched:**
- `database/migrations/*_create_message_attachments_table.php`
- `app/Models/MessageAttachment.php`
- `app/Http/Requests/Api/StoreMessageRequest.php`
- `app/Http/Resources/MessageResource.php`
- `tests/Feature/Api/MessageAttachmentTest.php`

**Estimated scope:** M

#### Task 22: Filament Conversation Management

**Description:** Add staff/admin conversation management and replies in Filament.

**Acceptance criteria:**
- [x] Staff/admin can list conversations and filter by context.
- [x] Staff/admin can reply to customers.
- [x] Attachment metadata is visible without exposing unauthorized files.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=ConversationResource`

**Dependencies:** Tasks 20, 21

**Files likely touched:**
- `app/Filament/Resources/Conversations/ConversationResource.php`
- `app/Filament/Resources/Conversations/Pages/ListConversations.php`
- `app/Filament/Resources/Conversations/Pages/ViewConversation.php`
- `tests/Feature/Filament/ConversationResourceTest.php`

**Estimated scope:** M

#### Task 23: Feedback Workflow

**Description:** Let customers submit feedback after completed appointments or completed orders, with staff replies in Filament.

**Acceptance criteria:**
- [x] Feedback is accepted for completed appointments and completed orders.
- [x] Feedback is rejected for incomplete or unrelated records.
- [x] Staff/admin can view and reply to feedback.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=Feedback`

**Dependencies:** Tasks 8, 14

**Files likely touched:**
- `database/migrations/*_create_feedback_table.php`
- `app/Models/Feedback.php`
- `app/Http/Controllers/Api/FeedbackController.php`
- `app/Filament/Resources/Feedback/FeedbackResource.php`
- `tests/Feature/Api/FeedbackTest.php`

**Estimated scope:** M

#### Task 24: Audit Log Recording

**Description:** Add audit log persistence and hook important workflow actions to record actor, subject, action, and metadata.

**Acceptance criteria:**
- [x] Audit entries include actor, subject, action, and metadata.
- [x] Appointment, inventory, order, billing, payment, and feedback workflow actions create audit logs.
- [x] Audit recording uses a single `CreateAuditLog` action.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=AuditLogRecording`

**Dependencies:** Tasks 7, 16, 19, 23

**Files likely touched:**
- `database/migrations/*_create_audit_logs_table.php`
- `app/Models/AuditLog.php`
- `app/Actions/Audit/CreateAuditLog.php`
- `tests/Feature/AuditLogRecordingTest.php`

**Estimated scope:** M

#### Task 25: Filament Audit Log Resource

**Description:** Add read-only staff/admin audit log visibility in Filament.

**Acceptance criteria:**
- [x] Staff/admin can list and view audit log entries.
- [x] Table filters include action and subject type.
- [x] Audit logs are not editable through Filament.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=AuditLogResource`

**Dependencies:** Tasks 3, 24

**Files likely touched:**
- `app/Filament/Resources/AuditLogs/AuditLogResource.php`
- `app/Filament/Resources/AuditLogs/Pages/ListAuditLogs.php`
- `tests/Feature/Filament/AuditLogResourceTest.php`

**Estimated scope:** S

#### Task 26: Dashboard Widgets

**Description:** Add the approved Filament dashboard cards using efficient aggregate queries.

**Acceptance criteria:**
- [x] Dashboard shows appointment counts, pending orders, low stock, unpaid billings, and recent feedback.
- [x] Widgets avoid N+1 queries and unnecessary model loading.
- [x] Staff/admin can access the dashboard.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=Dashboard`

**Dependencies:** Tasks 8, 16, 19, 23

**Files likely touched:**
- `app/Filament/Widgets/AppointmentOverview.php`
- `app/Filament/Widgets/PendingOrders.php`
- `app/Filament/Widgets/LowStockProducts.php`
- `app/Filament/Widgets/UnpaidBillings.php`
- `tests/Feature/Filament/DashboardTest.php`

**Estimated scope:** M

### Checkpoint: Operations

- [x] `vendor/bin/sail artisan test --compact --filter=Message`
- [x] `vendor/bin/sail artisan test --compact --filter=Feedback`
- [x] `vendor/bin/sail artisan test --compact --filter=Dashboard`
- [x] Messaging works without an appointment or order.
- [x] Dashboard stays limited to approved cards.

### Phase 6: Demo Data And Hardening

#### Task 27: Demo Accounts And Core Seed

**Description:** Ensure fresh seed creates known admin, staff, and customer demo accounts plus catalog baseline for local and defense demos.

**Acceptance criteria:**
- [x] Fresh seed creates admin, staff, and customer accounts with documented credentials.
- [x] Catalog seed data includes products, variants, lens types, and AR references.
- [x] Seeders remain idempotent.

**Verification:**
- [x] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=DemoAccountsSeed`

**Dependencies:** Tasks 1–11

**Files likely touched:**
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/DemoUserSeeder.php`
- `database/seeders/CatalogSeeder.php`
- `tests/Feature/DemoAccountsSeedTest.php`

**Estimated scope:** S

#### Task 28: Defense Workflow Demo Seed

**Description:** Seed end-to-end clinic workflow records for the approved defense path (appointment, order, billing, messages, feedback).

**Acceptance criteria:**
- [x] Demo data includes a representative appointment, order request, billing, messages, and feedback.
- [x] Seeded data supports both prescription and non-prescription order paths.
- [x] Demo flow can run without manual database edits.

**Verification:**
- [x] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=DemoWorkflowSeed`

**Dependencies:** Tasks 1–26

**Files likely touched:**
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/ClinicWorkflowSeeder.php`
- `tests/Feature/DemoWorkflowSeedTest.php`

**Estimated scope:** M

#### Task 29: Final Backend Hardening

**Description:** Run the final backend quality pass across formatting, tests, route review, and build verification.

**Acceptance criteria:**
- [x] Full Pest suite passes.
- [x] Laravel Pint formats dirty PHP files.
- [x] Frontend asset build succeeds for Filament/Vite assets.
- [x] Approved demo path is verified from seeded data.

**Verification:**
- [x] Format: `vendor/bin/sail bin pint --dirty --format agent`
- [x] Tests pass: `vendor/bin/sail artisan test --compact`
- [x] Build succeeds: `vendor/bin/sail npm run build`
- [x] Route review: `vendor/bin/sail artisan route:list --except-vendor`

**Dependencies:** Task 28

**Files likely touched:**
- `routes/api.php`
- `docs/optical-clinic-journey-mvp-spec.md`
- `tests/Feature/DemoWorkflowSeedTest.php`

**Estimated scope:** S

### Checkpoint: Complete

- [x] `vendor/bin/sail bin pint --dirty --format agent`
- [x] `vendor/bin/sail artisan test --compact`
- [x] `vendor/bin/sail npm run build`
- [x] `vendor/bin/sail artisan route:list --except-vendor`
- [x] Full seeded defense flow works without manual database edits.

## Review Gate

Task breakdown approved. Implementation executes one task at a time with the listed tests and checkpoints.

Split tasks further before implementation if they grow beyond five files or one focused session. The authoritative schema is defined by migrations and task acceptance criteria in this document — not `.context/database schema.md`.
