# Checklist: Patient-Account Link Identity Safety

**Spec:** `docs/specs/patient-account-link-identity-safety-spec.md`
**Plan:** `tasks/patient-account-link-identity-safety-plan.md`
**Status:** Implemented; checkpoint verification complete; full merge run pending (2026-09-19)

## Phase 1: Foundation

- [x] Task 1 — Add Patient identity-review schema and model state.
  - [x] Migration is additive/reversible and does not mutate links.
  - [x] Server-controlled fields have defaults and casts.
  - [x] Focused schema/model tests and Pint pass.
- [x] Task 2 — Implement deterministic identity matcher.
  - [x] Required, optional-middle, and verified-contact rules are covered.
  - [x] Results contain safe reason codes only.
  - [x] Focused matcher tests and Pint pass.
- [x] Task 3 — Implement canonical locked link action.
  - [x] Eligible link succeeds with conversation and PII-safe audit.
  - [x] Ineligible/racing links leave all state unchanged.
  - [x] Patient link is removed from generic mass assignment.

### Checkpoint A — Complete

- [x] Foundation tests pass and matcher policy is reviewed.
- [x] No production workflow is migrated before the checkpoint review.

## Phase 2: Dedicated Linking Workflows

- [x] Task 4 — Guard Patient Link Request approval.
- [x] Task 5 — Guard authenticated invitation acceptance.
- [x] Task 6 — Guard registration-with-invitation linking.

### Checkpoint B — Complete

- [x] Link request and invitation success/mismatch/retry tests pass.
- [x] Mobile mismatch responses expose no candidate or clinic values.

## Phase 3: Operational Linking Workflows

- [x] Task 7 — Guard Appointment Request resolution atomically.
- [x] Task 8 — Guard and authorize both direct admin link actions.
- [x] Task 9 — Remove remaining production link bypasses.

### Checkpoint C — Complete

- [x] All six link paths have positive and atomic negative tests.
- [x] Only the canonical action establishes non-null Patient links.
- [x] Existing unlink and active-link route behavior remains green.

## Phase 4: Post-Link Drift and Review

- [x] Task 10 — Add race-safe linked first/last-name step-up.
- [x] Task 11 — Flag drift from account and verified-contact changes.
- [x] Task 12 — Flag Patient-edit drift and add staff resolution.

### Checkpoint D — Complete

- [x] Identity drift remains linked and retains clinical access.
- [x] Review is deduplicated, PII-safe, and never auto-cleared.
- [x] Staff resolution requires current compatibility; unlink remains explicit.

## Phase 5: Rollout and Reconciliation

- [x] Task 13 — Add dry-run/idempotent existing-link audit command.
- [x] Task 14 — Reconcile API/context docs and complete verification.

### Checkpoint E — Focused verification complete; full merge run pending

- [x] Focused suites pass.
- [ ] Full `vendor/bin/sail artisan test --compact` passes (final run pending).
- [x] `vendor/bin/sail bin pint --dirty --format agent` is clean.
- [x] `git diff --check` passes.
- [x] Dry-run reconciliation totals are reviewed before `--mark-review`.
- [x] Spec, plan, checklist, API contract, and backend context agree.

## Approval

- [x] Project owner approves the spec and plan.
- [x] Project owner authorizes implementation to resume at Task 1.
