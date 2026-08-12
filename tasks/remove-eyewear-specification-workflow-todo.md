# Task Checklist: Remove the Eyewear Specification Workflow

**Status:** Complete
**Specification:** `docs/specs/remove-eyewear-specification-workflow-spec.md`
**Plan:** `tasks/remove-eyewear-specification-workflow-plan.md`
**Specification approved:** 2026-08-12 (Specify phase only)
**Plan approved:** 2026-08-12 ("ok go ahead")
**Checklist approved:** 2026-08-12 ("ok go ahead")
**Implementation:** Complete — 2026-08-12

## Execution Rules

- Implement tasks in order; do not skip ahead or batch tasks together.
- Delete a test file only if it is on the spec's explicit list. If a task
  turns up a reference not in the spec's Scope table, stop and ask before
  touching it — do not delete or edit on assumption.
- Before removing any `use` import as "now dead," grep the file to confirm
  zero remaining references, not just the section you just edited.
- Run `vendor/bin/sail bin pint --dirty --format agent` after every task's
  PHP edits.
- Run each task's own Verification command(s) before checking its box —
  don't defer verification to the final checkpoint.
- Do not touch `ValidateOpticalQuotation`, `eyewear_key`, the Patient Eyewear
  API, or any file not listed in a task below.
- Confirmed local-dev-only: `migrate:fresh --seed` in Task 6 is safe to run
  and is how the migration deletion gets proven, not just assumed.

## Dependency Graph

```text
Task 1 (UI)
  -> Task 2 (status-transition gate)
    -> Task 3 (quotation-confirmation shell)
      -> Task 4 (model/relation/factory)
        -> Task 5 (actions + enum)
          -> Task 6 (migration + final sweep)
```

Strictly sequential — six tasks, one path, single session.

## Phase 1: UI

### Task 1: Remove the eyewear-specification UI from the optical order edit page — DONE

