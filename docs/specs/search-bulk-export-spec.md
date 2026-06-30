# Spec: Product Search API + Bulk Actions + Print/Export

## Objective

Three features that deepen the system's business logic and operational efficiency:

1. **Product Search/Filter API** — Let Android users find products without scrolling the full catalog. Enables brand filtering, price range, stock availability, and text search.
2. **Bulk Actions** — Let staff perform batch operations on multiple records (confirm appointments, advance orders, retry SMS) instead of clicking one-by-one.
3. **Print/Export** — Generate professional PDF documents (billing receipts, prescription printouts) and CSV exports for reports. Demonstrates the system produces tangible clinic outputs.

**Users:** Android customers (search), clinic staff (bulk actions + print), clinic admin (export).

---

## Tech Stack

- PHP 8.5, Laravel 13, Filament 5, Pest 4
- **New dependency:** `barryvdh/laravel-dompdf` (PDF generation)

## Commands

```
Install:  vendor/bin/sail composer require barryvdh/laravel-dompdf
Test:     vendor/bin/sail artisan test --compact
Filter:   vendor/bin/sail artisan test --compact --filter=Name
Lint:     vendor/bin/sail bin pint --dirty --format agent
Build:    vendor/bin/sail npm run build
```

## Project Structure

```
app/Http/Controllers/Api/ProductController.php  → Search/filter additions
app/Filament/Resources/*/Tables/*.php           → Bulk actions
app/Http/Controllers/Api/BillingController.php  → Receipt PDF endpoint
app/Services/PdfService.php                     → PDF generation service
resources/views/pdf/                            → PDF Blade templates
tests/Feature/Api/ProductSearchTest.php         → Search API tests
tests/Feature/Filament/BulkActionsTest.php      → Bulk action tests
tests/Feature/Api/BillingReceiptTest.php        → Receipt PDF test
```

## Code Style

