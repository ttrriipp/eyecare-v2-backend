# Task Checklist: Replace Frame Reservations with Saved Frames

**Status:** Approved by the project owner on 2026-08-26 — remediation implementation complete
**Specification:** `docs/specs/saved-frames-replacement-spec.md` (approved 2026-08-26)
**Plan:** `tasks/saved-frames-replacement-plan.md` (approved 2026-08-26)
**Decision:** `docs/decisions/003-replace-frame-reservations-with-saved-frames.md`

32 tasks across five implementation phases. The project owner approved this
checklist on 2026-08-26; remediation implementation is complete for the
repository state dated 2026-08-26.

Affected Saved Frames, API contract, Filament, seeder, and migration tests pass.
The full suite still reports unrelated pre-existing failures in appointment,
catalog, OTP, quotation, and other domains.

## Execution Rules

- Implement in dependency order and do not cross a checkpoint until every
  listed verification passes.
- Before each Laravel, Filament, Livewire, or Pest change, use Laravel Boost
  `search-docs` with broad version-scoped queries.
- Apply `laravel-best-practices` whenever PHP/Laravel code is written and
  `pest-testing` plus `test-driven-development` whenever tests or behavior are
  changed.
- Use `vendor/bin/sail artisan make:* --no-interaction` for supported file
  generation. Run every PHP, Artisan, Composer, and Node command through Sail.
- Write or update the focused Pest expectation before changing behavior.
- Run `vendor/bin/sail bin pint --dirty --format agent` after every task that
  changes PHP.
- Preserve unrelated worktree changes. In particular,
  `database/seeders/ClinicWorkflowSeeder.php` and
  `tests/Feature/Seeders/ClinicWorkflowSeederTest.php` already contain
  user-owned edits and must be merged surgically.
- Do not reset a database, execute the conversion command, or perform the
  contract migration against non-disposable data without explicit target
  confirmation.
- Do not delete an obsolete test before equivalent replacement coverage has
  passed at the preceding checkpoint.
- Stop and split a task if it grows beyond five files, more than three
  acceptance criteria, or one focused implementation session.
- No implementation task may change the approved ownership, availability,
  inventory, or staff-mutation boundaries without returning to the spec.
- The referenced `.agents/references/definition-of-done.md` file is absent.
  The enforceable project Definition of Done is therefore: focused and
  checkpoint tests pass, relevant runtime behavior is checked, Pint is clean,
  canonical docs match shipped behavior, no dead references remain, and the
  human review gate is satisfied.

---

## Phase 1: Account-Owned Patient API

### Task 1: Persist account-owned Saved Frames

**Description:** Deliver the smallest database/model slice: an account can own
one SavedFrame per ProductVariant with stable timestamps and cascade cleanup.

**Acceptance criteria:**

- [ ] `saved_frames` has required cascading FKs, unique
      (`user_id`, `product_variant_id`), and (`user_id`, `created_at`) index.
- [ ] `SavedFrame` exposes typed `account()` and `variant()` relationships;
      `User::savedFrames()` is typed and newest-first behavior is testable.
- [ ] Factory/model tests prove uniqueness, timestamps, and account/variant
      force-delete cascades without any inventory movement.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/SavedFrames/SavedFrameTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** None

**Files likely touched:**

- `database/migrations/*_create_saved_frames_table.php` (new)
- `app/Models/SavedFrame.php` (new)
- `database/factories/SavedFrameFactory.php` (new)
- `app/Models/User.php`
- `tests/Feature/SavedFrames/SavedFrameTest.php` (new)

**Estimated scope:** M (5 files)

### Task 2: Save an active frame through an idempotent PUT

**Description:** Complete the first patient-facing vertical slice from an
authenticated account through validation, persistence, and sanitized response.

**Acceptance criteria:**

- [ ] `PUT /api/v1/saved-frames/{productVariant}` works for linked and
      unlinked patient accounts and rejects missing/inactive/non-frame targets.
- [ ] Repeated and competing PUTs return `200`, create one row, and preserve
      the original `saved_at`.
- [ ] The response follows the approved SavedFrame shape and exposes no exact
      stock, ownership ID, cost, or internal catalog fields.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SavedFrameTest.php --filter=save`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 1

**Files likely touched:**

- `app/Actions/SavedFrames/SaveFrame.php` (new)
- `app/Http/Controllers/Api/SavedFrameController.php` (new)
- `app/Http/Resources/SavedFrameResource.php` (new)
- `routes/api.php`
- `tests/Feature/Api/V1/SavedFrameTest.php` (new)

**Estimated scope:** M (5 files)

### Task 3: Remove a saved frame idempotently

**Description:** Complete the unsave vertical slice without requiring an
active or existing ProductVariant model binding.

**Acceptance criteria:**

- [ ] `DELETE /api/v1/saved-frames/{productVariant}` deletes only the
      authenticated account's row and returns `204` when repeated or absent.
- [ ] One account cannot remove another account's preference.
- [ ] Removing an active or unavailable preference changes no stock and writes
      no inventory movement.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SavedFrameTest.php --filter=remove`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 2

