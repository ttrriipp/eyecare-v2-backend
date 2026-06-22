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
| `users` | email + password nullable for walk-in customers |
| `appointments` | customer_id, staff_id (nullable), visit_reason_id, appointment_status_id |
| `prescriptions` | customer_id, appointment_id (nullable), OD/OS/PD fields |
| `products` | brand_id, category_id (nullable), lens_type_id (nullable FK — only for type `lens`), name, slug, is_active, product_type (frame/lens/contact_lens/accessory), images (nullable JSON). No price/dimensions (on variants). |
| `product_variants` | price, compare_at_price, cost_price, attributes (nullable JSON), stock_quantity, low_stock_threshold, ar_eligible, ar_asset_reference, images (nullable JSON) |
| `orders` | order_number (ORD-YYYY-XXXXXX), customer_id, is_non_prescription, discount_type_id, discount_amount, total_amount |
| `order_items` | price snapshot — product_name, variant_name, unit_price, lens_type_id (nullable FK), lens_type_name (nullable), lens_type_price (nullable), lens_product_variant_id (nullable — specific lens assigned by staff), subtotal. |
| `billings` | billing_number (BIL-YYYY-XXXXXX), order_id (1:1), total_amount, amount_paid, balance_due, issued_at |
| `payments` | billing_id, payment_method_id, amount, payment_status_id |
| `conversations` | customer_id — one per customer |
| `messages` | conversation_id, sender_id, body |
| `message_context_links` | polymorphic — links message to Appointment, Order, or Product |
| `message_attachments` | private storage, images + PDFs only |
| `feedback` | customer_id, appointment_id or order_id (one required), rating (1–5), staff_reply |
| `audit_logs` | actor_id, subject_type, subject_id, action, metadata (JSON) |
| `inventory_movements` | product_variant_id, order_id, inventory_movement_type_id, quantity_change, previous_stock, new_stock, created_by (FK to users), notes |
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
- **`product_type`** controls form behavior and API visibility. Fixed values: `frame`, `lens`, `contact_lens`, `accessory`. `frame` shows AR fields; all types show `attributes`. Mobile API returns only `frame` products. **Disabled on edit** — set at creation time only.
- **`lens_type_id`** — nullable FK on products, only used for `product_type = 'lens'`. Links a lens product to its lens type category (progressive, single_vision, etc.). The form shows this field only when type is `lens`.
- **`attributes`** — replaces old `dimensions`. Generic key-value JSON on variants, visible for ALL product types. Frame: `{"eye_size":52,"bridge":18,"temple":140}`. Contact lens: `{"power":"-1.25","base_curve":"8.4","diameter":"14.0"}`. Accessory/Lens: use as needed.

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

---

## Filament Panel

URL: `/admin` — accessible to `staff` and `admin` roles only.

**Navigation groups (in order):**
- *(ungrouped)* — Appointments, Prescriptions
- Orders & Billing — Orders, Billings
- Products & Inventory — Products, Inventory History
- Communication — Conversations, Feedback
- Administration — Users, Audit Logs
- Settings — Categories, Brands, Lens Types, Visit Reasons

**Resources (operational):**
- Appointments — guarded status dropdown on edit form; staff assignment
- Orders — KPI stats (reactive to active tab) + status tabs on list. Table with group-by-date, toggleable columns, date range filters, row actions (advance/cancel/edit in ⋮ menu). Create: 2-step wizard (Order Details → Order Items table repeater). Edit: sidebar (dates), inline ToggleButtons (cycle-guarded, sequential), discount selector, RichEditor notes. Full-width Order Items section (4-col grid repeater, inline lens assignment). Live Order Summary (subtotal/discount/total). View Billing header action. Soft delete with restore.
- Products — 3-col sidebar layout. Product type at top of Product Details (disabled on edit). On create: inline Variants Repeater (min 1). On edit: Variants managed via VariantsRelationManager table (image, name, SKU, price, visible ✓/✗, AR ✓/✗ (frames only), qty) with Adjust Stock (movement type selector), Adjust Price row actions. Product type + visibility filters on list. Products table shows: thumbnail, name, brand, category, type badge, visible ✓/✗, total qty.
- Prescriptions
- Billings — KPI stats (total, unpaid, collected) + status tabs. Table with badges, date range filters, row actions (View/View Order/Record Payment). View page: 3-col Billing Details infolist + Payments section with Record Payment and void per row. Not deletable — voided automatically on order cancellation. No create page.
- Conversations — chat-style page
- Feedback
- Inventory History — read-only movement log. Columns: Date, Product, Variant, Type (badge), Change (+/-), Before, After, By. Type/date range filters. View modal shows full details including notes and order link.
- Audit Logs (read-only)
- User Management (admin only)

