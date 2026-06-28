# Spec: Priority Gaps Implementation (P1–P3)

## Objective

Close the identified workflow gaps from the system checklist that block core user workflows (P1), are expected standard features (P2), and complete the system for academic submission (P3).

**Users affected:**
- Customers (mobile app) — profile editing, cancellation, notifications, messaging UX
- Admin/Staff (Filament) — SMS logs, feedback filters, reports

**Success = all items marked ✅ in `docs/gap-analysis.md` after implementation.**

---

## Tech Stack

- PHP 8.5 / Laravel 13 / Filament 5
- Pest 4 for tests
- MySQL via Sail
- Semaphore SMS API (for actual dispatch)

## Commands

```
Build:   vendor/bin/sail npm run build
Test:    vendor/bin/sail artisan test --compact
Filter:  vendor/bin/sail artisan test --compact --filter=TestName
Lint:    vendor/bin/sail bin pint --dirty --format agent
Dev:     vendor/bin/sail up -d
```

## Project Structure

```
app/Actions/           → Single-purpose workflow classes
app/Http/Controllers/  → API controllers
app/Http/Requests/     → Form request validation
app/Http/Resources/    → API resource transformers
app/Filament/          → Admin panel resources
app/Models/            → Eloquent models
app/Services/          → External service integrations (new: SemaphoreService)
database/migrations/   → Schema changes
tests/Feature/         → Feature tests
tests/Feature/Api/     → API endpoint tests
tests/Feature/Filament/ → Filament resource tests
```

## Code Style

Follow existing project conventions. Action classes for workflows. Form requests for validation. Example:

```php
// Controller — thin, delegates to action
public function update(UpdateProfileRequest $request): JsonResource
{
    $request->user()->update($request->validated());
    return new UserResource($request->user());
}
```

## Testing Strategy

- Every new endpoint gets a feature test
- Every new action gets tested via its endpoint or Filament action
- Use factories + RefreshDatabase
- Run `--filter=Name` during development, full suite before commit

## Boundaries

- **Always:** Run tests, format with Pint, update `BACKEND_CONTEXT.md` if schema/routes change
- **Ask first:** New dependencies, new database tables
- **Never:** Change existing API response shapes without noting in spec (breaks Android)

---

## Features

### P1 — Core UX Blockers

#### 1. Customer Profile Update (`PATCH /user`)

**What:** Customers can update their own name, phone, and email from the mobile app.

**Acceptance Criteria:**
- `PATCH /user` accepts `name`, `phone`, `email` (all optional, at least one required)
- Email uniqueness validated (excluding self)
- Phone stored but no uniqueness constraint (matches current registration behavior)
- Returns updated `UserResource`
- 401 if unauthenticated
- 422 on validation failure

**API:**
```
PATCH /user
Body: { "name": "New Name", "phone": "09171234567", "email": "new@email.com" }
Response: { "data": { "id": 3, "name": "New Name", ... } }
```

---

#### 2. Customer Order Cancellation (`POST /orders/{id}/cancel`)

**What:** Customers can cancel their own orders while status is `requested`.

**Acceptance Criteria:**
- `POST /orders/{id}/cancel` — customer can cancel own order
- Only works when order status is `requested` (422 otherwise)
- Only works for orders where `customer_id` matches authenticated user (403 otherwise)
- Calls `UpdateOrderStatus` action (same as admin cancel)
- Returns updated order
- No stock reversal needed (stock not yet deducted at `requested`)

**API:**
```
POST /orders/{id}/cancel
Response: { "data": { "id": 4, "status": "cancelled", ... } }
```

---

#### 3. SMS Actual Sending (Semaphore Integration)

**What:** Process queued `sms_notifications` records and dispatch via Semaphore SMS API.

**Acceptance Criteria:**
- New `SemaphoreService` class wraps Semaphore HTTP API
- New `ProcessSmsNotification` action: takes an `SmsNotification`, calls Semaphore, updates status to `sent` or `failed`
- New `ProcessPendingSms` artisan command: queries `queued` records, dispatches each
- Config: `services.semaphore.api_key`, `services.semaphore.sender_name`, `services.semaphore.enabled`
- When `enabled = false`, mark as `sent` without calling API (dev/test mode)
- Failed sends record error in `sms_notifications.failure_reason` (new nullable column)
- Schedulable via `schedule:run`
- Tests mock HTTP and verify status transitions

---

### P2 — Expected Features

#### 4. Message Read/Unread Tracking

**What:** Track when messages are read. Return unread count.

