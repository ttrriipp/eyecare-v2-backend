# Tasks: Patient Eyewear Aggregate API

## Status

Draft Phase 3 task breakdown for project-owner review on 2026-07-29.

Authoritative inputs:

- `docs/specs/patient-eyewear-aggregate-api-spec.md`
- `docs/specs/patient-eyewear-aggregate-api-plan.md`

This task breakdown does not authorize implementation. Phase 4 begins only
after project-owner approval.

## Pre-Implementation Gate

- [ ] Preserve and separately commit or otherwise isolate the existing Health
      Record, Encounter, Quotation, and Job Order changes.
- [ ] Commit the approved Eyewear specification, plan, and tasks as their own
      documentation save point.
- [ ] Confirm the testing database is healthy and no parallel database-refresh
      test process is running.
- [ ] Inspect the staged and unstaged diff before touching files shared with
      `CreateJobOrder`.

Do not use broad staging patterns. Existing user work must not be mixed into
Eyewear commits.

## Phase A: Stable Aggregate Identity

### Task 01: Add persistent Eyewear keys

- [ ] Generate an additive migration through Sail.
- [ ] Add unique `eyewear_key` columns to Quotations and Job Orders.
- [ ] Backfill Quotations first, copy linked keys to Job Orders, and generate
      keys for Job-order-only records.
- [ ] Make both columns required after validation.
- [ ] Generate `eyw_{ULID}` keys for newly created standalone source records.

Acceptance:

- Every non-deleted and soft-deleted Quotation and Job Order has one valid key.
- Keys are unique within each source table.
- A Job Order linked during backfill has the same key as its Quotation.
- Migration rollback removes only the added keys and indexes.