**Files likely touched:**

- `app/Actions/SavedFrames/RemoveSavedFrame.php` (new)
- `app/Http/Controllers/Api/SavedFrameController.php`
- `tests/Feature/Api/V1/SavedFrameTest.php`

**Estimated scope:** M (3 files)

### Checkpoint A: Save/remove vertical slice

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/SavedFrames tests/Feature/Api/V1/SavedFrameTest.php`
- [ ] Linked and unlinked accounts can save and remove through the account-only
      middleware; unauthenticated callers receive `401`.
- [ ] Stock and inventory movement counts remain identical before and after
      every Saved Frames write.
- [ ] Existing reservation routes still pass during this additive phase.
- [ ] Pint is clean.

### Task 4: List saved frames with pagination and live availability

**Description:** Add the read slice for a persistent, unbounded newest-first
wishlist, including inactive and soft-deleted saved targets.

**Acceptance criteria:**

- [ ] `GET /api/v1/saved-frames` validates `page`/`per_page`, paginates at 15
      by default with max 50, and orders by original `saved_at` descending.
- [ ] Existing inactive, soft-deleted, and zero-stock saves remain readable as
      `unavailable`; eligible positive-stock targets are `available`.
- [ ] The list is account-scoped, sanitized, and has bounded query behavior.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SavedFrameTest.php --filter=list`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 3

**Files likely touched:**

- `app/Models/SavedFrame.php`
- `app/Http/Controllers/Api/SavedFrameController.php`
- `app/Http/Resources/SavedFrameResource.php`
- `tests/Feature/Api/V1/SavedFrameTest.php`

**Estimated scope:** M (4 files)

### Task 5: Expose account-specific catalog save state

**Description:** Let Android render the heart state directly from frame list
and detail responses without fetching every saved page.

**Acceptance criteria:**

- [ ] Every patient frame variant includes required boolean `is_saved` for the
      authenticated account on list and detail responses.
- [ ] The catalog query computes the flag without per-variant queries and does
      not alter existing AR, rating, image, or filtering behavior.
- [ ] `ProductVariant::savedFrames()` is typed and force-delete cleanup remains
      covered.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameCatalogTest.php tests/Feature/Api/V1/SavedFrameTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 4

**Files likely touched:**

- `app/Models/ProductVariant.php`
- `app/Http/Controllers/Api/FrameController.php`
- `app/Http/Resources/FrameVariantResource.php`
- `tests/Feature/Api/V1/FrameCatalogTest.php`

**Estimated scope:** M (4 files)

### Checkpoint B: Replacement API complete

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/SavedFrames tests/Feature/Api/V1/SavedFrameTest.php tests/Feature/Api/V1/FrameCatalogTest.php`
- [ ] `vendor/bin/sail artisan route:list --path=api/v1 --except-vendor`
      shows the three additive Saved Frames routes in the account-only group.
- [ ] Save, remove, list, availability, privacy, idempotency, and query-count
      assertions pass.
- [ ] Reservation behavior is still live and green pending conversion.
- [ ] Pint is clean.

---

## Phase 2: Read-Only Clinic Preference Context

### Task 6: Show all Preferred Frames on Patient Records

**Description:** Deliver the staff-facing full list through the Patient's
current account link, with no staff mutation capability.

**Acceptance criteria:**

- [ ] `Patient::savedFrames()` resolves through current `user_id`; unlinked
      Patients return none without copying or deleting account preferences.
- [ ] The Preferred Frames relation manager shows full newest-first frame,
      variant, SKU, thumbnail, saved time, exact quantity, and availability.
- [ ] The relation manager has distinct unlinked/empty states and no row,
      bulk, create, attach, detach, edit, or delete action.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/PreferredFramesTest.php --filter=patient`
- [ ] Manual: linked and unlinked Patient Record tabs display the correct
      read-only state.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 5

**Files likely touched:**

- `app/Models/Patient.php`
- `app/Filament/Resources/Patients/PatientResource.php`
- `app/Filament/Resources/Patients/RelationManagers/PreferredFramesRelationManager.php` (new)
- `tests/Feature/Filament/PreferredFramesTest.php` (new)

**Estimated scope:** M (4 files)

### Task 7: Show the latest three preferences on Appointments

**Description:** Add compact visit context to Appointment edit pages while
keeping the full list owned by Patient Records.

**Acceptance criteria:**

- [ ] Appointment edit shows at most three saves ordered strictly by newest
      `created_at`, with thumbnail, frame/variant, SKU, saved time, and badge.
- [ ] The compact section has correct unlinked/empty behavior and links to the
      Patient Record full list.
