# Checklist: Remove Individual Optometrist Hours

**Status:** Proposed on 2026-09-15; no implementation started.
**Plan:** `tasks/remove-provider-hours-plan.md`

## Approval gates

- [ ] Approve the rule: active optometrists cover all clinic hours unless a
  dated absence overlaps the candidate interval.
- [ ] Approve consolidation of both availability endpoints onto the same
  interval-capacity engine.
- [ ] Confirm that clinic hours, schedule overrides, appointment assignments,
  and assigned-provider collision checks remain.
- [ ] Confirm that `optometrist_id: null` stays in the current API response for
  compatibility.
- [ ] Approve deletion of the `provider_hours` rows/table and feature-specific
  audit history only after consumer removal.

## Phase 0: Characterize

- [ ] Rewrite provider availability tests without `ProviderHour` fixtures.
- [ ] Cover two active providers, zero providers, inactive providers, and
  non-optometrist users.
- [ ] Cover full-day and partial provider absences.
- [ ] Cover aggregate capacity and assigned-provider collision behavior.
- [ ] Run the focused characterization tests and record the baseline.

## Phase 1: One availability engine

- [ ] Remove `ProviderHour` queries from `EvaluateAppointmentAvailability`.
- [ ] Make active status and optometrist capability the recurring eligibility
  boundary.
- [ ] Preserve full-day and interval-overlap absence rules.
- [ ] Calculate capacity for each candidate interval.
- [ ] Reuse day-scoped data so slot count does not multiply database queries.
- [ ] Route new-request listing through the shared generator.
- [ ] Route new-request submission validation through the shared generator.
- [ ] Route request preference updates through the shared generator.
- [ ] Preserve linked-rebooking appointment exclusion.
- [ ] Verify that both availability endpoints agree for identical inputs.
- [ ] Preserve pending requests as non-binding.
- [ ] Preserve the current HTTP response schemas.

## Phase 2: Remove surfaces and code

- [ ] Delete the Filament Optometrist Hours page.
- [ ] Delete its page test.
- [ ] Update Schedule Overrides wording and navigation order.
- [ ] Verify administrators and optometrists retain the correct override access.
- [ ] Delete `UpdateProviderHours` and its test.
- [ ] Delete `EvaluateAvailabilityChangeImpact::providerHourChange()`.
- [ ] Remove `AuditEvent::ProviderHoursUpdated` after its final producer is gone.
- [ ] Delete `ProviderHour` and `ProviderHourFactory`.
- [ ] Remove `User::providerHours()`.
- [ ] Remove automatic provider-hour creation from all optometrist factory
  states.
- [ ] Delete `ProviderHoursSeeder` and remove it from `DatabaseSeeder`.
- [ ] Rewrite mixed provider availability tests around the retained behavior.
- [ ] If unreferenced after consolidation, delete `BuildScheduleBlocks`,
  `ScheduleBlock`, and their isolated tests.
- [ ] Update stale provider-hours comments in otherwise retained tests.

## Consumer-removal checkpoint

- [ ] Run a reference scan across `app`, `database`, `resources`, `routes`, and
  `tests`.
- [ ] Confirm only migration-history files mention `provider_hours`.
- [ ] Run focused appointment, request, availability, override, navigation, and
  seeder tests.
- [ ] Confirm no per-slot query growth.
- [ ] Review the consumer release before database contraction.

## Phase 3: Drop storage

- [ ] Recount total, disabled, and non-default provider-hour rows in the target
  environment.
- [ ] Pause if any custom/disabled rows exist and obtain clinic review.
- [ ] Add a new reversible migration; do not edit the old create migration.
- [ ] Remove only provider-hour audit rows and `provider_hours` data.
- [ ] Drop `provider_hours` in `up()`.
- [ ] Recreate its original shape in `down()` and document that rows are not
  recoverable through rollback.
- [ ] Verify up/down/up on a disposable test database.
- [ ] Verify fresh migration and seed on a disposable test database.
- [ ] Confirm users, roles, appointments, clinic hours, and schedule overrides
  are unchanged.

## Phase 4: Reconcile and verify

- [ ] Update `docs/BACKEND_CONTEXT.md` current-state sections.
- [ ] Update availability semantics in `docs/API_CONTRACT.md` without changing
  response shape.
- [ ] Leave historical specifications unchanged.
- [ ] Run the final active-reference scan.
- [ ] Run `vendor/bin/sail bin pint --dirty --format agent`.
- [ ] Run focused test suites.
- [ ] Run `vendor/bin/sail artisan test --compact`.
- [ ] Perform code-quality, security, performance, and migration review.
- [ ] Deploy the consumer release and verify it before the contract release.

## Done when

- [ ] Clinic Hours is the only recurring weekly schedule.
- [ ] Dated provider absences still reduce only affected interval capacity.
- [ ] Both availability APIs and all request validators use one rule.
- [ ] Assigned-provider double booking remains impossible.
- [ ] No recurring provider-hours feature or storage remains.
- [ ] The retained tests cover behavior rather than deleted implementation.
