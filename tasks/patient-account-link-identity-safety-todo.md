# Checklist: Patient-Account Link Identity Safety

**Spec:** `docs/specs/patient-account-link-identity-safety-spec.md`
**Plan:** `tasks/patient-account-link-identity-safety-plan.md`
**Status:** Proposed; awaiting approval

## Phase 1: Foundation

- [ ] Task 1 — Add Patient identity-review schema and model state.
  - [ ] Migration is additive/reversible and does not mutate links.
  - [ ] Server-controlled fields have defaults and casts.
  - [ ] Focused schema/model tests and Pint pass.
- [ ] Task 2 — Implement deterministic identity matcher.
  - [ ] Required, optional-middle, and verified-contact rules are covered.
  - [ ] Results contain safe reason codes only.
  - [ ] Focused matcher tests and Pint pass.
- [ ] Task 3 — Implement canonical locked link action.
  - [ ] Eligible link succeeds with conversation and PII-safe audit.
  - [ ] Ineligible/racing links leave all state unchanged.
  - [ ] Patient link is removed from generic mass assignment.

### Checkpoint A

- [ ] Foundation tests pass and matcher policy is reviewed.
- [ ] No production workflow is migrated before the checkpoint review.

## Phase 2: Dedicated Linking Workflows

- [ ] Task 4 — Guard Patient Link Request approval.
- [ ] Task 5 — Guard authenticated invitation acceptance.
- [ ] Task 6 — Guard registration-with-invitation linking.

### Checkpoint B

- [ ] Link request and invitation success/mismatch/retry tests pass.
- [ ] Mobile mismatch responses expose no candidate or clinic values.

## Phase 3: Operational Linking Workflows

- [ ] Task 7 — Guard Appointment Request resolution atomically.
- [ ] Task 8 — Guard and authorize both direct admin link actions.
- [ ] Task 9 — Remove remaining production link bypasses.

### Checkpoint C

- [ ] All six link paths have positive and atomic negative tests.
- [ ] Only the canonical action establishes non-null Patient links.
- [ ] Existing unlink and active-link route behavior remains green.

## Phase 4: Post-Link Drift and Review

- [ ] Task 10 — Add race-safe linked first/last-name step-up.
- [ ] Task 11 — Flag drift from account and verified-contact changes.
- [ ] Task 12 — Flag Patient-edit drift and add staff resolution.

### Checkpoint D

- [ ] Identity drift remains linked and retains clinical access.
- [ ] Review is deduplicated, PII-safe, and never auto-cleared.
- [ ] Staff resolution requires current compatibility; unlink remains explicit.

## Phase 5: Rollout and Reconciliation

- [ ] Task 13 — Add dry-run/idempotent existing-link audit command.
- [ ] Task 14 — Reconcile API/context docs and complete verification.

### Checkpoint E

- [ ] Focused suites pass.
- [ ] Full `vendor/bin/sail artisan test --compact` passes.
- [ ] `vendor/bin/sail bin pint --dirty --format agent` is clean.
- [ ] `git diff --check` passes.
- [ ] Dry-run reconciliation totals are reviewed before `--mark-review`.
- [ ] Spec, plan, checklist, API contract, and backend context agree.

## Approval

- [ ] Project owner approves the spec and plan.
- [ ] Project owner authorizes implementation to resume at Task 1.
