# Spec: Mobile Visit Feedback & Service Ratings

> **Status:** APPROVED 2026-08-07. Scope decisions resolved — see Resolved Decisions.
> Plan: `mobile-visit-feedback-plan.md`. Tasks: `mobile-visit-feedback-tasks.md`.

## Objective

Let a patient using the Android app rate and comment on **a visit they actually attended**,
so the clinic can measure service quality per optometrist and per service type — and act on it.

### What already exists (and is therefore out of scope)

| Capability | Status | Where |
|---|---|---|
| Rate a **dispensed frame** (1–5 + comment) | ✅ Shipped | `FrameRating`, `POST /api/v1/optical-order-items/{item}/rating` |
| Edit a frame rating (append revision) | ✅ Shipped | `FrameRatingRevision`, `SaveFrameRating` |
| Staff hide/restore a rating comment | ✅ Shipped | `ModerateFrameRating`, `FrameRatingResource` |
| Staff-logged product complaint / remake | ✅ Shipped | `Complaint` (staff-created, not patient-submitted) |

**The gap:** nothing lets a patient say anything about the *service* — the exam, the
optometrist, the wait, the visit. `docs/gap-analysis.md` §J marks "Customer Feedback
Submission" ✅, but that checkmark is earned entirely by frame ratings. Service feedback
is untracked and unbuilt.

### User stories

1. As a patient, after my appointment is marked fulfilled, I can give the visit 1–5 stars
   and an optional comment from the app.
2. As a patient, I can correct my rating later; my earlier submission is retained as history.
3. As a patient, I can see which of my past visits I have already rated.
4. As staff, I can read all visit feedback in the admin panel, filtered by rating.
5. As staff/admin, I can hide an abusive or PII-leaking comment without destroying the
   star value that feeds averages.
6. As the clinic, I can see average satisfaction per optometrist and per service rendered.

### Non-goals for this round

- Push notifications prompting the patient to rate (Android + SMS concern, tracked separately).
- A full reports module (`gap-analysis.md` §M) — this spec ships raw data + a filterable
  admin table, not dashboards.
- Rating individual staff other than the assigned optometrist.
- Public display of ratings to other patients.

---

## Recommendation: what unit gets rated

**Anchor feedback on the fulfilled Appointment (the visit) — not on individual service line items.**

Rationale:

- **Patients can judge a visit; they cannot meaningfully judge a SKU.** If "Comprehensive
  Eye Exam" and "Contact Lens Fitting" were both billed for one 30-minute sitting, asking
  for two separate star ratings produces noise, not signal.
- **No new discovery endpoint is needed.** The mobile app already lists appointments
  (`GET /api/v1/appointments`) and `fulfilled` is a terminal status, so `is_rateable` +
  `rating` can ride on the existing resource.
  > ⚠️ **Correction (2026-08-07).** An earlier draft justified this by "reusing a pattern
  > Android has already implemented." That was wrong: `API_CONTRACT.md` §15 *documents*
  > `is_rateable`/`rating` on optical order items, but **no such fields exist in the
  > code** — `grep -rn is_rateable app/` returns nothing, and no test references them.
  > The shape is still the right one to adopt, but it is an unbuilt convention rather
  > than a proven precedent, so Android has no existing client code to reuse. Budget
  > for that in the mobile estimate.
- **Per-service averages are still obtainable** — without asking the patient N questions.
  The services rendered at that visit are already recorded as `billing_record_items`
  (`item_type = service`, `encounter_id` set). Snapshotting those onto the feedback record
  at submission time yields "Comprehensive Eye Exam: 4.6★" for free.
- **The clinic's real improvement lever is the optometrist and the visit type**, both of
  which hang off the appointment.

Rejected alternative — one rating per `service_id`, mirroring `FrameRating` exactly: it is
more symmetric with the frame code and would be simpler to build, but it asks the patient a
question they cannot answer well and would collect several near-identical ratings per visit.

