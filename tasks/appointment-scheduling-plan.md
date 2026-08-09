# Implementation Plan: Practical Variable-Duration Appointment Scheduling

Status: Approved
Spec: `docs/specs/appointment-scheduling-redesign-spec.md`
Spec approved: 2026-08-08
Plan approved: 2026-08-08
Implementation: Not started

## Overview

Implement the approved appointment-scheduling redesign as a sequence of
testable vertical slices. The work adds patient-visible appointment-type
metadata, variable-duration request availability, ordered time preferences,
optional provider preference, referral context, provider-aware confirmation,
admin configuration, and consistent staff scheduling.

The implementation preserves the existing request-first workflow, linked and
unlinked identity resolution, historical appointment duration snapshots,
appointment statuses, and mobile ownership protections. It deliberately
removes pending request capacity holds and replaces the fixed 30-minute request
assumption with the selected type's provisional duration.

## Architecture Decisions

1. **Keep the existing request and appointment aggregates.** An
   `AppointmentRequest` remains tentative patient intent and converts to one
   confirmed `Appointment` only after review. The redesign does not merge the
   two tables or duplicate clinical encounter data.

2. **Extend the API contract instead of replacing request routes.** Restore
   `GET /appointment-types`, add a patient-safe provider endpoint, and modify
   the existing availability and request endpoints. Preserve existing response
   fields wherever their meaning remains valid.

3. **Use a coordinated v1 cutover for the required type.** New submissions
   require `appointment_type_id`; the server never silently defaults a
   missing value. Backend, API documentation, and Android must be released as
   one coordinated contract change.

4. **Separate internal and patient-facing type labels.** Keep
   `appointment_types.name` for clinic terminology and add a patient label,
   description, and visibility flag for the mobile catalog.

5. **Keep legacy request rows readable.** The existing nullable request
   `appointment_type_id` remains nullable at the database level. New Form
   Requests require it, while old rows without a type continue through the
   existing staff-classification path.

6. **Use request snapshots for scheduling correctness.** Submission copies the
   selected type's duration to `provisional_duration_minutes`; confirmation
   copies the reviewer-approved duration to `appointments.duration_minutes`.
   Later default changes never rewrite either historical snapshot.

7. **Represent the two alternatives as bounded JSON.** The primary preference
   remains `scheduled_at` for compatibility. A nullable JSON array stores at
   most two ordered alternatives because they are always loaded with the
   request, never independently searched, and do not justify another aggregate
   table in this MVP.

8. **Make pending requests non-blocking.** Submission validates that every
   preference is currently viable but creates no schedule reservation.
   Availability and confirmed scheduling consider blocking appointments, not
   request holds.

9. **Evaluate provider capacity per candidate interval.** Reuse the shared
   availability engine, but calculate eligible provider capacity for each
   start/end interval so partial shifts and absences remain accurate.

10. **Assign a provider when a request becomes an appointment.** A preferred
    provider is a patient preference only. The reviewer selects the final
    active optometrist, and acceptance verifies that exact provider under the
    schedule-date lock.

11. **Keep request timing semantics explicit.** `review_due_at` is the next
    open clinic day's closing time and only drives an overdue indicator.
    `expires_at` becomes the latest submitted preference for new requests and
    drives the existing terminal expiry command.

12. **Reuse referral configuration instead of hardcoding Referral.** Conditional
    validation reads `requires_referral`; request referral source is encrypted
    and copied to the existing confirmed-appointment field on acceptance.

13. **Put appointment-type configuration under Availability.** A new
    administrator-only Filament resource owns type labels, visibility,
    duration, referral requirement, and activation. Request review remains a
    shared panel operation.

14. **Standardize scheduled starts on the clinic grid.** Mobile and normal
    staff-created scheduled appointments use the same cadence. Walk-ins remain
    exempt; an off-grid override is not part of this implementation.

15. **Use test-first, additive migrations.** Add characterization or failing
    behavior tests before each slice, use reversible additive columns, preserve
    clinic-customized durations, and run focused checkpoints before broad
    verification.

## Dependency Graph