**Resources (lookup / settings — grouped under "Settings" nav):**
- Categories, Brands (CRUD), Lens Types (with price), Visit Reasons

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

GET    /products                Active FRAME products only, paginated (default 15, `?per_page=N`). Non-frame types return 404.
GET    /products/{id}           Product detail with variants + AR metadata (404 for non-frame products)

GET    /prescriptions           Customer's own prescription history
GET    /prescriptions/{id}

POST   /orders                  Submit order request (status locked to requested). `items[].lens_type_id` is nullable — omit for accessories/contact lenses.
GET    /orders                  Customer's own orders, paginated (default 15, `?per_page=N`)
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

## API Response Examples (for Android)

**POST /register** and **POST /login** → returns:
```json
{ "token": "1|abc123...", "user": { "id": 3, "name": "...", "email": "...", "role": "customer" } }
```

**GET /user:**
```json
{ "data": { "id": 3, "name": "Demo Customer", "email": "customer@eyecare.test", "role": "customer" } }
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
    "category": "frames",
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
    "visit_reason": "eye_exam",
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
      "subtotal": "5600.00"
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
| `RecordInventoryMovement` | `app/Actions/Inventory/` | Creates inventory_movement record (with previous_stock, new_stock, created_by), updates variant stock_quantity, fires low stock notification if stock ≤ threshold after deduction |
| `CreateAuditLog` | `app/Actions/Audit/` | Persists audit entry (actor, subject, action, metadata) |

---

## Important Conventions

- **Walk-in customers:** `users.email` and `users.password` are nullable. Walk-in records have only name + phone. They cannot log in to the mobile app.
- **Order item totals:** `subtotal` = (`unit_price` + `lens_type_price`) × `quantity`. `lens_type_id` and `lens_type_price` are nullable (no lens = frame-only price). Order `subtotal` = sum of all item subtotals. `total_amount` = `subtotal` − `discount_amount`. Both recalculate when staff assigns a lens product variant.
- **Insufficient stock:** If a variant has 0 stock when an order is confirmed, `UpdateOrderStatus` throws a `ValidationException` (not a crash). The order status remains `requested`.
- **Lens inventory:** Lens products (type `lens`) are linked to a `lens_type` via `products.lens_type_id`. Staff assigns a specific lens product variant per order item via the ItemsRelationManager **on the order edit page while the order is still `requested`**. "Assign Lens" action is hidden once the order is confirmed or beyond. Confirmation is gated: if any order item has `lens_type_id` set but `lens_product_variant_id` is null, `UpdateOrderStatus` throws a `ValidationException` — staff must assign all lenses before confirming. On confirmation, both frame variant AND lens product variant stock deduct. On cancellation (from confirmed), both restore. Mobile API returns only `frame` products — all other types are admin-only.
- **Inventory movements:** All stock changes go through `RecordInventoryMovement`. Types: `restock`, `manual_adjustment`, `order_commitment`, `order_reversal`. Each movement records `previous_stock`, `new_stock`, and `created_by` (the user who triggered it, or null for system actions). Staff uses the "Adjust Stock" action on the Variants table (restock = add units, manual_adjustment = remove units). `stock_quantity` is read-only on the variant edit form — changes only through Adjust Stock. Full history viewable in Inventory History resource (read-only, with view modal per row).
- **Billing:** One billing per order. **Auto-generated when the order is confirmed** — status starts as `issued` with `issued_at` set. Status flow: `issued → partially_paid → paid` (+ `voided`). Billing is auto-voided when the order is cancelled. Staff records payments from the ViewBilling page's Payments section (50% downpayment hint on first payment). Payments are voidable (not deletable) — voided payments excluded from balance. Billings are not deletable through the UI.
- **Conversations:** One persistent conversation per customer. Context links (Appointment, Order, Product) attach per-message via `message_context_links` polymorphic table.
- **AR assets:** `ar_asset_reference` stores the storage path to the uploaded 3D model file. Staff uploads `.glb`, `.gltf`, or transparent `.png` files via FileUpload on the variant edit form (only visible on frame variants with `ar_eligible` enabled). Files stored at `storage/app/public/ar-assets/`. No biometric data, face geometry, or facial landmarks are stored.
- **SMS:** Appointment events only (confirmation, reschedule, cancellation). Records stored in `sms_notifications`. Real sending via Semaphore behind config flag — faked in tests.

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

**Important:** `APP_URL` in `.env` must match the URL you use to access the app in the browser (including or excluding port). If FilePond image previews load indefinitely, check that `APP_URL` matches exactly. Run `php artisan storage:link` if the storage symlink is missing.