---

## Tech Stack

Unchanged from the repo baseline — this feature introduces **no new dependencies**.

| Layer | Technology |
|---|---|
| Language | PHP 8.5 |
| Framework | Laravel 13 |
| Admin panel | Filament 5 |
| Mobile auth | Laravel Sanctum (`auth:sanctum` + `require.patient.link`) |
| Database | MySQL via Laravel Sail |
| Tests | Pest 4 |
| Formatting | Laravel Pint |

## Commands

```
Migrate:  vendor/bin/sail artisan migrate --no-interaction
Test:     vendor/bin/sail artisan test --compact
Focused:  vendor/bin/sail artisan test --compact tests/Feature/Ratings
Lint:     vendor/bin/sail bin pint --dirty --format agent
Make:     vendor/bin/sail artisan make:test --pest <Name>Test --no-interaction
```

## Project Structure

New and touched files, following existing repo layout:

```
app/Models/VisitRating.php                                  → new
app/Models/VisitRatingRevision.php                          → new
app/Actions/Ratings/SaveVisitRating.php                     → new (sibling of SaveFrameRating)
app/Actions/Ratings/ModerateVisitRating.php                 → new (sibling of ModerateFrameRating)
app/Http/Controllers/Api/VisitRatingController.php          → new
app/Http/Requests/Api/StoreVisitRatingRequest.php           → new
app/Http/Resources/VisitRatingResource.php                  → new
app/Http/Resources/AppointmentResource.php                  → edit (add is_rateable + rating)
app/Filament/Resources/VisitRatings/                        → new (mirrors FrameRatings/)
routes/api.php                                              → edit
database/migrations/*_create_visit_ratings_table.php        → new
database/factories/VisitRatingFactory.php                   → new
tests/Feature/Ratings/SaveVisitRatingTest.php               → new
tests/Feature/Api/V1/VisitRatingTest.php                    → new
tests/Feature/Filament/VisitRatingResourceTest.php          → new
docs/API_CONTRACT.md, docs/BACKEND_CONTEXT.md, docs/gap-analysis.md → edit
```

### Data model

```
visit_ratings
  id
  patient_id          FK patients, cascade      -- who rated
  appointment_id      FK appointments, cascade  -- UNIQUE: one rating per visit, ever
  encounter_id        FK encounters, nullOnDelete, nullable  -- resolved at submit
  optometrist_id      FK users, nullOnDelete, nullable        -- snapshot for aggregation
  rating              tinyint unsigned 1..5
  comment             text nullable
  service_ids         json nullable             -- snapshot of services rendered at this visit
  current_revision_id FK visit_rating_revisions, nullOnDelete
  is_hidden           bool default false
  moderation_reason   text nullable
  moderated_by        FK users, nullOnDelete
  moderated_at        timestamp nullable
  timestamps, softDeletes

visit_rating_revisions
  id
  visit_rating_id  FK visit_ratings, cascade
  revision_number  uint default 1
  rating           tinyint unsigned
  comment          text nullable
  revised_by       FK users, nullOnDelete
  revised_at       timestamp
  timestamps
```

`optometrist_id` and `service_ids` are **denormalized snapshots taken at submission time**,
not live joins. The patient rated the visit as it was staffed and billed that day; a later
staff correction to the encounter must not silently rewrite what the rating refers to.

### API surface

| Method | Route | Auth | Purpose |
|---|---|---|---|
| `POST` | `/api/v1/appointments/{appointment}/rating` | sanctum + active link | Create or revise the rating for one fulfilled appointment |
| `GET` | `/api/v1/appointments` (existing) | sanctum + active link | Each item gains `is_rateable` + `rating` |
| `GET` | `/api/v1/appointments/{appointment}` (existing) | sanctum + active link | Same two fields |

`POST` is an **upsert** — first call creates (201), later calls append a revision (200).
This matches the frame-rating endpoint's documented behavior exactly; there is no separate
`PATCH`.