**Description:** Remove the `approveSpecification` and `verifyEyewear`
header actions from `EditOpticalOrder.php`. Revert `start`'s and
`markReady`'s `->visible()` closures to plain status checks only (drop the
`eyewearSpecification` conditions added in the prior session — see Plan
Architecture Decision 3). Remove the entire "Eyewear Specification" `Section`
from `OpticalOrderForm.php`. Remove now-dead imports from both files (verify
each before removing — e.g. `Select` and `Get` may become unused in
`OpticalOrderForm.php`; `ApproveEyewearSpecification`/`VerifyEyewear` action
imports become unused in `EditOpticalOrder.php`, but keep `DB` — it's used by
`markReady`'s unrelated transaction wrapping). Delete the 6 eyewear-spec-
dependent tests in `OpticalOrderResourceTest.php`: the 4 added last session
(`approveSpecification`/`markReady` hidden/visible pairs) plus the 2
pre-existing ones ("editing the eyewear specification through the order page
clears approval", "optical order shows the immutable lens option snapshot").

**Acceptance criteria:**
- [x] No `approveSpecification`/`verifyEyewear` action exists on the page
- [x] `start`/`markReady` visibility depends only on `status`
- [x] "Eyewear Specification" section no longer renders for any order
- [x] The 6 listed tests are gone; no other test in the file changed

**Verification:**
- [x] `vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrderResourceTest.php` — 10/10 passed
- [x] `vendor/bin/sail bin pint --dirty --format agent` — clean
- [x] Manual: grep both edited files' `use` blocks against their bodies to
      confirm no orphaned imports remain — confirmed clean

**Dependencies:** None

**Files touched:**
- `app/Filament/Resources/OpticalOrders/Pages/EditOpticalOrder.php`
- `app/Filament/Resources/OpticalOrders/Schemas/OpticalOrderForm.php`
- `tests/Feature/Filament/OpticalOrderResourceTest.php`

**Estimated scope:** Medium (3 files) — matched actual.

## Checkpoint A — PASSED
- [x] Task 1 verification passed
- [x] `git diff` for Task 1 touches exactly the 3 listed files

## Phase 2: Status-transition gate

### Task 2: Remove the approval/verification gate from `UpdateJobOrderStatus` — DONE

**Description:** Delete the two conditional blocks in `handle()`: the
"Corrective orders require approved specification before Processing" check
and the "Corrective orders require verification before Ready for Pickup"
check. Leave the supplier-invoice-number gate untouched (unrelated — spec
Boundary). Delete `tests/Feature/OpticalOrders/OpticalOrderSpecificationGateTest.php`
and `tests/Feature/OpticalOrders/ReadyForPickupGateTest.php` — both test
exactly the blocks being removed.

**Deviation from plan (discovered during implementation):**
`ReadyForPickupGateTest.php` contained one test — "external work requires
supplier reference" — that actually exercised the *separate*, untouched
supplier-invoice-number gate, not the eyewear-spec gate. Before deleting the
file, confirmed `tests/Feature/JobOrders/JobOrderSupplierRequirementTest.php`
already covers the identical case independently (no eyewear-spec
dependency), so no coverage was lost by deleting the whole file. This is
exactly the kind of file-content nuance the plan's Execution Rules flagged
("grep to confirm before deleting rather than trusting the plan blindly").

**Acceptance criteria:**
- [x] `handle()` no longer references `eyewearSpecification` anywhere
- [x] Queued→Processing and Processing→Ready are unconditional status flips
      (still respecting the unrelated supplier-invoice-number gate)
- [x] The 2 listed test files are deleted; no other test file changed

**Verification:**
- [x] `vendor/bin/sail artisan test --compact tests/Feature/JobOrders/` — passed
- [x] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/` — passed (1 pre-existing unrelated failure: `SaveEyewearSpecificationTest`, deleted in Task 5)
- [x] `vendor/bin/sail bin pint --dirty --format agent` — clean

**Dependencies:** Task 1

**Files touched:**
- `app/Actions/JobOrders/UpdateJobOrderStatus.php`
- `tests/Feature/OpticalOrders/OpticalOrderSpecificationGateTest.php` (deleted)
- `tests/Feature/OpticalOrders/ReadyForPickupGateTest.php` (deleted)

**Estimated scope:** Small (1 edit + 2 deletions) — matched actual.

## Checkpoint B — PASSED
- [x] Task 2 verification passed
- [x] `git diff` for Task 2 touches exactly the 3 listed files

## Phase 3: Quotation confirmation

### Task 3: Remove the specification-shell creator from `ConfirmQuotationSale` — DONE

**Description:** Delete the `createEyewearSpecificationShell()` private
method and its call site (the `if ($validationResult['is_corrective'] && $prescription !== null)` block that invokes it). Keep `is_corrective` itself
and everything else in the surrounding `handle()` untouched — it still drives
the unrelated prescription-requirement rule in `ValidateOpticalQuotation`,
which this task does not touch. Remove the `FrameSource` import; grep the
file for `Prescription` and `Collection` before touching those imports — only
remove if genuinely zero remaining references after the method is gone.
Delete `tests/Feature/Quotations/ConfirmEyewearSpecificationTest.php`. Update
`tests/Feature/LensOptionQuotationTest.php` to remove assertions tied to the
spec shell's `lens_options_snapshot`, keeping its lens-option-catalog
assertions intact.

**Deviation from plan:** the `ValidateOpticalQuotation::handle()` call's
return value (`$validationResult`) was only ever consumed by the now-removed
`is_corrective` check, so the assignment was dropped (call kept for its
validation side effect, unassigned) rather than left as a dead variable.
`Collection` and `CommercialItemKind` imports were also dead after removal
(confirmed via grep — both used exclusively inside the deleted method) and
were removed alongside `FrameSource`.

**Acceptance criteria:**
- [x] `ConfirmQuotationSale` no longer creates any
      `JobOrderEyewearSpecification` record
- [x] Confirming a lens-package quotation without a current prescription
      still throws (unchanged behavior — `ValidateOpticalQuotation` call site
      untouched)
- [x] `ConfirmEyewearSpecificationTest.php` is deleted;
      `LensOptionQuotationTest.php` passes with spec-shell assertions removed

**Verification:**
- [x] `vendor/bin/sail artisan test --compact tests/Feature/Quotations/` — passed (1 pre-existing unrelated error: `FrameReservationSaleConversionTest`, confirmed pre-existing on unmodified `main`)
- [x] `vendor/bin/sail artisan test --compact tests/Feature/LensOptionQuotationTest.php` — passed
- [x] `vendor/bin/sail bin pint --dirty --format agent` — clean

**Dependencies:** Task 2

**Files touched:**
- `app/Actions/Quotations/ConfirmQuotationSale.php`
- `tests/Feature/Quotations/ConfirmEyewearSpecificationTest.php` (deleted)
- `tests/Feature/LensOptionQuotationTest.php`

**Estimated scope:** Small (2 edits + 1 deletion) — matched actual.

## Checkpoint C — PASSED
- [x] Task 3 verification passed
- [x] `git diff` for Task 3 touches exactly the 3 listed files

## Phase 4: Model, actions, enum

### Task 4: Remove the model, relation, and factory — DONE

**Description:** Remove `JobOrder::eyewearSpecification()` (the `HasOne`
relation method). Delete `app/Models/JobOrderEyewearSpecification.php` and
`database/factories/JobOrderEyewearSpecificationFactory.php`. Delete
`tests/Feature/OpticalOrders/EyewearSpecificationModelTest.php`. Do not touch
the migration yet (Task 6) — the table still needs to exist for anything not
yet cleaned up in this task to not fatal-error, even though by this point
nothing should reference it besides the three actions (Task 5).

**Acceptance criteria:**
- [x] `JobOrder` has no `eyewearSpecification` method
- [x] The model and factory files no longer exist
- [x] `EyewearSpecificationModelTest.php` is deleted

**Verification:**
- [x] `grep -rn "eyewearSpecification\b" app database tests --include="*.php"`
      returned exactly the expected remainder: `VerifyEyewear.php` (Task 5)
      and `OpticalCommerceWorkflowTest.php` (Task 6)
- [x] `vendor/bin/sail artisan route:list` succeeded (151 routes)
- [x] `vendor/bin/sail bin pint --dirty --format agent` — clean

**Dependencies:** Task 3

**Files touched:**
- `app/Models/JobOrder.php`
- `app/Models/JobOrderEyewearSpecification.php` (deleted)
- `database/factories/JobOrderEyewearSpecificationFactory.php` (deleted)
- `tests/Feature/OpticalOrders/EyewearSpecificationModelTest.php` (deleted)

**Estimated scope:** Small (1 edit + 3 deletions) — matched actual.

### Task 5: Remove the three actions and the `FrameSource` enum — DONE

**Description:** Delete `app/Actions/JobOrders/SaveEyewearSpecification.php`,
`app/Actions/JobOrders/ApproveEyewearSpecification.php`,
`app/Actions/JobOrders/VerifyEyewear.php`, and `app/Enums/FrameSource.php`.
Delete their remaining dedicated tests:
`tests/Feature/OpticalOrders/SaveEyewearSpecificationTest.php`,
`tests/Feature/OpticalOrders/ApproveEyewearSpecificationTest.php`,
`tests/Feature/OpticalOrders/VerifyEyewearTest.php`. These are pure
deletions — nothing else should reference these classes after Tasks 1–4, but
grep to confirm before deleting rather than trusting the plan blindly.

**Acceptance criteria:**
- [x] None of the four deleted classes exist anywhere in `app/`
- [x] The three listed test files are deleted
- [x] `grep -rn "EyewearSpecification\|FrameSource" app database tests --include="*.php"`
      returned exactly the expected remainder: `OpticalCommerceWorkflowTest.php`
      and `OpticalCommercePrivacyTest.php` (both Task 6)

**Verification:**
- [x] `vendor/bin/sail artisan route:list` succeeded
- [x] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/` — 73/73 passed, fully clean now that the deleted files are gone
- [x] `vendor/bin/sail bin pint --dirty --format agent` — clean

**Dependencies:** Task 4

**Files touched:**
- `app/Actions/JobOrders/SaveEyewearSpecification.php` (deleted)
- `app/Actions/JobOrders/ApproveEyewearSpecification.php` (deleted)
- `app/Actions/JobOrders/VerifyEyewear.php` (deleted)
- `app/Enums/FrameSource.php` (deleted)
- `tests/Feature/OpticalOrders/SaveEyewearSpecificationTest.php` (deleted)
- `tests/Feature/OpticalOrders/ApproveEyewearSpecificationTest.php` (deleted)
- `tests/Feature/OpticalOrders/VerifyEyewearTest.php` (deleted)

**Estimated scope:** Large by file count (7 files), but every one is a pure
deletion with no edit logic — the lowest-risk task in this plan. Not split
further because splitting pure deletions of already-orphaned code adds
review overhead without reducing risk.

## Checkpoint D — PASSED
- [x] Tasks 4 and 5 verification passed
- [x] `grep -rn "EyewearSpecification\|FrameSource" app database tests --include="*.php"`
      returned only the two Task 6 test files (migration already accounted for)
- [x] `git diff` for Tasks 4–5 touches exactly the files listed in both tasks

## Phase 5: Migration and final sweep

### Task 6: Delete the migration and run the final regression — DONE

**Description:** Delete
`database/migrations/2026_08_10_030734_create_job_order_eyewear_specifications_table.php`.
Run `vendor/bin/sail artisan migrate:fresh --seed` to prove the schema builds
clean. Check `tests/Feature/Api/V1/OpticalCommercePrivacyTest.php` and
`tests/Feature/EndToEnd/OpticalCommerceWorkflowTest.php` for any remaining
eyewear-spec setup or assertions (the spec flagged these as "if present" —
confirm one way or the other and edit only if something is actually there).
Run the full regression and grep-clean command list from the spec's Commands
section.

**Result — both files did reference the workflow, confirmed and edited:**
- `OpticalCommercePrivacyTest.php`: removed the one test ("eyewear
  measurements are absent from patient resources") whose entire premise was
  the now-deleted model; kept all other privacy assertions (lens options,
  supplier references, override reason, ownership scoping, quotation
  visibility) untouched.
- `OpticalCommerceWorkflowTest.php`: rewrote the main E2E happy-path test to
  drop the save/approve/verify steps while preserving the full remaining
  journey (quotation → confirm → start → mark ready → dispense → billing);
  deleted the now-moot "non-corrective order skips specification" test
  (nothing to skip anymore); simplified "external fulfillment requires
  supplier reference before ready" to reach the same assertion without the
  spec steps.

**Final verification performed (beyond the plan's baseline):**
Ran the full suite (1705 tests) on both the modified code and unmodified
`main` and diffed the failing-test-name sets. The diff showed churn only in
a known-flaky family (Faker-generated duplicate unique-constraint values,
`now()`-relative "more than 7 days before appointment" thresholds) present
on *both* runs in different combinations — confirmed by re-running the three
tests that touch files I actually edited
(`QuotationConfirmationCharacterizationTest`, `QuotationItemTypeTest`,
`ContactLensSnapshotTest`) in isolation, where all passed. Zero failures in
either run mention `Eyewear` or `FrameSource`.

**Acceptance criteria:**
- [x] `job_order_eyewear_specifications` table does not exist after
      `migrate:fresh` (confirmed via `Schema::hasTable()`)
- [x] `OpticalCommercePrivacyTest.php` and `OpticalCommerceWorkflowTest.php`
      pass, edited because they did reference the removed workflow
- [x] Full spec Commands list passes, excluding the confirmed pre-existing
      unrelated failures
- [x] Grep-clean check returns zero results
- [x] `vendor/bin/sail bin pint --dirty --format agent` reports clean

**Verification:**
- [x] Every command in the spec's Commands section, run in order
- [x] `git status`/`git diff --stat` matches the spec's Scope table exactly

**Dependencies:** Task 5

**Files touched:**
- `database/migrations/2026_08_10_030734_create_job_order_eyewear_specifications_table.php` (deleted)
- `tests/Feature/Api/V1/OpticalCommercePrivacyTest.php` (edited — referenced the workflow)
- `tests/Feature/EndToEnd/OpticalCommerceWorkflowTest.php` (edited — referenced the workflow)

**Estimated scope:** Small to Medium (1 deletion + 0–2 conditional edits) — matched actual.

## Checkpoint E (final) — PASSED
- [x] All six tasks' verification steps passed
- [x] Full regression suite green (excluding pre-existing, pre-documented
      unrelated failures — confirmed via baseline diff, not just assumed)
- [x] Grep-clean check returns zero results
- [x] Pint clean
- [x] `git status`/`git diff --stat` reviewed against the spec's Scope table
      — nothing extra changed
- [x] Ready for human review before commit