Follow existing conventions. Action classes for complex logic, inline closures for simple bulk operations. PDF templates are plain Blade with inline CSS (dompdf doesn't support Tailwind).

## Testing Strategy

- Every endpoint/action gets a Pest feature test
- Search: test each filter param independently + combined
- Bulk actions: test permission gates + state transitions
- PDF: test returns 200 with correct content-type header
- Target: full suite stays green (currently 509 tests)

## Boundaries

- **Always:** Run tests before commits; stage only relevant files; don't break existing API responses (additive only)
- **Ask first:** Nothing — all approved
- **Never:** Change existing response shapes (only add); modify unrelated code

---

## Feature 1: Product Search/Filter API

### Endpoint: `GET /api/products`

Add query parameters to the existing endpoint (currently returns all active frames paginated):

| Param | Type | Behavior |
|---|---|---|
| `search` | string | Fuzzy match on `products.name` and `products.description` |
| `brand` | int (id) | Filter by `brand_id` |
| `category` | int (id) | Filter by `category_id` |
| `min_price` | numeric | At least one variant has `price >= min_price` |
| `max_price` | numeric | At least one variant has `price <= max_price` |
| `in_stock` | boolean | Only products with at least one variant where `stock_quantity > 0` |
| `sort` | string | `price_asc`, `price_desc`, `name`, `newest` (default: `name`) |

All params are optional. Without params, behavior is unchanged (all active frames, sorted by name).

### Success Criteria

- `GET /products?search=classic` returns only matching products
- `GET /products?brand=1&in_stock=true` combines filters correctly
- `GET /products?min_price=100&max_price=500` filters by variant price
- `GET /products?sort=price_asc` sorts by cheapest variant first
- Without params, response is identical to current (backwards compatible)
- Test covers each filter + combined usage

---

## Feature 2: Bulk Actions

### Appointments Table

| Action | Label | Scope | Gate |
|---|---|---|---|
| Confirm selected | "Confirm" | Pending appointments only | Staff + Admin |
| Cancel selected | "Cancel" | Pending/confirmed only | Admin only |

### Orders Table

| Action | Label | Scope | Gate |
|---|---|---|---|
| Advance selected | "Advance Status" | Non-terminal orders | Staff + Admin |

"Advance" moves each order to its next sequential status (requested→confirmed, confirmed→processing, etc.). Orders that can't advance (missing prescription, missing lens assignment) are skipped with a notification count.

### SMS Log Table

| Action | Label | Scope | Gate |
|---|---|---|---|
| Retry selected | "Retry" | Failed SMS only | Admin only |

### Success Criteria

- Selecting 3 pending appointments + clicking "Confirm" transitions all 3
- Bulk confirm skips non-pending appointments and notifies how many were skipped
- Bulk advance respects prescription/lens gates (skips blocked orders)
- Bulk actions respect role permissions (staff can't bulk cancel confirmed orders)
- SMS retry resets failed records to queued
- Tests cover happy path + permission gate + skip scenarios

---

## Feature 3: Print/Export

### 3A: Billing Receipt PDF

- **Filament:** "Download Receipt" button on ViewBilling page header
- **API:** `GET /api/billing/{id}/pdf` — returns PDF file (same auth as existing billing show)
- **Content:** Clinic header (logo, name, address), billing number, date, patient name, line items table, subtotal/discount/total, payments made, balance due, footer

### 3B: Prescription Printout PDF

- **Filament:** "Print Prescription" button on EditPrescription page header
- **No API endpoint** (staff prints and hands to patient)
- **Content:** Clinic header, patient name, date prescribed, OD/OS table (sphere, cylinder, axis, add, prism, base), PD, notes, prescribing doctor, expiry date

### 3C: Reports CSV Export

- **Filament:** "Export CSV" button on each report page (Sales, Orders, Appointments, Feedback, Reorder)
- **Content:** The same breakdown data currently shown on the report page, as a downloadable CSV file

### Success Criteria

- Billing PDF returns 200 with `Content-Type: application/pdf`
- PDF contains correct billing data (number, items, total)
- Prescription PDF renders OD/OS values correctly (decrypted from encrypted storage)
- CSV export downloads with correct headers and data rows
- API receipt endpoint checks `billing.customer_id === auth user` (same auth as show)
- Tests verify response headers + basic content assertions

---

## Implementation Order

F1 (search) → F2 (bulk actions) → F3 (print/export)

Rationale: F1 is pure query logic with no dependencies. F2 uses existing Filament patterns. F3 requires the new dompdf dependency and Blade templates (most effort).

---

## Open Questions

None — all decisions made above.

---

## Phase 2: Implementation Plan

### Architecture Decisions

- **Product search:** All filtering via Eloquent `when()` clauses on the existing endpoint — no new route, no breaking changes. Price sort uses a correlated subquery `(SELECT MIN(price) FROM product_variants WHERE product_id = products.id)`.
- **Bulk actions:** Filament `BulkAction` with permission closures. Delegate to existing action classes (`UpdateAppointmentStatus`, `UpdateOrderStatus`) to reuse gate logic — no new business logic.
- **PDF:** Single `PdfService` class wrapping dompdf. Blade templates in `resources/views/pdf/` with inline CSS only (dompdf doesn't support Tailwind). API receipt uses the same auth as `GET /billing/{id}`.
- **CSV export:** No extra package — PHP's native `fputcsv` streamed as a download response. Export method on `BaseReport` so all report pages inherit it.

### Dependency Graph

```
Product search/filter       (no deps — pure query layer)
        │
Bulk actions                (deps: existing action classes — already built)
        │
dompdf installed            (required by PdfService)
        │
PdfService + templates      (deps: dompdf)
        │
Billing receipt (Filament)  (deps: PdfService)
Billing receipt (API)       (deps: PdfService)
Prescription PDF (Filament) (deps: PdfService)
        │
CSV export on reports       (no deps — BaseReport extension)
        │
Docs + final suite
```

---

## Phase 3: Tasks

### Phase 1: Search & Filter

#### Task 1: Product search, filter, and sort query params
**Description:** Add all 6 query params to `ProductController::index()`. Without params, behavior is unchanged.

**Acceptance criteria:**
- [ ] `?search=classic` returns products matching name/description
- [ ] `?brand=1`, `?category=2` filter correctly
- [ ] `?min_price=100&max_price=500` filters by variant price range
- [ ] `?in_stock=true` returns only products with stock > 0
- [ ] `?sort=price_asc|price_desc|name|newest` orders correctly
- [ ] No params → response identical to current (backwards compatible)

**Verification:** `vendor/bin/sail artisan test --compact --filter=ProductSearch`

**Dependencies:** None

**Files:**
- `app/Http/Controllers/Api/ProductController.php`
- `tests/Feature/Api/ProductSearchTest.php`

**Size:** M (2 files)

---

### ✅ Checkpoint 1 — After Task 1
- [ ] ProductSearch tests pass
- [ ] Full suite still green

---

### Phase 2: Bulk Actions

#### Task 2: Appointments bulk confirm + bulk cancel
**Description:** Add two bulk actions to the appointments table. Confirm transitions pending→confirmed for each selected record. Cancel (admin only) transitions pending/confirmed→cancelled. Both skip ineligible records and notify the count.

**Acceptance criteria:**
- [ ] Bulk confirm transitions all selected pending appointments
- [ ] Non-pending appointments are skipped, count reported in notification
- [ ] Bulk cancel is hidden for staff (admin only)
- [ ] Existing single-record actions still work

**Verification:** `vendor/bin/sail artisan test --compact --filter=BulkAction`

**Dependencies:** None (uses existing `UpdateAppointmentStatus`)

**Files:**
- `app/Filament/Resources/Appointments/Tables/AppointmentsTable.php`
- `tests/Feature/Filament/BulkActionsTest.php`

**Size:** S (2 files)

#### Task 3: Orders bulk advance action
**Description:** Add bulk advance to orders table. Each selected order advances to its next sequential status. Orders failing the prescription or lens gate are skipped gracefully.

**Acceptance criteria:**
- [ ] Selected `requested` orders advance to `confirmed`
- [ ] Gate-blocked orders (missing prescription/lens) are skipped
- [ ] Notification shows "X advanced, Y skipped (gate blocked)"
- [ ] Terminal orders (completed/cancelled) are skipped silently

**Verification:** Same test file as Task 2

**Dependencies:** None (uses existing `UpdateOrderStatus`)

**Files:**
- `app/Filament/Resources/Orders/Tables/OrdersTable.php`
- `tests/Feature/Filament/BulkActionsTest.php`

**Size:** S (2 files)

#### Task 4: SMS log bulk retry action
**Description:** Add bulk retry to the SMS log table. Resets selected failed records to queued. Admin only.

**Acceptance criteria:**
- [ ] Bulk retry resets `failed` records to `queued`
- [ ] Action hidden for staff
- [ ] Count notification after retry

**Verification:** Same test file

**Dependencies:** None

**Files:**
- `app/Filament/Resources/SmsNotifications/Tables/SmsNotificationsTable.php`
- `tests/Feature/Filament/BulkActionsTest.php`

**Size:** XS (2 files)

---

### ✅ Checkpoint 2 — After Tasks 2–4
- [ ] All bulk action tests pass
- [ ] Full suite green

---

### Phase 3: PDF Generation

#### Task 5: Install dompdf + PdfService + billing receipt template
**Description:** Install `barryvdh/laravel-dompdf`, create `PdfService` with `billingReceipt()` and `prescriptionPrintout()` methods, create the billing receipt Blade template.

**Acceptance criteria:**
- [ ] `composer require barryvdh/laravel-dompdf` runs cleanly
- [ ] `PdfService::billingReceipt(Billing $billing)` returns a PDF stream response
- [ ] Template shows: clinic header, billing number, patient, date, line items table, subtotal/discount/total, payments, balance due
- [ ] Template renders without errors

**Verification:** `vendor/bin/sail artisan test --compact --filter=BillingReceipt`

**Dependencies:** None (but required by Tasks 6–7)

**Files:**
- `composer.json` / `composer.lock`
- `app/Services/PdfService.php`
- `resources/views/pdf/billing-receipt.blade.php`
- `tests/Feature/Api/BillingReceiptTest.php`

**Size:** M (4 files)

#### Task 6: Wire billing receipt to Filament + API
**Description:** Add "Download Receipt" header action on ViewBilling. Add `GET /api/billing/{id}/pdf` route with same auth as existing show endpoint.

**Acceptance criteria:**
- [ ] "Download Receipt" button on billing view page returns PDF
- [ ] `GET /api/billing/{id}/pdf` returns 200 with `Content-Type: application/pdf`
- [ ] Unauthenticated request returns 401
- [ ] Accessing another customer's billing returns 403

**Verification:** Same test file + manual check of Filament button

**Dependencies:** Task 5

**Files:**
- `app/Filament/Resources/Billings/Pages/ViewBilling.php`
- `routes/api.php`
- `app/Http/Controllers/Api/BillingController.php`

**Size:** S (3 files)

#### Task 7: Prescription printout PDF
**Description:** Create prescription Blade template. Add "Print Prescription" header action on EditPrescription page.

**Acceptance criteria:**
- [ ] `PdfService::prescriptionPrintout(Prescription $p)` returns PDF response
- [ ] Template shows: clinic header, patient name, OD/OS table, PD, notes, expiry, prescribing staff
- [ ] Encrypted fields (sphere, cylinder, etc.) are decrypted and readable in the PDF
- [ ] "Print Prescription" button on edit page downloads PDF

**Verification:** `vendor/bin/sail artisan test --compact --filter=PrescriptionPrint`

**Dependencies:** Task 5

**Files:**
- `resources/views/pdf/prescription.blade.php`
- `app/Services/PdfService.php` (add method)
- `app/Filament/Resources/Prescriptions/Pages/EditPrescription.php`
- `tests/Feature/Filament/PrescriptionPrintTest.php`

**Size:** M (4 files)

---

### ✅ Checkpoint 3 — After Tasks 5–7
- [ ] PDF tests pass
- [ ] Full suite green
- [ ] Manual PDF review (check layout)

---

### Phase 4: CSV Export

#### Task 8: CSV export on report pages
**Description:** Add an `exportCsv()` method to `BaseReport`. Add an "Export CSV" button on each of the 5 report pages (Sales, Orders, Appointments, Feedback, Reorder). Uses native `fputcsv` streamed response — no new dependencies.

**Acceptance criteria:**
- [ ] Each report page has "Export CSV" button (admin only)
- [ ] Downloaded CSV has correct headers matching the on-screen breakdown
- [ ] CSV reflects the active date filters (same data as what's visible)
- [ ] Empty report exports a CSV with headers only (not a 500 error)

**Verification:** `vendor/bin/sail artisan test --compact --filter=Report`

**Dependencies:** None

**Files:**
- `app/Filament/Pages/Reports/BaseReport.php`
- `app/Filament/Pages/Reports/SalesReport.php`
- `app/Filament/Pages/Reports/OrdersReport.php`
- `app/Filament/Pages/Reports/AppointmentsReport.php`
- `app/Filament/Pages/Reports/FeedbackReport.php`
- `app/Filament/Pages/Reports/ReorderReport.php`
- `tests/Feature/Filament/ReportsTest.php`

**Size:** L (7 files — but all follow the same pattern)

---

### Phase 5: Documentation

#### Task 9: Update BACKEND_CONTEXT + spec registration
**Acceptance criteria:**
- [ ] BACKEND_CONTEXT documents new search params on `GET /products`
- [ ] BACKEND_CONTEXT documents `GET /api/billing/{id}/pdf`
- [ ] Spec registered in completed specs table
- [ ] Full suite passes (509+ tests)

**Verification:** `vendor/bin/sail artisan test --compact`

**Files:**
- `docs/BACKEND_CONTEXT.md`
- `docs/specs/search-bulk-export-spec.md`

**Size:** XS

---

### ✅ Final Checkpoint
- [ ] 509+ tests pass
- [ ] All acceptance criteria met across Tasks 1–9
- [ ] BACKEND_CONTEXT is accurate

---

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| dompdf can't render complex layouts | Medium | Keep templates simple — tables and basic fonts only, inline CSS |
| Price sort subquery is slow on large catalogs | Low | Single clinic, small catalog — acceptable |
| Bulk advance hits SMS/notification side effects | Medium | Wrap each record in try/catch; log but don't block |
| CSV export memory on large reports | Low | Stream response, don't collect all rows in memory |
