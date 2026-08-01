# POCMS Backend — Context Document

> **Living document.** Update this when schema, routes, roles, status values, or architectural decisions change.
>
> **Reconciliation status as of 2026-08-01.** Patient accounts, two-stage
> OTP-based registration, hybrid login, contact management, patient linking,
> appointment requests, authenticated step-up for sensitive changes, and
> Optical Orders workflow have been implemented. The API contract includes
> 54 routes (7 public, 22 account-only, 25 active-link). Legacy intake
> routes and direct booking have been removed. All accounts use structured
> first/middle/last names.

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
| Billing Records | Record payment | Void, correct payment |
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
| `users` | Login accounts. email + password nullable for walk-in patients. `is_optometrist` capability flag. `first_name`, `middle_name`, `last_name` for all accounts; `name` auto-derived. `privacy_notice_version`, `privacy_acknowledged_at`. |
| `patient_account_contacts` | Verified contact methods for patient accounts. `user_id`, `type` (email/phone), encrypted `value`, unique `lookup_hash`, `verified_at`, `is_primary`. Unique `(user_id, type)`. |
| `otp_challenges` | Purpose-bound OTP challenges. `public_id`, `user_id`, `purpose` (registration/login_step_up/password_recovery/add_contact/replace_primary_contact/invitation_acceptance), `channel`, encrypted `destination`, `destination_hash`, `code_digest`, `attempts`, `max_attempts`, `expires_at`, `consumed_at`, `invalidated_at`, `delivery_status`. |
| `patient_link_requests` | Staff-reviewed link attempts. `request_number`, `user_id`, encrypted `identity_snapshot`, `status` (pending/approved/rejected), `reviewed_patient_id`, `reviewer_id`, `decision_note`, `reviewed_at`. |
| `patient_link_candidates` | Staff-only candidate rankings. `link_request_id`, `patient_id`, `match_strength` (strong/moderate/weak), `reason_codes` (JSON), `rank`. |
| `patient_invitations` | Single-use expiring invitations. `public_id`, `patient_id`, `sender_id`, `channel`, encrypted `destination`, `destination_hash`, `secret_digest`, `status` (pending/accepted/expired/revoked/failed), `expires_at`, `sent_at`, `revoked_at`, `accepted_at`, `accepted_by_user_id`. |
| `appointment_requests` | Patient appointment requests. `request_number`, `user_id`, `patient_id`, `appointment_type_id`, `appointment_id` (unique), `scheduled_at`, `provisional_duration_minutes`, encrypted `reason_for_visit`, encrypted `identity_snapshot`, `status` (pending/accepted/rejected/cancelled/expired), `expires_at`, `resolved_by_user_id`, `resolved_at`. |
| `patients` | Independent clinical identity. `patient_number` (PAT-YYYY-NNNNNN), `first_name`, `middle_name`, `last_name`, `full_name` (derived), `date_of_birth`, `occupation`, `address`, `gender`, `contact_email`, `phone`, `contact_email_lookup_hash`, `phone_lookup_hash`. Optional `user_id` link to account. |
| `appointments` | `patient_id`, `appointment_type_id`, `referring_source`, `visit_reason_id`, `appointment_status_id`, `optometrist_id`, `source` (mobile/walk_in/manual), `scheduled_at`, `checked_in_at`, `fulfilled_at`, `cancelled_by`, `cancelled_by_user_id`, `cancellation_reason_category`, `cancellation_reason_details`, `cancelled_at`, `no_show_by`, `no_show_at`, `contact_notes`, `staff_notes`, `reason_for_visit`. |
| `appointment_reschedules` | `appointment_id`, `previous_scheduled_at`, `new_scheduled_at`, `initiated_by` (patient/clinic), `actor_id`, `reason_category`, `reason_details`, `rescheduled_at`, `notified_at`. |
| `patient_intakes` | `patient_id`, `appointment_id`, `status` (draft/submitted/verified), demographics snapshot, encrypted clinical narrative fields (`chief_complaint`, `past_ocular_history`, etc.), `submitted_by`, `verified_by`. |
| `encounters` | `patient_id`, `appointment_id`, `patient_intake_id`, `optometrist_id`, `status` (planned/in_progress/completed/cancelled), encrypted `findings`/`remarks`, encrypted `chief_complaint`/`past_ocular_history`/`past_surgical_history`/`past_medical_history`/`allergies`/`medications`/`plan`, `last_wizard_step`, `draft_saved_at`, `completed_by`. |
| `prescriptions` | `patient_id`, `encounter_id`, `appointment_id`, `previous_prescription_id`, `created_by`, encrypted main group (`main_od_value`, `main_od_sphere`, `main_od_cylinder`, `main_os_value`, `main_os_sphere`, `main_os_cylinder`), encrypted ADD group (`add_od_value`, `add_od_sphere`, `add_od_cylinder`, `add_os_value`, `add_os_sphere`, `add_os_cylinder`), encrypted `remarks`, encrypted `amendment_reason`, `prescribed_at`, `deleted_at`. |
| `quotations` | `patient_id`, `encounter_id`, `prescription_id`, `status` (draft/presented/accepted/declined/expired), `valid_until`, `eyewear_key` (unique, `eyw_{ULID}`). |
| `quotation_revisions` | Immutable snapshots with `revision_number`, `subtotal`, `discount_amount`, `total`, `presented_by`, `accepted_by`. |
| `quotation_items` | `description`, `quantity`, `unit_price`, `amount`, `product_variant_id`, `lens_category_id`. |
| `job_orders` | `patient_id`, `encounter_id`, `prescription_id`, `quotation_revision_id`, `frame_reservation_id` (unique, nullable), `status` (queued/in_progress/ready_for_dispensing/dispensed/cancelled), `total_amount`, nullable internal `supplier_invoice_number`, `eyewear_key` (unique, `eyw_{ULID}`, copied from quotation on creation). |
| `job_order_items` | `description`, `quantity`, `unit_price`, `amount`, `product_variant_id`, `lens_category_id`. |
| `billing_records` | `patient_id`, `job_order_id` (unique), `encounter_id`, `billing_record_number`, `status` (unpaid/partially_paid/paid/voided), `total_amount`, `amount_paid`, `balance_due`, `recorded_by`, `recorded_at`. |
| `billing_payments` | `billing_record_id`, `amount`, `payment_method`, `reference_number`, `status` (posted/voided), `recorded_by`, `recorded_at`, `notes`. |
| `dispensing_events` | `job_order_id`, `billing_record_id`, `dispensed_by`, `recipient_name`, `notes`. |
| `frame_reservations` | `patient_id`, `appointment_id` (required, restrict on delete), `status` (requested/prepared/tried_on/converted/released/cancelled), `staff_notes`, `expires_at`. |
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

