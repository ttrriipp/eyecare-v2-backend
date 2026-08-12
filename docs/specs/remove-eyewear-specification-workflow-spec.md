# Spec: Remove the Eyewear Specification Workflow

**Status:** Draft
**Phase:** Specify approved — Plan and Tasks not yet started
**Date:** 2026-08-12
**Approved:** 2026-08-12 (Specify phase only)

> **Naming disambiguation:** this spec removes `App\Models\JobOrderEyewearSpecification`
> and its optometrist-approve → staff-verify workflow. It does **not** touch the
> unrelated "Patient Eyewear" concept described in
> `single-optical-orders-workflow-spec.md` / `simplified-optical-orders-and-billing-spec.md`
> (the `/api/v1/eyewear` patient order-tracking API, or the `eyewear_key` ULID
> column on `quotations`/`job_orders`). Those share the word "eyewear" by
> coincidence and are explicitly out of scope — see Boundaries.

## Objective

Delete the eyewear-specification approve/verify workflow from optical orders
so every job order — corrective or not — follows the same simple status flow:
`queued → in_progress → ready_for_dispensing → dispensed` (or `cancelled`),
gated only by inventory and billing, never by a clinical specification check.

Today, a corrective order (one with a lens package) gets an auto-created
`JobOrderEyewearSpecification` "shell" at quotation confirmation. An
optometrist must approve it before the order can start processing, and staff
must verify the finished eyewear before it can be marked ready for pickup.
This is a real, working two-step QA gate — not dead code — but the user has
decided optical orders should be simpler, and this workflow is not a tracked
requirement in `docs/gap-analysis.md` or the UX audit.

Success means: no code, schema, or UI references this workflow; corrective
orders move through the same unconditional status transitions as any other
order; and everything genuinely unrelated (the prescription-for-corrective
sale rule, the Patient Eyewear API, `eyewear_key`) is untouched.

## Approved Product Decisions and Assumptions

1. **No replacement gate.** Queued→Processing and Processing→Ready become
   plain status flips with no clinical checkpoint substituted. *(Approved
   2026-08-12.)*
2. **Migration deleted outright**, not reverted via a new down-migration.
   `job_order_eyewear_specifications` has only ever been run in local dev.
   *(Approved 2026-08-12.)*
3. **Tests deleted, not just skipped.** The 7 test files dedicated entirely
   to this workflow are deleted; the 3 files that reference it only in
   passing are updated in place. *(Approved 2026-08-12.)*
4. `ValidateOpticalQuotation`'s rule requiring a current prescription for any
   quotation containing a lens package is a separate sale-level business
   rule and is **not** part of this removal.
5. `eyewear_key` (on `quotations`/`job_orders`) and the Patient Eyewear
   aggregate API are unrelated infrastructure sharing a name; **not** part of
   this removal.
6. This is a local-only academic project with no production data at stake;
   losing any seeded/demo `JobOrderEyewearSpecification` rows on migration
   rollback is acceptable.

## Scope

### Remove entirely

| File | What |
|---|---|
| `app/Models/JobOrderEyewearSpecification.php` | Model |
| `database/factories/JobOrderEyewearSpecificationFactory.php` | Factory |
| `database/migrations/2026_08_10_030734_create_job_order_eyewear_specifications_table.php` | Migration (delete file, not a new down-migration — see Decision 2) |
| `app/Actions/JobOrders/SaveEyewearSpecification.php` | Action |
| `app/Actions/JobOrders/ApproveEyewearSpecification.php` | Action |
| `app/Actions/JobOrders/VerifyEyewear.php` | Action |
| `app/Enums/FrameSource.php` | Enum (only ever used within this cluster) |
| `tests/Feature/OpticalOrders/SaveEyewearSpecificationTest.php` | Test |
| `tests/Feature/OpticalOrders/ApproveEyewearSpecificationTest.php` | Test |
| `tests/Feature/OpticalOrders/VerifyEyewearTest.php` | Test |
| `tests/Feature/OpticalOrders/EyewearSpecificationModelTest.php` | Test |
| `tests/Feature/OpticalOrders/OpticalOrderSpecificationGateTest.php` | Test |
| `tests/Feature/OpticalOrders/ReadyForPickupGateTest.php` | Test |
| `tests/Feature/Quotations/ConfirmEyewearSpecificationTest.php` | Test |

### Edit

