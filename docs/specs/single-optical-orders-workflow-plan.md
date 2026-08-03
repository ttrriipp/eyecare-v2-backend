# Implementation Plan: Single Optical Orders Workflow

## Status

Approved by the project owner on 2026-08-03. The revised Phase 1 (**Specify**)
and replacement Phase 2 (**Plan**) are complete. Replacement Phase 3 (**Tasks**)
remains subject to project-owner review before application-code changes.

It replaces the obsolete compatibility plan that assumed deployed Quotation
consumers and historical records.

## Overview

Retain `Quotation` only as the internal editable Draft record, then remove every
undeployed quotation workflow and API surface around it. New work moves directly
from Draft to accepted as part of Confirm Sale; patients see only confirmed Job
Orders through Eyewear.

The implementation is a pre-launch code cleanup, not a migration. It removes
dead statuses, resources, actions, routes, serializers, estimate-only Eyewear
branches, factories, seed states, and obsolete tests while preserving Draft
creation/update and all approved confirmation, billing, fulfillment, inventory,
reservation, and payment behavior.

## Current State and Removal Surface

### Still required

- `Quotation` and `QuotationItem` models and tables;
- `QuotationStatus::Draft` and `QuotationStatus::Accepted`;
- `CreateQuotation` and `UpdateQuotationDraft` internal actions;
- `OpticalOrderResource` backed by `Quotation`;
- direct confirmation actions that create Job Orders and Billing Records;
- `job_orders.quotation_id`, stable eyewear key copying, quotation totals, and
  accepted commercial snapshots;
- tests for Draft creation/update and confirmed workflows.

### Removed behavior

- Presented, Declined, and Expired enum cases and transitions;
- `PresentQuotation` and `RecordQuotationDecision`;
- hidden Filament Quotation resource, pages, schemas, and tables;
- Quotation-only policy methods;
- Share Estimate, Decline Estimate, Awaiting Decision, Valid Until, Quotation
  Status, and Quotations Pending UI;
- Quotation controller/resource and two mobile routes;
- quotation-only Eyewear listing/finding/building;
- estimate progress cases and the detail `estimate` section;
- factories, seed data, tests, and documentation that exist only for removed
  behavior.

## Architecture Decisions

### 1. Preserve the internal aggregate boundary

Do not create a replacement Optical Order table or model. `Quotation` remains
the commercial draft and accepted source; Job Order remains the confirmed
fulfillment snapshot. This avoids changing foreign keys, stable keys, inventory
integration, and billing confirmation.

Internal names such as `quotation_id` and `quotation_number` may remain where
renaming them offers no clinic or API value. Staff sees **Order #** and Optical
Order terminology.

### 2. Reduce commercial status to Draft and Accepted

Remove unsupported `QuotationStatus` cases and every code branch that refers to
them. Keep confirmation as the only Draft-to-accepted transition.

Factories and seeders retain Draft and accepted states only. Tests no longer
construct unsupported states. No database check constraint or migration is
needed because the status column already stores these supported strings.

### 3. Centralize staff workflow stage

Introduce a non-persisted `OpticalOrderStage` resolver over Draft plus Job Order
status:

```text
Draft | Confirmed | Processing | Ready for Pickup | Completed | Cancelled
```

Optical Order tables, tabs, badge colors, details, and dashboard links use this
single mapping. Efficient tab queries continue to use persisted Quotation and
Job Order columns.

An accepted Quotation without its required Job Order is treated as an invariant
violation, not a compatibility state.

### 4. Remove the Quotation staff surface completely

Delete the hidden Quotation Filament resource rather than leaving a zombie
compatibility shell. Update every normal entry point to use Optical Orders.

The supported actions become:

- Draft: Edit, Confirm Sale, Discard Draft;
- active confirmed order: approved fulfillment and cancellation actions;
- completed/cancelled order: read-only operational history plus approved
  financial links.

The existing soft-delete action handles discarded Drafts. It is relabeled and
tested for absence of downstream effects.

### 5. Remove the Quotation patient API directly

Delete the route import and route definitions, Quotation API controller and
resource, API documentation, and tests specific to that contract. Update route
contract counts and route-absence assertions.

There is no deprecation header, adapter, alias, or replacement endpoint because
no deployed consumer exists.

### 6. Make Eyewear Job-Order-only

Start listing and lookup from patient-owned Job Orders only:

- remove quotation-only candidate keys and lookup;
- remove `EstimateAvailable`, `EstimateDeclined`, and `EstimateExpired`;
- remove the quotation-only aggregate builder branch;
- remove the `estimate` property and detail section;
- retain Job Order-backed preparation items, dispensing, totals, payment
  summary, ownership, stable-key, and `jo_{id}` alias behavior;