- [ ] The section is read-only and does not reorder by availability.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/PreferredFramesTest.php --filter=appointment`
- [ ] Manual: an Appointment with four saves displays the latest three and the
      full-list link works.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 6

**Files likely touched:**

- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`
- `resources/views/filament/components/preferred-frames-summary.blade.php` (new)
- `tests/Feature/Filament/PreferredFramesTest.php`

**Estimated scope:** M (4 files)

### Task 8: Show the latest three preferences on Consultations

**Description:** Reuse the compact preference presentation on Consultation edit
pages without persisting it into the Encounter.

**Acceptance criteria:**

- [ ] Consultation edit shows the same latest-three contract and live internal
      availability as Appointment edit.
- [ ] Completed or changed Consultations do not snapshot, copy, or mutate
      account preferences.
- [ ] The shared presentation stays accessible and has no mutation action.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/PreferredFramesTest.php --filter=consultation`
- [ ] Manual: planned, in-progress, and completed Consultation pages render the
      same account-owned preference state.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 7

**Files likely touched:**

- `app/Filament/Resources/Encounters/Pages/EditEncounter.php`
- `app/Filament/Resources/Encounters/Schemas/EncounterForm.php`
- `resources/views/filament/components/preferred-frames-summary.blade.php`
- `tests/Feature/Filament/PreferredFramesTest.php`

**Estimated scope:** M (4 files)

### Checkpoint C: Clinic surfaces complete

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/PreferredFramesTest.php tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Filament/EncounterResourceTest.php`
- [ ] Linked Patient full list and Appointment/Consultation latest-three views
      agree on account ownership and availability.
- [ ] Unlinking immediately removes staff visibility but the account API still
      returns the saves.
- [ ] Browser/runtime check finds no create, edit, remove, prepare, or hold
      affordance in Preferred Frames.
- [ ] Pint is clean.

---

## Phase 3: Reservation Conversion and Stock Release

### Task 9: Convert one reservation atomically

**Description:** Build the high-risk per-reservation conversion boundary before
the command orchestration: preserve eligible choices, release accepted holds,
and delete the source in one transaction.

**Acceptance criteria:**

- [ ] A linked reservation creates one SavedFrame per distinct variant while
      an unlinked reservation assigns no preference owner.
- [ ] Accepted items release exactly once in ascending variant-ID lock order;
      requested items produce no stock change or release movement.
- [ ] Any failure rolls back saves, stock, movements, items, and reservation;
      a retry completes once.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/SavedFrames/ConvertFrameReservationTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Checkpoint C

**Files likely touched:**

- `app/Actions/SavedFrames/ConvertFrameReservation.php` (new)
- `tests/Feature/SavedFrames/ConvertFrameReservationTest.php` (new)

**Estimated scope:** S (2 files)

### Task 10: Orchestrate dry-run and execute conversion

**Description:** Add the operator-facing command that reports scope without
writes by default and performs idempotent chunked conversion only explicitly.

**Acceptance criteria:**

- [ ] `saved-frames:migrate-reservations --dry-run --no-interaction` reports
      reservations, held items, releases, linked saves, and unlinked skips with
      zero writes.
- [ ] `--execute --no-interaction` processes deterministically in chunks,
      returns failure on any incomplete conversion, and is safe to rerun.
- [ ] Final output verifies zero source rows and exact stock/movement/save
      totals without logging patient details.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/SavedFrames/MigrateFrameReservationsCommandTest.php`
- [ ] `vendor/bin/sail artisan saved-frames:migrate-reservations --dry-run --no-interaction`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 9

**Files likely touched:**

- `app/Console/Commands/MigrateFrameReservationsToSavedFrames.php` (new)
- `tests/Feature/SavedFrames/MigrateFrameReservationsCommandTest.php` (new)

**Estimated scope:** S (2 files)

### Checkpoint D: Conversion mechanism proven

- [ ] Conversion action and command tests pass for accepted, requested,
      linked, unlinked, duplicate, rollback, retry, and dry-run paths.
- [ ] `--dry-run` reports but does not change stock, movements, preferences, or
      reservation rows.
- [ ] Broad inventory and Optical Order suites remain green.
- [ ] No real execute has been run without explicit target authorization.

### Task 11: Reconcile the clinic workflow demo safely

**Description:** Replace reservation terminology/data in the primary clinic
workflow demo without overwriting the user's existing seeder improvements.

**Acceptance criteria:**

- [ ] Demo linked accounts receive representative Saved Frames and no
      reservation hold is created.
- [ ] Existing unrelated workflow scenarios and assertions remain unchanged.
- [ ] Seeder reruns are idempotent and create no duplicate account/variant row.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Seeders/ClinicWorkflowSeederTest.php`
- [ ] Review the diff to confirm only Saved Frames-related hunks overlap the
      pre-existing user changes.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 10

**Files likely touched:**

- `database/seeders/ClinicWorkflowSeeder.php`
- `tests/Feature/Seeders/ClinicWorkflowSeederTest.php`

