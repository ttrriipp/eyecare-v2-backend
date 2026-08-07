# Tasks: Mobile Visit Feedback & Service Ratings

> Spec: `mobile-visit-feedback-spec.md` · Plan: `mobile-visit-feedback-plan.md`

Run `vendor/bin/sail bin pint --dirty --format agent` before finalizing every task.

---

- [ ] **Task 1 — Foundation: schema, models, factory**
  - **Description:** Create `visit_ratings` + `visit_rating_revisions` tables and their
    Eloquent models, mirroring the shipped `FrameRating` pair. Unlike `frame_ratings`
    (which added moderation columns in a follow-up migration), create all columns in one
    migration since this is greenfield.
  - **Acceptance:**
    - `visit_ratings` has `unique(appointment_id)`; `encounter_id`, `optometrist_id`,
      `moderated_by` all `nullOnDelete`; `service_ids` is `json` nullable; soft deletes on.
    - `current_revision_id` FK added *after* `visit_rating_revisions` exists (circular FK,
      same ordering as the frame_ratings migration).
    - Models use `#[Fillable([...])]`, typed relations with PHPDoc generics, and cast
      `rating => integer`, `is_hidden => boolean`, `service_ids => array`,
      `moderated_at`/`revised_at` => `datetime`.
    - `Appointment::visitRating(): HasOne` added.
    - `VisitRatingFactory` produces a valid rating tied to a fulfilled appointment.
  - **Verify:** `vendor/bin/sail artisan migrate --no-interaction` then
    `vendor/bin/sail artisan test --compact --filter=VisitRatingFactory` (a trivial
    factory smoke test), and confirm rollback works with `migrate:rollback`.
  - **Files:** `database/migrations/*_create_visit_ratings_table.php`,
    `app/Models/VisitRating.php`, `app/Models/VisitRatingRevision.php`,
    `app/Models/Appointment.php`, `database/factories/VisitRatingFactory.php`
  - **Scope:** Medium (5 files)

---

