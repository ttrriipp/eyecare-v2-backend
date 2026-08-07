# POCMS Backend — Context Document

> **Living document.** Update this when schema, routes, roles, status values, or architectural decisions change.
>
> **Reconciliation status as of 2026-08-07.** Patient accounts, two-stage
> phone-OTP registration, phone-primary authentication, contact management,
> patient linking, expanded unlinked appointment-request identity snapshots,
> authenticated step-up for sensitive changes, Optical Orders workflow,
> separate Quotations and Optical Orders sections, and unified billing
> with explicit charge provenance have been implemented. The admin sidebar
> was restructured into a workflow-shaped taxonomy (Today, Patients,
> Clinical, Optical, Billing, Catalog, Admin), Availability is now a
> Filament cluster (Clinic Hours, Optometrist Hours, Schedule Overrides —
> the "Today" resolved-availability sub-page was built and then removed),
> and a Service catalog was added so clinical/service charges no longer
> need a product/lens-category workaround. `JobOrderResource` was folded
> into `OpticalOrderResource` (index + edit only; creation happens via
> quotation confirm-sale or the new "New Direct Order" direct-creation
> flow). Quotation confirm-sale, Encounter service charges, and direct
> Billing Record charges now share one open checkout per patient
> visit instead of creating duplicate billing records per source. Staff
> can reserve frames from any scheduled appointment regardless of source
> (mobile, walk-in, or manually created), not just mobile-originated ones.
> A frame reservation is a strictly before-the-visit tool — an appointment
> gets exactly one, ever (DB-level unique constraint on `appointment_id`);
> staff can add/remove candidate frames on that one reservation up to
> `tried_on`, mark it tried on, and reservation expiration is now actually
> wired up (`expires_at` is stamped at prepare time and `reservations:expire`
> runs on a schedule — both existed structurally before but neither was
> connected). Confirm Sale converts a selected reservation's held stock into
> the resulting Optical Order instead of committing it a second time. The
> Patient record gained Encounters, Optical Orders, and Billing tabs
> alongside the existing Prescriptions/Appointments/Health Record/Invitation
> History ones, so staff no longer have to leave the patient page to see
> commercial history. Patient app-invitation delivery is phone/SMS only
> (email invitation delivery was removed) so the invitation-acceptance trust
> anchor matches the verified login contact. The API contract includes 51
> routes (8 public, 24 account-only, 19 active-link). Legacy intake routes,
> direct booking, job-orders, eyewear, and billing-records API routes have
> been removed. All accounts use structured first/middle/last names.
> Staff/admin accounts now own their own credentials (Filament `->profile()`
> and `->passwordReset()`, replacing admin-typed passwords), support
> forced-password-change on admin-issued credentials, can be deactivated
> without being deleted, and have login/logout/failed-login activity
> audited — see "Account ownership and lifecycle" below.
>
> **Approved but NOT yet built (2026-08-07):** patient-submitted **visit
> feedback** — one 1–5 star rating + optional comment per *fulfilled
> appointment*, with the optometrist and the services rendered snapshotted onto
> the record so per-optometrist and per-service averages fall out without asking
> the patient to grade individual line items. Frame ratings (product feedback)
> stay a separate feature answering a different question. Spec, plan, and tasks
> are in `docs/specs/mobile-visit-feedback-{spec,plan,tasks}.md`; no schema,
> route, or model exists yet. Nothing below describes it — this note is the only
> mention until it ships.
>
> **Known drift found while writing that spec (2026-08-07):** `API_CONTRACT.md`
> §14–§15 documented behavior that was never implemented — inert `?filter=`
> parameters on both the quotations and optical-orders endpoints, `is_rateable` /
> `rating` / `product_variant_id` / `is_overdue` fields absent from the optical
> order response, a `payment_summary.status` that returns a display label rather
> than the documented machine-readable value, and a rating endpoint that requires
> a `product_variant_id` the contract said clients would not send. Every instance
> is now flagged inline in that document. Separately, **frame ratings are
> write-only**: collected via `POST /optical-order-items/{id}/rating` but never
> returned by any catalog endpoint, and the rating response leaks staff-only
> moderation fields (`is_hidden`, `moderation_reason`) to the patient. None of
> this is fixed yet.

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
| Quotations | Present, accept/decline, confirm sale | — |
| Optical Orders | Start, mark ready, dispense, cancel, create direct order | — |
| Billing Records | Record payment | Void, correct payment |
| Products | Create, edit, manage variants | Delete/restore |
| Patients | Create, edit | Delete/restore |
| Users | Hidden | Full CRUD, activate/deactivate |
| Audit Logs | Hidden | Read-only |

