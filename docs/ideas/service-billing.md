# Service Billing

## Problem Statement

How might we extend the billing system to support charging for clinical services (eye exams, fittings, repairs) without conflicting with the existing order-based billing workflow?

## Recommended Direction

Introduce a **fee schedule** (`services` table) and a **service record** (`service_records` table) that captures when a service was performed for a patient. Make the `billings` table polymorphic (`billable_type` + `billable_id`) so a billing can belong to either an `Order` or a `ServiceRecord`. Staff manually triggers service billing — no auto-generation on appointment completion.

This reuses the entire existing payment infrastructure (payments, balance tracking, statuses, RecordPayment, RecalculateBillingBalance) without modification. The billing model stays identical; only its source changes.

**Visit reasons remain separate.** They describe scheduling intent (customer-facing, no price). Services describe billable deliverables (staff-facing, with price). They overlap in naming but serve different systems with different cardinality (one visit reason per appointment vs. potentially multiple services billed per visit).

## Key Assumptions to Validate

- [ ] One billing per service record is sufficient for MVP — staff won't need to group multiple services on one invoice immediately. (Validate: ask staff after 2–4 weeks of usage.)
- [ ] SC/PWD discounts apply to services the same way they apply to orders — same `discount_type_id` pattern. (Validate: confirm with clinic owner re: Philippine regulations.)
- [ ] Staff will always manually trigger service billing — no automation needed for MVP. (Validate: observe if staff forgets to bill after appointments.)

## MVP Scope

### New Tables

| Table | Columns |
|---|---|
| `services` | id, name, description (nullable), price (decimal 10,2), is_active (default true), timestamps |
| `service_records` | id, customer_id (FK users), service_id (FK services), appointment_id (nullable FK), staff_id (FK users), amount (decimal 10,2 — defaults from service price, overridable), discount_type_id (nullable FK), discount_amount (decimal 10,2, default 0), total_amount (decimal 10,2), notes (nullable text), performed_at (datetime), timestamps, soft_deletes |

### Modified Tables

| Table | Change |
|---|---|
| `billings` | Drop `order_id` FK. Add `billable_type` (varchar) + `billable_id` (bigint unsigned) polymorphic columns + index. |

### Models

- `Service` — simple CRUD model (name, price, is_active)
- `ServiceRecord` — belongs to customer, service, appointment (optional), staff. Has one billing (via polymorphic inverse). Applies discount same pattern as orders.

### Actions

- `GenerateBillingForService` — mirrors `GenerateBillingForOrder`. Takes a `ServiceRecord`, creates a billing with `billable_type = ServiceRecord`.
- `ApplyServiceDiscount` — calculates discount on service amount, sets total_amount.

### Filament

- `ServiceResource` — Settings group CRUD (name, description, price, visibility toggle). Same pattern as Brands/Categories.
- "Bill Service" action — available on:
  - Appointment edit page (pre-fills customer + appointment)
  - Patient resource page (for walk-in services)
- `BillingsResource` — add "Source" column showing "Order #ORD-..." or "Service: Eye Exam". Filter by source type.
- Modify `GenerateBillingForOrder` + all billing references to use morphTo instead of `order_id`.

### API (future, not MVP)

- Service billings visible to customers via existing `GET /billing/{id}` once the polymorphic change is in place. No new endpoints needed for MVP.

## Not Doing (and Why)

- **Grouped service billing (multiple services → one invoice)** — YAGNI for MVP. One billing per service is consistent with order pattern. Clear upgrade path exists (add `billing_id` FK on service_records later).
- **Auto-billing on appointment completion** — Too rigid. Follow-ups are often free. Staff needs discretion.
- **Merging visit_reasons into services** — Different purpose (scheduling vs billing), different audience (customer vs staff), different cardinality (1 per appointment vs N per visit).
- **Time-based or package billing** — Fee schedule with flat prices covers 95% of optical clinic needs. Packages can be added as future service entries.
- **Service status flow** — Services don't need a lifecycle like orders. They're either "performed and billed" or not yet billed. No intermediate states.
- **Android app service booking/payment** — Mobile app stays focused on appointments and orders. Service billing is a staff-only workflow for now.

## Open Questions

- Does the clinic want a "suggested service" link on visit reasons (e.g., "eye_exam" visit reason auto-suggests "Comprehensive Eye Exam ₱800" service)? Nice-to-have convenience, not blocking.
- Should the billing PDF/print view (if ever built) combine order + service billings for a single visit? Deferred until reporting needs are clearer.
