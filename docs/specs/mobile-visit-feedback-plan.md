# Plan: Mobile Visit Feedback & Service Ratings

> Spec: `mobile-visit-feedback-spec.md` (approved 2026-08-07)
> Tasks: `mobile-visit-feedback-tasks.md`

## Resolved scope

| Decision | Choice |
|---|---|
| Unit of feedback | **The visit** — one rating per fulfilled appointment, with services + optometrist snapshotted |
| General/un-anchored feedback channel | **Out of scope** — visit-anchored only |
| Per-service star breakdown | **Out of scope** — snapshot `service_ids`, one overall star value |
| Admin scope | **Table + moderation only** — no averages widget |
| Eligibility | `appointment.status = fulfilled` |
| Model naming | `VisitRating` / `VisitRatingRevision` (symmetry with shipped `FrameRating`); Filament label reads "Visit Feedback" |
| Filament nav group | **Patients**, after Conversations — grouped with the other patient-submitted channel |
| Frame-rating aggregates | **In scope**, resequenced from Task 8 into the prelude as Task 0d |
| Frame-rating read path | **In scope as a prelude (Tasks 0a–0d)**, ahead of visit feedback — see below |

## Prelude: finish the frame-rating read path first

An audit while writing this spec found that `API_CONTRACT.md` §14–§15 documents an
interface that was never built. The write path for frame ratings shipped; the read path
did not. The drift is the symptom, not the disease:

| Patient can… | Status |
|---|---|
| Submit a frame rating | ✅ works |
| Discover what is rateable | ❌ `is_rateable` never built |
| See a rating they already left | ❌ `rating` never built |
| See ratings while browsing the catalog | ❌ no aggregates |
| Filter orders to current vs history | ❌ `?filter=` inert on §14 **and** §15 |
| Submit without a 422 | ⚠️ only by ignoring the contract and sending `product_variant_id` |

Plus a defect: the rating endpoint returns the raw model, exposing `moderation_reason`
and `moderated_by` — a staff member's internal note about *this patient's* comment, and
the staff user ID who wrote it. Narrow (it only surfaces on a re-submission after staff
hid the comment) but real.

**Why before, not after.** `is_rateable` on optical order items and `is_rateable` on
appointments must be one convention, not two. Building the appointment version first
(Task 5) means inventing a shape in isolation and retrofitting orders to match later —
two chances to diverge. Building orders first makes Task 5 mirror working, tested code,
which is what this spec claimed all along but could not deliver. Task 0d (catalog
aggregates) touches the same resources, so it folds in for free rather than being a
separate final commit.

Frame ratings remain a **separate feature** — they answer "is this frame good?" (purchasing
lever); visit ratings answer "was this visit good?" (staffing/process lever). The prelude
finishes frame ratings' read path but does not merge the two: they stay distinct tables,
endpoints, and admin resources.

## Components and dependency order

```
0a. FrameRatingResource      stop the moderation leak; optional product_variant_id
        │
0b. ?filter= + ordering      quotations AND optical orders
        │
0c. Rating fields            is_rateable / rating / product_variant_id / is_overdue
        │                    ← establishes the convention Task 5 mirrors
0d. Catalog aggregates       average_rating / rating_count            ← CHECKPOINT 0
        │
        ▼
1. Foundation        migration + models + factory
        │
        ▼
2. Domain            SaveVisitRating, ModerateVisitRating          ← CHECKPOINT A
        │
        ├──────────────┬─────────────────┐
        ▼              ▼                 ▼
3. Mobile API     4. Appointment     6. Admin (Filament)
   controller,       contract           resource + nav
   request,          (is_rateable,      + moderation
   resource,          rating)
   routes
        │              │                 │
        └──────────────┴─────────────────┘   ← CHECKPOINT B (mobile contract complete)
                       │
                       ▼
              7. Docs reconciliation                ← CHECKPOINT C
                       │
                       ▼
          8. Surface frame-rating aggregates in
             the catalog API  (separate commit)
```

Tasks 0a–0d are a prelude, not part of visit feedback — they finish the frame-rating read
path (see the Prelude section above). They must land **first**: 0c establishes the
`is_rateable` convention that Task 5 mirrors. Tasks 3, 4, and 6 are independent of each
other and can be built in any order once 2 lands; 1 → 2 and 6 → 7 are strictly sequential.

## Design decisions

### Denormalized snapshots, not live joins

`optometrist_id` and `service_ids` are captured **at submission time** and never
recomputed. The patient rated the visit as it was staffed and billed that day; a later
staff correction to the encounter must not silently rewrite what the rating refers to.
This is the same reasoning behind `billing_record_items` storing immutable charge
snapshots rather than resolving live from `jobOrder.items`.

### One rating per appointment, ever

`unique(appointment_id)` at the DB level. An appointment belongs to exactly one patient,
so this is also implicitly one-per-patient. Edits append a `VisitRatingRevision` rather
than overwriting — identical to `SaveFrameRating`, and it means moderation review can see
what was originally submitted.

### Ownership resolves as 404, not 403