Verify:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Eyewear/EyewearKeyTest.php
vendor/bin/sail bin pint --dirty --format agent
```

Likely files:

- `database/migrations/*_add_eyewear_keys_to_quotations_and_job_orders.php`
- `app/Models/Quotation.php`
- `app/Models/JobOrder.php`
- `tests/Feature/Eyewear/EyewearKeyTest.php`

Dependencies: None  
Estimated scope: Medium

### Task 02: Preserve keys during Job Order creation

- [ ] Make `CreateJobOrder` copy the accepted Quotation key.
- [ ] Keep Job-order-only creation capable of generating its own key.
- [ ] Reject another Job Order using the same aggregate key.
- [ ] Preserve the existing transaction, stock commitment, and role checks.

Acceptance:

- Estimate-to-Job-Order progression never changes the canonical key.
- Concurrent or repeated creation cannot produce two Job Orders for one key.
- Existing inventory rollback behavior remains intact.

Verify:

```bash
vendor/bin/sail artisan test --compact tests/Feature/JobOrders/CreateJobOrderTest.php
vendor/bin/sail artisan test --compact tests/Feature/JobOrders/JobOrderInventoryAtomicTest.php
vendor/bin/sail bin pint --dirty --format agent
```

Likely files:

- `app/Actions/JobOrders/CreateJobOrder.php`
- `tests/Feature/JobOrders/CreateJobOrderTest.php`
- `tests/Feature/JobOrders/JobOrderInventoryAtomicTest.php`

Dependencies: Task 01  
Estimated scope: Small

## Checkpoint A: Identity

- [ ] Key migration and rollback pass.
- [ ] New and backfilled lifecycles retain stable keys.
- [ ] Existing Job Order and inventory tests pass.
- [ ] Save an atomic identity-foundation commit.

## Phase B: Shared Aggregate Mapping

### Task 03: Establish aggregate types and estimate mapping

- [ ] Add aggregate progress and payment-status enums.
- [ ] Add a typed Eyewear read model under the existing Services namespace.
- [ ] Add one aggregate builder used by both list and detail.
- [ ] Implement estimate-only progress, money, items, created date, and
      conditional estimate section.
- [ ] Exclude Draft estimates.

Acceptance:

- Presented and accepted estimates map to `estimate_available`.
- Declined and expired estimates map to their History progress values.
- Draft estimates cannot produce an aggregate.
- Estimate money is serialized as exact two-decimal strings.

Verify:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Eyewear/EyewearAggregateTest.php --filter=estimate
vendor/bin/sail bin pint --dirty --format agent
```

Likely files:

- `app/Enums/EyewearProgress.php`
- `app/Enums/EyewearPaymentStatus.php`
- `app/Services/Eyewear/EyewearAggregate.php`
- `app/Services/Eyewear/BuildEyewearAggregate.php`
- `tests/Feature/Eyewear/EyewearAggregateTest.php`

Dependencies: Checkpoint A  
Estimated scope: Medium

### Task 04: Add preparation and dispensing mapping

- [ ] Map every Job Order status with Job Order precedence.
- [ ] Use Job Order items for description and detail when present.
- [ ] Include preparation for every visible Job Order.
- [ ] Include dispensing only for ready or dispensed Job Orders.
- [ ] Use the exact Quotation Revision linked by the Job Order.

Acceptance:

- Queued and in-progress map to `in_preparation`.
- Ready maps to `ready_for_pickup`.
- Dispensed and cancelled map to their History values.
- A linked Job Order overrides inconsistent Quotation progress.
- `expected_completion_at` is absent.

Verify:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Eyewear/EyewearAggregateTest.php --filter=job_order
vendor/bin/sail bin pint --dirty --format agent
```

Likely files:

- `app/Models/QuotationRevision.php`
- `app/Services/Eyewear/BuildEyewearAggregate.php`
- `tests/Feature/Eyewear/EyewearAggregateTest.php`

Dependencies: Task 03  
Estimated scope: Small

### Task 05: Add payment, description, and timestamp mapping

- [ ] Implement active Billing Record and posted-Payment rules.
- [ ] Implement Billing, Job Order, then Estimate total precedence.
- [ ] Implement null behavior for missing and voided Billing Records.
- [ ] Implement deterministic description and fallback.
- [ ] Implement consultation, created, and patient-visible activity timestamps.

Acceptance:

- Unpaid and partial records map to `balance_due`; paid maps to `paid`.
- Voided Billing Records do not affect totals or produce payment sections.
- A dispensed transaction with a balance remains History.
- Payment activity affects ordering data but not progress.
- Internal notes, recorder IDs, reversals, and void data never enter the read
  model.

Verify:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Eyewear/EyewearAggregateTest.php
vendor/bin/sail bin pint --dirty --format agent
```

Likely files:

- `app/Services/Eyewear/BuildEyewearAggregate.php`
- `app/Services/Eyewear/EyewearAggregate.php`
- `tests/Feature/Eyewear/EyewearAggregateTest.php`

Dependencies: Task 04  
Estimated scope: Medium

## Checkpoint B: Aggregate Semantics

- [ ] Estimate-only, Job-order-only, complete linked, paid, balance-due,
      voided, cancelled, and missing-consultation fixtures pass.
- [ ] All money values are strings with two decimal places.
- [ ] Partial sections are represented in the read model without placeholders.
- [ ] Save an atomic aggregate-mapping commit.

## Phase C: Query, Filtering, and Pagination

### Task 06: Build the de-duplicated candidate query

- [ ] Add the Job Order query branch.
- [ ] Add the visible estimate-only branch.
- [ ] Exclude Quotations that already have a visible Job Order.
- [ ] Combine branches with `UNION ALL`.
- [ ] Hydrate only the requested candidates with bounded eager loads.

Acceptance:

- A complete linked lifecycle appears exactly once.
- Job-order-only and estimate-only lifecycles both appear.
- Draft and soft-deleted source records are excluded according to the spec.
- Query count remains bounded as page size grows.

Verify:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Eyewear/EyewearListQueryTest.php --filter=deduplicates
vendor/bin/sail bin pint --dirty --format agent
```

Likely files:

- `app/Services/Eyewear/ListPatientEyewear.php`
- `app/Services/Eyewear/BuildEyewearAggregate.php`
- `tests/Feature/Eyewear/EyewearListQueryTest.php`

Dependencies: Checkpoint B  
Estimated scope: Medium

### Task 07: Add filters, ordering, and pagination

- [ ] Apply the exact Current and History progress groups.
- [ ] Compute patient-visible `activity_at`.
- [ ] Order by `activity_at DESC, eyewear_key ASC`.
- [ ] Add page-number pagination with default 15 and maximum 50.
- [ ] Inspect the generated SQL and MySQL query plan.

Acceptance:

- Payment state never changes filter membership.
- Equal activity timestamps have deterministic key ordering.
- Pagination totals, boundaries, and empty pages are correct.
- No full patient transaction collection is loaded before pagination.

Verify:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Eyewear/EyewearListQueryTest.php
vendor/bin/sail bin pint --dirty --format agent
```

Likely files:

- `app/Services/Eyewear/ListPatientEyewear.php`
- `tests/Feature/Eyewear/EyewearListQueryTest.php`
- an additional migration only if `EXPLAIN` proves a composite index necessary

Dependencies: Task 06  
Estimated scope: Medium

### Task 08: Implement patient-scoped detail lookup

- [ ] Resolve canonical `eyw_...` keys inside Patient scope.
- [ ] Resolve `jo_{job_order_id}` aliases inside Patient scope.
- [ ] Return the same canonical aggregate for either lookup.
- [ ] Return identical `404` behavior for absent and cross-patient lookups.

Acceptance:

- Existing Job Order IDs resolve to the canonical Eyewear detail.
- The alias never becomes the response key.
- Another patient's key or alias cannot be distinguished from a missing key.

Verify:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Eyewear/FindPatientEyewearTest.php
vendor/bin/sail bin pint --dirty --format agent
```

Likely files:

- `app/Services/Eyewear/FindPatientEyewear.php`
- `tests/Feature/Eyewear/FindPatientEyewearTest.php`

Dependencies: Checkpoint B, Task 01  
Estimated scope: Small

## Checkpoint C: Retrieval

- [ ] Current and History queries match the approved matrix.
- [ ] Pagination and ordering are deterministic.
- [ ] Canonical and alias detail lookup are patient-isolated.
- [ ] Save an atomic retrieval commit.

## Phase D: HTTP Contract

### Task 09: Add patient-safe API Resources

- [ ] Add the summary Resource.
- [ ] Add the detail Resource.
- [ ] Reuse summary fields in detail.
- [ ] Conditionally omit unavailable sections.
- [ ] Add recursive assertions for prohibited fields.

Acceptance:

- List and detail use identical summary types and values.
- Money, timestamps, enums, arrays, and nullability match the spec.
- Empty placeholder sections and internal fields are absent.

Verify:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/EyewearResourceTest.php
vendor/bin/sail bin pint --dirty --format agent
```

Likely files:

- `app/Http/Resources/EyewearSummaryResource.php`
- `app/Http/Resources/EyewearDetailResource.php`
- `tests/Feature/Api/EyewearResourceTest.php`

Dependencies: Checkpoint C  
Estimated scope: Small

### Task 10: Expose the two read-only endpoints

- [ ] Add list-query validation and defaults.
- [ ] Add a thin controller using the approved services and Resources.
- [ ] Register only the two authenticated GET routes.
- [ ] Cover authentication, ownership, validation, rate-limit middleware,
      pagination envelope, and exact JSON.

Acceptance:

- `GET /api/v1/eyewear` matches the approved list contract.
- `GET /api/v1/eyewear/{key}` matches all complete and partial detail shapes.
- Invalid query values return `422`.
- Unauthenticated requests return `401`.
- No Eyewear mutation route exists.

Verify:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/EyewearApiTest.php
vendor/bin/sail artisan route:list --path=api/v1/eyewear --except-vendor
vendor/bin/sail bin pint --dirty --format agent
```

Likely files:

- `app/Http/Requests/Api/ListEyewearRequest.php`
- `app/Http/Controllers/Api/EyewearController.php`
- `routes/api.php`
- `tests/Feature/Api/EyewearApiTest.php`

Dependencies: Task 09  
Estimated scope: Medium

### Task 11: Preserve migration endpoints

- [ ] Run the existing Quotation, Job Order, and Billing Record API tests.
- [ ] Confirm all six legacy GET routes remain registered.
- [ ] Add a route-contract assertion covering 35 total routes and GET-only
      Eyewear behavior.
- [ ] Correct only regressions caused by Eyewear work.

Acceptance:

- Existing Android builds can still call the six legacy read endpoints.
- Eyewear introduces no legacy response change.
- The route inventory contains 35 documented routes.

Verify:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api
vendor/bin/sail artisan route:list --path=api/v1 --except-vendor
```

Likely files:

- existing API route-contract test
- `tests/Feature/Api/EyewearApiTest.php`
- affected legacy API test only if a regression is found

Dependencies: Task 10  
Estimated scope: Small

## Checkpoint D: API

- [ ] Exact list and detail JSON pass.
- [ ] Patient isolation and forbidden-field checks pass.
- [ ] Two Eyewear GET routes and six legacy GET routes coexist.
- [ ] Focused API suite is green.
- [ ] Save an atomic API commit.

## Phase E: Contract Reconciliation

### Task 12: Update authoritative backend documentation

- [ ] Add Eyewear architecture and migration status to `BACKEND_CONTEXT.md`.
- [ ] Add exact request, pagination, response, enum, nullability, error, alias,
      and void semantics to `API_CONTRACT.md`.
- [ ] Add complete list, estimate-only, Job-order-only, complete linked, and
      voided-Billing examples.
- [ ] Add both routes to the endpoint appendix and change 33 to 35.
- [ ] Record the implementing backend commit hash.

Acceptance:

- Documentation agrees exactly with tested runtime output.
- Android can write its specification without consulting internal models.
- Existing endpoints are clearly marked temporary rather than removed.

Verify:

```bash
vendor/bin/sail artisan route:list --path=api/v1 --except-vendor
vendor/bin/sail artisan test --compact tests/Feature/Api/EyewearApiTest.php
git diff --check
```

Likely files:

- `docs/BACKEND_CONTEXT.md`
- `docs/API_CONTRACT.md`
- `docs/specs/patient-eyewear-aggregate-api-spec.md`
- `docs/specs/patient-eyewear-aggregate-api-plan.md`

Dependencies: Checkpoint D and an implementation commit hash  
Estimated scope: Medium

### Task 13: Final verification and handoff

- [ ] Run formatting after the final PHP change.
- [ ] Run Eyewear, Job Order, Billing, legacy API, and route-contract tests
      sequentially.
- [ ] Inspect the final staged diff for secrets and unintended internal fields.
- [ ] Commit documentation reconciliation separately.
- [ ] Announce **Contract finalized for Android specification** with the
      backend commit hash only after every gate passes.

Acceptance:

- All approved success criteria have direct passing evidence.
- No required work remains hidden behind unchecked tasks.
- The final response identifies the canonical contract files and commit.

Verify:

```bash
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail artisan test --compact tests/Feature/Eyewear
vendor/bin/sail artisan test --compact tests/Feature/Api
vendor/bin/sail artisan test --compact tests/Feature/JobOrders
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords
vendor/bin/sail artisan route:list --path=api/v1 --except-vendor
git diff --cached
```

Likely files:

- no new implementation files unless verification exposes a scoped defect
- authoritative documentation for the final commit reference

Dependencies: Task 12  
Estimated scope: Small

## Checkpoint E: Complete

- [ ] Tasks 01–13 are checked with evidence.
- [ ] All required tests pass sequentially.
- [ ] Route count and endpoint appendix agree.
- [ ] Legacy migration endpoints remain available.
- [ ] Contract documentation contains complete examples.
- [ ] Implementation and documentation commits are recorded.
- [ ] Android handoff is explicitly finalized.

## Execution Rules

- Execute tasks in dependency order.
- Stop and update the approved specification before changing any contract
  decision.
- Complete one task, test it, and save it before starting the next.
- Do not run database-refreshing Pest processes in parallel.
- Do not update documentation examples from intended output; use verified
  runtime output.
- Do not remove legacy endpoints in this phase.
- Do not implement Android code in this repository.

