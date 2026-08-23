# AR Asset Admin Studio Checklist

**Status:** Draft for owner review — 2026-08-23

- Spec: `docs/specs/ar-asset-admin-studio-spec.md`
- Plan: `tasks/ar-asset-admin-studio-plan.md`

Implementation must not start until the owner approves this plan and checklist.

## Phase 1: Protect and Simplify the Domain Workflow

- [ ] Task 1: Characterize the approved lifecycle and patient boundary
  - [ ] Pin the existing patient `ar` contract and replacement safety.
  - [ ] Express same-actor upload/approval/publication with separate audits.
  - [ ] Express correctable invalid calibration behavior.
- [ ] Task 2: Make lifecycle primitives safe for one operator
  - [ ] Remove only the uploader/approver inequality guard.
  - [ ] Leave structurally valid candidates quarantined on calibration errors.
  - [ ] Delete orphan quarantine objects after database failure.
- [ ] Task 3: Coordinate one-step validation and publication
  - [ ] Preflight before creating a candidate.
  - [ ] Advance new or resumed candidates through existing actions.
  - [ ] Keep approved failures retryable and the old pointer active.

### Checkpoint 1

- [ ] Focused AR lifecycle and patient contract tests pass.
- [ ] Same-actor audit attribution and failure recovery are verified.
- [ ] Lifecycle diff is reviewed before Filament exposure.

## Phase 2: Establish Secure Preview and Frontend Foundations

- [ ] Task 4: Add the authenticated private-candidate preview
  - [ ] Enforce the role/state/missing-object matrix.
  - [ ] Emit inline GLB, no-store, and nosniff headers.
  - [ ] Accept no client-selected storage coordinate.
- [ ] Task 5: Register the exact page-scoped Three.js bundle
  - [ ] Lock production dependency exactly to `three@0.185.1`.
  - [ ] Register a dedicated Vite ES-module entry through Filament.
  - [ ] Keep unrelated admin pages a runtime no-op.
- [ ] Task 6: Build the shared calibration state adapter
  - [ ] Prefill available physical measurements without a preset.
  - [ ] Test normalization and transform conversion boundaries.
  - [ ] Test reset and bounded undo/redo snapshots.

### Checkpoint 2

- [ ] Private preview authorization and header tests pass.
- [ ] Lockfile and production dependency audit are reviewed.
- [ ] Node tests and Vite build pass.

## Phase 3: Deliver the Preview Workspace

- [ ] Task 7: Create the authorized variant-scoped studio page
  - [ ] Replace seven row actions with one `Manage 3D model` entry.
  - [ ] Enforce user, parent/child, frame, and lifecycle access invariants.
  - [ ] Keep the page out of sidebar navigation.
- [ ] Task 8: Render and inspect one GLB in Model mode
  - [ ] Load local, retained-private, and immutable published sources safely.
  - [ ] Add inspection controls, overlays, and bounded diagnostics.
  - [ ] Dispose all resources on replacement and navigation.
- [ ] Task 9: Add the synchronized Reference face calibration view
  - [ ] Build the neutral head from local primitives and show the disclosure.
  - [ ] Synchronize gizmos, sliders, numeric fields, and both modes.
  - [ ] Provide reset, undo/redo, and accessible transform summaries.

### Checkpoint 3

- [ ] Authorized studio routing and navigation tests pass.
- [ ] Both preview modes use one calibration state.
- [ ] Node tests, Vite build, and bounded viewer runtime checks pass.

## Phase 4: Complete the Operator Workflow

- [ ] Task 10: Wire the end-to-end Validate and publish form
  - [ ] Preview one temporary GLB without duplicate permanent storage.
  - [ ] Revalidate calibration and every attestation server-side.
  - [ ] Publish a valid first version or replacement from one action.
- [ ] Task 11: Support resume, retry, discard, and version operations
  - [ ] Resume the single actionable candidate with clear recovery states.
  - [ ] Discard only the candidate without changing the published pointer.
  - [ ] Preserve history, disable, rollback, confirmations, and audits.
- [ ] Task 12: Harden accessibility, responsiveness, errors, and cleanup
  - [ ] Verify keyboard, reduced-motion, dark, 1024 px, and narrow layouts.
  - [ ] Give every failure state a safe, non-leaking next action.
  - [ ] Verify one model/loop and clean navigation teardown.

### Checkpoint 4

- [ ] One authorized operator completes first publish and replacement.
- [ ] Recovery operations preserve the patient-visible asset.
- [ ] Focused PHP/Node tests, Vite build, and browser checks pass.

## Phase 5: Reconcile Documentation and Release Evidence

- [ ] Task 13: Reconcile AR documentation and run the release gate
  - [ ] Update the system context and original AR spec to one-person workflow.
  - [ ] Mark the studio spec implemented only after evidence is complete.
  - [ ] Run the complete focused verification matrix.

### Checkpoint 5

- [ ] Every approved specification success criterion is satisfied.
- [ ] No migration, patient API/Android change, extra dependency, or public
  quarantine exposure was introduced.
- [ ] Route check, focused Pest tests, Node tests, Vite build, npm audit, Pint,
  and browser checks pass.
- [ ] Implementation is ready for owner review and deployment planning.

## Planning Gate

- [x] Feature specification approved.
- [x] Every implementation task has acceptance criteria, verification,
  dependencies, likely files, and an S/M scope in the detailed plan.
- [x] No task is expected to touch more than five files.
- [x] Checkpoints occur after every two to three implementation tasks.
- [ ] Owner reviewed and approved this implementation plan and checklist.
