# Spec: Replace Frame Reservations with Saved Frames

## Status

**Approved by the project owner on 2026-08-26.**

Implementation and remediation are complete in the repository state dated
2026-08-26. The replacement API, read-only clinic surfaces, legacy conversion
guard, contract migration, tests, and canonical documentation are verified;
Android cutover remains an external deployment checkpoint.

This specification supersedes
`docs/specs/frame-reservation-simplification-spec.md`. The architectural reason
is recorded in
`docs/decisions/003-replace-frame-reservations-with-saved-frames.md`.

## Objective

Replace appointment-bound Frame Reservations with an account-owned **Saved
Frames** feature that lets patients record frames they liked while browsing or
using AR without withholding sellable inventory from the clinic.

The feature serves two users:

- patients use a familiar save/heart interaction to build a persistent frame
  shortlist;
- clinic staff use that shortlist as read-only preference context during a
  visit.

Success means patients can save and remove frames before they have an active
Patient link, linked clinic staff can see those preferences, and no save,
remove, link, unlink, or viewing operation changes stock or creates an
inventory movement.

The core promise is:

> Saving a frame records interest only. It never reserves stock or guarantees
> availability.

## Confirmed Decisions and Assumptions

1. **Saved Frames** is the patient-facing label. **Preferred Frames** is the
   clinic-facing label for the same records.
2. A saved frame belongs to the authenticated `User` account, not a `Patient`,
   Appointment, Consultation, Quotation, or Optical Order.
3. Any authenticated patient account may save frames, including an unlinked
   account that can already browse the frame catalog and use AR.
4. Clinic staff may see an account's saved frames only while that account is
   the current account linked through `patients.user_id`.
5. Saved frames persist until the account removes them. They do not expire and
   have no hard count limit.
6. The Patient Record exposes the full preference list. Appointment and
   Consultation surfaces show the three most recently saved frames and link to
   the full Patient Record list.
7. Inactive, soft-deleted, and out-of-stock variants remain saved and are shown
   as unavailable. A force-deleted variant removes its saved rows through a
   database cascade.
8. The clinic can sell a saved frame to another customer at any time. Stock is
   committed only by the existing Optical Order workflows.
9. Staff can view preferences but cannot add, remove, rank, accept, prepare, or
   otherwise mutate them for the patient.
10. The existing backend and Android reservation contract has not been
    released to production. The replacement uses a coordinated clean cutover,
    consistent with the premise of ADR-002, rather than an indefinite
    compatibility API.
11. Existing non-disposable records are converted safely before reservation
    tables are removed: held stock is released, and choices belonging to a
    linked account become saved frames.

If assumption 10 is no longer true before implementation, stop and replace the
clean cutover with a separately approved client deprecation plan.

## Terminology

| Surface | Term | Meaning |
|---|---|---|
| Android/API documentation | Saved Frame | An account-owned frame-variant preference. |
| Filament | Preferred Frame | The same saved record, presented as visit context. |
| Inventory | Available stock | Live variant stock controlled only by inventory and sale workflows. |
| Removed term | Reservation / hold / set aside | No longer supported by this feature. |

The API and PHP domain use `SavedFrame`; they do not encode two domain concepts
for the two presentation labels.

## Domain Invariants

```text
User ──(0..*)── SavedFrame ──(*..1)── ProductVariant ──(*..1)── Product(frame)

Patient ──(0..1 current link via user_id)── User
```

The following invariants are mandatory:

1. One account can save a given variant at most once.
2. A new save may target only an active, non-deleted variant belonging to an
   active, non-deleted `frame` Product.
3. An existing save survives later catalog deactivation, soft deletion, or
   stock depletion and becomes unavailable in read models.
4. Saving the same variant repeatedly is idempotent and does not create a
   duplicate or change its original `saved_at` ordering timestamp.
5. Removing a saved variant repeatedly is idempotent.
6. No Saved Frames code may update `product_variants.stock_quantity` or write
   `inventory_movements`.
7. Linking and unlinking change only staff visibility. They do not copy,
   transfer, or delete account-owned saves.
8. Saved Frames never become a source reference on Quotations, Optical Orders,
   Billing Records, or Inventory Movements.

## Scope

### Included