**Estimated scope:** S (2 files)

### Task 12: Reconcile scenario coverage data

**Description:** Replace `ScenarioCoverageSeeder` reservation fixtures with
account-owned Saved Frame scenarios used for manual evaluation.

**Acceptance criteria:**

- [ ] Scenario data includes linked Saved Frames with available and
      unavailable presentation cases.
- [ ] The seeder creates no FrameReservation, held stock, or reservation
      inventory movement.
- [ ] Repeated seeding preserves uniqueness and unrelated scenarios.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Seeders/ScenarioCoverageSeederTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 11

**Files likely touched:**

- `database/seeders/ScenarioCoverageSeeder.php`
- `tests/Feature/Seeders/ScenarioCoverageSeederTest.php` (new if absent)

**Estimated scope:** S (2 files)

### Checkpoint E: Data ready for cutover

- [ ] Focused Saved Frames, conversion, seeder, inventory, Appointment, and
      Optical Order tests pass.
- [ ] Authorized dry-run totals are reviewed; execute has either completed with
      zero source rows or the target is confirmed disposable and separately
      approved for reset.
- [ ] Accepted-item stock and release movements reconcile exactly.
- [ ] Saved Frames contains each eligible linked choice once and no unlinked
      choice has an invented owner.

### External Checkpoint: Android cutover confirmation

- [ ] Android uses `GET/PUT/DELETE /api/v1/saved-frames`.
- [ ] Frame catalog/AR hearts use variant `is_saved`.
- [ ] Saved lists handle pagination and `available`/`unavailable`.
- [ ] Appointment selection, reservation state, hold language, and expiry UI
      are removed.
- [ ] The no-guarantee copy is visible or readily accessible.
- [ ] Runtime/network verification shows zero calls to the five reservation
      routes.

**Gate:** Do not begin Task 13 until every item above is confirmed. If a
released old client exists, return to specification review for a deprecation
window.

---

## Phase 4: Reservation Contract Removal

### Task 13: Remove reservation effects from Appointment outcomes

**Description:** Make cancellation and no-show independent of preferences and
remove only reservation-specific expectations from shared Appointment tests.

**Acceptance criteria:**

- [ ] Cancel and no-show no longer resolve or delete a FrameReservation and
      retain all existing Appointment/audit behavior.
- [ ] Saved Frames survive cancellation and no-show unchanged.
- [ ] Obsolete cleanup tests are removed only after new persistence assertions
      pass.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments tests/Feature/Filament/AppointmentResourceTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** External Android checkpoint

**Files likely touched:**

- `app/Actions/Appointments/CancelAppointment.php`
- `app/Actions/Appointments/MarkAppointmentNoShow.php`
- `tests/Feature/Appointments/AppointmentReservationCleanupTest.php` (deleted/replaced)
- `tests/Feature/Filament/AppointmentResourceTest.php`

**Estimated scope:** M (4 files)

### Task 14: Remove the reservation expiry scheduler

**Description:** Retire the scheduled cleanup path after conversion leaves no
active reservation records.

**Acceptance criteria:**

- [ ] `reservations:expire` is absent from the scheduler and Artisan command
      list.
- [ ] No Saved Frames operation is scheduled or expires automatically.
- [ ] Other scheduled commands and overlap protection remain unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan list --raw`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 13

**Files likely touched:**

- `routes/console.php`
- `app/Console/Commands/ExpireFrameReservations.php` (deleted)

**Estimated scope:** S (2 files)

### Task 15: Retire obsolete reservation API behavior tests

**Description:** Remove the old endpoint behavior suite only after Checkpoint B
has proven the complete replacement API.

**Acceptance criteria:**

- [ ] The reservation endpoint feature test is deleted, not skipped.
- [ ] SavedFrame API coverage includes every replacement ownership, validation,
      serialization, and inventory-isolation behavior.
- [ ] No shared Appointment, catalog, auth, or route-contract coverage is lost.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SavedFrameTest.php tests/Feature/Api/V1/FrameCatalogTest.php`

**Dependencies:** Task 14, Checkpoint B

**Files likely touched:**

- `tests/Feature/Api/V1/FrameReservationTest.php` (deleted)

**Estimated scope:** XS (1 file)

### Checkpoint F: Appointment and sweep detached

- [ ] Appointment and scheduler suites pass.
- [ ] Saved Frames survive every Appointment lifecycle event.
- [ ] No scheduled process changes or deletes preferences.
- [ ] Replacement API coverage passes before old routes are removed.

### Task 16: Remove reservation routes and update auth contracts

**Description:** Perform the patient API cutover while keeping unused
controller/resource classes temporarily available for a smaller review.

**Acceptance criteria:**

- [ ] All five reservation routes are absent; three Saved Frames routes remain
      account-only and authenticated.
- [ ] Route/auth/link characterization expects Saved Frames for unlinked
      accounts and no reservation active-link surface.