These models use `SoftDeletes`: `Patient`, `Product`, `ProductVariant`, `Appointment`, `Prescription`, `Conversation`, `BillingRecord`, `JobOrder`, `Complaint`.

---

## Status Transition Rules

**Appointments:** `scheduled → checked_in → fulfilled` (terminal). `cancelled` and `no_show` are terminal from `scheduled` or `checked_in`. Check-in creates an Encounter transactionally.

**Encounters:** `planned → in_progress → completed` (terminal). `cancelled` is terminal from `planned` only. Only optometrists can start/complete. The in-progress Encounter page is the only normal entry point for finalizing its first prescription.

**Quotations:** `draft → presented → accepted/declined/expired`. Presented revisions are immutable. Accepted quotations can create job orders.

Quotation forms expose only the patient-visible notes field. The legacy `internal_notes` column remains temporarily for compatibility but is hidden from creation and editing; a future cleanup may replace the remaining notes field with a single patient-visible `Remarks` field and remove `internal_notes`.

**Job Orders:** `queued → in_progress → ready_for_dispensing → dispensed` (terminal). `cancelled` is terminal from any active state. Cancellation reverses inventory. `supplier_invoice_number` may be blank while work is queued or in progress, but the domain transition rejects Ready for Dispensing or Dispensed without it.

**Billing Records:** `unpaid → partially_paid → paid` (terminal). `voided` is terminal. Payments are append-only with posted/voided status. No BIR/official number. The Job Order is authoritative for line items; the Billing Record admin detail page displays those linked items read-only rather than duplicating them.

**Frame Reservations:** `requested → prepared → tried_on → converted/released/cancelled`. Prepared reservations allocate stock. Release restores stock.

**Eyewear Aggregate:** Patient-facing read-only API joining Quotation, Job Order, and Billing Record into one coherent transaction. Each aggregate carries a stable `eyw_{ULID}` key persisted on both Quotations and Job Orders. A `jo_{id}` alias is accepted for migration compatibility. Current filter: `estimate_available`, `in_preparation`, `ready_for_pickup`. History filter: `dispensed`, `estimate_declined`, `estimate_expired`, `cancelled`. Payment state never changes filter membership.

---

## Filament Panel

URL: `/admin` — accessible to `staff` and `admin` roles only.

