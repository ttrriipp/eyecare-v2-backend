# Implementation Plan: One-Person AR Asset Publication

## Status

**Implemented — 2026-08-24.**

This plan superseded the live-preview Admin Studio plan. The owner approved the
revised specification, plan, and checklist before implementation. The
server-driven one-person workflow is now shipped; browser preview remains
deferred. The measured transformed-width adjustment is now implemented without
changing the patient contract or GLB storage lifecycle.

## Outcome

Replace the normal Upload, Submit for review, Approve, and Publish row actions
with one state-aware **Manage 3D model** modal. One active staff member or
administrator can upload or resume a GLB, provide calibration, attest to its
physical match, and run the existing internal lifecycle with one submit.

The work preserves private quarantine, structural validation, immutable public
versions, checksums, audits, version history, disablement, rollback, and the
patient API. It adds no preview runtime, JavaScript module, route, migration, or
dependency.

## Planning Basis

- Revised spec: `docs/specs/ar-asset-admin-studio-spec.md`
- System context: `docs/BACKEND_CONTEXT.md`
- Existing lifecycle: `app/Actions/ArAssets/`
- Existing admin entry point:
  `app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php`
- Existing lifecycle tests: `tests/Feature/RemoteFrameAssetTest.php`
- Existing Filament tests: `tests/Feature/RemoteFrameAssetActionsTest.php`
- Existing patient contract tests: `tests/Feature/Api/V1/FrameCatalogTest.php`

## Current-State Findings

1. The domain already separates upload, structural review, approval, and
   immutable publication. A coordinator can compose those actions without
   duplicating their responsibilities.
2. `ApproveArAsset` currently rejects uploader self-approval. The one-person
   coordinator needs an explicit opt-in while direct approval retains the
   safer default.
3. `SubmitArAssetForReview` currently rejects a candidate when calibration is
   invalid. A structurally valid candidate should remain quarantined so the
   operator can correct and resume it.
4. `UploadArAsset` writes the private object before its database transaction.
   It needs cleanup if the transaction fails.
5. `PublishArAsset` already keeps publication failures approved and retryable,
   and it swaps the patient-facing pointer only after immutable publication.
6. The Variants relation manager already owns calibration fields, history,
   disablement, and rollback. The lean workflow can stay in that class rather
   than adding a dedicated page.

## Architecture Decisions

### Preserve lifecycle actions behind a coordinator

Add `PublishArAssetCandidate` as an application-level coordinator. It performs
known preflight checks, creates or resumes the one actionable candidate, and
delegates transitions to the existing actions.

### Make one-person approval explicit

Add an explicit self-approval option to `ApproveArAsset`. Its default remains
false, preserving the direct action's existing separation-of-duties guard.
Only `PublishArAssetCandidate` opts in after its own authorization and
attestation checks.

### Fail before persistence when possible

Authorization, attestation, calibration, active catalog state, publication
configuration, and candidate-count checks happen before a new upload. Invalid
GLB structure may retain the existing rejected audit record, but it never
reaches public storage.

### Resume instead of multiplying candidates

The coordinator serializes candidate selection and creation per variant. The
modal resumes a single `quarantined`, `validated`, or `approved` candidate.
More than one actionable candidate produces an operator-facing resolution
error; the system never chooses silently.

### Keep the UX server-driven

Use Filament form fields and the existing relation manager. Variant
measurements prefill available calibration dimensions, the round-frame preset
stays explicit, and a required physical-match checkbox is enforced in both the
form and coordinator. No browser preview is part of this plan.

### Adjust renderer scale from measured width

For separated-object models, accept the complete transformed rendered width at
the current scale as an operator input. The server computes the ratio from the
variant's physical `frame_width_mm` and multiplies all three scale axes. This
keeps physical measurements separate from model correction and avoids trusting
client-calculated scale values.

## Dependency Graph