- [ ] Final route count is 59: 8 public, 40 account-only, 11 active-link.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php tests/Feature/Api/V1/AuthContractCharacterizationTest.php tests/Feature/Api/V1/PatientLinkAccessCharacterizationTest.php`
- [ ] `vendor/bin/sail artisan route:list --path=api/v1 --except-vendor`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 15

**Files likely touched:**

- `routes/api.php`
- `tests/Feature/Api/V1/RouteContractTest.php`
- `tests/Feature/Api/V1/AuthContractCharacterizationTest.php`
- `tests/Feature/Api/V1/PatientLinkAccessCharacterizationTest.php`

**Estimated scope:** M (4 files)

### Task 17: Delete the unused reservation API controller boundary

**Description:** Remove the now-unreachable write/list controller and its input
request after routes and behavior tests are gone.

**Acceptance criteria:**

- [ ] No API route or class imports `FrameReservationController` or
      `StoreFrameReservationRequest`.
- [ ] Both obsolete classes are deleted with no compatibility adapter.
- [ ] Saved Frames API behavior remains unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SavedFrameTest.php tests/Feature/Api/V1/RouteContractTest.php`
- [ ] `rg -n "FrameReservationController|StoreFrameReservationRequest" app routes tests` returns no active reference.

**Dependencies:** Task 16

**Files likely touched:**

- `app/Http/Controllers/Api/FrameReservationController.php` (deleted)
- `app/Http/Requests/Api/StoreFrameReservationRequest.php` (deleted)

**Estimated scope:** S (2 files)

### Task 18: Delete reservation patient resources

**Description:** Remove the four unreachable reservation serializers after the
route/controller cutover.

**Acceptance criteria:**

- [ ] Reservation, item, Product, and variant reservation resources are
      deleted with no active imports.
- [ ] SavedFrame serialization remains the single preference representation.
- [ ] Frame catalog and Saved Frames sanitization tests remain green.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SavedFrameTest.php tests/Feature/Api/V1/FrameCatalogTest.php`
- [ ] `rg -n "FrameReservation(Resource|ItemResource|ProductResource|VariantResource)" app routes tests` returns no active reference.

**Dependencies:** Task 17

**Files likely touched:**

- `app/Http/Resources/FrameReservationResource.php` (deleted)
- `app/Http/Resources/FrameReservationItemResource.php` (deleted)
- `app/Http/Resources/FrameReservationProductResource.php` (deleted)
- `app/Http/Resources/FrameReservationVariantResource.php` (deleted)

**Estimated scope:** M (4 files)

### Checkpoint G: Patient contract removed

- [ ] Saved Frames and API contract suites pass.
- [ ] Route list contains no `frame-reservations` path.
- [ ] No reservation controller, request, or patient resource is reachable.
- [ ] Android cutover evidence remains valid against the final routes.

### Task 19: Retire obsolete reservation Filament tests

**Description:** Remove old reservation-only panel tests after Checkpoint C has
proven the replacement staff surfaces.

**Acceptance criteria:**

- [ ] Standalone reservation resource/item and Appointment reserve-frame tests
      are deleted, not skipped.
- [ ] Preferred Frames tests cover Patient, Appointment, Consultation,
      availability, empty states, and read-only behavior.
- [ ] Shared Appointment and navigation tests remain present.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/PreferredFramesTest.php tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Filament/AdminNavigationStructureTest.php`

**Dependencies:** Checkpoint C, Task 18

**Files likely touched:**

- `tests/Feature/Filament/AppointmentReserveFramesTest.php` (deleted)
- `tests/Feature/Filament/FrameReservationItemsRelationManagerTest.php` (deleted)
- `tests/Feature/Filament/FrameReservationResourceTest.php` (deleted)

**Estimated scope:** M (3 files)

### Task 20: Remove Appointment reservation controls

**Description:** Delete the old reserve/view actions and relation managers
while preserving the new compact Preferred Frames context.

**Acceptance criteria:**

- [ ] Appointment edit has no Reserve Frame or View Reservation action and
      retains the Preferred Frames summary.