| File | What changes |
|---|---|
| `app/Models/JobOrder.php` | Remove the `eyewearSpecification()` `HasOne` relation |
| `app/Actions/JobOrders/UpdateJobOrderStatus.php` | Remove the two spec-gate conditional blocks (Processing requires approval, Ready requires verification); transitions become unconditional status flips |
| `app/Actions/Quotations/ConfirmQuotationSale.php` | Remove `createEyewearSpecificationShell()` and its call site; drop now-dead imports (`FrameSource` at minimum — re-check `Prescription`/`Collection` are still used elsewhere in the file before removing those) |
| `app/Filament/Resources/OpticalOrders/Schemas/OpticalOrderForm.php` | Remove the entire "Eyewear Specification" `Section`; drop now-dead imports (`Select`, `Get`, `JobOrderEyewearSpecification`, `FrameSource`, `SaveEyewearSpecification`, `User` — verify each is truly unused elsewhere in the file first) |
| `app/Filament/Resources/OpticalOrders/Pages/EditOpticalOrder.php` | Remove the `approveSpecification` and `verifyEyewear` header actions entirely; remove the eyewear-spec conditions from `start`'s and `markReady`'s `->visible()` closures (added in the prior session's fix — now dead since the spec can't exist); drop dead imports |
| `tests/Feature/Filament/OpticalOrderResourceTest.php` | Remove the eyewear-spec-dependent tests added last session (`approveSpecification`/`markReady` hidden/visible pairs) and the pre-existing "editing the eyewear specification…" and "shows the immutable lens option snapshot" tests |
| `tests/Feature/Api/V1/OpticalCommercePrivacyTest.php` | Remove eyewear-spec setup/assertions if present; keep everything else |
| `tests/Feature/EndToEnd/OpticalCommerceWorkflowTest.php` | Remove approve/verify steps from the end-to-end flow; adjust any assertions that depended on the gate blocking a transition |
| `tests/Feature/LensOptionQuotationTest.php` | Remove assertions tied to the spec shell's `lens_options_snapshot`; keep lens-option-catalog assertions |

### Explicitly out of scope (do not touch)

- `ValidateOpticalQuotation` and its current-prescription-for-corrective-sale
  rule.
- `eyewear_key` columns/migration, `CommercialItemKind`, `LensOption`/
  `LensPackage` catalog resources, `FrameReservation` module.
- The Patient Eyewear (`/api/v1/eyewear`) API and any spec describing it.
- Any file not listed above. If implementation turns up an unexpected
  reference, stop and flag it rather than deleting/editing it on assumption.

## Data Model

`job_order_eyewear_specifications` table is dropped entirely (columns:
`prescription_id`, `frame_job_order_item_id`, `lens_package_job_order_item_id`,
`frame_source`, `lens_design_snapshot`, `lens_material_snapshot`,
`refractive_index_snapshot`, `lens_options_snapshot`, PD/fitting/segment-height
measurement columns, `approved_by`/`approved_at`, `verified_by`/`verified_at`,
`verification_notes`). No other table has a foreign key into it (confirmed —
only its own create-migration references the table name). `JobOrder` loses
its `eyewearSpecification` relation; nothing else in the schema changes.

## Tech Stack

No change. PHP 8.5, Laravel 13, Filament 5, Pest 4 — existing project stack.

## Commands

```bash
vendor/bin/sail artisan migrate:fresh --seed   # after migration deletion, verify schema builds clean
vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrderResourceTest.php
vendor/bin/sail artisan test --compact tests/Feature/JobOrders/
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/
vendor/bin/sail artisan test --compact tests/Feature/Quotations/
vendor/bin/sail artisan test --compact tests/Feature/EndToEnd/
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/OpticalCommercePrivacyTest.php
vendor/bin/sail artisan test --compact tests/Feature/LensOptionQuotationTest.php
vendor/bin/sail bin pint --dirty --format agent
grep -rn "EyewearSpecification\|FrameSource\|eyewear_specification" app database tests --include="*.php"   # must return nothing
```

Run the smallest affected test set after each task; run the full list above
at the final verification checkpoint.

## Code Style

Mostly deletion. Where `UpdateJobOrderStatus`'s transition logic is edited,
match its existing match-expression style — e.g. the surviving structure
after removing the two spec-gate blocks:

