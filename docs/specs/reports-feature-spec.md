# Spec: Reports Feature

**Status:** Implemented 2026-08-30.

## Assumptions

1. Reports are an internal Filament feature for administrators, not a patient-mobile API feature.
2. The first release contains Financial, Appointments, Optical Orders, and Feedback reports.
3. Reports are aggregate and read-only. They do not show patient names, comments, clinical narrative, or other PII.
4. CSV export is included; scheduled delivery, PDF export, custom report builders, and formal accounting statements are deferred.
5. All periods use the clinic timezone (`Asia/Manila`) and inclusive calendar dates.

## Objective

Give the clinic owner a reliable, date-filtered view of financial activity,
appointment outcomes, optical-order fulfillment, and patient ratings using the
current canonical workflow tables.

The feature replaces the intentionally disabled legacy report scaffold. It must
not restore queries against the retired `Billing`, `Order`, or `Feedback`
models. It must also fit the current workflow-shaped navigation without adding
a new top-level sidebar group.

## Users and Access

- Administrators may view reports and download aggregate CSV exports.
- Staff, optometrists without the admin role, patients, inactive users, and
  unauthenticated users may not access report pages or exports.
- An `admin + optometrist` account is an administrator and therefore has access.
- Authorization is enforced by the report cluster/page boundary, not only by
  hiding navigation.

## Information Architecture

Add one **Reports** cluster entry to the existing **Admin** navigation group.
The cluster contains four pages:

1. Financial
2. Appointments
3. Optical Orders
4. Feedback

Each page shares the same period controls, preset ranges, KPI presentation,
accessible breakdowns, empty state, and CSV action. Filters are reflected
in the URL so a report view can be bookmarked or shared with another authorized
administrator.

## Report Contracts

### Financial

Canonical sources: `billing_records`, `billing_record_items`, and
`billing_payments`.

- **Net billed in period:** sum of `billing_records.total_amount` for
  non-voided records whose `recorded_at` falls in the selected period.
- **Discounts in period:** sum of `discount_amount` over the same bill cohort.
- **Collections in period:** sum of currently posted payments whose
  `recorded_at` falls in the selected period. Reversed payments are excluded.
- **Current balance on bills created in period:** sum of the current
  `balance_due` for the non-voided bill cohort. This is explicitly a current
  snapshot, not the historical balance as of the period end.
- Breakdowns: current billing status for bills created in the period, bill line
  amount by `source_kind`, and collections by payment method.

The report must not add `job_orders.total_amount` to billing totals, because an
Optical Order and its Billing Record represent the same commercial event.
Collections are operational ledger reporting, not a formal immutable cashflow
statement: payment corrections mutate the original payment to `reversed` and
create a replacement.

### Appointments

Canonical source: `appointments` joined to the seeded appointment status and
type records.

- **Appointments scheduled:** records whose `scheduled_at` falls in the period.
- Current outcome breakdown for that scheduled cohort: scheduled, checked in,
  fulfilled, cancelled, and no-show.
- **Fulfillment rate:** fulfilled divided by terminal outcomes only
  (`fulfilled + cancelled + no_show`). Nonterminal and future appointments are
  excluded from the denominator.
- **No-show rate:** no-show divided by the same terminal denominator.
- Breakdowns: current outcome, appointment source, and appointment type.

Labels must state that statuses are current outcomes for appointments scheduled
in the selected period; the report does not reconstruct historical status at a
past point in time.

### Optical Orders

Canonical sources: `job_orders` and `dispensing_events`.

- **Orders created:** records whose `created_at` falls in the period.
- Current status breakdown for that created cohort: Confirmed (`queued`),
  Processing, Ready for Pickup, Completed (`dispensed`), and Cancelled.
- **Orders dispensed in period:** records whose `dispensed_at` falls in the
  period, regardless of creation date.
- **Orders cancelled in period:** records whose `cancelled_at` falls in the
  period, regardless of creation date.
- **Average fulfillment time:** `created_at` to `dispensed_at` for orders
  dispensed in the period.
- Breakdowns: created-cohort status, fulfillment mode, and supplier mode.

Created-cohort metrics and period event metrics must be visually and verbally
distinguished.

### Feedback

Canonical sources: `visit_ratings` and `frame_ratings`. These are separate
populations and must never be merged into one average.

- Visit rating count, average, and 1–5 star distribution by rating
  `created_at`.
- Frame rating count, average, and 1–5 star distribution by rating
  `created_at`.
- Hidden comments still contribute their star value, matching the current
  rating contract, but no comment text, patient identity, moderation reason,
  or other PII is rendered or exported.
- Ratings are updated in place, so the feature does not claim to provide a
  historical rating-revision trend.

## Shared Filter Contract

- Presets: This month (default), Last month, Last 30 days, This year, and All
  time.
- Custom input: `dateFrom` and `dateUntil`, exact `Y-m-d` values.
- `dateFrom` must be on or before `dateUntil`.
- Query boundaries are half-open instants in the clinic timezone:
  `>= startOfDay(dateFrom)` and `< startOfDay(dateUntil + 1 day)`.
- The implementation must not use `whereDate()` for report range predicates,
  because wrapping indexed datetime columns prevents efficient range scans.
- Filters apply only after explicit submission so changing both dates does not
  run every aggregate twice.
