# POCMS Backend — Context Document

> **Living document.** Update this when schema, routes, roles, status values, or architectural decisions change.
>
> **Reconciliation status as of 2026-07-27.** Canonical schema creation,
> patient route equality, and conversation attachment isolation have been
> reconciled through Tasks 1–13 of
> `docs/specs/post-implementation-reconciliation-closure-tasks.md`. Full
> regression passed at this cutoff: 442 Pest tests, 1,174 assertions. Tasks 14+
> remain documented as future release-hardening work.

---

## What This Is

Laravel 13 backend for the Padilla Optical Clinic Management System. Serves two clients:
- **Filament admin panel** (`/admin`) — staff and admin web UI
- **Android mobile app** — patient-facing, consumes the `/api/v1` REST API

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
| `admin` | Filament panel. Can manage users, audit/privacy records, destructive archive/restore actions, and optometrist workflow actions when `is_optometrist` is true. |
| `staff` | Filament panel. Used for clinic operators such as optometrists and possible receptionists; optometrist-only workflow actions require `is_optometrist`. |
| `patient` | Mobile API only — cannot access Filament |

Role enforcement: `canAccessPanel()` on `User` model for Filament; policies and action-level checks for patient records, clinical records, and optometrist-only workflow steps. There is no approved staff-only mobile API route group.

### Admin vs Staff permissions

Use `User::isAdmin()` to check role in Filament. `is_optometrist` is a capability flag on staff/admin accounts.

| Area | Staff CAN | Admin only |
|---|---|---|
| Appointments | Create, check-in, reschedule, cancel, mark no-show | Cancel bulk |
| Encounters | View | Start/complete (optometrist only) |
| Prescriptions | View | Finalize/amend (optometrist only) |
| Quotations | Present, accept/decline | — |
| Job Orders | Start, mark ready, cancel | — |
| Invoices | Record payment | Void, correct payment |
| Products | Create, edit, manage variants | Delete/restore |
| Patients | Create, edit | Delete/restore |
| Users | Hidden | Full CRUD |
| Audit Logs | Hidden | Read-only |

---

## Demo Accounts

Seeded by `DemoUserSeeder`. All passwords: `password`

| Role | Email | Notes |
|---|---|---|
| Admin (optometrist) | admin@eyecare.test | Dr. Maria Santos |
| Staff (optometrist) | staff@eyecare.test | Dr. Juan dela Cruz |
| Patient (linked) | customer@eyecare.test | Ana Reyes |
| Patient (walk-in) | — | Pedro Cruz, no account |

---

## Database: Key Tables

### Lookup / Status Tables (seeded, rarely changed)

| Table | Values |
|---|---|
| `roles` | admin, staff, patient |
| `appointment_statuses` | scheduled, checked_in, fulfilled, cancelled, no_show |
| `appointment_types` | New Patient (30m), Follow-up (15m), Routine Check-up (30m), Referral (30m) |
| `inventory_movement_types` | restock, manual_adjustment, reservation_allocation, reservation_release, order_commitment, order_reversal, damaged |
| `payment_methods` | Cash, GCash, Bank Transfer, Credit Card, Check |
| `notification_statuses` | queued, sent, failed, cancelled |

### Business Tables

