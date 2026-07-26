# POCMS Backend — Context Document

> **Living document.** Update this when schema, routes, roles, status values, or architectural decisions change.
>
> **Stabilization warning (2026-07-26):** This document still describes the
> superseded customer/Order/Billing workflow in several sections and is not a
> release-ready account of the current target system. The measured baseline is
> 973 tests with 15 failures and 65 errors. Use the approved clinic workflow
> stabilization specification and its Task 1 manifest for implementation
> decisions until Task 26 rewrites this document from verified route and schema
> evidence.

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
| Appointments | Create scheduled visits and walk-ins, assign optometrist, reschedule, advance status | — |
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
| `appointment_statuses` | pending, confirmed, arrived, completed, no_show, cancelled |
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
| `users` | email + password nullable for walk-in customers, date_of_birth (nullable), address (nullable), is_optometrist (staff/admin capability flag) |
| `appointments` | appointment_number (APT-YYYY-XXXXXX), customer_id, created_by (nullable booking audit), optometrist_id (nullable clinical provider), visit_reason_id, appointment_status_id, source, scheduled_at, checked_in_at (nullable), checked_in_by (nullable check-in audit), completed_at (nullable), last_reschedule_reason (nullable latest staff-entered customer-visible reason) |
| `prescriptions` | customer_id, appointment_id (nullable), OD/OS/PD fields |
| `products` | brand_id, category_id (nullable FK → product_categories), lens_category_id (nullable FK — only for type `lens`), name, slug, is_active, product_type (`frame`/`lens`/`contact_lens`/`accessory`), images (nullable JSON). No price/dimensions (on variants). |
| `product_variants` | price, compare_at_price, cost_price, attributes (nullable JSON), stock_quantity, low_stock_threshold, target_stock_level (nullable), ar_eligible, ar_asset_reference, images (nullable JSON) |
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

**UI terminology:** Filament's default "Delete"/"Restore" action labels are renamed to **"Archive"/"Restore"** in the panel (Products, Orders, Prescriptions, Appointments, Conversations, Feedback resources — Billings has no row-level delete/restore action, only `Void Billing`) — "Archive" communicates that the record is hidden but recoverable, not permanently destroyed. The underlying mechanism, action names (`delete`/`restore`), and DB behavior are unchanged — this is a label-only change, paired with an `heroicon-o-archive-box` icon (replacing the default trash-can icon) on every renamed "Archive" action so the icon doesn't undercut the softer label. The confirmation modal that opens on click (`modalHeading`/`modalDescription`/`modalSubmitActionLabel`/`modalIcon`) is fully aligned with "Archive" too, not just the trigger button. `TrashedFilter` is labeled "Show Archived" on every table that has one (Products, Orders, Prescriptions, Appointments, Billings, and the Products → Variants relation manager) — its internal dropdown options are also relabeled: "Active only" (default/blank state), "Active and archived" (with trashed), "Archived only" (only trashed) — replacing Filament's defaults ("Without trashed records" / "With trashed records" / "Only trashed records").

