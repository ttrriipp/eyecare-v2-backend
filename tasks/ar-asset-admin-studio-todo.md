# One-Person AR Asset Publication Checklist

**Status:** Revised draft for owner review — 2026-08-23

- Spec: `docs/specs/ar-asset-admin-studio-spec.md`
- Plan: `tasks/ar-asset-admin-studio-plan.md`

Implementation must not start until the owner approves this revised spec, plan,
and checklist.

## Task 1: Lifecycle safety

- [ ] Add failing tests for explicit coordinated self-approval.
- [ ] Keep direct uploader self-approval rejected by default.
- [ ] Record explicit policy-exception metadata when self-approval is used.
- [ ] Keep structurally valid candidates quarantined on calibration errors.
- [ ] Delete orphan quarantine objects after database failure.
- [ ] Run focused lifecycle tests and create the Task 1 commit.

## Task 2: One-person coordinator

- [ ] Add `PublishArAssetCandidate` using existing lifecycle actions.
- [ ] Preflight authorization, attestation, calibration, catalog state,
      publication configuration, and candidate count before upload.
- [ ] Create a candidate or resume one non-terminal candidate.
- [ ] Serialize candidate selection/creation and reject legacy ambiguity.
- [ ] Attribute every lifecycle transition to the same authorized actor.
- [ ] Keep publication failures approved and the old patient pointer active.
- [ ] Prove the patient API contract is unchanged.
- [ ] Run focused domain/API tests and create the Task 2 commit.

### Checkpoint 1: Domain review

- [ ] Apply `code-review-and-quality` to Tasks 1 and 2.
- [ ] Fix all actionable authorization, integrity, concurrency, cleanup, audit,
      and failure-recovery findings.
- [ ] Re-run focused tests and Pint.
- [ ] Commit checkpoint fixes separately if code changed.

## Task 3: State-aware Filament modal

- [ ] Replace the staged happy-path actions with **Manage 3D model**.
- [ ] Accept one GLB for a new candidate or resume the one existing candidate.
- [ ] Prefill available variant measurements and prioritize saved calibration.
- [ ] Keep calibration read-only after the candidate is validated.
- [ ] Keep the round-frame preset explicit.
- [ ] Require and server-enforce the physical-match attestation.
- [ ] Call the coordinator with **Validate & publish**.
- [ ] Preserve History, Disable, and Rollback.
- [ ] Test authorization, first upload, resume, replacement, errors, and
      retained secondary actions.
- [ ] Smoke-test the modal and upload in a real browser with no console or
      network errors.
- [ ] Run focused Filament/domain tests and create the Task 3 commit.

### Checkpoint 2: Filament review

- [ ] Apply `code-review-and-quality` to Task 3.
- [ ] Fix all actionable form-state, upload, authorization, validation,
      notification, and recovery-UX findings.
- [ ] Confirm the real-browser happy path and failure feedback.
- [ ] Re-run focused tests and Pint.
- [ ] Commit checkpoint fixes separately if code changed.

## Task 4: Context and release evidence

- [ ] Update `docs/BACKEND_CONTEXT.md` with the implemented workflow.
- [ ] Confirm no preview runtime, route, migration, dependency, patient API,
      Android contract, status, or storage-configuration change was introduced.
- [ ] Mark the revised spec, plan, and checklist implemented only after all
      evidence passes.
- [ ] Run the complete focused test matrix, Pint, and `git diff --check`.
- [ ] Create the Task 4 documentation commit.

### Checkpoint 3: Final review

- [ ] Apply `code-review-and-quality` to the complete change set.
- [ ] Fix every actionable finding and re-run the release gate.
- [ ] Update `tasks/plan.md` and `tasks/todo.md` after evidence passes.
- [ ] Commit checkpoint fixes separately if code changed.
- [ ] Report exact commits and verification results for owner review.

## Planning Gate

- [x] Revised specification defines the one-person workflow and deferred
      preview scope.
- [x] Every task has acceptance criteria, verification, dependencies, likely
      files, scope, and a planned commit.
- [x] No task is expected to touch more than five files.
- [x] Quality-review checkpoints follow domain, Filament, and final work.
- [ ] Owner reviewed and approved the revised specification.
- [ ] Owner reviewed and approved this implementation plan and checklist.
