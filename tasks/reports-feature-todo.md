# Reports Feature Checklist

**Status:** Approved 2026-08-30 — do not implement until the owner explicitly
asks to begin.

- [x] Task 1: Approve access, metrics, navigation, MVP, and deferred scope.
- [ ] Task 2: Add failing characterization tests.
- [ ] Task 3: Build the admin-only Reports cluster and shared filter shell.

## Checkpoint: Shared Shell

- [ ] Access and date-range tests pass.
- [ ] Navigation contains one authorized Reports cluster entry.

- [ ] Task 4: Deliver the Financial report.
- [ ] Task 5: Deliver the Appointments report.
- [ ] Task 6: Deliver the Optical Orders report.
- [ ] Task 7: Deliver the Feedback report.

## Checkpoint: Report Semantics

- [ ] KPI totals reconcile with applicable breakdowns.
- [ ] Populated and empty ranges render correctly.
- [ ] Patient API routes and contract remain unchanged.

- [ ] Task 8: Add aggregate CSV exports and accessibility verification.
- [ ] Task 9: Review query plans; request approval before any index migration.
- [ ] Task 10: Reconcile documentation and run final verification.

## Final Verification

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/ReportsTest.php tests/Feature/Filament/AdminNavigationStructureTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `vendor/bin/sail npm run build`
- [ ] `git diff --check`
- [ ] Owner reviews the completed feature.
