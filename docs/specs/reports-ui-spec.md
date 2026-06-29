# Spec: Reports Module UI Improvements

## Objective

Make the 4 report pages (Sales, Orders, Appointments, Feedback) more useful and visually rich for the defense, without adding fragile client-side JS.

**Scope (confirmed): A + B + E**
- **A — Visual breakdown:** replace the plain status→count table with server-rendered horizontal share bars (count + percentage + proportional bar per status).
- **B — Preset date ranges:** quick-select pills (This month, Last month, Last 30 days, This year, All time) that set the date range in one click. Manual date pickers still work and clear the active preset.
- **E — Empty states:** when there are no records in the selected range, show a clear "No records in this period" message instead of an empty table.

**Plus a refactor (separate commit):** extract a `BaseReport` abstract class to remove duplication across the 4 pages (shared date filters, access control, mount, presets).

**User:** Admin viewing reports. **Success:** preset pills work, breakdown shows proportional bars with %, empty ranges show a friendly message, all reactive to the date filter.

## Tech Stack

PHP 8.5 / Laravel 13 / Filament 5 / Pest 4. No new dependencies. No JS — bars are server-rendered (always in sync with the Livewire date filter).

## Commands

```
Test:  vendor/bin/sail artisan test --compact --filter=Report
Lint:  vendor/bin/sail bin pint --dirty --format agent
Build: vendor/bin/sail npm run build
```

## Code Style

Bars use inline `style="width: {pct}%"` (dynamic value can't be a Tailwind class) with an inline brand color, inside Tailwind-styled track:

```blade
<div class="h-2 w-full rounded-full bg-gray-100 dark:bg-white/10">
    <div class="h-full rounded-full" style="width: {{ $pct }}%; background-color: #4F8DD7;"></div>
</div>
```

## Architecture Decisions

- **Server-rendered bars, not Chart.js:** the date filter is reactive (Livewire). Server-rendered bars re-render correctly on every filter change with zero JS glue — robust for a live defense. A JS chart would need re-sync logic and is hard to verify without a browser.
- **`BaseReport` abstract class:** the 4 pages duplicate ~40 lines (date props, mount, canAccess, presets). With presets added, the duplication grows — extraction is now justified. Each page keeps only its icon, sort, title, `getStats()`, and `getBreakdown()`.
- **Remove dead code:** `filtersSchema()` and the redundant `getTitle()` override are unused (the view renders raw date inputs; static `$title` suffices). Drop them during the refactor.
- **Preset state:** `activePreset` property highlights the active pill; manual date edits clear it via `updatedDateFrom/Until` hooks.

## Testing Strategy

- Existing `ReportsTest` (render + access) must stay green through the refactor.
- Add preset tests: `applyPreset('last_month')` sets the correct date range; `activePreset` clears on manual date change.
- Render assertion: a report with data shows the breakdown; with no data shows the empty-state copy.

## Boundaries

- **Always:** run `--filter=Report`, Pint, rebuild assets (view classes), update BACKEND_CONTEXT if behavior changes.
- **Ask first:** new dependencies, any Chart.js addition.
- **Never:** change report query semantics, break admin-only access.

## Tasks

### Task 1 — Extract `BaseReport` (refactor, no behavior change)
- Acceptance: new `app/Filament/Pages/Reports/BaseReport.php`; all 4 pages extend it and keep only icon/sort/title/getStats/getBreakdown; dead `filtersSchema()`/`getTitle()` removed; `ReportsTest` still passes.
- Verify: `--filter=Report`
- Files: BaseReport.php (new) + 4 report pages

### Task 2 — Preset ranges + visual bars + empty states
- Acceptance: preset pills set ranges and highlight the active one; manual date edit clears active pill; breakdown renders proportional bars with % ; empty range shows "No records in this period".
- Verify: `--filter=Report`, `npm run build`, manual check at `/admin/sales-report`
- Files: BaseReport.php (presets + hooks), `report.blade.php`, `ReportsTest.php`

## Success Criteria

1. Clicking "Last month" updates both dates and re-renders stats + bars.
2. Breakdown shows a labeled row per status with count, %, and a proportional brand-blue bar.
3. A date range with zero records shows the empty state, not blank/zeroed bars.
4. Refactor leaves all report tests green; no duplicated boilerplate across the 4 pages.

## Open Questions

None — scope confirmed (A+B+E), charts intentionally server-rendered.