```text
Approved scheduling specification
    |
    v
Characterization tests
    |
    +--> Appointment-type metadata migration/model
    |        |
    |        +--> Canonical type transition
    |        +--> Patient type catalog API
    |        +--> Admin type configuration
    |
    +--> Appointment-request preference migration/model
             |
             +--> Patient-safe provider catalog
             +--> Per-interval provider availability correction
                       |
                       v
             Variable-duration request availability API
                       |
                       v
             Request submission validation + snapshots
                       |
                       v
             Expanded request reads + expiry semantics
                       |
                       v
             Atomic provider-assigned acceptance
                       |
                       v
             Filament request review and queue
                       |
                       v
             Staff scheduled-create/edit alignment
                       |
                       v
             API/context docs + full verification
```

## Implementation Phases

### Phase 1: Characterization and Additive Data Foundation

- Task 1: Characterize preserved scheduling and request invariants.
- Task 2: Add patient-visible appointment-type metadata.
- Task 3: Add request preference and review fields.
- Task 4: Transition the canonical appointment-type catalog.

#### Checkpoint: Data Foundation

- Fresh and upgrade migrations both succeed.
- Existing appointment duration snapshots are unchanged.
- Legacy type-less requests remain readable.
- The six canonical types are idempotent and clinic-customized durations survive.
- Focused model, migration, and seeder tests pass.

### Phase 2: Patient Discovery and Provider-Aware Availability

- Task 5: Restore the patient appointment-type catalog endpoint.
- Task 6: Add the patient-safe optometrist catalog endpoint.
- Task 7: Correct shared availability to evaluate exact provider intervals.
- Task 8: Make request availability variable-duration and non-blocking.

#### Checkpoint: Discovery and Availability

- The type and provider catalogs expose only patient-safe active records.
- A 45-minute type produces starts every 15 minutes and 45-minute ends.
- A preferred provider constrains availability to that provider.
- Partial provider schedules are calculated per interval.
- Pending requests do not hide slots.
- Focused API and availability suites pass.

### Phase 3: Request Submission, Reads, and Expiration

- Task 9: Define the expanded request-submission boundary.
- Task 10: Persist request snapshots without capacity holds.
- Task 11: Centralize and expand patient request responses.
- Task 12: Align expiry and active-request semantics.

#### Checkpoint: Patient Request Flow

- Missing type and invalid preferences fail predictably.
- One primary plus up to two alternatives round-trip in order.
- Referral source is conditionally required and encrypted at rest on requests.
- Pending requests create no capacity hold.
- Review due and latest-preference expiry are independently correct.
- Existing identity, ownership, throttling, and active-limit tests pass.

### Phase 4: Atomic Confirmation and Filament Review

- Task 13: Make request acceptance provider-assigned and concurrency-safe.
- Task 14: Enforce referral and outside-preference confirmation rules.
- Task 15: Build the complete Filament request-review action.
- Task 16: Align the request queue with tentative-demand semantics.

#### Checkpoint: Staff Confirmation

- Every request-created appointment has a final active optometrist.
- Concurrent acceptance cannot overbook or resolve a request twice.
- Reclassification and duration override recheck the full interval.
- Referral and outside-preference contact rules are enforced transactionally.
- Staff, optometrist, and admin can review; patient accounts cannot.
- Failed confirmation leaves the request pending and editable.

### Phase 5: Admin Configuration and Staff Scheduling

- Task 17: Add the admin-only Appointment Types resource shell.
- Task 18: Add appointment-type create/edit/deactivate forms.
- Task 19: Align staff scheduled creation with type defaults and the grid.
- Task 20: Revalidate schedule-defining appointment edits.

#### Checkpoint: Panel Scheduling

- Only admins manage appointment-type configuration.
- Active/internal/patient-visible distinctions work in their intended surfaces.
- Staff-created scheduled appointments snapshot variable duration and obey the grid.
- Referral fields are conditionally required without hardcoded type names.
- Pre-check-in schedule-defining edits lock and revalidate.
- Walk-ins and checked-in restrictions retain their approved behavior.

### Phase 6: Contract Reconciliation and Release Verification

- Task 21: Reconcile configuration and canonical documentation.
- Task 22: Run final formatting, static checks, and regression suites.

#### Checkpoint: Complete

- Every approved success criterion is demonstrated by tests or contract checks.
- Fresh and upgrade migration paths pass.
- API routes and response documentation match runtime behavior.
- Focused and full Pest suites pass.
- Modified PHP passes Pint.
- No unrelated worktree changes are overwritten.
- Implementation is ready for human review, not automatically committed or deployed.

