# Implementation Plan: Replace Frame Reservations with Saved Frames

**Status:** Approved by the project owner on 2026-08-26
**Specification:** `docs/specs/saved-frames-replacement-spec.md` (approved 2026-08-26)
**Decision:** `docs/decisions/003-replace-frame-reservations-with-saved-frames.md`
**Checklist:** `tasks/saved-frames-replacement-todo.md` (approved 2026-08-26; implementation paused)

## Overview

Replace the appointment-bound, stock-holding Frame Reservations feature with
account-owned Saved Frames. The work is an expand → convert → contract change:
build and verify the non-holding replacement first, expose it to patients and
staff, safely release and convert existing reservation records, confirm the
Android cutover, and only then remove the reservation contract and schema.

Each checkpoint must leave the application in a runnable, testable state. No
contraction task may begin while an old client or reservation row remains.

## Architecture Decisions

### 1. Saved Frames is account-owned

`saved_frames.user_id` is the ownership and API authorization boundary.
Unlinked accounts can save frames because frame browsing and AR are already
account-only. Filament resolves preferences through the Patient's current
`user_id`, so unlinking changes staff visibility without moving or deleting
preference rows.

### 2. The API is keyed by ProductVariant

The patient contract has three idempotent endpoints:

```http
GET    /api/v1/saved-frames
PUT    /api/v1/saved-frames/{productVariant}
DELETE /api/v1/saved-frames/{productVariant}
```

Android does not need an internal SavedFrame ID. ProductVariant identity is
already present in catalog and AR responses, and the unique
`(user_id, product_variant_id)` index is the concurrency boundary.

### 3. Availability is derived, not persisted

Saved records survive stock depletion and catalog deactivation. Patient reads
derive only `available` or `unavailable`; Filament derives the richer internal
availability badge and may show exact available quantity. There is no
availability snapshot to reconcile.

### 4. Saved Frames never enters commerce or inventory

The new domain has no appointment, expiry, acceptance, stock collaborator,
inventory movement, Quotation reference, or Optical Order reference. Existing
Optical Order actions remain the only paths that commit frame stock.

### 5. Destructive removal is a separate final phase

The additive schema and replacement behavior land before any reservation
surface is removed. An idempotent conversion command releases every accepted
item and preserves eligible linked choices. A later contract migration refuses
to drop reservation tables unless they are empty.

Historical inventory movements and their legacy `reservation_id` provenance
remain append-only after the active reservation tables disappear.

## Dependency Graph

```text
Approved spec + ADR
        │
        ▼
SavedFrame schema/model/relationships
        │
        ├───────────────┐
        ▼               ▼
Save/remove API     List/catalog API
        └───────┬───────┘
                ▼
      Replacement API checkpoint
                │
        ┌───────┼───────────┐
        ▼       ▼           ▼
 Patient UI  Appointment  Consultation
 preferred    context       context
 frames
        └───────┼───────────┘
                ▼
        Staff surfaces checkpoint
                │
                ▼
  Reservation conversion dry-run/execute
                │
                ▼
    Zero rows + exact stock verification
                │
                ▼
       Android cutover confirmation
                │
        ┌───────┴────────┐
        ▼                ▼
 Remove patient API   Remove Filament/
 and resources        appointment/sweep
        └───────┬────────┘
                ▼
 Remove domain code and drop active tables
                │
                ▼
 Canonical docs + full regression checkpoint
```

## Implementation Phases

### Phase 1: Account-owned replacement foundation

1. Create the additive `saved_frames` migration, `SavedFrame` model, factory,
   relationships, and foundational Pest coverage.
2. Add test-first `SaveFrame` and `RemoveSavedFrame` actions with idempotent,
   account-scoped behavior and no inventory effects.
3. Add `SavedFrameController` write endpoints to the account-only route group,
   validating active frame eligibility at both HTTP and action boundaries.
4. Add the paginated list resource and endpoint, including unavailable
   inactive/trashed targets and sanitized nested frame data.
