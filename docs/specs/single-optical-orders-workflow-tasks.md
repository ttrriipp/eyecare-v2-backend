# Tasks: Single Optical Orders Workflow

## Status

Replacement Phase 3 (**Tasks**) draft for project-owner review on 2026-08-03.
The revised Phase 1 specification and replacement Phase 2 plan are approved.
No application-code changes or test deletions are authorized until this task
breakdown is approved.

## Working Rules

- Execute tasks in dependency order and stop at every checkpoint.
- Search installed-version Laravel and Filament documentation before the first
  code change in each relevant area.
- Add or update the named Pest coverage before changing behavior.
- Create new Laravel and Pest files with Sail-prefixed Artisan `make:*`
  commands and `--no-interaction`.
- Keep each task within approximately five files.
- Run `vendor/bin/sail bin pint --dirty --format agent` after every PHP task.
- Delete a test only when this approved task identifies its entire subject as
  removed and replacement/absence coverage already passes.
- Add no migration, table, column, dependency, API alias, compatibility
  adapter, or persisted status.
- Never reset a developer database automatically.
- Preserve unrelated changes in the existing dirty worktree.

## Phase A: Protect the Supported Core

### Task 1: Rewrite confirmation fixtures around direct Draft sales

**Description:** Prove the supported prepared, immediate, reservation, and
billing paths without using Presented fixtures.

**Acceptance criteria:**

- [ ] Prepared and immediate confirmation accept Draft directly and remain
  idempotent.
- [ ] Reservation conversion, inventory commitment, Billing Record creation,
  optional payment, and cancellation retain current behavior.
- [ ] The focused tests contain no Presented, Declined, or Expired fixture.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php tests/Feature/OpticalOrders/CompleteImmediateOpticalOrderTest.php tests/Feature/OpticalOrders/FrameReservationJobOrderLinkTest.php tests/Feature/BillingRecords/BillingRecordLedgerTest.php
```

**Dependencies:** None.

**Files likely touched:**

- `tests/Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php`
- `tests/Feature/OpticalOrders/CompleteImmediateOpticalOrderTest.php`
- `tests/Feature/OpticalOrders/FrameReservationJobOrderLinkTest.php`
- `tests/Feature/BillingRecords/BillingRecordLedgerTest.php`

**Estimated scope:** Medium (4 files).

### Task 2: Rewrite cross-domain Optical Order characterization

**Description:** Retain only supported relationship, Job Order, and end-to-end
coverage before obsolete workflow tests are removed.

**Acceptance criteria:**

- [ ] Job Order creation requires an accepted internal record and never a
  Presented state.
- [ ] Direct Draft confirmation remains covered end to end.
- [ ] Relationship and characterization tests assert Draft/accepted behavior
  without estimate decisions.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/JobOrders/CreateJobOrderTest.php tests/Feature/EndToEnd/ClinicWorkflowTest.php tests/Feature/Quotations/QuotationDirectRelationshipsTest.php tests/Feature/OpticalOrders/LegacyOpticalOrderCharacterizationTest.php
```

**Dependencies:** Task 1.

**Files likely touched:**

- `tests/Feature/JobOrders/CreateJobOrderTest.php`
- `tests/Feature/EndToEnd/ClinicWorkflowTest.php`
- `tests/Feature/Quotations/QuotationDirectRelationshipsTest.php`
- `tests/Feature/OpticalOrders/LegacyOpticalOrderCharacterizationTest.php`

**Estimated scope:** Medium (4 files).

### Task 3: Add the derived Optical Order stage

**Description:** Centralize clinic-facing stages over Draft plus Job Order
status before simplifying Filament consumers.

**Acceptance criteria:**

- [ ] Draft and every Job Order status resolve to the approved stage and label.
- [ ] Accepted without a Job Order is treated as an invariant violation.
- [ ] The resolver is non-persisted and introduces no schema change.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/OpticalOrderStageTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 1-2.

**Files likely touched:**

- `app/Enums/OpticalOrderStage.php`
- `app/Models/Quotation.php`
- `tests/Feature/OpticalOrders/OpticalOrderStageTest.php`

**Estimated scope:** Medium (3 files).

### Checkpoint A: Supported foundation