**Acceptance Criteria:**
- New `read_at` nullable timestamp column on `messages` table
- Messages sent by the other party start as unread (`read_at = null`)
- `POST /conversations/{id}/messages/read` — marks all messages in conversation as read for the authenticated user
- `GET /conversations` response includes `unread_count` (messages where `sender_id != auth user` and `read_at is null`)
- Messages sent by self are auto-marked as read

---

#### 5. Customer Appointment Cancellation (`POST /appointments/{id}/cancel`)

**What:** Customers can cancel their own appointments while status is `pending` or `confirmed`.

**Acceptance Criteria:**
- `POST /appointments/{id}/cancel` — customer can cancel own appointment
- Only works for `pending` or `confirmed` status (422 otherwise)
- Only works when `customer_id` matches authenticated user (403 otherwise)
- Calls `UpdateAppointmentStatus` action with `cancelled`
- SMS record created (same as admin cancel)
- Returns updated appointment

---

#### 6. SMS for Order Status Changes

**What:** Create SMS notification records when order status changes to `confirmed`, `ready_for_pickup`, `completed`, or `cancelled`.

**Acceptance Criteria:**
- `UpdateOrderStatus` creates `sms_notifications` record for: confirmed, ready_for_pickup, completed, cancelled
- Message text describes the status change with order number
- Uses same pattern as appointment SMS (queued → processed by `ProcessPendingSms`)

---

#### 7. Feedback Date Filter

**What:** Add date range filter to the feedback list in Filament.

**Acceptance Criteria:**
- `Filter::make('created_at')` with `DatePicker` from/until on FeedbackTable
- Filters feedback by submission date range

---

### P3 — Important for Completeness

#### 8. Customer Prescription Upload

**What:** Customers can upload a prescription image/PDF for admin review.

**Acceptance Criteria:**
- New `prescription_uploads` table: `id`, `customer_id`, `file_path`, `status` (pending/approved/rejected), `admin_notes`, `prescription_id` (nullable FK, set on approval), `created_at`, `updated_at`
- `POST /prescriptions/upload` — customer uploads file (image or PDF, max 5MB)
- `GET /prescriptions/uploads` — customer views own upload history with status
- New Filament resource: PrescriptionUploads (admin only) — list with status filter, view page, approve/reject actions
- Approve action: admin fills prescription form → creates Prescription record → links `prescription_id` on upload → status `approved`
- Reject action: admin enters notes → status `rejected`
- File stored privately at `prescription-uploads/`

---

#### 9. Reports Module

**What:** Dedicated reports pages in Filament with date-range filtered summaries.

**Acceptance Criteria:**
- New Filament custom pages (not resources) under "Reports" navigation group (admin only):
  - **Sales Report** — total billings, paid amount, unpaid balance, by date range
  - **Orders Report** — order count by status, by date range
  - **Appointments Report** — appointment count by status, by date range
  - **Feedback Report** — average rating, count, by date range
- Each page has date range filter (from/until DatePickers)
- Data displayed as stats cards (KPIs) + simple table breakdown
- No charts required (keep simple)

---

#### 10. Failed SMS Admin Log + Retry

**What:** Staff/admin can view SMS history and retry failed sends.

**Acceptance Criteria:**
- New Filament resource: SmsNotifications (admin only, read-only)
- Columns: recipient, event, status badge, message (truncated), sent_at, failure_reason
- Filters: status (queued/sent/failed), event type
- Row action: "Retry" on failed records → resets status to `queued`
- No create/edit/delete

---

#### 11. Appointment Slot Availability Check

**What:** Prevent double-booking at the same time.

**Acceptance Criteria:**
- `POST /appointments` validates: no existing non-cancelled appointment within ±30 minutes of `scheduled_at` for the same `staff_id` (if staff is assigned) or globally if no staff preference
- 422 with message "This time slot is not available" if conflict exists
- Admin-created appointments in Filament get same validation via form rule
- Existing appointments (reschedule) excluded from conflict check

---

## Resolved Questions

1. **Semaphore** — stub with `enabled = false` by default. Config keys in `services.semaphore`. Actual API key provided at deployment time.
2. **Prescription upload** — rejected uploads kept as history (no customer delete). Admin rejection notes provide context for re-upload.
3. **Reports** — four report pages (sales, orders, appointments, feedback) are sufficient. No additional metrics.
4. **Appointment slots** — fixed ±30 minute buffer. Not configurable per visit reason.

---

## Success Criteria

All items in `docs/gap-analysis.md` currently marked ❌ for sections P1–P3 are resolved and marked ✅. Tests pass. `BACKEND_CONTEXT.md` updated.

---