**Navigation groups (in order):**
- Accounts & Access — Staff Accounts, Patient Accounts, Link Requests
- Patients & Clinical — Patients, Encounters, Prescriptions, Complaints
- Fulfillment & Finance — Optical Orders, Frame Reservations
- Catalog & Inventory — Products, Inventory History
- Communication — Conversations, Frame Ratings
- Reports — Reorder Report
- Administration — Audit Logs
- Settings — Categories, Brands, Lens Categories, Visit Reasons, Services

**Dashboard widgets:**
1. **Stats Overview** — Today's Appointments, Waiting Today, Active Encounters, Quotations Pending, Ready for Dispensing, Low Stock
2. **Today's Schedule** — Next 5 active appointments with patient name, phone, visit reason, status
3. **Appointments Chart** — 30-day trend line

---

## Mobile REST API

Base: `/api/v1` (sole patient-mobile contract)

### Public Authentication (no token required)
```
POST   /api/v1/auth/registration/otp          Request registration OTP
POST   /api/v1/auth/registration/verify       Verify OTP, get registration_token
POST   /api/v1/auth/register                  Complete registration with profile
POST   /api/v1/auth/login                     Password login (step-up or token)
POST   /api/v1/auth/login/verify              Verify login OTP, issue token
POST   /api/v1/auth/password-recovery/otp     Request recovery OTP
POST   /api/v1/auth/password-recovery/verify  Reset password, issue token
GET    /api/v1/auth/policies                  Get current Terms/Privacy metadata
```

### Authenticated Account-Only (token required, no active link needed)
```
POST   /api/v1/logout
POST   /api/v1/logout-all
GET    /api/v1/me
PATCH  /api/v1/me
POST   /api/v1/auth/step-up/otp               Request sensitive-change OTP
POST   /api/v1/auth/step-up/verify            Get step_up_token (15min)
POST   /api/v1/auth/password                  Change password (X-Step-Up-Token header)
GET    /api/v1/account/contacts
POST   /api/v1/account/contacts/otp           Requires X-Step-Up-Token header
POST   /api/v1/account/contacts/verify
PATCH  /api/v1/account/contacts/{id}/primary  Requires X-Step-Up-Token header
DELETE /api/v1/account/contacts/{id}          Requires X-Step-Up-Token header
GET    /api/v1/account/link
POST   /api/v1/patient-link-requests
GET    /api/v1/patient-link-requests/current
POST   /api/v1/patient-invitations/acceptance/otp
POST   /api/v1/patient-invitations/accept
GET    /api/v1/appointment-request-availability
GET    /api/v1/appointment-requests
POST   /api/v1/appointment-requests
GET    /api/v1/appointment-requests/{id}
POST   /api/v1/appointment-requests/{id}/cancel
```

### Active Patient Link Required (token + active link)
```
GET    /api/v1/appointment-availability
GET    /api/v1/appointments
GET    /api/v1/appointments/{id}
POST   /api/v1/appointments/{id}/cancel
POST   /api/v1/appointments/{id}/reschedule
GET    /api/v1/frames
GET    /api/v1/frames/{id}
GET    /api/v1/frame-reservations
POST   /api/v1/frame-reservations
POST   /api/v1/frame-reservations/{id}/cancel
GET    /api/v1/prescriptions
GET    /api/v1/prescriptions/{id}
GET    /api/v1/quotations
GET    /api/v1/quotations/{id}
GET    /api/v1/job-orders
GET    /api/v1/job-orders/{id}
GET    /api/v1/billing-records
GET    /api/v1/billing-records/{id}
GET    /api/v1/eyewear
GET    /api/v1/eyewear/{key}
GET    /api/v1/conversation
GET    /api/v1/conversation/messages
POST   /api/v1/conversation/messages
GET    /api/v1/conversation/attachments/{id}
POST   /api/v1/job-order-items/{id}/rating
```

**Route count:** 8 public + 21 account-only + 25 active-link = **54 routes total.**

Breaking changes from coordinated Android cutover:
- Direct `POST /appointments` removed (use appointment requests)
- `GET /appointment-types` removed (internal only)
- `POST /register` and `POST /login` removed (replaced by auth/register and auth/login)
- Three intake routes removed (retired)
- Three intake routes removed (retired)

All patient resource access is scoped through the authenticated account's linked patient identity. Patients cannot create job orders, billing records, payments, orders, billings, checkout records, or purchases.

---

## Key Actions (Single-Purpose Workflow Classes)