## Verification Strategy

1. Write or update the narrow behavior test before each implementation slice.
2. Run the task's focused Pest file immediately after the change.
3. Run checkpoint suites after every phase, especially after shared
   availability and acceptance changes.
4. Test both generic capacity and provider-specific capacity with overlapping
   intervals, partial shifts, absences, and unassigned appointments.
5. Test fresh migrations and a representative upgrade state containing
   historical appointments, customized type duration, and a legacy type-less
   request.
6. Assert API response allowlists so identity snapshots and private staff data
   cannot leak as new relationships are loaded.
7. Use Filament Livewire tests for authorization and conditional form behavior;
   do not rely only on hidden navigation.
8. Before final handoff, run:
   - `vendor/bin/sail bin pint --dirty --format agent`
   - the focused commands listed in the approved spec
   - `vendor/bin/sail artisan route:list --except-vendor --path=api/v1`
   - `vendor/bin/sail artisan test --compact`

## Sequential and Parallel Work

### Must remain sequential

- Tasks 1-4 establish the schema and domain contract.
- Task 7 precedes request availability, submission, acceptance, and staff
  scheduling because all consume the shared capacity algorithm.
- Tasks 9-12 establish the patient request contract before confirmation UI.
- Tasks 13-14 establish server-side invariants before Filament actions use them.
- Contract documentation follows implemented behavior.

### Logically independent after the foundation

- Tasks 5 and 6 are independent catalog endpoints after their model scopes exist.
- Appointment-type admin configuration can proceed after Task 4 without waiting
  for request submission, provided shared `AppointmentType` edits are coordinated.
- Filament queue presentation can be developed separately from scheduled
  appointment create/edit once request response/model contracts are stable.

No multi-agent execution is assumed. These boundaries primarily support safe
work across focused sessions.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Required type breaks an older Android build | High | Treat as an explicit coordinated cutover; update API contract first in the implementation branch and do not deploy backend alone |
| Shared availability changes regress rescheduling or manual bookings | High | Characterization tests first, one shared interval evaluator, and checkpoint all appointment entry points |
| Capacity is computed for a whole day instead of each interval | High | Add partial-shift and partial-absence tests before refactoring slot generation |
| Two reviewers overbook or resolve one request twice | High | Request row lock plus schedule-date lock, final in-transaction recheck, deadlock retries, and concurrency tests |
| Pending requests still block through a legacy helper | High | Remove request blocks from active availability paths and add static/reference plus behavioral checks |
| JSON alternatives contain duplicate, malformed, or timezone-shifted values | Medium | Normalize at the boundary, cap at two, assert distinct timestamps including the primary, and round-trip ISO-8601 tests |
| Legacy requests have no type or new fields | Medium | Keep columns nullable where required, add fallbacks in resources/review, and test an upgrade fixture |
| Canonical seeding overwrites clinic-customized durations | High | Update old defaults only when values still match the prior canonical values; test custom preservation |
| Preferred provider becomes inactive before review | Medium | Treat preference as non-binding and require selection of a currently active provider at acceptance |
| Referral context leaks through API/logs/notifications | Medium | Encrypted request field, response allowlists, no logging, and privacy assertions |
| Review due calculation mishandles closed days | Medium | Centralize calculation against clinic hours and test weekday closure/override boundaries |
| Filament quick action bypasses required review fields | High | Remove/redirect quick accept and keep invariants in the server-side acceptance action |
| Existing arbitrary-minute staff times conflict with grid enforcement | Medium | Apply the grid to new/changed scheduled records only; do not rewrite historical appointments |
| Dirty demo/seeder work is overwritten | High | Inspect current working copies before implementation and preserve all unrelated changes |
| Plan tasks expand beyond five files | Medium | Split the task during implementation rather than expanding an approved slice silently |

## Open Questions

None. Any implementation discovery requiring a new API compatibility mode,
provider specialization, resource scheduling, off-grid override, document
upload, notification channel, role change, or dependency returns to the
specification phase for approval.

## Implementation Gate

The specification, plan, and task list are approved, but implementation remains
unstarted. Begin only on a separate explicit implementation request.