- account-owned Saved Frame persistence and relationships;
- account-only patient API for listing, saving, and removing frame variants;
- an additive `is_saved` field on frame-catalog variants;
- coarse, patient-safe live availability on Saved Frame responses;
- read-only Preferred Frames surfaces in the Patient, Appointment, and
  Consultation Filament workflows;
- removal of reservation API routes, resources, actions, models, policy,
  command, schedule, Filament resource, appointment integrations, factories,
  and obsolete tests;
- safe release and conversion of any existing reservation records;
- coordinated Android contract handoff;
- updates to the authoritative API/context documentation when implementation
  ships;
- correction or explicit historical marking of stale reservation-backed
  commerce documentation.

### Not Doing

- inventory allocation, frame holds, or availability guarantees;
- appointment binding, acceptance, expiry, no-show cleanup, or staff queues;
- deposits or payments for future reservations;
- staff-created or staff-edited patient preferences;
- preference ranking, notes, folders, sharing, recommendations, or alerts;
- automatically preparing frames before a visit;
- direct conversion from a Saved Frame into a Quotation or Optical Order;
- exposing exact stock quantity, reorder thresholds, cost, or inactive reasons
  to Android;
- analytics based on aggregate patient preference data;
- compatibility aliases that translate old reservation writes into saves.

A deposit-backed hold may be designed later as a separate commercial feature.
It must not reuse `SavedFrame` or silently change Saved Frames semantics.

## Data Model

### `saved_frames`

| Column | Contract |
|---|---|
| `id` | Primary key; internal only and not required by the patient API. |
| `user_id` | Required FK to `users.id`, cascade on delete. |
| `product_variant_id` | Required FK to `product_variants.id`, cascade on force delete. |
| `created_at` | The stable `saved_at` value used for newest-first ordering. |
| `updated_at` | Conventional timestamp; idempotent repeated saves do not touch it. |

Constraints and indexes:

- unique (`user_id`, `product_variant_id`);
- index (`user_id`, `created_at`) for account lists;
- foreign-key indexes supplied by Laravel migrations.

There is deliberately no `patient_id`, `appointment_id`, `status`,
`accepted_at`, `expires_at`, quantity, stock snapshot, rank, or staff note.

### Model relationships

- `User::savedFrames(): HasMany`
- `SavedFrame::account(): BelongsTo`
- `SavedFrame::variant(): BelongsTo`
- `ProductVariant::savedFrames(): HasMany`
- `Patient::savedFrames(): HasMany`, using `saved_frames.user_id` and the
  Patient's current `user_id` as the local key solely for read-only Filament
  access

The Patient relationship must return no records when `user_id` is null.

### Catalog lifecycle

Saved preferences are not commercial history. They do not, by themselves,
prevent permanent deletion of an otherwise-never-referenced ProductVariant.
`CatalogLifecycle` must therefore continue using transactional and inventory
references to decide deletion eligibility, while the FK cascade cleans up a
preference if a variant is force-deleted.

Soft-deleted or inactive variants are loaded with their Product for Saved
Frame reads and rendered unavailable. They remain excluded from new saves and
normal catalog browsing.

## Authorization and Privacy

### Patient API

- Laravel Sanctum authentication is required.
- An active Patient link is not required.
- All reads and writes are scoped to `auth()->user()->id` on the server.
- A caller never supplies `user_id` or `patient_id`.
- Another account's saved record is never disclosed; ownership misses behave
  like an absent resource.
- Only active frame variants may be newly saved.

### Filament

- Active panel users with the existing Patient-view permission may view
  Preferred Frames through a linked Patient.
- Staff visibility resolves through the Patient's current `user_id` on every
  request; no account identity is snapshotted onto the Patient view.
- Unlinking a Patient immediately makes its Preferred Frames section empty.
- Linking the account to a Patient makes the same account-owned saves visible
  on that Patient without copying rows.
- Filament exposes no create, edit, attach, detach, delete, bulk, or inventory
  action for Preferred Frames.

Saved Frames are preference data, not clinical findings or financial records.
They are not copied into Encounter snapshots or audit-log metadata.

## Patient API Contract

All paths use the existing `/api/v1` prefix, `auth:sanctum`, and
`throttle:api-account` middleware. They move into the account-only route group.

