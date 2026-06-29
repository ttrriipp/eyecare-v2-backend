# Spec: Defense-Priority Improvements

## Objective

Close the highest-impact gaps identified in the system evaluation before the capstone defense. These improvements transform the system from "tracks data" to "proactively helps the clinic" — which is the difference between a 7/10 and an 8.5/10 in the panel's eyes.

**Users affected:**
- Customers (mobile app) — stock visibility, upload status notifications
- Admin/Staff (Filament) — reports, appointment reminders in SMS log
- System (security) — rate limiting, token expiration

**Success = each item has a passing test, the defense demo shows proactive system behavior (reminders), and security questions have concrete answers backed by code.**

---

## Tech Stack

- PHP 8.5 / Laravel 13 / Filament 5
- Pest 4 for tests
- MySQL via Sail
- Existing SMS infrastructure (`sms_notifications` + `sms:process` command)
- Existing Sanctum auth

## Commands

```
Build:   vendor/bin/sail npm run build
Test:    vendor/bin/sail artisan test --compact
Filter:  vendor/bin/sail artisan test --compact --filter=TestName
Lint:    vendor/bin/sail bin pint --dirty --format agent
Dev:     vendor/bin/sail up -d
```

## Boundaries

- **Always:** Run tests, format with Pint, update `docs/BACKEND_CONTEXT.md` if routes/schema/conventions change
- **Ask first:** New database tables, new dependencies
- **Never:** Change existing API response shapes (breaks Android), remove existing tests

---

## Features

### 1. Appointment Reminders (SMS 24h Before)

**What:** A scheduled command that sends reminder SMS to patients with confirmed appointments tomorrow.

**Why it matters:** Transforms "we notify on status change" into "we reduce no-shows by 30-40%." Proactive system behavior is the single strongest defense talking point.

**Acceptance Criteria:**
- [ ] New artisan command `appointments:send-reminders`
- [ ] Queries appointments where `status = confirmed` AND `scheduled_at` is tomorrow (between tomorrow 00:00 and tomorrow 23:59)
- [ ] Creates an `sms_notifications` record with `event = 'appointment_reminder'` for each (uses existing SMS infrastructure)
- [ ] Idempotent: does not create duplicate reminders (check if reminder already exists for that appointment today)
- [ ] Schedulable daily (e.g., 6 PM the day before)
- [ ] Test verifies: correct appointments selected, duplicates skipped, SMS records created

**Files:**
- `app/Console/Commands/SendAppointmentRemindersCommand.php` (new)
- `tests/Feature/AppointmentReminderTest.php` (new)

---

### 2. Stock Visibility in Product API

**What:** Add `in_stock` boolean to the variant response so customers see availability before ordering.

**Why it matters:** Closes the "can a customer order something out of stock?" panel question. Currently yes — which is embarrassing to defend.

