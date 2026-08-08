# Implementation Plan: Panel Role Model

Status: Approved
Spec status: Approved 2026-08-08
Plan approved: 2026-08-08

## Overview

Implement the approved role contract in
`docs/specs/panel-role-model-spec.md`: three fixed Filament panel roles
(`admin`, `optometrist`, and `staff`), an exclusive mobile-only `patient`
role, and one supported multi-role combination (`admin + optometrist`).

The work replaces `users.role_id` and `users.is_optometrist` with a fixed
many-to-many assignment model. It preserves patient API contracts and user
IDs, centralizes authorization, migrates existing owner-optometrists without
splitting accounts, and ensures plain administrators cannot perform clinical
actions.

## Architecture Decisions

1. **Use an additive-then-contract migration.** First create and backfill
   `role_user` while retaining legacy columns. Remove `role_id` and
   `is_optometrist` only after all callers, factories, and seeders use the new
   contract. This reduces MySQL DDL risk and permits verification before the
   destructive step.

2. **Keep four fixed role records without a permission package.** Role names
   and valid combinations remain application constants and validation rules.
   No dynamic permission tables or user-defined roles are introduced.

3. **Compose dual-duty access as a union.** `admin + optometrist` receives
   both roles' permissions. Neither role silently implies the other, and no
   owner-specific flag or one-owner constraint exists.

4. **Centralize role checks on `User`.** Policies, actions, Filament pages,
   API guards, scopes, and queries use `hasRole()`, `isAdmin()`,
   `isOptometrist()`, `isPatient()`, and `hasPanelRole()` rather than raw role
   strings, `role_id`, or `is_optometrist`.

5. **Centralize runtime assignment changes in one action.** Team Accounts
   creation/editing validates role sets, protects the current and last active
   administrator, synchronizes the pivot, refreshes loaded relations, and
   audits old/new role names. Filament relationship auto-sync must not bypass
   this action.

6. **Preserve the mobile contract explicitly.** Patient creation attaches the
   exclusive `patient` role, patient-only guards call `isPatient()`, and
   existing mobile resources continue returning `role: "patient"`. No mobile
   endpoint gains panel access.

7. **Authorize twice for sensitive workflows.** Filament visibility improves
   usability, but clinical and administrative actions must enforce roles at
   the policy/action boundary as well.

8. **Do not rewrite historical specs or migrations.** Older documents remain
   historical records of the superseded capability design. The new forward
   migrations, approved spec, ADR, and `BACKEND_CONTEXT.md` describe the new
   runtime contract.

## Dependency Graph

```text
Approved role specification
    |
    v
Additive role_user migration + deterministic backfill
    |
    v
User/Role many-to-many contract + centralized helpers
    |
    +--> Validated/audited role-assignment action
    |
    +--> Factories + fixed role seeding
    |       |
    |       +--> Patient account creation and mobile role compatibility
    |       +--> Common policies and operational actions
    |       +--> Provider and clinical workflows
    |       +--> Filament clinical/availability authorization
    |       +--> Team Accounts role management
    |       +--> Demo/canonical seeders
    |
    v
Repository-wide legacy-reference cleanup and regression tests
    |
    v
Contraction migration removes role_id/is_optometrist
    |
    v
Documentation + full verification
```

## Implementation Phases

### Phase 1: Additive Foundation

- Task 1: Add and verify the additive `role_user` migration.
- Task 2: Add many-to-many model relationships and centralized role helpers.
- Task 3: Add validated and audited runtime role synchronization.
- Task 4: Update fixed-role seeding and factory states.

#### Checkpoint: Foundation

- The pivot contains deterministic assignments for every legacy state.
- Existing legacy columns are still present.
- Role helpers and scopes pass focused tests for all approved role sets.
- Invalid role combinations are rejected.

### Phase 2: Application Authorization Contract

- Task 5: Move patient account creation to role assignments.
- Task 6: Preserve patient API guards and serialized role values.
- Task 7: Update common operational policies.
- Task 8: Update patient, privacy, and prescription policies.
- Task 9: Update operational workflow action authorization.
- Task 10: Update reports, notification recipients, and conversation routing.

#### Checkpoint: Application Contract

- Patient authentication and API contract tests pass unchanged externally.
- Staff, optometrist, admin, and dual-role policy matrix tests pass.
- Common operations include all panel roles.
- Admin-only operations exclude optometrist-only and staff-only accounts.

### Phase 3: Provider and Clinical Workflows

- Task 11: Replace provider eligibility checks in appointment actions.
- Task 12: Enforce explicit optometrist authority in clinical actions.
- Task 13: Update Encounter Filament authorization.
- Task 14: Update Prescription Filament authorization.
- Task 15: Apply clinic-wide versus own-provider availability boundaries.

#### Checkpoint: Clinical Separation

- A plain administrator cannot start/complete encounters or author
  prescriptions.
- An optometrist can complete clinical workflows.
- A dual-role owner can complete both admin and clinical workflows using one
  user ID.