5. Add account-specific `is_saved` to catalog list/detail queries without an
   N+1 regression.

#### Checkpoint: Replacement API

- focused Saved Frames and API tests pass;
- linked and unlinked accounts can save, list, and remove;
- repeated PUT and DELETE are idempotent;
- another account's preference is never visible or mutable;
- save/remove/list operations leave stock and inventory movement counts
  unchanged;
- inactive saved frames remain readable as unavailable;
- frame catalog list/detail returns the correct `is_saved` value;
- the five reservation routes and existing behavior still run during this
  additive checkpoint.

### Phase 2: Read-only clinic preference surfaces

6. Add the full newest-first Preferred Frames relation manager to Patient
   Records, including distinct unlinked/empty states and internal availability.
7. Add the latest-three Preferred Frames context to Appointment edit pages,
   with a link to the full Patient Record relation manager.
8. Add the same latest-three context to Consultation edit pages without
   copying preference data into the Encounter.
9. Add Filament tests proving current-link visibility, latest-three ordering,
   availability labels, and the absence of mutation actions.

#### Checkpoint: Staff surfaces

- affected Filament tests pass with authenticated panel users;
- linked Patient Records show the full list and unlinked records show none;
- Appointment and Consultation display exactly the newest three;
- deactivated and out-of-stock frames are labelled correctly;
- no staff action can create, remove, rank, hold, or prepare a preference;
- existing Frame Reservations remains operational until conversion.

### Phase 3: Existing-data conversion and stock release

10. Add a test-first `saved-frames:migrate-reservations` command with
    `--dry-run`, `--execute`, and `--no-interaction` support.
11. Convert one locked reservation per transaction: preserve choices for the
    Patient's currently linked account, release accepted items in ascending
    variant-ID lock order, and delete the reservation atomically.
12. Add verification/reporting for reservation counts, linked/unlinked choice
    outcomes, release movement counts, and exact stock deltas.
13. Reconcile scenario/demo seed data so a fresh database demonstrates Saved
    Frames rather than creating reservation holds. Preserve any overlapping
    user changes already present in `ClinicWorkflowSeeder` and its test.

#### Checkpoint: Conversion safety

- dry-run reports effects without writes;
- execute is retry-safe and leaves no partial conversion;
- accepted items release exactly once and requested items release nothing;
- linked choices exist once; unlinked choices remain unowned;
- `frame_reservations` and `frame_reservation_items` are empty;
- direct/quotation Optical Order inventory tests remain green;
- a disposable database reset is not used without explicit authorization.

### External checkpoint: Android cutover

Before contraction, confirm that the Android client:

- calls the three Saved Frames endpoints;
- uses variant `is_saved` for the AR/catalog heart state;
- handles paginated saved lists and `available`/`unavailable`;
- removes appointment selection, hold status, expiry, and reservation copy;
- displays the no-guarantee message; and
- makes zero requests to the five reservation routes.

This checkpoint is sequential and blocking. Discovery of a released old
client returns the work to specification review for a deprecation window.

### Phase 4: Reservation contract removal

14. Remove reservation cleanup from appointment cancellation/no-show and
    remove the expiry command plus scheduler registration.
15. Remove the five reservation routes, controller, requests, patient
    resources, ownership tests, and active-link contract references.
16. Remove the Frame Reservations Filament resource, navigation/badge,
    Appointment actions, and reservation relation managers.
17. Remove reservation actions, models, policy, factories, and remaining
    application relationships after a dead-reference search.
18. Add the reversible contract migration that checks for zero reservation
    rows before dropping `frame_reservation_items` and then
    `frame_reservations`.
19. Remove/replace obsolete reservation-only tests only after equivalent Saved
    Frames, Appointment, catalog, and inventory regression coverage exists.

#### Checkpoint: Contract removed

- route listing contains three Saved Frames routes and no reservation route;
- Optical navigation and Appointment actions contain no reservation surface;
- no scheduler references `reservations:expire`;
- no active application class references `FrameReservation`;
- reservation tables are absent while historical inventory movements remain;
- expected route count is 59: 8 public, 40 account-only, 11 active-link;
- affected and full Pest suites pass; Pint is clean.