```http
GET    /api/v1/saved-frames?page=1&per_page=15
PUT    /api/v1/saved-frames/{productVariant}
DELETE /api/v1/saved-frames/{productVariant}
```

The expected final route count is 59:

```text
8 public + 40 account-only + 11 active-link = 59
```

This reflects three new account-only routes and removal of five active-link
reservation routes.

### `GET /saved-frames`

Returns the authenticated account's preferences, newest first.

Query parameters:

| Parameter | Required | Validation | Default |
|---|---:|---|---|
| `page` | No | integer, minimum 1 | `1` |
| `per_page` | No | integer, 1 through 50 | `15` |

The endpoint uses the established Laravel pagination envelope.

```json
{
  "data": [
    {
      "product_variant_id": 42,
      "saved_at": "2026-08-26T10:30:00+08:00",
      "availability": "available",
      "variant": {
        "id": 42,
        "name": "Black / 52mm",
        "sku": "RB-CR-BLK-52",
        "price": "4500.00",
        "compare_at_price": null,
        "attributes": {
          "color": "black",
          "size": "52mm"
        },
        "images": [],
        "ar": null,
        "product": {
          "id": 7,
          "name": "Classic Rectangle",
          "slug": "classic-rectangle",
          "description": "Timeless frame design",
          "product_type": "frame",
          "brand": "Ray-Ban",
          "category": "Full Rim"
        }
      }
    }
  ],
  "links": {},
  "meta": {}
}
```

Patient-facing `availability` has exactly two values:

| Value | Rule |
|---|---|
| `available` | Variant and Product are active and not deleted, and `stock_quantity > 0`. |
| `unavailable` | Any other state, including zero stock, deactivation, or soft deletion. |

The patient response does not distinguish why a frame is unavailable and does
not expose any inventory number. It reuses the existing patient-safe frame,
variant, image, and published-AR serialization rules. Unpublished AR data and
internal catalog fields remain excluded.

### `PUT /saved-frames/{productVariant}`

Ensures the active frame variant is saved for the authenticated account.

- There is no request body.
- The route parameter is the ProductVariant ID.
- The controller treats the segment as an integer identifier rather than
  relying on implicit active-model binding, so it can return the specified
  validation response consistently.
- The target must satisfy the same active frame Product and active variant
  eligibility used by the patient frame catalog.
- The operation uses the unique account/variant constraint as its concurrency
  boundary.
- The first and repeated request both return `200` with the same resource
  shape.
- A repeated save neither duplicates the row nor changes `saved_at`.
- An inactive, deleted, non-frame, or nonexistent target returns the
  established `422` validation response without revealing internal details.

```json
{
  "data": {
    "product_variant_id": 42,
    "saved_at": "2026-08-26T10:30:00+08:00",
    "availability": "available",
    "variant": {}
  }
}
```

### `DELETE /saved-frames/{productVariant}`

Ensures the variant is not saved by the authenticated account.

- There is no request body.
- The operation is idempotent and always returns `204` whether or not that
  account currently has the preference.
- It deletes only a row whose `user_id` is the authenticated account.
- The controller treats the segment as an integer identifier rather than
  implicit model binding, so an absent or force-deleted variant remains an
  idempotent `204`.
- The route does not require the ProductVariant to remain active or
  non-deleted, allowing an unavailable preference to be removed.

### Additive frame-catalog field

Every variant returned by `GET /frames` and `GET /frames/{frame}` gains:

```json
{
  "is_saved": true
}
```

`is_saved` is a required boolean for authenticated responses. It is computed
for the authenticated account without an N+1 query. No Saved Frame ID is
exposed because the toggle contract is keyed by ProductVariant ID.

### Patient-facing copy

The Android save surface must display or make readily accessible:

> Saved frames are preferences only. Availability is not guaranteed until
> your purchase is confirmed.

The app must not use “reserved,” “held,” “set aside,” an expiry countdown, or
appointment-selection language for this feature.

## Filament Contract

### Patient Record: Preferred Frames

Add a read-only `Preferred Frames` relation manager to the Patient Resource.
It shows the full newest-first list when the Patient has a linked account.

Columns:

