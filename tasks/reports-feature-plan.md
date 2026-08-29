# Implementation Plan: Reports Feature

**Status:** Implemented 2026-08-30.

## Overview

Build an admin-only Filament Reports cluster with Financial, Appointments,
Optical Orders, and Feedback pages. The implementation uses current canonical
tables, a shared validated date-range shell, aggregate-only CSV exports, and no
patient API changes.

## Architecture Decisions

- Use a Filament cluster inside the existing Admin navigation group. This adds
  one sidebar entry and avoids reviving the removed top-level Reports group.
- Replace the disabled legacy `BaseReport` rather than adapting its obsolete
  `label => count` interface.
- Keep report metrics contract-first and domain-specific; share only filters,
  authorization, common rendering structures, and export plumbing.
- Treat billed/collected values as period flows and balances/statuses as
  clearly labeled current snapshots or current cohort outcomes.
- Use half-open datetime bounds in `Asia/Manila`, conditional aggregates, and
  zero-filled bounded groups. Do not use `whereDate()` on report predicates.
- Start without schema changes, then review final query plans. Add only the
  reversible report-specific indexes justified by those plans.

## Dependency Graph

```text
Approved metric contract
        |
        v
Reports cluster + shared filter/access shell
        |
        +--> Financial slice
        +--> Appointments slice
        +--> Optical Orders slice
        +--> Feedback slice
                  |
                  v
        Shared export/accessibility hardening
                  |
                  v
          Query-plan review + docs
```

The four domain slices can be implemented independently after the shared shell
lands, but navigation, export shape, and shared test helpers must remain
coordinated.

## Phase 0: Approval and Characterization

### Task 1: Approve the report contract

**Description:** Review the proposed access model, four report definitions,
date semantics, CSV scope, and explicit accounting limitations before code is
written.

**Acceptance criteria:**

- [x] Access roles are approved.
- [x] Every KPI and denominator is approved.
- [x] MVP/deferred scope is approved.

**Verification:** Completed 2026-08-30. The owner approved the recommended
access model, report definitions, navigation model, and CSV/deferred scope,
then explicitly paused implementation.

**Dependencies:** None.

**Files likely touched:**

- `docs/specs/reports-feature-spec.md`
- `tasks/reports-feature-plan.md`

**Estimated scope:** Small.

### Task 2: Add report characterization tests

**Description:** Create the focused Pest/Livewire test file with factories and
shared helpers that encode the approved role matrix, timezone boundaries, and
domain metric fixtures before implementation.

**Acceptance criteria:**

- [x] Focused tests describe access, filtering, all four metric contracts,
  empty states, and CSV behavior.
- [x] Characterization tests were committed red before the shared shell and
  then turned green by the implementation.
- [x] Existing navigation tests remain green with the Reports cluster exposed.

**Verification:** Focused report tests and the navigation structure test pass.

**Dependencies:** Task 1.

**Files likely touched:**

- `tests/Feature/Reports/ReportsAccessAndFiltersTest.php`
- `tests/Feature/Reports/FinancialReportTest.php`
- `tests/Feature/Reports/AppointmentsReportTest.php`
- `tests/Feature/Reports/OpticalOrdersReportTest.php`
- `tests/Feature/Reports/FeedbackReportTest.php`
- `tests/Feature/Reports/ReportExportsTest.php`

**Estimated scope:** Small.

## Phase 1: Shared Shell

### Task 3: Build the Reports cluster and shared report page

**Description:** Create the admin-only cluster, replace the orphaned report
base/view, and implement validated URL-backed period filters, presets, date
boundaries, loading/error/empty states, and a typed presentation contract for
KPI and breakdown sections.

**Acceptance criteria:**

- [x] Only active administrators can access the cluster or direct report URLs.
- [x] This month is the default; presets and valid custom ranges produce the
  expected half-open boundaries in `Asia/Manila`.
- [x] Invalid and reversed date ranges render field-level errors and do not run
  report/export queries.

**Verification:** `ReportsAccessAndFiltersTest.php` and
`AdminNavigationStructureTest.php` pass.

**Dependencies:** Task 2.

**Files likely touched:**