## Implementation Plan

### Architecture Decisions

- **Customer cancel endpoints:** New methods on existing `OrderController` and `AppointmentController` (not new controllers). Reuse `UpdateOrderStatus`/`UpdateAppointmentStatus` actions.
- **SMS service:** New `App\Services\SemaphoreService` + `App\Actions\Sms\ProcessSmsNotification`. Artisan command for scheduled dispatch. Config-gated.
- **Message read:** Schema already has `read_at` on `messages` and returns it in API. Just need the "mark read" endpoint + `unread_count` on conversation response.
- **Prescription upload:** New model/table/controller/resource. Filament resource for admin review.
- **Reports:** Filament custom pages (not resources). Stats widget cards + table with query.
- **Slot check:** Validation rule in `StoreAppointmentRequest` + Filament form rule.

### Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Semaphore API unavailable during dev | Low | `enabled = false` default; tests mock HTTP |
| Prescription upload file handling | Low | Reuse existing attachment storage pattern |
| Report queries slow on large data | Low | Simple COUNT/SUM with date index; no materialized views needed |

---

## Tasks

### Phase 1: Quick API Endpoints

#### Task 1: Customer Profile Update

**Description:** Add `PATCH /user` endpoint so customers can update their name, phone, and email.

**Acceptance criteria:**
- [ ] `PATCH /user` with `name`, `phone`, `email` (all optional, min one required)
- [ ] Email uniqueness validated excluding self
- [ ] Returns updated `UserResource`
- [ ] 422 on validation failure

**Verification:** `vendor/bin/sail artisan test --compact --filter=ProfileUpdate`

**Dependencies:** None

**Files:**
- `app/Http/Requests/Api/UpdateProfileRequest.php` (new)
- `app/Http/Controllers/Api/AuthController.php`
- `routes/api.php`
- `tests/Feature/Api/ProfileUpdateTest.php` (new)

**Scope:** Small

---

#### Task 2: Customer Order Cancellation

**Description:** Add `POST /orders/{order}/cancel` for customers to cancel their own `requested` orders.

**Acceptance criteria:**
- [ ] Endpoint callable by authenticated customer
- [ ] Only works when `customer_id` matches user and status is `requested`
- [ ] 403 if not own order; 422 if status is not `requested`
- [ ] Calls `UpdateOrderStatus` with `cancelled`
- [ ] Returns updated `OrderResource`

**Verification:** `vendor/bin/sail artisan test --compact --filter=OrderCancel`

**Dependencies:** None

**Files:**
- `app/Http/Controllers/Api/OrderController.php`
- `routes/api.php`
- `tests/Feature/Api/OrderCancelTest.php` (new)

**Scope:** Small

---

#### Task 3: Customer Appointment Cancellation

**Description:** Add `POST /appointments/{appointment}/cancel` for customers to cancel their own `pending` or `confirmed` appointments.

**Acceptance criteria:**
- [ ] Only works when `customer_id` matches user
- [ ] Only works for `pending` or `confirmed` status
- [ ] 403 if not own; 422 if terminal/completed status
- [ ] Calls `UpdateAppointmentStatus` with `cancelled`
- [ ] SMS record created (same as admin cancel)
- [ ] Returns updated `AppointmentResource`

**Verification:** `vendor/bin/sail artisan test --compact --filter=AppointmentCancel`

**Dependencies:** None

**Files:**
- `app/Http/Controllers/Api/AppointmentController.php`
- `routes/api.php`
- `tests/Feature/Api/AppointmentCancelTest.php` (new)

**Scope:** Small

---

#### Task 4: Feedback Date Filter

**Description:** Add date range filter to the Filament feedback list.

**Acceptance criteria:**
- [ ] `Filter::make('created_at')` with from/until DatePickers on `FeedbackTable`
- [ ] Filters feedback by submission date range

**Verification:** `vendor/bin/sail artisan test --compact --filter=FeedbackResource`

**Dependencies:** None

**Files:**
- `app/Filament/Resources/Feedback/Tables/FeedbackTable.php`

**Scope:** XS

---

### Checkpoint: Phase 1
- [ ] All tests pass
- [ ] 3 new API endpoints working
- [ ] Feedback filter visible in admin

---

### Phase 2: SMS Infrastructure

#### Task 5: Semaphore Service + Process Command

**Description:** Build SMS dispatch infrastructure: service class, action, command, config, migration.