- frame Product name;
- variant name and SKU;
- saved date/time;
- live availability badge;
- current available quantity for clinic staff only;
- thumbnail with a safe placeholder when no image exists.

Staff availability labels:

| Label | Rule |
|---|---|
| `Inactive` | Product or variant is inactive or soft-deleted. |
| `Out of stock` | Active but `stock_quantity === 0`. |
| `Low stock` | Active, positive stock and `isLowStock()` is true. |
| `Available` | Active, positive stock and not low stock. |

The empty state distinguishes:

- `No linked account` when `patients.user_id` is null;
- `No preferred frames` when the linked account has saved nothing.

There are no row or bulk actions.

### Appointment and Consultation context

The Appointment edit page and Consultation edit page show a compact read-only
**Preferred Frames** section containing the three most recent saves for the
Patient's currently linked account.

Each entry shows the thumbnail, Product/variant, SKU, saved date, and the same
staff availability badge. A `View all preferred frames` link opens the Patient
Record's Preferred Frames relation manager. The section is absent or shows the
appropriate empty state when no linked account or preferences exist.

The compact view does not change ordering based on stock: “latest three” means
descending `saved_frames.created_at`. Availability is context, not preference
ranking.

### Removed Filament surfaces

- the Optical → Frame Reservations navigation item and navigation badge;
- `FrameReservationResource` and all pages, tables, schemas, and relation
  managers;
- Appointment `Reserve Frame` and `View Reservation` actions;
- Appointment reservation relation managers;
- all Accept, Release, Add Frame, and Remove Frame controls.

There is no standalone Saved Frames navigation resource.

## Inventory and Commerce Boundaries

Saved Frames has no inventory service or reservation stock collaborator.

The following actions remain the only commercial commitment paths relevant to
frames:

- `CreateOpticalOrderFromQuotation`;
- `CreateDirectOpticalOrder`;
- their existing inventory commitment collaborators.

No Quotation or Optical Order selector is filtered by Saved Frames. A future UI
may visually suggest a preferred frame, but selection, price snapshot,
validation, locking, and stock commitment must still use the normal catalog
and order path.

Appointment cancellation, no-show, reschedule, check-in, fulfillment, and
Consultation lifecycle actions perform no Saved Frames cleanup because the
preferences are account-owned and persistent.

## Reservation Data Conversion and Retirement

The replacement follows **expand → convert → contract** even though the
project currently has no production data.

### Phase A: Expand

1. Add `saved_frames` and the new account relationships.
2. Add the new account-only API and additive `is_saved` catalog field.
3. Add read-only Filament Preferred Frames surfaces.
4. Keep reservation tables and release code temporarily intact.
5. Update the Android client to Saved Frames and verify it makes no reservation
   requests.

### Phase B: Convert and release

Provide an idempotent, transaction-safe
`saved-frames:migrate-reservations` operation with `--dry-run` and `--execute`
modes. It processes each reservation under a row lock and locks affected
ProductVariants in ascending ID order.

For each reservation:

1. If `accepted_at` is set, release exactly one unit per item and append the
   corresponding `reservation_release` ledger movement. The conversion and
   release must commit atomically with reservation deletion.
2. If the reservation's Patient currently has `user_id`, create one Saved Frame
   per item using insert-or-ignore semantics. Preserve the reservation item's
   creation time as `saved_frames.created_at` where practical; otherwise use
   the reservation creation time.
3. If the Patient has no linked account, create no Saved Frame because the new
   feature has no valid owner. Held stock is still released.
4. Collapse duplicate account/variant choices through the unique constraint,
   retaining the earliest original save timestamp.
5. Delete the converted reservation only inside the same successful
   transaction.

The operation is idempotent: a completed reservation no longer exists, and a
rolled-back transaction leaves its reservation, stock, movement ledger, and
preferences unchanged.

Before contraction, verification must prove:

- zero `frame_reservations` and `frame_reservation_items` remain;
- every previously accepted item has exactly one matching release movement;
- available stock increased by exactly the number of released held items;
- requested reservations caused no stock change;
- every eligible linked choice exists once in `saved_frames`;
- unlinked choices did not become owned by an unrelated account.

For disposable local/demo environments, an explicitly approved database reset
and reseed remains an alternative. It must never be performed implicitly.

### Phase C: Contract