- [ ] AppointmentResource registers no reservation relation manager.
- [ ] Both Appointment reservation relation-manager classes are deleted with
      no active import.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Filament/PreferredFramesTest.php`
- [ ] Manual: Appointment edit shows preferences but no reservation control.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 19

**Files likely touched:**

- `app/Filament/Resources/Appointments/AppointmentResource.php`
- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `app/Filament/Resources/Appointments/RelationManagers/FrameReservationItemsRelationManager.php` (deleted)
- `app/Filament/Resources/Appointments/RelationManagers/FrameReservationsRelationManager.php` (deleted)

**Estimated scope:** M (4 files)

### Checkpoint G.1: Appointment reservation controls removed

- [ ] Preferred Frames and Appointment resource tests pass.
- [ ] Appointment edit retains read-only preferences with no reservation
      action or relation manager.
- [ ] No deleted Appointment reservation class remains imported.

### Task 21: Remove Frame Reservations navigation and pages

**Description:** Remove the standalone Optical navigation entry and page shell
while updating shared navigation assertions in the same green slice.

**Acceptance criteria:**

- [ ] `FrameReservationResource` and its list/edit pages are deleted.
- [ ] Optical navigation contains Quotations, Optical Orders, and Frame Ratings
      without Frame Reservations; its badge no longer exists.
- [ ] Navigation structure/badge tests assert the final order and unique icons.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AdminNavigationStructureTest.php tests/Feature/AdminNavigationBadgeTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Checkpoint G.1

**Files likely touched:**

- `app/Filament/Resources/FrameReservations/FrameReservationResource.php` (deleted)
- `app/Filament/Resources/FrameReservations/Pages/ListFrameReservations.php` (deleted)
- `app/Filament/Resources/FrameReservations/Pages/EditFrameReservation.php` (deleted)
- `tests/Feature/Filament/AdminNavigationStructureTest.php`
- `tests/Feature/AdminNavigationBadgeTest.php`

**Estimated scope:** M (5 files)

### Task 22: Delete unreachable reservation Filament definitions

**Description:** Remove the orphaned table, schema, and item manager after the
resource shell is gone.

**Acceptance criteria:**

- [ ] Reservation form, table, and ItemsRelationManager definitions are
      deleted.
- [ ] No active Filament class imports a reservation action/model/resource.
- [ ] Preferred Frames and navigation tests remain green.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/PreferredFramesTest.php tests/Feature/Filament/AdminNavigationStructureTest.php`
- [ ] `rg -n "Filament\\\\Resources\\\\FrameReservations" app tests` returns no active reference.

**Dependencies:** Task 21

**Files likely touched:**

- `app/Filament/Resources/FrameReservations/Schemas/FrameReservationForm.php` (deleted)
- `app/Filament/Resources/FrameReservations/Tables/FrameReservationsTable.php` (deleted)
- `app/Filament/Resources/FrameReservations/RelationManagers/ItemsRelationManager.php` (deleted)

**Estimated scope:** M (3 files)

### Checkpoint H: Staff reservation surface removed

- [ ] Preferred Frames, Appointment, Consultation, navigation, and badge tests
      pass.
- [ ] Filament discovery has no Frame Reservations resource or action.
- [ ] Patient/Appointment/Consultation preference views remain read-only.
- [ ] Pint is clean.

### Task 23: Remove shared application reservation relationships

**Description:** Detach shared models and catalog lifecycle from active
reservation classes/tables while preserving historical ledger provenance.

**Acceptance criteria:**

- [ ] Appointment has no reservation/item relationship and InventoryMovement
      has no live FrameReservation relationship.
- [ ] CatalogLifecycle no longer queries `frame_reservation_items`; SavedFrames
      alone does not block an otherwise-valid force delete.
- [ ] Historical `inventory_movements.reservation_id` and movement type rows
      remain unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Catalog tests/Feature/Inventory tests/Feature/Appointments`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Checkpoint H, Checkpoint E

**Files likely touched:**

- `app/Models/Appointment.php`
- `app/Models/InventoryMovement.php`
- `app/Services/CatalogLifecycle.php`
- `tests/Feature/CatalogLifecycleTest.php`

**Estimated scope:** M (4 files)

### Task 24: Retire obsolete reservation domain tests

**Description:** Remove the old reservation action/model test suite only after
conversion, inventory isolation, and replacement coverage are all green.

**Acceptance criteria:**

- [ ] Five reservation-only Pest files are deleted, not skipped.
- [ ] Conversion tests retain accepted/requested release and retry coverage.
- [ ] SavedFrame tests retain model, API, ownership, lifecycle, and zero-stock-
      effect coverage.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/SavedFrames tests/Feature/Inventory tests/Feature/OpticalOrders`

**Dependencies:** Task 23, Checkpoint E

**Files likely touched:**

- `tests/Feature/Reservations/AddFrameReservationItemTest.php` (deleted)
- `tests/Feature/Reservations/CreateFrameReservationTest.php` (deleted)
- `tests/Feature/Reservations/FrameReservationStockTest.php` (deleted)
- `tests/Feature/Reservations/FrameReservationTest.php` (deleted)
- `tests/Feature/Reservations/RemoveFrameReservationItemTest.php` (deleted)

**Estimated scope:** M (5 files, deletions)

### Task 25: Retire one-time conversion tooling

**Description:** Remove the migration command/action after an authorized
conversion has completed and its zero-row report is retained in deployment
evidence.

**Acceptance criteria:**

- [ ] Conversion command and per-reservation action are deleted only after
      verified execution or explicitly approved disposable reset.
- [ ] Their focused tests are deleted after the deployment evidence satisfies
      the approved conversion criteria.