```text
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders tests/Feature/JobOrders/CreateJobOrderTest.php tests/Feature/BillingRecords/BillingRecordLedgerTest.php tests/Feature/EndToEnd/ClinicWorkflowTest.php
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] Draft confirmation and every downstream invariant are covered.
- [ ] The stage contract is stable.
- [ ] No migration exists.

## Phase B: Simplify the Staff Workflow

### Task 4: Replace Optical Order tabs, stages, and filters

**Description:** Use the derived Order stage and remove estimate-decision
terminology from the index.

**Acceptance criteria:**

- [ ] Tabs are All, Drafts, Confirmed, Processing, Ready for Pickup, Completed,
  and Cancelled.
- [ ] Awaiting Decision, In Production, and Quotation Status are absent.
- [ ] Stage badges, colors, and filters use the centralized stage contract.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/QuotationCreationTest.php tests/Feature/OpticalOrders/OpticalOrderStageTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 3.

**Files likely touched:**

- `app/Filament/Resources/OpticalOrders/Pages/ListOpticalOrders.php`
- `app/Filament/Resources/OpticalOrders/Tables/OpticalOrdersTable.php`
- `tests/Feature/Filament/QuotationCreationTest.php`
- `tests/Feature/OpticalOrders/OpticalOrderStageTest.php`

**Estimated scope:** Medium (4 files).

### Task 5: Simplify Draft form, update, and discard

**Description:** Remove quotation-only form behavior and limit commercial
editing to Draft.

**Acceptance criteria:**

- [ ] Valid Until is absent and notes explain patient visibility after
  confirmation.
- [ ] Update accepts Draft only; accepted records remain immutable.
- [ ] Discard Draft is confirmation-protected, soft-deletes, and creates no
  patient, billing, inventory, or fulfillment side effect.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/QuotationCreationTest.php tests/Feature/Quotations/UpdateQuotationDraftTest.php tests/Feature/Eyewear/EyewearListQueryTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 4.

**Files likely touched:**

- `app/Filament/Resources/OpticalOrders/Schemas/OpticalOrderForm.php`
- `app/Filament/Resources/OpticalOrders/Pages/EditOpticalOrder.php`
- `app/Actions/Quotations/UpdateQuotationDraft.php`
- `tests/Feature/Quotations/UpdateQuotationDraftTest.php`
- `tests/Feature/Filament/QuotationCreationTest.php`

**Estimated scope:** Medium (5 files).

### Task 6: Reduce Draft detail actions

**Description:** Present Edit, Confirm Sale, and Discard Draft as the only Draft
actions while preserving confirmation inputs.

**Acceptance criteria:**

- [ ] Share Estimate and Decline Estimate actions are absent.
- [ ] Edit, Confirm Sale, and Discard Draft have Order-oriented labels and
  correct visibility.
- [ ] Confirm Sale retains fulfillment, supplier, due-date, deposit, and first-
  payment-finalization behavior.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/QuotationCreationTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 5.

**Files likely touched:**

- `app/Filament/Resources/OpticalOrders/Pages/ViewOpticalOrder.php`
- `tests/Feature/Filament/QuotationCreationTest.php`

**Estimated scope:** Small (2 files).

### Task 7: Narrow confirmation actions to Draft and accepted

**Description:** Remove Presented/Declined/Expired transition branches from both
confirmation modes after their callers use the supported workflow.

**Acceptance criteria:**

- [ ] Draft confirms once and accepted returns the existing result
  idempotently.
- [ ] No confirmation action references an unsupported commercial state.
- [ ] Prepared and immediate focused suites retain all approved transaction
  behavior.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php tests/Feature/OpticalOrders/CompleteImmediateOpticalOrderTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 1 and 6.

**Files likely touched:**

- `app/Actions/OpticalOrders/AcceptAndStartOpticalOrder.php`
- `app/Actions/OpticalOrders/CompleteImmediateOpticalOrder.php`
- `tests/Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php`
- `tests/Feature/OpticalOrders/CompleteImmediateOpticalOrderTest.php`

**Estimated scope:** Medium (4 files).

### Task 8: Replace dashboard quotation copy

**Description:** Report actionable Draft Optical Orders rather than pending
Quotations.

**Acceptance criteria:**

- [ ] The dashboard shows the non-deleted Draft count as Draft Optical Orders.
- [ ] Quotations Pending and Awaiting Decision copy are absent.
- [ ] All unrelated dashboard statistics remain unchanged.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/DashboardTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 4.

**Files likely touched:**

- `app/Filament/Widgets/StatsOverviewWidget.php`
- `tests/Feature/Filament/DashboardTest.php`

**Estimated scope:** Small (2 files).

### Checkpoint B: Staff workflow

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/QuotationCreationTest.php tests/Feature/Filament/DashboardTest.php tests/Feature/OpticalOrders tests/Feature/Quotations/UpdateQuotationDraftTest.php
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] Staff completes the full workflow through Optical Orders only.
- [ ] No supported staff action presents or decides an estimate.
- [ ] Downstream behavior remains green.

## Phase C: Delete the Quotation Staff Surface

### Task 9: Remove presentation and decision domain code

**Description:** Delete actions, policy, and tests whose entire subject is the
removed presentation/decision lifecycle.

**Acceptance criteria:**

- [ ] No supported application call references presentation or quotation
  decisions.
- [ ] `PresentQuotation`, `RecordQuotationDecision`, and the unused policy are
  deleted after a zero-reference audit.
- [ ] The obsolete lifecycle test is deleted while retained Draft/confirmation
  suites stay green.

**Verification:**

```text
rg -n "PresentQuotation|RecordQuotationDecision|QuotationPolicy" app routes tests
vendor/bin/sail artisan test --compact tests/Feature/Quotations tests/Feature/OpticalOrders
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 5-7.

