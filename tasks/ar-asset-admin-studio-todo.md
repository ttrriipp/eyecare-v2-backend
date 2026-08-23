# One-Person AR Asset Publication Checklist

**Status:** Implemented — 2026-08-23

- Spec: `docs/specs/ar-asset-admin-studio-spec.md`
- Plan: `tasks/ar-asset-admin-studio-plan.md`

The owner approved the revised spec, plan, and checklist before implementation.
The server-driven one-person workflow is shipped; browser preview remains
deferred.

## Task 1: Lifecycle safety

- [x] Add failing tests for explicit coordinated self-approval.
- [x] Keep direct uploader self-approval rejected by default.
- [x] Record explicit policy-exception metadata when self-approval is used.
- [x] Keep structurally valid candidates quarantined on calibration errors.
- [x] Delete orphan quarantine objects after database failure.
- [x] Run focused lifecycle tests and create the Task 1 commit.

## Task 2: One-person coordinator

- [x] Add `PublishArAssetCandidate` using existing lifecycle actions.
- [x] Preflight authorization, attestation, calibration, catalog state,
      publication configuration, and candidate count before upload.
- [x] Create a candidate or resume one non-terminal candidate.
- [x] Serialize candidate selection/creation and reject legacy ambiguity.
- [x] Attribute every lifecycle transition to the same authorized actor.
- [x] Keep publication failures approved and the old patient pointer active.
- [x] Prove the patient API contract is unchanged.
- [x] Run focused domain/API tests and create the Task 2 commit.

### Checkpoint 1: Domain review

- [x] Apply `code-review-and-quality` to Tasks 1 and 2.
- [x] Fix all actionable authorization, integrity, concurrency, cleanup, audit,
      and failure-recovery findings.
- [x] Re-run focused tests and Pint.
- [x] Commit checkpoint fixes separately if code changed.

## Task 3: State-aware Filament modal

- [x] Replace the staged happy-path actions with **Manage 3D model**.
- [x] Accept one GLB for a new candidate or resume the one existing candidate.
- [x] Prefill available variant measurements and prioritize saved calibration.
- [x] Keep calibration read-only after the candidate is validated.
- [x] Keep the round-frame preset explicit.
- [x] Require and server-enforce the physical-match attestation.
- [x] Call the coordinator with **Validate & publish**.
- [x] Preserve History, Disable, and Rollback.
- [x] Test authorization, first upload, resume, replacement, errors, and
      retained secondary actions.
- [x] Verify the modal and upload through the Livewire suite; a real-browser
      smoke run remains unavailable because Chrome DevTools MCP is not configured.
- [x] Run focused Filament/domain tests and create the Task 3 commit.

### Checkpoint 2: Filament review

- [x] Apply `code-review-and-quality` to Task 3.
- [x] Fix all actionable form-state, upload, authorization, validation,
      notification, and recovery-UX findings.
- [x] Confirm the server-rendered happy path and failure feedback through
      Filament tests; real-browser confirmation is environment-pending.
- [x] Re-run focused tests and Pint.
- [x] Commit checkpoint fixes separately if code changed.

## Task 4: Context and release evidence

- [x] Update `docs/BACKEND_CONTEXT.md` with the implemented workflow.
- [x] Confirm no preview runtime, route, migration, dependency, patient API,
      Android contract, status, or storage-configuration change was introduced.
- [x] Mark the revised spec, plan, and checklist implemented after the
      evidence passes.
- [x] Run the complete focused test matrix, Pint, and `git diff --check`.
- [x] Create the Task 4 documentation commit.

### Checkpoint 3: Final review

- [x] Apply `code-review-and-quality` to the complete change set.
- [x] Fix every actionable finding and re-run the release gate.
- [x] Update `tasks/plan.md` and `tasks/todo.md` after evidence passes.
- [x] Commit checkpoint fixes separately if code changed.
- [x] Report exact commits and verification results for owner review.

## Planning Gate

- [x] Revised specification defines the one-person workflow and deferred
      preview scope.
- [x] Every task has acceptance criteria, verification, dependencies, likely
      files, scope, and a planned commit.
- [x] No task is expected to touch more than five files.
- [x] Quality-review checkpoints follow domain, Filament, and final work.
- [x] Owner reviewed and approved the revised specification.
- [x] Owner reviewed and approved this implementation plan and checklist.
