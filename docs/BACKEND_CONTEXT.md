# POCMS Backend — Context Document

> **Living document.** Update this when schema, routes, roles, status values, or architectural decisions change.
>
> **Reconciliation status as of 2026-08-26.** Patient accounts, two-stage
> phone-OTP registration, phone-primary authentication, contact management,
> patient linking, expanded unlinked appointment-request identity snapshots,
> authenticated step-up for sensitive changes, Optical Orders workflow,
> separate Quotations and Optical Orders sections, unified billing
> with explicit charge provenance, and **Saved Frames** (replacing Frame
> Reservations) have been implemented. The admin sidebar
> was restructured into a workflow-shaped taxonomy (Today, Patients,
> Clinical, Optical, Billing, Catalog, Admin), Availability is now a
> Filament cluster (Clinic Hours, Optometrist Hours, Schedule Overrides,
> Appointment Types — the "Today" resolved-availability sub-page was built
> and then removed), and a Service catalog was added so clinical/service
> charges no longer need a product/lens-category workaround.
> `JobOrderResource` was folded into `OpticalOrderResource` (index + edit
> only; creation happens via `CreateOpticalOrderFromQuotation` or the new
> `CreateDirectOpticalOrder` direct-creation flow). Order creation,
> Encounter service charges, and direct Billing Record charges now share
> one open checkout per patient visit instead of creating duplicate billing
> records per source.

> **Consultation presentation terminology (2026-08-21).** The Filament panel
> presents the backend `Encounter` record as a **Consultation** across clinical,
> appointment, patient, prescription, quotation, billing, dashboard, and print
> surfaces. This is a presentation-only decision: PHP classes, database tables
> and columns, relationships, routes, API keys, audit values, and internal
> action names remain Encounter-based. User-readable validation messages may
> use Consultation, while native Android labels remain a follow-up in the
> separate Android repository.

> **Shipped (2026-08-26): Saved Frames replaces Frame Reservations.**
> Appointment-bound Frame Reservations have been replaced with account-owned
> **Saved Frames** (patient-facing) / **Preferred Frames** (clinic-facing).
> Patients save frame variants as persistent preferences without withholding
> inventory. Three new account-only API routes (`GET /api/v1/saved-frames`,
> `PUT /api/v1/saved-frames/{productVariant}`, and
> `DELETE /api/v1/saved-frames/{productVariant}`) replace the reservation
> routes.
> Frame catalog variants now include an account-specific `is_saved` boolean.
> The Patient Record exposes a full Preferred Frames relation manager;
> Appointment and Consultation edit pages show the latest three saves.
> No save, remove, link, unlink, or viewing operation changes stock or
> creates an inventory movement. The one-time
> `saved-frames:migrate-reservations --execute` command converts any legacy
> rows, releases held stock exactly once, and records historical provenance.
> The `saved_frames` table uses
> `user_id` + `product_variant_id` with a unique constraint. Historical
> `inventory_movements.reservation_id` column and movement-type rows are
> preserved for provenance. Spec/plan/tasks live in
> `docs/specs/saved-frames-replacement-{spec,plan}.md` and
> `tasks/saved-frames-replacement-{plan,todo}.md`.

> **Shipped (2026-08-17): remote frame 3D assets.** Frame variants now have an
> additive, nullable patient-facing `ar` object backed by a versioned
> `ar_assets` table. The legacy `ar_eligible` and `ar_asset_reference` columns
> remain in every existing response for compatibility, but they are not used
> as the Android 3D loading contract. `product_variants.published_ar_asset_id`
> points to the one immutable version currently published for that variant.
>
> Only active staff and administrators can manage 3D assets through the
> variant actions in the Products panel. The workflow is upload to private
> quarantine, server-side GLB/calibration validation, coordinated physical-match
> approval, immutable publication, and optional discard, disablement, or rollback.
> Asset statuses are `quarantined`, `validated`, `approved`, `published`,
> `rejected`, `discarded`, `superseded`, and `disabled`. Replacements receive a new
> version; the prior published file remains available for rollback and remains
> active until the replacement is atomically published. Every lifecycle
> transition is audit-logged with actor and timestamp.
>
> Quarantine files use the private `AR_QUARANTINE_DISK` (default
> `ar_quarantine`); published files use `AR_PUBLISHED_DISK` (default
> `ar_published`). Publishing fails closed unless `AR_ASSET_BASE_URL` is an
> HTTPS public origin. Published URLs are immutable:
> `/ar/variants/{variantId}/v{version}/model.glb`. Uploads accept only GLB,
> have a 10 MiB maximum, reject external resources and unsupported textures,
> cap geometry at 100,000 triangles and textures at 2048×2048, and verify
> checksums and file size before patient exposure. Rejected, quarantined, and
> discarded files are never exposed by the patient API. Discarding an unpublished
> candidate retains its append-only row and audit history, then removes its
> private quarantine object; it cannot remove published history.
>
> **Android integration handoff:** New clients must gate 3D loading on
> `ar.status: ready`, use the variant `images` array for the 2D fallback, and
> apply `calibration.scale`, `anchor`, and `rotation_degrees` in the remote
> renderer. The backend intentionally preserves, but does not repurpose,
> `ar_eligible` or `ar_asset_reference`; a published GLB URL must not be
> written into the legacy reference because older Android fallback code may
> treat it as an image URL.
>
> **Updated (2026-08-24): one-person publication workflow.** Active staff and
> administrators now use one state-aware **Manage 3D model** action from the
> Products → Variants panel. With no pending candidate, one operator uploads a
> `.glb`, records the physical calibration, attests that it matches the
> physical frame, and selects **Validate & publish**. A single quarantined,
> validated, or approved candidate is resumed rather than duplicated; more
> than one actionable candidate is blocked for administrator resolution.
> Calibration is editable for new or quarantined candidates, read-only after
> validation, and prefilled from available variant measurements when no saved
> candidate calibration exists. The reviewed round-frame preset remains an
> explicit choice. The coordinator may record the same authorized actor for
> upload, approval, and publication, while direct approval still keeps its
> separation-of-duties guard; the coordinated approval audit records the
> explicit exception.
>
> The workflow remains server-driven. There is no browser preview, Three.js,
> WebGL runtime, candidate-preview route, or dedicated Admin Studio page; the
> operator compares the model in the clinic's existing modeling tools and
> against the physical frame. Invalid calibration remains quarantined and
> correctable, failed upload transactions clean up private objects, and a
> publication failure leaves the candidate approved and the existing patient
> pointer active for retry. Catalog variant images remain the 2D fallback, and
> checksum, byte size, URL, disk, and version are generated server-side.
>
> The **Discard 3D upload** action is available to active staff/admin users for
> unpublished `quarantined`, `validated`, `approved`, or `rejected` candidates.
> It records an `ar_asset.discarded` audit event, changes the row to
> `discarded`, and removes the private quarantine object. The current published
> model uses **Disable 3D model**; a retained previous version uses **Rollback**.
>
> For a separated-object GLB, the Manage 3D model modal accepts the complete
> transformed rendered width measured at the current scale. The server computes
> `frame_width_mm / measured_rendered_width_mm` and multiplies all three scale
> axes uniformly; it leaves the physical dimensions unchanged and does not
> store the temporary measured-width input. Measurements must include all
> relevant transformed frame objects, and the same correction must not be baked
> into both node transforms and calibration metadata.
>
> **Shipped (2026-08-17): reservation maximum three.** *(Superseded by Saved
> Frames 2026-08-26.)* New frame reservations and item additions were capped at
> three variants per reservation, coordinated with the Android domain constant.
> `CreateFrameReservation` validated 1–3 items; `AddFrameReservationItem`
> rejected additions that would exceed three. `StoreFrameReservationRequest`
> enforced `max:3` at the HTTP layer. Existing reservations containing four or
> five items remained readable and could have items removed; they were never
> truncated automatically. No addition was allowed until the count was below
> three. Frame Reservations have been replaced by Saved Frames.
>
> **Shipped (2026-08-15): direct messaging hardening.** Patient-side
> `messages.read_at` is now written by `POST /conversation/messages/read`
> (bulk mark-read), so `unread_count` works for the first time. Staff side
> uses a clinic-wide `conversations.staff_last_read_at` watermark stamped
> on thread selection — the patient/staff asymmetry is deliberate. The
> inbox orders by latest message, hides archived threads by default with a
> toggle, and shows a navigation badge for unread conversations. Inbox
> archiving is wired end to end (archive, restore, auto-restore on new
> message). `MessageResource` now returns `sender_type` and
> `attachments[].download_url`; the `contexts` field and
> `message_context_links` table are removed. The send throttle is
> 10 requests/minute. Notification feed routes (`GET /notifications`,
> `GET /notifications/unread-count`, `PATCH /notifications/{notification}/read`,
> `PATCH /notifications/read-all`) are wired. Both parties are notified
> of new messages (`NewMessageReceived`); deactivated staff are excluded.
> `GET /conversation/messages/search?q=` provides conversation-scoped MySQL
> FULLTEXT message search. `GET /conversation/messages` and the search endpoint
> are cursor-paginated newest-first with stable `(created_at, id)` ordering
> (page size 50, `next_cursor`/`has_more`). Eight
> unreachable Filament classes under `Conversations/` are deleted.
>
> **Enhanced (2026-08-18): staff chat capabilities.** The Filament conversation
> chat page now supports message search (MySQL FULLTEXT via the existing search
> infrastructure) and file attachment uploads (PDF, PNG, JPG, DOC, DOCX).
> Staff can send attachments without a body message — the body is set to
> `"attachment"` and the existing rendering hides the placeholder text when
> attachments are present. Attachments are stored using the same path as
> patient uploads (`attachments` directory on the `local` disk).