| Action | Location | Does |
|---|---|---|
| `IssueOtpChallenge` | `app/Actions/Auth/` | Creates encrypted OTP challenge with blind index, invalidates earlier pending |
| `VerifyOtpChallenge` | `app/Actions/Auth/` | Verifies purpose-bound codes under row locks, single consumption |
| `DispatchOtpChallenge` | `app/Actions/Auth/` | Dispatches OTP delivery job after commit |
| `RegisterPatientAccount` | `app/Actions/Auth/` | Creates patient-role User + verified contact after OTP, no Patient created |
| `BeginPatientLogin` | `app/Actions/Auth/` | Verifies password against contacts, issues login step-up OTP |
| `IssuePatientDeviceToken` | `app/Actions/Auth/` | Verifies login OTP, manages device tokens, enforces max 5 |
| `RecoverPatientPassword` | `app/Actions/Auth/` | Resets password after recovery OTP, revokes other tokens |
| `NormalizeContact` | `app/Actions/PatientAccounts/` | Deterministic email/phone/name normalization |
| `CreateContactLookupHash` | `app/Actions/PatientAccounts/` | HMAC blind indexes for contact lookups |
| `RankPatientCandidates` | `app/Actions/PatientAccounts/` | Searches clinic data by contact/name/DOB, returns ranked candidates |
| `SubmitPatientLinkRequest` | `app/Actions/PatientAccounts/` | Creates link request with candidates, returns existing on repeat |
| `ReviewPatientLinkRequest` | `app/Actions/PatientAccounts/` | Approve (with row-lock recheck) or reject link request |
| `UnlinkPatientAccount` | `app/Actions/PatientAccounts/` | Revokes tokens, removes link, creates audit log |
| `IssuePatientInvitation` | `app/Actions/PatientAccounts/` | Creates single-use expiring invitation |
| `AcceptPatientInvitation` | `app/Actions/PatientAccounts/` | Verifies OTP, creates/reuses account, activates link |
| `SearchPatientDuplicates` | `app/Actions/Patients/` | Searches by email hash, phone hash, name+DOB |
| `SubmitAppointmentRequest` | `app/Actions/Appointments/` | Creates request with hold, validates slot availability |
| `CancelAppointmentRequest` | `app/Actions/Appointments/` | Ownership check, status validation |
| `AcceptAppointmentRequest` | `app/Actions/Appointments/` | Creates scheduled appointment, copies reason, idempotent |
| `RejectAppointmentRequest` | `app/Actions/Appointments/` | Closes request without creating appointment |
| `ExpireAppointmentRequests` | `app/Actions/Appointments/` | Idempotent scheduled expiry of pending requests |
| `BuildScheduleBlocks` | `app/Actions/Appointments/` | Produces blocks from appointments + request holds |
| `ConvertFrameReservationToJobOrder` | `app/Actions/Reservations/` | Transfers reservation allocation to order commitment |
| `AcceptAndStartOpticalOrder` | `app/Actions/OpticalOrders/` | Accepts quotation, creates Job Order + Billing Record |
| `CancelOpticalOrder` | `app/Actions/OpticalOrders/` | Reverses inventory, voids unpaid billing, preserves payments |
| `AuditLegacyPatientIntakes` | `app/Actions/Encounters/` | Reports cleanup readiness for legacy intake data |
| `PrunePatientAccountData` | `app/Actions/PatientAccounts/` | Prunes expired OTPs, tokens, invitations, terminal requests |
| `CreateScheduledAppointment` | `app/Actions/Appointments/` | Creates appointment from mobile API with availability checks |
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
- **Edit pages:** Quotations, Billing Records, and Job Orders have full form schemas showing related items, financial summaries, and timelines. Billing Record items are read-only values resolved from `jobOrder.items`.
- **Supplier invoice reference:** `job_orders.supplier_invoice_number` records the supplier's external invoice number only. Staff may enter it while the Job Order is active, and the Mark Ready action requires it. It is clinic-internal, is not part of Billing Records, and is hidden from patient APIs.
- **Walk-in patients:** `users.email` and `users.password` are nullable. Walk-in records have only name + phone.
- **Patient address:** Single nullable free-text field. Editable by patient via API and by staff via Patients edit form.
- **Optometrist assignment:** Clinic-controlled. Patients choose clinic time only, not a specific provider.
- **Clinical data encrypted:** Prescription values, intake narrative, encounter findings/remarks use Laravel's `encrypted` cast. Not queryable.
- **`CX` in prescription print:** Binds to cylinder values. Axis is separate. Confirmed by clinic 2026-07-26.
- **Inventory:** `stock_quantity` represents available stock. Preparing a reservation reduces available stock. Dispensing does not deduct again.
- **Legacy tables:** `orders`, `order_items`, `order_statuses`, `billings`, `billing_items`, `billing_statuses`, `discount_types`, `payments`, `service_records` remain in the schema but have no canonical application consumers. They will be removed in a future cleanup migration.