```text
Task 1: lifecycle safety
    -> Task 2: one-person coordinator
        -> Checkpoint 1: domain review
            -> Task 3: state-aware Filament modal
                -> Checkpoint 2: UX review
                    -> Task 4: context and release evidence
                        -> Task 5: measured-width calibration
                            -> Checkpoint 4: calibration review
                                -> Checkpoint 5: final review
```

Tasks are intentionally sequential because each consumes the contract created
by the previous task.

## Task 1: Make lifecycle primitives safe for explicit coordination

Write failing Pest coverage first, then update the existing actions so the new
coordinator can opt into same-actor approval without weakening direct calls.
Keep correctable calibration failures quarantined and clean up an uploaded
private object if its database transaction fails.

**Acceptance criteria:**

- Direct uploader self-approval remains rejected by default.
- An explicit coordination option permits the same authorized actor and still
  records the approval actor, audit event, and explicit policy-exception
  metadata.
- Invalid calibration on a structurally valid candidate leaves it quarantined
  and correctable, with no rejection event.
- A failed upload transaction leaves neither an `ArAsset` row nor an orphaned
  quarantine object.
- Existing authorization, state, lock, and structural validation tests remain
  green.

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/RemoteFrameAssetTest.php`

**Dependencies:** None

**Files likely touched:**

- `app/Actions/ArAssets/ApproveArAsset.php`
- `app/Actions/ArAssets/SubmitArAssetForReview.php`
- `app/Actions/ArAssets/UploadArAsset.php`
- `tests/Feature/RemoteFrameAssetTest.php`

**Estimated scope:** Medium (4 files)

**Commit:** `feat: prepare AR lifecycle for one-person publication`

## Task 2: Add the one-person publication coordinator

Create `PublishArAssetCandidate` using Laravel dependency injection and the
existing AR actions. Test first-publication, replacement, resume, preflight,
attestation, actor attribution, and publication-retry behavior.

**Acceptance criteria:**

- Missing attestation, invalid calibration, inactive catalog state, a missing
  file when no actionable candidate exists, known publication configuration
  failure, or multiple actionable candidates creates no new candidate.
- A new valid upload advances through quarantined, validated, approved, and
  published states using one authorized actor.
- A single existing quarantined, validated, or approved candidate resumes from
  its current state without a duplicate upload.
- Concurrent attempts cannot create two actionable candidates for one variant;
  legacy ambiguity is rejected rather than resolved implicitly.
- Separate uploaded, validated, approved, and published audit events identify
  the same actor, and the approval event records use of the self-approval
  exception.
- A publication failure leaves the candidate approved and retryable and keeps
  the previous patient-facing pointer unchanged.
- The patient frame response remains unchanged.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/RemoteFrameAssetTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameCatalogTest.php
```

**Dependencies:** Task 1

**Files likely touched:**

- `app/Actions/ArAssets/PublishArAssetCandidate.php`
- `tests/Feature/RemoteFrameAssetTest.php`
- `tests/Feature/Api/V1/FrameCatalogTest.php`

**Estimated scope:** Medium (3 files)

**Commit:** `feat: coordinate one-person AR asset publication`

## Checkpoint 1: Domain workflow review

Apply `code-review-and-quality` across Tasks 1 and 2 and fix every actionable
finding before continuing.

- Review authorization, policy opt-in, state transitions, locking, storage
  cleanup, transaction boundaries, and audit attribution.
- Review candidate ambiguity and every partial-failure path.
- Run the focused lifecycle and patient contract tests.
- Run `vendor/bin/sail bin pint --dirty --format agent`.
- Commit checkpoint fixes separately if the review changes code.

## Task 3: Replace the staged row actions with one state-aware modal

Refactor the Variants relation manager around one **Manage 3D model** action.
The action accepts a new GLB only when no actionable candidate exists, resumes
one candidate otherwise, exposes the existing calibration contract, requires
physical-match attestation, and calls the coordinator.

**Acceptance criteria:**

