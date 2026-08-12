# Spec: Minimal Frame Reservations

## Status

**Rewritten and approved 2026-08-12.**

This replaces the earlier revision of this file, which kept a five-value status
enum, closure reasons, same-row reactivation, quotation backing, and
reservation-to-sale conversion. The project owner has since directed a deeper
cut: an appointment may have one reservation, a reservation has many frames,
staff accept it before stock is held, and nothing else.

`docs/decisions/002-use-clean-break-frame-reservation-contract.md` (clean break,
no deployed data, no compatibility layer) still applies. The lifecycle described
in `docs/ideas/frame-reservation-simplification.md` is superseded by this
document.

## Objective

Reduce Frame Reservations to the smallest model that still answers two
questions for clinic staff:

1. Which requests need my attention?
2. Which frames are physically set aside for this visit?

The whole feature becomes:

```text
Appointment ──(0..1)── FrameReservation ──(1..5)── FrameReservationItem → ProductVariant
```

There is no reservation status enum, no try-on step, no conversion to a sale,
no closure reason, no reactivation, and no link from a reservation to a
Quotation or Optical Order.

Success means a staff member can see incoming requests, accept one, see the
frames, add or remove frames, and release them — and that clinic stock is
exactly right at every step.

## Users

- Staff, optometrists, and administrators managing reservations in Filament
  (the Frame Reservations resource and the Appointment page).
- Linked patients creating, viewing, and deleting their own reservations
  through `/api/v1/frame-reservations`.

## Core Design Decision: Acceptance Is the Hold

A reservation has exactly two states, carried by **one nullable timestamp**
rather than a status enum:

| `accepted_at` | Meaning | Stock held? |
|---|---|---:|
| `null` | A request. The clinic has the patient's frame choices. | No |
| set | Staff accepted it. The frames are physically set aside. | Yes |

The resulting invariant:

> **A `frame_reservation_items` row holds exactly one unit of its product
> variant if and only if its parent reservation has `accepted_at` set.**

Every stock effect follows from that one rule:

| Event | Stock effect |
|---|---|
| Reservation created (staff or patient) | None |
| Reservation accepted, N frames | N × `reservation_allocation` (−1 each) |
| Frame added to an accepted reservation | 1 × `reservation_allocation` (−1) |
| Frame added to an unaccepted reservation | None |
| Frame removed from an accepted reservation | 1 × `reservation_release` (+1) |
| Frame removed from an unaccepted reservation | None |
| Reservation deleted while accepted | 1 × `reservation_release` (+1) per item |
| Reservation deleted while unaccepted | None |

Two states is the minimum that can express "the patient asked" separately from
"the clinic committed inventory." Collapsing them would let a patient take
frames off the shelf with no staff in the loop.

**There is no un-accept.** Acceptance is one-way. To stop holding frames, staff
delete the reservation — deletion *is* the release. This keeps the state space
at two rather than reintroducing transitions.

A reservation is **hard deleted**, not soft deleted. History is not lost:
`inventory_movements.reservation_id` is a plain nullable column with no foreign
key, so allocation and release ledger rows survive the parent row's deletion.
Hard deletion also keeps the existing unique index on
`frame_reservations.appointment_id` meaningful — an appointment whose
reservation was released can receive a fresh one.

There is no `accepted_by` column. The `reservation_allocation` movements
already carry `created_by`, so attribution lives in the ledger where it belongs
and is not duplicated on the reservation.

### Consequence: acceptance can fail on stock

Allocation happens at acceptance, so **Accept** can fail with an out-of-stock
validation error and allocate nothing. Creation never fails on stock — a
request is just a request. This is the right place for the failure: it happens
in front of a staff member who can act on it, not in the patient's app.

### Consequence: the hold is time-boxed at acceptance

Frames should not sit off the shelf for weeks. Acceptance is therefore bounded
by a **seven-day window**: staff may only accept a reservation when the
appointment is within seven days. This reuses the threshold already present in
the outgoing `PrepareFrameReservation`, which refuses to allocate stock more
than seven days before the appointment.

The window is a single named constant,
`AcceptFrameReservation::RESERVATION_WINDOW_DAYS`.

Creation is deliberately **not** window-checked. Patients may submit their
frame choices as far ahead as they like — the request costs nothing. Only the
inventory commitment is bounded.

