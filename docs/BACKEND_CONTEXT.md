# POCMS Backend — Context Document

> **Living document.** Update this when schema, routes, roles, status values, or architectural decisions change.

---

## What This Is

Laravel 13 backend for the Padilla Optical Clinic Management System. Serves two clients:
- **Filament admin panel** (`/admin`) — staff and admin web UI
- **Android mobile app** — customer-facing, consumes the REST API

---

## Branding

| Element | Value |
|---|---|
| App name | Eyecare |
| Clinic name | Padilla Optical Clinic |
| Primary color | `#4F8DD7` (use in both web panel and mobile app) |
| Panel font | Instrument Sans (400/500/600) |
| Logo | Biconvex lens/eye mark + "Eyecare" wordmark — see `resources/views/filament/admin/logo.blade.php` |
| Favicon | `public/images/favicon.svg` |
| Default theme mode | Light (dark mode toggle available) |

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

### Admin vs Staff permissions

Use `User::isAdmin()` to check role in Filament. The default is "staff can do all operational work; admin additionally controls configuration and destructive actions."

| Area | Staff CAN | Admin only |
|---|---|---|
| Appointments | Create, edit, change status, assign staff, bill service | — |
| Orders | Create, edit, advance status, assign lenses, cancel `requested` | Cancel `confirmed`/`processing`/`ready_for_pickup` (triggers inventory reversal) |
| Billings | View, record payment, add service | Void billing, apply/change discount |
| Products | Create, edit, manage variants, adjust stock | Delete/restore |
| Prescriptions | Create, edit | Delete/restore |
| Appointments | Create, edit, change status | Delete/restore |
| Users | ❌ Hidden entirely | Full CRUD |
| Audit Logs | ❌ Hidden entirely | Read-only access |
| Settings (categories, brands, lens types, visit reasons, services) | ❌ Hidden entirely | Full CRUD |

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
| `order_statuses` | requested, confirmed, processing, ready_for_pickup, completed, cancelled |
| `billing_statuses` | issued, partially_paid, paid, voided |
| `payment_statuses` | posted, voided |
| `notification_statuses` | queued, sent, failed, cancelled |
| `inventory_movement_types` | restock, manual_adjustment, order_commitment, order_reversal |
| `payment_methods` | Cash, GCash, Bank Transfer, Credit Card, Check |
| `discount_types` | Senior Citizen (20%), PWD (20%), Loyalty (10%), Custom |

### Business Tables

| Table | Notes |
|---|---|
| `users` | email + password nullable for walk-in customers, date_of_birth (nullable) |
| `appointments` | customer_id, staff_id (nullable), visit_reason_id, appointment_status_id |
| `prescriptions` | customer_id, appointment_id (nullable), OD/OS/PD fields |
| `products` | brand_id, category_id (nullable FK → product_categories), lens_type_id (nullable FK — only for type `lens`), name, slug, is_active, product_type (frame/lens/contact_lens/accessory), images (nullable JSON). No price/dimensions (on variants). |
| `product_variants` | price, compare_at_price, cost_price, attributes (nullable JSON), stock_quantity, low_stock_threshold, ar_eligible, ar_asset_reference, images (nullable JSON) |
| `product_categories` | name. FK target for products.category_id. PHP class: `ProductCategory`. |
| `services` | name, description, price, is_active. Fee schedule for billable clinical services. |
| `service_records` | customer_id, service_id, appointment_id (nullable), staff_id, amount, notes, performed_at. Audit log of services performed — created when a service is added to a billing. |
| `orders` | order_number (ORD-YYYY-XXXXXX), customer_id, is_non_prescription, discount_type_id, discount_amount, subtotal, total_amount |
| `order_items` | price snapshot — product_name, variant_name, unit_price, lens_type_id (nullable FK), lens_type_name (nullable), lens_type_price (nullable), lens_product_variant_id (nullable — specific lens assigned by staff), subtotal. |
| `billings` | billing_number (BIL-YYYY-XXXXXX), customer_id, order_id (nullable FK — set when triggered by an order), appointment_id (nullable FK — used for encounter grouping via GetOrCreateBilling), discount_type_id (nullable), discount_amount, subtotal, total_amount, amount_paid, balance_due, issued_at |
| `billing_items` | billing_id, type (product\|service), description, quantity, unit_price, amount, order_item_id (nullable FK), service_record_id (nullable FK). Line items on an invoice. |
| `payments` | billing_id, payment_method_id, amount, payment_status_id |
| `conversations` | customer_id — one per customer |
| `messages` | conversation_id, sender_id, body, read_at (nullable timestamp) |
| `message_context_links` | polymorphic — links message to Appointment, Order, or Product |
| `message_attachments` | private storage, images + PDFs only |
| `feedback` | customer_id, appointment_id or order_id (one required), rating (1–5), comment |
| `audit_logs` | actor_id, subject_type, subject_id, action, metadata (JSON) |
| `inventory_movements` | product_variant_id, order_id, inventory_movement_type_id, quantity_change, previous_stock, new_stock, created_by (FK to users), notes |
| `sms_notifications` | appointment_id (nullable), order_id (nullable), notification_status_id, event, recipient, message, failure_reason (nullable). Queued records dispatched via `sms:process` command using Semaphore API (config-gated). |