**Files likely removed:**

- `app/Actions/Quotations/PresentQuotation.php`
- `app/Actions/Quotations/RecordQuotationDecision.php`
- `app/Policies/QuotationPolicy.php`
- `tests/Feature/Quotations/QuotationLifecycleTest.php`

**Estimated scope:** Medium (4 files).

### Task 10: Remove the hidden Quotation resource core

**Description:** Delete the unused Filament resource and its primary pages and
schemas after Optical Orders owns every entry point.

**Acceptance criteria:**

- [ ] The Quotation resource, list/edit pages, form, and table are absent.
- [ ] No Filament navigation, URL, or cross-link references the removed
  resource.
- [ ] Optical Order create, edit, view, and list pages remain accessible.

**Verification:**

```text
rg -n "Resources\\Quotations|QuotationResource" app tests
vendor/bin/sail artisan test --compact tests/Feature/Filament/QuotationCreationTest.php tests/Feature/Filament/FilamentAccessTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 9.

**Files likely removed:**

- `app/Filament/Resources/Quotations/QuotationResource.php`
- `app/Filament/Resources/Quotations/Pages/EditQuotation.php`
- `app/Filament/Resources/Quotations/Pages/ListQuotations.php`
- `app/Filament/Resources/Quotations/Schemas/QuotationForm.php`
- `app/Filament/Resources/Quotations/Tables/QuotationsTable.php`

**Estimated scope:** Medium (5 files).

### Task 11: Remove remaining Quotation UI artifacts and tests

**Description:** Delete the unused creation schema and resource test, then add
explicit staff-surface absence coverage to a retained test.

**Acceptance criteria:**

- [ ] The remaining Quotation schema and resource test are deleted because
  their entire subject is gone.
- [ ] A retained Filament test proves no Quotation resource/navigation exists.
- [ ] `app/Filament/Resources/Quotations` no longer exists.

**Verification:**

```text
rg --files app/Filament/Resources/Quotations
vendor/bin/sail artisan test --compact tests/Feature/Filament/FilamentAccessTest.php tests/Feature/Filament/QuotationCreationTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 10.

**Files likely touched or removed:**

- `app/Filament/Resources/Quotations/Schemas/QuotationCreationForm.php`
- `tests/Feature/Filament/QuotationResourceTest.php`
- `tests/Feature/Filament/FilamentAccessTest.php`

**Estimated scope:** Medium (3 files).

### Checkpoint C: Staff-surface removal

```text
rg -n "PresentQuotation|RecordQuotationDecision|Resources\\Quotations|QuotationResource" app routes tests
vendor/bin/sail artisan test --compact tests/Feature/Filament tests/Feature/OpticalOrders tests/Feature/Quotations
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] No Quotation staff surface or presentation domain remains.
- [ ] Internal Draft creation/update remains covered.
- [ ] Optical Orders remains the sole staff workflow.

## Phase D: Remove Quotation API and Estimate-Only Eyewear

### Task 12: Remove the Quotation patient API

**Description:** Assert route absence, then remove the undeployed controller,
resource, routes, and contract test.

**Acceptance criteria:**

- [ ] Both Quotation routes are absent and return 404 through the application.
- [ ] The controller, API resource, import, route definitions, and dedicated API
  test are deleted.
- [ ] Route contract counts and remaining active-link routes are correct.

**Verification:**

```text
vendor/bin/sail artisan route:list --except-vendor
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Checkpoint C.