**Acceptance criteria:**
- [ ] `config/services.php` has `semaphore.api_key`, `semaphore.sender_name`, `semaphore.enabled` (default false)
- [ ] New migration adds `failure_reason` (nullable text) to `sms_notifications`
- [ ] `SemaphoreService::send(string $recipient, string $message): bool` — calls Semaphore API (or skips if disabled)
- [ ] `ProcessSmsNotification` action: takes SmsNotification, sends via service, updates status to `sent` or `failed` with reason
- [ ] `sms:process` artisan command: queries `queued` records, processes each
- [ ] When `enabled = false`, marks as `sent` without HTTP call
- [ ] Tests mock HTTP; verify status transitions

**Verification:** `vendor/bin/sail artisan test --compact --filter=Sms`

**Dependencies:** None

**Files:**
- `database/migrations/xxxx_add_failure_reason_to_sms_notifications.php` (new)
- `config/services.php`
- `app/Services/SemaphoreService.php` (new)
- `app/Actions/Sms/ProcessSmsNotification.php` (new)
- `app/Console/Commands/ProcessPendingSmsCommand.php` (new)
- `tests/Feature/SmsProcessingTest.php` (new)

**Scope:** Medium

---

#### Task 6: Order SMS Notifications

**Description:** Create SMS notification records when order status changes to key statuses.

**Acceptance criteria:**
- [ ] `UpdateOrderStatus` creates `sms_notifications` record on: confirmed, ready_for_pickup, completed, cancelled
- [ ] SMS `event` follows pattern: `order_confirmed`, `order_ready`, `order_completed`, `order_cancelled`
- [ ] Recipient is customer phone or email
- [ ] Message includes order number and status description
- [ ] `sms_notifications.appointment_id` made nullable (or add `order_id` FK) — **check: current schema has `appointment_id` non-nullable**

**Verification:** `vendor/bin/sail artisan test --compact --filter=UpdateOrderStatus`

**Dependencies:** Task 5 (for migration)

**Files:**
- `database/migrations/xxxx_make_sms_notifications_flexible.php` (new — add nullable `order_id`, make `appointment_id` nullable)
- `app/Models/SmsNotification.php`
- `app/Actions/Orders/UpdateOrderStatus.php`
- existing test file updates

**Scope:** Medium

---

#### Task 7: SMS Admin Log + Retry

**Description:** Filament resource for viewing SMS history and retrying failed sends.

**Acceptance criteria:**
- [ ] New `SmsNotificationResource` (admin only, read-only, no create/edit/delete)
- [ ] Columns: recipient, event, status badge, message (truncated), created_at, failure_reason
- [ ] Filters: status (queued/sent/failed), event type
- [ ] Row action: "Retry" on failed records → resets status to `queued`, clears failure_reason
- [ ] Navigation group: "Communication" (alongside Conversations, Feedback)

**Verification:** `vendor/bin/sail artisan test --compact --filter=SmsNotificationResource`

**Dependencies:** Tasks 5, 6 (schema must exist)

**Files:**
- `app/Filament/Resources/SmsNotifications/SmsNotificationResource.php` (new)
- `app/Filament/Resources/SmsNotifications/Pages/ListSmsNotifications.php` (new)
- `app/Filament/Resources/SmsNotifications/Tables/SmsNotificationsTable.php` (new)
- `tests/Feature/Filament/SmsNotificationResourceTest.php` (new)

**Scope:** Medium

---

### Checkpoint: Phase 2
- [ ] All tests pass
- [ ] `sms:process` command works
- [ ] Order status changes create SMS records
- [ ] SMS log visible in Filament with retry action

---

### Phase 3: Message Read Tracking

#### Task 8: Mark Messages Read + Unread Count

**Description:** Add endpoint to mark messages as read and include unread count in conversation response.

**Acceptance criteria:**
- [ ] `POST /conversations/{conversation}/messages/read` — marks all messages where `sender_id != auth user` and `read_at is null` as read
- [ ] `GET /conversations` response includes `unread_count` integer
- [ ] Messages sent by self have `read_at` auto-set on creation
- [ ] Returns 200 with `{ "data": { "marked": N } }`
- [ ] Only works for customer's own conversation (403 otherwise)

**Verification:** `vendor/bin/sail artisan test --compact --filter=Messaging`

**Dependencies:** None (schema already has `read_at`)

**Files:**
- `app/Http/Controllers/Api/ConversationController.php`
- `app/Http/Resources/ConversationResource.php`
- `routes/api.php`
- `tests/Feature/Api/MessagingTest.php` (update existing)

**Scope:** Small

---

### Checkpoint: Phase 3
- [ ] All tests pass
- [ ] Unread count returned in conversation endpoint
- [ ] Mark-read endpoint works

---