Adding a frame to an already-accepted reservation is also not window-checked;
the reservation is already inside the window, and re-checking would block a
legitimate same-visit addition.

### Consequence: staff must release before confirming a sale

With reservation-to-sale conversion removed, `CommitJobOrderInventory` commits
the sold frame from ordinary catalog stock. If an accepted reservation still
holds that unit, the commit sees `stock_quantity` already decremented.

The failure mode is a **blocking validation error** ("Insufficient stock for
variant N"), not silent double-decrementing or negative stock. The fix is one
click: release the frames, then confirm the sale. This is acceptable and
self-correcting, and cheaper than reintroducing the linkage the owner asked to
remove. The operational order is documented for staff:

```text
fitting ends → Release Frames → build/confirm the Quotation
```

## Scope

### Included

- drop the `ReservationStatus` enum and the `status` column;
- replace it with a nullable `accepted_at` timestamp;
- allocate at acceptance and at item-add-while-accepted; release at
  item-remove-while-accepted and at deletion-while-accepted;
- a seven-day window bounding acceptance, not creation;
- hard delete replaces release/cancel/convert transitions;
- remove all Quotation and Optical Order reservation linkage, both columns and
  actions;
- replace the patient cancel endpoint with `DELETE`;
- a derived (not stored) expiry plus a simplified sweep command;
- appointment cancellation and no-show delete the reservation;
- appointment reschedule no longer touches reservations;
- rewritten Filament resource, relation managers, tests, and canonical docs.

### Excluded

- Android UI implementation (the API change is a contract change the app must
  follow; the app itself is out of scope here);
- reservation notifications of any kind;
- data migration, compatibility fields, or reconciliation commands;
- un-accepting a reservation;
- per-item status, quantities above one per frame, or non-frame products;
- changing the one-reservation-per-appointment invariant;
- changing the one-to-five candidate limit;
- reservations created after the visit (this stays a before-the-visit tool).

## Domain Model

### `frame_reservations`

| Column | Notes |
|---|---|
| `id` | |
| `patient_id` | FK, cascade on delete |
| `appointment_id` | FK, restrict on delete, **unique** |
| `accepted_at` | nullable timestamp — null means request, set means held |
| `staff_notes` | nullable text |
| `created_at` / `updated_at` | |

**Dropped:** `status`, `expires_at`, `released_at`, `released_by`,
`release_reason`, `deleted_at`.

The model exposes one derived accessor, `isHeld(): bool`
(`$this->accepted_at !== null`). No other code compares `accepted_at` to null
directly.

### `frame_reservation_items`

Unchanged: `frame_reservation_id`, `product_variant_id`, timestamps. Cascade
delete from the parent remains, but release is performed explicitly by the
delete action before the rows are removed, so cascade never silently drops a
hold without a ledger entry.

### Dropped elsewhere

| Column | Table |
|---|---|
| `frame_reservation_id` | `quotations` |
| `frame_reservation_id` | `job_orders` |

### Eligibility rules

Creation:

- the appointment must belong to the patient, be `scheduled`, and not have
  ended;
- one to five distinct variants;
- each variant must be an active variant of an active `frame` product;
- a variant may appear at most once in a reservation;
- **no window check** — a request may be submitted any time before the visit.

Acceptance:

- the reservation must not already be accepted (accepting twice is a no-op);
- the appointment must still be `scheduled` and not have ended;
- **the appointment must be within seven days**;
- every item must have at least one unit in stock.

## Actions

Final contents of `app/Actions/Reservations/`:

| Action | Behavior |
|---|---|
| `CreateFrameReservation` | Validates eligibility, locks the appointment, creates the row and its items. No stock effect. |
| `AcceptFrameReservation` | Validates the window and stock, allocates every item, stamps `accepted_at`. Idempotent. |
| `AddFrameReservationItem` | Validates the variant, creates the item, allocates it only if the reservation is accepted. |
| `RemoveFrameReservationItem` | Releases the item if accepted, deletes it. Removing the last item deletes the reservation. |
| `DeleteFrameReservation` | Releases every remaining item if accepted, deletes the reservation. Idempotent. |

Allocation and release live in one place — a single
`App\Actions\Reservations\FrameReservationStock` collaborator exposing
`allocate(FrameReservation, int $variantId)` and
`release(FrameReservation, int $variantId)` — so the actions above cannot drift
on lock order or movement shape.

**Deleted:** `PrepareFrameReservation` (replaced by `AcceptFrameReservation`),
`MarkFrameReservationTriedOn`, `ReleaseFrameReservation`,
`ConvertFrameReservationToJobOrder`, `CancelReservationsForAppointment`
(replaced by a direct call to `DeleteFrameReservation`),
`App\Enums\ReservationStatus`,
`App\Notifications\FrameReservationStatusChanged`,
`App\Actions\Quotations\ApplyQuotationFrameReservationSelection`,
`App\Actions\Quotations\ValidateQuotationFrameReservation`.

### Locking

Every stock-affecting operation runs in a transaction that locks the
reservation row first, then each affected `product_variants` row in ascending
ID order. This is the same order used today; keeping it identical avoids
introducing a deadlock class while the surrounding code shrinks.

Acceptance re-reads `accepted_at` under the reservation lock before allocating,
so two concurrent Accept clicks produce one allocation, not two.

## Expiry and Sweep

`expires_at` is not stored. The moment a reservation lapses is derived:

```text
expires_at = clinic close time (ClinicSchedule::forDate) on the appointment's scheduled date
```

Deriving it means a rescheduled appointment moves its own deadline with no
reservation write at all. It applies to both states: for an accepted
reservation it is when the frames go back on the shelf; for a request it is
when the unactioned request lapses.

`reservations:expire` keeps its signature and its fifteen-minute
`withoutOverlapping()` schedule, and is rewritten to delete — via
`DeleteFrameReservation`, so stock is restored and ledgered when the
reservation was accepted — every reservation whose appointment is either:

1. past its derived `expires_at`, or
2. no longer in `scheduled` status.

The class is renamed `ExpireFrameReservations` to match what it now does.

**Resolved:** the sweep fires at clinic close on the appointment date and does
*not* also release on appointment fulfillment. Releasing at fulfillment would
risk pulling the hold while staff are still writing up the sale. The cost is a
delayed release for a morning appointment whose staff forgot to release —
acceptable, since the only consequence is a frame staying off the shelf until
closing time that same day.

## Appointment Integration

| Appointment event | Reservation effect |
|---|---|
| Cancelled | Deleted (stock released if accepted) |
| No-show | Deleted (stock released if accepted) |
| Rescheduled | **None** — the reservation follows the appointment; the derived expiry moves with it |
| Checked in / fulfilled | None — staff release manually at the end of the fitting; the sweep is the fallback |

Appointment mutation, reservation deletion, inventory movements, and audit
records commit or roll back together.

The reservation-release block in `RescheduleAppointment` is removed outright.

## Quotation and Optical Order Integration

There is none. Specifically:

- `quotations.frame_reservation_id` and `job_orders.frame_reservation_id` are
  dropped, along with `Quotation::frameReservation()`,
  `JobOrder::frameReservation()`, and `FrameReservation::jobOrder()`.
- `frame_reservation_id` / `frame_reservation_item_id` are removed from the
  `CreateQuotation` and `UpdateQuotationDraft` payloads and validation rules.
- The reservation blocks in `ConfirmQuotationSale` and
  `AcceptAndStartOpticalOrder` are removed; both simply commit inventory.
- `FrameReservationItem::scopeEligibleForQuotation` is removed.
- The **Use in Quotation** action is removed from both relation managers.
- `CommitJobOrderInventory::$excludeProductVariantIds` is removed. It exists
  only for the reservation-conversion path and is already dead — no caller
  passes a non-empty array.

Staff build a quotation by selecting the frame from the catalog, exactly as
they would for a walk-in.

## Patient API Contract

### Routes

```text
GET    /api/v1/frame-reservations
POST   /api/v1/frame-reservations
DELETE /api/v1/frame-reservations/{reservation}
```

`POST /frame-reservations/{reservation}/cancel` is removed. Authentication,
active-patient-link requirement, ownership scoping, request shape, and the
one-to-five limit are unchanged.

### Response shape

```json
{
  "id": 12,
  "appointment_id": 340,
  "is_held": true,
  "expires_at": "2026-08-20T18:00:00+08:00",
  "created_at": "2026-08-12T09:14:22+08:00",
  "appointment": { "...": "AppointmentContextResource" },
  "items": [{ "...": "FrameReservationItemResource" }]
}
```

`status` is gone. `is_held` replaces it and is derived from `accepted_at`; the
raw timestamp is not exposed. `expires_at` remains in the contract but is now
derived rather than stored.

Android presentation:

| `is_held` | Patient label |
|---|---|
| `false` | "Request sent — the clinic will set these aside before your visit." |
| `true` | "Set aside for your visit until {expires_at}." |

The API exposes no stock quantities, staff notes, acceptance attribution, or
internal identifiers beyond the patient's own records.

`POST` never fails on stock. It rejects an ineligible appointment or an
inactive frame with a `422`. `DELETE` returns `204` for the owning patient in
either state, `403` otherwise, and `404` for an already-deleted reservation.

## Authorization and Privacy

- `FrameReservationPolicy` remains authoritative for Filament. Its methods
  reduce to `viewAny`, `view`, `create`, `reserveFrames`, `accept`, `addFrame`,
  `removeFrame`, and `delete` — all `hasPanelRole()`. The duplicated
  `update`/`addOrRemoveItems` indirection is removed.
- Mobile ownership is derived server-side from the authenticated account's
  patient, never from the request.
- Audit metadata stays identifier-only.

## Filament Staff Experience

### Frame Reservations resource

- Table columns: patient, appointment date, frame count, held state, expires
  at, created.
- Held state renders as a two-value badge: **Awaiting acceptance** or
  **Frames set aside**. No status column, no lifecycle tabs.
- Two tabs: **Awaiting acceptance** and **Set aside**. The navigation badge
  counts unaccepted reservations only — that is the number representing work
  to do.
- Row actions: **Accept & Set Aside** (unaccepted only, confirms and reports
  the allocation), **Release Frames** (delete, with confirmation, both states).
- `ItemsRelationManager`: **Add Frame** header action, **Remove Frame** row
  action, both available in either state. No Use in Quotation, no Prepare, no
  Mark Tried On.

### Appointment page

`FrameReservationsRelationManager` and `FrameReservationItemsRelationManager`
keep the same jobs — show the reservation, accept it, add/remove frames — with
all status-conditional visibility replaced by the single held/not-held check.

Filament visibility is never the authorization boundary; policies and actions
remain authoritative.

## Technical Context

- PHP 8.5, Laravel 13, Filament 5, MySQL via Sail, Pest 4 / PHPUnit 12.
- No new dependency. One migration: six column drops, one column add, two
  foreign-key column drops on other tables.

## Project Structure

```text
app/Actions/Reservations/          → 5 actions + FrameReservationStock
app/Console/Commands/ExpireFrameReservations.php
app/Http/Controllers/Api/FrameReservationController.php
app/Http/Resources/FrameReservation*.php
app/Filament/Resources/FrameReservations/
app/Filament/Resources/Appointments/RelationManagers/
app/Models/FrameReservation.php, FrameReservationItem.php
app/Policies/FrameReservationPolicy.php
database/migrations/                → one migration
tests/Feature/Reservations/
tests/Feature/Api/V1/FrameReservationTest.php
tests/Feature/Filament/
docs/API_CONTRACT.md, docs/BACKEND_CONTEXT.md
```

## Code Style

Explicit types, constructor promotion, curly braces, domain actions for every
mutation, row locks for every stock change:

```php
public function handle(FrameReservation $reservation): void
{
    DB::transaction(function () use ($reservation): void {
        $locked = FrameReservation::query()
            ->lockForUpdate()
            ->find($reservation->id);

        if ($locked === null) {
            return; // Already released; deletion is idempotent.
        }

        if ($locked->isHeld()) {
            foreach ($locked->items()->orderBy('product_variant_id')->get() as $item) {
                $this->stock->release($locked, $item->product_variant_id);
            }
        }

        $locked->items()->delete();
        $locked->delete();
    });
}
```

## Testing Strategy

Pest feature tests with `RefreshDatabase`, written or updated before the
behavior. Required coverage:

1. creation moves no stock, in either the staff or patient path;
2. creation is allowed regardless of how far away the appointment is;
3. acceptance allocates one unit per frame and writes one movement each;
4. acceptance fails and allocates nothing when any frame is out of stock;
5. acceptance is rejected outside the seven-day window; acceptance at the
   boundary succeeds;
6. accepting twice allocates once, including under concurrent calls;
7. adding a frame allocates only when accepted; removing a frame releases only
   when accepted; removing the last frame deletes the reservation;
8. deleting an accepted reservation releases every unit exactly once and is
   idempotent; deleting an unaccepted one moves no stock;
9. the sweep deletes past-deadline and non-scheduled reservations in both
   states, restores stock only for accepted ones, and leaves current ones
   alone;
10. appointment cancellation and no-show release stock transactionally;
11. appointment reschedule leaves the reservation and its stock untouched;
12. the API returns `is_held` and derived `expires_at` and never `status` or
    `accepted_at`; `DELETE` enforces ownership in both states;
13. an appointment can receive a new reservation after the previous one is
    released (unique constraint still holds);
14. no production code, test, factory, or document references
    `ReservationStatus`, `tried_on`, `converted`, or a reservation-to-quotation
    link;
15. quotation confirmation and optical-order acceptance still commit inventory
    correctly with the reservation code removed;
16. Filament tabs, badge count, and the visibility of Accept versus Release
    follow the held state, with policy enforcement intact.

**Tests deleted:** `MarkFrameReservationTriedOnTest`,
`ConvertFrameReservationToJobOrderTest`, `ReleaseFrameReservationTest`,
`ReservationLifecycleTest`, `FrameReservationQuotationSelectionTest`,
`FrameReservationSaleConversionTest`,
`OpticalOrders/FrameReservationJobOrderLinkTest`.

## Commands

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reservations
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameReservationTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments
vendor/bin/sail artisan test --compact tests/Feature/Quotations
vendor/bin/sail artisan test --compact tests/Feature/Filament
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail artisan test --compact
```

## Boundaries

### Always

- keep inventory movements append-only and one-per-unit;
- perform every stock change inside a transaction with row locks in ascending
  variant-ID order;
- gate every allocation and release on the reservation's held state;
- derive patient and appointment ownership server-side;
- update focused tests, `docs/API_CONTRACT.md`, and `docs/BACKEND_CONTEXT.md`
  in the same change;
- run through Sail: focused tests, Pint, then the full compact suite.

### Ask first

- adding an un-accept transition;
- changing the one-reservation-per-appointment invariant;
- changing the seven-day acceptance window;
- reintroducing any reservation-to-quotation or reservation-to-order link;
- storing an expiry column instead of deriving it;
- changing the one-to-five candidate limit;
- adding notifications.

### Never

- hold stock for an unaccepted reservation;
- let an accepted reservation's item exist without a matching stock hold;
- delete or rewrite inventory movement rows;
- reintroduce a reservation status column, `tried_on`, or `converted`;
- rely on Filament visibility as authorization;
- expose stock quantities, staff notes, or `accepted_at` through the patient
  API.

## Deployment

One release. Per ADR-002 there is no production data, so the migration drops
columns without backfill. Any development database containing reservations must
be re-migrated or reset before use, because the drop is not reversible with
data intact.

## Success Criteria

1. `frame_reservations` has exactly `id`, `patient_id`, `appointment_id`,
   `accepted_at`, `staff_notes`, and timestamps.
2. `ReservationStatus`, `tried_on`, `converted`, and reservation-to-sale
   conversion are absent from code, tests, and canonical docs.
3. `quotations` and `job_orders` no longer carry `frame_reservation_id`.
4. A request holds no stock; acceptance holds exactly one unit per frame.
5. Stock is exact under create, accept, add, remove, delete, sweep, appointment
   cancellation, and appointment no-show — one movement per unit, no double
   counting, none at all while unaccepted.
6. No frame is held more than seven days: acceptance outside the window is
   rejected.
7. An appointment can hold at most one reservation, and can receive a new one
   after release.
8. The patient API exposes `is_held` and derived `expires_at`, no persistence
   status and no `accepted_at`, and `DELETE` replaces the cancel endpoint.
9. Filament shows two tabs, an accept action, a release action, and add/remove
   frame; no other lifecycle controls remain.
10. `app/Actions/Reservations/` contains five actions plus one stock
    collaborator.
11. Focused and full test suites pass; Pint reports no changes.

## Open Questions

1. **Android coordination.** `POST .../cancel` → `DELETE`, `status` → `is_held`,
   and the new request-versus-held distinction are breaking client changes. The
   backend can ship first only if the app is not yet released against these
   routes. *Assumed safe per ADR-002; confirm before deploy.*