### Soft Deletes

These models use `SoftDeletes`: `Product`, `ProductVariant`, `Order`, `Billing`, `Appointment`, `Prescription`, `Conversation`, `Feedback`, `ServiceRecord`.

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
- **`product_type`** controls form behavior and API visibility. Fixed values: `frame`, `lens`, `contact_lens`, `accessory`. `frame` shows AR fields; all types show `attributes`. Mobile API returns only `frame` products. **Disabled on edit** — set at creation time only.
- **`lens_type_id`** — nullable FK on products, only used for `product_type = 'lens'`. Links a lens product to its lens type category (progressive, single_vision, etc.). The form shows this field only when type is `lens`.
- **`attributes`** — replaces old `dimensions`. Generic key-value JSON on variants, visible for ALL product types. Frame: `{"eye_size":52,"bridge":18,"temple":140}`. Contact lens: `{"power":"-1.25","base_curve":"8.4","diameter":"14.0"}`. Accessory/Lens: use as needed.

See `docs/product-data-structure.md` for full rationale.

---

## Billing Model (Encounter/Invoice)

Billings are standalone invoices — not owned by any single entity. A billing has:
- A `customer_id` (who pays)
- An optional `order_id` (which order triggered this billing, if any)
- One or more `billing_items` — product charges (from order items) or service charges (from service records)
- A discount applied at the billing level (`discount_type_id`, `discount_amount`)
- A payment trail via `payments`

**Two creation paths:**
1. **Order confirmed** → `GenerateBillingForOrder` calls `GetOrCreateBilling(customer, appointment)` to find or create a billing, then `AddOrderItemsToBilling` to populate product line items
2. **Staff bills a service** → "Bill Service" action on Appointment/Patient page → `CreateServiceBilling` calls `GetOrCreateBilling(customer, appointment)` then `AddServiceToBilling`

**`GetOrCreateBilling` — encounter grouping:** If `appointment_id` is provided, it finds an existing non-voided billing for that customer+appointment and reuses it. This means an order billing and a service billing for the same appointment automatically merge into one invoice. If `appointment_id` is null (walk-in with no appointment), always creates a new billing.

**Billing items** (`billing_items`):
- `type = 'product'` → links to `order_item_id`, description is "Product — Variant"
- `type = 'service'` → links to `service_record_id`, description is the service name

**Adding services to an existing billing:** Staff uses the "Add Service" action on the ViewBilling page. If an appointment has an existing order billing, "Bill Service" on the appointment page adds a service item to that billing instead of creating a new one.

---

## Status Transition Rules

Status changes always go through the relevant action class — never direct model update.

**Appointments** (`UpdateAppointmentStatus`):
```
pending → confirmed, cancelled          (+ reschedule via dedicated action)
confirmed → cancelled, completed        (+ reschedule via dedicated action)
rescheduled → confirmed, cancelled, completed
cancelled → (terminal)
completed → (terminal)
```
SMS notification records created on: confirmed, rescheduled, cancelled.
Rescheduling always goes through the dedicated "Reschedule" action (header action on edit page, row action in list) which accepts a new date — it does not appear in the status toggle buttons.

