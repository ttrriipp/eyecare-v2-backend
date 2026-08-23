# Implementation Plan: AR Asset Admin Studio

## Status

**Draft for owner review — 2026-08-23.**

The feature specification is approved. Implementation must not start until this
plan and `tasks/ar-asset-admin-studio-todo.md` are reviewed and approved.

## Overview

Replace the seven row-level AR lifecycle actions with one variant-scoped
Filament workspace where one authorized staff member can select a GLB, inspect
it in Model and Reference face previews, adjust calibration, complete a
physical-match attestation, and validate and publish. Existing private
quarantine, structural validation, immutable versioning, audit events,
publication integrity, disablement, rollback, and patient API contracts remain
in place. No database migration or Android change is planned.

## Planning Basis

- Approved spec: `docs/specs/ar-asset-admin-studio-spec.md`
- System context: `docs/BACKEND_CONTEXT.md`
- Existing lifecycle: `app/Actions/ArAssets/`
- Existing admin entry point:
  `app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php`
- Existing patient contract tests: `tests/Feature/Api/V1/FrameCatalogTest.php`
- Existing AR fixtures and lifecycle tests:
  `tests/Feature/RemoteFrameAssetTest.php` and
  `tests/Feature/RemoteFrameAssetActionsTest.php`

The planning skill's referenced
`.agents/references/definition-of-done.md` file is not present in this
repository. The standing Definition of Done therefore comes from `AGENTS.md`:
focused Pest coverage for every behavior change, Node tests for pure JavaScript,
Vite build verification, dependency audit, browser checks for runtime UI, and
Pint after PHP changes.

## Current-State Findings

1. `UploadArAsset`, `SubmitArAssetForReview`, `ApproveArAsset`, and
   `PublishArAsset` already separate storage, validation, approval, publication,
   locking, and auditing. A coordinator can delegate to them without replacing
   their responsibilities.
2. `ApproveArAsset` explicitly prevents uploader self-approval. That guard and
   its inverse test are the primary two-person policy boundary.
3. Invalid calibration in `SubmitArAssetForReview` currently rejects the
   candidate. The approved flow instead needs the structurally valid candidate
   to remain correctable in quarantine.
4. `UploadArAsset` writes the private quarantine object before its database
   transaction, but does not currently remove that object if the transaction
   fails.
5. `PublishArAsset` already leaves an approved asset retryable when publication
   configuration or storage fails and swaps the variant pointer only after a
   successful immutable publish.
6. The Product resource currently has index, create, and edit pages only. The
   verified Filament 5 generator supports a resource page through `--resource`;
   the implementation command is:
   `vendor/bin/sail artisan make:filament-page ManageVariantArAsset --resource=Product --no-interaction`.
7. Filament 5's version-specific documentation requires imported JavaScript to
   be built as a Vite entry and registered as a module through the asset manager.
8. Livewire temporary preview URLs are image-only, so a newly selected GLB must
   be parsed from its browser `File`/`ArrayBuffer`. Retained private candidates
   need the authenticated preview response in the approved spec.
9. The repository already has an authenticated streamed-file controller pattern
   in `MessageAttachmentPreviewController`, but the AR route needs stricter
   state, role, cache, and password-change controls.

## Architecture Decisions

### 1. Preserve lifecycle actions behind one coordinator

Add `PublishArAssetCandidate` as the orchestration boundary for the final UI
submission. It performs known publication preflight, selects or creates the one
actionable candidate, and delegates each state transition to the existing
actions. It must not duplicate GLB parsing, calibration normalization, version
allocation, storage, checksums, locks, or audit writes.

### 2. Make same-actor approval an explicit policy change

Remove only the uploader/approver inequality guard. All existing role,
active-account, state, and lock checks remain. `uploaded_by`, `approved_by`, and
`published_by` stay separately populated even when they contain the same user
ID.

### 3. Keep correctable candidates non-terminal

Calibration errors detected before upload create no row. Calibration errors on
an existing structurally valid candidate leave it quarantined and correctable.
Structural GLB failures may still create a rejected audit record. Only one
quarantined, validated, or approved candidate may be actionable per variant.

### 4. Stream quarantine assets through a narrow web contract

The preview controller derives the disk and path from the bound `ArAsset`,
authorizes the active staff/admin on every request, restricts previewable states,
and returns no-store, nosniff GLB bytes. It never accepts storage coordinates
from the request and does not add an API endpoint.