**Files likely touched or removed:**

- `routes/api.php`
- `app/Http/Controllers/Api/QuotationController.php`
- `app/Http/Resources/QuotationResource.php`
- `tests/Feature/Api/V1/QuotationTest.php`
- `tests/Feature/Api/V1/RouteContractTest.php`

**Estimated scope:** Medium (5 files).

### Task 13: Make Eyewear listing and lookup Job-Order-only

**Description:** Remove quotation-only candidate and lookup branches while
preserving stable keys, aliases, ownership, pagination, and filters.

**Acceptance criteria:**

- [ ] List and find queries start from patient-owned Job Orders only.
- [ ] Draft and discarded Draft keys return no result.
- [ ] Canonical keys, `jo_{id}` aliases, ownership, deterministic ordering, and
  current/history pagination remain correct.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Eyewear/EyewearListQueryTest.php tests/Feature/Eyewear/FindPatientEyewearTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 12.

**Files likely touched:**

- `app/Services/Eyewear/ListPatientEyewear.php`
- `app/Services/Eyewear/FindPatientEyewear.php`
- `tests/Feature/Eyewear/EyewearListQueryTest.php`
- `tests/Feature/Eyewear/FindPatientEyewearTest.php`

**Estimated scope:** Medium (4 files).

### Task 14: Remove estimate-only progress and aggregate building

**Description:** Make the aggregate builder accept confirmed Job Order context
only and delete estimate progress mappings.

**Acceptance criteria:**

- [ ] Eyewear progress contains only In Preparation, Ready for Pickup,
  Dispensed, and Cancelled.
- [ ] The builder has no quotation-only branch or estimate-state mapping.
- [ ] Job Order items, order total, activity, dispensing, and payment values
  remain correct.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Eyewear/EyewearAggregateTest.php tests/Feature/Eyewear/EyewearResourceTest.php tests/Feature/Api/EyewearApiTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 13.

**Files likely touched:**

- `app/Enums/EyewearProgress.php`
- `app/Services/Eyewear/BuildEyewearAggregate.php`
- `tests/Feature/Eyewear/EyewearAggregateTest.php`
- `tests/Feature/Eyewear/EyewearResourceTest.php`
- `tests/Feature/Api/EyewearApiTest.php`

**Estimated scope:** Medium (5 files).

### Task 15: Remove the Eyewear estimate section

**Description:** Delete quotation-derived detail state after preparation items
are proven as the supported confirmed-order item source.

**Acceptance criteria:**

- [ ] Eyewear aggregate and detail resources contain no `estimate` property or
  section.
- [ ] Preparation items, order total, fulfillment, dispensing, combined-payment
  scope, balance, and privacy behavior remain intact.
- [ ] API tests assert the revised confirmed-order-only response explicitly.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Eyewear/EyewearAggregateTest.php tests/Feature/Eyewear/EyewearResourceTest.php tests/Feature/Api/EyewearApiTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 14.

**Files likely touched:**

- `app/Services/Eyewear/EyewearAggregate.php`
- `app/Services/Eyewear/BuildEyewearAggregate.php`
- `app/Http/Resources/EyewearDetailResource.php`
- `tests/Feature/Eyewear/EyewearResourceTest.php`
- `tests/Feature/Api/EyewearApiTest.php`

**Estimated scope:** Medium (5 files).

### Checkpoint D: Patient contract

```text
vendor/bin/sail artisan route:list --except-vendor
vendor/bin/sail artisan test --compact tests/Feature/Eyewear tests/Feature/Api/EyewearApiTest.php tests/Feature/Api/V1/RouteContractTest.php
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] Quotation routes are absent.
- [ ] Eyewear is Job-Order-only and contains no estimate state.
- [ ] Confirmed orders remain trackable with correct privacy and ownership.

## Phase E: Prune Status Fixtures and Reconcile Documentation

### Task 16: Remove unsupported commercial status cases and fixtures

**Description:** Delete the now-unreferenced enum cases and factory states after
all consumers and obsolete tests are gone.

**Acceptance criteria:**

- [ ] `QuotationStatus` contains only Draft and Accepted.
- [ ] The factory creates only Draft by default and provides accepted as its
  only status state.
- [ ] The retained commercial-schema migration coverage no longer asserts
  presentation behavior or references an unsupported commercial state.
- [ ] Remaining update, relationship, stage, and schema tests contain no
  unsupported commercial state reference.

**Verification:**

```text
rg -n "QuotationStatus::Presented|QuotationStatus::Declined|QuotationStatus::Expired|->presented\(|->declined\(|->expired\(" app database tests
vendor/bin/sail artisan test --compact tests/Feature/Quotations tests/Feature/OpticalOrders/OpticalOrderStageTest.php
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Tasks 1-15.