Request body:

```json
{ "rating": 5, "comment": "Dr. Santos explained everything clearly." }
```

Added to `AppointmentResource`:

```json
{
  "is_rateable": true,
  "rating": { "rating": 5, "comment": "...", "created_at": "...", "revision_number": 2 }
}
```

`is_rateable` is `true` only when `status = fulfilled` and the appointment belongs to the
authenticated patient. `rating` is `null` until submitted.

## Code Style

Match the existing `Actions/Ratings` pair exactly — single-purpose action class, constructor
promotion, explicit return types, `DB::transaction`, `ValidationException::withMessages` for
domain rule violations:

```php
class SaveVisitRating
{
    /**
     * Create or revise a patient's rating of one fulfilled visit.
     *
     * One current rating per appointment. Edits append revisions rather than
     * overwriting, so the original submission survives moderation review.
     */
    public function handle(
        Patient $patient,
        Appointment $appointment,
        int $rating,
        ?string $comment = null,
    ): VisitRating {
        if ($appointment->status?->name !== 'fulfilled') {
            throw ValidationException::withMessages([
                'appointment' => ['Only fulfilled appointments can be rated.'],
            ]);
        }

        return DB::transaction(function () use ($patient, $appointment, $rating, $comment): VisitRating {
            // lock, upsert, append revision — mirrors SaveFrameRating
        });
    }
}
```

Conventions carried over from the repo:
- `#[Fillable([...])]` attribute on models, not `$fillable`.
- Relations return typed `BelongsTo<Model, $this>` with a PHPDoc generic.
- Row locks re-fetch (`Model::query()->lockForUpdate()->findOrFail($id)`) — calling
  `lockForUpdate()` on an already-hydrated instance is a no-op.
- Filament actions live in `Tables/` / `Pages/` classes, never inline in the Resource.

## Testing Strategy

Pest 4 feature tests, `RefreshDatabase`, factories over hand-built models. Three layers:

| Layer | File | Covers |
|---|---|---|
| Action | `tests/Feature/Ratings/SaveVisitRatingTest.php` | Domain rules in isolation |
| API | `tests/Feature/Api/V1/VisitRatingTest.php` | Auth, ownership, contract shape |
| Admin | `tests/Feature/Filament/VisitRatingResourceTest.php` | Listing, filters, moderation |

Required cases:

1. A fulfilled appointment can be rated → 201, revision #1 created.
2. Re-submitting revises → 200, revision #2 appended, original revision retained.
3. A `scheduled` / `checked_in` / `cancelled` / `no_show` appointment is rejected (422).
4. Another patient's appointment is rejected (403) — **and returns the same shape as a
   non-existent appointment**, so the endpoint is not a patient-enumeration oracle.
5. `rating` outside 1–5 is rejected (422).
6. An unlinked account is rejected by `require.patient.link`.
7. `optometrist_id` and `service_ids` are snapshotted at submit and do **not** change when
   the encounter is later edited.
8. `AppointmentResource` exposes `is_rateable: false` before fulfillment, `true` after,
   and `rating: null` until submitted.
9. Staff can hide a comment; the star value still counts toward averages.
10. Hidden comments are **not** returned to the patient API.

Baseline note: this suite currently has known pre-existing flakiness from Faker unique-name
collisions on `product_categories` / `appointment_types`. Compare failures against a
`git stash` run before treating one as a regression.

## Boundaries

**Always:**
- Run `vendor/bin/sail bin pint --dirty --format agent` before finalizing.
- Run the affected tests and report real results, including failures.
- Scope every patient-facing query through the authenticated account's linked `Patient`.
- Snapshot denormalized fields at write time; never resolve them live on read.
- Update `docs/API_CONTRACT.md` and `docs/BACKEND_CONTEXT.md` in the same change.

**Ask first:**
- Any change to `frame_ratings` or existing `FrameRating` behavior.
- Adding a dependency.
- Exposing feedback to any patient other than its author.
- Changing appointment status semantics or the `fulfilled` transition.

