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

Frame ratings remain a separate, untouched feature — they answer "is this frame good?"
(purchasing lever), visit ratings answer "was this visit good?" (staffing/process lever).

## Components and dependency order

```
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
          8. (OPTIONAL) Surface frame-rating
             aggregates in the catalog API
```

Steps 3, 4, and 6 are independent of each other and can be built in any order once 2 lands.
Steps 1 → 2 and 6 → 7 are strictly sequential.

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
| `AdminNavigationStructureTest` locks group order, item order per group, and unique icons — adding a Filament resource **will** fail it | Red suite, looks like a regression | Update that test in the **same commit** as the resource (Task 6), not a follow-up |
| `lockForUpdate()` on an already-hydrated model is a silent no-op — this repo has shipped that bug at least three times (`ConvertFrameReservationToJobOrder`, `ReviewPatientLinkRequest`, `AcceptAppointmentRequest`) | Lost update on concurrent submit | Always re-fetch inside the transaction: `VisitRating::query()->lockForUpdate()->find(...)` |
| `unique(appointment_id)` + concurrent first-submit race | 500 instead of a clean revision | Lock the appointment row inside the transaction; catch `UniqueConstraintViolationException` and fall through to the revision path |
| Service snapshot misses quotation-sourced items | Per-service averages silently under-count | Union both paths (above); assert in Task 2 tests with a quotation-sourced service item |
| Pre-existing Faker unique-name collisions on `product_categories` / `appointment_types` | False "regression" signals | Re-run; compare against a `git stash` baseline before treating any failure as caused by this work |
| N+1 on `GET /appointments` once `rating` is exposed | Slow list endpoint | Eager-load `visitRating` in `AppointmentController::index` |
| `AppointmentResource` is shared by list + show + possibly other callers | Unintended contract change elsewhere | Grep all `AppointmentResource::` usages before editing; the two new keys are additive so risk is low |

## Verification checkpoints

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
