# Spec: Defense Hardening — Missing Features & Panel Question Mitigations

## Objective

Address remaining gaps identified in `docs/system-evaluation.md` before thesis defense. These changes:
1. Close functional gaps that panelists will probe (variable duration, void safety, reorder visibility)
2. Add proactive features that demonstrate business value (expiry alerts, end-of-day summary)
3. Improve query performance (database indexes)
4. Update documentation to reflect the current system state (remove prescription uploads from BACKEND_CONTEXT since they're not used)

**User:** Thesis panel evaluators + clinic admin/staff.
**Success:** Each feature is testable, demonstrable, and provides a concrete defense talking point.

---

## Tech Stack

- PHP 8.5, Laravel 13, Filament 5, MySQL, Pest 4, Tailwind 4 + Vite 8
- No new dependencies required

## Commands

```
Test:    vendor/bin/sail artisan test --compact
Filter:  vendor/bin/sail artisan test --compact --filter=Name
Lint:    vendor/bin/sail bin pint --dirty --format agent
Build:   vendor/bin/sail npm run build
Migrate: vendor/bin/sail artisan migrate --no-interaction
```

## Project Structure

```
app/Actions/          → Business logic (single-purpose)
app/Console/Commands/ → Scheduled commands
app/Models/           → Eloquent models
app/Filament/         → Panel resources, pages, widgets
database/migrations/  → Schema changes
tests/Feature/        → Pest feature tests
docs/                 → Living documentation
```

## Code Style

Follow existing conventions:
- Action classes for business logic
- Feature tests with factories + RefreshDatabase
- PHPDoc on non-obvious methods
- Run Pint before committing

## Testing Strategy

- Every feature gets a Pest feature test
- Scheduled commands tested by asserting side effects (records created, notifications sent)
- Schema changes verified by running full suite after migration
- Target: full suite stays green (currently 500 tests)

## Boundaries

- **Always:** Run full test suite before committing; stage only relevant files; update BACKEND_CONTEXT.md when schema/routes change
- **Ask first:** Nothing — all features below are approved
- **Never:** Push to remote; modify prescription upload routes/controller (leave as-is in code, just remove from docs); break existing API contract

---

## Features

### F1: Prescription Expiry Alerts

**What:** A daily scheduled command (`prescriptions:check-expiry`) that finds prescriptions expiring within 30 days and sends a database notification to all staff/admin users. Idempotent — won't re-notify for the same prescription within 30 days.

**Defense talking point:** "The system proactively alerts staff when a patient's prescription is nearing expiry, enabling outreach for rebooking. This reduces missed renewal opportunities."

**Success criteria:**
- Command finds prescriptions where `expires_at` is between today and today+30 days
- Creates one database notification per expiring prescription, grouped per staff user
- Idempotent: running twice on the same day doesn't duplicate notifications (track via a `last_expiry_notified_at` column on prescriptions, or check if notification already exists)
- Test proves the command creates notifications and doesn't duplicate

### F2: End-of-Day Summary

**What:** A scheduled command (`clinic:daily-summary`) that sends a database notification to all admin users with today's stats: appointments completed, revenue collected, orders placed, pending orders.

**Defense talking point:** "The clinic owner sees a daily operations summary without logging in — immediate visibility into business performance."

**Success criteria:**
- Command calculates: appointments completed today, total revenue (payments posted today), new orders today, pending orders count
- Sends a single database notification to each admin user with these stats
- Scheduled at 9 PM Manila time (cron: `0 21 * * *`)
- Test proves correct stats calculation and notification delivery

### F3: Variable Appointment Duration (Panel Question #6)

**What:** Add `duration_minutes` (unsigned int, default 30) to `visit_reasons`. Update `Appointment::conflictsWith()` to use the appointment's visit reason duration instead of a fixed 30-minute window. Update the API and Filament forms.

**Defense talking point:** "Appointment conflict detection is visit-reason-aware. An Eye Exam (30 min) and a Contact Lens Fitting (90 min) correctly detect overlaps based on their actual duration."

**Success criteria:**
- `visit_reasons` gets `duration_minutes` column (default 30)
- Seeder updates existing visit reasons with realistic durations
- `conflictsWith()` uses the duration of the existing appointment, not a hardcoded 30 min
- Filament visit reasons form shows the duration field
- API `POST /appointments` conflict check uses duration
- Existing tests updated/new tests prove variable duration conflict detection
- Calendar drag-reschedule uses duration for conflict check

### F4: Billing Void Confirmation Gate (Panel Question #9)

**What:** When admin voids a billing that has posted payments, require explicit acknowledgment that payments will also be voided. Log the void action with full billing state in audit metadata (amounts, payments voided) so it's recoverable by re-issuing.

**Defense talking point:** "Voiding a paid billing requires explicit confirmation and creates a full audit trail. The voided amounts and payment details are preserved in the audit log — an admin can re-issue based on that record if the void was accidental."

**Success criteria:**
- Void Billing action shows a warning modal when billing has posted payments: "This billing has ₱X in posted payments. Voiding will mark those payments as voided. This action is logged."
- Audit log entry for void includes: billing_number, total_amount, amount_paid, payment details (method, amount per payment), line items
- Test proves the confirmation modal appears (or that the action includes `requiresConfirmation()`)
- Test proves audit metadata captures payment state

### F5: Reorder Report (Panel Question #10)

**What:** Add a Filament report page (`ReorderReport`) under Reports showing product variants at or below their `low_stock_threshold`. This answers "how does the clinic know what to reorder?" without building a full purchase order system.

**Defense talking point:** "The system provides a reorder report showing all products at or below their stock threshold. Staff uses this as their reorder list — it tells them what to order and how low they are."

**Success criteria:**
- New report page at Reports → Reorder
- Shows: product name, variant name, SKU, current stock, threshold, deficit (threshold − stock)
- Sorted by deficit descending (most urgent first)
- Only shows items where `stock_quantity <= low_stock_threshold`
- Test proves the page renders and shows correct items

### F6: Database Performance Indexes

**What:** Add composite indexes on frequently-queried date columns.

**Defense talking point:** "We've added database indexes on the columns used in date-range queries (appointment scheduling, billing reports, payment summaries) to ensure consistent query performance as data grows."

**Success criteria:**
- Migration adds indexes on:
  - `appointments.scheduled_at` (used by conflict check, reminders, calendar, reports)
  - `billings.issued_at` (used by reports date filtering)
  - `payments.created_at` (used by revenue calculations)
  - `sms_notifications.created_at` + `notification_status_id` (compound, for processing command)
  - `prescriptions.expires_at` (used by expiry alerts)
- Full test suite still passes after migration
- No functional change — purely performance

### F7: Documentation Update

**What:** Update `docs/BACKEND_CONTEXT.md` to:
- Remove prescription uploads section from API routes (it's not implemented on Android)
- Remove the prescription uploads conventions section
- Note encryption at rest (already done)
- Note variable appointment duration
- Note end-of-day summary command
- Note prescription expiry alerts command
- Note reorder report page

**Success criteria:**
- BACKEND_CONTEXT.md accurately reflects the current system
- No mention of prescription uploads as a live feature

---

## Open Questions

None — all answered by user's direction:
1. Prescription expiry: staff notification, 30-day window ✓
2. End-of-day summary: database notification ✓
3. Database indexes: include ✓
4. Password policy: skip ✓
5. Prescription upload notification: skip (feature not used) ✓

---

## Implementation Order

F6 (indexes) → F3 (variable duration) → F1 (expiry alerts) → F2 (daily summary) → F4 (void gate) → F5 (reorder report) → F7 (docs)

Rationale: F6 is a no-logic migration that should go first. F3 changes `conflictsWith()` which other features depend on. F1/F2 are independent commands. F4/F5 are independent Filament features. Docs last once everything is stable.

---

## Phase 2: Implementation Plan

### F6 — Database Indexes
Single migration, no logic. Add indexes on: `appointments.scheduled_at`, `billings.issued_at`, `payments.created_at`, `sms_notifications(notification_status_id, created_at)`, `prescriptions.expires_at`. Run full test suite to confirm no breakage.

### F3 — Variable Appointment Duration
1. Add `duration_minutes` (unsigned int, default 30) to `visit_reasons` table.
2. Update `VisitReason` model — add to fillable, add to seeder with realistic values.
3. Rewrite `Appointment::conflictsWith()` — instead of a fixed ±30-min window, query all non-cancelled appointments where their `scheduled_at + duration` overlaps with the proposed `at + at's duration`. The new signature: `conflictsWith(CarbonInterface $at, int $durationMinutes = 30, ?int $ignoreId = null)`.
4. Update the 3 callsites: (a) `StoreAppointmentRequest` — look up the selected visit reason's duration; (b) `AppointmentForm` inline rule — same; (c) `AppointmentCalendarWidget::validateReschedule` — use the appointment's visit reason duration.
5. Update `toCalendarEvent()` to use the visit reason duration for the event end time (currently hardcoded `addHour()`).
6. Add `duration_minutes` to the Visit Reasons Filament form.
7. Update existing tests + add new tests for variable-duration conflicts.

### F1 — Prescription Expiry Alerts
1. Add `last_expiry_notified_at` (nullable timestamp) to `prescriptions` table.
2. Create `prescriptions:check-expiry` command. Queries prescriptions expiring within 30 days where `last_expiry_notified_at` is null or older than 30 days ago. Groups by staff users. Sends one batched database notification per staff user listing expiring prescriptions. Updates `last_expiry_notified_at`.
3. Register in `routes/console.php` at 8 AM daily.
4. Test: create prescriptions with various expiry dates, run command, assert notifications and idempotency.

### F2 — End-of-Day Summary
1. Create `clinic:daily-summary` command. Calculates: appointments completed today, payments posted today (sum), new orders today, pending orders count.
2. Sends one database notification to each admin user with the summary.
3. Register in `routes/console.php` at 9 PM daily.
4. Test: seed data, run command, assert notification content.

### F4 — Billing Void Confirmation Gate
1. Enhance the existing `void_billing` action on `ViewBilling.php`:
   - When billing has posted payments: update modal description to show "₱X in posted payments will be voided"
   - Log the void in audit_logs with full billing state: billing_number, total_amount, amount_paid, payment details array, line items array
2. Test: void a billing with payments, assert audit log metadata includes payment details.

### F5 — Reorder Report
1. Create `ReorderReport` page extending `BaseReport` under Reports nav group.
2. Query: product variants where `stock_quantity <= low_stock_threshold` and threshold > 0.
3. Display as a table: product name, variant name/SKU, current stock, threshold, deficit.
4. Sorted by deficit desc.
5. Test: page renders, shows correct items.

### F7 — Documentation
Update BACKEND_CONTEXT.md: remove prescription upload API routes + conventions section, add new features.

---

## Phase 3: Task Breakdown

### Task 1: Database performance indexes (F6)
- **Acceptance:** Migration adds 5 indexes. Full test suite passes.
- **Verify:** `vendor/bin/sail artisan test --compact`
- **Files:** `database/migrations/2026_06_29_*_add_performance_indexes.php`

### Task 2: Visit reason duration column + seeder (F3 part 1)
- **Acceptance:** `visit_reasons` has `duration_minutes` column. Seeder populates realistic values. VisitReason model updated.
- **Verify:** `vendor/bin/sail artisan migrate --no-interaction && vendor/bin/sail artisan test --compact --filter=VisitReason`
- **Files:** `database/migrations/...`, `app/Models/VisitReason.php`, `database/seeders/VisitReasonSeeder.php`, Visit Reasons Filament form

### Task 3: Rewrite conflictsWith for variable duration (F3 part 2)
- **Acceptance:** `conflictsWith()` accepts a duration parameter. All 3 callsites pass the correct duration. `toCalendarEvent()` uses visit reason duration. Tests prove a 90-min appointment blocks further out than a 30-min one.
- **Verify:** `vendor/bin/sail artisan test --compact --filter=Calendar` + `--filter=Appointment`
- **Files:** `app/Models/Appointment.php`, `app/Http/Requests/Api/StoreAppointmentRequest.php`, `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`, `app/Filament/Resources/Appointments/Widgets/AppointmentCalendarWidget.php`, `tests/Feature/Filament/CalendarInteractivityTest.php`

### Task 4: Prescription expiry alerts command (F1)
- **Acceptance:** Command finds expiring prescriptions, notifies staff, is idempotent. Scheduled at 8 AM.
- **Verify:** `vendor/bin/sail artisan test --compact --filter=ExpiryAlert`
- **Files:** `database/migrations/...`, `app/Console/Commands/CheckPrescriptionExpiryCommand.php`, `routes/console.php`, `tests/Feature/Commands/PrescriptionExpiryAlertTest.php`

### Task 5: End-of-day summary command (F2)
- **Acceptance:** Command calculates daily stats, sends notification to admins. Scheduled at 9 PM.
- **Verify:** `vendor/bin/sail artisan test --compact --filter=DailySummary`
- **Files:** `app/Console/Commands/SendDailySummaryCommand.php`, `routes/console.php`, `tests/Feature/Commands/DailySummaryTest.php`

### Task 6: Billing void confirmation + audit (F4)
- **Acceptance:** Void modal shows payment amount warning. Audit log captures full billing state on void.
- **Verify:** `vendor/bin/sail artisan test --compact --filter=Billing`
- **Files:** `app/Filament/Resources/Billings/Pages/ViewBilling.php`, `tests/Feature/Filament/BillingResourceTest.php`

### Task 7: Reorder report page (F5)
- **Acceptance:** Page renders under Reports, shows items at/below threshold sorted by deficit.
- **Verify:** `vendor/bin/sail artisan test --compact --filter=Report`
- **Files:** `app/Filament/Pages/Reports/ReorderReport.php`, `tests/Feature/Filament/ReportsTest.php`

### Task 8: Documentation update (F7)
- **Acceptance:** BACKEND_CONTEXT.md is accurate — no prescription upload routes, new features documented.
- **Verify:** Manual review
- **Files:** `docs/BACKEND_CONTEXT.md`

### Task 9: Final full suite + commit
- **Acceptance:** 500+ tests pass. All files committed.
- **Verify:** `vendor/bin/sail artisan test --compact`
- **Files:** All modified files from Tasks 1–8