| Table | Notes |
|---|---|
| `users` | Login accounts. email + password nullable for walk-in patients. `is_optometrist` capability flag. `privacy_notice_version`, `privacy_acknowledged_at`. |
| `patients` | Independent clinical identity. `patient_number` (PAT-ULID), `full_name`, `date_of_birth`, `occupation`, `address`, `gender`, `contact_email`, `phone`. Optional `user_id` link to account. |
| `appointments` | `patient_id`, `appointment_type_id`, `referring_source`, `visit_reason_id`, `appointment_status_id`, `optometrist_id`, `source` (mobile/walk_in/manual), `scheduled_at`, `checked_in_at`, `fulfilled_at`, `cancelled_by`, `cancelled_by_user_id`, `cancellation_reason_category`, `cancellation_reason_details`, `cancelled_at`, `no_show_by`, `no_show_at`, `contact_notes`, `staff_notes`. |
| `appointment_reschedules` | `appointment_id`, `previous_scheduled_at`, `new_scheduled_at`, `initiated_by` (patient/clinic), `actor_id`, `reason_category`, `reason_details`, `rescheduled_at`, `notified_at`. |
| `patient_intakes` | `patient_id`, `appointment_id`, `status` (draft/submitted/verified), demographics snapshot, encrypted clinical narrative fields (`chief_complaint`, `past_ocular_history`, etc.), `submitted_by`, `verified_by`. |
| `encounters` | `patient_id`, `appointment_id`, `patient_intake_id`, `optometrist_id`, `status` (planned/in_progress/completed/cancelled), encrypted `findings`/`remarks`. |
| `prescriptions` | `patient_id`, `encounter_id`, `appointment_id`, `previous_prescription_id`, `created_by`, encrypted main group (`main_od_value`, `main_od_sphere`, `main_od_cylinder`, `main_os_value`, `main_os_sphere`, `main_os_cylinder`), encrypted ADD group (`add_od_value`, `add_od_sphere`, `add_od_cylinder`, `add_os_value`, `add_os_sphere`, `add_os_cylinder`), encrypted `remarks`, encrypted `amendment_reason`, `prescribed_at`, `deleted_at`. |
| `quotations` | `patient_id`, `encounter_id`, `prescription_id`, `status` (draft/presented/accepted/declined/expired), `valid_until`. |
| `quotation_revisions` | Immutable snapshots with `revision_number`, `subtotal`, `discount_amount`, `total`, `presented_by`, `accepted_by`. |
| `quotation_items` | `description`, `quantity`, `unit_price`, `amount`, `product_variant_id`, `lens_category_id`. |
| `job_orders` | `patient_id`, `encounter_id`, `prescription_id`, `quotation_revision_id`, `status` (queued/in_progress/ready_for_dispensing/dispensed/cancelled), `total_amount`. |
| `job_order_items` | `description`, `quantity`, `unit_price`, `amount`, `product_variant_id`, `lens_category_id`. |
| `invoices` | `patient_id`, `job_order_id`, `encounter_id`, `official_number` (nullable, unique), `status` (draft/issued/partially_paid/paid/voided), `sale_type`, `sold_to_name`, `subtotal`, `discount_amount`, `tax_amount`, `total`, `amount_paid`, `balance_due`. |
| `invoice_items` | `type` (product/service), `description`, `quantity`, `unit_price`, `amount`, `job_order_item_id`. |
| `invoice_payments` | `amount`, `payment_method`, `reference_number`, `status` (posted/voided), `recorded_by`. |
| `dispensing_events` | `job_order_id`, `invoice_id`, `dispensed_by`, `recipient_name`, `notes`. |
| `frame_reservations` | `patient_id`, `appointment_id`, `status` (requested/prepared/tried_on/converted/released/cancelled), `staff_notes`, `expires_at`. |
| `frame_reservation_items` | `product_variant_id`. |
| `frame_ratings` | `patient_id`, `product_variant_id`, `dispensing_event_id`, `rating` (1-5), `comment`, `is_hidden`, `moderation_reason`, `current_revision_id`. |
| `frame_rating_revisions` | `revision_number`, `rating`, `comment`, `revised_by`. |
| `complaints` | `patient_id`, `original_job_order_id`, `status`, `patient_description`, `resolution_notes`, `new_appointment_id`, `new_encounter_id`. |
| `conversations` | `patient_id` — one per patient. |
| `messages` | `conversation_id`, `sender_id`, `body`, `read_at`. |
| `audit_logs` | `actor_id`, `subject_type`, `subject_id`, `action`, `metadata` (JSON). |
| `inventory_movements` | `product_variant_id`, `reservation_id`, `job_order_id`, `inventory_movement_type_id`, `quantity_change`, `previous_stock`, `new_stock`, `created_by`. |
| `privacy_requests` | `patient_id`, `request_type` (access/correction/objection/erasure), `disposition`, `handled_by`. |
| `privacy_incidents` | `title`, `description`, `status`, `reported_by`, `assigned_to`. |
| `clinic_hours` | `weekday` (0-6), `open_time`, `close_time`, `enabled`. |
| `provider_hours` | `user_id`, `weekday`, `start_time`, `end_time`, `enabled`. |
| `schedule_overrides` | `user_id` (nullable), `override_date`, `type` (closed/early_close/provider_absence), `start_time`, `end_time`, `reason`. |

### Soft Deletes

These models use `SoftDeletes`: `Patient`, `Product`, `ProductVariant`, `Appointment`, `Prescription`, `Conversation`, `Invoice`, `JobOrder`, `Complaint`.

---