> **Shipped (2026-08-14): unified quotation, optical-order, and billing
> entry points.** Prescription eyewear has a dedicated builder in both the
> Quotation and New Direct Optical Order forms. The eyewear mode is form
> state only: frame, lens package, and lens-option selections are normalized
> into the existing quotation/order item arrays, so `item_kind` and immutable
> catalog snapshots remain the source of truth. Enabling the mode requires a
> current, non-voided patient prescription; a lens package is required, a
> frame is optional (catalog or patient-supplied), and lens options are
> optional and unique. Draft quotations can be revised through the shared
> wide builder; accepted and declined quotations are read-only.
>
> Quotation confirmation and direct order creation explicitly capture
> `fulfillment_mode` (`prepared` is the default; `immediate` is for items
> ready to dispense), external-supplier use, payment due date, and optional
> initial deposit. Confirmation is atomic: product quotations create an
> Optical Order and Billing Record, service-only quotations create billing
> only, and mixed quotations append selected service lines to the same bill.
> The short Add Service Charge modal is shared by Billing Records and
> Encounters; catalog services keep their description and price locked while
> custom lines allow both to be edited.
>
> Catalog lifecycle is now consistently Active/Inactive for brands, product
> categories, products, variants, lens packages, lens options, and services;
> Appointment Types retain an active toggle and have no destructive UI
> action. Admin tables expose Active, Inactive, and All filters and
> Activate/Deactivate actions. Inactive catalog records are excluded from
> new selectors while historical snapshots remain unchanged. There are no
> user-facing Archive/Restore/Delete actions; the lifecycle service only
> permits permanent deletion of never-referenced catalog records.
>
> Patient-link approval now backfills previously unlinked appointment
> requests for that account. Unlinking clears the patient link only from
> pending requests so they return to identity resolution; terminal requests
> retain their historical clinical patient.
>
> **Shipped (2026-08-14): mobile clinic-hours catalog.** The account-only
> `GET /api/v1/clinic-hours` endpoint returns all seven weekly
> `clinic_hours` rows in Carbon/DB weekday order (`0 = Sunday`
> through `6 = Saturday`). It exposes patient-safe `day_name`,
> `enabled`, and local `HH:mm` wall-clock ranges; disabled days
> retain both time keys as `null`. It uses the standard 120
> requests/minute account-only rate limit and does not require an active
> patient link.
>
> **Shipped (2026-08-12): simplified frame reservations.** *(Superseded by
> Saved Frames 2026-08-26.)* Frame reservations collapsed to two states carried
> by one nullable `accepted_at` timestamp: a request holds nothing, an accepted
> reservation holds exactly one unit per frame. The `ReservationStatus` enum,
> try-on, conversion to a sale, closure reasons, reactivation, release
> attribution, stored deadline, and every link between a reservation and a
> Quotation or Optical Order were deleted rather than migrated. Five actions
> plus one stock collaborator: `CreateFrameReservation`,
> `AcceptFrameReservation`, `AddFrameReservationItem`,
> `RemoveFrameReservationItem`, `DeleteFrameReservation`,
> `FrameReservationStock`. Acceptance was one-way (deletion is the release),
> bounded by a seven-day window, and idempotent. The sweep derived expiry from
> clinic close on the appointment date. The patient API used `DELETE` instead
> of cancel, returned `is_held` and derived `expires_at`, and never exposed
> `status` or `accepted_at`. New reservations accepted a maximum of three frame
> variants (changed from five on 2026-08-17 to support the 3D pilot). Filament
> showed two tabs (Awaiting acceptance / Set aside), an accept action, a
> release action, and add/remove frame — no other lifecycle controls remained.
> Spec/plan/tasks lived in
> `docs/specs/frame-reservation-simplification-{spec,plan}.md` and
> `tasks/frame-reservation-simplification-{plan,todo}.md`. The Patient record
> gained Encounters, Optical Orders, and Billing tabs alongside the existing
> Prescriptions/Appointments/Health Record/Invitation History ones, so staff no
> longer have to leave the patient page to see commercial history. Patient
> app-invitation delivery is phone/SMS only (email invitation delivery was
> removed) so the invitation-acceptance trust anchor matches the verified login
> contact. Frame Reservations have been replaced by Saved Frames.
>
> **Shipped (2026-08-13): commerce model simplification.** The commercial
> item model was simplified around a single `item_kind` classification.
> Removed: `item_type` column from `quotation_items`, `job_order_items`,
> `billing_record_items`; `TransactionItemType` enum; `inventory_lots` table
> and `InventoryLot` model; `inventory_movements.inventory_lot_id`;
> `products.lens_category_id`; `eyewear_key` from `quotations` and
> `job_orders`; `presented_by`/`presented_at` from `quotations`;
> `current_revision_id` from `frame_ratings` and `visit_ratings`;
> `frame_rating_revisions` and `visit_rating_revisions` tables;
> `QuotationStatus::Presented` and `QuotationStatus::Expired`;
> `PresentQuotation` action; five billing append actions replaced by
> `AddChargesToBilling`; five order creation actions replaced by
> `CreateOpticalOrderFromQuotation` and `CreateDirectOpticalOrder`;
> `POST /api/v1/job-order-items/{id}/rating` alias; and
> `GET /api/v1/quotations` / `GET /api/v1/quotations/{id}` endpoints.
> `QuotationStatus` now has three cases: Draft, Accepted, Declined.
> Order creation uses two paths; billing uses one append path keyed by
> `BillingItemSourceKind`. Ratings update in place with no revision history.
> `products` table no longer has `lens_category_id`; permitted product types
> are `frame`, `contact_lens`, `accessory`. The commerce reconciliation reduced
> the route count from 55 to 54; subsequent messaging hardening brought the
> current total to 61.
>
> **Shipped (2026-08-11): resilient patient invitation linking.** Invitation
> acceptance is bound to the authenticated account's verified invited contact,
> verifies the OTP challenge and request IP inside the same transaction, and
> locks the invitation, challenge, patient, contact, and account rows before
> linking. The operation is idempotent for retries from the same account after
> a successful link, so the original Sanctum token remains usable for
> `GET /api/v1/me` and reports `link_status: linked`. Rate-limit responses are
> JSON-safe and machine-readable (`OTP_RATE_LIMIT_REACHED`,
> `INVITATION_RATE_LIMIT_REACHED`, or `API_RATE_LIMIT_REACHED`) with a
> `Retry-After` header; rate-limit events record route, account, IP, and retry
> metadata without logging bearer tokens or OTP values.

**Shipped (2026-08-11): admin workflow surfaces aligned with the workflow specs.**
The Filament entry points now expose the operational context required by the
appointment scheduling, encounter, and optical commerce workflows while
keeping the domain actions as the server-side source of truth:

- **Appointment requests and scheduling.** Request review shows the patient
  and internal appointment-type labels, provisional duration, referral
  context, all submitted time alternatives, request age, and overdue state.
  Acceptance and staff-created or edited appointments revalidate active
  appointment types, 5-minute duration bounds, referral requirements, and
  schedule-grid availability. A final accepted time outside the submitted
  preferences requires a contact note. Provider assignment and consultation
  start use the actor/self-claim rules; rescheduling controls use the
  15-minute start grid.
- **Encounters and prescriptions.** Encounter list and edit actions enforce
  planned/in-progress ownership and role boundaries through the assignment
  and start actions. Optometrists can reach the quotation flow and add
  post-completion supplements where permitted. Prescription creation and
  amendment pages show read-only patient/appointment context, preserve
  server-derived ownership, and render finalized remarks on the read-only
  view.
- **Quotations, Optical Orders, and billing.** Quotation review shows the
  corrective-eyewear configuration and prescription/version context; the
  discount control is visibly admin-only. Optical Order pages show immutable
  product and lens-option snapshots, eyewear-specification state, prescription
  attribution, billing totals, amount paid, balance, and due date. Dispensing
  exposes the administrator-only outstanding-balance override with a reason
  and due date. The first payment requires explicit review of the current
  immutable charge set, and service-charge entry supports either a catalog
  Service or a custom line.
- **Patient and preferred-frame entry points.** Patient records expose a direct
  Create Quotation action and a read-only Preferred Frames relation manager;
  appointment and consultation pages show the latest three preferences. All
  appointment actions use the same policy abilities as their underlying domain
  operations.

These UI safeguards are additive to the action-level validation and do not
replace it; hidden or tampered Filament fields remain subject to the same
server-side rules.

> **Shipped (2026-08-10): practical optical commerce and dispensing.**
> The implemented commercial workflow now supports real small-clinic optical
> operations. Key additions:
>
> - **Stable item classification.** `CommercialItemKind` enum (`frame`,
>   `lens_package`, `lens_option`, `contact_lens`, `accessory`,
>   `custom_product`, `service`) is persisted on `quotation_items` and
>   `job_order_items` alongside nullable JSON `item_snapshot` for immutable
>   catalog data. `BuildQuotationItemSnapshot` derives kinds from controlled
>   catalog selections; custom lines require explicit kind.
>
> - **Optical quotation shape.** `ValidateOpticalQuotation` enforces
>   single-build structure: exactly one lens package, at most one frame,
>   lens options require a package. Corrective eyewear requires a current
>   Patient-owned Prescription at confirmation. Contact-lens-only and
>   non-corrective quotations do not require the spectacle Prescription.

> - **Reservation-backed quotations.** *(Historical — Frame Reservations
> superseded by Saved Frames 2026-08-26.)* `quotations.frame_reservation_id`
> was a nullable source reference, not a second sale entity or item-status
> table. The selected reserved frame was the quotation's one catalog-backed
> `Frame` line, with the normal catalog description, price, and immutable
> item snapshot. Creation and revision selectors only offered individual
> items whose reservation belonged to the quotation patient, was `requested`,
> `prepared`, or `tried_on`, had an active frame variant, and was not already
> linked to an Optical Order. Removing the Frame line or changing it away
> from the selected candidate cleared the source or failed validation rather
> than leaving an inconsistent quotation.