**Acceptance Criteria:**
- [ ] `GET /products` and `GET /products/{id}` variant objects include `"in_stock": true|false`
- [ ] `in_stock` = `stock_quantity > 0`
- [ ] No other response shape changes (additive only — doesn't break Android)
- [ ] Test verifies: in-stock variant returns `true`, zero-stock variant returns `false`

**Files:**
- `app/Http/Resources/ProductVariantResource.php` (or wherever variants are serialized)
- `tests/Feature/Api/ProductCatalogTest.php` (add assertions)

---

### 3. Customer Notification on Prescription Upload Approval/Rejection

**What:** When admin approves or rejects a prescription upload, create an SMS notification to the customer.

**Why it matters:** Closes the "incomplete feedback loop" critique — the customer uploads but never hears back.

**Acceptance Criteria:**
- [ ] When PrescriptionUpload status changes to `approved`: create `sms_notifications` record with `event = 'prescription_approved'`, message includes "Your prescription has been reviewed and approved."
- [ ] When status changes to `rejected`: create record with `event = 'prescription_rejected'`, message includes "Your prescription upload requires attention. Please contact the clinic."
- [ ] Recipient = customer's phone (from `prescription_upload.customer.phone`)
- [ ] No SMS if customer has no phone
- [ ] Test verifies: approval creates SMS, rejection creates SMS, no-phone skips

**Files:**
- `app/Filament/Resources/PrescriptionUploads/Pages/` (modify approve/reject actions)
- `tests/Feature/Filament/PrescriptionUploadNotificationTest.php` (new)

---

### 4. API Rate Limiting

**What:** Apply Laravel's `throttle` middleware to authentication endpoints and general API.

**Why it matters:** Directly answers the security critique: "brute-force login attempts are unthrottled." One config change, high defense impact.

**Acceptance Criteria:**
- [ ] `POST /login` and `POST /register` throttled to 5 requests/minute per IP
- [ ] General authenticated API endpoints throttled to 60 requests/minute per user
- [ ] 429 response returned when limit exceeded with `Retry-After` header
- [ ] Test verifies: 6th login attempt within a minute returns 429

**Files:**
- `routes/api.php` (add `throttle:` middleware)
- `tests/Feature/Api/RateLimitTest.php` (new)

---

### 5. Sanctum Token Expiration

**What:** Configure Sanctum tokens to expire after 30 days so stolen tokens don't grant indefinite access.

**Why it matters:** Directly answers "No token expiration — stolen token = permanent access." One config line.

**Acceptance Criteria:**
- [ ] `config/sanctum.php` sets `expiration` to `43200` (30 days in minutes)
- [ ] Expired tokens return 401 on any authenticated request
- [ ] Login always issues a fresh token (existing behavior)
- [ ] Test verifies: token created 31 days ago returns 401

**Files:**
- `config/sanctum.php` (edit)
- `tests/Feature/Api/TokenExpirationTest.php` (new)

---

### 6. Reports Module (from priority-gaps-spec Phase 6)

**What:** Four Filament custom pages under "Reports" navigation group with date-range filtered summaries.

**Why it matters:** Answers "does this produce actionable insights?" — the dashboard shows trends, but reports give the owner actual business intelligence (monthly revenue, order completion rates, appointment volume).

**Acceptance Criteria:**
- [ ] **Sales Report** — total billings count, total billed amount, total paid, total outstanding, filtered by issued_at date range
- [ ] **Orders Report** — order count grouped by status, filtered by created_at date range
- [ ] **Appointments Report** — appointment count grouped by status, filtered by scheduled_at date range
- [ ] **Feedback Report** — feedback count, average rating, filtered by created_at date range
- [ ] All pages admin-only (`canAccess` checks `isAdmin()`)
- [ ] Each page has from/until DatePicker filters (default: current month)
- [ ] Data displayed as stats cards (KPIs) + table breakdown by status/category
- [ ] Navigation group: "Reports" (between Communication and Administration)

**Files:**
- `app/Filament/Pages/Reports/SalesReport.php` (new)
- `app/Filament/Pages/Reports/OrdersReport.php` (new)
- `app/Filament/Pages/Reports/AppointmentsReport.php` (new)
- `app/Filament/Pages/Reports/FeedbackReport.php` (new)
- `tests/Feature/Filament/ReportsTest.php` (new)

---

## Implementation Plan

### Order of Execution

```
1. Token expiration     (trivial — one config line)
2. Rate limiting        (trivial — route middleware)
3. Stock visibility     (trivial — one field addition)
4. Appointment reminders (small — one command + test)
5. Upload notifications  (small — modify existing actions)
6. Reports module        (medium — 4 pages, formulaic)
```

Rationale: security fixes first (instant credibility), then proactive features (reminders), then analytical features (reports).

### Architecture Decisions

- **Appointment reminders:** Artisan command (not a queued job) for simplicity — same pattern as `sms:process`. Creates `sms_notifications` records that the existing `sms:process` command dispatches. Separation of concerns.
- **Stock visibility:** Additive API field only. No removal of out-of-stock products from listings (customer might want to see what's available when restocked).
- **Upload notifications:** Fire from the Filament approve/reject actions directly (not an observer) — explicit, testable, mirrors how appointment SMS works.
- **Reports:** Custom Filament pages (not resources) because there's no CRUD — just filtered read-only data. Each page is self-contained with its own query logic. Uses `HasFiltersSchema` trait from ChartWidget docs for the date pickers.
- **Rate limiting:** Laravel's built-in `RateLimiter` via `throttle:` named limiters. No custom middleware needed.

### Risks

| Risk | Mitigation |
|------|------------|
| Token expiration breaks existing Android sessions | 30-day window is generous; Android handles 401 → redirect to login |
| Rate limiting too aggressive for testing | Tests use `withoutMiddleware` or test the limiter directly |
| Reports slow on large datasets | Simple COUNT/SUM with date WHERE clauses; adequate for single-clinic volume |

---

## Success Criteria

1. `vendor/bin/sail artisan test --compact` — all pass (no regressions)
2. `appointments:send-reminders` creates SMS records for tomorrow's confirmed appointments
3. `GET /products` includes `in_stock` on variants
4. Prescription approval/rejection triggers SMS to customer
5. 6th login attempt in a minute returns 429
6. 31-day-old token returns 401
7. Four report pages accessible at `/admin/reports/*` with date filtering
8. `docs/BACKEND_CONTEXT.md` updated with: reminder command, `in_stock` field, rate limiting note, token expiration note, reports nav group

---

## Open Questions

None — all decisions resolved above based on existing codebase patterns.