### 5. Keep the studio variant-scoped

Use a custom Product resource page with nested Product and Product Variant
context. It has no navigation registration. The Variants relation manager gets
one `Manage 3D model` entry point, while the page owns the happy path and the
secondary history actions.

### 6. Separate PHP mutations from JavaScript rendering

Livewire and domain actions own authorization, validation, storage, and state
transitions. A page-scoped ES module owns local file parsing, Three.js scenes,
camera controls, transform interactions, and browser-only undo/redo. A pure
calibration-state module is the single conversion boundary between form values
and both preview modes.

### 7. Load one exact Three.js build only where needed

Install exact production dependency `three@0.185.1` with scripts disabled,
review the lockfile, and register a dedicated Vite entry as a module. The entry
does no work unless the studio root exists. No CDN, remote model, decoder, or
second 3D framework is introduced.

### 8. Treat accessibility and cleanup as functional requirements

Every gizmo operation has a keyboard-accessible button and numeric alternative.
The viewer owns one scene and render loop, pauses while hidden, and disposes all
models, object URLs, observers, controls, GPU resources, and listeners on
replacement or navigation.

## Dependency Graph

```text
Characterization tests
    -> Safe one-person lifecycle primitives
        -> Publish candidate coordinator
            -> Private candidate preview route
            -> Exact Three.js/Vite registration
                -> Pure calibration state
                    -> Authorized studio page and single entry point
                        -> Model inspection viewer
                            -> Reference-face calibration view
                                -> End-to-end Validate & publish form
                                    -> Resume/history/secondary operations
                                        -> Accessibility/runtime hardening
                                            -> Documentation and release gate
```

Tasks 4 and 5 may proceed independently after Task 3. All other work should
follow the dependency order because the page, Vite entry, and viewer files are
shared integration points.

## Phase 1: Protect and Simplify the Domain Workflow

### Task 1: Characterize the approved lifecycle and patient boundary

Add focused tests before changing behavior. Capture the unchanged patient
response, state/audit attribution, replacement safety, the new same-actor
expectation, and the requirement that invalid calibration remains correctable.

**Acceptance criteria:**

- Tests pin the existing patient `ar` response and immutable replacement
  behavior.
- Tests express one actor as uploader, approver, and publisher while preserving
  distinct audit events.
- Tests express that invalid calibration cannot reject an otherwise valid
  quarantined GLB.

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/RemoteFrameAssetTest.php tests/Feature/RemoteFrameAssetActionsTest.php tests/Feature/Api/V1/FrameCatalogTest.php`

**Dependencies:** None

**Files likely touched:**

- `tests/Feature/RemoteFrameAssetTest.php`
- `tests/Feature/RemoteFrameAssetActionsTest.php`
- `tests/Feature/Api/V1/FrameCatalogTest.php`

**Estimated scope:** Medium (3 files)

### Task 2: Make lifecycle primitives safe for one operator

Remove the self-approval prohibition, keep invalid review calibration
correctable, and remove a private quarantine object if the database transaction
that should own it fails. Preserve all role, status, locking, validation, and
audit boundaries.

**Acceptance criteria:**

- The uploader may approve the validated candidate, with `approved_by` and the
  approval audit attributed to that actor.
- Invalid calibration leaves a structurally valid candidate quarantined with no
  rejection transition or rejection audit.
- A failed upload database transaction leaves no orphaned quarantine object or
  `ArAsset` row.

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/RemoteFrameAssetTest.php tests/Feature/RemoteFrameAssetActionsTest.php`

**Dependencies:** Task 1

**Files likely touched:**

- `app/Actions/ArAssets/ApproveArAsset.php`
- `app/Actions/ArAssets/SubmitArAssetForReview.php`
- `app/Actions/ArAssets/UploadArAsset.php`
- `tests/Feature/RemoteFrameAssetTest.php`

**Estimated scope:** Medium (4 files)

### Task 3: Coordinate one-step validation and publication

Create `PublishArAssetCandidate` to preflight known publication requirements,
reuse or create the one actionable candidate, and advance it through the
existing lifecycle actions according to its current state. Keep an approved
candidate retryable and the old published pointer unchanged after publication
failure.

**Acceptance criteria:**

