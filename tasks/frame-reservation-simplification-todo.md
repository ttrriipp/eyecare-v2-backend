# Task Checklist: Minimal Frame Reservations

**Status:** ✅ Implemented 2026-08-12 — retained for the record
**Specification:** `docs/specs/frame-reservation-simplification-spec.md`
**Plan:** `tasks/frame-reservation-simplification-plan.md`
**Decision:** `docs/decisions/002-use-clean-break-frame-reservation-contract.md`

17 tasks in 6 phases. This replaces the 15-task checklist written against the
superseded five-status lifecycle.

## Execution Rules

- Implement in order; do not cross a checkpoint until its verification passes.
- Write or update focused Pest expectations **before** changing behavior.
- Use Laravel Boost `search-docs` before Laravel, Filament, Livewire, or Pest
  changes.
- Use Sail-prefixed Artisan generators with `--no-interaction` where one exists.
- Run `vendor/bin/sail bin pint --dirty --format agent` after PHP changes and
  before completing each checkpoint.
- Preserve: append-only inventory movements, the one-to-five candidate limit,
  one reservation per appointment, and ascending-variant-ID lock order.
- Never hold stock for an unaccepted reservation.
- Do not reset a database as part of implementation. `migrate:fresh` at the
  final checkpoint requires explicit confirmation of the target environment.
- Do not add a column, dependency, notification, or top-level directory beyond
  what the spec names. Stop and return to the owner instead.
- Stop and split any task that grows past ~5 files.

---

## Phase 1: Decouple

### Task 1: Remove reservation linkage from the Quotation actions

**Description:** Strip reservation awareness out of quotation creation, draft
updates, and sale confirmation. The `frame_reservation_id` column stays on the
table until Task 16; only the code stops using it.

**Acceptance criteria:**
- [ ] `ApplyQuotationFrameReservationSelection` and
      `ValidateQuotationFrameReservation` are deleted
- [ ] `frame_reservation_id` and `frame_reservation_item_id` are gone from the
      `CreateQuotation` and `UpdateQuotationDraft` payloads, validation rules,
      and private helpers
- [ ] `ConfirmQuotationSale` no longer resolves, validates, or converts a
      reservation; it creates the order and commits inventory

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations`
- [ ] `FrameReservationQuotationSelectionTest` and
      `FrameReservationSaleConversionTest` are deleted, not skipped

**Dependencies:** None

**Files likely touched:**
- `app/Actions/Quotations/CreateQuotation.php`
- `app/Actions/Quotations/UpdateQuotationDraft.php`
- `app/Actions/Quotations/ConfirmQuotationSale.php`
- `app/Actions/Quotations/{Apply,Validate}Quotation*` (deleted)
- `tests/Feature/Quotations/FrameReservation*Test.php` (deleted)

**Estimated scope:** M

---

### Task 2: Remove reservation conversion from the Optical Order path

**Description:** Delete `ConvertFrameReservationToJobOrder` and its call sites,
and remove the dead `excludeProductVariantIds` parameter from
`CommitJobOrderInventory` — it exists only for the conversion path and no
caller passes a non-empty array.

**Acceptance criteria:**
- [ ] `ConvertFrameReservationToJobOrder` is deleted
- [ ] `AcceptAndStartOpticalOrder` has no reservation validation, linkage, or
      conversion block
- [ ] `CommitJobOrderInventory::handle()` takes only `$jobOrder` and
      `$selectedLotIds`
- [ ] `JobOrder::frameReservation()` and `FrameReservation::jobOrder()` are
      removed

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders`
- [ ] `tests/Feature/OpticalOrders/FrameReservationJobOrderLinkTest.php` and
      `tests/Feature/Reservations/ConvertFrameReservationToJobOrderTest.php`
      are deleted

**Dependencies:** Task 1

**Files likely touched:**
- `app/Actions/OpticalOrders/AcceptAndStartOpticalOrder.php`
- `app/Actions/JobOrders/CommitJobOrderInventory.php`
- `app/Actions/Reservations/ConvertFrameReservationToJobOrder.php` (deleted)
- `app/Models/JobOrder.php`, `app/Models/FrameReservation.php`

**Estimated scope:** M

---

### ✅ Checkpoint: Decoupled

- [ ] Full suite green
- [ ] `grep -rl "FrameReservation" app/` lists only reservation-owned files
      (Actions/Reservations, Models, Filament reservation resources, Http,
      Console, Policies)