> - **Reservation conversion inventory.** *(Historical — Frame Reservations
> superseded by Saved Frames 2026-08-26.)* A `prepared` or `tried_on`
> reservation released every reserved candidate before the normal Optical
> Order commitment ran. The quoted frame was then committed once, while
> each unselected candidate was returned once. A `requested` reservation had
> no allocation-release movement; the quoted product lines were committed
> once by the normal order path. `FrameReservationItem` rows were never
> deleted during conversion.
>
> - **Eyewear specification.** `job_order_eyewear_specifications` table
>   stores one-to-one dispensing data per corrective Optical Order:
>   prescription reference, frame source (catalog/patient-supplied), lens
>   construction snapshots, encrypted dispensing measurements (PD, heights),
>   lab instructions, optometrist approval, and verification attribution.
>   Created as an empty shell at corrective confirmation; staff saves
>   measurements; optometrist approves; editing clears approval.
>
> - **Fulfillment gates.** Corrective orders cannot enter Processing
>   without an approved specification. Ready for Pickup requires completed
>   verification and, for external work, the supplier/lab reference.
>   Non-corrective and immediate orders skip these stages.
>
> - **Discount authorization.** Only admin or dual-role owner can apply
>   or change a nonzero discount. Staff and optometrist accounts are
>   rejected at the action boundary.
>
> - **Payment hardening.** Overpayments are rejected (balance no longer
>   clamps to zero). Zero, negative, and over-balance payments fail under
>   the Billing Record row lock. First posted payment locks the charge set.
>   Corrections remain append-only (reverse + replace).
>
> - **Dispensing balance policy.** Routine dispensing requires zero balance.
>   A pickup payment is prevalidated and committed atomically with the
>   Dispensing Event. Admin may release with a remaining balance only with
>   a nonblank reason and a current/future payment due date. The Dispensing
>   Event snapshots remaining balance, override actor, reason, and due date.
>
> - **Contact-lens parameter validation.** `ContactLensAttributeValidator`
>   enforces canonical keys (power, base_curve, diameter, cylinder, axis,
>   add, color, pack_size) with range validation. Confirmed snapshots retain
>   only applicable parameters.
>
> Spec/plan/tasks live in `docs/specs/optical-commerce-and-dispensing-{spec,plan,tasks}.md`.
>
> **Shipped (2026-08-09): variable-duration appointment scheduling.**
> Patients now select an active, patient-visible appointment type before
> choosing time preferences. Availability uses that type's duration on a
> 15-minute start grid. Pending requests are non-binding and never consume
> capacity. Acceptance atomically creates one conflict-free appointment
> with a final provider, start time, type, and duration snapshot. Six
> canonical types are seeded; the API exposes patient-facing labels: New Patient
> → First eye examination (45m), Follow-up → Follow-up requested by the
> optometrist (15m), Routine Check-up → Regular eye examination (30m),
> Problem/Urgent Visit → New or worsening eye concern (30m), Contact Lens
> Consultation → Contact lens consultation (45m), and Referral → Referral
> (45m, requires referral source). At that point in the 2026-08-09 rollout,
> the API contract included 54 routes (8 public, 29 account-only, 17
> active-link). New
> endpoints: `GET /appointment-types` (restored, patient-visible catalog),
> `GET /appointment-optometrists` (patient-safe provider catalog). Modified
> endpoints: `GET /appointment-request-availability` (now requires
> `appointment_type_id`), `POST
> /appointment-requests` (now requires `appointment_type_id`, accepts
> alternatives, conditional referral source). Reschedule availability via
> `GET /appointment-availability` accepts `appointment_id` without requiring
> `appointment_type_id`; it scopes the appointment to the authenticated patient,
> derives the type and duration server-side, and always returns the resolved
> `appointment_type_id`. When no appointment ID is supplied, an active,
> patient-visible `appointment_type_id` remains required. The
> admin Availability cluster gained an Appointment Types resource for
> type configuration (admin-only). Staff appointment forms now expose an
> editable duration field with 5-minute increments. Request acceptance
> requires final provider assignment under schedule-date lock with
> deadlock retries.
>
> **Shipped (2026-08-09): practical clinical encounter workflow.**
> Refactored encounter lifecycle into a provider-owned, four-step
> autosaving wizard (History, Examination, Assessment & Plan, Review &
> Complete). Check-in no longer attaches PatientIntake; copies assigned
> provider and prefills chief complaint from appointment reason. Start
> uses self-claim pattern (actor becomes provider when unassigned).
> `EncounterPolicy` enforces the full role matrix. Completion requires
> `chief_complaint`, `findings`, `assessment`, and `plan`; only the
> assigned active optometrist can complete. Optional prescription
> finalizes atomically in the same transaction. New columns: encrypted
> `assessment` and `supporting_test_results` on `encounters`. New table:
> `encounter_addenda` for immutable post-completion corrections
> (original author only) and supplements (any active optometrist).
> Print route at `GET /encounters/{id}/print` with audit logging.
> New actions: `SaveEncounterDraft`, `AssignEncounterOptometrist`,
> `TransferEncounter`, `CreateEncounterAddendum`.
> Spec/plan/tasks live in `docs/specs/encounter-workflow-{spec,plan,tasks}.md`.
>
> **Shipped (2026-08-07): patient-submitted visit feedback.** One 1–5 star
> rating + optional comment per *fulfilled appointment*, with the optometrist
> and the services rendered snapshotted onto the record at submission time so
> per-optometrist and per-service averages fall out without asking the patient
> to grade individual line items. Frame ratings (product feedback) remain a
> separate feature answering a different question. `POST
> /api/v1/appointments/{appointment}/rating`, `visit_ratings` table,
> `SaveVisitRating`/`ModerateVisitRating`
> actions, and the "Visit Feedback" Filament resource are all live — see below
> for each. Spec/plan/tasks live in
> `docs/specs/mobile-visit-feedback-{spec,plan,tasks}.md` for the design
> rationale, but the tasks file's checkboxes are stale (unchecked despite the
> work landing) as of this note.
>
> **Also shipped (2026-08-07): the frame-rating read-path drift found while
> building the above.** `?filter=` now works on both quotations and
> optical-orders; `items[].product_variant_id`/`is_rateable`/`rating` and
> `payment_summary.is_overdue` are present on optical orders;
> `payment_summary.status` returns the machine-readable enum value, not the
> display label; and `POST /optical-order-items/{id}/rating` returns a
> sanitized `FrameRatingResource` instead of leaking `is_hidden`/
> `moderation_reason`. `API_CONTRACT.md`'s drift markers for all of these are
> cleared. `GET /frames` and `GET /frames/{id}` also now surface
> `average_rating`/`rating_count` per product (tasks file's "Task 0d",
> shipped in `5dcf292`) — corrected here 2026-08-07 after this note wrongly
> called that surface still write-only.
>
> **Historical issue, fixed 2026-08-13:** hidden ratings were excluded from
> both the average and the count — `FrameController` eager-loaded
> `ratings` filtered to `where('is_hidden', false)`, so a moderated rating's
> star value vanished from the aggregate entirely. As part of the commerce
> model simplification (Task 15), the aggregate now includes every rating;
> only comment text is suppressed for hidden rows.
>
> Separately, `docs/gap-analysis.md` §J still describes only the frame-rating
> workflow and hasn't been updated to mention visit feedback as a second, now-
> shipped, feedback channel.

---

## What This Is

Laravel 13 backend for the Padilla Optical Clinic Management System. Serves two clients:
- **Filament admin panel** (`/admin`) — staff and admin web UI
- **Android mobile app** — patient-facing, consumes the `/api/v1` REST API

---

## Branding

| Element | Value |
|---|---|
| App name | EyeCare |
| Clinic name | Padilla Optical Clinic |
| Primary color | `#4F8DD7` (use in both web panel and mobile app) |
| Panel font | Instrument Sans (400/500/600) |
| Logo | Biconvex lens/eye mark + "EyeCare" wordmark — see `resources/views/filament/admin/logo.blade.php` |
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

Four fixed system roles. Three are panel roles; one is mobile-only. No dynamic permission management.

| Role | Account surface | Meaning |
|---|---|---|
| `admin` | Filament panel | Administrative and privileged authority; never implicitly clinical |
| `optometrist` | Filament panel | Clinical authority plus shared clinic operations |
| `staff` | Filament panel | Shared non-clinical clinic operations |
| `patient` | Mobile API | Patient account behavior; never panel access |

Users may hold multiple panel roles. The supported combinations are: `admin`, `optometrist`, `staff`, and `admin + optometrist`. Redundant combinations (`admin + staff`, `optometrist + staff`) and cross-boundary combinations (any panel role + `patient`) are rejected.

Role enforcement: `canAccessPanel()` on `User` model checks for at least one panel role; policies and action-level checks use `hasRole()`, `isAdmin()`, `isOptometrist()`, `isStaff()`, `isPatient()`, and `hasPanelRole()`. There is no approved staff-only mobile API route group.

### Permission matrix

| Area | Staff | Optometrist | Admin |
|---|---|---|---|
| Panel login and own profile/MFA | Yes | Yes | Yes |
| Appointments: view, create, check-in, reschedule, cancel, no-show | Yes | Yes | Yes |
| Appointments: bulk cancellation | No | No | Yes |
| Encounters: view | Yes | Yes | Yes |
| Encounters: assign planned | Yes | Yes | Yes |
| Encounters: start (assigned/self-claim) | No | Yes | No |
| Encounters: edit in-progress draft | No | Assigned only | No |
| Encounters: complete | No | Assigned only | No |
| Encounters: transfer as provider | No | Yes | No |
| Encounters: transfer as admin | No | No | Yes |
| Encounters: add correction | No | Original completer | No |
| Encounters: add supplement | No | Yes | No |
| Encounters: void | No | Yes | Yes |
| Encounters: print | Yes | Yes | Yes |
| Prescriptions: view | Yes | Yes | Yes |
| Prescriptions: create, finalize, and amend | No | Yes | No |
| Prescriptions: void | No | Yes | No |
| Quotations: create, revise, decide, confirm sale | Yes | Yes | Yes |
| Quotations: apply or change nonzero discount | No | No | Yes |
| Optical Orders: create and advance operational workflow | Yes | Yes | Yes |
| Optical Orders: prepare eyewear specification | Yes | Yes | Yes |
| Optical Orders: approve corrective-eyewear specification | No | Yes | No, unless also optometrist |
| Optical Orders: verify completed eyewear | Yes | Yes | Yes |
| Billing: view and record payment | Yes | Yes | Yes |
| Billing: void/correct payment | No | No | Yes |
| Billing: release with outstanding balance | No | No | Yes |
| Inventory: receive stock | Yes | Yes | Yes |
| Patients: create and edit | Yes | Yes | Yes |
| Patients: archive (duplicate/erroneous/deceased) | No | No | Yes |
| Catalog (brands/categories/products): archive and restore | No | No | Yes |
| Catalog: create, edit, and manage variants | Yes | Yes | Yes |
| Catalog: upload, approve, publish, discard unpublished, disable, and rollback frame 3D assets | Yes | No | Yes |
| Team accounts and role assignments | No | No | Yes |
| Audit logs | Yes (view) | Yes (view) | Yes |
| Privacy administration | Backend only — no panel UI | Backend only — no panel UI | Backend only — no panel UI |
| Clinic-wide availability configuration | No | No | Yes |
| Appointment type configuration | No | No | Yes |
| Own provider hours and own provider absences | No | Yes | No |
| Any provider's hours/absence overrides | No | No | Yes |