```php
public function handle(JobOrder $jobOrder, string $statusName): JobOrder
{
    $currentStatus = $jobOrder->status->value;
    $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

    if (! in_array($statusName, $allowed, true)) {
        throw ValidationException::withMessages([
            'status' => ["Cannot transition job order from '{$currentStatus}' to '{$statusName}'."],
        ]);
    }

    $newStatus = JobOrderStatus::from($statusName);

    // Supplier invoice is required only for external prepared work
    if (
        in_array($newStatus, [JobOrderStatus::ReadyForDispensing, JobOrderStatus::Dispensed], true)
        && blank($jobOrder->supplier_invoice_number)
        && $jobOrder->uses_external_supplier
    ) {
        throw ValidationException::withMessages([
            'supplier_invoice_number' => ['Enter the supplier invoice number before marking this job order ready.'],
        ]);
    }

    return DB::transaction(function () use ($jobOrder, $newStatus): JobOrder {
        // unchanged
    });
}
```

## Testing Strategy

Pest feature tests, following existing project convention. No new test
*types* are introduced — this phase only deletes and trims coverage.

- **Deleted test files** (Decision 3): verify via `git status`/`git diff`
  that only the 7 listed files disappear, nothing else.
- **Updated test files**: after edits, each must pass standalone and contain
  no remaining reference to the removed classes/enum.
- **Regression check**: `OpticalOrderResourceTest`, `JobOrders/`,
  `OpticalOrders/`, `Quotations/`, and `EndToEnd/` suites must pass with the
  same or fewer tests than before (fewer, since 7 files are gone), and no new
  failures beyond the pre-existing, already-documented unrelated ones
  (`SaveEyewearSpecificationTest` — moot, it's deleted;
  `OpticalCommerceWorkflowTest::external_fulfillment_requires_supplier_reference_before_ready`
  and `ClinicWorkflowTest::scheduled_patient_journey` were already failing on
  `main` before this work for unrelated reasons — confirm they still fail for
  the *same* reason, not a new one, or fix incidentally if trivial).
- **Grep-clean**: the final grep command in Commands must return zero
  results.

## Boundaries

### Always

- Re-check each "now-dead import" claim in the Scope table against the
  actual file before removing it — don't assume based on this spec's survey.
- Run Pint and the relevant test file(s) after every task, not just at the
  end.
- Keep `ValidateOpticalQuotation`'s prescription-for-corrective rule intact.
- Keep `eyewear_key`, the Patient Eyewear API, and any file not in the Scope
  table untouched.

### Ask first

- Anything found during implementation that references
  `JobOrderEyewearSpecification`/`FrameSource`/the two removed actions but
  isn't in the Scope table above.
- Any change to `UpdateJobOrderStatus`'s supplier-invoice-number gate (out of
  scope — unrelated to the eyewear spec).

### Never

- Touch `docs/specs/single-optical-orders-workflow-spec.md`,
  `docs/specs/simplified-optical-orders-and-billing-spec.md`, or any other
  spec describing the Patient Eyewear API or `eyewear_key`.
- Delete a test file not on the explicit list without asking first.
- Leave a partially-removed reference (e.g. deleting the model but leaving a
  type-hint or `use` statement pointing at it) — every deletion must be
  grep-verified clean before moving to the next task.

## Success Criteria

- [ ] `job_order_eyewear_specifications` table does not exist after a fresh
      migrate.
- [ ] `JobOrder` model has no `eyewearSpecification` relation.
- [ ] The optical order edit page has no "Eyewear Specification" section and
      no "Approve Specification"/"Verify Eyewear" header actions.
- [ ] `Start` and `Mark Ready` require no eyewear-spec check — a corrective
      order transitions exactly like a non-corrective one.
- [ ] Confirming a quotation with a lens package no longer creates any
      specification shell, but still enforces the current-prescription rule.
- [ ] `SaveEyewearSpecification`, `ApproveEyewearSpecification`,
      `VerifyEyewear`, and `FrameSource` no longer exist in the codebase.
- [ ] The 7 listed test files are deleted; the 3 referencing files are
      updated and pass.
- [ ] Full regression command list in Commands passes (excluding the two
      pre-existing, pre-documented unrelated failures, confirmed unchanged).
- [ ] `vendor/bin/sail bin pint --dirty --format agent` reports clean.
- [ ] The grep-clean check in Commands returns zero results.

## Approval Record

Decisions 1–3 above were approved on 2026-08-12 via direct confirmation.
Decisions 4–6 are scope disambiguations derived from codebase research, not
separately negotiated — flagged here for the user to correct if wrong.

**Next step:** this Specify phase is complete. Plan (`tasks/plan.md`) and
Tasks (`tasks/todo.md`) have not been written yet — proceeding to Phase 2
requires the user's go-ahead per the gated workflow.