- [ ] Pint clean

---

## Phase 2: Foundation

### Task 3: Migration A — add `accepted_at`; rewrite the model

**Description:** Add the nullable `accepted_at` column, cast it, expose
`isHeld()`, and drop `SoftDeletes` from the model. Obsolete columns stay in
place until Task 16 so the suite keeps building.

**Acceptance criteria:**
- [ ] Migration adds nullable `accepted_at` to `frame_reservations`
- [ ] `FrameReservation` casts `accepted_at` to `datetime`, exposes
      `isHeld(): bool`, drops the `SoftDeletes` trait, and lists `accepted_at`
      in `#[Fillable]`
- [ ] `FrameReservationFactory` defaults to unaccepted with an `accepted()`
      state

**Verification:**
- [ ] `vendor/bin/sail artisan migrate`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Reservations`

**Dependencies:** Task 2

**Files likely touched:**
- `database/migrations/*_add_accepted_at_to_frame_reservations.php` (new)
- `app/Models/FrameReservation.php`
- `database/factories/FrameReservationFactory.php`

**Estimated scope:** S

---

### Task 4: Build the `FrameReservationStock` collaborator

**Description:** One class owning every allocation and release, so lock order
and movement shape cannot drift across the five actions.

**Acceptance criteria:**
- [ ] `allocate(FrameReservation, int $variantId)` locks the variant, fails
      when `stock_quantity < 1`, decrements once, writes one
      `reservation_allocation` movement
- [ ] `release(FrameReservation, int $variantId)` locks the variant, increments
      once, writes one `reservation_release` movement
- [ ] Both record `created_by` from the authenticated user when present, and
      tolerate its absence (the sweep runs unauthenticated)

**Verification:**
- [ ] New `tests/Feature/Reservations/FrameReservationStockTest.php` covers
      allocate, release, out-of-stock rejection, and movement shape
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Reservations`

**Dependencies:** Task 3

**Files likely touched:**
- `app/Actions/Reservations/FrameReservationStock.php` (new)
- `tests/Feature/Reservations/FrameReservationStockTest.php` (new)

**Estimated scope:** S

---

### ✅ Checkpoint: Foundation

- [ ] `accepted_at` casts correctly; `isHeld()` is true only when set
- [ ] Allocate then release returns stock to baseline and leaves exactly two
      movement rows
- [ ] Full suite green, Pint clean

---

## Phase 3: Domain Actions

### Task 5: Rewrite `CreateFrameReservation`

**Description:** Creation records the request and nothing else — no stock, no
window check, no reactivation branch.

**Acceptance criteria:**
- [ ] Validates appointment ownership, `scheduled` status, not-yet-ended, and
      one-to-five distinct active frame variants
- [ ] Moves no stock and writes no inventory movement
- [ ] No window check; an appointment months away is accepted
- [ ] The released-reservation reactivation branch is deleted

**Verification:**
- [ ] `CreateFrameReservationTest` updated: asserts zero movements, asserts a
      far-future appointment succeeds
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Reservations/CreateFrameReservationTest.php`

**Dependencies:** Task 4

**Files likely touched:**
- `app/Actions/Reservations/CreateFrameReservation.php`
- `tests/Feature/Reservations/CreateFrameReservationTest.php`

**Estimated scope:** S

---

### Task 6: Replace `PrepareFrameReservation` with `AcceptFrameReservation`

**Description:** The one action that commits inventory. Highest-risk task in
the project — write the concurrency test first.

**Acceptance criteria:**
- [ ] `RESERVATION_WINDOW_DAYS = 7` constant; acceptance rejected when the
      appointment is further out, accepted at the boundary
- [ ] Rejects when the appointment is no longer `scheduled` or has ended
- [ ] Allocates every item through `FrameReservationStock`, then stamps
      `accepted_at` in the same transaction
- [ ] Out-of-stock on any item rolls back completely — no partial allocation,
      no `accepted_at`
- [ ] Re-reads `accepted_at` under the reservation lock; accepting twice
      allocates once
- [ ] `PrepareFrameReservation` is deleted

**Verification:**
- [ ] New `AcceptFrameReservationTest` covers window boundary, partial-stock
      rollback, idempotency, and concurrent acceptance
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Reservations`

**Dependencies:** Task 4

**Files likely touched:**
- `app/Actions/Reservations/AcceptFrameReservation.php` (new)
- `app/Actions/Reservations/PrepareFrameReservation.php` (deleted)
- `tests/Feature/Reservations/AcceptFrameReservationTest.php` (new)

**Estimated scope:** M

---

### Task 7: Replace `ReleaseFrameReservation` with `DeleteFrameReservation`

**Description:** Deletion is the release. Must be idempotent and must release
explicitly rather than relying on the items' cascade delete.

**Acceptance criteria:**
- [ ] Releases every item through `FrameReservationStock` when `isHeld()`, then
      deletes items and reservation in the same transaction
- [ ] Moves no stock when unaccepted
- [ ] Idempotent — a second call on a deleted reservation is a no-op, not an
      error, and writes no extra movement
- [ ] `ReleaseFrameReservation` and `ReservationStatus` usage are gone

**Verification:**
- [ ] New `DeleteFrameReservationTest` covers accepted, unaccepted, idempotent,
      and concurrent deletion
- [ ] `tests/Feature/Reservations/ReleaseFrameReservationTest.php` and
      `ReservationLifecycleTest.php` are deleted

**Dependencies:** Task 4

**Files likely touched:**
- `app/Actions/Reservations/DeleteFrameReservation.php` (new)
- `app/Actions/Reservations/ReleaseFrameReservation.php` (deleted)
- `tests/Feature/Reservations/DeleteFrameReservationTest.php` (new)

**Estimated scope:** M

---

### Task 8: Gate the item actions on held state

**Description:** Adding and removing frames works in both states; only the
stock effect is conditional. Comes after Task 7 because removing the last
frame delegates to `DeleteFrameReservation`.

**Acceptance criteria:**
- [ ] `AddFrameReservationItem` allocates only when `isHeld()`; no window check
- [ ] `RemoveFrameReservationItem` releases only when `isHeld()`
- [ ] Removing the last item deletes the reservation via
      `DeleteFrameReservation`
- [ ] Both drop every `ReservationStatus` reference and the reactivation branch

**Verification:**
- [ ] `AddFrameReservationItemTest` / `RemoveFrameReservationItemTest` updated
      to assert movement counts in both states
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Reservations`

**Dependencies:** Task 6, Task 7

**Files likely touched:**
- `app/Actions/Reservations/AddFrameReservationItem.php`
- `app/Actions/Reservations/RemoveFrameReservationItem.php`
- `tests/Feature/Reservations/{Add,Remove}FrameReservationItemTest.php`

**Estimated scope:** M

---

### ✅ Checkpoint: Domain Actions

- [ ] `tests/Feature/Reservations` passes
- [ ] Round-trip test: create → accept → add → remove → delete returns
      `stock_quantity` to its starting value with a balanced movement ledger
- [ ] `app/Actions/Reservations/` contains exactly `CreateFrameReservation`,
      `AcceptFrameReservation`, `AddFrameReservationItem`,
      `RemoveFrameReservationItem`, `DeleteFrameReservation`,
      `FrameReservationStock`
- [ ] Full suite green, Pint clean

---

## Phase 4: Integration

### Task 9: Rewire appointment integration

**Description:** Cancellation and no-show delete the reservation; reschedule
stops touching it entirely.

**Acceptance criteria:**
- [ ] `CancelReservationsForAppointment` is deleted; callers invoke
      `DeleteFrameReservation` directly
- [ ] Cancellation and no-show release stock in the same transaction as the
      appointment mutation
- [ ] The reservation-release block in `RescheduleAppointment` is removed;
      rescheduling moves no stock and keeps the reservation

**Verification:**
- [ ] `AppointmentReservationCleanupTest` updated for delete-not-close and for
      reschedule-as-no-op
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments`

**Dependencies:** Task 8

**Files likely touched:**
- `app/Actions/Appointments/RescheduleAppointment.php`
- `app/Actions/Reservations/CancelReservationsForAppointment.php` (deleted)
- appointment cancel / no-show call sites
- `tests/Feature/Appointments/AppointmentReservationCleanupTest.php`

**Estimated scope:** M

---

### Task 10: Rewrite the sweep as `ExpireFrameReservations`

**Description:** Derive the deadline from the appointment instead of a stored
column, and delete rather than transition.

**Acceptance criteria:**
- [ ] Renamed to `ExpireFrameReservations`, keeping the `reservations:expire`
      signature and the 15-minute `withoutOverlapping()` schedule
- [ ] Deletes reservations past clinic close on the appointment date
      (`ClinicSchedule::forDate`) or whose appointment is not `scheduled`
- [ ] Restores stock only for accepted reservations
- [ ] Runs unauthenticated without error

**Verification:**
- [ ] New/updated command test covers accepted expiry, unaccepted expiry,
      non-scheduled appointments, and current reservations left alone
- [ ] `vendor/bin/sail artisan reservations:expire` runs clean

**Dependencies:** Task 8

**Files likely touched:**
- `app/Console/Commands/ExpireFrameReservations.php` (renamed)
- `routes/console.php`
- `tests/Feature/Reservations/ExpireFrameReservationsTest.php`

**Estimated scope:** S

---

### ✅ Checkpoint: Integration

- [ ] `tests/Feature/Appointments` passes
- [ ] Cancelling an appointment holding 3 frames restores exactly 3 units
- [ ] Rescheduling writes zero inventory movements
- [ ] Full suite green, Pint clean

---

## Phase 5: Surfaces

### Task 11: Rewrite the patient API

**Description:** `DELETE` replaces cancel; `is_held` replaces `status`;
`expires_at` becomes derived.

**Acceptance criteria:**
- [ ] Routes are `GET`, `POST`, `DELETE /frame-reservations/{reservation}`;
      the `cancel` route is gone
- [ ] `FrameReservationResource` returns `is_held` and derived `expires_at`,
      and never `status` or `accepted_at`
- [ ] `DELETE` returns 204 for the owner in either state, 403 for a non-owner,
      404 for an already-deleted record
- [ ] `POST` never fails on stock

**Verification:**
- [ ] `tests/Feature/Api/V1/FrameReservationTest.php` rewritten for the new
      contract, including the absence of `status`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameReservationTest.php`

**Dependencies:** Task 8

**Files likely touched:**
- `routes/api.php`
- `app/Http/Controllers/Api/FrameReservationController.php`
- `app/Http/Resources/FrameReservationResource.php`
- `tests/Feature/Api/V1/FrameReservationTest.php`

**Estimated scope:** M

---

### Task 12: Rewrite the Frame Reservations Filament resource

**Description:** Two tabs, a held badge, an accept action, a release action.

**Acceptance criteria:**
- [ ] Table shows patient, appointment date, frame count, held badge
      (Awaiting acceptance / Frames set aside), derived expiry
- [ ] Two tabs: Awaiting acceptance, Set aside — no status filter or column
- [ ] **Accept & Set Aside** visible only when unaccepted; **Release Frames**
      (delete, confirmed) in both states
- [ ] Navigation badge counts unaccepted only
- [ ] `ItemsRelationManager` keeps Add/Remove Frame; **Use in Quotation** is gone

**Verification:**
- [ ] `FrameReservationResourceTest` updated for tabs, badge, action visibility
- [ ] `AdminNavigationBadgeTest` updated for the unaccepted-only count
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament`

**Dependencies:** Task 8

**Files likely touched:**
- `app/Filament/Resources/FrameReservations/**`
- `tests/Feature/Filament/FrameReservationResourceTest.php`
- `tests/Feature/AdminNavigationBadgeTest.php`

**Estimated scope:** M

---

### Task 13: Rewrite the Appointment relation managers

**Description:** Same two jobs — show the reservation, manage its frames — with
status-conditional visibility replaced by the held check.

**Acceptance criteria:**
- [ ] `FrameReservationsRelationManager` shows held state and offers
      Accept / Release
- [ ] `FrameReservationItemsRelationManager` offers Add/Remove Frame in both
      states; **Use in Quotation** is gone
- [ ] No `ReservationStatus` reference remains in either

**Verification:**
- [ ] `FrameReservationItemsRelationManagerTest` and
      `AppointmentReserveFramesTest` updated
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament`

**Dependencies:** Task 12

**Files likely touched:**
- `app/Filament/Resources/Appointments/RelationManagers/FrameReservation*.php`
- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `tests/Feature/Filament/{FrameReservationItemsRelationManager,AppointmentReserveFrames}Test.php`

**Estimated scope:** M

---

### Task 14: Simplify `FrameReservationPolicy`

**Description:** Flatten the duplicated indirection and add the accept ability.

**Acceptance criteria:**
- [ ] Methods are `viewAny`, `view`, `create`, `reserveFrames`, `accept`,
      `addFrame`, `removeFrame`, `delete` — all `hasPanelRole()`
- [ ] `update` and `addOrRemoveItems` are removed
- [ ] Every Filament action references a policy ability

**Verification:**
- [ ] Policy assertions in the Filament tests pass for staff, optometrist, and
      admin
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament`

**Dependencies:** Task 13

**Files likely touched:**
- `app/Policies/FrameReservationPolicy.php`
- Filament reservation resources and relation managers

**Estimated scope:** S

---

### ✅ Checkpoint: Surfaces

- [ ] API and Filament suites pass
- [ ] Manual: accept a 2-frame reservation in the panel, confirm both variants
      drop by exactly 1; release it, confirm both return
- [ ] Full suite green, Pint clean

---

## Phase 6: Cleanup

### Task 15: Delete dead code and obsolete tests

**Description:** Remove everything the rewrite orphaned, then prove it with a
grep.

**Acceptance criteria:**
- [ ] `app/Enums/ReservationStatus.php` and
      `app/Notifications/FrameReservationStatusChanged.php` are deleted
- [ ] `FrameReservationItem::scopeEligibleForQuotation` is removed
- [ ] `MarkFrameReservationTriedOn` + its test are deleted
- [ ] `QuotationFactory` and `FrameReservationFactory` carry no obsolete fields
- [ ] `grep -ri "ReservationStatus\|tried_on"` over `app/`, `database/`,
      `routes/`, `tests/` returns nothing

**Verification:**
- [ ] `vendor/bin/sail artisan test --compact`

**Dependencies:** Task 14

**Files likely touched:**
- `app/Enums/ReservationStatus.php`, `app/Notifications/...` (deleted)
- `app/Models/FrameReservationItem.php`
- `database/factories/{Quotation,FrameReservation}Factory.php`
- `tests/Feature/Reservations/MarkFrameReservationTriedOnTest.php` (deleted)

**Estimated scope:** M

---

### Task 16: Migration B — drop every obsolete column

**Description:** The contract half of the split. Safe only because Task 15
proved nothing references these columns.

**Acceptance criteria:**
- [ ] Drops `status`, `expires_at`, `released_at`, `released_by` (with its
      foreign key), `release_reason`, `deleted_at` from `frame_reservations`
- [ ] Drops `frame_reservation_id` from `quotations` and from `job_orders`,
      each with its foreign key and index
- [ ] `down()` restores the columns structurally

**Verification:**
- [ ] `vendor/bin/sail artisan migrate` then `migrate:rollback` then `migrate`
- [ ] `vendor/bin/sail artisan test --compact`

**Dependencies:** Task 15

**Files likely touched:**
- `database/migrations/*_drop_obsolete_frame_reservation_columns.php` (new)

**Estimated scope:** S

---

### Task 17: Update canonical documentation

**Description:** Bring the living docs in line with what shipped.

**Acceptance criteria:**
- [ ] `docs/BACKEND_CONTEXT.md`: `frame_reservations` /
      `frame_reservation_items` rows updated, the Frame Reservations status
      transition section replaced with the two-state model, the `quotations`
      and `job_orders` rows lose `frame_reservation_id`, and a Shipped note is
      added
- [ ] `docs/API_CONTRACT.md`: `DELETE` replaces cancel; `is_held` and derived
      `expires_at` documented; `status` removed
- [ ] `docs/ideas/frame-reservation-simplification.md` carries a superseded
      note pointing at the spec
- [ ] The permission matrix reflects the accept ability

**Verification:**
- [ ] Manual read-through against the implemented behavior
- [ ] Route count in `API_CONTRACT.md` still reconciles

**Dependencies:** Task 16

**Files likely touched:**
- `docs/BACKEND_CONTEXT.md`
- `docs/API_CONTRACT.md`
- `docs/ideas/frame-reservation-simplification.md`

**Estimated scope:** S

---

### ✅ Checkpoint: Complete

- [ ] `grep -ri "ReservationStatus\|tried_on\|frame_reservation_id"` returns
      only historical files under `docs/specs/`
- [ ] `vendor/bin/sail artisan migrate:fresh --seed` succeeds (confirm the
      target environment first)
- [ ] `vendor/bin/sail artisan test --compact` fully green
- [ ] `vendor/bin/sail bin pint --dirty --format agent` reports no changes
- [ ] Ready for review