- Provider selectors exclude inactive/non-optometrist users.

### Phase 4: Team Account Management and Demo Data

- Task 16: Build the Team Accounts role-assignment form and save flow.
- Task 17: Update Team Accounts listing, filtering, and lifecycle safeguards.
- Task 18: Update the canonical demo accounts.
- Task 19: Update secondary/demo workflow seeders.

#### Checkpoint: Account Administration

- Only admins can manage Team Accounts.
- The UI can submit only approved role sets.
- Self-role and last-active-admin protections hold.
- Role changes contain auditable old/new role names.
- Demo data includes staff, optometrist, dual-role owner, and patient.

### Phase 5: Compatibility Cleanup and Contract Migration

- Task 20: Update patient and appointment regression fixtures.
- Task 21: Update Filament patient-link regression fixtures.
- Task 22: Update remaining domain and end-to-end fixtures.
- Task 23: Update remaining patient/model/rating fixtures.
- Task 24: Remove remaining legacy role reads/writes from application code.
- Task 25: Add and verify the contraction migration.

#### Checkpoint: Legacy Removal

- Static searches find no active `role_id`, `is_optometrist`,
  `hasOptometristCapability()`, singular `role` relationship, or legacy
  `whereHas('role', ...)` usage outside historical migrations/docs and
  deliberate migration tests.
- Fresh migrations and upgrade migrations both produce the target schema.
- `users` no longer contains `role_id` or `is_optometrist`.
- Every user has one valid role set in `role_user`.

### Phase 6: Documentation and Release Verification

- Task 26: Record the decision and update canonical backend context.
- Task 27: Run formatting, focused suites, the full suite, and final static
  checks.

#### Checkpoint: Complete

- Every success criterion in the approved spec is met.
- Focused and full Pest suites pass.
- Modified PHP files pass Pint.
- Documentation matches runtime behavior.
- No unrelated worktree change has been overwritten.

## Verification Strategy

1. Run the task-specific Pest file or filter after each task.
2. Run checkpoint suites after every phase rather than waiting for the end.
3. Use a dedicated migration test to prove all five legacy mappings and
   invalid-data failure before dropping columns.
4. Use a role permission matrix test for common, clinical, and admin-only
   behavior.
5. Run targeted Filament tests for Team Accounts, Encounters, Prescriptions,
   and Availability.
6. Run patient authentication and API contract suites to prove no external
   mobile contract change.
7. Before the contraction migration, run repository-wide static searches for
   every legacy symbol.
8. Run `vendor/bin/sail bin pint --dirty --format agent`, then the focused
   suite and `vendor/bin/sail artisan test --compact`.

## Sequential and Parallel Work

### Must remain sequential

- Tasks 1–4 establish the schema and domain contract.
- Task 3 must precede Team Accounts save-flow work.
- Tasks 5–24 must complete before Task 25 removes legacy columns.
- Documentation and final verification follow the target schema.

### Independent after the foundation

After Tasks 1–4, the policy, patient API, operational action, clinical UI,
availability, and seeder workstreams are logically independent. They may be
worked in separate sessions, but shared edits to `User`, shared role matrix
tests, and the dirty `ClinicWorkflowSeeder.php` require coordination.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| MySQL DDL leaves a partial migration | High | Use additive/backfill and contraction migrations; validate all assignments before dropping columns |
| Pivot changes bypass the existing `UserObserver` | High | Route runtime changes through one transactional synchronization action that writes the audit event |
| A plain admin retains a raw boolean clinical bypass | High | Central helper/action guards, role matrix tests, and a zero-reference static scan |
| Patient API resources assume one singular role | High | Serialize the exclusive patient role explicitly and run auth/contract regression suites |
| Filament relationship auto-sync accepts invalid combinations | High | Use a constrained role-set input and custom save hooks backed by the synchronization action |
| Removing an admin role locks out the clinic | High | Block self-role mutation and removal/deactivation of the last active admin; test both |
| Removing optometrist rewrites or hides history | Medium | Change only pivot assignments; preserve all existing user foreign keys and historical records |
| Factories cannot attach roles until a model is persisted | Medium | Use explicit post-creation role attachment and ensure tests requiring authorization use `create()`, not unsaved `make()` models |
| Role relation caches remain stale after sync | Medium | Unset/reload `roles` after synchronization and test immediate authorization behavior |
| Broad singular-role usage is missed | Medium | Search for relationship loading, serialization, raw strings, `role_id`, and `is_optometrist` before contraction |
| Existing dirty `ClinicWorkflowSeeder.php` is overwritten | High | Inspect and integrate with the current working copy; never replace or revert unrelated edits |
| Legacy historical specs contradict the new model | Low | Leave them historical; add an accepted ADR and update only canonical current context |

## Open Questions

None. Any discovery that requires a role combination, permission, patient API
change, credential field, dependency, or owner-specific rule outside the
approved spec returns to the specification phase for approval.