- Invalid filters produce a visible field-level error and do not run report
  queries or exports.

## CSV Contract

- One CSV per report page, containing the report title, selected period,
  timezone, metric definitions, KPI rows, and every displayed breakdown row.
- Filenames are deterministic and include the report key and export date.
- Monetary values use raw two-decimal numeric strings; formatting is left to
  the spreadsheet application.
- Export authorization and filter validation are identical to the page.
- No PII or free-text patient content is exported.
- Cells are escaped against spreadsheet formula injection even though the MVP
  primarily exports controlled labels and numeric values.

## UI and Accessibility

- Use Filament components and the existing EyeCare visual system; do not add a
  charting or export dependency.
- Prefer KPI cards plus compact tables or labeled share bars. Tables remain the
  semantic source for screen readers; color is not the only status indicator.
- Provide loading, empty, validation-error, and export-in-progress states.
- Controls are keyboard accessible and have visible labels and focus states.
- Layout remains usable at 320, 768, 1024, and 1440 pixel widths.
- Do not execute queries from Blade. Pages prepare all display/export data.

## Technical Approach

- Replace the orphaned `app/Filament/Pages/Reports/BaseReport.php` and report
  views intentionally; do not layer new behavior onto their obsolete
  single-breakdown contract.
- Use a Filament cluster under `app/Filament/Clusters/Reports/` and a shared
  abstract report page for authorization, URL filter state, date-boundary
  parsing, presets, and export response handling.
- Keep each domain's aggregate queries in its concrete report page unless the
  implementation proves a smaller report builder under the existing
  `app/Actions/` base is needed. Do not create a new top-level application
  folder without approval.
- Use fixed conditional aggregates and bounded grouped queries. Select scalar
  values with no model hydration when relationships are not rendered.
- Add no reporting/materialized tables for the MVP.
- Inspect representative queries with `EXPLAIN` after implementation. Add a
  focused reversible index migration only if the final query plans justify it.

## Tech Stack and Commands

- PHP 8.5, Laravel 13, Filament 5, Livewire 4, Pest 4, MySQL, Laravel Sail.
- Focused tests:
  `vendor/bin/sail artisan test --compact tests/Feature/Reports tests/Feature/Filament/AdminNavigationStructureTest.php`
- Format modified PHP:
  `vendor/bin/sail bin pint --dirty --format agent`
- Frontend build when report views or theme classes change:
  `vendor/bin/sail npm run build`

## Testing Strategy

- Feature/Livewire tests use factories and cover each report's metric semantics.
- Access tests cover unauthenticated, patient, inactive, staff, optometrist,
  admin, and admin+optometrist accounts.
- Boundary tests freeze time and prove start/end dates are inclusive in
  `Asia/Manila`, while out-of-range records are excluded.
- Financial tests prove voided bills and reversed payments are excluded and
  that Optical Order totals are not double-counted.
- Appointment tests prove the terminal-only rate denominator.
- Optical Order tests distinguish created-cohort status from dispensed and
  cancelled event metrics.
- Feedback tests keep visit/frame aggregates separate and include hidden stars
  without rendering comments.
- CSV tests assert authorization, headers, selected period, metric rows, PII
  absence, and formula-safe cells.
- Navigation tests lock the Reports cluster into the Admin group and preserve
  unique outlined icons and group ordering.

## Boundaries

- **Always:** use canonical current models; authorize page and export access;
  validate filters; use clinic-timezone half-open ranges; run focused tests,
  Pint, and the frontend build; update `BACKEND_CONTEXT.md` when shipped.
- **Ask first:** add indexes beyond the shipped report-query migration, add
  dependencies, expose reports outside Filament, widen access beyond
  administrators, or add a historical snapshot model.
- **Never:** add report routes to the patient `/api/v1` contract; expose PII or
  clinical narrative; resurrect legacy models; call the current balance an
  historical as-of balance; present mutable payments as an audited cash ledger.

## Success Criteria

1. An active administrator can open all four report pages from one Reports
   cluster; every other role is denied even by direct URL.
2. Every KPI and breakdown follows the definitions above for default, preset,
   custom, empty, and all-time ranges.
3. Exports contain the displayed aggregate data and no PII.
4. The patient API contract and route count remain unchanged.
5. Focused tests, Pint, and the frontend build pass.

## Approved Decisions

1. The first release is administrator-only.
2. The four-page scope and metric definitions above are approved.
3. Aggregate CSV export is included in the MVP. Comparison periods, PDF
   exports, and scheduled delivery are deferred.
4. The Reports cluster has one main-sidebar entry and four internal report
   destinations: Financial, Appointments, Optical Orders, and Feedback.
5. Implementation is complete in the admin-only Reports cluster. The patient
   API contract remains unchanged.

## Implementation Reconciliation

The shipped feature uses `app/Filament/Clusters/Reports/` with one Reports
navigation entry under Admin and four top-sub-navigation pages: Financial,
Appointments, Optical Orders, and Feedback. The shared page shell owns
authorization, clinic-timezone date filters, presets, half-open boundaries,
aggregate CSV export, and validation. The retired report scaffold and view were
removed rather than reused. CSV cells are formula-safe and monetary values are
exported as numeric strings; page and export payloads contain no patient PII.

Final query-plan review justified one reversible migration adding report cohort
indexes. No reporting tables, dependencies, or patient API routes were added.