### Phase 4: Appointment Slot Check

#### Task 9: Appointment Availability Validation

**Description:** Prevent double-booking by validating no conflicting appointment exists within ±30 minutes.

**Acceptance criteria:**
- [ ] `POST /appointments` returns 422 if non-cancelled appointment exists within 30 min of `scheduled_at`
- [ ] Error message: "This time slot is not available. Please choose another time."
- [ ] Filament appointment create form has same validation rule
- [ ] Existing appointments (edit/reschedule) exclude self from conflict check
- [ ] Cancelled appointments are not considered conflicts

**Verification:** `vendor/bin/sail artisan test --compact --filter=AppointmentBooking`

**Dependencies:** None

**Files:**
- `app/Http/Requests/Api/StoreAppointmentRequest.php`
- `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`
- `tests/Feature/Api/AppointmentBookingTest.php` (update existing)

**Scope:** Small

---

### Checkpoint: Phase 4
- [ ] All tests pass
- [ ] Overlapping appointment booking rejected with clear error

---

### Phase 5: Prescription Upload

#### Task 10: Prescription Upload (API + Filament)

**Description:** Customers upload prescription images for admin review. Admin can approve (creates prescription) or reject.

**Acceptance criteria:**
- [ ] New `prescription_uploads` table: id, customer_id, file_path, status (pending/approved/rejected), admin_notes (nullable), prescription_id (nullable FK), created_at, updated_at
- [ ] `POST /prescriptions/upload` — accepts `file` (image/pdf, max 5MB), creates record with status `pending`
- [ ] `GET /prescriptions/uploads` — returns customer's own uploads with status
- [ ] Filament `PrescriptionUploadResource` (admin only): list with status filter, view detail
- [ ] "Approve" action: opens prescription form → creates Prescription → links to upload → status `approved`
- [ ] "Reject" action: enters admin_notes → status `rejected`
- [ ] Files stored at `prescription-uploads/` (private disk)

**Verification:** `vendor/bin/sail artisan test --compact --filter=PrescriptionUpload`

**Dependencies:** None

**Files:**
- `database/migrations/xxxx_create_prescription_uploads_table.php` (new)
- `app/Models/PrescriptionUpload.php` (new)
- `database/factories/PrescriptionUploadFactory.php` (new)
- `app/Http/Controllers/Api/PrescriptionController.php` (add methods)
- `app/Http/Requests/Api/UploadPrescriptionRequest.php` (new)
- `app/Http/Resources/PrescriptionUploadResource.php` (new)
- `routes/api.php`
- `app/Filament/Resources/PrescriptionUploads/` (new resource)
- `tests/Feature/Api/PrescriptionUploadTest.php` (new)
- `tests/Feature/Filament/PrescriptionUploadResourceTest.php` (new)

**Scope:** Large (split into 10a: API + model, 10b: Filament resource if needed)

---

### Checkpoint: Phase 5
- [ ] All tests pass
- [ ] Customer can upload and view uploads
- [ ] Admin can approve/reject in Filament

---

### Phase 6: Reports Module

#### Task 11: Reports Custom Pages

**Description:** Four Filament custom pages under "Reports" nav group with date-range filtered summaries.

**Acceptance criteria:**
- [ ] **Sales Report** — total billings count, total amount, paid amount, unpaid balance filtered by date range
- [ ] **Orders Report** — order count grouped by status, filtered by date range
- [ ] **Appointments Report** — appointment count grouped by status, filtered by date range
- [ ] **Feedback Report** — feedback count, average rating, filtered by date range
- [ ] All pages admin-only
- [ ] Each page has from/until DatePickers
- [ ] Data displayed as stats cards + table breakdown

**Verification:** `vendor/bin/sail artisan test --compact --filter=Report`

**Dependencies:** None

**Files:**
- `app/Filament/Pages/Reports/SalesReport.php` (new)
- `app/Filament/Pages/Reports/OrdersReport.php` (new)
- `app/Filament/Pages/Reports/AppointmentsReport.php` (new)
- `app/Filament/Pages/Reports/FeedbackReport.php` (new)
- `tests/Feature/Filament/ReportsTest.php` (new)

**Scope:** Large (4 pages, but each is formulaic)

---

### Final Checkpoint
- [ ] All tests pass (`vendor/bin/sail artisan test --compact`)
- [ ] All P1–P3 gaps in `docs/gap-analysis.md` resolved
- [ ] `docs/BACKEND_CONTEXT.md` updated with new routes, tables, conventions
- [ ] Pint formatted (`vendor/bin/sail bin pint --dirty --format agent`)
