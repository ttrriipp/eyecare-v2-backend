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
| Appointments | Create, edit, change status, assign staff | — |
| Orders | Create (starts at `confirmed`), edit while `confirmed`, advance to `processing`, assign lenses, cancel any non-terminal status | Cancel from `processing`/`ready_for_pickup` (triggers inventory reversal) — no staff/admin distinction here beyond the general cancel permission |
| Billings | View, record payment, add service, create standalone billing, create order from billing | Void billing, apply/change discount |
| Products | Create, edit, manage variants, adjust stock | Delete/restore |
| Prescriptions | Create, edit | Delete/restore |
| Users | ❌ Hidden entirely | Full CRUD |
| Audit Logs | ❌ Hidden entirely | Read-only access |
| Settings (categories, brands, lens categories, visit reasons, services) | ❌ Hidden entirely | Full CRUD |

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
| `inventory_movement_types` | restock, manual_adjustment, order_commitment, order_reversal, damaged |
| `payment_methods` | Cash, GCash, Bank Transfer, Credit Card, Check |
| `discount_types` | Senior Citizen (20%), PWD (20%), Loyalty (10%), Custom |

### Business Tables

| Table | Notes |
|---|---|
| `users` | email + password nullable for walk-in customers, date_of_birth (nullable), address (nullable) |
| `appointments` | customer_id, staff_id (nullable), visit_reason_id, appointment_status_id |
| `prescriptions` | customer_id, appointment_id (nullable), OD/OS/PD fields |
| `products` | brand_id, category_id (nullable FK → product_categories), lens_category_id (nullable FK — only for type `lens`), name, slug, is_active, product_type (`frame`/`lens`/`general`), images (nullable JSON). No price/dimensions (on variants). |
| `product_variants` | price, compare_at_price, cost_price, attributes (nullable JSON), stock_quantity, low_stock_threshold, ar_eligible, ar_asset_reference, images (nullable JSON) |
| `product_categories` | name. FK target for products.category_id. PHP class: `ProductCategory`. |
| `lens_categories` | name, description, price (nullable). Renamed from `lens_types`. PHP class: `LensCategory`. |
| `services` | name, description, price, is_active. Fee schedule for billable clinical services. |
| `service_records` | customer_id, service_id, appointment_id (nullable), staff_id, amount, notes, performed_at. Audit log of services performed — created when a service is added to a billing. |
| `orders` | order_number (ORD-YYYY-XXXXXX), customer_id, appointment_id (nullable), prescription_id (nullable), billing_id (nullable FK — pre-links a new order to an existing billing), is_non_prescription, subtotal, total_amount, notes. **No discount fields** — discount lives only on `billings`. |
| `order_items` | price snapshot — product_name, variant_name, unit_price, lens_category_id (nullable FK, renamed from `lens_type_id`), lens_category_name (nullable, renamed from `lens_type_name`), lens_type_price (nullable — column name unchanged), lens_product_variant_id (nullable — specific lens assigned by staff), subtotal. |
| `billings` | billing_number (BIL-YYYY-XXXXXX) — sole identifier, **`or_number` removed**, customer_id, order_id (nullable FK — set to the *first* order that generated this billing; never overwritten by later orders attaching items to the same billing), appointment_id (nullable FK — used for encounter grouping via GetOrCreateBilling), discount_type_id (nullable), discount_amount, subtotal, total_amount, amount_paid, balance_due, **notes** (nullable text — new), issued_at |
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
- **`product_type`** controls form behavior and API visibility. Fixed values: `frame`, `lens`, `general`. Only `frame` and `lens` trigger special system behavior — `general` covers everything else (contact lenses, solutions, cases, accessories, cleaning kits) and is organized purely by `product_categories`. `frame` shows AR fields; all types show `attributes`. Mobile API returns `frame` and `general` products (not `lens`). **Disabled on edit** — set at creation time only.
- **`lens_category_id`** — nullable FK on products, only used for `product_type = 'lens'`. Links a lens product to its lens category (progressive, single_vision, etc.). The form shows this field only when type is `lens`. Renamed from `lens_type_id` (see Renaming Note below).
- **`attributes`** — replaces old `dimensions`. Generic key-value JSON on variants, visible for ALL product types. Frame: `{"eye_size":52,"bridge":18,"temple":140}`. Contact lens (type `general`): `{"power":"-1.25","base_curve":"8.4","diameter":"14.0"}`. Other `general` products: use as needed.
- **Lens products are never directly orderable.** They don't appear in any order item product selector (Filament create/edit forms, relation manager, or the mobile API's `POST /orders` validation) — they are staff-assigned to frame order items instead (see Lens Assignment below).