## Status Transition Rules

**Appointments:** `scheduled → checked_in → fulfilled` (terminal). `cancelled` and `no_show` are terminal from `scheduled` or `checked_in`. Check-in creates an Encounter transactionally.

**Encounters:** `planned → in_progress → completed` (terminal). `cancelled` is terminal from `planned` only. Only optometrists can start/complete. The in-progress Encounter page is the only normal entry point for finalizing its first prescription.

**Quotations:** `draft → presented → accepted/declined/expired`. Presented revisions are immutable. Accepted quotations can create job orders.

**Job Orders:** `queued → in_progress → ready_for_dispensing → dispensed` (terminal). `cancelled` is terminal from any active state. Cancellation reverses inventory.

**Invoices:** `draft → issued → partially_paid → paid` (terminal). `voided` is terminal. Payments are append-only with posted/voided status.

**Frame Reservations:** `requested → prepared → tried_on → converted/released/cancelled`. Prepared reservations allocate stock. Release restores stock.

---

## Filament Panel

URL: `/admin` — accessible to `staff` and `admin` roles only.

**Navigation groups (in order):**
- Patients & Clinical — Patients, Encounters, Prescriptions, Complaints
- Fulfillment & Finance — Frame Reservations, Quotations, Job Orders, Invoices
- Catalog & Inventory — Products, Inventory History
- Communication — Conversations, Frame Ratings
- Reports — Reorder Report
- Administration — Users, Audit Logs, Privacy Incidents
- Settings — Categories, Brands, Lens Categories, Visit Reasons, Services

**Dashboard widgets:**
1. **Stats Overview** — Today's Appointments, Waiting Today, Active Encounters, Quotations Pending, Ready for Dispensing, Low Stock
2. **Today's Schedule** — Next 5 active appointments with patient name, phone, visit reason, status
3. **Appointments Chart** — 30-day trend line

---

## Mobile REST API

Base: `/api/v1` (sole patient-mobile contract)

```
POST   /api/v1/register           Patient registration → Sanctum token
POST   /api/v1/login              Login → Sanctum token
POST   /api/v1/logout
GET    /api/v1/me                 Authenticated user profile
PATCH  /api/v1/me                 Update own profile

GET    /api/v1/appointment-types
GET    /api/v1/appointment-availability
GET    /api/v1/appointments
POST   /api/v1/appointments
GET    /api/v1/appointments/{appointment}
POST   /api/v1/appointments/{appointment}/reschedule
POST   /api/v1/appointments/{appointment}/cancel
GET    /api/v1/appointments/{appointment}/intake
PUT    /api/v1/appointments/{appointment}/intake
POST   /api/v1/appointments/{appointment}/intake/submit

GET    /api/v1/frames
GET    /api/v1/frames/{frame}
GET    /api/v1/frame-reservations
POST   /api/v1/frame-reservations
POST   /api/v1/frame-reservations/{reservation}/cancel

GET    /api/v1/prescriptions
GET    /api/v1/prescriptions/{prescription}
GET    /api/v1/quotations
GET    /api/v1/quotations/{quotation}
GET    /api/v1/job-orders
GET    /api/v1/job-orders/{jobOrder}
GET    /api/v1/invoices
GET    /api/v1/invoices/{invoice}

GET    /api/v1/conversation
GET    /api/v1/conversation/messages
POST   /api/v1/conversation/messages
GET    /api/v1/conversation/attachments/{attachment}

POST   /api/v1/job-order-items/{item}/rating
```

The approved patient-mobile contract contains exactly 33 routes. List endpoints are paginated except `GET /frame-reservations` (returns full list) and `GET /conversation/messages` (returns all messages). All patient resource access is scoped through the authenticated account's linked patient identity. Patients cannot create job orders, invoices, payments, orders, billings, checkout records, or purchases.

---

## Key Actions (Single-Purpose Workflow Classes)