- `app/Filament/Clusters/Reports/ReportsCluster.php`
- `app/Filament/Clusters/Reports/Pages/ReportsClusterPage.php`
- `resources/views/filament/clusters/reports/pages/report.blade.php`
- `app/Providers/Filament/AdminPanelProvider.php`
- `tests/Feature/Filament/AdminNavigationStructureTest.php`

**Estimated scope:** Medium.

## Checkpoint: Shared Shell

- [x] Access and date-filter tests pass.
- [x] Admin navigation contains one Reports entry with a unique outlined icon.
- [x] Staff navigation has no empty or unauthorized group.
- [x] Code review completed; shell typing and title handling findings were fixed.

## Phase 2: Vertical Report Slices

### Task 4: Deliver the Financial report

**Description:** Implement the approved non-voided billing, currently posted
collection, current balance, status, source-kind, and payment-method aggregates
without double-counting Optical Orders.

**Acceptance criteria:**

- [x] KPI values and breakdowns match the Financial contract at both date
  boundaries.
- [x] Voided bills and reversed payments are excluded.
- [x] Flow and current-snapshot labels are visibly distinct.

**Verification:** `FinancialReportTest.php` passes.

**Dependencies:** Task 3.

**Files likely touched:**

- `app/Filament/Clusters/Reports/Pages/FinancialReport.php`
- `tests/Feature/Reports/FinancialReportTest.php`

**Estimated scope:** Medium.

### Task 5: Deliver the Appointments report

**Description:** Implement scheduled-cohort counts, current outcome/source/type
breakdowns, and terminal-only fulfillment/no-show rates.

**Acceptance criteria:**

- [x] All five appointment outcomes are represented, including zero counts.
- [x] Nonterminal appointments do not dilute terminal outcome rates.
- [x] Source and appointment-type totals reconcile to the scheduled cohort.

**Verification:** `AppointmentsReportTest.php` passes.

**Dependencies:** Task 3.

**Files likely touched:**

- `app/Filament/Clusters/Reports/Pages/AppointmentsReport.php`
- `tests/Feature/Reports/AppointmentsReportTest.php`

**Estimated scope:** Medium.

### Task 6: Deliver the Optical Orders report

**Description:** Implement created-cohort status/mode breakdowns and distinct
dispensed/cancelled period events with average fulfillment time.

**Acceptance criteria:**

- [x] Current created-cohort status counts reconcile to orders created in the
  period.
- [x] Dispensed and cancelled event metrics use their transition timestamps,
  regardless of order creation date.
- [x] Average fulfillment time includes only orders dispensed in the period.

**Verification:** `OpticalOrdersReportTest.php` passes.

**Dependencies:** Task 3.

**Files likely touched:**

- `app/Filament/Clusters/Reports/Pages/OpticalOrdersReport.php`
- `tests/Feature/Reports/OpticalOrdersReportTest.php`

**Estimated scope:** Medium.

### Task 7: Deliver the Feedback report

**Description:** Implement independent visit-rating and frame-rating counts,
averages, and star distributions without exposing comments or PII.

**Acceptance criteria:**

- [x] Visit and frame populations are never combined into one average.
- [x] Each 1–5 distribution is zero-filled and reconciles to its count.
- [x] Hidden comments contribute stars but no text or moderation data appears.

**Verification:** `FeedbackReportTest.php` passes.

**Dependencies:** Task 3.

**Files likely touched:**

- `app/Filament/Clusters/Reports/Pages/FeedbackReport.php`
- `tests/Feature/Reports/FeedbackReportTest.php`

**Estimated scope:** Medium.

## Checkpoint: Report Semantics

- [x] All four pages render useful populated and empty states.
- [x] KPI totals reconcile with every applicable breakdown.
- [x] The patient API route contract remains unchanged.
- [x] Code review completed; unknown/null breakdown values now use explicit
  safe labels and do not disappear or type-error.

## Phase 3: Export, Performance, and Reconciliation

### Task 8: Add aggregate CSV exports and accessibility verification

**Description:** Add one authorized export per report using the same validated
filters and presentation data, then verify keyboard, screen-reader, responsive,
loading, and empty-state behavior.