**Orders** (`UpdateOrderStatus`):
```
requested → confirmed, cancelled
confirmed → processing, cancelled
processing → ready_for_pickup, cancelled
ready_for_pickup → completed, cancelled
completed → (terminal)
cancelled → (terminal)
```
Inventory deducted on `confirmed`. Inventory restored + billing voided on `cancelled` (if was `confirmed`).
Prescription gate: orders with `is_non_prescription = false` cannot be confirmed without a customer prescription on record.
Lens gate: all order items with `lens_type_id` must have `lens_product_variant_id` assigned before confirming.
Discount: applied at confirmation time via `discount_type_id` (Senior Citizen 20%, PWD 20%, Loyalty 10%, Custom fixed amount).

**Billing statuses** (`issued → partially_paid → paid` + `voided`):
- `issued` — billing generated, no payments recorded
- `partially_paid` — some payments posted but balance remains
- `paid` — balance_due = 0
- `voided` — billing cancelled (auto-triggered when order is cancelled from confirmed)

---

## Filament Panel

URL: `/admin` — accessible to `staff` and `admin` roles only.

**Panel features:**
- **Database Notifications** — bell icon in topbar with unread badge. Auto-fires on: new appointment booked, new order placed, order confirmed, customer cancels appointment/order, low stock alert. All staff/admin receive.
- **Global Search** — topbar search bar (opt-in). Searches: Patients (name/phone/email), Orders (order number/customer name), Appointments (customer name/phone), Products (name/variant SKU).

**Navigation groups (in order):**
- *(ungrouped)* — Appointments, Prescriptions, Patients
- Orders & Billing — Orders, Billings
- Products & Inventory — Products, Inventory History
- Communication — Conversations, Feedback, SMS Log (admin only)
- Reports — Sales, Orders, Appointments, Feedback, Reorder (admin only)
- Administration — Users, Audit Logs
- Settings — Categories, Brands, Lens Types, Visit Reasons, Services

**Resources (operational):**
- Appointments — guarded status toggle buttons on edit form (cycle-guarded, excludes rescheduled); staff assignment. "Reschedule" is a dedicated header action (and row action in list) that opens a date picker modal — it is not selectable via the status toggle buttons. "Bill Service" header action opens modal to add a service charge to existing billing (if linked order has one) or create a standalone service billing. Calendar view (toggle on the list page): drag an event to reschedule (validates status + ±30-min conflict via `UpdateAppointmentStatus`), click an empty day to create with `scheduled_at` pre-filled, click an event to open its edit page.
- Orders — KPI stats (reactive to active tab) + status tabs on list. Table with group-by-date, toggleable columns, date range filters, row actions (advance/cancel/edit in ⋮ menu). Create: 2-step wizard (Order Details → Order Items table repeater). Edit: sidebar (dates), inline ToggleButtons (cycle-guarded, sequential), discount selector, RichEditor notes. Full-width Order Items section (4-col grid repeater, inline lens assignment). Live Order Summary (subtotal/discount/total). View Billing header action. Soft delete with restore.
- Products — 3-col sidebar layout. Product type at top of Product Details (disabled on edit). On create: inline Variants Repeater (min 1). On edit: Variants managed via VariantsRelationManager table (image, name, SKU, price, visible ✓/✗, AR ✓/✗ (frames only), qty) with Adjust Stock (movement type selector), Adjust Price row actions. Product type + visibility filters on list. Products table shows: thumbnail, name, brand, category, type badge, visible ✓/✗, total qty.
- Prescriptions — edit form with sections (Patient Info, OD/OS side-by-side, Prescription Details)
- Patients — dedicated resource for customer-role users labeled as "Patients". List: Name, Phone, Email, Last Visit, Orders count. Edit: Patient Information section + relation managers for Prescriptions, Appointments, Orders. "Bill Service" header action. DB role stays `customer`, UI label is "Patient". Customers cannot access.
- Billings — KPI stats (total, unpaid, collected) + status tabs. Table shows: billing #, customer name, items summary, total, balance, status. Row actions: View, Record Payment. View page: infolist with Billing Summary section (billing #, status, issued at, patient, amount paid, balance due), Linked Records section (clickable links to Order and Appointment), Line Items section (items table + subtotal/discount/total below). Header actions: Add Service (modal), Apply Discount (modal, recalculates totals, admin only), Void Billing (destructive, auto-voids posted payments, admin only). Not deletable — voided via Void Billing action or automatically on order cancellation. No create page.
- Conversations — chat-style page
- Feedback — read-only. List: customer, rating, comment (toggleable), appointment/order (hidden by default, toggleable), submitted date. Filter by rating. View page: sections layout (Feedback Details + Timestamps sidebar). Staff reply was intentionally removed — staff communicates with patients via Conversations instead.
- Inventory History — read-only movement log. Columns: Date, Product, Variant, Type (badge), Change (+/-), Before, After, By. Type/date range filters. View modal shows full details including notes and order link.
- Audit Logs (read-only)
- User Management (admin only) — scoped to staff/admin accounts only (customers managed via Patients). 3-col sidebar layout: main (Account Details: name, email, phone, password) + sidebar (Role & Access selector + Timeline). Table: name, email, phone, color-coded role badge (admin=red, staff=blue), relative joined date. Role selector restricted to admin/staff. Self-role-edit disabled. Last admin demotion blocked.
- SMS Log (admin only) — read-only log of all SMS notifications. Columns: recipient, event badge, status badge, message, created at. Filters: status, event type. Row action: Retry (failed records only) — resets status to `queued`.