`ProductVariant` variants (managed via the Variants relation manager on a product's edit page) also fully support archive/restore: the relation manager's table removes the default soft-delete global scope so archived variants can be found via "Show Archived", and exposes Restore (admin-only, shown only when trashed) alongside Archive (admin-only, hidden when trashed) — matching the documented Products permission table ("Delete/restore" is admin-only even though staff can otherwise fully manage variants). `EditPrescription`'s header also has a matching Restore action (previously only the Prescriptions list table exposed restore) — `PrescriptionResource::getRecordRouteBindingEloquentQuery()` was added with `->withTrashed()` so an archived prescription's edit URL is reachable at all (it previously 404'd, making the record permanently stuck once archived).

---

## Product Data Model

**Products have no price or dimensions** — those live exclusively on variants.

- `products` = catalog entry (brand, category, name, slug, description, is_active, product_type, images)
- `product_variants` = purchasable SKU (price, dimensions, stock, AR data)
- Every product must have at least one variant. An active accessory is catalog-visible only while it has at least one active, non-archived variant.
- Simple products (e.g., lens cleaning kit) get one active variant named "Standard" — dimensions left null
- **Images** follow the optical industry standard — two levels:
  - `products.images` — product-level hero/lifestyle shots (JSON array of paths)
  - `product_variants.images` — variant-specific images per colorway/size (JSON array of paths). Android app should prefer variant images when a variant is selected, fall back to product images if none.
  - No separate images table. API returns both. No `is_primary` or `sort_order` metadata.
- **`product_type`** controls form behavior and API visibility. Fixed values: `frame`, `lens`, `contact_lens`, `accessory`. `frame` shows AR fields. Optical `lens` products use lens categories and staff assignment and are never directly orderable. Staff/admin can directly order frames, contact lenses, and accessories. Android customers can order accessories only; the mobile catalog also exposes browse-only frames having at least one active AR-eligible variant with an asset. Contact lenses and optical lenses are hidden from the mobile catalog. Solutions, cases, cleaning kits, and similar products use `accessory`. **Disabled on edit** — set at creation time only.
- **`lens_category_id`** — nullable FK on products, only used for `product_type = 'lens'`. Links a lens product to its lens category (progressive, single_vision, etc.). The form shows this field only when type is `lens`. Renamed from `lens_type_id` (see Renaming Note below).
- **`attributes`** — replaces old `dimensions`. Generic key-value JSON on variants, visible for ALL product types. Frame: `{"eye_size":52,"bridge":18,"temple":140}`. Contact lens: `{"power":"-1.25","base_curve":"8.4","diameter":"14.0"}`. Accessories use attributes as needed.
- **Lens products are never directly orderable.** They don't appear in any order item product selector (Filament create/edit forms, relation manager, or the mobile API's `POST /orders` validation) — they are staff-assigned to frame order items instead (see Lens Assignment below).

**Renaming note:** "Lens Type" was renamed to "Lens Category" throughout the system (table `lens_types` → `lens_categories`, model `LensType` → `LensCategory`, FK columns `lens_type_id` → `lens_category_id` on both `products` and `order_items`, Filament nav "Lens Types" → "Lens Categories"). Mobile order responses retain both `lens_category_id`/`lens_type_id` aliases for historical orders. Customer `POST /orders` rejects both fields because Android customers can order accessories only.

See `docs/specs/product-data-structure.md` for the product/variant rationale and `docs/specs/product-type-expansion-spec.md` for the current taxonomy decision.

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
pending → confirmed, cancelled
confirmed → arrived, no_show, cancelled
arrived → completed, cancelled
completed → (terminal)
no_show → (terminal)
cancelled → (terminal)
```
Entering `arrived` sets `checked_in_at`; entering `completed` sets `completed_at`. SMS notification records are created for confirmation and cancellation. `appointment_rescheduled` remains an SMS event, not an appointment status.

**Rescheduling** (`RescheduleAppointment`): Rescheduling changes `scheduled_at` through a dedicated action and never changes the appointment to a `rescheduled` status. It validates clinic hours and provider capacity through `ScheduleAppointment`, sends the reschedule SMS/database notification, and audits the old and new times. A staff reschedule preserves `pending` or `confirmed` and requires a customer-readable reason, stored on `appointments.last_reschedule_reason`, included in the reschedule SMS, and recorded in audit metadata. A customer reschedule returns the appointment to `pending` for staff confirmation and clears the latest staff reschedule reason.

**Customer-initiated reschedule (mobile API):** `POST /appointments/{id}/reschedule` lets a customer reschedule their own `pending` or `confirmed` appointment. There is no hard reschedule-count limit. The central scheduler ignores the appointment's existing slot while checking the replacement time, and the result is `pending` so staff can confirm the new schedule.

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
- **Global Search** — topbar search bar (opt-in). Searches: Patients (name/phone/email), Orders (order number/customer name), Appointments (appointment number/customer name/phone), Products (name/variant SKU).

**Navigation groups (in order):**
- *(ungrouped)* — Appointments, Prescriptions, Patients
- Orders & Billing — Orders, Billings
- Products & Inventory — Products, Inventory History
- Communication — Conversations, Feedback, SMS Log (admin only)
- Reports — Reorder, Sales, Orders, Appointments, Feedback (ordered by operational priority). Reorder, Orders, and Appointments reports accessible to staff; Sales and Feedback admin-only. Each report page has an Export CSV button that downloads the current breakdown respecting active date filters. Reorder Report links product names to their edit page.
- Administration — Users, Audit Logs
- Settings — Categories, Brands, Lens Categories, Visit Reasons, Services

**Resources (operational):**
- Appointments — source and optometrist-aware scheduling with simple row actions: Confirm (`pending`), Mark Arrived (`confirmed`), Complete (`arrived`), No Show (`confirmed`), Cancel, and Reschedule. Each appointment has a generated `appointment_number` (`APT-YYYY-XXXXXX`) used as the clinic-facing reference in tables, global search, API responses, SMS messages, and database notifications; staff never types it manually. `optometrist_id` is the clinical provider and lists only staff/admin users marked `is_optometrist`; appointment staff ownership is not manually assigned. Booking/check-in audit is automatic via `created_by` and `checked_in_by`. Staff-created scheduled visits start `confirmed`. The **Add Walk-in** action creates a same-day `walk_in` appointment already `arrived`, records the current staff as creator/check-in user, and the table has a Today's Walk-in Queue filter. The filter shows today's active walk-in queue even if another status tab was previously selected. `scheduled_at` is disabled on edit; the dedicated Reschedule action and calendar drag both use `RescheduleAppointment` and the central scheduler. Staff reschedule actions require a customer-readable reason, stored as the appointment's latest reschedule reason for API/mobile display. Table/calendar show the assigned optometrist. The read-only **Billings relation manager** shows invoices linked through `appointment_id`, but appointment completion does not create a billing automatically. **Bulk actions:** Confirm Selected (staff+admin, pending only), Cancel Selected (admin only, pending/confirmed).
- Orders — KPI stats (reactive to active tab) + status tabs on list. Table with group-by-date, toggleable columns, date range filters, row actions (advance/cancel/edit in ⋮ menu). **Single "New Order" header action** — creates orders starting at `confirmed` directly (no separate "Walk-in Sale" button; that concept was removed). Create: 2-step wizard (Order Details → Order Items table repeater). Variant select shows stock count: `(stock: 5)` or `⚠ [OUT OF STOCK]` and excludes `lens`-type products (lenses are staff-assigned, never ordered directly). No lens fields on the create form. Edit: sidebar (dates), inline ToggleButtons (cycle-guarded, sequential), RichEditor notes — **no discount fields** (discount lives on billing only). Full-width Order Items section (4-col grid repeater) — while status is `confirmed`, frame-type items show a Lens Category selector and, once a category is chosen, an "Assign Lens" selector filtered to matching lens product variants; these fields and the whole repeater lock once the order reaches `processing`. Live Order Summary (subtotal/total only, no discount row). View Billing (document icon, resolves via `Order::resolvedBilling()`) + **Collect Payment** (banknotes icon, records payment inline, pre-fills balance_due, auto-refreshes page after success, hidden when no billing or fully paid). Soft delete with restore. **Bulk action:** Advance Selected (moves each to next status, skips gate-blocked).
- Products — 3-col sidebar layout. Product type at top of Product Details (disabled on edit) — **4 values: Frame, Lens, Contact Lens, Accessory**. Lens type shows a Lens Category selector. On create: inline Variants Repeater (min 1). On edit: Variants managed via VariantsRelationManager table (image, name, SKU, price, visible ✓/✗, AR ✓/✗ (frames only), qty) with Adjust Stock (movement type selector), Adjust Price row actions, and Archive/Restore (admin only — matches the top-level Products permission, staff can otherwise fully manage variants); a "Show Archived" filter reveals archived variants (hidden by default, since the relation manager removes the default soft-delete scope to make them findable at all). Each variant has a required non-negative `low_stock_threshold` and an optional non-negative integer `target_stock_level`; when a target is provided, it must be greater than or equal to the threshold. Product type + visibility filters on list. Products table shows: thumbnail, name, brand, category, type badge (Frame=blue, Lens=green, Contact Lens=yellow, Accessory=gray), visible ✓/✗, total qty.
- Prescriptions — edit form with sections (Patient Info, OD/OS side-by-side, Prescription Details). Prism/Base fields hidden behind a "Show Prism / Base fields" toggle (auto-enables on edit if values exist). Edit page subheading shows "⚠ Expires in X days" (warning) or "⚠ Expired X days ago" (danger) when applicable. Previous Prescription section (collapsed, read-only) shows the patient's most recent prior prescription for comparison — only appears if a prior prescription exists. "Print Prescription" header action downloads A4 PDF. "Print Card" header action downloads wallet-size (85.6mm × 54mm) PDF. Table row action "Copy to New" opens create form pre-filled from that prescription (for repeat visits with minor changes). Header includes Archive/Restore (admin only, swap visibility based on trashed state); archived prescriptions remain reachable at their edit URL so Restore is always clickable.
- Patients — dedicated resource for customer-role users labeled as "Patients". List: Name, Phone, Email, Last Visit (sortable), Orders count (sortable) — both computed in the main query via `withCount('orders')` + an `addSelect()` subquery (no N+1). Toggleable Date of Birth and Address columns (hidden by default). "No visits yet" filter (`whereDoesntHave('appointments')`) surfaces patients who registered but never came in. Row action is "Edit" (opens the same profile/edit page as before — label changed from "View" for clarity, since it's a full edit form, not read-only). Edit: Patient Information section (name, phone, email, date of birth, address) + relation managers for Prescriptions, Appointments, Orders. No header actions — "Bill Service" was removed (see Billing Rework below). DB role stays `customer`, UI label is "Patient". Customers cannot access.
- Billings — KPI stats (total, unpaid, collected) + status tabs. Table shows: billing #, customer name, items summary, total, balance, status — **no OR # column** (removed). Row actions: View, Record Payment (with cash tendered + change for Cash method). **Create page** ("New Billing" header action) — customer (required), appointment (optional, filtered to that customer), notes (optional); creates a billing at `issued` status with zero amounts, for standalone service invoices with no associated order. View page: infolist with Billing Summary section (billing #, status, issued at, patient, amount paid, balance due, **notes**), Linked Records section (clickable links to Order and Appointment), Line Items section (collapsed by default for staff, expanded for admin). Header actions: Record Payment (green, visible when balance > 0), **Actions ⋮** (gray dropdown: Add Service, Apply Discount — admin only, Create Order, Edit Notes — all hidden on voided billings), Void Billing (danger, admin only, separate from the dropdown for visibility), Print / Download (gray dropdown: Download Receipt as A4 PDF, Print Receipt on 80mm thermal — neither shows an OR # anymore, `billing_number` is the sole identifier). Not deletable — voided via Void Billing action or automatically on order cancellation (unless the billing is shared with another active order — see Multi-Order Billings).
- Conversations — chat-style page
- Feedback — read-only. List: customer, rating, comment (toggleable), appointment/order (hidden by default, toggleable), submitted date. Filter by rating. View page: sections layout (Feedback Details + Timestamps sidebar). Staff reply was intentionally removed — staff communicates with patients via Conversations instead.
- Inventory History — read-only movement log. Columns: Date, Product, Variant, Type (badge), Change (+/-), Before, After, By. Type/date range filters. View modal shows full details including notes and order link.
- Audit Logs (read-only)
- User Management (admin only) — scoped to staff/admin accounts only (customers managed via Patients). 3-col sidebar layout: main (Account Details: name, email, phone, password) + sidebar (Role & Access selector, `is_optometrist` capability, Timeline). Table shows the optometrist marker. Optometrist is not a separate role: only staff/admin users can be marked, so an optometrist keeps normal panel access while becoming selectable as a clinical provider. Self-role-edit disabled. Last admin demotion blocked.
- SMS Log (admin only) — read-only log of all SMS notifications. Columns: recipient, event badge, status badge, message, created at. Filters: status, event type. Row action: Retry (failed records only) — resets status to `queued`. **Bulk action:** Retry Selected (admin only, resets failed to queued).

**Resources (lookup / settings — grouped under "Settings" nav):**
- Categories, Brands (CRUD + supplier contact field for ordering reference), Lens Categories (with price + description, renamed from "Lens Types"), Visit Reasons (with duration_minutes for conflict detection), Services (fee schedule with price, description, visibility toggle)
- All settings edit forms use a 2-column layout: main details section (left, 2/3) + Timestamps sidebar (right, 1/3) showing Created at and Last modified.
- Edit pages include relation managers: Brands → Products table, Categories → Products table, Lens Categories → Products table (shows products where `product_type = 'lens'`), Visit Reasons → Appointments table. Services has no relation manager (service_records are audit-only, not directly managed).

**Dashboard widgets (ordered top to bottom):**
1. **Stats Overview** (6 cards, 2 rows of 3) — Today's appointments (sparkline + delta vs yesterday), Waiting today (`walk_in` + `arrived` queue), Revenue this month (sparkline + % vs last month), Pending orders (sparkline), Unpaid billings (₱ outstanding), Low stock variants
2. **Today's Schedule** — table of today's next 5 active (`pending`, `confirmed`, or `arrived`) appointments (time, patient name, phone, visit reason, status badge). Heading includes pickup count: "Today's Schedule · 2 orders ready for pickup" when applicable. Empty state: "No appointments today"
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
POST   /appointments            Book appointment (source mobile_app, status pending, optional optometrist_id; central scheduling rules apply)
GET    /appointments/availability  Availability grid for date + visit_reason_id, optionally optometrist_id and appointment_id for reschedule self-exclusion
GET    /appointments/{id}
GET    /visit-reasons           List all visit reasons (id, name, duration_minutes)
GET    /brands                  List all brands (id, name) — use for product filter UI
GET    /categories              List all product categories (id, name) — use for product filter UI

GET    /products                Active accessories having at least one active, non-archived variant, plus browse-only AR-capable frames; paginated (default 15, `?per_page=N`). Accessory responses contain all active, non-archived variants; frame responses contain active AR-ready variants only. Supports: `?search=`, `?brand={id}`, `?category={id}`, `?min_price=`, `?max_price=`, `?in_stock=true`, `?sort=name|newest|price_asc|price_desc`.
GET    /products/{id}           Accessory or AR-capable frame detail with the same variant filtering as the list endpoint. Returns 404 for accessories without an active, non-archived variant; non-AR frames; contact lenses; optical lenses; legacy general products; and inactive products.

GET    /prescriptions           Customer's own prescription history
GET    /prescriptions/{id}

POST   /orders                  Submit an accessory-only order request (status locked to requested and `appointment_id` always null). Requires `is_non_prescription: true`; `product_variant_id` must reference an active variant of an active accessory. A non-null customer-supplied `appointment_id`, `items[].lens_category_id`, and `items[].lens_type_id` are prohibited.
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
POST   /appointments/{id}/reschedule  Reschedule own pending/confirmed appointment — changes `scheduled_at` through `RescheduleAppointment` and returns status to pending
POST   /orders/{id}/cancel        Cancel own order (requested only)
PATCH  /user                      Update own profile (name, email, phone, address)

--- Staff only (EnsureUserIsStaff middleware) ---
PATCH  /staff/appointments/{id}/status
PATCH  /staff/orders/{id}/status
```

---

## API Response Examples (for Android)

**GET /appointments/availability?date=2026-07-13&visit_reason_id=1:**
```json
{
  "data": {
    "date": "2026-07-13",
    "timezone": "Asia/Manila",
    "interval_minutes": 15,
    "visit_reason_id": 1,
    "visit_duration_minutes": 30,
    "optometrist_id": null,
    "appointment_id": null,
    "day_status": "open",
    "generated_at": "2026-07-13T08:12:04+08:00",
    "slots": [
      {
        "starts_at": "2026-07-13T09:00:00+08:00",
        "ends_at": "2026-07-13T09:30:00+08:00",
        "available": true,
        "reason": null
      },
      {
        "starts_at": "2026-07-13T09:15:00+08:00",
        "ends_at": "2026-07-13T09:45:00+08:00",
        "available": false,
        "reason": "capacity_reached"
      }
    ]
  }
}
```

Availability is customer-authenticated and returns all generated starts that fit before clinic close. Closed days return `day_status = "closed"` and `slots = []`. Same-day elapsed starts remain in the grid with `available = false` and `reason = "elapsed"`. Booking and rescheduling are still authoritative; if a selected slot becomes stale, the mutation returns HTTP 422 with `code = "SLOT_UNAVAILABLE"`, conventional `errors.scheduled_at`, and a safe `availability` refresh context.

**POST /register** and **POST /login** → returns:
```json
{ "token": "1|abc123...", "user": { "id": 3, "name": "...", "email": "...", "phone": "...", "role": "customer" } }
```

**GET /user:**
```json
{ "data": { "id": 3, "name": "Demo Customer", "email": "customer@eyecare.test", "phone": "09171234567", "address": "123 Rizal St, Quezon City", "role": "customer" } }
```

**GET /products** (paginated, accessories + browse-only AR-capable frames):

The `category` field is `string|null`; products without a category return `"category": null`.
Accessory products include all active, non-archived variants and are excluded when no such variant exists. Frame products include only active, non-archived variants where `ar_eligible` is true and `ar_asset_reference` is non-null. `GET /products/{id}` applies the same product eligibility and variant filtering as this list endpoint. Variant-based price, stock, and sorting parameters evaluate only those returned variants.

Both `products.images` and each variant's `images` field are arrays of uploaded relative paths. A catalog product should have at least one uploaded image path at either level. Android should prefer the selected variant's images and fall back to the product-level images.

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

Use `GET /visit-reasons` to get brand and category IDs for filter dropdowns. Brands are returned in the product response as name strings, while categories are returned as a name string or `null` — use a separate `GET /brands` or `GET /categories` endpoint if needed, or store IDs from the product list response.

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
    "last_reschedule_reason": null,
    "source": "mobile_app",
    "assigned_optometrist": { "id": 3, "name": "Dr. Demo" }
  }]
}
```

**PATCH /appointments/{appointment}/contact-note — customer appointment contact notes**

- Auth: Sanctum customer auth required.
- Scope: only the authenticated customer’s own appointment.
- Editable statuses: `pending`, `confirmed`.
- Body:
  ```json
  { "contact_notes": "Please call before arrival." }
  ```
- Validation: `contact_notes` must be present, nullable, string, max 1000 characters.
- Clearing: `null`, empty string, or whitespace-only values clear the note and store `null`.
- Ignored fields: `staff_notes`, status, schedule, visit reason, customer, source, provider assignment, and all unrelated appointment fields are not accepted for mutation by this endpoint.
- Response: existing appointment resource shape, with status and unrelated fields preserved.
- Errors: unauthenticated requests return `401`; another customer’s appointment returns `403`; ineligible statuses and validation failures return `422`.

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
  "items": [
    { "product_variant_id": 5, "quantity": 2 }
  ]
}
```
The selected variant must belong to an active accessory product. `is_non_prescription` must be the JSON boolean `true`. Android should omit `appointment_id`; sending it as JSON `null` is also accepted for transitional compatibility, but any non-null value produces HTTP 422 and customer-created orders always store `appointment_id = null`. Do not send `lens_category_id` or the legacy `lens_type_id` alias; either field produces HTTP 422. Order responses retain nullable `appointment_id` so historical and staff-created appointment-linked orders remain representable.

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
| `EvaluateAppointmentAvailability` | `app/Actions/Appointments/` | Shared availability decision engine for preview, booking, staff create, and reschedule. Uses visit durations, half-open overlap checks, provider capacity, and in-memory peak concurrency across each candidate interval. |
| `ScheduleAppointment` | `app/Actions/Appointments/` | Enforces clinic hours, closed days, visit duration, provider overlap, clinic capacity, and optional customer grid alignment. |
| `ListAvailableAppointmentSlots` | `app/Actions/Appointments/` | Builds 15-minute slot decisions for a date/visit reason and optional optometrist or reschedule appointment context. Loads blockers once per request. |
| `CreateScheduledAppointment` | `app/Actions/Appointments/` | Customer booking path: locks the clinic date, reruns availability, creates a pending mobile appointment, and returns `SLOT_UNAVAILABLE` for stale capacity conflicts. |
| `LockAppointmentScheduleDate` | `app/Actions/Appointments/` | Acquires a database-backed `FOR UPDATE` lock on `appointment_schedule_locks.schedule_date` inside scheduling transactions. |
| `RescheduleAppointment` | `app/Actions/Appointments/` | Locks affected clinic dates, validates a replacement slot, changes `scheduled_at` without a rescheduled status, stores staff-entered latest reschedule reason, sends notifications, and audits old/new times/reason. |
| `CreateWalkInAppointment` | `app/Actions/Appointments/` | Creates today's walk-in as arrived with check-in time, staff owner, and optional optometrist |
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
- **Customer order appointment linking:** Android does not link orders to appointments. Customer `POST /orders` accepts an omitted or null `appointment_id`, rejects any non-null value, and always persists null. The nullable field remains in order responses for historical and staff-created orders; trusted admin workflows and existing appointment-linked records are unaffected by the customer API restriction.
- **`is_non_prescription` field:** Stored as boolean. UI label: "No lens cutting required" — toggle ON = `is_non_prescription = true` = no cutting needed (sunglasses, contact lenses, accessories). Toggle OFF = `is_non_prescription = false` = requires lens cutting (prescription orders). API field name unchanged.
- **Order item totals:** `subtotal` = (`unit_price` + `lens_type_price`) × `quantity`. `lens_category_id` and `lens_type_price` are nullable (no lens = frame-only price). Order `subtotal` = sum of all item subtotals. `total_amount` = `subtotal` (orders have no discount — see below). Both recalculate when staff assigns a lens product variant.
- **No discount on orders:** `orders.discount_type_id`/`discount_amount` were removed. `order.total_amount` always equals `order.subtotal`. Discount is applied only on the billing, via the "Apply Discount" action on ViewBilling (admin only).
- **Billing (encounter model):** A billing is a standalone invoice with line items. When an order reaches `processing`, a billing is auto-generated (or items attach to a pre-linked billing) with product line items. Staff can add service items to any non-voided billing via "Add Service" on the ViewBilling page. Standalone service billings (no order) are created manually from the Billings resource's "New Billing" action. `billing_items.created_at` is insert-only — line items are never edited.
- **Billing auth (API):** `GET /billing/{id}` checks `billing.customer_id === $user->id` directly — no polymorphic lookup.
- **Insufficient stock:** If a variant has 0 stock when an order advances to `processing`, `UpdateOrderStatus` throws a `ValidationException` (not a crash). The order status remains `confirmed`.
- **Lens inventory:** Lens products (type `lens`) are linked to a `lens_category` via `products.lens_category_id`. Staff assigns a lens category, then a specific lens product variant, per order item via the ItemsRelationManager **on the order edit page while the order is `confirmed`**. The "Assign Lens" action is hidden once the order reaches `processing` or beyond. Advancing to `processing` is gated: if any order item has `lens_category_id` set but `lens_product_variant_id` is null, `UpdateOrderStatus` throws a `ValidationException` — staff must assign all lenses first. On advancing to `processing`, both frame variant AND lens product variant stock deduct. On cancellation (from `processing`/`ready_for_pickup`), both restore. Lens products are never directly orderable. Customer `POST /orders` accepts accessories only and prohibits lens category fields; Android can browse AR-capable frames but cannot submit them as order items.
- **Inventory movements:** All stock changes go through `RecordInventoryMovement`. Types: `restock`, `manual_adjustment`, `order_commitment`, `order_reversal`, `damaged`. Each movement records `previous_stock`, `new_stock`, and `created_by` (the user who triggered it, or null for system actions). Staff uses the "Adjust Stock" action on the Variants table (restock = add units, manual_adjustment = remove units) and "Write Off Damaged" action (reduces stock with required reason, records as `damaged` type — shown as red badge in Inventory History). `stock_quantity` is read-only on the variant edit form — changes only through these actions. Restocking above a configured `target_stock_level` is allowed but returns a "Stock exceeds target" warning; no warning is produced when the target is null. Full history viewable in Inventory History resource (read-only, with view modal per row).
- **Product categories:** The DB table is `product_categories` and the PHP class is `ProductCategory`. The FK column on `products` stays `category_id`. The Filament nav label is "Categories".
- **Product type expansion:** `product_type` has 4 values: `frame`, `lens`, `contact_lens`, `accessory`. Frame and optical-lens special behavior is unchanged. Staff/admin order selectors support frames, contact lenses, and accessories. Android ordering is restricted to accessories, while AR-capable frames remain browseable for virtual try-on. Solutions, cases, and cleaning kits use `accessory`. A forward migration blocks deployment if ambiguous legacy `general` rows still exist so they can be explicitly reclassified.
- **Pre-linked billing:** Staff can click "Create Order" on an existing billing to pre-link a new order to it (`orders.billing_id`). When that order reaches `processing`, its items attach to the pre-linked billing instead of generating a new one — the order's customer must match the billing's customer (validated on creation). See Billing Model → Multi-Order Billings for how `billings.order_id` and `Order::resolvedBilling()` interact in this scenario.
- **Services vs Visit Reasons:** `visit_reasons` describe *why a patient is booking* (scheduling vocabulary). `services` describe *what was performed and charged* (billing vocabulary). They are separate tables with different purposes. Visit reason names use proper capitalization: "Eye Exam", "Follow-up", "Prescription Check".
- **Billing grouping by appointment:** An appointment is an encounter grouping point, not an automatic invoice trigger. Completing an appointment does not create a billing. When an order reaches `processing` or staff adds a billable service and `GetOrCreateBilling` receives an `appointment_id`, it reuses any existing non-voided billing for that customer+appointment. Order and service items for the encounter can therefore share one invoice. A walk-in created through the appointment queue has an appointment id and can use the same grouping; sales with no appointment always get a fresh billing.
- **Service records:** `service_records` are created automatically when a service is added to a billing — they are the audit trail of "what was performed, by whom, when." They are not managed directly by staff; the "Add Service" action on ViewBilling creates them as a side effect.
- **Conversations:** One persistent conversation per customer. Context links (Appointment, Order, Product) attach per-message via `message_context_links` polymorphic table. `messages.read_at` tracks when a message was read. `GET /conversations` returns `unread_count` (messages from the other party with null `read_at`). Customers mark messages read via `POST /conversations/{id}/messages/read`.
- **Appointment source:** `source` records how the booking entered the clinic: `mobile_app`, `walk_in`, `phone_call`, `messenger`, or `staff_created`. It supports operations/reporting and does not change lifecycle permissions by itself.
- **Optometrist capability and assignment:** Optometrists remain staff/admin users marked with `users.is_optometrist`; no separate role or provider table is required for this clinic. `appointments.optometrist_id` is the only manual appointment assignment field. Staff/admin handling is recorded automatically through `created_by` and `checked_in_by`, not through a manual operational-owner field.
- **Clinic scheduling rules:** `config/appointments.php` currently defines 09:00-17:00, Sunday closed, and 15-minute slot intervals. These are configuration values, not controller/UI hardcoding. `ScheduleAppointment` requires the full visit-reason duration to fit clinic hours. The same optometrist cannot overlap; different optometrists may work concurrently. Unassigned appointments consume one shared clinic-capacity unit. Capacity is based on marked optometrists, falling back to one, and is evaluated by peak concurrent usage across the proposed interval rather than by a raw count of all appointments touching the range. `cancelled`, `no_show`, and soft-deleted appointments do not consume capacity; pending, confirmed, arrived, and completed records do.
- **Availability API:** `GET /appointments/availability` takes `date`, `visit_reason_id`, optional eligible `optometrist_id`, and optional owned `appointment_id` for reschedule self-exclusion. It returns clinic timezone metadata, visit duration, generation interval, day status, and slot states. Timestamps include an explicit offset. Candidates that overrun closing are not generated.
- **Scheduling concurrency:** Customer booking, customer/staff rescheduling, and staff scheduled creation recheck availability inside database transactions after acquiring `appointment_schedule_locks` rows with `FOR UPDATE`. Reschedules lock old and target clinic dates in sorted order. Availability reads are snapshots only and never reserve a slot.
- **Customer grid alignment:** Android/customer booking and customer rescheduling must use configured 15-minute starts. Staff create/reschedule continues to support exact-minute times.
- **`scheduled_at` is not directly editable on the appointment edit form:** All date changes use `RescheduleAppointment`, which applies the central scheduler and records SMS, database notification, and audit side effects. Staff reschedules require a reason; customer reschedules do not. Rescheduling is an action, not a lifecycle state.
- **Unlimited customer reschedule:** There is no hard cap on reschedules, but only `pending` and `confirmed` appointments are eligible. A customer-initiated reschedule returns to `pending` and clears `last_reschedule_reason`; a staff-initiated reschedule preserves the current eligible status and stores the latest staff-entered reason for customer visibility.
- **AR assets:** `ar_asset_reference` stores the storage path to the uploaded asset file. Staff uploads transparent PNG overlays or 3D models (.glb, .gltf, .obj) via FileUpload on the variant edit form (only visible on frame variants with `ar_eligible` enabled). When `ar_eligible` is true, the asset is required — cannot save without uploading. Max 10MB. Files stored at `storage/app/public/ar-assets/`. No biometric data, face geometry, or facial landmarks are stored. Android accesses via `{APP_URL}/storage/{ar_asset_reference}`. The API returns the relative path (e.g. `ar-assets/abc123.glb`) — Android must prepend the base URL.
- **SMS:** Appointment events (confirmation, reschedule, cancellation) and order events (confirmed, ready_for_pickup, completed, cancelled). Records stored in `sms_notifications` with status `queued`. `sms:process` command dispatches `SendSmsJob` per record to the queue (3 retries, 30s backoff). Actual delivery via `SemaphoreService`. Config: `services.semaphore.enabled` (default false — disabled in dev/tests). Failed sends record `failure_reason`; admin can retry via SMS Log Filament resource.
- **Appointment reminders:** `appointments:send-reminders` creates queued SMS records for tomorrow's confirmed appointments and is idempotent per day. Laravel schedules it daily at 09:00 in the application timezone with `withoutOverlapping()`.
- **Token expiration:** Sanctum tokens expire after 30 days (`config/sanctum.php` → `expiration = 43200`). Expired tokens return 401.
- **Rate limiting:** Login/register: 5 attempts/minute per IP (`throttle:login`). General authenticated API: 60 requests/minute per user (`throttle:60,1`). Exceeding returns 429.
- **Stock visibility:** `GET /products` variant objects include `"in_stock": true|false` (derived from `stock_quantity > 0`). Additive — does not break existing Android responses.
- **Prescription encryption at rest:** All sensitive prescription health data columns (sphere, cylinder, axis, add, prism, base, pd, notes) use Laravel's `encrypted` cast — stored as AES-256 ciphertext in MySQL. Demonstrates DPA compliance. Non-health columns (dates, FKs) remain unencrypted.
- **Variable appointment duration:** Visit reasons have a `duration_minutes` column (default 30). Conflict detection uses actual overlap based on each appointment's visit reason duration — not a fixed ±30 min window. Calendar events render with correct duration.
- **Prescription expiry alerts:** `prescriptions:check-expiry` command (daily at 8 AM) notifies staff about prescriptions expiring within 30 days. Batched notification. Idempotent via `last_expiry_notified_at` timestamp.
- **End-of-day summary:** `clinic:daily-summary` command (daily at 9 PM) sends admin users a database notification with: appointments completed, revenue collected, new orders, pending orders.
- **Billing void audit:** Voiding a billing with posted payments shows the exact amount being voided and creates a full audit log entry (billing number, amounts, payment details, line items) for recoverability.
- **Reorder report:** Reports → Reorder shows product variants at or below their `low_stock_threshold`. For variants with a configured `target_stock_level`, suggested reorder quantity is `max(target_stock_level - stock_quantity, 0)`. An unconfigured nullable target displays "—" for both Target and Suggested reorder rather than treating null as zero. Rows with suggestions are sorted by suggested quantity descending (SKU breaks ties), with unconfigured targets last. The table and CSV include Stock, Threshold, Target, Suggested Reorder, and supplier contact. Answers "what needs to be reordered, how many should be ordered, and who to call?"
- **OR number removed:** Billings no longer have an `or_number` column. `billing_number` (`BIL-YYYY-XXXXXX`) is the sole identifier — shown in the billing table, infolist, and both PDF/thermal receipts. **Open question:** earlier specs documented the OR number as "required for BIR-compliant Official Receipt issuance in the Philippines." This removal was an explicit instruction; confirm with clinic stakeholders whether `billing_number` alone satisfies real-world BIR requirements before relying on this in production, or whether a compliant OR numbering scheme needs to be reintroduced under a different name. See `docs/specs/product-order-billing-rework-spec.md` → Open Questions.
- **Billing notes:** `billings.notes` (nullable text) lets staff annotate an invoice — e.g., "Patient will pay balance next visit," "Insurance claim pending." Editable via the "Edit Notes" action on ViewBilling (hidden on voided billings).
- **Supplier contact on brands:** Brands have a nullable `supplier_contact` field (phone/Viber/name of rep). Shown in the Brand edit form and Reorder Report table/CSV.
- **Thermal receipt:** `GET /thermal/billings/{id}` serves an 80mm-wide HTML page optimised for browser-printing on thermal receipt printers. "Print Receipt" button on ViewBilling opens it in a new tab.
- **PDF routes (web, authenticated):** `GET /pdf/prescriptions/{id}` (A4), `GET /pdf/prescriptions/{id}/card` (85.6mm × 54mm), `GET /pdf/billings/{id}` (A4), `GET /thermal/billings/{id}` (80mm HTML). All gated by `canAccessPanel()`.
- **Cash tendered + change:** The Record Payment modal shows "Cash Tendered" and computed "Change" fields when Cash payment method is selected. Not stored — display only for cashier.

