# Tasks: Mobile Visit Feedback & Service Ratings

> Spec: `mobile-visit-feedback-spec.md` · Plan: `mobile-visit-feedback-plan.md`

Run `vendor/bin/sail bin pint --dirty --format agent` before finalizing every task.

---

# Prelude — finish the frame-rating read path

The write path shipped; the read path never did. `API_CONTRACT.md` §14–§15 documents
the full interface, but `?filter=` is inert, optical order items expose no rating
fields, and the rating endpoint leaks staff moderation data. Doing this **before**
Tasks 1–7 means Task 5 mirrors code that actually exists, and old Task 8 folds in here.

Each prelude task removes its own ⚠️ markers from `API_CONTRACT.md`.

- [ ] **Task 0 — Green the `Api/V1` baseline** *(approved as "0e"; sequenced first, because
      every later task's "no new failures" check depends on it)*
  - **Description:** 27 of 176 tests in `tests/Feature/Api/V1` fail before any of this
    work starts — almost all exercising routes the Android cutover deliberately removed.
    A red baseline hides new regressions: if one of these starts failing for a *different*
    reason, nobody notices.
  - **Acceptance:**
    - **Retarget, don't delete, where a live successor exists:** `job-orders` →
      `optical-orders`; `MeEndpointTest`/`AuthContractTest` profile assertions →
      `linked_patient.*`. The behavior under test is still real; only the address moved.
    - **Delete where the capability is genuinely gone:** `/register`, `/login` (replaced by
      two-stage OTP auth), `/billing-records`, `/eyewear`.
    - `RouteContractTest`'s expected route list is corrected in full — the *current* live
      list, not a patch. It must contain **52** entries (generate it with
      `vendor/bin/sail artisan route:list --path=api/v1 --json`, don't hand-edit), and
      must include the `job-order-items/{item}/rating` legacy alias with a comment saying
      what it is. Task 4 later makes it 53.
    - **Delete the two self-contradicting assertions.**
      `AuthContractCharacterizationTest`'s *"register route still exists for backward
      compatibility"* and *"login route still exists…"* assert the exact opposite of
      `RouteContractTest::legacy routes are absent`. They are unsatisfiable together;
      the cutover made the latter correct.
    - **Keep** the genuinely valuable tests in that file — `patients.user_id is the
      authoritative active link`, `one patient cannot link to multiple accounts`,
      `deleting the account preserves but unlinks the patient`, the walk-in behavior,
      and the intake/appointment-types removal checks. Only the pre-cutover auth
      characterizations go.
    - `tests/Feature/Api/V1` finishes **green**.
    - No test is deleted that covers behavior still reachable through another route —
      check each one before removing it.
  - **Verify:** `vendor/bin/sail artisan test --compact tests/Feature/Api/V1`
  - **Files:** the 5 affected test files under `tests/Feature/Api/V1/`

- [ ] **Task 0a — `FrameRatingResource`: stop leaking moderation data**
  - **Description:** `FrameRatingController::store` returns `$rating->load('revisions')`
    — the raw model, no resource class. Wrap it.
  - **Acceptance:**
    - Response exposes only `id`, `item_id`, `product_variant_id`, `rating`, `comment`,
      `revision_number`, `created_at`.
    - **`moderation_reason`, `moderated_by`, `moderated_at`, `is_hidden`, `patient_id`,
      `deleted_at`, `current_revision_id` are absent.**
    - The author still sees their **own** `comment` even when staff hid it — hiding
      suppresses public/aggregate display, not the author's view of their own words.
      (Refines the spec's blanket "hidden comments are not returned"; that rule applies
      to aggregate surfaces, not to showing an author their own submission.)
    - `product_variant_id` becomes **optional** in the request: derived from the route's
      item when omitted, still validated as matching when supplied. This makes the
      contract's "the client does not submit the variant ID" true without breaking any
      client already sending it.
    - `201` on create, `200` on revise, per the contract.
  - **Verify:** `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameRatingTest.php`
  - **Files:** `app/Http/Resources/Api/FrameRatingResource.php`,
    `app/Http/Controllers/Api/FrameRatingController.php`,
    `tests/Feature/Api/V1/FrameRatingTest.php`, `docs/API_CONTRACT.md`

- [ ] **Task 0b — Implement `?filter=` on quotations and optical orders**
  - **Description:** Both controllers ignore the parameter the contract specifies.
    Semantics are already fully defined there — no product decision needed.
  - **Acceptance:**
    - Optical orders: `current` = `queued`/`in_progress`/`ready_for_dispensing`;
      `history` = `dispensed`/`cancelled`; default `current`.
    - Quotations: `current` = `presented`; `history` = `accepted`/`declined`/`expired`;
      drafts never returned under either.
    - Invalid `filter` value is a 422, not a silent fallback.
    - `per_page` validated to 1–50 as documented.
    - Optical-order ordering becomes `created_at DESC, id DESC` (deterministic ties).
    - **Fix the pre-existing failure in this file's path:**
      `WorkflowReadsTest::quotations list is paginated and patient-scoped` currently
      asserts 3 and gets 0, because `QuotationFactory` defaults to `Draft` and the
      controller excludes drafts. Switch it to the `presented()` state — which is what
      `filter=current` now requires anyway. Do **not** leave it red and call it pre-existing.
  - **Verify:** `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/`
  - **Files:** `app/Http/Controllers/Api/OpticalOrderController.php`,
    `app/Http/Controllers/Api/QuotationController.php`, their tests, `docs/API_CONTRACT.md`

- [ ] **Task 0c — Optical order item rating fields**
  - **Description:** Add the four documented-but-missing fields. This establishes the
    `is_rateable` convention that visit-feedback Task 5 will mirror.
  - **Acceptance:**
    - `items[].product_variant_id`, `items[].is_rateable`, `items[].rating` present.
    - `is_rateable` is `true` only for a **dispensed** order's item with a non-null
      `product_variant_id`.
    - `payment_summary.is_overdue` present (`BillingRecord::isOverdue()`).
    - **`payment_summary.status` returns the machine-readable enum value**
      (`partially_paid`), not `getLabel()`'s `"Partially Paid"` — the contract always
      specified this. **Breaking change for any client string-matching the label; call
      it out in the commit.**
    - No N+1: the patient's `FrameRating`s are fetched once and keyed by variant, not
      queried per item.
  - **Verify:** `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/`
  - **Files:** `app/Http/Resources/Api/OpticalOrderResource.php`,
    `app/Http/Controllers/Api/OpticalOrderController.php`, tests, `docs/API_CONTRACT.md`

- [ ] **Task 0d — Catalog rating aggregates** *(was Task 8)*
  - **Description:** Ratings are collected but never surfaced. Expose them where they
    earn their keep — the frame catalog a patient browses in AR try-on.
  - **Acceptance:** as old Task 8 (see bottom of this file).
  - **Verify:** frame catalog test file
  - **Files:** frame resource(s), `FrameController`, `ProductVariant`, tests

> **CHECKPOINT 0** — frame-rating read path complete, all ⚠️ markers gone from
> `API_CONTRACT.md`. Only then start Task 1.

---

# Visit feedback

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
    - ⚠️ **`RouteContractTest::every_approved_v1_route_is_present_exactly_once` must be
      updated in this commit.** It hard-asserts the exact v1 route list, so adding a route
      breaks it. It is *already* failing on a stale list (expects the removed
      `job-orders`/`billing-records`/`eyewear`, missing `optical-orders`) — fix the whole
      list, don't just append the new route.
  - **Verify:** `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/VisitRatingTest.php`
  - **Files:** `app/Http/Controllers/Api/VisitRatingController.php`,
    `app/Http/Requests/Api/StoreVisitRatingRequest.php`,
    `app/Http/Resources/VisitRatingResource.php`, `routes/api.php`,
    `tests/Feature/Api/V1/VisitRatingTest.php`
  - **Scope:** Medium (5 files)

---

- [ ] **Task 5 — Appointment contract: `is_rateable` + `rating`**
  - **Description:** Let the app discover what can be rated without a new endpoint, using
    the `is_rateable` + `rating` shape `API_CONTRACT.md` §15 documents for optical order
    items. **Note:** that shape is documented but *not implemented* there (see the §15
    drift notice) — this task is the first real implementation of it, not a copy of
    working code.
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

## Task 0d detail — Surface frame-rating aggregates in the catalog

> Originally Task 8 (a post-feature optional). **Resequenced into the prelude as Task 0d**
> once we decided to finish the frame-rating read path first — it touches the same
> resources as 0c, so it belongs with them rather than as a trailing commit.

- [x] **Task 0d — Surface frame-rating aggregates in the catalog** *(shipped `5dcf292`,
      2026-08-07 — but one acceptance criterion below is not met; see the note)*
  - **Description:** Fixes a hole found while writing this spec: frame ratings are
    **write-only** — collected by `POST /optical-order-items/{item}/rating` but never
    returned by `GET /frames` or `GET /frames/{id}`. `ModerateFrameRating`'s own docblock
    references preserving stars "in aggregates" that do not exist.
  - **Acceptance:**
    - [x] `GET /frames` and `GET /frames/{id}` expose `average_rating` (1 decimal, null when
      unrated) and `rating_count` per variant or product — decide which at implementation.
      *(Per product, via `FrameResource`.)*
    - [ ] **Hidden ratings still count toward the average** (that is the documented intent
      of hiding only the comment); their comments are never exposed. **Not met as shipped:**
      `FrameController` eager-loads `ratings` filtered to `where('is_hidden', false)`, so a
      hidden rating's star is excluded from the average and count entirely, not just its
      comment. Staff hiding an abusive 1-star comment also erases that 1 star from the
      product's average. See `docs/API_CONTRACT.md` §11 for the flagged detail. Needs a
      follow-up fix: drop the `is_hidden` filter on the eager-load, keep it only on whatever
      exposes the comment text.
    - [~] Aggregates are computed with `withAvg`/`withCount`, not an N+1 per row. *(Shipped
      as a single filtered eager-load + in-PHP `avg()`/`count()` over the loaded collection
      instead — not literally `withAvg`/`withCount`, but also not N+1: one query per
      relation, batched across the page. Acceptable as shipped; revisit only if profiling
      shows it matters.)*
  - **Verify:** `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameCatalogTest.php`
  - **Files:** `app/Http/Resources/FrameResource.php`,
    `app/Http/Controllers/Api/FrameController.php`
  - **Scope:** Small (≤4 files)