| Action | Location | Does |
|---|---|---|
| `CreateScheduledAppointment` | `app/Actions/Appointments/` | Creates appointment from mobile API with availability checks |
| `CreateWalkInAppointment` | `app/Actions/Appointments/` | Creates walk-in with arrived status, immediate check-in |
| `CheckInAppointment` | `app/Actions/Encounters/` | Row-locked check-in, snapshot verified intake, create encounter |
| `FinalizePrescription` | `app/Actions/Prescriptions/` | Optometrist-only; validates encounter/patient ownership, derives appointment linkage, prevents duplicate/branching originals or amendments, and audits finalization |
| `PresentQuotation` | `app/Actions/Quotations/` | Marks draft as presented, records presenter/time |
| `RecordQuotationDecision` | `app/Actions/Quotations/` | Records accept/decline/expired with actor/time |
| `CreateJobOrder` | `app/Actions/JobOrders/` | Creates from accepted quotation, commits inventory atomically |
| `UpdateJobOrderStatus` | `app/Actions/JobOrders/` | Enforced transitions, timestamps, cancel reverses inventory |
| `CommitJobOrderInventory` | `app/Actions/JobOrders/` | Row-locked stock decrement with movement records |
| `RecordInvoicePayment` | `app/Actions/Invoices/` | Row-locked payment recording, recalculates balance |
| `CorrectInvoicePayment` | `app/Actions/Invoices/` | Voids original, creates replacement, preserves audit |
| `DispenseJobOrder` | `app/Actions/Invoices/` | Atomic dispensing + invoice issuance |
| `PrepareFrameReservation` | `app/Actions/Reservations/` | Row-locked stock allocation with movement records |
| `ReleaseFrameReservation` | `app/Actions/Reservations/` | Idempotent stock restoration |
| `SaveFrameRating` | `app/Actions/Ratings/` | Create or append revision, one per patient/variant |
| `ModerateFrameRating` | `app/Actions/Ratings/` | Hide/restore comments, preserve star aggregates |
| `VerifyPatientIntake` | `app/Actions/Intakes/` | Records verifier/time, locks snapshot |
| `ProcessPrivacyRequest` | `app/Actions/Privacy/` | Records disposition, no auto-deletion |
| `CreateAuditLog` | `app/Actions/Audit/` | Persists audit entry (actor, subject, action, metadata) |

---

## Soft Deletes and Archive/Restore

Filament's "Delete"/"Restore" labels are renamed to **"Archive"/"Restore"** with `heroicon-o-archive-box` icon. `TrashedFilter` is labeled "Show Archived" with relabeled options.

---

## Important Conventions

- **Appointment source values:** `mobile` (patient books via Android app), `walk_in` (patient physically at clinic), `manual` (staff creates in admin panel). Set automatically — not a user-facing dropdown.
- **Appointment create form:** Patient mode toggle (new/existing). New patient shows full demographic fields. Walk-in toggle hides date/time and auto-sets source/status/checked_in_at. Referring source appears when appointment type is Referral. Notes is a single staff_notes field.
- **Appointment edit form:** Patient is read-only placeholder. Fields editable until checked in (scheduled/checked_in): appointment type, date/time, referring source, notes, optometrist. Status toggle and appointment type share a row. Quick "Assign" action available from list for optometrist assignment.
- **Prescriptions:** No standalone create. An optometrist starts the encounter, then uses **Create Prescription** on that in-progress Encounter page. Patient, appointment, encounter, and author linkage are locked and derived server-side. Finalized prescriptions are read-only and cannot be archived through Filament. An optometrist must use **Amend Prescription**, provide a reason, and create a new linear version through `previous_prescription_id`; the original remains unchanged and is visibly marked superseded. Only the current leaf version can be printed or appears in the patient API list. The reason and clinical fields are encrypted, while the audit log stores only linkage metadata, actor, action, and time.
- **Edit pages:** Quotations, Invoices, and Job Orders have full form schemas showing related items, financial summaries, and timelines.
- **Walk-in patients:** `users.email` and `users.password` are nullable. Walk-in records have only name + phone.
- **Patient address:** Single nullable free-text field. Editable by patient via API and by staff via Patients edit form.
- **Optometrist assignment:** Clinic-controlled. Patients choose clinic time only, not a specific provider.
- **Clinical data encrypted:** Prescription values, intake narrative, encounter findings/remarks use Laravel's `encrypted` cast. Not queryable.
- **`CX` in prescription print:** Binds to cylinder values. Axis is separate. Confirmed by clinic 2026-07-26.
- **Inventory:** `stock_quantity` represents available stock. Preparing a reservation reduces available stock. Dispensing does not deduct again.
- **Legacy tables:** `orders`, `order_items`, `order_statuses`, `billings`, `billing_items`, `billing_statuses`, `discount_types`, `payments`, `service_records` remain in the schema but have no canonical application consumers. They will be removed in a future cleanup migration.