Every staff/admin account can manage its own credentials via the panel's Profile page (avatar menu, top-right), independent of role — see "Account ownership and lifecycle" below.

---

## Account ownership and lifecycle

Staff/admin accounts used to be entirely admin-managed: an admin typed each user's initial password directly into the Staff Accounts form, and there was no way to disable an account short of deleting it (which is unsafe — see the `users` table note above). Both gaps are closed:

- **Self-service.** The panel exposes `->profile()` (`App\Filament\Pages\Auth\EditProfile`, extending Filament's base to swap the single `name` field for structured `first_name`/`middle_name`/`last_name`) and `->passwordReset()`. Every staff/admin account can change its own name, email, and password, and enrol TOTP MFA, without admin involvement. Changing the password requires the current password (Filament's built-in `currentPassword` field).
- **Password policy.** `Password::defaults()` is configured once in `AppServiceProvider::boot()` (`min(12)->mixedCase()->numbers()` in production, `min(8)` elsewhere) and used by both the Staff Accounts form and the profile page, replacing a bare `minLength(8)`.
- **Forced first-login password change.** When an admin creates a user or resets an existing user's password on their behalf, `must_change_password` is set (`CreateUser::mutateFormDataBeforeCreate()`, and `UserObserver::saving()` for admin-initiated password changes on someone else's account — distinguished from self-service by comparing `auth()->id()` to the record being saved). The `EnsurePasswordIsChanged` middleware (registered in the panel's `authMiddleware`) redirects any such user to the profile page until they change it themselves, while still allowing the profile page and logout so they're never locked out. `password_changed_at` is stamped whenever the password changes.
- **Deactivation, not deletion.** Admins can activate/deactivate any staff/admin account from the Staff Accounts table (`toggleActive` action), guarded so the last active admin cannot be deactivated (mirrors the existing last-admin-demotion guard on role changes). A deactivated account fails `canAccessPanel()` immediately and is excluded from `scopeOptometrists()`, but every record they authored (encounters, prescriptions, provider hours, audit entries) is untouched.
- **Authentication audit trail.** `RecordAuthenticationAudit` (registered in `AppServiceProvider::boot()` via `Event::listen()`) listens to Laravel's `Illuminate\Auth\Events\{Login,Logout,Failed}` and writes `user.logged_in` / `user.logged_out` / `user.login_failed` audit entries, scoped to accounts that can access the admin panel so patient-mobile activity never pollutes this trail. A failed attempt against an *unknown* email writes nothing (only known accounts are audited, to prevent flooding). `last_login_at` is updated on every successful login and shown as a "Last Login" column on Staff Accounts.
- **Password/lifecycle audit events.** `UserObserver` additionally writes `user.password_changed` (on any password change) and `user.deactivated`/`user.reactivated` (on `is_active` transitions), alongside its existing `user.created`/`user.role_changed`.

Deliberately out of scope for now: a formal staff invitation flow (mirroring `PatientInvitation`) and the optometrist-as-credential correction (PRC license number; unifying the `is_optometrist` capability check, which currently has some call sites reading the raw boolean instead of `hasOptometristCapability()`) — both real, both independent of the above.

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
| `users` | Login accounts. Patient mobile login uses a verified phone plus password; email is optional account contact data and is never a mobile login identifier. email + password are nullable for walk-in patients, but the Staff Accounts Filament form requires email (needed for `->passwordReset()`). `is_optometrist` capability flag. `first_name`, `middle_name`, `last_name` are the stored account name fields; `full_name` (and the API compatibility `name` value) is derived in the model. The legacy `name` database column has been removed. `privacy_notice_version`, `privacy_acknowledged_at`. `is_active` (default true) gates `canAccessPanel()` and `scopeOptometrists()` — deactivation, not deletion, since hard-deleting a user would cascade-destroy `provider_hours`/`schedule_overrides` and null `encounters.optometrist_id`/`prescriptions.created_by` history. `must_change_password` (default false) and `password_changed_at` support forced password rotation after an admin sets an account's initial or reset password. `last_login_at` is updated on every successful Filament login. |
| `patient_account_contacts` | Contact methods for patient accounts. `user_id`, `type` (email/phone), encrypted `value`, unique `lookup_hash`, `verified_at`, `is_primary`. Phone is the patient login contact; an optional registration email starts unverified and must be verified through the authenticated contact flow. Unique `(user_id, type)`. |
| `otp_challenges` | Purpose-bound OTP challenges. `public_id`, `user_id`, `purpose` (registration/login_step_up/password_recovery/add_contact/replace_primary_contact/invitation_acceptance), `channel`, encrypted `destination`, `destination_hash`, `code_digest`, `attempts`, `max_attempts`, `expires_at`, `consumed_at`, `invalidated_at`, `delivery_status`. |
| `personal_access_tokens` | Sanctum mobile tokens. Device-labelled, expiring tokens with optional `installation_id` for trusted-device login and same-installation replacement. |
| `patient_link_requests` | Staff-reviewed link attempts. `request_number`, `user_id`, encrypted `identity_snapshot`, `status` (pending/approved/rejected), `reviewed_patient_id`, `reviewer_id`, `decision_note`, `reviewed_at`. |
| `patient_link_candidates` | Staff-only candidate rankings. `link_request_id`, `patient_id`, `match_strength` (strong/moderate/weak), `reason_codes` (JSON), `rank`. |
| `patient_invitations` | Single-use expiring invitations. `public_id`, `patient_id`, `sender_id`, `channel`, encrypted `destination`, `destination_hash`, `secret_digest`, `status` (pending/accepted/expired/revoked/failed), `expires_at`, `sent_at`, `revoked_at`, `accepted_at`, `accepted_by_user_id`. |
| `appointment_requests` | Patient appointment requests. `request_number`, `user_id`, `patient_id`, `appointment_type_id`, `appointment_id` (unique), `scheduled_at`, `provisional_duration_minutes`, encrypted `reason_for_visit`, encrypted `identity_snapshot` for unlinked submissions (phone, optional email, structured name, date of birth, gender, occupation, home address, and server-derived verified-contact metadata), `status` (pending/accepted/rejected/cancelled/expired), `expires_at`, `resolved_by_user_id`, `resolved_at`. |
| `patients` | Independent clinical identity. `patient_number` (PAT-YYYY-NNNNNN), `first_name`, `middle_name`, `last_name`, `full_name` (derived), `date_of_birth`, `occupation`, `address`, `gender`, `contact_email`, `phone`, `contact_email_lookup_hash`, `phone_lookup_hash`. Optional `user_id` link to account. |
| `appointments` | `patient_id`, `appointment_type_id`, `referring_source`, `visit_reason_id`, `appointment_status_id`, `optometrist_id`, `source` (mobile/walk_in/manual), `scheduled_at`, `checked_in_at`, `fulfilled_at`, `cancelled_by`, `cancelled_by_user_id`, `cancellation_reason_category`, `cancellation_reason_details`, `cancelled_at`, `no_show_by`, `no_show_at`, `contact_notes`, `staff_notes`, `reason_for_visit`. |
| `appointment_reschedules` | `appointment_id`, `previous_scheduled_at`, `new_scheduled_at`, `initiated_by` (patient/clinic), `actor_id`, `reason_category`, `reason_details`, `rescheduled_at`, `notified_at`. |
| `patient_intakes` | `patient_id`, `appointment_id`, `status` (draft/submitted/verified), demographics snapshot, encrypted clinical narrative fields (`chief_complaint`, `past_ocular_history`, etc.), `submitted_by`, `verified_by`. |
| `encounters` | `patient_id`, `appointment_id`, `patient_intake_id`, `optometrist_id`, `status` (planned/in_progress/completed/cancelled), encrypted `findings`/`remarks`, encrypted `chief_complaint`/`past_ocular_history`/`past_surgical_history`/`past_medical_history`/`allergies`/`medications`/`plan`, `last_wizard_step`, `draft_saved_at`, `completed_by`. |
| `prescriptions` | `prescription_number` (RX-YYYY-NNNNNN, unique), `patient_id`, `encounter_id`, `appointment_id`, `previous_prescription_id`, `created_by`, encrypted main group (`main_od_value`, `main_od_sphere`, `main_od_cylinder`, `main_os_value`, `main_os_sphere`, `main_os_cylinder`), encrypted ADD group (`add_od_value`, `add_od_sphere`, `add_od_cylinder`, `add_os_value`, `add_os_sphere`, `add_os_cylinder`), encrypted `remarks`, encrypted `amendment_reason`, `prescribed_at`, `deleted_at`. |
| `quotations` | `patient_id`, `encounter_id`, `prescription_id`, `status` (draft/presented/accepted/declined/expired), `valid_until`, `subtotal`, `discount_amount`, `total`, `presented_by`, `presented_at`, `confirmed_by`, `confirmed_at`, `notes`, `eyewear_key` (unique, `eyw_{ULID}`). |
| `quotation_items` | `quotation_id`, `description`, `quantity`, `unit_price`, `amount`, `product_variant_id`, `lens_category_id`, `service_id`, `item_type` (product/service). |
| `services` | Service/exam charge catalog. `name` (unique), `description` (nullable), `price`, `is_active`. Referenced by `quotation_items.service_id` and `billing_record_items.service_id`; inactive services are rejected wherever an item references one. |
| `job_orders` | `patient_id`, `encounter_id`, `prescription_id`, `quotation_id` (unique, nullable), `frame_reservation_id` (unique, nullable), `status` (queued/in_progress/ready_for_dispensing/dispensed/cancelled), `fulfillment_mode` (immediate/prepared), `uses_external_supplier`, `total_amount`, nullable internal `supplier_invoice_number`, `eyewear_key` (unique, `eyw_{ULID}`, copied from quotation on creation). |
| `job_order_items` | `description`, `quantity`, `unit_price`, `amount`, `product_variant_id`, `lens_category_id`, `item_type` (product only for new records). |
| `billing_records` | `patient_id`, `job_order_id` (nullable), `encounter_id` (nullable), `quotation_id` (nullable), `billing_record_number`, `status` (unpaid/partially_paid/paid/voided), `subtotal_amount`, `discount_amount`, `total_amount`, `amount_paid`, `balance_due`, `payment_due_date`, `recorded_by`, `recorded_at`. |
| `billing_record_items` | `billing_record_id`, `item_type` (product/service), `source_kind` (optical_order/quotation/encounter/direct_service), `description`, `quantity`, `unit_price`, `amount`, `job_order_item_id` (nullable), `quotation_item_id` (nullable), `service_id` (nullable), `encounter_id` (nullable). |
| `billing_payments` | `billing_record_id`, `amount`, `payment_method`, `reference_number`, `status` (posted/voided), `recorded_by`, `recorded_at`, `notes`. |
| `dispensing_events` | `job_order_id`, `billing_record_id`, `dispensed_by`, `recipient_name`, `notes`. |
| `frame_reservations` | `patient_id`, `appointment_id` (required, restrict on delete, **unique** — one reservation per appointment, ever), `status` (requested/prepared/tried_on/converted/released/cancelled), `staff_notes`, `expires_at` (null until `Prepared`, then the appointment day's clinic close time). |
| `frame_reservation_items` | `product_variant_id`. |
| `frame_ratings` | `patient_id`, `product_variant_id`, `dispensing_event_id`, `rating` (1-5), `comment`, `is_hidden`, `moderation_reason`, `current_revision_id`. |
| `frame_rating_revisions` | `revision_number`, `rating`, `comment`, `revised_by`. |
| `complaints` | `patient_id`, `original_job_order_id`, `status`, `patient_description`, `resolution_notes`, `new_appointment_id`, `new_encounter_id`. |
| `conversations` | `patient_id` — one per patient. |
| `messages` | `conversation_id`, `sender_id`, `body`, `read_at`. |
| `audit_logs` | `actor_id`, `subject_type`, `subject_id`, `action`, `metadata` (JSON), `ip_address`, `user_agent`. |
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

**Quotations:** `draft → presented → accepted/declined/expired`. Draft and presented are editable. Accepted quotations create job orders. No revisions.

**Optical Orders** (`job_orders` table; `OpticalOrderResource` in Filament): `queued → in_progress → ready_for_dispensing → dispensed` (terminal). `cancelled` is terminal from any active state. Cancellation reverses inventory. `supplier_invoice_number` required only for external prepared work. `fulfillment_mode` (immediate/prepared) determines completion path.

**Billing Records:** `unpaid → partially_paid → paid` (terminal). `voided` is terminal. Payments are append-only with posted/voided status. `job_order_id` and `encounter_id` are nullable; at least one source required. `billing_record_items` stores immutable charge snapshots. `payment_due_date` tracks due dates.

**Frame Reservations:** `requested → prepared → tried_on → converted/released/cancelled`. A reservation is strictly a before-the-visit tool: an appointment gets exactly one, ever (`frame_reservations.appointment_id` is unique at the DB level). Prepared reservations allocate stock and stamp `expires_at` at that day's clinic close time; the `reservations:expire` command (scheduled every 15 minutes in `routes/console.php`) releases any `Prepared` reservation past its `expires_at`, restoring stock. Release restores stock. Staff can add/remove candidate frames on the one reservation up to `tried_on` via `AddFrameReservationItem`/`RemoveFrameReservationItem`, exposed as header/row actions on the `ItemsRelationManager` (FrameReservations resource) and the `FrameReservationItemsRelationManager` (Appointment resource) — both gated by `FrameReservationPolicy`.

---

## Filament Panel

URL: `/admin` — accessible to `staff` and `admin` roles only. `canAccessPanel()` also requires `is_active`, so a deactivated account is blocked regardless of role.

Auth-related panel configuration (`AdminPanelProvider`): custom `->login(Login::class)`, `->profile(EditProfile::class, isSimple: false)`, `->passwordReset()`, and `->multiFactorAuthentication([AppAuthentication::make()], isRequired: app()->isProduction())` (TOTP, required in production, optional and enrollable via the profile page otherwise). `EnsurePasswordIsChanged` runs as panel `authMiddleware` alongside Filament's `Authenticate`.

**Navigation groups (in order), workflow-shaped rather than by data domain:**
- Today — Appointments, Appointment Requests, Availability (cluster)
- Patients — Patient Records, Patient Accounts, Link Requests, Conversations
- Clinical — Encounters, Prescriptions
- Optical — Quotations, Optical Orders, Frame Reservations, Frame Ratings
- Billing — Billing & Payments, Appointments Report
- Catalog — Products, Inventory History, Reorder Report, Brands, Lens Categories, Product Categories, Services
- Admin — Staff Accounts, SMS Log, Audit Logs

Locked in by `tests/Feature/Filament/AdminNavigationStructureTest.php` (group order, item order per group, no orphaned/singleton groups, unique outlined icons).

**Availability cluster** (`app/Filament/Clusters/Availability/`) replaces the old single Availability page. Sub-pages:
- **Clinic Hours** — weekly `clinic_hours` schedule.
- **Optometrist Hours** — per-optometrist `provider_hours` schedule.
- **Schedule Overrides** — one-off `schedule_overrides` (clinic closed / early close / optometrist absence), audit-logged on create/delete; the upcoming-overrides list is a real Filament table (`HasTable`/`InteractsWithTable` on the page), not hand-rolled HTML.

**Patient Record tabs** (`app/Filament/Resources/Patients/RelationManagers/`): Prescriptions, Appointments, **Encounters**, **Optical Orders**, **Billing**, Health Record, Invitation History — all read-only lists with a `ViewAction` linking out to the full resource page. Encounters/Optical Orders reuse the existing `Patient::encounters()`/`jobOrders()` relations; Billing required a new `Patient::billingRecords()` relation.

**Dashboard widgets:**
1. **Stats Overview** — Today's Appointments, Waiting Today, Active Encounters, Quotations Pending, Ready for Dispensing, Low Stock
2. **Today's Schedule** — Next 5 active appointments with patient name, phone, visit reason, status
3. **Appointments Chart** — 30-day trend line

---

## Mobile REST API

Base: `/api/v1` (sole patient-mobile contract)

### Public Authentication (no token required)
```
POST   /api/v1/auth/registration/otp          Request phone registration OTP; owned phone returns 422
POST   /api/v1/auth/registration/verify       Verify OTP, get registration_token
POST   /api/v1/auth/register                  Complete registration with profile
POST   /api/v1/auth/login                     Phone/password login (step-up or trusted token)
POST   /api/v1/auth/login/verify              Verify login OTP, issue token
POST   /api/v1/auth/password-recovery/otp     Request phone recovery OTP
POST   /api/v1/auth/password-recovery/verify  Reset password, issue token
GET    /api/v1/auth/policies                  Get Terms/Privacy versions and URLs
```

Patient authentication rules:
- Registration starts with a phone OTP. The registration form has no phone
  field; the verified phone becomes the primary patient login contact.
- Registration may include an optional email. It is stored as a pending
  contact and is verified after authentication through the contact OTP routes.
  Email is never accepted for patient login or password recovery.
- An already-owned phone is rejected before an OTP is sent with
  `CONTACT_ALREADY_OWNED`. The final registration check also rejects an owned
  phone or optional email without creating an account or issuing a token.
- Login requires the phone password and a login OTP for a new installation.
  A non-expired token carrying the same `installation_id` may skip the OTP;
  omitting the installation ID requires OTP.
- Phone OTP delivery is queued. In `local`/`testing`, the delivery job logs the
  OTP code for development testing; other environments log only the masked
  phone until an SMS provider is configured.

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
GET    /api/v1/frames
GET    /api/v1/frames/{id}
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
GET    /api/v1/frame-reservations
POST   /api/v1/frame-reservations
POST   /api/v1/frame-reservations/{id}/cancel
GET    /api/v1/prescriptions
GET    /api/v1/prescriptions/{id}
GET    /api/v1/quotations
GET    /api/v1/quotations/{id}
GET    /api/v1/optical-orders
GET    /api/v1/optical-orders/{id}
GET    /api/v1/conversation
GET    /api/v1/conversation/messages
POST   /api/v1/conversation/messages
GET    /api/v1/conversation/attachments/{id}
POST   /api/v1/optical-order-items/{id}/rating
```

**Route count:** 8 public + 24 account-only + 19 active-link = **51 routes total.**

Breaking changes from coordinated Android cutover:
- `POST /register` and `POST /login` removed (replaced by two-stage auth/register)
- `GET /appointment-types` removed (internal only)
- Direct `POST /appointments` removed (use appointment requests)
- Three intake routes removed (retired)

All patient-specific clinical resource access is scoped through the authenticated account's linked patient identity. The frame catalog is account-level catalog data; unlinked accounts may browse it but cannot create frame reservations. Patients cannot create job orders, billing records, payments, orders, billings, checkout records, or purchases.

---

## Key Actions (Single-Purpose Workflow Classes)

| Action | Location | Does |
|---|---|---|
| `IssueOtpChallenge` | `app/Actions/Auth/` | Creates encrypted OTP challenge with blind index, invalidates earlier pending, queues delivery |
| `VerifyOtpChallenge` | `app/Actions/Auth/` | Verifies purpose-bound codes under row locks, single consumption |
| `DispatchOtpChallenge` | `app/Actions/Auth/` | Backward-compatible no-op retained alongside queued delivery |
| `RegisterPatientAccount` | `app/Actions/Auth/` | Creates patient-role User with verified phone, optional pending email, and device token; no Patient created |
| `BeginPatientLogin` | `app/Actions/Auth/` | Verifies phone/password, skips OTP for trusted installations, otherwise issues login step-up OTP |
| `IssuePatientDeviceToken` | `app/Actions/Auth/` | Verifies login OTP, binds installation IDs, replaces same-installation tokens, enforces max 5 |
| `RecoverPatientPassword` | `app/Actions/Auth/` | Resets password through verified phone recovery OTP, revokes other tokens, issues device token |
| `NormalizeContact` | `app/Actions/PatientAccounts/` | Deterministic email/phone/name normalization |
| `CreateContactLookupHash` | `app/Actions/PatientAccounts/` | HMAC blind indexes for contact lookups |
| `RankPatientCandidates` | `app/Actions/PatientAccounts/` | Searches clinic data by contact/name/DOB, returns ranked candidates |
| `SubmitPatientLinkRequest` | `app/Actions/PatientAccounts/` | Creates link request with candidates, returns existing on repeat |
| `ReviewPatientLinkRequest` | `app/Actions/PatientAccounts/` | Approve (with row-lock recheck) or reject link request |
| `UnlinkPatientAccount` | `app/Actions/PatientAccounts/` | Revokes tokens, removes link, creates audit log |
| `IssuePatientInvitation` | `app/Actions/PatientAccounts/` | Creates single-use expiring invitation |
| `AcceptPatientInvitation` | `app/Actions/PatientAccounts/` | Verifies OTP, creates/reuses account, activates link |
| `SearchPatientDuplicates` | `app/Actions/Patients/` | Searches by email hash, phone hash, name+DOB |
| `SubmitAppointmentRequest` | `app/Actions/Appointments/` | Creates request with hold, validates slot availability, persists encrypted identity snapshot for unlinked accounts |
| `BuildAppointmentRequestIdentitySnapshot` | `app/Actions/Appointments/` | Builds the expanded encrypted identity snapshot from submitted identity or account fallback, derives the verified phone server-side, and validates any submitted phone against it |
| `CancelAppointmentRequest` | `app/Actions/Appointments/` | Ownership check, status validation |
| `AcceptAppointmentRequest` | `app/Actions/Appointments/` | Creates scheduled appointment, copies reason, idempotent; re-validates availability against the chosen appointment type's real duration before creating it, since the mobile request only ever held a provisional 30-minute block and the real type/duration is picked here |
| `RejectAppointmentRequest` | `app/Actions/Appointments/` | Closes request without creating appointment |
| `ExpireAppointmentRequests` | `app/Actions/Appointments/` | Idempotent scheduled expiry of pending requests |
| `BuildScheduleBlocks` | `app/Actions/Appointments/` | Produces blocks from appointments + request holds |
| `UpdateClinicHours` | `app/Actions/Appointments/` | Updates the weekly `clinic_hours` schedule, audit-logged |
| `UpdateProviderHours` | `app/Actions/Appointments/` | Updates a single optometrist's weekly `provider_hours` schedule, audit-logged |
| `CreateScheduleOverride` | `app/Actions/Appointments/` | Creates a one-off closed/early-close/provider-absence override, audit-logged |
| `DeleteScheduleOverride` | `app/Actions/Appointments/` | Removes a schedule override, audit-logged |
| `ConvertFrameReservationToJobOrder` | `app/Actions/Reservations/` | Transfers reservation allocation to order commitment |
| `CreateFrameReservation` | `app/Actions/Reservations/` | Creates a frame reservation with items for a patient/appointment; used by both the mobile API and the admin "Reserve Frames" action, which works on any scheduled appointment regardless of `source`; rejects a second reservation for an appointment that already has one, ever |
| `AddFrameReservationItem` | `app/Actions/Reservations/` | Adds another candidate frame to an existing `Requested`/`Prepared` reservation; allocates stock immediately if already `Prepared` |
| `RemoveFrameReservationItem` | `app/Actions/Reservations/` | Drops a candidate frame from a `Requested`/`Prepared` reservation, restoring allocated stock if `Prepared`; releases the whole reservation if the last item is removed |
| `MarkFrameReservationTriedOn` | `app/Actions/Reservations/` | Transitions a `Prepared` reservation to `TriedOn` |
| `PrepareFrameReservation` | `app/Actions/Reservations/` | Allocates stock for a `Requested` reservation's items and stamps `expires_at` at the appointment day's clinic close time |
| `ReleaseFrameReservation` | `app/Actions/Reservations/` | Restores allocated stock (if any) and sets a terminal status; accepts a `targetStatus` param (default `Released`) so callers that mean `Cancelled` can request it directly instead of writing `Released` then immediately overwriting it |
| `AcceptAndStartOpticalOrder` | `app/Actions/OpticalOrders/` | Legacy accept-quotation flow (creates Job Order + Billing Record); still covered by tests but no longer reachable from the Filament UI, superseded by `ConfirmQuotationSale` |
| `ConfirmQuotationSale` | `app/Actions/Quotations/` | Current confirm-sale flow used by the Quotation edit page: accepts the quotation, creates an Optical Order from product lines only, copies selected performed service lines into billing, and records an optional deposit — idempotent |
| `CreateDirectOpticalOrder` | `app/Actions/OpticalOrders/` | Creates an Optical Order directly for a patient without a preceding Quotation ("New Direct Order") |
| `CreateQuotation` | `app/Actions/Quotations/` | Creates a quotation for a patient, from an in-progress encounter or, independently, from any current-version prescription (`?Prescription $prescription`); validates `service_id` items against active services |
| `CancelOpticalOrder` | `app/Actions/OpticalOrders/` | Reverses inventory, voids unpaid billing, preserves payments |
| `ResolveOpenCheckoutBillingRecord` | `app/Actions/BillingRecords/` | Resolves or reuses the one open Billing Record for a patient visit (matched by `job_order_id`/`encounter_id`) instead of creating a separate record per charge source |
| `AddEncounterChargesToBilling` | `app/Actions/BillingRecords/` | Adds service-line charges from the Encounter edit page's "Add Service Charge" action to the visit's open Billing Record |
| `AddDirectServiceChargesToBilling` | `app/Actions/BillingRecords/` | Adds service-line charges directly from the Billing Records list, independent of an encounter or optical order |
| `AuditLegacyPatientIntakes` | `app/Actions/Encounters/` | Reports cleanup readiness for legacy intake data |
| `PrunePatientAccountData` | `app/Actions/PatientAccounts/` | Prunes expired OTPs, tokens, invitations, terminal requests |
| `CreateScheduledAppointment` | `app/Actions/Appointments/` | Creates appointment from mobile API with availability checks |
| `VerifyPatientIntake` | `app/Actions/Intakes/` | Records verifier/time, locks snapshot |
| `ProcessPrivacyRequest` | `app/Actions/Privacy/` | Records disposition, no auto-deletion |
| `CreateAuditLog` | `app/Actions/Audit/` | Persists audit entry (actor, subject, action, metadata, ip_address, user_agent — the latter two default from the current request when not passed explicitly) |
| `RecordAuthenticationAudit` | `app/Listeners/` | Listens to `Illuminate\Auth\Events\{Login,Logout,Failed}`, scoped to panel-capable accounts; writes login/logout/failed-login audit entries and updates `last_login_at` |

---

## Soft Deletes and Archive/Restore

Filament's "Delete"/"Restore" labels are renamed to **"Archive"/"Restore"** with `heroicon-o-archive-box` icon. `TrashedFilter` is labeled "Show Archived" with relabeled options.

---

## Important Conventions

- **Phone number format:** All phone numbers are stored in `+63XXXXXXXXXX` format. The `Patient` and `User` models have mutators that automatically normalize phone numbers using `NormalizeContact::phone()`. Filament forms display a non-removable `+63` prefix and store the full formatted value.
- **Appointment source values:** `mobile` (patient books via Android app), `walk_in` (patient physically at clinic), `manual` (staff creates in admin panel). Set automatically — not a user-facing dropdown.
- **Appointment create form:** Patient mode toggle (new/existing). New patient shows full demographic fields. Walk-in toggle hides date/time and auto-sets source/status/checked_in_at. Referring source appears when appointment type is Referral. Notes is a single staff_notes field.
- **Appointment edit form:** Patient is read-only placeholder. Fields editable until checked in (scheduled/checked_in): appointment type, date/time, referring source, notes, optometrist. Status toggle and appointment type share a row. Quick "Assign" action available from list for optometrist assignment.
- **Prescriptions:** No standalone create. An optometrist starts the encounter, then uses **Create Prescription** on that in-progress Encounter page. Patient, appointment, encounter, and author linkage are locked and derived server-side. Finalized prescriptions are read-only and cannot be archived through Filament. An optometrist must use **Amend Prescription**, provide a reason, and create a new linear version through `previous_prescription_id`; the original remains unchanged and is visibly marked superseded. Only the current leaf version can be printed or appears in the patient API list. The reason and clinical fields are encrypted, while the audit log stores only linkage metadata, actor, action, and time. The view page uses a two-column layout: left shows Prescription and ADD sections with placeholders, right shows prescription number, patient info, encounter, optometrist, and date. **Create Quotation** on the view page opens `CreateQuotation` directly against the current-version prescription (`?prescription=` query param), independent of whether its originating encounter is still in progress or already completed — this path has no one-per-prescription cap, unlike the one-per-encounter limit on the encounter-linked creation path.
- **Service catalog:** `Service` (admin-only Filament resource, Catalog group) holds priced, active/inactive clinical or service charges (e.g. exam fees) that aren't tied to a product variant or lens category. Quotation items, the Quotation creation form, the Encounter charge form, and the direct Billing Record charge form all offer a Service picker alongside the existing product/lens-category pickers; an item may reference at most one of the three, and inactive services are rejected at validation time.
- **Edit pages:** Quotations, Billing Records, and Optical Orders have full form schemas showing related items, financial summaries, and timelines. Billing Record items are the record's own immutable `items` snapshot (`BillingRecordItem`, tagged with `source_kind`), not values resolved live from `jobOrder.items`.
- **Encounter "Create Quotation":** The in-progress/completed Encounter edit page also offers **Create Quotation**, opening `CreateQuotation` with `?encounter=` — distinct from the Prescription-page path: it requires the encounter's current prescription to be finalized, and is capped at one quotation per encounter (hidden once one exists). The Prescription-page path has no such cap.
- **Encounter billing:** The Encounter edit page offers **Add Service Charge** (posts service-line charges via `AddEncounterChargesToBilling`) and **View Billing Record**, both resolving to the single open Billing Record for that patient visit via `ResolveOpenCheckoutBillingRecord` — charges added after a Quotation sale is confirmed land on the same record instead of opening a second one.
- **Reserve Frames:** The Appointment edit page offers a staff-initiated **Reserve Frames** action for any scheduled, not-yet-elapsed appointment without an active reservation, regardless of `source` (mobile/walk-in/manual) — reuses `CreateFrameReservation`, the same action the mobile API uses.
- **Patient app invitations:** "Send App Invitation" is phone/SMS only; email is not an invitation delivery channel, since the verified phone is also the account's login contact. In `local`/`testing`, invitation codes are logged for `sail artisan pail` visibility, mirroring OTP delivery.
- **Supplier invoice reference:** `job_orders.supplier_invoice_number` records the supplier's external invoice number only. Staff may enter it while the Job Order is active, and the Mark Ready action requires it. It is clinic-internal, is not part of Billing Records, and is hidden from patient APIs.
- **Walk-in patients:** `users.email` and `users.password` are nullable. Walk-in records have only structured name + phone.
- **Patient address:** Single nullable free-text field. Read-only via mobile API; editable by staff via Patients edit form.
- **Optometrist assignment:** Clinic-controlled. Patients choose clinic time only, not a specific provider.
- **Clinical data encrypted:** Prescription values, intake narrative, encounter findings/remarks use Laravel's `encrypted` cast. Not queryable.
- **`CX` in prescription print:** Binds to cylinder values. Axis is separate. Confirmed by clinic 2026-07-26.
- **Inventory:** `stock_quantity` represents available stock. Preparing a reservation reduces available stock. Dispensing does not deduct again.
- **Legacy tables:** `orders`, `order_items`, `order_statuses`, `billings`, `billing_items`, `billing_statuses`, `discount_types`, `payments`, `service_records` remain in the schema but have no canonical application consumers. They will be removed in a future cleanup migration.