**Renaming note:** "Lens Type" was renamed to "Lens Category" throughout the system (table `lens_types` → `lens_categories`, model `LensType` → `LensCategory`, FK columns `lens_type_id` → `lens_category_id` on both `products` and `order_items`, Filament nav "Lens Types" → "Lens Categories"). The mobile API accepts and returns both `lens_category_id`/`lens_type_id` (aliases, same value) for backward compatibility with existing Android builds.

See `docs/product-data-structure.md` for full rationale.

---

## Billing Model (Encounter/Invoice)

Billings are standalone invoices — not owned by any single entity. A billing has:
- A `customer_id` (who pays)
- An optional `order_id` (the *first* order that generated this billing, if any — see Multi-Order Billings below)
- One or more `billing_items` — product charges (from order items) or service charges (from service records)
- A discount applied at the billing level (`discount_type_id`, `discount_amount`) — **discount exists only on billings**, never on orders
- A payment trail via `payments`
- An optional `notes` text field for staff annotations

**Three creation paths:**
1. **Order reaches `processing`** → `GenerateBillingForOrder` calls `GetOrCreateBilling(customer, appointment)` (or attaches to a pre-linked billing if `order.billing_id` is set) then `AddOrderItemsToBilling` to populate product line items
2. **Staff creates a standalone billing manually** — via the Billings resource's "New Billing" action (customer, optional appointment, optional notes). Used for services with no associated order.
3. **Staff clicks "Create Order" on an existing billing** — opens the order create form pre-linked to that billing (`billing_id` set, customer pre-filled). When the resulting order reaches `processing`, its items attach to the pre-linked billing instead of generating a new one.

**`GetOrCreateBilling` — encounter grouping:** If `appointment_id` is provided, it finds an existing non-voided billing for that customer+appointment and reuses it. This means an order billing and a service billing for the same appointment automatically merge into one invoice. If `appointment_id` is null (walk-in with no appointment), always creates a new billing.

**Multi-order billings:** A billing can accumulate items from more than one order — either via appointment-based encounter grouping or explicit pre-linking. `billings.order_id` records only the *first* order that generated the billing; it is never overwritten by subsequent orders attaching items to the same billing (`AddOrderItemsToBilling` checks `order_id === null` before writing). Use `Order::resolvedBilling()` — not `Order::billing()` — to look up "the billing this order's items are on," since it checks the pre-linked FK (`billing_id`) first and falls back to the `order_id`-based relationship. Cancelling one order on a shared billing does not void the billing if another active order still references it.

**Billing items** (`billing_items`):
- `type = 'product'` → links to `order_item_id`, description is "Product — Variant"
- `type = 'service'` → links to `service_record_id`, description is the service name