**Never:**
- Hard-delete a rating or a revision (soft deletes + moderation only).
- Return a hidden comment through the patient API.
- Let a rating be submitted for an appointment the caller does not own.
- Store the patient's identity inside `service_ids` or any JSON blob (PII stays in columns).

## Success Criteria

- [ ] `POST /api/v1/appointments/{appointment}/rating` creates a rating for a fulfilled,
      owned appointment and returns 201 with the created resource.
- [ ] A second POST to the same appointment returns 200 and leaves exactly 2 revision rows.
- [ ] Rating a non-fulfilled, unowned, or nonexistent appointment never succeeds, and
      unowned vs nonexistent are indistinguishable to the caller.
- [ ] `GET /api/v1/appointments` returns `is_rateable` and `rating` on every item.
- [ ] A staff user can list visit ratings in Filament, filter by star value, and hide a
      comment with a required reason.
- [ ] A hidden comment disappears from the patient API but its stars still count.
- [ ] Average rating per optometrist is derivable with one query against `visit_ratings`.
- [ ] `vendor/bin/sail artisan test --compact` shows no **new** failures vs. the pre-change
      baseline.
- [ ] `docs/API_CONTRACT.md`, `docs/BACKEND_CONTEXT.md`, and `docs/gap-analysis.md` §J
      reflect the new capability.

## Resolved Decisions

All scope questions are closed. Recorded here so the reasoning survives the conversation.

| # | Question | Decision | Consequence |
|---|---|---|---|
| 1 | Unit of feedback | **The visit** — one rating per fulfilled appointment | Services + optometrist are snapshotted onto the record; per-service and per-optometrist averages still fall out. Rejected: one rating per service line item (asks the patient to grade SKUs from a single sitting). |
| 2 | Un-anchored "app feedback" channel | **Out of scope** | Every rating ties to a real completed visit. Addable later without rework. |
| 3 | Per-service star breakdown | **Out of scope** — snapshot only | `service_ids` JSON + one overall star value. Per-service stars would need a third table and N prompts per visit. |
| 4 | Model naming | **`VisitRating` / `VisitRatingRevision`** | Symmetric with shipped `FrameRating`; no `$table` override needed. Filament UI label still reads "Visit Feedback". |
| 5 | Eligibility anchor | **`appointment.status = fulfilled`** | A patient can rate a visit they attended even if the optometrist never completed the encounter record — staff oversight must not silently block feedback. |
| 6 | Admin reporting | **Table + moderation only** | No averages widget this round; `gap-analysis.md` §M tracks the reports module separately. |
| 7 | Filament navigation group | **Patients**, after Conversations | Grouped with Conversations as "things patients sent us". Accepted cost: the two rating types sit in different groups (Frame Ratings stays under Optical, a purchasing concern with a different audience). |
| 8 | Frame-rating aggregates in the catalog | **In scope**, resequenced into the prelude as Task 0d | Originally planned as a cuttable final commit. A follow-up audit found the whole frame-rating *read path* was unbuilt — no `is_rateable`, no `rating`, inert `?filter=`, plus a moderation-data leak — so the aggregates now ship alongside those fixes in Tasks 0a–0d, **before** visit feedback. See the plan's Prelude section. |

### Why frame ratings stay a separate feature

Merging the two would destroy both signals. A patient who liked their optometrist but
dislikes their frames would have one number to give, and neither the purchasing team nor
clinic management could tell which it referred to.

| | Frame rating (shipped) | Visit rating (this spec) |
|---|---|---|
| Question | "Is this frame model any good?" | "Was this visit any good?" |
| Anchor | Catalog item (`product_variant`) | Event (`appointment`) |
| Lever | Purchasing — stop restocking a 2★ frame | Staffing / process |
| Aggregates by | Product, brand | Optometrist, service, visit type |