- [ ] **Task 2 — `SaveVisitRating` action**
  - **Description:** The domain core. Create-or-revise a rating for a fulfilled
    appointment, snapshotting the optometrist and the services rendered.
  - **Acceptance:**
    - Rejects a non-`fulfilled` appointment with `ValidationException`.
    - Rejects `rating` outside 1–5.
    - First call creates the rating + revision #1 and sets `current_revision_id`.
    - Second call appends revision #2, updates the head row, and **retains** revision #1.
    - `optometrist_id` and `service_ids` are captured on create and **not** recomputed on
      revise — editing the encounter afterward must not change them.
    - `service_ids` includes service items reachable by *both* paths in the plan
      (own `encounter_id`, and via the parent billing record's `encounter_id`).
    - Runs in `DB::transaction`; the lock **re-fetches** (`Model::query()->lockForUpdate()
      ->find(...)`) rather than calling `lockForUpdate()` on a hydrated instance.
  - **Verify:** `vendor/bin/sail artisan test --compact tests/Feature/Ratings/SaveVisitRatingTest.php`
  - **Files:** `app/Actions/Ratings/SaveVisitRating.php`,
    `tests/Feature/Ratings/SaveVisitRatingTest.php`
  - **Scope:** Small (2 files)

---

- [ ] **Task 3 — `ModerateVisitRating` action**
  - **Description:** Hide/restore a comment while preserving the star value, mirroring
    `ModerateFrameRating` including its `handle()` / `restore()` shape.
  - **Acceptance:**
    - `handle()` sets `is_hidden`, `moderation_reason`, `moderated_by`, `moderated_at`;
      throws if already hidden.
    - `restore()` clears the reason and is a no-op (not an error) when not hidden.
    - The `rating` integer is never mutated by either method.
  - **Verify:** `vendor/bin/sail artisan test --compact tests/Feature/Ratings/ModerateVisitRatingTest.php`
  - **Files:** `app/Actions/Ratings/ModerateVisitRating.php`,
    `tests/Feature/Ratings/ModerateVisitRatingTest.php`
  - **Scope:** Small (2 files)

> **CHECKPOINT A** — domain proven without an HTTP layer. Review before wiring the API.

---

- [ ] **Task 4 — Mobile API endpoint**
  - **Description:** `POST /api/v1/appointments/{appointment}/rating` inside the
    `require.patient.link` group. Upsert semantics: 201 on create, 200 on revise.
  - **Acceptance:**
    - Appointment is resolved **through the patient's own relation**, so a foreign or
      nonexistent id both yield 404 with an identical body.
    - `rating` required integer 1–5; `comment` nullable string max 1000 — validated in a
      `StoreVisitRatingRequest`, not inline in the controller.
    - Unlinked account is rejected by existing `require.patient.link` middleware (403).
    - Response uses a `VisitRatingResource`; a hidden comment returns `comment: null`.
  - **Verify:** `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/VisitRatingTest.php`
  - **Files:** `app/Http/Controllers/Api/VisitRatingController.php`,
    `app/Http/Requests/Api/StoreVisitRatingRequest.php`,
    `app/Http/Resources/VisitRatingResource.php`, `routes/api.php`,
    `tests/Feature/Api/V1/VisitRatingTest.php`
  - **Scope:** Medium (5 files)

---

- [ ] **Task 5 — Appointment contract: `is_rateable` + `rating`**
  - **Description:** Let the app discover what can be rated without a new endpoint,
    mirroring the `is_rateable` + `rating` shape already documented for optical order items.
  - **Acceptance:**
    - `is_rateable` is `true` only when `status = fulfilled`.
    - `rating` is `null` until submitted, else `{rating, comment, revision_number, created_at}`.
    - Hidden comment ⇒ `comment: null` but stars still present.
    - `AppointmentController::index` eager-loads `visitRating` — assert no N+1 growth in
      query count across 1 vs 5 appointments.
  - **Verify:** `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/` (whole
    V1 dir — this edits a shared resource, so re-run the neighbours)
  - **Files:** `app/Http/Resources/AppointmentResource.php`,
    `app/Http/Controllers/Api/AppointmentController.php`,
    `tests/Feature/Api/V1/VisitRatingTest.php`
  - **Scope:** Small (3 files)

---

- [ ] **Task 6 — Filament admin resource + navigation test**
  - **Description:** Read-and-moderate resource mirroring `FrameRatings/`. Navigation
    group **Patients**, labeled "Visit Feedback".
  - **Acceptance:**
    - List shows patient, appointment/visit date, optometrist, stars, comment, hidden flag.
    - Filter by star value; filter for hidden-only.
    - Row actions: Hide Comment (required reason, confirmation) and Restore, both
      delegating to `ModerateVisitRating` — no domain logic inline in the table class.
    - No create action — feedback originates from mobile only.
    - **`AdminNavigationStructureTest` is updated in this same commit** (it locks group
      order, per-group item order, and unique outlined icons — adding a resource fails it).
  - **Verify:** `vendor/bin/sail artisan test --compact
    tests/Feature/Filament/VisitRatingResourceTest.php
    tests/Feature/Filament/AdminNavigationStructureTest.php`
  - **Files:** `app/Filament/Resources/VisitRatings/VisitRatingResource.php`,
    `.../Pages/ListVisitRatings.php`, `.../Tables/VisitRatingsTable.php`,
    `tests/Feature/Filament/VisitRatingResourceTest.php`,
    `tests/Feature/Filament/AdminNavigationStructureTest.php`
  - **Scope:** Medium (5 files)

> **CHECKPOINT B** — mobile contract complete; Android can begin integrating.

---

- [ ] **Task 7 — Documentation reconciliation**
  - **Description:** These are living documents per their own headers; update them in the
    same change, not later.
  - **Acceptance:**
    - `API_CONTRACT.md`: new section for the rating endpoint + the two new
      `AppointmentResource` fields; route count updated (51 → 52).
    - `BACKEND_CONTEXT.md`: `visit_ratings` / `visit_rating_revisions` in Business Tables,
      the two actions in Key Actions, "Visit Feedback" in the Patients nav group.
    - `gap-analysis.md` §J: distinguish product feedback (shipped) from service feedback
      (now shipped) — the existing ✅ is currently earned by frame ratings alone.
  - **Verify:** Manual read-through; confirm no stale route count or missing table row.
  - **Files:** `docs/API_CONTRACT.md`, `docs/BACKEND_CONTEXT.md`, `docs/gap-analysis.md`
  - **Scope:** Small (3 files)

> **CHECKPOINT C** — full suite vs. pre-change baseline, no new failures.

---

- [ ] **Task 8 — OPTIONAL / SEPARABLE: surface frame-rating aggregates in the catalog**
  - **Description:** Not part of visit feedback. Fixes a hole found while writing this
    spec: frame ratings are **write-only** — collected by
    `POST /optical-order-items/{item}/rating` but never returned by `GET /frames` or
    `GET /frames/{id}`. `ModerateFrameRating`'s own docblock references preserving stars
    "in aggregates" that do not exist. Cut this task freely; it does not block anything.
  - **Acceptance:**
    - `GET /frames` and `GET /frames/{id}` expose `average_rating` (1 decimal, null when
      unrated) and `rating_count` per variant or product — decide which at implementation.
    - Hidden ratings still count toward the average (that is the documented intent of
      hiding only the comment); their comments are never exposed.
    - Aggregates are computed with `withAvg`/`withCount`, not an N+1 per row.
  - **Verify:** `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameTest.php`
    (or the existing frame catalog test file)
  - **Files:** `app/Http/Resources/` frame resource(s),
    `app/Http/Controllers/Api/FrameController.php`, `app/Models/ProductVariant.php`,
    frame catalog test
  - **Scope:** Small (≤4 files)