**Files likely touched:**

- `app/Enums/QuotationStatus.php`
- `database/factories/QuotationFactory.php`
- `tests/Feature/Quotations/UpdateQuotationDraftTest.php`
- `tests/Feature/Quotations/QuotationDirectRelationshipsTest.php`
- `tests/Feature/OpticalOrders/OpticalOrderTransitionMigrationTest.php`

**Estimated scope:** Medium (5 files).

### Task 17: Reconcile backend and API contracts

**Description:** Update operational documentation and route inventories for the
single workflow and Job-Order-only patient contract.

**Acceptance criteria:**

- [ ] Backend context describes Draft/accepted internal storage and only the
  supported staff stages.
- [ ] API contract removes Quotation routes and estimate response sections and
  updates route counts/examples.
- [ ] Existing optical specs no longer prescribe presentation, decision, or
  estimate-only behavior.

**Verification:**

```text
rg -n "Share Estimate|Awaiting Decision|Quotations Pending|Quotation Status|estimate_available|estimate_declined|estimate_expired|GET.*/quotations" docs/BACKEND_CONTEXT.md docs/API_CONTRACT.md docs/specs/simplified-optical-orders-and-billing-spec.md docs/specs/optical-transaction-types-and-encounter-billing-spec.md docs/specs/optical-transaction-types-and-encounter-billing-plan.md
```

**Dependencies:** Task 16.

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`
- `docs/API_CONTRACT.md`
- `docs/specs/simplified-optical-orders-and-billing-spec.md`
- `docs/specs/optical-transaction-types-and-encounter-billing-spec.md`
- `docs/specs/optical-transaction-types-and-encounter-billing-plan.md`

**Estimated scope:** Medium (5 files).

### Task 18: Run full conformance and close the workflow

**Description:** Audit all remaining references, reconcile task documents, run
the full quality gate, and record shipped behavior without claiming table
replacement.

**Acceptance criteria:**

- [ ] No removed resource, route, action, status, estimate state, factory state,
  test subject, or active documentation instruction remains.
- [ ] The wider optical task breakdown and this spec-driven set agree on the
  single workflow and unchanged internal storage.
- [ ] Full Pest, Pint, route audit, and frontend build pass with no migration.

**Verification:**

```text
rg -n "PresentQuotation|RecordQuotationDecision|Resources\\Quotations|QuotationController|QuotationStatus::Presented|QuotationStatus::Declined|QuotationStatus::Expired|estimate_available|estimate_declined|estimate_expired|Share Estimate|Awaiting Decision|Quotations Pending" app routes database tests docs
vendor/bin/sail artisan route:list --except-vendor
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
```

**Dependencies:** Tasks 1-17.

**Files likely touched:**

- `docs/specs/optical-transaction-types-and-encounter-billing-tasks.md`
- `docs/specs/single-optical-orders-workflow-spec.md`
- `docs/specs/single-optical-orders-workflow-plan.md`
- `docs/specs/single-optical-orders-workflow-tasks.md`

**Estimated scope:** Medium (4 documentation files).

### Final Checkpoint

- [ ] Optical Orders is the only staff commercial workflow.
- [ ] Draft moves directly to Confirmed and is private before confirmation.
- [ ] Only Draft and accepted internal commercial statuses remain.
- [ ] Quotation staff and patient resources are deleted.
- [ ] Eyewear is Job-Order-only and exposes no estimate state.
- [ ] Billing, payment, fulfillment, inventory, reservation, and privacy
  behavior remains correct.
- [ ] No database migration or compatibility layer was introduced.
- [ ] Full tests, Pint, route audit, and frontend build pass.

## Phase 3 Approval Gate

Approval of this replacement task breakdown authorizes Phase 4 (**Implement**)
to execute Tasks 1-18 sequentially, including deletion of the specifically
listed obsolete implementation and test files after replacement/absence tests
pass. Any material schema, workflow, or API decision discovered during
implementation must update the approved specification first and return to the
appropriate approval gate.