- retain current/history filters with only confirmed operational stages.

The builder may stop accepting a nullable Job Order. Where Quotation timestamps
or totals were formerly preferred, use the confirmed Job Order snapshot so the
patient contract has one authoritative source.

### 7. Leave harmless schema residue, remove runtime complexity

Do not add a migration solely to drop nullable presentation metadata columns.
Do not edit deployed migrations. Runtime code, routes, resources, tests, and
documentation for removed behavior are deleted because they carry ongoing
cost; inert nullable columns do not.

Developers with obsolete local rows may manually recreate their local database.
No implementation command resets data automatically.

### 8. Delete safely with replacement coverage

Before deleting a component:

1. add or update tests for the supported replacement behavior;
2. add absence assertions for removed routes, actions, or states;
3. audit references with `rg`;
4. delete the now-unreferenced implementation and obsolete tests;
5. run the focused suite immediately.

Tests covering Draft or confirmed behavior are rewritten even if their current
file or class name says Quotation. Only tests whose entire subject is removed
are deleted.

## Implementation Order

### Wave 1: Lock the supported core and status model

- Characterize Draft creation/update, direct prepared and immediate
  confirmation, soft-delete privacy, Job Order snapshotting, Billing Record,
  inventory, reservation, and payment behavior.
- Add route/action/state absence tests that initially fail against legacy
  surfaces.
- Inventory every removed-status consumer and prepare supported fixtures. Keep
  the old enum cases temporarily until their UI, API, and Eyewear consumers are
  removed so intermediate commits remain executable.
- Add and adopt the centralized Optical Order stage resolver.

**Checkpoint:** all supported domain behavior is covered; unsupported state
references are identified; no schema change exists.

### Wave 2: Simplify the staff Optical Orders workflow

- Replace tabs, stage badges, filters, and labels with the supported stages.
- Remove Share Estimate, Decline Estimate, Awaiting Decision, Valid Until, and
  quotation-specific form copy.
- Relabel Draft deletion as Discard Draft.
- Preserve Confirm Sale inputs and approved transaction behavior.
- Replace dashboard and permissions copy with Optical Order terminology.

**Checkpoint:** staff can create, edit, discard, confirm, fulfill, and cancel
through Optical Orders without any quotation workflow concept.

### Wave 3: Delete the unused Quotation application surface

- Verify no supported staff reference targets the hidden resource.
- Delete the Filament Quotation resource tree.
- Delete presentation/decision actions and unused policy code.
- Remove or rewrite their dedicated tests after replacement assertions pass.
- Audit the application for unsupported status cases and presentation wording.

**Checkpoint:** no staff route, resource, action, policy, enum case, or test
supports formal Quotations; Draft/confirmed suites remain green.

### Wave 4: Remove the Quotation API and simplify Eyewear

- Add route-absence contract assertions, then remove Quotation API routes,
  controller, resource, imports, and obsolete tests.
- Convert Eyewear listing, lookup, aggregate, enum, and resources to Job Order-
  only composition.
- Remove estimate-only progress/filter behavior and the `estimate` detail
  section.
- Preserve order items, status, total, combined-payment privacy, stable keys,
  ownership, pagination, and current/history behavior.

**Checkpoint:** `/api/v1/quotations` is absent; Drafts are invisible; confirmed
orders remain completely trackable through Eyewear.

### Wave 5: Reconcile documentation and run conformance

- Remove the now-unreferenced status cases and factory states after a zero-
  reference audit.
- Update API route counts, route summary, Eyewear examples, backend status
  rules, navigation, permissions, dashboard, and affected optical specs.
- Audit code, tests, routes, seeders, and docs for removed concepts.
- Run focused suites, full Pest, Pint, and frontend build.
- Record completion in the approved spec, replacement plan, and replacement
  task document without claiming physical table removal.

**Checkpoint:** code and documentation describe one Optical Orders workflow and
one patient Eyewear contract; all verification gates pass.

## Dependency Graph

```text
Supported-behavior characterization
    -> derived stage resolver
        -> staff Optical Orders cleanup
            -> delete Quotation staff surface
                -> remove Quotation API
                    -> Job-Order-only Eyewear
                        -> prune unsupported statuses and fixtures
                            -> docs and full conformance
```

Status reduction must follow removal of every UI, API, Eyewear, factory, and
test consumer so each intermediate task remains executable. Staff cleanup must
precede deleting its old resource/actions. API route removal and Eyewear
simplification can share a wave but should land as separate tasks so each
remains reviewable.