Only after Phase B verification:

1. remove the five reservation API routes;
2. remove all reservation application and Filament code;
3. remove Appointment cancellation/no-show reservation cleanup;
4. remove `reservations:expire` and its scheduler registration;
5. drop `frame_reservation_items`, then `frame_reservations`, in a separate
   reversible schema migration;
6. replace reservation factories/seed data/tests with Saved Frames coverage;
7. update canonical context, API documentation, navigation assertions, and
   stale reservation-backed commerce text.

Historical `inventory_movements` rows, the nullable legacy `reservation_id`
provenance value, and the `reservation_allocation` / `reservation_release`
movement-type rows remain intact. New application code never writes them.

### Client cutover

Because no reservation client has been released, the final contract does not
retain 410 handlers, route aliases, adapters, or dual writes. The Android build
and backend contraction must be coordinated. After contraction, old
reservation URLs are absent and return the normal route-not-found response.

If a released client is discovered, Phase C is blocked until active usage is
measured and an advisory or compulsory deprecation window is approved.

## Project Structure

Expected implementation locations follow current conventions:

```text
app/Actions/SavedFrames/                 account-owned write actions
app/Http/Controllers/Api/                SavedFrameController
app/Http/Resources/                       patient-safe Saved Frame resources
app/Models/                               SavedFrame and relationships
app/Filament/Resources/Patients/          Preferred Frames relation manager
app/Filament/Resources/Appointments/      compact Preferred Frames context
app/Filament/Resources/Encounters/        compact Preferred Frames context
database/migrations/                      expand and later contract migrations
database/factories/                       SavedFrameFactory
tests/Feature/Api/V1/                     account API and ownership tests
tests/Feature/Filament/                   read-only staff visibility tests
tests/Feature/SavedFrames/                domain and inventory-isolation tests
docs/                                    canonical contract/context updates
```

Do not create a second top-level application directory or add a dependency.

## Code Style

Use the existing Laravel action boundary, explicit types, promoted
dependencies where useful, and descriptive method names. The intended style is
illustrated by this contract-level example:

```php
final class SaveFrame
{
    public function handle(User $account, ProductVariant $variant): SavedFrame
    {
        $this->ensureVariantCanBeSaved($variant);

        SavedFrame::query()->insertOrIgnore([
            'user_id' => $account->id,
            'product_variant_id' => $variant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SavedFrame::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($variant)
            ->firstOrFail();
    }
}
```

Implementation must follow sibling conventions, use curly braces for every
control structure, use explicit parameter and return types, and format PHP with
Laravel Pint.

## Testing Strategy

Use Pest feature tests and factories. Tests must prove behavior, authorization,
query shape, lifecycle handling, and absence of inventory side effects.

### API coverage

- linked and unlinked patient accounts can list/save/remove;
- unauthenticated requests return `401`;
- account A cannot observe or remove account B's saves;
- saving a valid active frame succeeds;
- saving an inactive, soft-deleted, missing, or non-frame variant fails;
- repeated PUT is idempotent under the unique constraint and preserves
  `saved_at`;
- repeated DELETE returns `204` and changes nothing else;
- pagination validates and orders newest first;
- inactive/out-of-stock existing saves remain listed as `unavailable`;
- frame list/detail return the correct account-specific `is_saved` value;
- patient resources never expose exact stock or internal catalog fields;
- list/catalog serialization executes without an N+1 query regression.

### Filament coverage

- call `$this->actingAs(User::factory()->create())` with the appropriate panel
  role before panel tests;
- linked Patient Records show all preferences newest first;
- unlinked Patient Records cannot see the formerly linked account's saves;
- Appointment and Consultation surfaces show only the latest three;
- availability badges map correctly;
- no mutation actions exist;
- Frame Reservations is absent from navigation and Appointment actions.

### Domain and migration coverage

- save/unsave/link/unlink operations leave stock and inventory movements
  unchanged;
- force-deleting an otherwise-unreferenced variant cascades Saved Frames;
- conversion preserves linked choices and skips unlinked ownership;
- held conversion releases exactly once and requested conversion releases
  nothing;
- conversion rollback and retry are safe;
- contraction is blocked while reservation rows remain;
- direct and quotation-backed Optical Order inventory tests remain green.