Resolve the appointment through the authenticated patient's own relation
(`$patient->appointments()->findOrFail($id)`), so "not yours" and "does not exist" are
indistinguishable. This matches the documented contract for optical orders and
prescriptions (`API_CONTRACT.md`: *"404: not found or not owned by the authenticated
patient"*). Note this deliberately **differs** from the existing `FrameRatingController`,
which returns 403 for a foreign job-order item and is a mild enumeration oracle; that is
pre-existing and out of scope to change here.

### Hidden comments

A moderated rating keeps its star value in the patient API response but returns
`comment: null`. Stars still count toward any future average; the text disappears.

### Service snapshot query

Services rendered at a visit are service-type billing items reachable from the visit's
encounter by **either** path:

```
billing_record_items
  WHERE item_type = 'service' AND service_id IS NOT NULL
    AND ( encounter_id = :encounter
          OR billing_record_id IN (SELECT id FROM billing_records WHERE encounter_id = :encounter) )
```

Both are needed: items added via `AddEncounterChargesToBilling` carry their own
`encounter_id`, while items copied from a confirmed quotation may only be reachable
through the parent billing record. Verify against seeded data during Task 2.

## Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| **(0b) Implementing `?filter=` to spec changes default behavior.** Today both endpoints return *everything*; the contract says the default is `current`. An existing client that relied on getting all orders will silently start receiving only active ones | Android list screens lose history rows with no error | Follow the contract (it is the authority Android should code to), but call it out explicitly in the commit and notify the mobile side. Do **not** quietly default to "all" — that leaves the contract lying |
| **(0c) `payment_summary.status` switches from `"Partially Paid"` to `partially_paid`** | Any client string-matching the display label breaks | Contract always specified machine-readable; this is code catching up. Flag in the commit as a breaking change; the label is trivially derivable client-side |
| **(0a) Making `product_variant_id` optional must not break clients already sending it** | Existing submissions 422 | Optional-but-validated: derive from the route item when absent, verify it matches when present. Covered by a test for each path |
| **(0a) Author's own hidden comment** — spec says "hidden comments are not returned to the patient API", which would blank an author's own words back to them | Confusing UX; author thinks their text vanished | Refine the rule: hiding suppresses **public/aggregate** display, not an author's view of their own submission. Recorded in Task 0a |
| `AdminNavigationStructureTest` locks group order, item order per group, and unique icons — adding a Filament resource **will** fail it | Red suite, looks like a regression | Update that test in the **same commit** as the resource (Task 6), not a follow-up |
| `lockForUpdate()` on an already-hydrated model is a silent no-op — this repo has shipped that bug at least three times (`ConvertFrameReservationToJobOrder`, `ReviewPatientLinkRequest`, `AcceptAppointmentRequest`) | Lost update on concurrent submit | Always re-fetch inside the transaction: `VisitRating::query()->lockForUpdate()->find(...)` |
| `unique(appointment_id)` + concurrent first-submit race | 500 instead of a clean revision | Lock the appointment row inside the transaction; catch `UniqueConstraintViolationException` and fall through to the revision path |
| Service snapshot misses quotation-sourced items | Per-service averages silently under-count | Union both paths (above); assert in Task 2 tests with a quotation-sourced service item |
| Pre-existing Faker unique-name collisions on `product_categories` / `appointment_types` | False "regression" signals | Re-run; compare against a `git stash` baseline before treating any failure as caused by this work |
| N+1 on `GET /appointments` once `rating` is exposed | Slow list endpoint | Eager-load `visitRating` in `AppointmentController::index` |
| `AppointmentResource` is shared by list + show + possibly other callers | Unintended contract change elsewhere | Grep all `AppointmentResource::` usages before editing; the two new keys are additive so risk is low |

## Verification checkpoints

**Checkpoint 0 — after Tasks 0a–0d (prelude).**
Every ⚠️ `NOT IMPLEMENTED` / `MISMATCH` marker is gone from `API_CONTRACT.md` §14–§15,
because the code now does what the document says. The moderation leak is closed and the
`is_rateable` convention exists in working, tested code. Only then start Task 1.

**Checkpoint A — after Task 2 (domain).**
`vendor/bin/sail artisan test --compact tests/Feature/Ratings` green. Domain rules
(eligibility, revision append, snapshot immutability) proven without any HTTP layer.

**Checkpoint B — after Tasks 3–6 (contract + admin).**
`vendor/bin/sail artisan test --compact tests/Feature/Api/V1/VisitRatingTest.php
tests/Feature/Filament/VisitRatingResourceTest.php
tests/Feature/Filament/AdminNavigationStructureTest.php` green.
Mobile contract is complete and Android can begin integrating.

**Checkpoint C — after Task 7 (docs).**
Full suite run compared against the pre-change baseline — **no new failures**, flakiness
accounted for. `API_CONTRACT.md`, `BACKEND_CONTEXT.md`, `gap-analysis.md` §J updated.

## Out of scope (recorded, not built)

- Push/SMS prompt inviting the patient to rate after a visit.
- Averages dashboard or feedback report (`gap-analysis.md` §M tracks the reports module).
- Per-service individual star ratings.
- Un-anchored "app feedback" channel.
- Changing `FrameRatingController`'s 403-on-foreign-item behavior.