**Resources (lookup / settings — grouped under "Settings" nav):**
- Categories, Brands (CRUD), Lens Types (with price + description), Visit Reasons, Services (fee schedule with price, description, visibility toggle)
- All settings edit forms use a 2-column layout: main details section (left, 2/3) + Timestamps sidebar (right, 1/3) showing Created at and Last modified.
- Edit pages include relation managers: Brands → Products table, Categories → Products table, Lens Types → Products table, Visit Reasons → Appointments table. Services has no relation manager (service_records are audit-only, not directly managed).

**Dashboard widgets (ordered top to bottom):**
1. **Stats Overview** — Today's appointments (sparkline + delta vs yesterday), Revenue this month (sparkline + % vs last month), Pending orders (sparkline), Unpaid billings (₱ outstanding), Low stock variants
2. **Appointments Chart** (hero) — 30-day trend line of daily non-cancelled appointments, brand color `#4F8DD7`
3. **Recent Feedback** — last 5 feedback entries table

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
GET    /visit-reasons           List all visit reasons (id, name, duration_minutes)

GET    /products                Active FRAME products only, paginated (default 15, `?per_page=N`). Non-frame types return 404.
GET    /products/{id}           Product detail with variants + AR metadata (404 for non-frame products)

GET    /prescriptions           Customer's own prescription history
GET    /prescriptions/{id}

POST   /orders                  Submit order request (status locked to requested). `items[].lens_type_id` is nullable — omit for accessories/contact lenses.
GET    /orders                  Customer's own orders, paginated (default 15, `?per_page=N`)
GET    /orders/{id}

GET    /billing/{id}            Customer billing with line items + payment history (auth: billing.customer_id must match user)

GET    /conversations           Customer's single persistent conversation (includes unread_count)
GET    /conversations/{id}/messages
POST   /conversations/{id}/messages  (with optional contexts[] and attachments)
POST   /conversations/{id}/messages/read  Mark all messages from other party as read
GET    /attachments/{id}        Download attachment (authorized)

POST   /feedback                Submit feedback (completed appointment or order only)
GET    /feedback
GET    /feedback/{id}

POST   /appointments/{id}/cancel  Cancel own appointment (pending or confirmed only)
POST   /orders/{id}/cancel        Cancel own order (requested only)
PATCH  /user                      Update own profile (name, email, phone)