- [ ] No command or application class can create a new reservation reference.

**Verification:**

- [ ] `vendor/bin/sail artisan list --raw` contains neither reservation expiry
      nor reservation migration command.
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/SavedFrames tests/Feature/Inventory`

**Dependencies:** Task 24, authorized conversion completion

**Files likely touched:**

- `app/Actions/SavedFrames/ConvertFrameReservation.php` (deleted)
- `app/Console/Commands/MigrateFrameReservationsToSavedFrames.php` (deleted)
- `tests/Feature/SavedFrames/ConvertFrameReservationTest.php` (deleted)
- `tests/Feature/SavedFrames/MigrateFrameReservationsCommandTest.php` (deleted)

**Estimated scope:** M (4 files, deletions)

### Checkpoint I: Old consumers gone

- [ ] `rg -l "FrameReservation|frameReservation" app routes tests` lists only
      reservation-owned domain files scheduled for Tasks 26–28.
- [ ] Full Saved Frames, Appointment, Filament, catalog, inventory, and Optical
      Order affected suites pass.
- [ ] Conversion completion evidence proves zero reservation rows and balanced
      released stock.

### Task 26: Delete reservation creation and item actions

**Description:** Remove the first action group after every API, staff, shared,
and conversion consumer is gone.

**Acceptance criteria:**

- [ ] Create, Accept, Add-item, and Remove-item actions are deleted.
- [ ] No active PHP class imports or resolves those actions.
- [ ] SavedFrame and affected regression suites remain green.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/SavedFrames tests/Feature/Appointments tests/Feature/Filament`
- [ ] `rg -n "CreateFrameReservation|AcceptFrameReservation|AddFrameReservationItem|RemoveFrameReservationItem" app routes tests` returns no active reference.

**Dependencies:** Checkpoint I

**Files likely touched:**

- `app/Actions/Reservations/CreateFrameReservation.php` (deleted)
- `app/Actions/Reservations/AcceptFrameReservation.php` (deleted)
- `app/Actions/Reservations/AddFrameReservationItem.php` (deleted)
- `app/Actions/Reservations/RemoveFrameReservationItem.php` (deleted)

**Estimated scope:** M (4 files, deletions)

### Task 27: Delete reservation release actions

**Description:** Remove the remaining inventory-hold collaborators after the
conversion path and all consumers are gone.

**Acceptance criteria:**

- [ ] `DeleteFrameReservation` and `FrameReservationStock` are deleted.
- [ ] No new application path writes reservation allocation/release movements.
- [ ] Existing historical inventory and Optical Order tests remain green.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Inventory tests/Feature/OpticalOrders`
- [ ] `rg -n "DeleteFrameReservation|FrameReservationStock" app routes tests` returns no active reference.

**Dependencies:** Task 26

**Files likely touched:**

- `app/Actions/Reservations/DeleteFrameReservation.php` (deleted)
- `app/Actions/Reservations/FrameReservationStock.php` (deleted)

**Estimated scope:** S (2 files, deletions)

### Task 28: Delete reservation models, policy, and factories

**Description:** Remove the final active PHP domain types once every consumer
and behavior test is gone.

**Acceptance criteria:**

- [ ] FrameReservation, FrameReservationItem, their policy, and both factories
      are deleted.
- [ ] No active application/test import references either model.
- [ ] SavedFrame ownership and ProductVariant cascade behavior remain green.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/SavedFrames tests/Feature/Catalog tests/Feature/Inventory`
- [ ] `rg -n "FrameReservation|FrameReservationItem" app routes tests database/factories database/seeders` returns no active reference.

**Dependencies:** Task 27

**Files likely touched:**

- `app/Models/FrameReservation.php` (deleted)
- `app/Models/FrameReservationItem.php` (deleted)
- `app/Policies/FrameReservationPolicy.php` (deleted)
- `database/factories/FrameReservationFactory.php` (deleted)
- `database/factories/FrameReservationItemFactory.php` (deleted)

**Estimated scope:** M (5 files, deletions)

### Checkpoint J: Active reservation code gone

- [ ] `rg -l "FrameReservation|frameReservation|reservations:expire" app routes tests database/seeders` returns no active source reference.
- [ ] Old migration and historical documentation references are allowed and
      are not mistaken for live consumers.
- [ ] Affected suites and Pint pass before schema contraction.
- [ ] Database verification still reports zero reservation rows.

### Task 29: Drop empty reservation tables in a reversible migration

**Description:** Contract the active schema only after all consumers and rows
are gone, while keeping historical inventory provenance.

**Acceptance criteria:**

- [ ] Migration fails closed when either reservation table contains rows, then
      drops items before reservations only when both are empty.
- [ ] `down()` recreates the final pre-removal table shape and constraints for
      rollback without altering historical inventory rows.