Every panel account can manage its own credentials via the panel's Profile page (avatar menu, top-right), independent of role — see "Account ownership and lifecycle" below.

---

## Account ownership and lifecycle

Staff/admin accounts used to be entirely admin-managed: an admin typed each user's initial password directly into the Staff Accounts form, and there was no way to disable an account short of deleting it (which is unsafe — see the `users` table note above). Both gaps are closed:

- **Self-service.** The panel exposes `->profile()` (`App\Filament\Pages\Auth\EditProfile`, extending Filament's base to swap the single `name` field for structured `first_name`/`middle_name`/`last_name`) and `->passwordReset()`. Every staff/admin account can change its own name, email, and password, and enrol TOTP MFA, without admin involvement. Changing the password requires the current password (Filament's built-in `currentPassword` field).
- **Password policy.** `Password::defaults()` is configured once in `AppServiceProvider::boot()` (`min(12)->mixedCase()->numbers()` in production, `min(8)` elsewhere) and used by both the Staff Accounts form and the profile page, replacing a bare `minLength(8)`.
- **Forced first-login password change.** When an admin creates a user or resets an existing user's password on their behalf, `must_change_password` is set (`CreateUser::mutateFormDataBeforeCreate()`, and `UserObserver::saving()` for admin-initiated password changes on someone else's account — distinguished from self-service by comparing `auth()->id()` to the record being saved). The `EnsurePasswordIsChanged` middleware (registered in the panel's `authMiddleware`) redirects any such user to the profile page until they change it themselves, while still allowing the profile page and logout so they're never locked out. `password_changed_at` is stamped whenever the password changes.
- **Deactivation, not deletion.** Admins can activate/deactivate any staff/admin account from the Staff Accounts table (`toggleActive` action), guarded so the last active admin cannot be deactivated (mirrors the existing last-admin-demotion guard on role changes). A deactivated account fails `canAccessPanel()` immediately and is excluded from `scopeOptometrists()`, but every record they authored (encounters, prescriptions, provider hours, audit entries) is untouched.
- **Authentication audit trail.** `RecordAuthenticationAudit` (registered in `AppServiceProvider::boot()` via `Event::listen()`) listens to Laravel's `Illuminate\Auth\Events\{Login,Logout,Failed}` and writes `user.logged_in` / `user.logged_out` / `user.login_failed` audit entries, scoped to accounts that can access the admin panel so patient-mobile activity never pollutes this trail. A failed attempt against an *unknown* email writes nothing (only known accounts are audited, to prevent flooding). `last_login_at` is updated on every successful login and shown as a "Last Login" column on Staff Accounts.
- **Password/lifecycle audit events.** `UserObserver` additionally writes `user.password_changed` (on any password change) and `user.deactivated`/`user.reactivated` (on `is_active` transitions), alongside its existing `user.created`/`user.role_changed`.

Deliberately out of scope for now: a formal staff invitation flow (mirroring `PatientInvitation`) and professional credential capture (PRC license number) — both real, both independent of the above.

---

## Demo Accounts

Seeded by `DemoUserSeeder`. All passwords: `password`

| Role | Email | Notes |
|---|---|---|
| Admin + Optometrist | admin@eyecare.test | Dr. Maria Santos — dual-role owner |
| Optometrist | staff@eyecare.test | Dr. Juan dela Cruz — sole clinician |
| Patient (linked) | customer@eyecare.test | Ana Reyes |
| Patient (walk-in) | — | Pedro Cruz, no account |

---

## Database: Key Tables

### Lookup / Status Tables (seeded, rarely changed)

| Table | Values |
|---|---|
| `roles` | admin, optometrist, staff, patient |
| `appointment_statuses` | scheduled, checked_in, fulfilled, cancelled, no_show |
| `appointment_types` | New Patient (45m), Follow-up (15m), Routine Check-up (30m), Problem/Urgent Visit (30m), Contact Lens Consultation (45m), Referral (45m, requires referral). Added `patient_label`, `patient_description`, `is_patient_visible` for mobile catalog. |
| `inventory_movement_types` | restock, manual_adjustment, reservation_allocation (historical), reservation_release (historical), order_commitment, order_reversal, damaged |
| `payment_methods` | Cash, GCash, Bank Transfer, Credit Card, Check |
| `notification_statuses` | queued, sent, failed, cancelled |

### Business Tables

| Table | Notes |
|---|---|
| `users` | Login accounts. Patient mobile login uses a verified phone plus password; email is optional account contact data and is never a mobile login identifier. email + password are nullable for walk-in patients, but the Staff Accounts Filament form requires email (needed for `->passwordReset()`). `first_name`, `middle_name`, `last_name` are the stored account name fields; `full_name` (and the API compatibility `name` value) is derived in the model. The legacy `name` database column has been removed. `privacy_notice_version`, `privacy_acknowledged_at`. `is_active` (default true) gates `canAccessPanel()` and `scopeOptometrists()` — deactivation, not deletion, since hard-deleting a user would cascade-destroy `provider_hours`/`schedule_overrides` and null `encounters.optometrist_id`/`prescriptions.created_by` history. `must_change_password` (default false) and `password_changed_at` support forced password rotation after an admin sets an account's initial or reset password. `last_login_at` is updated on every successful Filament login. |
| `role_user` | Many-to-many pivot between `users` and `roles`. Unique `(role_id, user_id)`. Supports multi-role assignments: `admin + optometrist` for dual-duty accounts. |
| `patient_account_contacts` | Contact methods for patient accounts. `user_id`, `type` (email/phone), encrypted `value`, unique `lookup_hash`, `verified_at`, `is_primary`. Phone is the patient login contact; an optional registration email starts unverified and must be verified through the authenticated contact flow. Unique `(user_id, type)`. |
| `otp_challenges` | Purpose-bound OTP challenges. `public_id`, `user_id`, `purpose` (registration/login_step_up/password_recovery/add_contact/replace_primary_contact/invitation_acceptance), `channel`, encrypted `destination`, `destination_hash`, `code_digest`, `attempts`, `max_attempts`, `expires_at`, `consumed_at`, `invalidated_at`, `delivery_status`. |
| `personal_access_tokens` | Sanctum mobile tokens. Device-labelled, expiring tokens with optional `installation_id` for trusted-device login and same-installation replacement. |
| `patient_link_requests` | Staff-reviewed link attempts. `request_number`, `user_id`, encrypted `identity_snapshot`, `status` (pending/approved/rejected), `reviewed_patient_id`, `reviewer_id`, `decision_note`, `reviewed_at`. |
| `patient_link_candidates` | Staff-only candidate rankings. `link_request_id`, `patient_id`, `match_strength` (strong/moderate/weak), `reason_codes` (JSON), `rank`. |
| `patient_invitations` | Single-use expiring invitations. `public_id`, `patient_id`, `sender_id`, `channel`, encrypted `destination`, `destination_hash`, `secret_digest`, `status` (pending/accepted/expired/revoked/failed), `expires_at`, `sent_at`, `revoked_at`, `accepted_at`, `accepted_by_user_id`. |
| `appointment_requests` | Patient appointment requests. `request_number`, `user_id`, `patient_id`, `appointment_type_id` (required for new requests, nullable for legacy), `appointment_id` (unique), `scheduled_at` (primary preference), `alternative_scheduled_times` (nullable JSON array, max 2 ordered alternatives), `provisional_duration_minutes` (snapshot from type), `encrypted_reason_for_visit`, `encrypted_referring_source` (nullable, required when type requires referral), `encrypted_identity_snapshot` for unlinked submissions (phone, optional email, structured name, date of birth, gender, occupation, home address, and server-derived verified-contact metadata), `status` (pending/accepted/rejected/cancelled/expired), `expires_at` (latest preference time for new requests), `resolved_by_user_id`, `resolved_at`, `rejection_reason` (nullable text, populated when status is rejected). Pending requests are non-binding and never consume capacity. Approving a Patient Link Request backfills `patient_id` on the account's previously unlinked requests without changing their encrypted snapshot. Unlinking clears `patient_id` only on pending requests; terminal requests retain their historical patient link. Deferred: `preferred_optometrist_id`, `review_due_at`. |
| `patients` | Independent clinical identity. `patient_number` (PAT-YYYY-NNNNNN), `first_name`, `middle_name`, `last_name`, `full_name` (derived), `date_of_birth`, `occupation`, `address`, `gender`, `contact_email`, `phone`, `contact_email_lookup_hash`, `phone_lookup_hash`. Optional `user_id` link to account. |
| `appointments` | `patient_id`, `appointment_type_id`, `referring_source`, `visit_reason_id`, `appointment_status_id`, `optometrist_id`, `source` (mobile/walk_in/manual), `scheduled_at`, `checked_in_at`, `fulfilled_at`, `cancelled_by`, `cancelled_by_user_id`, `cancellation_reason_category`, `cancellation_reason_details`, `cancelled_at`, `no_show_by`, `no_show_at`, `contact_notes`, `staff_notes`, `reason_for_visit`. |
| `appointment_reschedules` | `appointment_id`, `previous_scheduled_at`, `new_scheduled_at`, `initiated_by` (patient/clinic), `actor_id`, `reason_category`, `reason_details`, `rescheduled_at`, `notified_at`. |
| `encounters` | `patient_id`, `appointment_id`, `optometrist_id`, `status` (planned/in_progress/completed/cancelled/voided), encrypted `findings`/`remarks`/`assessment`/`supporting_test_results`, encrypted `chief_complaint`/`past_ocular_history`/`past_surgical_history`/`past_medical_history`/`allergies`/`medications`/`plan`, `last_wizard_step`, `draft_saved_at`, `prescription_draft` (JSON), `completed_by`, `voided_by` (nullable FK users), `voided_at`, encrypted `void_reason`. Check-in no longer attaches PatientIntake. Assigned provider is synchronized with Appointment. |
| `encounter_addenda` | Append-only post-completion notes. `encounter_id` (FK, restrict delete), `sequence_number` (unique per encounter), `type` (correction/supplement), encrypted `reason`/`content`, `authored_by` (FK, restrict delete), `authored_at`. No `updated_at`, no soft deletes, no edit/delete actions. |
| `prescriptions` | `prescription_number` (RX-YYYY-NNNNNN, unique), `patient_id`, `encounter_id`, `appointment_id`, `previous_prescription_id`, `created_by`, `voided_by` (nullable FK users), `voided_at`, encrypted `void_reason`, encrypted main group (`main_od_value`, `main_od_sphere`, `main_od_cylinder`, `main_os_value`, `main_os_sphere`, `main_os_cylinder`), encrypted ADD group (`add_od_value`, `add_od_sphere`, `add_od_cylinder`, `add_os_value`, `add_os_sphere`, `add_os_cylinder`), encrypted `remarks`, encrypted `amendment_reason`, `prescribed_at`, `deleted_at`. |
| `products` | Stocked physical catalog entries. Permitted `product_type` values: `frame`, `contact_lens`, `accessory`. Variants own price, dimensions, SKU, stock, legacy AR compatibility fields, and the pointer to the current published 3D asset. Historical `lens` Products are retained but deactivated by `2026_08_10_193536_deactivate_legacy_lens_products.php`. |
| `product_variants` | Catalog variants. `published_ar_asset_id` is a nullable FK to the current published `ar_assets` version. `ar_eligible` and `ar_asset_reference` remain as legacy compatibility fields and are not sufficient for Android 3D loading. |
| `ar_assets` | Versioned GLB assets associated with a `product_variant_id`. Stores `version`, `status` (`quarantined`, `validated`, `approved`, `published`, `rejected`, `discarded`, `superseded`, or `disabled`), `format` (`glb`), private `quarantine_path`, immutable `published_path`/HTTPS `url`, server-computed `byte_size` and lowercase `sha256`, JSON `calibration`, upload/validation/approval/publication/disablement actors and timestamps, optional `expires_at`, and staff-only `validation_error`. Unique (`product_variant_id, version`); old published files are retained for rollback, while discarded unpublished rows retain audit history after their private quarantine object is removed. |
| `quotations` | `patient_id`, `encounter_id`, `prescription_id`, `status` (draft/accepted/declined), `valid_until`, `subtotal`, `discount_amount`, `total`, `confirmed_by`, `confirmed_at`, `decline_reason` (nullable text, populated when status is declined), `notes`. |
| `quotation_items` | `quotation_id`, `description`, `quantity`, `unit_price`, `amount`, `product_variant_id`, `lens_category_id`, `lens_option_id`, `service_id`, `item_kind` (frame/lens_package/lens_option/contact_lens/accessory/custom_product/service), `item_snapshot` (nullable JSON snapshot of catalog data). |
| `services` | Service/exam charge catalog. `name` (unique), `description` (nullable), `price`, `is_active`. Referenced by `quotation_items.service_id` and `billing_record_items.service_id`; inactive services are rejected wherever an item references one. |
| `job_orders` | `patient_id`, `encounter_id`, `prescription_id`, `quotation_id` (unique, nullable), `status` (queued/in_progress/ready_for_dispensing/dispensed/cancelled), `fulfillment_mode` (immediate/prepared), `uses_external_supplier`, `total_amount`, nullable internal `supplier_invoice_number`. |
| `job_order_items` | `description`, `quantity`, `unit_price`, `amount`, `product_variant_id`, `lens_category_id`, `lens_option_id`, `item_kind` (frame/lens_package/lens_option/contact_lens/accessory/custom_product), `item_snapshot` (nullable JSON snapshot of catalog data). |
| `job_order_eyewear_specifications` | One-to-one with `job_orders`. `job_order_id` (unique), `prescription_id`, `frame_job_order_item_id` (nullable), `lens_package_job_order_item_id`, `frame_source` (catalog/patient_supplied), lens construction snapshots (`lens_design_snapshot`, `lens_material_snapshot`, `refractive_index_snapshot`, `lens_options_snapshot` JSON), encrypted dispensing measurements (`distance_pd_mode`, `distance_pd_binocular`/`od`/`os`, `near_pd_*`, `fitting_height_*`, `segment_height_*`), encrypted `lab_instructions`, `approved_by` (nullable FK users), `approved_at`, `verified_by` (nullable FK users), `verified_at`, encrypted `verification_notes`. |
| `billing_records` | `patient_id`, `job_order_id` (nullable), `encounter_id` (nullable), `quotation_id` (nullable), `billing_record_number`, `status` (unpaid/partially_paid/paid/voided), `subtotal_amount`, `discount_amount`, `total_amount`, `amount_paid`, `balance_due`, `payment_due_date`, `recorded_by`, `recorded_at`. |
| `billing_record_items` | `billing_record_id`, `source_kind` (optical_order/quotation/encounter/direct_service), `description`, `quantity`, `unit_price`, `amount`, `job_order_item_id` (nullable), `quotation_item_id` (nullable), `service_id` (nullable), `encounter_id` (nullable). |
| `billing_payments` | `billing_record_id`, `amount`, `payment_method`, `reference_number`, `status` (posted/voided), `recorded_by`, `recorded_at`, `notes`. |
| `dispensing_events` | `job_order_id`, `billing_record_id`, `dispensed_by`, `recipient_name`, `notes`, `released_balance_amount` (default 0), `balance_override_by` (nullable FK users), encrypted `balance_override_reason`, `balance_due_date` (nullable date). |
| `saved_frames` | Account-owned frame preferences. `user_id` (FK users, cascade delete), `product_variant_id` (FK product_variants, cascade on force delete), `created_at` (stable `saved_at` for ordering), `updated_at`. Unique (`user_id`, `product_variant_id`); index (`user_id`, `created_at`). No `patient_id`, `appointment_id`, `status`, `accepted_at`, `expires_at`, quantity, stock snapshot, rank, or staff note. |
| `frame_ratings` | `patient_id`, `product_variant_id`, `dispensing_event_id`, `rating` (1-5), `comment`, `is_hidden`, `moderation_reason`. |
| `visit_ratings` | `patient_id`, `appointment_id` (unique — one rating per visit), `encounter_id`, `optometrist_id`, `rating` (1-5), `comment`, `service_ids` (JSON snapshot), `is_hidden`, `moderation_reason`, `moderated_by`, `moderated_at`. |
| `conversations` | `account_user_id` (nullable FK users, unique when set), `patient_id` (nullable FK patients, indexed, no longer unique), `inbox_archived_at` (nullable timestamp, inbox archive semantics), `staff_last_read_at` (nullable timestamp, clinic-wide staff read watermark). At least one of `account_user_id` or `patient_id` must be non-null. States: unlinked (`account_user_id` set, `patient_id` null), current linked (both set), historical after unlink (`account_user_id` null, `patient_id` set). `account_user_id` is the mobile authorization boundary. Inbox archive removes from staff inbox without soft-deleting; auto-restores on new message. Patient read uses per-message `messages.read_at`; staff read uses the clinic-wide `staff_last_read_at` watermark — the asymmetry is deliberate (one thread per patient, 2–3 staff). |
| `messages` | `conversation_id`, `sender_id`, `body`, `read_at`. |
| `audit_logs` | `actor_id`, `subject_type`, `subject_id`, `action`, `metadata` (JSON), `ip_address`, `user_agent`. |
| `inventory_movements` | `product_variant_id`, `reservation_id` (historical provenance, no active FK), `job_order_id`, `inventory_movement_type_id`, `quantity_change`, `previous_stock`, `new_stock`, `created_by`. |
| `privacy_requests` | `patient_id`, `request_type` (access/correction/objection/erasure), `disposition`, `handled_by`. |
| `privacy_incidents` | `title`, `description`, `status`, `reported_by`, `assigned_to`. |
| `clinic_hours` | `weekday` (0-6, Carbon convention: Sunday through Saturday), `open_time`, `close_time`, `enabled`. Staff-editable via the Availability cluster; exposed to authenticated mobile accounts as all seven rows through `GET /api/v1/clinic-hours`. |
| `provider_hours` | `user_id`, `weekday`, `start_time`, `end_time`, `enabled`. |
| `schedule_overrides` | `user_id` (nullable), `override_date`, `type` (closed/early_close/provider_absence), `start_time`, `end_time`, `reason`. |

### Soft Deletes

These models use `SoftDeletes`: `Patient`, `Product`, `ProductVariant`, `Brand`, `ProductCategory`, `LensCategory`, `Appointment`, `Prescription`, `Conversation`, `BillingRecord`, `JobOrder`, `VisitRating`.

### Record Lifecycle Patterns

**Activate/Deactivate (availability state).** All reusable catalog resources — `Brand`, `ProductCategory`, `Product`, `ProductVariant`, `LensCategory` (lens package), `LensOption`, and `Service` — use an explicit Active/Inactive lifecycle. Appointment Types use the same active-state principle. Admin tables provide Active, Inactive, and All filters plus Activate/Deactivate actions; there are no user-facing Archive/Restore/Delete actions. Deactivated records are excluded from patient browsing and new transaction selectors, while historical quotation, Optical Order, and Billing snapshots remain unchanged. The lifecycle service permits permanent deletion only when the record has never been referenced; referenced records must be deactivated instead. Soft-delete columns remain on some catalog models for historical compatibility, and activation restores a trashed row when applicable.

**Account deactivation.** `User` also uses `is_active`; deactivated accounts fail `canAccessPanel()` regardless of role.

**Remote 3D asset lifecycle.** `ArAsset` records are append-only by version:
staff/admin upload creates a quarantined record and validates the binary;
coordinated submission validates calibration and moves it to `validated`,
physical-match attestation moves it to `approved`, and publication makes it the
variant's current `published` asset. The one-person coordinator may record the
same actor for upload, approval, and publication with explicit audit metadata;
direct approval still keeps the uploader self-approval guard.
An unpublished `quarantined`, `validated`, `approved`, or `rejected` record may
be marked `discarded` by the authorized **Discard 3D upload** action. The row
and audit history remain, but its private quarantine object is removed. The
action cannot discard `published`, `superseded`, or `disabled` versions.
Publishing a replacement locks the variant, demotes the previous version to
`superseded`, and switches the pointer atomically. Disablement clears only the
published pointer and marks the version `disabled`; it does not delete the
file, frame images, variant, or Saved Frame preferences. Rollback restores a
previous valid published file and demotes the current version. There is no
patient upload route.

**Void (status-based irreversible).** For records created in error that require a reason, actor, timestamp, and audit log: `Encounter` (status: `voided`), `Prescription` (void fields), `BillingRecord` (status: `voided`), `Quotation` (status: `declined` with `decline_reason`). Voided records are terminal and immutable.

**Inbox Archive (conversations).** Removes conversation from staff inbox without soft-deleting. Uses `inbox_archived_at` timestamp. Auto-restores when new message arrives. Patient still sees the conversation. Never creates a second conversation.

**No destructive actions.** Historical/ledger records have no delete/archive: `AuditLog`, `InventoryMovement`, `SmsNotification`.

---

## Status Transition Rules

**AR Assets:** `quarantined` → `validated` or `rejected` after coordinated
review submission; `validated` → `approved` through the authorized one-person
coordinator or a separate authorized reviewer; `approved` → `published`.
Any unpublished `quarantined`, `validated`, `approved`, or `rejected` record
may also move to `discarded`, which is terminal for that upload and removes its
private quarantine object without deleting the row or audit history.
Publishing a replacement marks the prior
version `superseded` only after the new immutable file is stored and the
variant pointer is switched in one transaction. The current `published`
version can move to `disabled`; a retained `superseded` or `disabled`
version can return to `published` through rollback after its stored file
passes size/checksum validation. Patient responses serialize only a valid
published version as `status: ready`, otherwise `ar` is `null`.

**Appointments:** `scheduled → checked_in → fulfilled` (terminal). `cancelled` and `no_show` are terminal from `scheduled` or `checked_in`. Check-in creates an Encounter transactionally.

**Encounters:** `planned → in_progress → completed` (terminal). `cancelled` is terminal from `planned` only. `voided` is terminal from `planned` or `completed` (requires reason, actor, timestamp, audit log). Only active assigned optometrists can start (self-claim if unassigned) and complete. Starting synchronizes provider to Appointment. Completion requires `chief_complaint`, `findings`, `assessment`, and `plan`; fulfills the Appointment atomically. Optional prescription finalizes in the same transaction. Completed encounters are immutable; corrections/supplements use append-only addenda.

**Quotations:** `draft → accepted/declined`. Drafts are editable and can be revised through the wide shared quotation builder; accepted and declined quotations are read-only. Confirmation validates expiry and the current, non-voided prescription when corrective items exist, then atomically creates the downstream records: product quotations create an Optical Order and Billing Record, service-only quotations create billing only, and mixed quotations append selected service lines to the bill. Declined quotations require a `decline_reason`.

**Optical Orders** (`job_orders` table; `OpticalOrderResource` in Filament): `queued → in_progress → ready_for_dispensing → dispensed` (terminal). The UI presents these as Confirmed → Processing → Ready for Pickup → Dispensed. `cancelled` is terminal from any active state. Cancellation reverses inventory. `supplier_invoice_number` required only for external prepared work. `fulfillment_mode` (immediate/prepared) determines completion path; prepared is the default for quotation and direct-order creation, while immediate is intended for items already ready to dispense. Corrective orders cannot enter Processing without an approved eyewear specification. Ready for Pickup requires completed verification and, for external work, the supplier/lab reference. Routine dispensing requires a zero billing balance, except for the documented administrator override with reason and due date. Non-corrective and immediate orders skip the corrective preparation gates.

**Billing Records:** `unpaid → partially_paid → paid` (terminal). `voided` is terminal. Payments are append-only with posted/voided status. Overpayments are rejected; the balance comparison occurs under the Billing Record row lock. First posted payment locks the charge set. `job_order_id` and `encounter_id` are nullable; at least one source required. `billing_record_items` stores immutable charge snapshots. `payment_due_date` tracks due dates. Routine dispensing requires zero balance. Admin may release with an outstanding balance only with a nonblank reason and a current/future payment due date; the Dispensing Event snapshots the override attribution.

**Inventory** (`InventoryResource`, backed by `ProductVariant`): stock is variant-level, so this resource lists variants across every product rather than products — the Products table's quantity column sums a product's variants and hides the single variant sitting at zero. Sorted by `stock_quantity` ascending. Tabs: All (default) · Needs Reorder · Out of Stock. Navigation badge counts active variants matching the `needsReorder()` scope, and the dashboard's Low Stock stat deep-links to that tab. Read-only apart from the two stock movements — variant name, price, images, and attributes are edited in Products, so a variant has exactly one editor. Three fields drive it: `low_stock_threshold` (the tripwire; `0` means untracked), `target_stock_level` (the restock-to level, nullable), and the derived suggested order quantity (`target − current`, floored at zero, null when no target is set). Stock actions live in `App\Filament\Support\StockActions` and are shared with the Products → Variants relation manager so the ledger-writing logic has one definition.

**Saved Frames:** Account-owned preferences keyed by `user_id` and
`product_variant_id`. Saving, removing, linking, unlinking, and viewing are
read-only with respect to inventory; a preference remains readable as
`unavailable` when its variant, product, brand, or category is inactive or
soft-deleted, or when stock reaches zero. Staff see preferences only through a
Patient's current `user_id` link. The one-time
`saved-frames:migrate-reservations` command converts legacy reservation rows in
a transaction, releases each held unit once with `reservation_release`
provenance, saves linked choices with their historical timestamp, skips
unlinked choices, and deletes the legacy rows atomically.

---

## Filament Panel

URL: `/admin` — accessible to users with at least one of `admin`, `optometrist`, or `staff` roles. `canAccessPanel()` also requires `is_active`, so a deactivated account is blocked regardless of role.

Auth-related panel configuration (`AdminPanelProvider`): custom `->login(Login::class)`, `->profile(EditProfile::class, isSimple: false)`, `->passwordReset()`, and `->multiFactorAuthentication([AppAuthentication::make()], isRequired: app()->isProduction())` (TOTP, required in production, optional and enrollable via the profile page otherwise). `EnsurePasswordIsChanged` runs as panel `authMiddleware` alongside Filament's `Authenticate`.

**Navigation groups (in order), workflow-shaped rather than by data domain:**
- Today — Appointments, Appointment Requests, Availability (cluster)
- Patients — Patient Records, Patient Accounts, Link Requests, Conversations, Visit Feedback
- Clinical — Encounters (4-step wizard, provider-owned), Prescriptions
- Optical — Quotations, Optical Orders, Frame Ratings
- Billing — Billing & Payments
- Catalog — Products, Inventory, Inventory History, Brands, Lens Categories, Lens Options, Product Categories, Services
- Admin — Staff Accounts, SMS Log, Audit Logs

**Remote frame 3D assets** are managed from the `VariantsRelationManager` on
frame products. Active staff/admin users use the single **Manage 3D model**
action to upload or resume a candidate, calibrate it, optionally adjust scale
from a complete transformed rendered-width measurement, attest to the physical
match, and publish an immutable version. The action layer repeats
authorization checks, so hidden Filament actions are not the security
boundary. History, disablement, and rollback remain secondary operations. The
table shows `Upload received`, `Awaiting physical approval`, `Ready to publish`,
`Published`, `Rejected`, `Discarded`, and `Disabled`, plus human-readable
validation notes. **Discard 3D upload** is visible only for unpublished
candidates; published models use **Disable 3D model**, and retained previous
versions use **Rollback**.

Locked in by `tests/Feature/Filament/AdminNavigationStructureTest.php` (group order, item order per group, no orphaned/singleton groups, unique outlined icons).

**Availability cluster** (`app/Filament/Clusters/Availability/`) replaces the old single Availability page. Sub-pages:
- **Clinic Hours** — weekly `clinic_hours` schedule.
- **Optometrist Hours** — per-optometrist `provider_hours` schedule.
- **Schedule Overrides** — one-off `schedule_overrides` (clinic closed / early close / optometrist absence), audit-logged on create/delete; the upcoming-overrides list is a real Filament table (`HasTable`/`InteractsWithTable` on the page), not hand-rolled HTML.
- **Appointment Types** (admin-only resource) — manages appointment type labels, description, duration (5-minute step, 5–240 range), referral requirement, patient visibility, and active state. The resource has no delete action; deactivate a type to remove it from new patient selections while preserving existing appointment snapshots.

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
GET    /api/v1/appointment-types              List patient-visible appointment types
GET    /api/v1/appointment-optometrists       List active optometrists
GET    /api/v1/clinic-hours                   List weekly clinic hours
GET    /api/v1/conversation                   Get conversation
GET    /api/v1/conversation/messages          List messages
GET    /api/v1/conversation/messages/search   Search messages
POST   /api/v1/conversation/messages          Send message
POST   /api/v1/conversation/messages/read     Mark messages read
GET    /api/v1/conversation/attachments/{attachment}  Download attachment
GET    /api/v1/notifications                  List notifications
GET    /api/v1/notifications/unread-count     Unread count
PATCH  /api/v1/notifications/{notification}/read Mark read
PATCH  /api/v1/notifications/read-all         Mark all read
GET    /api/v1/frames
GET    /api/v1/frames/{id}
GET    /api/v1/appointment-request-availability
GET    /api/v1/appointment-requests
POST   /api/v1/appointment-requests
GET    /api/v1/appointment-requests/{id}
POST   /api/v1/appointment-requests/{id}/cancel
GET    /api/v1/saved-frames                    List this account's preferences
PUT    /api/v1/saved-frames/{productVariant}   Save a frame variant (idempotent)
DELETE /api/v1/saved-frames/{productVariant}   Remove a preference (idempotent)
```

The frame catalog responses include the additive `ar` field on every variant.
It is `null` unless a current published GLB passes storage, expiry, byte-size,
checksum, and calibration checks; only `status: ready` is patient-visible.
Frame browsing remains account-only and does not require an active patient link,
and Saved Frames are account-owned preferences that do not require an active
patient link. Staff see them only through a Patient's current link.

### Active Patient Link Required (token + active link)
```
GET    /api/v1/appointment-availability
GET    /api/v1/appointments
GET    /api/v1/appointments/{id}
POST   /api/v1/appointments/{id}/cancel
POST   /api/v1/appointments/{id}/reschedule
POST   /api/v1/appointments/{id}/rating
GET    /api/v1/prescriptions
GET    /api/v1/prescriptions/{id}
GET    /api/v1/optical-orders
GET    /api/v1/optical-orders/{id}
POST   /api/v1/optical-order-items/{id}/rating
```

**Route count:** 8 public + 40 account-only + 11 active-link = **59 routes total.**

Conversation routes (including attachment download) are in the account-only tier —
no patient link required for read, send, or download. Upload still requires a
linked patient.

Authenticated API throttles use separate per-account buckets so a mobile
bootstrap burst cannot consume the profile and clinical budgets together:
`GET /me` allows 300 requests per minute, account-only routes allow 120 per
minute, active-link routes allow 120 per minute, invitation OTP requests allow
5 per minute, and invitation acceptance allows 120 per minute. Rate-limited
responses include `Retry-After`; middleware-backed limits also include the
standard `X-RateLimit-*` headers.

> **Appointment catalog update (2026-08-09).** Added `GET /appointment-types`
> (restored patient-visible catalog) and `GET /appointment-optometrists`
> (patient-safe provider catalog) to the account-only tier. Both are
> account-only, require authentication, and do not require an active link.

Breaking changes from coordinated Android cutover:
- `POST /register` and `POST /login` removed (replaced by two-stage auth/register)
- Direct `POST /appointments` removed (use appointment requests)
- Three intake routes removed (retired)
- `GET /appointment-types` restored as patient-visible (was previously removed as internal-only)
- `POST /appointment-requests` now requires `appointment_type_id` (coordinated contract change)

Patient-specific clinical resource access is scoped through the authenticated
account's linked patient identity. The frame catalog and Saved Frames are
account-level data; unlinked accounts may browse, save, list, and remove frame
preferences. Patients cannot create job orders, billing records, payments,
orders, billings, checkout records, or purchases.

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
| `AcceptPatientInvitation` | `app/Actions/PatientAccounts/` | Atomically verifies the account-bound OTP, locks and activates the patient link, and safely returns the existing link on a same-account retry |
| `SearchPatientDuplicates` | `app/Actions/Patients/` | Searches by email hash, phone hash, name+DOB |
| `SubmitAppointmentRequest` | `app/Actions/Appointments/` | Creates request with type-based duration snapshot, validates all time preferences for availability, persists alternatives, encrypted referral source, and latest-preference expiry; does NOT create capacity holds |
| `BuildAppointmentRequestIdentitySnapshot` | `app/Actions/Appointments/` | Builds the expanded encrypted identity snapshot from submitted identity or account fallback, derives the verified phone server-side, and validates any submitted phone against it |
| `CancelAppointmentRequest` | `app/Actions/Appointments/` | Ownership check, status validation |
| `AcceptAppointmentRequest` | `app/Actions/Appointments/` | Creates scheduled appointment with final provider, type, duration, and start under schedule-date lock with deadlock retries; enforces referral and outside-preference contact note rules; idempotent |
| `RejectAppointmentRequest` | `app/Actions/Appointments/` | Closes request without creating appointment |
| `ExpireAppointmentRequests` | `app/Actions/Appointments/` | Idempotent scheduled expiry of pending requests |
| `BuildScheduleBlocks` | `app/Actions/Appointments/` | Produces blocks from appointments + request holds |
| `UpdateClinicHours` | `app/Actions/Appointments/` | Updates the weekly `clinic_hours` schedule, audit-logged |
| `UpdateProviderHours` | `app/Actions/Appointments/` | Updates a single optometrist's weekly `provider_hours` schedule, audit-logged |
| `CreateScheduleOverride` | `app/Actions/Appointments/` | Creates a one-off closed/early-close/provider-absence override, audit-logged |
| `DeleteScheduleOverride` | `app/Actions/Appointments/` | Removes a schedule override, audit-logged |
| `SaveFrame` | `app/Actions/SavedFrames/` | Validates an active frame variant and idempotently saves an account-owned preference without changing inventory |
| `ConvertFrameReservation` | `app/Actions/SavedFrames/` | Converts one legacy reservation under row locks, releases held stock once with historical provenance, saves linked choices with their original timestamp, and deletes legacy rows atomically |
| `UploadArAsset` | `app/Actions/ArAssets/` | Authorizes staff/admin, stores an opaque `.glb` in private quarantine, validates the binary, computes checksum/size, and records the upload audit event |
| `SubmitArAssetForReview` | `app/Actions/ArAssets/` | Validates explicit steward calibration and moves a received asset into the physical-review queue |
| `ApproveArAsset` | `app/Actions/ArAssets/` | Records active staff/admin physical-review approval for a validated asset |
| `PublishArAsset` | `app/Actions/ArAssets/` | Verifies quarantine integrity, writes the immutable public version, and atomically swaps the variant's published pointer while preserving the prior version |
| `DiscardArAsset` | `app/Actions/ArAssets/` | Marks an unpublished candidate `discarded`, records the audit event, and removes its private quarantine object without touching published history |
| `DisableArAsset` | `app/Actions/ArAssets/` | Removes only the variant's patient-facing AR pointer and records disablement; normal images and Saved Frame preferences remain available |
| `RollbackArAsset` | `app/Actions/ArAssets/` | Verifies a retained published file and atomically restores it as the current version |
| `CreateOpticalOrderFromQuotation` | `app/Actions/OpticalOrders/` | Accepts the quotation, creates an Optical Order from product lines, commits inventory, copies selected performed service lines into billing, records an optional deposit — idempotent |
| `CreateDirectOpticalOrder` | `app/Actions/OpticalOrders/` | Creates a product-only Optical Order with no source Quotation (walk-in sale); uses the shared `BuildOpticalOrder` collaborator |
| `AddChargesToBilling` | `app/Actions/BillingRecords/` | One append path keyed by `BillingItemSourceKind` (`optical_order`, `quotation`, `encounter`, `direct_service`); replaces five previous append actions |
| `ValidateOpticalQuotation` | `app/Actions/Quotations/` | Validates optical item matrix: exactly one lens package, at most one frame, lens options require package, corrective eyewear requires current Patient-owned Prescription |
| `BuildQuotationItemSnapshot` | `app/Actions/Quotations/` | Converts controlled catalog selections into stable transaction snapshots with item_kind and identifying data |
| `CreateDirectOpticalOrder` | `app/Actions/OpticalOrders/` | Creates an Optical Order directly for a patient without a preceding Quotation ("New Direct Order") |
| `CreateQuotation` | `app/Actions/Quotations/` | Creates a quotation for a patient, from an in-progress or completed encounter or, independently, from any current-version prescription; assigns item_kind and snapshot via `BuildQuotationItemSnapshot`; validates `service_id` items against active services |
| `UpdateQuotationDraft` | `app/Actions/Quotations/` | Updates a draft quotation; assigns item_kind and snapshot; enforces admin-only discount |
| `SaveEyewearSpecification` | `app/Actions/JobOrders/` | Validates and saves lens construction, frame source, PD representation, required heights, and lab instructions; clears approval on edit |
| `ApproveEyewearSpecification` | `app/Actions/JobOrders/` | Active optometrist approves a corrective-eyewear specification; creates audit event |
| `VerifyEyewear` | `app/Actions/JobOrders/` | Records who checked completed eyewear against the approved specification, when, and optional notes |
| `RecordInventoryMovement` | `app/Actions/Inventory/` | Single writer for every stock change: adjusts `stock_quantity` and appends the `inventory_movements` ledger row in one transaction, guarding against negative stock on deductions |
| `CancelOpticalOrder` | `app/Actions/OpticalOrders/` | Reverses inventory, voids unpaid billing, preserves payments |
| `ResolveOpenCheckoutBillingRecord` | `app/Actions/BillingRecords/` | Resolves or reuses the one open Billing Record for a patient visit (matched by `job_order_id`/`encounter_id`) instead of creating a separate record per charge source |
| `AddChargesToBilling` | `app/Actions/BillingRecords/` | Adds charges to the visit's open Billing Record, keyed by `BillingItemSourceKind` (encounter service, direct service, optical order, quotation) |
| `AuditLegacyPatientIntakes` | `app/Actions/Encounters/` | Reports cleanup readiness for legacy intake data |
| `SaveEncounterDraft` | `app/Actions/Encounters/` | Validates and persists partial encounter drafts; trims and caps narrative at 10,000 characters; enforces assigned-optometrist-only access |
| `AssignEncounterOptometrist` | `app/Actions/Encounters/` | Assigns an active optometrist to a planned Encounter and synchronizes Appointment provider in one locked transaction |
| `TransferEncounter` | `app/Actions/Encounters/` | Transfers in-progress Encounter to a different active optometrist; preserves draft data; audit-logs identifiers and reason category only |
| `CreateEncounterAddendum` | `app/Actions/Encounters/` | Creates append-only correction or supplement on a completed Encounter; locks parent for sequence allocation; enforces type-specific authorization |
| `PrunePatientAccountData` | `app/Actions/PatientAccounts/` | Prunes expired OTPs, tokens, invitations, terminal requests |
| `CreateScheduledAppointment` | `app/Actions/Appointments/` | Creates appointment from mobile API with availability checks |
| `VerifyPatientIntake` | `app/Actions/Intakes/` | Records verifier/time, locks snapshot |
| `ProcessPrivacyRequest` | `app/Actions/Privacy/` | Records disposition, no auto-deletion |
| `CreateAuditLog` | `app/Actions/Audit/` | Persists audit entry (actor, subject, action, metadata, ip_address, user_agent — the latter two default from the current request when not passed explicitly) |
| `SyncUserRoles` | `app/Actions/Users/` | Validates and synchronizes multi-role pivot assignments; blocks self-role mutation, rejects invalid role combinations, protects last active admin, and emits `user.role_changed` audit events with old/new role names |
| `RecordAuthenticationAudit` | `app/Listeners/` | Listens to `Illuminate\Auth\Events\{Login,Logout,Failed}`, scoped to panel-capable accounts; writes login/logout/failed-login audit entries and updates `last_login_at` |
| `SaveVisitRating` | `app/Actions/Ratings/` | Create or revise a patient's visit rating; snapshots optometrist and services at submission time |
| `ModerateVisitRating` | `app/Actions/Ratings/` | Hide/restore a visit rating comment while preserving the star value |

---

## Soft Deletes and Catalog Lifecycle

Some catalog models retain `SoftDeletes` for historical compatibility, and
their Filament resources include trashed rows in the lifecycle table. The
user-facing lifecycle is nevertheless **Activate/Deactivate**, not
Archive/Restore: Active, Inactive, and All filters are provided, and
deactivated rows are excluded from active selectors. Activation restores a
trashed row when applicable. Catalog deletion is not exposed as a user-facing
action; the lifecycle service permits permanent deletion only for records that
have never been referenced.

---

## Important Conventions

- **Phone number format:** All phone numbers are stored in `+63XXXXXXXXXX` format. The `Patient` and `User` models have mutators that automatically normalize phone numbers using `NormalizeContact::phone()`. Filament forms display a non-removable `+63` prefix and store the full formatted value.
- **Appointment source values:** `mobile` (patient books via Android app), `walk_in` (patient physically at clinic), `manual` (staff creates in admin panel). Set automatically — not a user-facing dropdown.
- **Appointment create form:** Patient mode toggle (new/existing). New patient shows full demographic fields. Walk-in toggle hides date/time and auto-sets source/status/checked_in_at. Appointment type select applies default duration reactively; duration is editable in 5-minute increments (5–240) for scheduled appointments. Referring source appears when the selected type `requires_referral`. Notes is a single staff_notes field. Walk-ins remain exempt from grid and future-time validation.
- **Appointment edit form:** Patient is read-only placeholder. Fields editable until checked in (scheduled/checked_in): appointment type (applies default duration on change), duration (editable in 5-minute increments), date/time, referring source (conditional on type), notes, optometrist. Status toggle and appointment type share a row. Quick "Assign" action available from list for optometrist assignment. Changing type, duration, time, or provider before check-in must revalidate availability under the schedule lock.
- **Appointment request review:** Staff review the patient-visible and internal appointment-type labels, provisional duration, referral context, submitted alternatives, request age, and overdue state before accepting or rejecting. Acceptance requires an active type, a valid duration, an assigned provider, a conflict-free 15-minute grid slot, and a contact note when the final time is outside all submitted preferences.
- **Prescriptions:** No standalone create. An optometrist starts the encounter, then uses **Create Prescription** on that in-progress Encounter page. The create/amend pages display patient and appointment context as read-only fields, while patient, appointment, encounter, and author linkage remain locked and derived server-side. Finalized prescriptions are read-only and cannot be archived through Filament. An optometrist must use **Amend Prescription**, provide a reason, and create a new linear version through `previous_prescription_id`; the original remains unchanged and is visibly marked superseded. Only the current leaf version can be printed or appears in the patient API list. The reason and clinical fields are encrypted, while the audit log stores only linkage metadata, actor, action, and time. The view page uses a two-column layout: left shows Prescription and ADD sections with placeholders, right shows prescription number, patient info, encounter, optometrist, date, and remarks. **Create Quotation** on the view page opens `CreateQuotation` directly against the current-version prescription (`?prescription=` query param), independent of whether its originating encounter is still in progress or already completed — this path has no one-per-prescription cap, unlike the one-per-encounter limit on the encounter-linked creation path.
- **Service catalog:** `Service` (admin-only Filament resource, Catalog group) holds priced, active/inactive clinical or service charges (e.g. exam fees) that aren't tied to a product variant, lens category, or lens option. Quotation items, the Quotation creation form, the Encounter charge form, and the direct Billing Record charge form all offer a Service picker alongside the existing product/lens-category/lens-option pickers; an item may reference at most one of the four catalog references (`product_variant_id`, `lens_category_id`, `lens_option_id`, or `service_id`), and inactive services are rejected at validation time.
- **Lens option catalog:** `LensOption` is a separately billed enhancement catalog, not a Product, Service, or inventory item. New transactions can select only active options; an option requires exactly one lens package in the same optical build, duplicate selections are rejected, and the transaction stores `item_kind = lens_option` with an immutable name/description/price snapshot. Renaming or deactivating an option does not rewrite confirmed quotation or Optical Order snapshots.
- **Edit pages:** Quotations, Billing Records, and Optical Orders have full form schemas showing related items, financial summaries, and timelines. Quotation confirmation surfaces corrective configuration and prescription version/author context. Optical Order pages show immutable item and lens-option snapshots plus eyewear-specification state. Billing Record items are the record's own immutable `items` snapshot (`BillingRecordItem`, tagged with `source_kind`), not values resolved live from `jobOrder.items`.
- **Quotation and direct-order builders:** When a current prescription is selected, the forms expose a dedicated prescription-eyewear section (optional frame, required one-pair lens package, optional unique lens options) and a separate Other Items list. The mode toggle is not persisted; the normalized commercial item rows are authoritative. Without a prescription, the normal item list remains available for frames, contact lenses, accessories, custom products, and services in quotations; direct optical orders use the same eyewear builder but commit the order and bill immediately. Draft quotation revisions use a wide slide-over and return to the review page; accepted and declined quotations cannot be revised.
- **Service-charge entry:** Add Service Charge is a compact modal shared by Billing Records and Encounters. Catalog-service lines derive and lock the catalog name and price; custom-service lines require an editable description and unit price. The modal contains no prescription, fulfillment, supplier, deposit, or pickup controls and appends the normalized lines to the patient's existing open Billing Record.
- **Encounter "Create Quotation":** The in-progress/completed Encounter edit page also offers **Create Quotation**, opening `CreateQuotation` with `?encounter=` — distinct from the Prescription-page path: it requires the encounter's current prescription to be finalized, and is capped at one quotation per encounter (hidden once one exists). The Prescription-page path has no such cap.
- **Encounter workflow:** Four-step autosaving wizard (History, Examination, Assessment & Plan, Review & Complete). Check-in creates a planned Encounter without attaching PatientIntake, copies assigned provider, and prefills chief complaint from appointment reason. Start uses self-claim pattern — the actor becomes the provider when unassigned; only the assigned optometrist can start otherwise. Draft saves via `SaveEncounterDraft` trim and cap narrative at 10,000 characters. Completion requires `chief_complaint`, `findings`, `assessment`, and `plan`; only the assigned active optometrist can complete. Optional prescription finalizes atomically in the same transaction. Completed encounters are immutable; corrections (original author only) and supplements (any active optometrist) use append-only `encounter_addenda` records.
- **Encounter provider assignment:** Staff, optometrists, and admins can assign an active optometrist to a planned Encounter. In-progress transfer requires the current provider or admin. Encounter and Appointment provider IDs are always synchronized.
- **Encounter printing:** `GET /encounters/{id}/print` returns an authenticated Blade view of the completed record with addenda. Each print records an `encounter.printed` audit event with identifiers only.
- **Encounter billing:** The Encounter edit page offers **Add Service Charge** (posts service-line charges via `AddChargesToBilling` keyed by `BillingItemSourceKind`) and **View Billing Record**, both resolving to the single open Billing Record for that patient visit via `ResolveOpenCheckoutBillingRecord` — charges added after a Quotation sale is confirmed land on the same record instead of opening a second one.
- **Preferred Frames:** Appointment and Consultation edit pages show the three
  most recent Saved Frame preferences from the patient's currently linked
  account. The Patient Record's read-only Preferred Frames relation manager
  shows the complete list and labels inactive, out-of-stock, and low-stock
  variants without offering mutations.
- **Patient app invitations:** "Send App Invitation" is phone/SMS only; email is not an invitation delivery channel, since the verified phone is also the account's login contact. In `local`/`testing`, invitation codes are logged for `sail artisan pail` visibility, mirroring OTP delivery.
- **Invitation acceptance:** The mobile API requires the authenticated account to own the verified invited contact. Acceptance is atomic and idempotent for that same account; it never revokes the existing Sanctum token, creates inventory activity, or relinks an already-linked account. Duplicate mobile requests may safely reuse the consumed challenge after the invitation has already been accepted by that account.
- **Supplier invoice reference:** `job_orders.supplier_invoice_number` records the supplier's external invoice number only. Staff may enter it while the Job Order is active, and the Mark Ready action requires it. It is clinic-internal, is not part of Billing Records, and is hidden from patient APIs.
- **Walk-in patients:** `users.email` and `users.password` are nullable. Walk-in records have only structured name + phone.
- **Patient address:** Single nullable free-text field. Read-only via mobile API; editable by staff via Patients edit form.
- **Optometrist assignment:** Clinic-controlled. Patients choose clinic time only, not a specific provider.
- **Clinical data encrypted:** Prescription values, intake narrative, encounter findings/remarks/assessment/supporting_test_results/addenda reason/content use Laravel's `encrypted` cast. Not queryable.
- **`CX` in prescription print:** Binds to cylinder values. Axis is separate. Confirmed by clinic 2026-07-26.
- **Inventory:** `stock_quantity` represents available stock. Saving a frame
  preference never changes stock or creates an inventory movement. Normal order
  commitment and dispensing rules remain the only active frame stock paths;
  legacy reservation conversion releases any historical held units exactly once.
- **Legacy tables:** `orders`, `order_items`, `order_statuses`, `billings`, `billing_items`, `billing_statuses`, `discount_types`, `payments`, `service_records` remain in the schema but have no canonical application consumers. They will be removed in a future cleanup migration.