**Adding services to an existing billing:** Staff uses the "Add Service" action on the ViewBilling page. There is no shortcut from Appointment/Patient pages anymore (see "Bill Service" removal below) — staff navigates to the relevant billing (via the Appointment's read-only Billings relation manager, or the Billings list) and adds the service there.

---

## Status Transition Rules

Status changes always go through the relevant action class — never direct model update.

**Appointments** (`UpdateAppointmentStatus`):
```
pending → confirmed, rescheduled, cancelled
confirmed → rescheduled, cancelled, completed
rescheduled → confirmed, rescheduled, cancelled, completed
cancelled → (terminal)
completed → (terminal)
```
SMS notification records created on: confirmed, rescheduled, cancelled.
Rescheduling always goes through the dedicated "Reschedule" action (header action on edit page, row action in list) which accepts a new date — it does not appear in the status toggle buttons.

**Customer-initiated reschedule (mobile API):** `POST /appointments/{id}/reschedule` lets a customer reschedule their own appointment while it is `pending`, `confirmed`, or `rescheduled` (same set of eligible statuses as staff — including reschedule of an already-`rescheduled` appointment, since `rescheduled → rescheduled` is an allowed transition). It calls the same `UpdateAppointmentStatus::handle()` action as the staff-facing Filament "Reschedule" action — same SMS event (`appointment_rescheduled`), same audit log entry, same resulting `rescheduled` status. There is no limit on how many times a customer may reschedule; each reschedule requires staff re-confirmation (the appointment stays in `rescheduled` until staff moves it back to `confirmed`). A database notification is sent to staff/admin on every customer-initiated reschedule (old time → new time). The endpoint uses `Appointment::conflictsWith()` with `$ignoreId` set to the appointment's own id, so the appointment's current slot never blocks its own reschedule.

**Orders** (`UpdateOrderStatus`):
```
requested → confirmed, cancelled
confirmed → processing, cancelled
processing → ready_for_pickup, cancelled
ready_for_pickup → completed, cancelled
completed → (terminal)
cancelled → (terminal)
```
Admin/staff-created orders start at `confirmed` directly (see "Single New Order button" below) — customer-submitted orders (mobile API) still start at `requested` and must be confirmed by staff first.

`requested → confirmed` is a plain status change — no gates, no inventory, no billing. This is the **preparation stage**: staff assigns lens categories and specific lens products to frame items while the order is `confirmed` (see Lens Assignment below). The order is fully editable at this stage (items, quantities, lens assignment).

**Everything commits at `confirmed → processing`:**
- Prescription gate: orders with `is_non_prescription = false` cannot advance to `processing` without a customer prescription on record
- Lens gate: all order items with `lens_category_id` set must have `lens_product_variant_id` assigned before advancing to `processing`
- Inventory deducted (both frame and, if assigned, lens product variant stock)
- Billing auto-generated (or items attached to a pre-linked billing — see Billing Model)
- The order becomes locked — no further item/quantity/lens edits

Inventory restored on `cancelled` only if the order was in `processing` or `ready_for_pickup` (nothing was committed if cancelled from `confirmed`, so nothing needs restoring). The billing is auto-voided on cancellation only if it is not shared with another still-active pre-linked order (see Multi-Order Billings).

**No discount on orders.** Discount lives only on `billings` — applied directly via the "Apply Discount" action on ViewBilling (admin only), never derived from or copied to an order.

**Billing statuses** (`issued → partially_paid → paid` + `voided`):
- `issued` — billing generated, no payments recorded
- `partially_paid` — some payments posted but balance remains
- `paid` — balance_due = 0
- `voided` — billing cancelled (auto-triggered when an order that exclusively owns this billing is cancelled from `processing`/`ready_for_pickup`; a billing shared with another still-active order survives)

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
- Reports — Reorder, Sales, Orders, Appointments, Feedback (ordered by operational priority). Reorder, Orders, and Appointments reports accessible to staff; Sales and Feedback admin-only. Each report page has an Export CSV button that downloads the current breakdown respecting active date filters. Reorder Report links product names to their edit page.
- Administration — Users, Audit Logs
- Settings — Categories, Brands, Lens Categories, Visit Reasons, Services

**Resources (operational):**
- Appointments — guarded status toggle buttons on edit form (cycle-guarded, excludes rescheduled); staff assignment. "Reschedule" is a dedicated header action (and row action in list) that opens a date picker modal — it is not selectable via the status toggle buttons. Read-only **Billings relation manager** shows any invoices linked to this appointment (via `appointment_id`), with a View action to the full billing page — there is no "Bill Service" quick-action anymore (see Billing Rework below). Calendar view (toggle on the list page): events show "Patient Name · Phone — Visit Reason" in the title; clicking an event opens a quick-view modal (phone, status, visit reason, time, duration, assigned staff, notes) with "Open Full Details" button to navigate to edit; drag an event to reschedule (validates status + conflict via `UpdateAppointmentStatus`); click an empty day to create with `scheduled_at` pre-filled. **Bulk actions:** Confirm Selected (staff+admin, pending only), Cancel Selected (admin only, pending/confirmed).
- Orders — KPI stats (reactive to active tab) + status tabs on list. Table with group-by-date, toggleable columns, date range filters, row actions (advance/cancel/edit in ⋮ menu). **Single "New Order" header action** — creates orders starting at `confirmed` directly (no separate "Walk-in Sale" button; that concept was removed). Create: 2-step wizard (Order Details → Order Items table repeater). Variant select shows stock count: `(stock: 5)` or `⚠ [OUT OF STOCK]` and excludes `lens`-type products (lenses are staff-assigned, never ordered directly). No lens fields on the create form. Edit: sidebar (dates), inline ToggleButtons (cycle-guarded, sequential), RichEditor notes — **no discount fields** (discount lives on billing only). Full-width Order Items section (4-col grid repeater) — while status is `confirmed`, frame-type items show a Lens Category selector and, once a category is chosen, an "Assign Lens" selector filtered to matching lens product variants; these fields and the whole repeater lock once the order reaches `processing`. Live Order Summary (subtotal/total only, no discount row). View Billing (document icon, resolves via `Order::resolvedBilling()`) + **Collect Payment** (banknotes icon, records payment inline, pre-fills balance_due, auto-refreshes page after success, hidden when no billing or fully paid). Soft delete with restore. **Bulk action:** Advance Selected (moves each to next status, skips gate-blocked).
- Products — 3-col sidebar layout. Product type at top of Product Details (disabled on edit) — **3 values: Frame, Lens, General** (was 4: frame/lens/contact_lens/accessory; `contact_lens` and `accessory` were consolidated into `general`). Lens type shows a Lens Category selector. On create: inline Variants Repeater (min 1). On edit: Variants managed via VariantsRelationManager table (image, name, SKU, price, visible ✓/✗, AR ✓/✗ (frames only), qty) with Adjust Stock (movement type selector), Adjust Price row actions. Product type + visibility filters on list. Products table shows: thumbnail, name, brand, category, type badge (Frame=blue, Lens=green, General=gray), visible ✓/✗, total qty.
- Prescriptions — edit form with sections (Patient Info, OD/OS side-by-side, Prescription Details). Prism/Base fields hidden behind a "Show Prism / Base fields" toggle (auto-enables on edit if values exist). Edit page subheading shows "⚠ Expires in X days" (warning) or "⚠ Expired X days ago" (danger) when applicable. Previous Prescription section (collapsed, read-only) shows the patient's most recent prior prescription for comparison — only appears if a prior prescription exists. "Print Prescription" header action downloads A4 PDF. "Print Card" header action downloads wallet-size (85.6mm × 54mm) PDF. Table row action "Copy to New" opens create form pre-filled from that prescription (for repeat visits with minor changes).
- Patients — dedicated resource for customer-role users labeled as "Patients". List: Name, Phone, Email, Last Visit, Orders count. Edit: Patient Information section (name, phone, email, date of birth, address) + relation managers for Prescriptions, Appointments, Orders. No header actions — "Bill Service" was removed (see Billing Rework below). DB role stays `customer`, UI label is "Patient". Customers cannot access.
- Billings — KPI stats (total, unpaid, collected) + status tabs. Table shows: billing #, customer name, items summary, total, balance, status — **no OR # column** (removed). Row actions: View, Record Payment (with cash tendered + change for Cash method). **Create page** ("New Billing" header action) — customer (required), appointment (optional, filtered to that customer), notes (optional); creates a billing at `issued` status with zero amounts, for standalone service invoices with no associated order. View page: infolist with Billing Summary section (billing #, status, issued at, patient, amount paid, balance due, **notes**), Linked Records section (clickable links to Order and Appointment), Line Items section (collapsed by default for staff, expanded for admin). Header actions: Record Payment (green, visible when balance > 0), **Actions ⋮** (gray dropdown: Add Service, Apply Discount — admin only, Create Order, Edit Notes — all hidden on voided billings), Void Billing (danger, admin only, separate from the dropdown for visibility), Print / Download (gray dropdown: Download Receipt as A4 PDF, Print Receipt on 80mm thermal — neither shows an OR # anymore, `billing_number` is the sole identifier). Not deletable — voided via Void Billing action or automatically on order cancellation (unless the billing is shared with another active order — see Multi-Order Billings).
- Conversations — chat-style page
- Feedback — read-only. List: customer, rating, comment (toggleable), appointment/order (hidden by default, toggleable), submitted date. Filter by rating. View page: sections layout (Feedback Details + Timestamps sidebar). Staff reply was intentionally removed — staff communicates with patients via Conversations instead.
- Inventory History — read-only movement log. Columns: Date, Product, Variant, Type (badge), Change (+/-), Before, After, By. Type/date range filters. View modal shows full details including notes and order link.
- Audit Logs (read-only)
- User Management (admin only) — scoped to staff/admin accounts only (customers managed via Patients). 3-col sidebar layout: main (Account Details: name, email, phone, password) + sidebar (Role & Access selector + Timeline). Table: name, email, phone, color-coded role badge (admin=red, staff=blue), relative joined date. Role selector restricted to admin/staff. Self-role-edit disabled. Last admin demotion blocked.
- SMS Log (admin only) — read-only log of all SMS notifications. Columns: recipient, event badge, status badge, message, created at. Filters: status, event type. Row action: Retry (failed records only) — resets status to `queued`. **Bulk action:** Retry Selected (admin only, resets failed to queued).

**Resources (lookup / settings — grouped under "Settings" nav):**
- Categories, Brands (CRUD + supplier contact field for ordering reference), Lens Categories (with price + description, renamed from "Lens Types"), Visit Reasons (with duration_minutes for conflict detection), Services (fee schedule with price, description, visibility toggle)
- All settings edit forms use a 2-column layout: main details section (left, 2/3) + Timestamps sidebar (right, 1/3) showing Created at and Last modified.
- Edit pages include relation managers: Brands → Products table, Categories → Products table, Lens Categories → Products table (shows products where `product_type = 'lens'`), Visit Reasons → Appointments table. Services has no relation manager (service_records are audit-only, not directly managed).

**Dashboard widgets (ordered top to bottom):**
1. **Stats Overview** (6 cards, 2 rows of 3) — Today's appointments (sparkline + delta vs yesterday), Waiting today (pending appointments for today — walk-in queue indicator), Revenue this month (sparkline + % vs last month), Pending orders (sparkline), Unpaid billings (₱ outstanding), Low stock variants
2. **Today's Schedule** — table of today's next 5 non-completed appointments (time, patient name, phone, visit reason, status badge). Heading includes pickup count: "Today's Schedule · 2 orders ready for pickup" when applicable. Empty state: "No appointments today"
3. **Appointments Chart** (hero) — 30-day trend line of daily non-cancelled appointments, brand color `#4F8DD7`
4. **Recent Feedback** — last 5 feedback entries table

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
GET    /brands                  List all brands (id, name) — use for product filter UI
GET    /categories              List all product categories (id, name) — use for product filter UI

GET    /products                Active FRAME and GENERAL products (not `lens`), paginated (default 15, `?per_page=N`). Supports: `?search=`, `?brand={id}`, `?category={id}`, `?min_price=`, `?max_price=`, `?in_stock=true`, `?sort=name|newest|price_asc|price_desc`. All params optional — without params behavior unchanged.
GET    /products/{id}           Product detail with variants + AR metadata (404 for `lens`-type products)

GET    /prescriptions           Customer's own prescription history
GET    /prescriptions/{id}

POST   /orders                  Submit order request (status locked to requested). `items[].lens_category_id` is nullable — omit for general products. `items[].lens_type_id` accepted as a backward-compat alias (same meaning). `product_variant_id` must reference a `frame` or `general` product — `lens` products are rejected.
GET    /orders                  Customer's own orders, paginated (default 15, `?per_page=N`)
GET    /orders/{id}

GET    /billing/{id}            Customer billing with line items + payment history (auth: billing.customer_id must match user)
GET    /billing/{id}/pdf        Download billing receipt as PDF (same auth as show)
GET    /conversations           Customer's single persistent conversation (includes unread_count)
GET    /conversations/{id}/messages
POST   /conversations/{id}/messages  (with optional contexts[] and attachments)
POST   /conversations/{id}/messages/read  Mark all messages from other party as read
GET    /attachments/{id}        Download attachment (authorized)

POST   /feedback                Submit feedback (completed appointment or order only)
GET    /feedback
GET    /feedback/{id}

POST   /appointments/{id}/cancel  Cancel own appointment (pending or confirmed only)
POST   /appointments/{id}/reschedule  Reschedule own appointment (pending, confirmed, or rescheduled only) — sets a new `scheduled_at` and transitions status to `rescheduled` via `UpdateAppointmentStatus`, no reschedule limit
POST   /orders/{id}/cancel        Cancel own order (requested only)
PATCH  /user                      Update own profile (name, email, phone, address)

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
{ "data": { "id": 3, "name": "Demo Customer", "email": "customer@eyecare.test", "phone": "09171234567", "address": "123 Rizal St, Quezon City", "role": "customer" } }
```

**GET /products** (paginated, frame + general):
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
      "in_stock": true,
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

**GET /products — Search & Filter params (all optional, combinable):**

| Param | Type | Example | Behaviour |
|---|---|---|---|
| `search` | string | `?search=classic` | Fuzzy match on product name and description |
| `brand` | integer | `?brand=2` | Filter by brand ID |
| `category` | integer | `?category=1` | Filter by category ID |
| `min_price` | numeric | `?min_price=100` | At least one variant priced ≥ value |
| `max_price` | numeric | `?max_price=500` | At least one variant priced ≤ value |
| `in_stock` | boolean | `?in_stock=true` | Only products with stock > 0 |
| `sort` | string | `?sort=price_asc` | `name` (default), `newest`, `price_asc`, `price_desc` |
| `per_page` | integer | `?per_page=20` | Items per page (default 15) |

Use `GET /visit-reasons` to get brand and category IDs for filter dropdowns. Brands and categories are returned in the product response as name strings — use a separate `GET /brands` or `GET /categories` endpoint if needed, or store IDs from the product list response.

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
      "lens_category_id": null,
      "lens_type_id": null,
      "product_id": 2,
      "product_name": "Zeiss Single Vision",
      "variant_name": "1.50 Standard",
      "variant_sku": "ZSV-150-STD",
      "lens_category_name": null,
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
    { "product_variant_id": 3, "lens_category_id": 1, "quantity": 1 },
    { "product_variant_id": 5, "lens_category_id": null, "quantity": 2 }
  ]
}
```
`lens_type_id` is also accepted in place of `lens_category_id` (alias, same meaning) for backward compatibility.

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
| `UpdateOrderStatus` | `app/Actions/Orders/` | Validates transition. `requested → confirmed` is a plain status change. `confirmed → processing` checks prescription gate, checks lens gate, deducts inventory, generates/attaches billing, fires audit log. Cancellation from `processing`/`ready_for_pickup` restores inventory and auto-voids the billing only if it isn't shared with another active order. |
| `GenerateBillingForOrder` | `app/Actions/Billing/` | Called when an order reaches `processing`. Attaches to a pre-linked billing (`order.billing_id`) if set, otherwise calls GetOrCreateBilling then AddOrderItemsToBilling. |
| `GetOrCreateBilling` | `app/Actions/Billing/` | Finds existing non-voided billing for customer+appointment, or creates a new issued one. Null appointment always creates new. |
| `AddOrderItemsToBilling` | `app/Actions/Billing/` | Adds product billing_items from order_items to an existing billing, recalculates totals. Sets `order_id` only if not already set — never overwrites an earlier order's link when a billing is shared across a multi-order encounter. |
| `AddServiceToBilling` | `app/Actions/Billing/` | Creates service_record + service billing_item, recalculates billing subtotal/total |
| `RecalculateBillingBalance` | `app/Actions/Billing/` | Sums posted payments, updates amount_paid/balance_due/status |
| `RecordPayment` | `app/Actions/Billing/` | Creates payment + recalculates balance |
| `RecordInventoryMovement` | `app/Actions/Inventory/` | Creates inventory_movement record (with previous_stock, new_stock, created_by), updates variant stock_quantity, fires low stock notification if stock ≤ threshold after deduction |
| `CreateAuditLog` | `app/Actions/Audit/` | Persists audit entry (actor, subject, action, metadata) |
| `ProcessSmsNotification` | `app/Actions/Sms/` | Takes a queued SmsNotification, calls SemaphoreService, updates status to `sent` or `failed` with reason |

**Removed:** `ApplyDiscount` (order-level discount action — discount now applies directly on billings via the "Apply Discount" ViewBilling action, unchanged). `CreateServiceBilling` (its only callers, the "Bill Service" quick-actions on Appointment/Patient pages, were removed — staff now creates a standalone billing manually and uses "Add Service").

**`Order::resolvedBilling()`** — use this accessor, not `Order::billing()`, when looking up "the billing this order's items are on." It checks the pre-linked FK (`billing_id`) first, falling back to the `order_id`-based `hasOne` relationship. This matters for multi-order encounters where `billings.order_id` only points to the first order that generated the billing.

---

## Important Conventions

- **Walk-in customers:** `users.email` and `users.password` are nullable. Walk-in records have only name + phone. They cannot log in to the mobile app.
- **Customer address:** `users.address` is a single nullable free-text field (no structured street/city/province breakdown) — matches the flat-column convention used for `phone` and `date_of_birth`. Editable by the customer via `PATCH /user` and by staff via the Patients edit form. Not shown on the Patients list table (kept lean) or on staff/admin account forms (`UserForm` — address is customer-only data) or walk-in quick-create forms (name + phone only).
- **Single "New Order" button:** Admin/staff-created orders always start at `confirmed` directly — there is no separate "requested" step and no separate "Walk-in Sale" button (that concept was removed; every admin-created order now behaves the way "Walk-in Sale" used to). Customer-submitted orders via the mobile API still start at `requested` and require staff confirmation.
- **`is_non_prescription` field:** Stored as boolean. UI label: "No lens cutting required" — toggle ON = `is_non_prescription = true` = no cutting needed (sunglasses, general products). Toggle OFF = `is_non_prescription = false` = requires lens cutting (prescription orders). API field name unchanged.
- **Order item totals:** `subtotal` = (`unit_price` + `lens_type_price`) × `quantity`. `lens_category_id` and `lens_type_price` are nullable (no lens = frame-only price). Order `subtotal` = sum of all item subtotals. `total_amount` = `subtotal` (orders have no discount — see below). Both recalculate when staff assigns a lens product variant.
- **No discount on orders:** `orders.discount_type_id`/`discount_amount` were removed. `order.total_amount` always equals `order.subtotal`. Discount is applied only on the billing, via the "Apply Discount" action on ViewBilling (admin only).
- **Billing (encounter model):** A billing is a standalone invoice with line items. When an order reaches `processing`, a billing is auto-generated (or items attach to a pre-linked billing) with product line items. Staff can add service items to any non-voided billing via "Add Service" on the ViewBilling page. Standalone service billings (no order) are created manually from the Billings resource's "New Billing" action. `billing_items.created_at` is insert-only — line items are never edited.
- **Billing auth (API):** `GET /billing/{id}` checks `billing.customer_id === $user->id` directly — no polymorphic lookup.
- **Insufficient stock:** If a variant has 0 stock when an order advances to `processing`, `UpdateOrderStatus` throws a `ValidationException` (not a crash). The order status remains `confirmed`.
- **Lens inventory:** Lens products (type `lens`) are linked to a `lens_category` via `products.lens_category_id`. Staff assigns a lens category, then a specific lens product variant, per order item via the ItemsRelationManager **on the order edit page while the order is `confirmed`**. The "Assign Lens" action is hidden once the order reaches `processing` or beyond. Advancing to `processing` is gated: if any order item has `lens_category_id` set but `lens_product_variant_id` is null, `UpdateOrderStatus` throws a `ValidationException` — staff must assign all lenses first. On advancing to `processing`, both frame variant AND lens product variant stock deduct. On cancellation (from `processing`/`ready_for_pickup`), both restore. Lens products are never directly orderable — they never appear in any order item product selector (Filament or mobile API); the mobile API's `POST /orders` validation explicitly restricts `product_variant_id` to `frame`/`general` products. Mobile API `GET /products` returns `frame` + `general` products — `lens` is admin-only.
- **Inventory movements:** All stock changes go through `RecordInventoryMovement`. Types: `restock`, `manual_adjustment`, `order_commitment`, `order_reversal`, `damaged`. Each movement records `previous_stock`, `new_stock`, and `created_by` (the user who triggered it, or null for system actions). Staff uses the "Adjust Stock" action on the Variants table (restock = add units, manual_adjustment = remove units) and "Write Off Damaged" action (reduces stock with required reason, records as `damaged` type — shown as red badge in Inventory History). `stock_quantity` is read-only on the variant edit form — changes only through these actions. Full history viewable in Inventory History resource (read-only, with view modal per row).
- **Product categories:** The DB table is `product_categories` and the PHP class is `ProductCategory`. The FK column on `products` stays `category_id`. The Filament nav label is "Categories".
- **Product type simplification:** `product_type` has 3 values: `frame`, `lens`, `general` (was 4: `frame`, `lens`, `contact_lens`, `accessory` — the latter two were consolidated into `general`, an irreversible one-time migration). Only `frame` and `lens` trigger special system behavior (AR fields + lens pairing; lens category + staff assignment, respectively). Everything else the clinic sells — contact lenses, solutions, cases, cleaning kits, accessories — is `general` and organized purely through `product_categories`. Adding a new kind of general-purpose product is a category row, not a code change.
- **Pre-linked billing:** Staff can click "Create Order" on an existing billing to pre-link a new order to it (`orders.billing_id`). When that order reaches `processing`, its items attach to the pre-linked billing instead of generating a new one — the order's customer must match the billing's customer (validated on creation). See Billing Model → Multi-Order Billings for how `billings.order_id` and `Order::resolvedBilling()` interact in this scenario.
- **Services vs Visit Reasons:** `visit_reasons` describe *why a patient is booking* (scheduling vocabulary). `services` describe *what was performed and charged* (billing vocabulary). They are separate tables with different purposes. Visit reason names use proper capitalization: "Eye Exam", "Follow-up", "Prescription Check".
- **Billing grouping by appointment:** When `GetOrCreateBilling` is called with an `appointment_id`, it reuses any existing non-voided billing for that appointment. This means an order billing and a service billing for the same appointment share one invoice automatically. Walk-ins without an appointment (`appointment_id = null`) always get a fresh billing.
- **Service records:** `service_records` are created automatically when a service is added to a billing — they are the audit trail of "what was performed, by whom, when." They are not managed directly by staff; the "Add Service" action on ViewBilling creates them as a side effect.
- **Conversations:** One persistent conversation per customer. Context links (Appointment, Order, Product) attach per-message via `message_context_links` polymorphic table. `messages.read_at` tracks when a message was read. `GET /conversations` returns `unread_count` (messages from the other party with null `read_at`). Customers mark messages read via `POST /conversations/{id}/messages/read`.
- **Appointment slot check:** `POST /appointments` (API), `POST /appointments/{id}/reschedule` (API), and the Filament create/reschedule forms all validate that no non-cancelled appointment overlaps with the requested time slot (using each appointment's visit reason `duration_minutes`). Returns 422 with "This time slot is not available" if a conflict exists. Reschedule (both staff edit-page action and the customer API endpoint) excludes the current appointment from the conflict check via `Appointment::conflictsWith()`'s `$ignoreId` parameter.
- **Unlimited customer reschedule:** There is no cap on how many times a customer may reschedule an appointment via `POST /appointments/{id}/reschedule`. Each reschedule sets status to `rescheduled` (requiring staff re-confirmation) and fires a staff database notification with the old and new times — this gives staff visibility to intervene manually (e.g. call a patient who reschedules repeatedly) rather than enforcing a hard limit in code.
- **AR assets:** `ar_asset_reference` stores the storage path to the uploaded asset file. Staff uploads transparent PNG overlays or 3D models (.glb, .gltf, .obj) via FileUpload on the variant edit form (only visible on frame variants with `ar_eligible` enabled). When `ar_eligible` is true, the asset is required — cannot save without uploading. Max 10MB. Files stored at `storage/app/public/ar-assets/`. No biometric data, face geometry, or facial landmarks are stored. Android accesses via `{APP_URL}/storage/{ar_asset_reference}`. The API returns the relative path (e.g. `ar-assets/abc123.glb`) — Android must prepend the base URL.
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
- **Reorder report:** Reports → Reorder shows product variants at or below their low_stock_threshold, sorted by deficit. Includes supplier contact per brand. Answers "what needs to be reordered and who to call?"
- **OR number removed:** Billings no longer have an `or_number` column. `billing_number` (`BIL-YYYY-XXXXXX`) is the sole identifier — shown in the billing table, infolist, and both PDF/thermal receipts. **Open question:** earlier specs documented the OR number as "required for BIR-compliant Official Receipt issuance in the Philippines." This removal was an explicit instruction; confirm with clinic stakeholders whether `billing_number` alone satisfies real-world BIR requirements before relying on this in production, or whether a compliant OR numbering scheme needs to be reintroduced under a different name. See `docs/specs/product-order-billing-rework-spec.md` → Open Questions.
- **Billing notes:** `billings.notes` (nullable text) lets staff annotate an invoice — e.g., "Patient will pay balance next visit," "Insurance claim pending." Editable via the "Edit Notes" action on ViewBilling (hidden on voided billings).
- **Supplier contact on brands:** Brands have a nullable `supplier_contact` field (phone/Viber/name of rep). Shown in the Brand edit form and Reorder Report table/CSV.
- **Thermal receipt:** `GET /thermal/billings/{id}` serves an 80mm-wide HTML page optimised for browser-printing on thermal receipt printers. "Print Receipt" button on ViewBilling opens it in a new tab.
- **PDF routes (web, authenticated):** `GET /pdf/prescriptions/{id}` (A4), `GET /pdf/prescriptions/{id}/card` (85.6mm × 54mm), `GET /pdf/billings/{id}` (A4), `GET /thermal/billings/{id}` (80mm HTML). All gated by `canAccessPanel()`.
- **Cash tendered + change:** The Record Payment modal shows "Cash Tendered" and computed "Change" fields when Cash payment method is selected. Not stored — display only for cashier.

---

## Filament UI Conventions

- **`is_active` fields** are labelled "Visibility" in all forms. Toggle states are "Visible" / "Hidden" with helper text explaining the consequence to staff. The database column stays `is_active` — label is UI-only.
- **Status dropdowns** on appointment and order edit forms show only valid next transitions (cycle-guarded via `ALLOWED_TRANSITIONS` in the action class). Staff cannot skip steps or move to an invalid state through the form.
- **Status on create forms** is not shown for appointments — the system auto-assigns `pending`. Orders are the exception: admin/staff-created orders start at `confirmed` directly (see "Single New Order button" above); the status only becomes visible/editable once the order exists, via the ToggleButtons on the edit form.
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
| `docs/specs/search-bulk-export-spec.md` | Complete — product search/filter API, bulk actions, PDF receipts, CSV export |
| `docs/specs/order-improvements-spec.md` | Complete — label change, walk-in sale, inline payment |
| `docs/specs/product-order-billing-rework-spec.md` | Complete — 20 tasks across 5 phases: Lens Category rename, product type simplification (frame/lens/general), order flow rework (admin orders start at confirmed, gates/inventory/billing moved to processing), billing rework (OR# removed, notes added, manual creation, pre-linked billing, Bill Service removed) |

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