Obsolete reservation-only tests may be removed only as their replacement
coverage lands; tests proving shared Appointment, inventory, catalog, and
Optical Order behavior must be updated rather than discarded.

## Verification Commands

All project commands run through Laravel Sail:

```bash
vendor/bin/sail artisan test --compact tests/Feature/SavedFrames tests/Feature/Api/V1/SavedFrameTest.php tests/Feature/Filament/PreferredFramesTest.php
vendor/bin/sail artisan test --compact tests/Feature/Appointments tests/Feature/Inventory tests/Feature/OpticalOrders
vendor/bin/sail artisan saved-frames:migrate-reservations --dry-run --no-interaction
vendor/bin/sail artisan route:list --path=api/v1 --except-vendor
vendor/bin/sail bin pint --dirty --format agent
```

During implementation, use the smallest relevant test selection after each
slice and run the combined affected suites before final review.

## Boundaries

### Always

- validate new saves at the authenticated API boundary and action boundary;
- derive ownership from authentication;
- preserve the account/variant unique constraint;
- serialize money and timestamps using existing patient API conventions;
- release all old accepted holds before dropping reservation records;
- test that Saved Frames cannot affect inventory;
- update `docs/API_CONTRACT.md` and `docs/BACKEND_CONTEXT.md` when shipped;
- coordinate Android copy, endpoints, and removal of appointment/hold UX.

### Ask first

- changing the approved account-owned model to Patient- or Appointment-owned;
- adding deposits, notifications, ranking, notes, staff mutation, analytics, or
  automatic preparation;
- retaining compatibility endpoints after discovering a released client;
- adding any dependency or new top-level directory;
- resetting or deleting non-disposable data.

### Never

- decrement stock when a frame is saved;
- imply availability is guaranteed;
- expose another account's preferences;
- expose exact patient-facing inventory values;
- silently assign an unlinked reservation to an account;
- drop reservation tables while rows or held allocations remain;
- delete historical inventory ledger entries;
- write a Saved Frame reference into commercial transaction tables.

## Success Criteria

The change is complete only when all of the following are true:

1. An authenticated unlinked account can save a frame from AR/catalog browsing
   and retrieve it later.
2. Saving and removing are concurrency-safe and idempotent.
3. Saved Frame operations produce zero stock changes and zero inventory
   movements.
4. Catalog list/detail responses expose accurate account-specific `is_saved`.
5. Saved lists are paginated, newest first, sanitized, and label unavailable
   records without exposing exact stock.
6. A linked Patient's staff surfaces show all preferences in Patient Records
   and the latest three in Appointment/Consultation context.
7. Unlinking immediately removes staff visibility without deleting the
   account's saves.
8. No staff preference mutation or reservation workflow remains.
9. Every old held unit is released exactly once before reservation data is
   removed.
10. Reservation routes, scheduler entry, navigation, application code, active
    schema tables, factories, and obsolete tests are removed.
11. Existing Optical Order inventory commitment and reversal behavior still
    passes.
12. Canonical API/context documents and the Android handoff describe Saved
    Frames and contain no current-state claims that reservations remain live.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Existing accepted holds are dropped without release | Inventory becomes understated | Transactional conversion, exact ledger assertions, contract migration refuses nonempty tables. |
| An old Android build calls removed routes | Save feature breaks for that build | Confirm no released client; coordinate Android/backend cutover; block contraction if this is false. |
| Account preferences leak after unlinking | Privacy breach | Resolve Filament data through current `patients.user_id` on every request; ownership tests. |
| `is_saved` introduces N+1 catalog queries | Frame browsing slows down | Account-scoped `withExists`/eager loading and query-count regression test. |
| Inactive frames disappear unexpectedly | Patient loses remembered choices | Load saved targets with inactive/trashed catalog records and label unavailable. |
| Staff mistakes a preference for a hold | Customer expectation or lost sale | “Preferred” terminology, explicit no-guarantee copy, no stock action or hold status. |
| Saved rows block safe catalog cleanup | Catalog records accumulate | Do not treat preferences as commercial references; cascade only on force delete. |

## Open Questions

No product question blocks implementation. Before Phase C deployment, confirm
only the operational premise that no released Android client still depends on
the five reservation routes.
