# POCMS Backend — Context Document

> **Living document.** Update this when schema, routes, roles, status values, or architectural decisions change.

---

## What This Is

Laravel 13 backend for the Padilla Optical Clinic Management System. Serves two clients:
- **Filament admin panel** (`/admin`) — staff and admin web UI
- **Android mobile app** — customer-facing, consumes the REST API

---

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.5 |
| Framework | Laravel 13 |
| Admin panel | Filament 5 |
| Auth | Laravel Sanctum (mobile API tokens) |
| Database | MySQL via Laravel Sail |
| Tests | Pest 4 + PHPUnit 12 |
| Formatting | Laravel Pint |
| Frontend assets | Tailwind CSS 4 + Vite 8 |

---

## Roles

Three fixed roles. No dynamic permission management.

| Role | Access |
|---|---|
| `admin` | Filament panel + full API (staff routes included) |
| `staff` | Filament panel + staff API routes |
| `customer` | Mobile API only — cannot access Filament |

Role enforcement: `canAccessPanel()` on `User` model for Filament; `EnsureUserIsStaff` middleware for staff API routes; form request `authorize()` for customer-scoped endpoints.

---

## Demo Accounts

Seeded by `DemoUserSeeder`. All passwords: `password`

| Role | Email |
|---|---|
| Admin | admin@eyecare.test |
| Staff | staff@eyecare.test |
| Customer | customer@eyecare.test |

---

## Database: Key Tables

### Lookup / Status Tables (seeded, rarely changed)

| Table | Values |
|---|---|
| `roles` | admin, staff, customer |
| `appointment_statuses` | pending, confirmed, rescheduled, cancelled, completed |
| `order_statuses` | requested, under_review, confirmed, preparing, ready_for_pickup, completed, cancelled |
| `billing_statuses` | draft, issued, partially_paid, paid, voided |
| `payment_statuses` | posted, voided, reversed |
| `notification_statuses` | queued, sent, failed, cancelled |
| `inventory_movement_statuses` | initial, manual_adjustment, order_commitment, order_reversal |
| `inventory_movement_types` | Restock, Sale, Adjustment, Return |
| `payment_methods` | Cash, GCash, Bank Transfer, Credit Card, Check |
| `discount_types` | Senior Citizen (20%), PWD (20%), Loyalty (10%), Custom |

### Business Tables

| Table | Notes |
|---|---|
| `users` | email + password nullable for walk-in customers |
| `appointments` | customer_id, staff_id (nullable), visit_reason_id, appointment_status_id |
| `prescriptions` | customer_id, appointment_id (nullable), OD/OS/PD fields |
| `products` | brand_id, category_id, name, slug, is_active, product_type (frame/contact_lens/accessory), specifications (nullable JSON), images (nullable JSON array of file paths) — no price/dimensions (on variants) |
| `product_variants` | price, attributes (nullable JSON — replaces old `dimensions`; stores frame measurements or contact lens power/base_curve/diameter), stock_quantity, ar_eligible, ar_asset_reference, images (nullable JSON array of file paths) |
| `orders` | order_number (ORD-YYYY-XXXXXX), customer_id, is_non_prescription, discount_type_id, discount_amount, total_amount |
| `order_items` | price snapshot at order time — product_name, variant_name, unit_price, etc. |
| `billings` | billing_number (BIL-YYYY-XXXXXX), order_id (1:1), due_date |
| `payments` | billing_id, payment_method_id, amount, payment_status_id |
| `conversations` | customer_id — one per customer |
| `messages` | conversation_id, sender_id, body |
| `message_context_links` | polymorphic — links message to Appointment, Order, or Product |
| `message_attachments` | private storage, images + PDFs only |
| `feedback` | customer_id, appointment_id or order_id (one required), rating (1–5), staff_reply |
| `audit_logs` | actor_id, subject_type, subject_id, action, metadata (JSON) |
| `sms_notifications` | appointment-scoped only |

### Soft Deletes

These models use `SoftDeletes`: `Product`, `ProductVariant`, `Order`, `Billing`, `Appointment`, `Prescription`, `Conversation`, `Feedback`.