**Acceptance criteria:**

- [x] CSV metadata, metric definitions, KPIs, and breakdowns match the visible
  report; monetary values are raw two-decimal numeric strings.
- [x] Exports contain no PII and use formula-safe cells.
- [x] Report pages remain usable at the required breakpoints and without color
  as the only status signal.

**Verification:** `ReportExportsTest.php` passes and the frontend build is
green; the rendered view includes labeled empty, validation, and loading states.

**Dependencies:** Tasks 4–7.

**Files likely touched:**

- `app/Filament/Clusters/Reports/Pages/ReportsClusterPage.php`
- `resources/views/filament/clusters/reports/pages/report.blade.php`
- `tests/Feature/Reports/ReportExportsTest.php`

**Estimated scope:** Medium.

**Review:** Checkpoint review fixed presentation-formatted currency in CSVs,
added formula-safe numeric handling, and added explicit filter/export loading
states.

### Task 9: Review query plans and add only justified indexes

**Description:** Run `EXPLAIN` for the final aggregate queries against
representative data. The reviewed plans showed full scans on the principal
report cohorts, so one reversible migration adds only the justified report
access paths.

**Acceptance criteria:**

- [x] Every report query has a reviewed plan and bounded result shape.
- [x] The migration contains only indexes justified by the reviewed plans.
- [x] Each index names its supporting query and is covered by a focused schema
  test.

**Verification:** Focused report tests, `ReportQueryIndexTest.php`, and
`vendor/bin/sail artisan migrate:status` pass after migration.

**Dependencies:** Tasks 4–7.

**Files likely touched:**

- `database/migrations/2026_08_30_003957_add_report_query_indexes.php`
- `tests/Feature/Reports/ReportQueryIndexTest.php`

**Estimated scope:** Small without migration; Medium with migration.

**Review:** Query-plan review justified the single reversible index migration;
the schema regression test verifies every named index.

### Task 10: Reconcile documentation and run final verification

**Description:** Document the shipped report surfaces, access boundary,
definitions, and limitations, remove the obsolete disabled report scaffold,
and run the focused quality gates.

**Acceptance criteria:**

- [x] `BACKEND_CONTEXT.md` describes the Reports cluster and canonical metric
  semantics.
- [x] The historical `reports-ui-spec.md` is left as history or clearly marked
  superseded; it is not treated as current truth.
- [x] No API contract or route count changes are made.

**Verification:**

- `vendor/bin/sail artisan test --compact tests/Feature/Reports tests/Feature/Filament/AdminNavigationStructureTest.php`
- `vendor/bin/sail bin pint --dirty --format agent`
- `vendor/bin/sail npm run build`
- `git diff --check`

**Dependencies:** Tasks 8–9.

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`
- `docs/specs/reports-ui-spec.md`
- `app/Filament/Pages/Reports/BaseReport.php` (removed)
- `resources/views/filament/pages/reports/report.blade.php` (removed)
- `resources/views/filament/pages/reports/reorder.blade.php` (removed)

**Estimated scope:** Medium.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Financial labels imply formal historical accounting | High | Separate period flows from current snapshots and document mutable payment corrections. |
| Status counts are mistaken for historical as-of state | Medium | Label them as current outcomes/status for a selected cohort; use transition timestamps for event metrics. |
| Range predicates cause full table scans | Medium | Use half-open bounds, conditional aggregates, and review `EXPLAIN` before requesting indexes. |
| Aggregate pages expose PII through details or exports | High | Aggregate-only contracts, admin authorization, no comments/names, and explicit PII-absence tests. |
| Reports clutter the workflow navigation | Low | One Reports cluster entry inside Admin, not a new top-level group or four sidebar rows. |
| Legacy report code is accidentally reused | High | Replace the disabled base/view and test canonical model usage. |

## Deferred Scope

- Patient or Android reports API
- Staff/optometrist access
- Previous-period comparisons and forecasting
- Scheduled/email delivery
- PDF reports
- Custom report builder
- Historical as-of balances, inventory snapshots, or materialized aggregates
- Procurement/reorder report (the Inventory workspace already owns that queue)