## Component Change Map

### Retained and adapted

- `app/Models/Quotation.php` and `QuotationItem.php`;
- `app/Actions/Quotations/CreateQuotation.php` and
  `UpdateQuotationDraft.php`;
- `app/Filament/Resources/OpticalOrders/`;
- `app/Actions/OpticalOrders/` confirmation and cancellation actions;
- Job Order, Billing Record, payment, inventory, reservation, and dispensing
  domains;
- Eyewear endpoints and confirmed-order resources.

### Removed after reference audit

- presentation/decision action classes;
- `app/Filament/Resources/Quotations/`;
- Quotation-specific policy if no supported method remains;
- Quotation API controller/resource and routes;
- unsupported enum cases, factory states, seed records, tests, and docs;
- Eyewear estimate-only branches and response section.

### Documentation

- `docs/BACKEND_CONTEXT.md`;
- `docs/API_CONTRACT.md`;
- simplified Optical Orders and unified transaction specs/plans/tasks;
- this spec-driven document set.

## Verification Checkpoints

Focused commands during implementation:

```text
vendor/bin/sail artisan route:list --except-vendor
vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders tests/Feature/Quotations
vendor/bin/sail artisan test --compact tests/Feature/Filament/QuotationCreationTest.php tests/Feature/Filament/DashboardTest.php
vendor/bin/sail artisan test --compact tests/Feature/Eyewear tests/Feature/Api/EyewearApiTest.php tests/Feature/Api/V1/RouteContractTest.php
vendor/bin/sail bin pint --dirty --format agent
```

Removal audits:

```text
rg -n "PresentQuotation|RecordQuotationDecision|QuotationStatus::Presented|QuotationStatus::Declined|QuotationStatus::Expired" app routes database tests
rg -n "QuotationController|api/v1/quotations|Route::get\('quotations|estimate_available|estimate_declined|estimate_expired" app routes tests docs
rg -n "Share Estimate|Awaiting Decision|Quotations Pending|Quotation Status|Valid Until" app tests docs
```

Final gate:

```text
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
```

Before code changes, search installed-version Laravel and Filament documentation
for resource removal, soft-delete actions, tabs, action testing, route testing,
and API resource behavior.

## Parallelization and Sequencing

The status and stage foundation is sequential. After staff UI no longer uses
the removed workflow, Filament resource deletion and patient API removal are
conceptually independent; however, this repository should land them as separate
tasks because both touch shared enums, factories, and tests. Documentation and
full conformance remain last.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| A broad deletion removes Draft/confirmation behavior because it is named Quotation | High | Classify retained versus removed files first; require supported replacement tests before deletion. |
| Accepted orders exist without Job Orders after status cleanup | High | Treat as an invariant violation; test atomic confirmation and idempotency. |
| Eyewear loses items or totals when quotation composition is removed | High | Source them from immutable Job Order snapshots and run exact detail-contract tests. |
| Route count or API documentation remains stale | Medium | Add route-absence assertions and regenerate the documented inventory manually from route:list. |
| Tests are deleted instead of rewritten | High | Remove only tests whose entire subject is gone; retain Draft/confirmed assertions in renamed or focused suites. |
| Local databases contain unsupported statuses | Low | Document manual reset-and-seed; do not add production migration or reset automatically. |
| Inert presentation columns cause confusion | Low | Document them as unused schema residue; remove only in a future schema cleanup if worthwhile. |
| Existing broader optical specs conflict | Medium | Reconcile every living spec and task document during conformance before marking completion. |
| Dirty worktree changes overlap implementation | Medium | Reinspect status before every task and preserve unrelated changes. |

## Rollout and Rollback

### Rollout

This is a pre-launch integration change. Run fresh-database migrations and
seeders in the test environment, focused suites, full tests, and frontend build.
Verify Draft creation, direct confirmation, immediate/prepared fulfillment,
route absence, and a linked-patient Eyewear response.

No production migration, compatibility window, or dual operation is needed.

### Rollback

Revert the application deploy. The plan does not rename or drop internal
tables, foreign keys, or stable eyewear identifiers. Developers who recreated
local data can reseed either revision as needed.

## Phase 2 Approval Gate

Approval of this replacement plan authorizes a replacement Phase 3 (**Tasks**)
only. It does not authorize application-code implementation. Tasks will be
test-first, limited to approximately five files, and will request explicit
approval before deleting obsolete test files as part of the approved removal
scope.