- [ ] Migration tests exercise nonempty refusal, up, and down paths.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/SavedFrames/DropFrameReservationsMigrationTest.php`
- [ ] `vendor/bin/sail artisan migrate --no-interaction`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Checkpoint J

**Files likely touched:**

- `database/migrations/*_drop_frame_reservation_tables.php` (new)
- `tests/Feature/SavedFrames/DropFrameReservationsMigrationTest.php` (new)

**Estimated scope:** S (2 files)

### Checkpoint K: Contract removed

- [ ] Reservation tables are absent; `saved_frames` and historical inventory
      movements remain.
- [ ] Route count and navigation match the approved contract.
- [ ] Focused API, Filament, Appointment, catalog, inventory, and Optical Order
      suites pass.
- [ ] No runtime route, scheduler, policy, model, action, or UI refers to Frame
      Reservations.

---

## Phase 5: Canonical Documentation and Final Review

### Task 30: Publish the Saved Frames API contract

**Description:** Replace current reservation claims with the exact shipped API
shape only after the final route/resource behavior is verified.

**Acceptance criteria:**

- [ ] API contract documents GET/PUT/DELETE, pagination, ownership,
      availability, `is_saved`, errors, no-guarantee copy, and sanitization.
- [ ] Auth boundary and route inventory show 8 public, 40 account-only, 11
      active-link routes, total 59.
- [ ] No current-state section claims reservation routes or holds remain live;
      historical notes are clearly historical.

**Verification:**

- [ ] Compare `vendor/bin/sail artisan route:list --path=api/v1 --except-vendor`
      with every route listed in `docs/API_CONTRACT.md`.
- [ ] `git diff --check`

**Dependencies:** Checkpoint K

**Files likely touched:**

- `docs/API_CONTRACT.md`

**Estimated scope:** XS (1 file)

### Task 31: Reconcile backend context and historical specifications

**Description:** Make the living backend context describe Saved Frames while
preserving the reason and record of the superseded reservation design.

**Acceptance criteria:**

- [ ] Current-state table, action, navigation, API, inventory, Appointment,
      Android, and route-count sections describe Saved/Preferred Frames.
- [ ] Stale reservation-backed quotation text is removed or explicitly marked
      historical and superseded.
- [ ] The old reservation spec remains historical; the approved replacement
      spec remains the source of truth.

**Verification:**

- [ ] `rg -n -i "frame reservation|frame-reservation|frame_reservation|reserved|held" docs/BACKEND_CONTEXT.md docs/API_CONTRACT.md` is manually classified as historical or erroneous.
- [ ] `git diff --check`

**Dependencies:** Task 30

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`
- `docs/specs/frame-reservation-simplification-spec.md`

**Estimated scope:** S (2 files)

### Task 32: Complete full verification and close planning records

**Description:** Prove the approved success criteria end to end, complete the
quality review, and only then mark the feature records implemented.

**Acceptance criteria:**

- [ ] Every spec success criterion has linked test/runtime evidence; focused
      and full suites pass, Pint is clean, and no dead active reference remains.
- [ ] Five-axis review covers correctness, readability, architecture, security,
      and performance; any required finding is resolved and reverified.
- [ ] Spec, plan, feature checklist, and canonical pointers are marked complete
      only after implementation and review genuinely finish.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `vendor/bin/sail artisan route:list --path=api/v1 --except-vendor`
- [ ] Runtime: verify Patient, Appointment, and Consultation Preferred Frames
      plus Android Saved Frames/AR heart behavior.
- [ ] `git diff --check`

**Dependencies:** Task 31

**Files likely touched:**

- `docs/specs/saved-frames-replacement-spec.md`
- `tasks/saved-frames-replacement-plan.md`
- `tasks/saved-frames-replacement-todo.md`
- `tasks/plan.md`
- `tasks/todo.md`

**Estimated scope:** M (5 files)

---

## Parallelization Map

Planning identifies opportunities only; it does not authorize agent
delegation or changes outside this checklist. The checklist and remediation
were authorized by the project owner.

- Tasks 7 and 8 may proceed independently after Task 6, but they share the
  preferred-frames summary view and test file, so edits require coordination.
- Tasks 11 and 12 may proceed independently after Task 10 because the seeders
  are separate; Task 11 must preserve the existing user-owned dirty changes.
- After the Android gate, Task 14 may proceed alongside Task 15, but Task 16
  remains dependent on Task 15.
- API removal (Tasks 15–18) and Filament removal (Tasks 19–22) are logically
  independent after their respective replacement checkpoints, but the final
  shared-reference cleanup waits for both.
- Documentation Tasks 30 and 31 should stay sequential because route/API
  wording becomes an input to the living backend context.

## Final Implementation Gate

The project owner approved this checklist on 2026-08-26 and subsequently
authorized remediation implementation. The repository-side Saved Frames
remediation is complete; this does not authorize database reset, execution
against non-disposable data, deployment, or changes to the separate Android
repository beyond the documented coordination checkpoint.