--- Staff only (EnsureUserIsStaff middleware) ---
PATCH  /staff/appointments/{id}/status
PATCH  /staff/orders/{id}/status
```

---

## API Response Examples (for Android)

**POST /register** and **POST /login** → returns:
```json
{ "token": "1|abc123...", "user": { "id": 3, "name": "...", "email": "...", "phone": "...", "role": "customer" } }
```

**GET /user:**
```json
{ "data": { "id": 3, "name": "Demo Customer", "email": "customer@eyecare.test", "phone": "09171234567", "role": "customer" } }
```

**GET /products** (paginated, frame-only):
```json
{
  "data": [{
    "id": 3,
    "name": "Classic Rectangle Frame",
    "slug": "classic-rectangle-frame",
    "description": "...",
    "product_type": "frame",
    "brand": "VisionCraft",
    "category": "Frames",
    "variants": [{
      "id": 3,
      "name": "Matte Black",
      "sku": "CRF-BLK-001",
      "price": "159.99",
      "compare_at_price": null,
      "attributes": { "bridge": 18, "temple": 140, "lens_width": 52 },
      "ar_eligible": true,
      "ar_asset_reference": "ar-assets/abc123.glb",
      "images": []
    }],
    "images": []
  }],
  "links": { "first": "...", "next": "..." },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 2 }
}
```

**GET /appointments:**
```json
{
  "data": [{
    "id": 1,
    "visit_reason": "Eye Exam",
    "status": "confirmed",
    "scheduled_at": "2026-06-22T10:00:00.000000Z",
    "contact_notes": "...",
    "staff_notes": "...",
    "assigned_staff": { "id": 2, "name": "Demo Staff" }
  }]
}
```

**GET /orders** (paginated):
```json
{
  "data": [{
    "id": 4,
    "order_number": "ORD-2026-000004",
    "appointment_id": null,
    "billing_id": null,
    "is_non_prescription": true,
    "status": "requested",
    "subtotal": "5600.00",
    "total_amount": "5600.00",
    "items": [{
      "id": 4,
      "product_variant_id": 2,
      "lens_type_id": null,
      "product_id": 2,
      "product_name": "Zeiss Single Vision",
      "variant_name": "1.50 Standard",
      "variant_sku": "ZSV-150-STD",
      "lens_type_name": null,
      "unit_price": "2800.00",
      "quantity": 1,
      "subtotal": "5600.00",
      "product_images": [],
      "variant_images": []
    }],
    "created_at": "2026-06-19T05:16:53.000000Z"
  }],
  "links": { "first": "...", "next": "..." },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 2 }
}
```

**POST /orders** request body:
```json
{
  "is_non_prescription": true,
  "appointment_id": null,
  "items": [
    { "product_variant_id": 3, "lens_type_id": 1, "quantity": 1 },
    { "product_variant_id": 5, "lens_type_id": null, "quantity": 2 }
  ]
}
```

**GET /visit-reasons:**
```json
{
  "data": [
    { "id": 1, "name": "Eye Exam", "duration_minutes": 30 },
    { "id": 2, "name": "Follow-up", "duration_minutes": 15 },
    { "id": 3, "name": "Prescription Check", "duration_minutes": 20 },
    { "id": 4, "name": "Contact Lens Fitting", "duration_minutes": 60 }
  ]
}
```

**GET /billing/{id}:**
```json
{
  "data": {
    "id": 1,
    "billing_number": "BIL-2026-000001",
    "status": "partially_paid",
    "subtotal": "2500.00",
    "discount_amount": "0.00",
    "total_amount": "2500.00",
    "amount_paid": "1000.00",
    "balance_due": "1500.00",
    "issued_at": "2026-06-29T10:00:00.000000Z",
    "created_at": "2026-06-29T10:00:00.000000Z",
    "items": [
      { "id": 1, "type": "product", "description": "Classic Frame — Matte Black", "quantity": 1, "unit_price": "2500.00", "amount": "2500.00" }
    ],
    "payments": [
      { "id": 1, "amount": "1000.00", "status": "posted", "method": "Cash", "reference_number": null, "paid_at": "2026-06-29T11:00:00.000000Z" }
    ]
  }
}
```

---

## Key Actions (Single-Purpose Workflow Classes)

| Action | Location | Does |
|---|---|---|
| `UpdateAppointmentStatus` | `app/Actions/Appointments/` | Validates transition, updates status, creates SMS record, fires audit log |
| `UpdateOrderStatus` | `app/Actions/Orders/` | Validates transition, checks prescription gate, deducts/restores inventory, fires audit log |
| `ApplyDiscount` | `app/Actions/Orders/` | Calculates discount_amount from type, updates total_amount |
| `GenerateBillingForOrder` | `app/Actions/Billing/` | Calls GetOrCreateBilling then AddOrderItemsToBilling to create/update invoice for confirmed order |
| `GetOrCreateBilling` | `app/Actions/Billing/` | Finds existing non-voided billing for customer+appointment, or creates a new issued one. Null appointment always creates new. |
| `AddOrderItemsToBilling` | `app/Actions/Billing/` | Adds product billing_items from order_items to an existing billing, sets order_id, copies discount, recalculates totals |
| `AddServiceToBilling` | `app/Actions/Billing/` | Creates service_record + service billing_item, recalculates billing subtotal/total |
| `CreateServiceBilling` | `app/Actions/Billing/` | Calls GetOrCreateBilling then AddServiceToBilling. Uses appointment_id for grouping. |
| `RecalculateBillingBalance` | `app/Actions/Billing/` | Sums posted payments, updates amount_paid/balance_due/status |
| `RecordPayment` | `app/Actions/Billing/` | Creates payment + recalculates balance |
| `RecordInventoryMovement` | `app/Actions/Inventory/` | Creates inventory_movement record (with previous_stock, new_stock, created_by), updates variant stock_quantity, fires low stock notification if stock ≤ threshold after deduction |
| `CreateAuditLog` | `app/Actions/Audit/` | Persists audit entry (actor, subject, action, metadata) |
| `ProcessSmsNotification` | `app/Actions/Sms/` | Takes a queued SmsNotification, calls SemaphoreService, updates status to `sent` or `failed` with reason |

---

## Important Conventions

- **Walk-in customers:** `users.email` and `users.password` are nullable. Walk-in records have only name + phone. They cannot log in to the mobile app.
- **Order item totals:** `subtotal` = (`unit_price` + `lens_type_price`) × `quantity`. `lens_type_id` and `lens_type_price` are nullable (no lens = frame-only price). Order `subtotal` = sum of all item subtotals. `total_amount` = `subtotal` − `discount_amount`. Both recalculate when staff assigns a lens product variant.
- **Billing (encounter model):** A billing is a standalone invoice with line items. When an order is confirmed, a billing is auto-generated with product line items and a copy of the order's discount. Staff can add service items to any non-voided billing via "Add Service" on the ViewBilling page, or via "Bill Service" on the Appointment/Patient edit page. Standalone service billings (no order) are created the same way. `billing_items.created_at` is insert-only — line items are never edited.
- **Billing auth (API):** `GET /billing/{id}` checks `billing.customer_id === $user->id` directly — no polymorphic lookup.
- **Insufficient stock:** If a variant has 0 stock when an order is confirmed, `UpdateOrderStatus` throws a `ValidationException` (not a crash). The order status remains `requested`.
- **Lens inventory:** Lens products (type `lens`) are linked to a `lens_type` via `products.lens_type_id`. Staff assigns a specific lens product variant per order item via the ItemsRelationManager **on the order edit page while the order is still `requested`**. "Assign Lens" action is hidden once the order is confirmed or beyond. Confirmation is gated: if any order item has `lens_type_id` set but `lens_product_variant_id` is null, `UpdateOrderStatus` throws a `ValidationException` — staff must assign all lenses before confirming. On confirmation, both frame variant AND lens product variant stock deduct. On cancellation (from confirmed), both restore. Mobile API returns only `frame` products — all other types are admin-only.
- **Inventory movements:** All stock changes go through `RecordInventoryMovement`. Types: `restock`, `manual_adjustment`, `order_commitment`, `order_reversal`. Each movement records `previous_stock`, `new_stock`, and `created_by` (the user who triggered it, or null for system actions). Staff uses the "Adjust Stock" action on the Variants table (restock = add units, manual_adjustment = remove units). `stock_quantity` is read-only on the variant edit form — changes only through Adjust Stock. Full history viewable in Inventory History resource (read-only, with view modal per row).
- **Product categories:** The DB table is `product_categories` and the PHP class is `ProductCategory`. The FK column on `products` stays `category_id`. The Filament nav label is "Categories".
- **Services vs Visit Reasons:** `visit_reasons` describe *why a patient is booking* (scheduling vocabulary). `services` describe *what was performed and charged* (billing vocabulary). They are separate tables with different purposes. Visit reason names use proper capitalization: "Eye Exam", "Follow-up", "Prescription Check".
- **Billing grouping by appointment:** When `GetOrCreateBilling` is called with an `appointment_id`, it reuses any existing non-voided billing for that appointment. This means an order billing and a service billing for the same appointment share one invoice automatically. Walk-ins without an appointment (`appointment_id = null`) always get a fresh billing.
- **Service records:** `service_records` are created automatically when a service is added to a billing — they are the audit trail of "what was performed, by whom, when." They are not managed directly by staff; the "Bill Service" / "Add Service" actions create them as a side effect.
- **Conversations:** One persistent conversation per customer. Context links (Appointment, Order, Product) attach per-message via `message_context_links` polymorphic table. `messages.read_at` tracks when a message was read. `GET /conversations` returns `unread_count` (messages from the other party with null `read_at`). Customers mark messages read via `POST /conversations/{id}/messages/read`.
- **Appointment slot check:** `POST /appointments` (API) and the Filament create form both validate that no non-cancelled appointment overlaps with the requested time slot (using each appointment's visit reason `duration_minutes`). Returns 422 with "This time slot is not available" if a conflict exists. Reschedule (edit) excludes the current appointment from the conflict check.
- **AR assets:** `ar_asset_reference` stores the storage path to the uploaded overlay image. Staff uploads transparent PNG files (front-facing frame, landscape ~3:1 ratio, tight crop, no background) via FileUpload on the variant edit form (only visible on frame variants with `ar_eligible` enabled). Max 10MB. Files stored at `storage/app/public/ar-assets/`. No biometric data, face geometry, or facial landmarks are stored. Android accesses via `{APP_URL}/storage/{ar_asset_reference}`.
- **SMS:** Appointment events (confirmation, reschedule, cancellation) and order events (confirmed, ready_for_pickup, completed, cancelled). Records stored in `sms_notifications` with status `queued`. `sms:process` command dispatches `SendSmsJob` per record to the queue (3 retries, 30s backoff). Actual delivery via `SemaphoreService`. Config: `services.semaphore.enabled` (default false — disabled in dev/tests). Failed sends record `failure_reason`; admin can retry via SMS Log Filament resource.
- **Appointment reminders:** `appointments:send-reminders` command creates queued SMS records for tomorrow's confirmed appointments. Idempotent (won't duplicate if run multiple times per day). Schedule daily at 6 PM.
- **Token expiration:** Sanctum tokens expire after 30 days (`config/sanctum.php` → `expiration = 43200`). Expired tokens return 401.
- **Rate limiting:** Login/register: 5 attempts/minute per IP (`throttle:login`). General authenticated API: 60 requests/minute per user (`throttle:60,1`). Exceeding returns 429.
- **Stock visibility:** `GET /products` variant objects include `"in_stock": true|false` (derived from `stock_quantity > 0`). Additive — does not break existing Android responses.
- **Prescription encryption at rest:** All sensitive prescription health data columns (sphere, cylinder, axis, add, prism, base, pd, notes) use Laravel's `encrypted` cast — stored as AES-256 ciphertext in MySQL. Demonstrates DPA compliance. Non-health columns (dates, FKs) remain unencrypted.
- **Variable appointment duration:** Visit reasons have a `duration_minutes` column (default 30). Conflict detection uses actual overlap based on each appointment's visit reason duration — not a fixed ±30 min window. Calendar events render with correct duration.
- **Prescription expiry alerts:** `prescriptions:check-expiry` command (daily at 8 AM) notifies staff about prescriptions expiring within 30 days. Batched notification. Idempotent via `last_expiry_notified_at` timestamp.
- **End-of-day summary:** `clinic:daily-summary` command (daily at 9 PM) sends admin users a database notification with: appointments completed, revenue collected, new orders, pending orders.
- **Billing void audit:** Voiding a billing with posted payments shows the exact amount being voided and creates a full audit log entry (billing number, amounts, payment details, line items) for recoverability.
- **Reorder report:** Reports → Reorder shows product variants at or below their low_stock_threshold, sorted by deficit. Answers "what needs to be reordered?"

---

## Filament UI Conventions

- **`is_active` fields** are labelled "Visibility" in all forms. Toggle states are "Visible" / "Hidden" with helper text explaining the consequence to staff. The database column stays `is_active` — label is UI-only.
- **Status dropdowns** on appointment and order edit forms show only valid next transitions (cycle-guarded via `ALLOWED_TRANSITIONS` in the action class). Staff cannot skip steps or move to an invalid state through the form.
- **Status on create forms** is not shown — the system auto-assigns the initial status (`pending` for appointments, `requested` for orders).
- **Walk-in customer quick-create** is available inline on appointment and order create forms via `->createOptionForm()` on the customer select. Creates a user with name + phone, no email/password.

---

## Completed Specs

| Spec | Status |
|---|---|
| `docs/optical-clinic-journey-mvp-spec.md` | Complete — 29 tasks |
| `docs/post-mvp-polish-spec.md` | Complete — 17 tasks |
| `docs/pre-phase2-bugfix-spec.md` | Complete — 8 tasks |
| `docs/post-mvp-phase2-spec.md` | Complete — 11 tasks |
| `docs/lens-inventory-spec.md` | Complete — 7 tasks |
| `docs/backend-polish-spec.md` | Complete — 11 tasks |
| `docs/billings-rework-spec.md` | Complete — 7 tasks |
| `docs/patients-resource-spec.md` | Complete — 4 tasks |
| `docs/specs/service-billing-spec.md` | Complete — 9 tasks |
| `docs/specs/encounter-billing-refactor-spec.md` | Complete — 12 tasks |
| `docs/specs/unified-billing-flow-spec.md` | Complete — 7 tasks |
| `docs/specs/priority-gaps-spec.md` | In progress — P1–P3 gaps (Phases 1–5 of 6 complete) |
| `docs/specs/defense-hardening-spec.md` | Complete — 7 features (performance indexes, variable duration, expiry alerts, daily summary, void audit, reorder report, docs) |

---

## Running the Project

```bash
vendor/bin/sail up -d                                    # start
vendor/bin/sail artisan migrate:fresh --seed             # reset + seed
vendor/bin/sail artisan db:seed --class=DashboardDemoSeeder  # populate dashboard demo data (idempotent)
vendor/bin/sail artisan appointments:send-reminders      # queue SMS reminders for tomorrow's appointments
vendor/bin/sail artisan prescriptions:check-expiry       # notify staff about expiring prescriptions
vendor/bin/sail artisan clinic:daily-summary             # send daily operations summary to admins
vendor/bin/sail artisan test --compact                   # run all tests
vendor/bin/sail artisan test --compact --filter=Name     # filtered tests
vendor/bin/sail bin pint --dirty --format agent          # format changed PHP
vendor/bin/sail npm run build                            # build frontend assets
vendor/bin/sail artisan route:list --except-vendor       # inspect routes
```

**Important:** `APP_URL` in `.env` must match the URL you use to access the app in the browser (including or excluding port). If FilePond image previews load indefinitely, check that `APP_URL` matches exactly. Run `php artisan storage:link` if the storage symlink is missing.

---

## Production Infrastructure

- **Health check:** `GET /health` returns 200 with DB connectivity status (or 503 if disconnected). Use with uptime monitors.
- **CI:** `.github/workflows/ci.yml` runs Pint lint check + full test suite on push/PR to `main`.
- **Deployment:** See `docs/DEPLOYMENT.md` for full VPS setup (nginx, queue worker, scheduler, SSL, backup) or Laravel Cloud deployment.
- **Queue:** `QUEUE_CONNECTION=database` in production. Queue worker processes `SendSmsJob` (SMS delivery with retries). See deployment docs for Supervisor config.