---

## Filament UI Conventions

- **`is_active` fields** are labelled "Visibility" in all forms. Toggle states are "Visible" / "Hidden" with helper text explaining the consequence to staff. The database column stays `is_active` — label is UI-only.
- **Appointment lifecycle actions** expose only the valid next command for the current state (Confirm, Mark Arrived, Complete), plus eligible No Show, Cancel, and Reschedule actions. Staff cannot skip lifecycle steps through the form.
- **Status on create forms** is not shown for appointments. Customer bookings start `pending`, staff-created scheduled visits start `confirmed`, and Add Walk-in starts `arrived`. Orders are the exception: admin/staff-created orders start at `confirmed` directly (see "Single New Order button" above); the status only becomes visible/editable once the order exists, via the ToggleButtons on the edit form.
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
| `docs/specs/product-order-billing-rework-spec.md` | Complete — 20 tasks across 5 phases: Lens Category rename, historical product type simplification (taxonomy superseded by product-type-expansion-spec), order flow rework (admin orders start at confirmed, gates/inventory/billing moved to processing), billing rework (OR# removed, notes added, manual creation, pre-linked billing, Bill Service removed) |
| `docs/specs/product-type-expansion-spec.md` | Implemented — restores distinct frame/lens/contact-lens/accessory types; focused suite green, unrelated appointment-suite failures recorded |
| `docs/specs/appointment-workflow-improvements.md` | Complete — source/provider scheduling, walk-in queue, six-state lifecycle, availability API, reminders |
| `docs/specs/appointment-availability-api-spec.md` | Complete — backend availability grid contract, shared evaluator, transactional booking/reschedule locks, stale-slot errors |

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