- Authorized active staff/admin see one primary action; patients, guests,
  inactive users, and optometrist-only users cannot invoke it.
- With no candidate, the modal requires one `.glb` upload up to the existing
  10 MiB limit; permanent storage remains in private quarantine.
- With one actionable candidate, the modal resumes it and does not accept a
  second hidden upload.
- Existing candidate calibration takes precedence; otherwise available lens,
  bridge, and temple measurements prefill from the variant.
- Calibration is editable for new or quarantined candidates and read-only for
  validated or approved candidates, which use their persisted values.
- The reviewed round-frame preset is available only by explicit selection.
- A required physical-match checkbox and **Validate & publish** submit are
  present and enforced.
- Separate Submit, Approve, and Publish happy-path row actions are removed.
- History, Disable, and Rollback remain accessible with their existing guards.
- Success and failure notifications describe the resulting state and safe next
  action without exposing storage paths.
- A real-browser smoke check confirms that the modal opens, accepts a GLB,
  submits once, and shows a useful result without console or network errors.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/RemoteFrameAssetActionsTest.php
vendor/bin/sail artisan test --compact tests/Feature/RemoteFrameAssetTest.php
```

**Dependencies:** Checkpoint 1

**Files likely touched:**

- `app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php`
- `tests/Feature/RemoteFrameAssetActionsTest.php`

**Estimated scope:** Medium (2 files)

**Commit:** `feat: simplify AR asset management to one modal`

## Checkpoint 2: Filament workflow review

Apply `code-review-and-quality` to Task 3 and fix every actionable finding.

- Review form hydration/dehydration, temporary upload handling, server-side
  authorization, state-aware visibility, validation messages, and recovery UX.
- Confirm retained history, disablement, and rollback behavior.
- Run the focused Filament and lifecycle tests.
- Run `vendor/bin/sail bin pint --dirty --format agent`.
- Commit checkpoint fixes separately if the review changes code.

No Node, Vite, or browser-preview verification is required because this plan
adds no frontend runtime code. A real-browser smoke check of the Filament modal
would be useful, but Chrome DevTools MCP is not configured in the implementation
environment. The Livewire modal suite is the recorded verification fallback;
the absence of a browser smoke run is documented rather than claimed as passed.

## Task 4: Reconcile system context and release evidence

Update the backend context and planning records to describe the implemented
one-person workflow and its deliberate lack of browser preview. Record the
final focused verification evidence and implementation commits.

**Acceptance criteria:**

- `docs/BACKEND_CONTEXT.md` accurately describes the operator workflow,
  security boundaries, recovery behavior, and patient contract.
- The specification and checklists are marked implemented only after all
  acceptance criteria pass.
- No route, migration, npm dependency, patient API shape, Android contract,
  status value, or public-storage configuration changed.
- The final verification matrix passes.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/RemoteFrameAssetTest.php
vendor/bin/sail artisan test --compact tests/Feature/RemoteFrameAssetActionsTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameCatalogTest.php
vendor/bin/sail bin pint --dirty --format agent
git diff --check
```

**Dependencies:** Checkpoint 2

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`
- `docs/specs/ar-asset-admin-studio-spec.md`
- `tasks/ar-asset-admin-studio-plan.md`
- `tasks/ar-asset-admin-studio-todo.md`

**Estimated scope:** Small (4 files)

**Commit:** `docs: record one-person AR publication workflow`

**Result:** Implemented. The context, spec, checklist, and release evidence
were reconciled after the focused verification matrix passed.

## Task 5: Apply measured transformed-width calibration

Add an optional measured rendered-width field to the editable portion of the
Manage 3D model modal. The value represents the complete frame width after all
relevant GLB node/world transforms, measured while the current renderer scale
is applied. The coordinator validates the value and uniformly adjusts the
scale vector using `frame_width_mm / measured_rendered_width_mm` before upload
or quarantined-candidate resumption.

**Acceptance criteria:**

- The measurement is validated as a finite positive number and creates no new
  candidate when invalid.
- A measured width of 123 mm leaves the current scale unchanged; a 61.5 mm
  width doubles all scale axes while preserving non-uniform proportions.
- Physical calibration dimensions remain unchanged and the measurement is not
  persisted as a second physical dimension.
- Validated, approved, and published assets cannot be adjusted in place; a
  replacement/version remains required.
- Patient API output and immutable publication behavior remain unchanged.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/RemoteFrameAssetTest.php tests/Feature/RemoteFrameAssetActionsTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameCatalogTest.php
vendor/bin/sail bin pint --dirty --format agent
git diff --check
```