- Invalid form calibration or known publication configuration failure creates
  no new candidate.
- One call can advance a new or resumed candidate through the applicable
  internal states using the same authorized actor.
- Failure after approval leaves that candidate approved and preserves the
  current published variant pointer.

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/RemoteFrameAssetTest.php tests/Feature/Api/V1/FrameCatalogTest.php`

**Dependencies:** Task 2

**Files likely touched:**

- `app/Actions/ArAssets/PublishArAssetCandidate.php`
- `tests/Feature/RemoteFrameAssetTest.php`
- `tests/Feature/Api/V1/FrameCatalogTest.php`

**Estimated scope:** Medium (3 files)

### Checkpoint 1: Domain workflow

- One active staff/admin account can complete the lifecycle in action tests.
- Invalid calibration remains correctable and storage failures are recoverable.
- Existing GLB validation, replacement safety, audits, and patient contract
  tests pass.
- Review the lifecycle diff before exposing it through Filament.

## Phase 2: Establish Secure Preview and Frontend Foundations

### Task 4: Add the authenticated private-candidate preview

Create the invokable preview controller and named admin web route. Reuse the
panel session and password-change middleware, then independently authorize the
actor and constrain the bound record to a previewable staff state.

**Acceptance criteria:**

- Active staff/admin receive existing private GLB bytes with the specified
  content type, inline disposition, no-store, and nosniff headers.
- Guests, patients, inactive users, and optometrist-only users receive 403;
  disallowed states or missing objects receive 404.
- The request accepts only the bound asset ID and cannot select a disk, path,
  filename, or URL.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/ArAssetPreviewTest.php
vendor/bin/sail artisan route:list --path=ar-assets --except-vendor
```

**Dependencies:** Task 3

**Files likely touched:**

- `app/Http/Controllers/ArAssetPreviewController.php`
- `routes/web.php`
- `tests/Feature/ArAssetPreviewTest.php`

**Estimated scope:** Medium (3 files)

### Task 5: Register the exact page-scoped Three.js bundle

Install `three@0.185.1` as an exact production dependency with install scripts
disabled. Add a dedicated Vite entry and register its compiled module through
Filament's asset manager, with a root-element guard that makes unrelated pages
a no-op.

**Acceptance criteria:**

- `package.json` and the lockfile resolve exactly `three@0.185.1`, with no CDN
  or additional runtime dependency.
- Vite emits the studio entry and Filament registers it as an ES module.
- Importing the module on an unrelated admin page creates no canvas, listener,
  render loop, or network request.

**Verification:**

```text
vendor/bin/sail npm audit --omit=dev
vendor/bin/sail npm run build
```

**Dependencies:** Task 3

**Files likely touched:**

- `package.json`
- `package-lock.json`
- `vite.config.js`
- `app/Providers/AppServiceProvider.php`
- `resources/js/ar-asset-studio/index.js`

**Estimated scope:** Medium (5 files)

### Task 6: Build the shared calibration state adapter

Implement a pure ES-module state adapter for variant prefill, numeric
normalization, presets, transform conversion, reset, and browser-session
undo/redo. This module is the only mapping used by numeric fields, gizmos, and
both scenes.

**Acceptance criteria:**

- Available lens width, lens height, bridge width, and temple length prefill
  without selecting a preset.
- Scale, anchor, and degree/radian conversion reject NaN, infinity, and zero
  scale and round-trip deterministically.
- Reset and bounded undo/redo restore complete calibration snapshots.

**Verification:**

```text
vendor/bin/sail node --test resources/js/ar-asset-studio/*.test.js
vendor/bin/sail npm run build
```

**Dependencies:** Task 5

**Files likely touched:**

- `resources/js/ar-asset-studio/calibration-state.js`
- `resources/js/ar-asset-studio/calibration-state.test.js`
- `resources/js/ar-asset-studio/index.js`

**Estimated scope:** Medium (3 files)

### Checkpoint 2: Secure frontend foundation

- The private route passes its complete role/state/header matrix.
- Three.js is exact-version locked, audited, built, and inactive off-page.
- All calibration-state Node tests pass.
- Review the lockfile and preview authorization before building the page.

## Phase 3: Deliver the Preview Workspace

### Task 7: Create the authorized variant-scoped studio page