---

## Product Data Model

**Products have no price or dimensions** — those live exclusively on variants.

- `products` = catalog entry (brand, category, name, slug, description, is_active, product_type, images)
- `product_variants` = purchasable SKU (price, dimensions, stock, AR data)
- Every product must have at least one variant
- Simple products (e.g., lens cleaning kit) get one variant named "Standard" — dimensions left null
- **Images** follow the optical industry standard — two levels:
  - `products.images` — product-level hero/lifestyle shots (JSON array of paths)
  - `product_variants.images` — variant-specific images per colorway/size (JSON array of paths). Android app should prefer variant images when a variant is selected, fall back to product images if none.
  - No separate images table. API returns both. No `is_primary` or `sort_order` metadata.
- **`product_type`** controls form behavior: `frame` shows attributes + AR fields on variants; `contact_lens` and `accessory` hide them. Fixed values: `frame`, `contact_lens`, `accessory`. Categories remain free-form.
- **`specifications`** — nullable JSON on products for product-level metadata (e.g., `{"material":"Acetate","shape":"Rectangle"}` for frames; `{"wear_type":"Monthly","pack_size":6}` for contact lenses).
- **`attributes`** — replaces the old `dimensions` JSON on variants. Generic key-value store for variant-specific data. Frame: `{"eye_size":52,"bridge":18,"temple":140}`. Contact lens: `{"power":"-1.25","base_curve":"8.4","diameter":"14.0"}`. Accessory: empty/null.

See `docs/product-data-structure.md` for full rationale.

---

## Status Transition Rules

Status changes always go through the relevant action class — never direct model update.

**Appointments** (`UpdateAppointmentStatus`):
```
pending → confirmed, rescheduled, cancelled
confirmed → rescheduled, cancelled, completed
rescheduled → confirmed, cancelled, completed
cancelled → (terminal)
completed → (terminal)
```
SMS notification records created on: confirmed, rescheduled, cancelled.

**Orders** (`UpdateOrderStatus`):
```
requested → under_review, cancelled
under_review → confirmed, cancelled
confirmed → preparing, cancelled
preparing → ready_for_pickup, cancelled
ready_for_pickup → completed, cancelled
completed → (terminal)
cancelled → (terminal)
```
Inventory deducted on `confirmed`. Inventory restored on `cancelled` (if was `confirmed`).
Prescription gate: orders with `is_non_prescription = false` cannot be confirmed without a customer prescription on record.

---

## Filament Panel

URL: `/admin` — accessible to `staff` and `admin` roles only.

**Resources (operational):**
- Appointments — guarded status dropdown on edit form
- Orders — guarded status dropdown on edit form
- Products — 3-col sidebar layout. Main area: Product Details (name, slug auto-generated read-only, RichEditor description), Images. Sidebar: Status (visibility toggle), Associations (brand, category). On create: inline Variants Repeater (min 1 required). On edit: Variants managed via VariantsRelationManager table below form with row actions (View, Edit, Adjust Price, Adjust Stock).
- Prescriptions
- Billings — generate billing from confirmed orders, record/void payments
- Conversations — chat-style page
- Feedback
- Audit Logs (read-only)

**Resources (lookup / settings):**
- Categories, Brands, Lens Types, Visit Reasons (recommend grouping under "Settings" nav group — not yet implemented)

**Dashboard widgets:** appointment counts, pending orders, low stock, unpaid billings, recent feedback.

---

## Mobile REST API

Base: `/api` (no versioning prefix — Android app already built against these routes)

```
POST   /register               Customer registration → returns Sanctum token
POST   /login                  Login → returns Sanctum token
GET    /user                   Authenticated user profile
POST   /logout

GET    /appointments            Customer's own appointments
POST   /appointments            Book appointment (customer, status locked to pending)
GET    /appointments/{id}

GET    /products                Active products (paginated)
GET    /products/{id}           Product detail with variants + AR metadata

GET    /prescriptions           Customer's own prescription history
GET    /prescriptions/{id}

POST   /orders                  Submit order request (status locked to requested)
GET    /orders                  Customer's own orders
GET    /orders/{id}

GET    /billing/{id}            Customer billing with payment history

GET    /conversations           Customer's single persistent conversation
GET    /conversations/{id}/messages
POST   /conversations/{id}/messages  (with optional contexts[] and attachments)
GET    /attachments/{id}        Download attachment (authorized)

POST   /feedback                Submit feedback (completed appointment or order only)
GET    /feedback
GET    /feedback/{id}

--- Staff only (EnsureUserIsStaff middleware) ---
PATCH  /staff/appointments/{id}/status
PATCH  /staff/orders/{id}/status
```