**Dependencies:** Existing one-person publication workflow (Task 1–4)

**Files likely touched:**

- `app/Services/ArAssets/ArCalibration.php`
- `app/Actions/ArAssets/PublishArAssetCandidate.php`
- `app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php`
- `tests/Feature/RemoteFrameAssetTest.php`
- `tests/Feature/RemoteFrameAssetActionsTest.php`

**Estimated scope:** Medium (5 files)

**Commit:** `feat: adjust AR scale from measured rendered width`

**Result:** Implemented. The coordinator and modal now apply the measured-width
ratio only while calibration is editable; tests cover first upload, quarantine
resumption, invalid input, locked states, and the patient contract.

## Checkpoint 4: Measured-width calibration review

Apply `code-review-and-quality` to Task 5. Review unit semantics, transformed
width wording, scale-factor math, validation, state locks, and the unchanged
patient contract. Fix every actionable finding, rerun the focused matrix and
Pint, and commit any review fixes separately.

**Result:** Passed. The review required field-specific measured-width errors;
the implementation now reports invalid adjustment input separately from the
calibration contract.

## Checkpoint 5: Final quality and release review

Apply `code-review-and-quality` to the complete implementation and fix every
actionable finding before handoff.

- Review correctness, security, data integrity, tests, maintainability, and
  scope compliance.
- Re-run the complete focused verification matrix and Pint.
- Update `tasks/plan.md` and `tasks/todo.md` only after the final evidence
  confirms the project is implemented.
- Confirm the worktree contains no unintended generated or unrelated files.
- Commit checkpoint fixes separately if the review changes code.
- Report the exact commits and test evidence for owner review.

**Result:** Passed after the final 57-test focused matrix, Pint, and diff check.

## Risks and Mitigations

| Risk | Mitigation |
| --- | --- |
| One request stops after creating or approving a candidate | Resume from the persisted non-terminal state; publication failure remains approved and retryable. |
| Two actionable candidates exist due to legacy or concurrent activity | Lock and count candidates; block with a resolution message instead of choosing silently. |
| One operator approves the wrong physical model | Require explicit physical comparison attestation, show variant identity and measurements, preserve history and rollback. |
| No live preview makes calibration less intuitive | Prefill known variant measurements, keep the reviewed preset explicit, use clear units and grouped fields. |
| Upload succeeds but database persistence fails | Delete the newly written quarantine object in the failure path. |
| Replacement publication fails | Keep the previous published pointer unchanged and the candidate approved for retry. |
| Modal becomes cognitively dense | Use one linear form with grouped file, calibration, and confirmation sections and state-specific helper text. |

## Definition of Done

- Every task acceptance criterion and checklist item is satisfied.
- Each task has its own commit; checkpoint fixes have separate commits when
  needed.
- Every checkpoint completes `code-review-and-quality` and resolves actionable
  findings.
- Focused Pest tests pass and Pint reports clean formatting.
- One active staff/admin can publish a first or replacement GLB without a
  second account.
- Existing security, recovery, audit, history, disablement, rollback, and
  patient API contracts remain intact.
- No deferred preview infrastructure or dependency is introduced.

## Approval Gate

- [x] Owner approves the revised specification.
- [x] Owner approves this implementation plan and checklist.
- [x] Implementation began after both approvals were explicit.