### Phase 5: Canonical documentation and final reconciliation

20. Replace the Frame Reservations section in `docs/API_CONTRACT.md` with the
    exact Saved Frames contract and update route counts/boundaries.
21. Update `docs/BACKEND_CONTEXT.md` current-state, table, action, navigation,
    API, inventory, and Android handoff sections; mark stale historical
    reservation-commerce text clearly.
22. Re-run focused and full verification, perform a dead-reference search, and
    complete a five-axis review before handoff.

#### Checkpoint: Complete

- every success criterion in the approved specification is evidenced;
- canonical documentation describes the shipped state and historical specs
  remain marked superseded;
- no patient-facing text implies a hold or availability guarantee;
- full test suite and formatter pass;
- the change is ready for human review and deployment planning.

## Verification Strategy

Run the smallest affected tests during each slice, then broaden at checkpoints.
All commands use Laravel Sail.

```bash
vendor/bin/sail artisan test --compact tests/Feature/SavedFrames
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SavedFrameTest.php
vendor/bin/sail artisan test --compact tests/Feature/Filament/PreferredFramesTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments tests/Feature/Inventory tests/Feature/OpticalOrders
vendor/bin/sail artisan saved-frames:migrate-reservations --dry-run --no-interaction
vendor/bin/sail artisan route:list --path=api/v1 --except-vendor
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail artisan test --compact
```

Runtime/manual verification is required after implementation for the Patient,
Appointment, and Consultation Filament surfaces and for the Android handoff.

## Sequential and Parallel Work

### Must remain sequential

- additive schema before any SavedFrame model/action/API work;
- replacement API before Android integration;
- conversion dry-run before execute;
- successful conversion and Android confirmation before contraction;
- application reference removal before the contract migration;
- contract removal before canonical documentation claims the feature shipped.

### Safe after shared foundations land

- Patient, Appointment, and Consultation read-only Filament surfaces can be
  developed independently once the SavedFrame relationships are stable;
- API ownership tests and Filament read-only tests can be extended in parallel
  with their corresponding already-defined contracts;
- API and backend-context documentation updates can be drafted in parallel
  after the final route/schema shape is fixed, but they become canonical only
  after verification.

Parallel work must not edit the same relationship, route, navigation, or
canonical documentation file without coordination.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Accepted holds are removed without restoring stock | High | Conversion action reuses the release boundary under locks; exact stock/movement assertions block contraction. |
| Partial conversion duplicates a release | High | One transaction per locked reservation; deletion commits with the release; retry tests. |
| Old Android client calls removed endpoints | High | External cutover checkpoint blocks contraction; revisit deprecation if a released client exists. |
| Account A sees account B's preferences | High | Derive account from Sanctum, unique ownership scope, cross-account API tests. |
| Staff still sees saves after unlinking | High | Resolve through current `patients.user_id` on every query; unlink regression tests. |
| Catalog `is_saved` adds N+1 queries | Medium | Account-scoped `withExists`/eager loading plus query-count test. |
| Inactive preferences disappear | Medium | Use with-trashed catalog relations on Saved Frame reads; lifecycle tests. |
| Cleanup deletes shared inventory/appointment behavior | High | Replace tests before deleting reservation-only coverage; broad checkpoint suites. |
| Current seeder edits are overwritten | Medium | Treat existing `ClinicWorkflowSeeder` and test changes as user-owned; merge surgically. |
| Plan tasks grow beyond five files | Medium | Phase 3 checklist splits each numbered item further before implementation. |

## Open Questions

No product question blocks task decomposition. The only deployment gate is
confirmation that no released Android client depends on Frame Reservations.

## Checklist Review Gate

The detailed checklist in `tasks/saved-frames-replacement-todo.md` was approved
on 2026-08-26. The project owner explicitly paused implementation, so do not
begin Task 1 until a later instruction to proceed.