---

## Key Actions (Single-Purpose Workflow Classes)

| Action | Location | Does |
|---|---|---|
| `UpdateAppointmentStatus` | `app/Actions/Appointments/` | Validates transition, updates status, creates SMS record, fires audit log |
| `UpdateOrderStatus` | `app/Actions/Orders/` | Validates transition, checks prescription gate, deducts/restores inventory, fires audit log |
| `ApplyDiscount` | `app/Actions/Orders/` | Calculates discount_amount from type, updates total_amount |
| `GenerateBillingForOrder` | `app/Actions/Billing/` | Creates billing from confirmed order (1:1, duplicate-guarded) |
| `RecalculateBillingBalance` | `app/Actions/Billing/` | Sums posted payments, updates amount_paid/balance_due/status |
| `RecordPayment` | `app/Actions/Billing/` | Creates payment + recalculates balance |
| `RecordInventoryMovement` | `app/Actions/Inventory/` | Creates inventory_movement record, updates variant stock_quantity |
| `CreateAuditLog` | `app/Actions/Audit/` | Persists audit entry (actor, subject, action, metadata) |

---

## Important Conventions

- **Walk-in customers:** `users.email` and `users.password` are nullable. Walk-in records have only name + phone. They cannot log in to the mobile app.
- **Order item snapshots:** `order_items` stores product/variant/lens names and prices at time of order. Source records can change without affecting historical orders.
- **Billing:** One billing per order. Generated manually by staff after order is confirmed. Payments reduce balance; voided/reversed payments undo that reduction.
- **Conversations:** One persistent conversation per customer. Context links (Appointment, Order, Product) attach per-message via `message_context_links` polymorphic table.
- **AR assets:** Backend stores only `ar_asset_reference` (a path/reference string). No biometric data, face geometry, or facial landmarks are stored anywhere.
- **SMS:** Appointment events only (confirmation, reschedule, cancellation). Records stored in `sms_notifications`. Real sending via Semaphore behind config flag — faked in tests.

---

## Filament UI Conventions

- **`is_active` fields** are labelled "Visibility" in all forms. Toggle states are "Visible" / "Hidden" with helper text explaining the consequence to staff. The database column stays `is_active` — label is UI-only.
- **Status dropdowns** on appointment and order edit forms show only valid next transitions (cycle-guarded via `ALLOWED_TRANSITIONS` in the action class). Staff cannot skip steps or move to an invalid state through the form.
- **Status on create forms** is not shown — the system auto-assigns the initial status (`pending` for appointments, `requested` for orders).
- **Walk-in customer quick-create** is available inline on appointment and order create forms via `->createOptionForm()` on the customer select. Creates a user with name + phone, no email/password.

---

## Pending Work

See specs for full task breakdowns:

| Spec | Status |
|---|---|
| `docs/pre-phase2-bugfix-spec.md` | Draft — 8 tasks (walk-in customers, status locking, payment recording) |
| `docs/post-mvp-phase2-spec.md` | Draft — 11 tasks (user management, staff assignment, lookup tables, discounts, notifications) |

---

## Running the Project

```bash
vendor/bin/sail up -d                                    # start
vendor/bin/sail artisan migrate:fresh --seed             # reset + seed
vendor/bin/sail artisan test --compact                   # run all tests
vendor/bin/sail artisan test --compact --filter=Name     # filtered tests
vendor/bin/sail bin pint --dirty --format agent          # format changed PHP
vendor/bin/sail npm run build                            # build frontend assets
vendor/bin/sail artisan route:list --except-vendor       # inspect routes
```