Generate and register the custom Product resource page, resolve its nested
variant, enforce the Product/Variant/frame/account invariants, and replace the
row-level lifecycle actions with one `Manage 3D model` link. Render a stable
Blade root and state summary for subsequent viewer slices.

**Acceptance criteria:**

- One row action opens the correct Product/Variant studio route and the seven
  outgoing lifecycle actions are no longer exposed there.
- Active staff/admin can open matching frame variants; all unauthorized or
  mismatched combinations fail without leaking the record.
- The page is absent from sidebar navigation and may show history for inactive
  records while preventing new publication.

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/Filament/ArAssetStudioTest.php tests/Feature/RemoteFrameAssetActionsTest.php tests/Feature/Filament/AdminNavigationStructureTest.php`

**Dependencies:** Tasks 3, 4, and 5

**Files likely touched:**

- `app/Filament/Resources/Products/ProductResource.php`
- `app/Filament/Resources/Products/Pages/ManageVariantArAsset.php`
- `app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php`
- `resources/views/filament/resources/products/pages/manage-variant-ar-asset.blade.php`
- `tests/Feature/Filament/ArAssetStudioTest.php`

**Estimated scope:** Medium (5 files)

### Task 8: Render and inspect one GLB in Model mode

Implement the model viewer using `GLTFLoader` and `OrbitControls`. Load a new
selection from its local `ArrayBuffer`, a retained candidate from the private
route, or a published model from its immutable URL. Add deterministic camera
shortcuts, backgrounds, overlays, bounded diagnostics, and complete resource
replacement/teardown.

**Acceptance criteria:**

- Local, retained-private, and published GLBs use the correct source without
  exposing quarantine metadata or following model-supplied external URLs.
- Orbit/pan/zoom, camera shortcuts, backgrounds, wireframe, bounding box, and
  diagnostics operate on the single loaded scene.
- Replacement and teardown leave one model at most and dispose object URLs,
  controls, observers, listeners, renderer, materials, textures, and geometry.

**Verification:**

```text
vendor/bin/sail node --test resources/js/ar-asset-studio/*.test.js
vendor/bin/sail npm run build
```

Manual check: load the approved GLB fixture, replace it once, navigate away, and
confirm no duplicate canvas/render loop or browser console error.

**Dependencies:** Tasks 6 and 7

**Files likely touched:**

- `resources/js/ar-asset-studio/viewer.js`
- `resources/js/ar-asset-studio/viewer.test.js`
- `resources/js/ar-asset-studio/index.js`
- `resources/views/filament/resources/products/pages/manage-variant-ar-asset.blade.php`

**Estimated scope:** Medium (4 files)

### Task 9: Add the synchronized Reference face calibration view

Build the neutral reference head from local Three.js primitives and add
TransformControls, transform modes, numeric/sliders synchronization, resets,
and undo/redo through the shared adapter. Keep Model and Reference face views
on one authoritative calibration snapshot.

**Acceptance criteria:**

- Reference face uses no remote asset, image, webcam, patient data, or physical-
  fit claim and always shows the approved approximation disclosure.
- Gizmos, sliders, and numeric fields remain synchronized across mode switches,
  with numeric controls available for every operation.
- Per-transform reset, full reset, undo, and redo update both scenes and the
  accessible textual transform summary.

**Verification:**

```text
vendor/bin/sail node --test resources/js/ar-asset-studio/*.test.js
vendor/bin/sail npm run build
```

Manual check: compare transform values in both modes and verify all operations
without using the pointer.

**Dependencies:** Task 8

**Files likely touched:**

- `resources/js/ar-asset-studio/viewer.js`
- `resources/js/ar-asset-studio/index.js`
- `resources/js/ar-asset-studio/calibration-state.js`
- `resources/js/ar-asset-studio/calibration-state.test.js`
- `resources/views/filament/resources/products/pages/manage-variant-ar-asset.blade.php`

**Estimated scope:** Medium (5 files)

### Checkpoint 3: Preview workspace

- The studio is reachable only from the correct frame variant context.
- Model and Reference face modes render one shared calibration state.
- Local and retained-private candidates preview without public exposure.
- Node tests, focused Filament tests, Vite build, and the bounded runtime check
  pass before wiring publication.

## Phase 4: Complete the Operator Workflow

### Task 10: Wire the end-to-end Validate and publish form

Add the one-file upload, measurement-prefilled calibration schema, explicit
physical-match checklist, and one final action. Use a temporary upload object
rather than Filament permanent storage, restrict Livewire uploads to schema
components, and delegate the mutation to `PublishArAssetCandidate`.

**Acceptance criteria:**

- The file advertises the approved GLB types/limit, previews locally before
  submission, and is stored only by the AR domain action.
- Required calibration and every physical-match item are enforced again on the
  server, including tampered Livewire submissions.
- One final action publishes a valid first version or replacement and focuses a
  useful validation or success summary.

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/Filament/ArAssetStudioTest.php tests/Feature/RemoteFrameAssetTest.php tests/Feature/Api/V1/FrameCatalogTest.php`

**Dependencies:** Tasks 3, 7, 8, and 9

**Files likely touched:**

- `app/Filament/Resources/Products/Pages/ManageVariantArAsset.php`
- `resources/views/filament/resources/products/pages/manage-variant-ar-asset.blade.php`
- `app/Actions/ArAssets/PublishArAssetCandidate.php`
- `tests/Feature/Filament/ArAssetStudioTest.php`
- `tests/Feature/RemoteFrameAssetTest.php`

**Estimated scope:** Medium (5 files)

### Task 11: Support resume, retry, discard, and version operations

Expose state-aware recovery and secondary operations in the studio. Resume the
single actionable quarantined/validated/approved candidate, provide publication
retry and safe discard, and reuse existing history, disable, and rollback
actions with confirmations.

**Acceptance criteria:**

- The studio resumes one existing actionable candidate and shows rejected or
  failed publication states with a specific corrective action.
- Discard marks only a non-terminal candidate rejected with the approved reason
  and never changes the published pointer.
- History, disable, and rollback retain their authorization, integrity, audit,
  confirmation, and immutable-version behavior.

**Verification:**

`vendor/bin/sail artisan test --compact tests/Feature/Filament/ArAssetStudioTest.php tests/Feature/RemoteFrameAssetTest.php tests/Feature/RemoteFrameAssetActionsTest.php`

**Dependencies:** Task 10

**Files likely touched:**

- `app/Actions/ArAssets/DiscardArAssetCandidate.php`
- `app/Filament/Resources/Products/Pages/ManageVariantArAsset.php`
- `resources/views/filament/resources/products/pages/manage-variant-ar-asset.blade.php`
- `tests/Feature/Filament/ArAssetStudioTest.php`
- `tests/Feature/RemoteFrameAssetTest.php`

**Estimated scope:** Medium (5 files)

### Task 12: Harden accessibility, responsiveness, errors, and runtime cleanup

Complete the specified state messaging, keyboard and reduced-motion behavior,
responsive two-column/stacked layout, dark mode, focus management, progress and
cancellation, visibility pausing, and escaped operator-safe errors. Verify the
actual Filament page in a browser.

**Acceptance criteria:**

- The complete flow is keyboard-operable, text-labelled, reduced-motion safe,
  dark-mode legible, and horizontally scroll-free at 1024 px and below.
- Loading, WebGL failure, parse failure, validation failure, publication retry,
  success, disabled, and restored states identify a safe next action without
  leaking internals.
- Runtime inspection shows one render loop/model, no stale object URLs or GPU
  resources after replacement/navigation, and no console or network errors.

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/ArAssetStudioTest.php
vendor/bin/sail node --test resources/js/ar-asset-studio/*.test.js
vendor/bin/sail npm run build
```

Manual browser check: desktop light, desktop dark, 1024 px, and stacked narrow
layouts; keyboard-only controls; reduced motion; replacement; Livewire
navigation; browser logs and network requests.

**Dependencies:** Tasks 10 and 11

**Files likely touched:**

- `resources/views/filament/resources/products/pages/manage-variant-ar-asset.blade.php`
- `resources/js/ar-asset-studio/index.js`
- `resources/js/ar-asset-studio/viewer.js`
- `resources/css/filament/admin/theme.css`
- `tests/Feature/Filament/ArAssetStudioTest.php`

**Estimated scope:** Medium (5 files)

### Checkpoint 4: Complete operator flow

- A single authorized operator completes first publication and replacement from
  one page.
- Recovery and secondary version operations preserve the current patient asset.
- All automated tests, build, and browser layout/accessibility/cleanup checks
  pass.
- Review the complete workflow before updating system documentation.

## Phase 5: Reconcile Documentation and Release Evidence

### Task 13: Reconcile AR documentation and run the release gate

Update the system context and original AR specification so they no longer
describe a mandatory two-person staff workflow, then mark the studio spec
implemented only after the complete verification matrix passes. Record no
patient API change because none is allowed.

**Acceptance criteria:**

- AR documentation consistently describes the one-person studio while retaining
  private quarantine, validation, audit, immutable history, and rollback.
- The approved studio spec is marked implemented only after every success
  criterion and checkpoint is satisfied.
- The final route, focused PHP suite, JavaScript suite, Vite build, production
  dependency audit, and Pint pass cleanly.

**Verification:**

```text
vendor/bin/sail artisan route:list --path=ar-assets --except-vendor
vendor/bin/sail artisan test --compact tests/Feature/Filament/ArAssetStudioTest.php tests/Feature/ArAssetPreviewTest.php tests/Feature/RemoteFrameAssetTest.php tests/Feature/RemoteFrameAssetActionsTest.php tests/Feature/Api/V1/FrameCatalogTest.php tests/Feature/Filament/AdminNavigationStructureTest.php
vendor/bin/sail node --test resources/js/ar-asset-studio/*.test.js
vendor/bin/sail npm run build
vendor/bin/sail npm audit --omit=dev
vendor/bin/sail bin pint --dirty --format agent
```

**Dependencies:** Task 12

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`
- `docs/3d-frame-try-on-spec.md`
- `docs/specs/ar-asset-admin-studio-spec.md`

**Estimated scope:** Medium (3 files plus release verification)

### Checkpoint 5: Ready for review

- Every specification success criterion is checked against evidence.
- No database migration, patient API change, Android change, or extra dependency
  was introduced.
- The exact Three.js dependency and lockfile have been reviewed.
- Focused tests, Node tests, route check, Vite build, npm audit, Pint, and browser
  checks pass.
- The implementation is ready for owner review and deployment planning.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Same-actor policy accidentally weakens other authorization | High | Remove only the identity inequality; retain `ArAssetAuthorizer` at page, controller, coordinator, and each action boundary, with a full role matrix. |
| A failed replacement changes the patient model | High | Keep publication and pointer swap in the existing locked action; test each failure point against the old pointer. |
| Candidate calibration errors remain terminal | High | Characterize first, normalize before mutation, and leave existing valid GLBs quarantined on calibration failure. |
| Quarantine data becomes publicly enumerable | High | Server-derived path only, active staff/admin authorization per request, previewable-state allowlist, and private no-store response tests. |
| Browser preview and Android apply transforms differently | High | One pure calibration adapter, conversion tests, and no unsupported loader features or automatic mesh-derived calibration. |
| Livewire upload stores a duplicate outside AR ownership | Medium | Use `storeFiles(false)`, restrict upload RPCs to schema fields, and let `UploadArAsset` own permanent quarantine storage. |
| Three.js or a malformed model consumes excessive resources | High | Exact dependency, 10 MiB client/server cap, server GLB limits, one model, one loop, cancellation, visibility pause, and deterministic disposal. |
| Vite asset loads on all admin pages | Medium | Dedicated entry plus root guard; inspect unrelated pages for canvases, requests, or listeners. |
| Nested Product/Variant IDs expose another record | High | Resolve through the parent relationship and test mismatch, non-frame, soft-deleted, inactive, and unauthorized cases. |
| Large shared page/viewer edits cause integration regressions | Medium | Keep each task at five files or fewer, run its focused verification, and stop for review at every checkpoint. |

## Parallelization

- Tasks 4 and 5 are safe to perform in parallel after Task 3 because the private
  controller/route and frontend dependency registration do not share files.
- Task 6 follows Task 5 because both touch the Vite entry.
- Tasks 7–12 should remain sequential because they share the page, Blade root,
  and viewer integration contract.
- Task 13 is intentionally last; documentation must describe shipped behavior,
  not an intermediate state.

## Open Questions

No blocking product or architecture questions remain. Any implementation need
outside the approved boundaries—schema/status changes, patient API or Android
changes, broader model formats, public storage changes, webcam/biometric data,
or another dependency—requires a new owner decision before work continues.
