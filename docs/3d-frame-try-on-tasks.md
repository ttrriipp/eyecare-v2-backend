# Tasks: Reservation-First 3D Frame Try-On

Status: Approved by stakeholder on 2026-08-15
Phase: Implement
Approved specification: `docs/specs/3d-frame-try-on-spec.md`
Approved plan: `docs/specs/3d-frame-try-on-plan.md`
Created: 2026-08-15

## Execution Rules

- Complete tasks in dependency order unless a section explicitly permits
  parallel work.
- Start each behavior change with its failing automated test where the behavior
  is testable off-device.
- Keep each task to the listed files unless a newly discovered dependency is
  recorded in this document first.
- Preserve unrelated working-tree changes.
- Run `.\gradlew assembleDebug` after every task.
- Do not begin any PASS-branch task until Task 12 records a passing feasibility
  result.
- Do not begin the FAIL branch after a pass, or the PASS branch after a fail,
  without changing the approved spec.
- Backend work packages are not executable in this Android workspace. Refine
  their paths against the backend repository before implementation.

## Phase 1: Renderer Proof

### Task 1: Pin the plain SceneView dependency

**Description:** Add only the approved plain SceneView 4.18.0 artifact through
the version catalog. This task proves dependency resolution without altering
the current try-on behavior.

**Acceptance criteria:**

- [x] The version catalog declares SceneView 4.18.0 and the app consumes its
  plain `sceneview` artifact.
- [x] Neither `arsceneview` nor ARCore is added.
- [x] Dependency resolution and the debug build succeed without forcing changes
  to AGP, Kotlin, Compose BOM, CameraX, or MediaPipe.

**Verification:**

- [x] `.\gradlew :app:dependencyInsight --dependency sceneview --configuration debugRuntimeClasspath`
- [x] `.\gradlew assembleDebug`

**Dependencies:** None.

**Files likely touched:**

- `gradle/libs.versions.toml`
- `app/build.gradle.kts`

**Estimated scope:** Small (2 files).

### Task 2: Render the bundled GLB in an isolated harness

**Description:** Introduce a small SceneView renderer and an instrumented test
harness that loads the bundled textured round-frame model without CameraX or
face tracking. Keep the expensive SceneView resources remembered and report
loading, ready, and failure states.

**Acceptance criteria:**

- [x] `models/round_frame_textured.glb` reaches a visible ready state with a
  camera and light on a physical device.
- [x] The renderer uses one remembered model instance and automatic Compose
  disposal; it never manually destroys a SceneView node.
- [x] A missing/invalid asset produces a recoverable failure signal rather than
  a crash.

**Verification:**

- [x] `.\gradlew connectedDebugAndroidTest -Pandroid.testInstrumentationRunnerArguments.class=com.eyecare.app.presentation.ar.rendering.FrameModelRendererTest`
- [x] Manual: open the test harness on the POCO X8 Pro and confirm the model is
  textured, illuminated, and fully visible.
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task 1.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/presentation/ar/rendering/FrameModelRenderer.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/model/BundledFrameAsset.kt`
- `app/src/androidTest/java/com/eyecare/app/presentation/ar/rendering/FrameModelRendererTest.kt`

**Estimated scope:** Medium (3 files).

## Checkpoint A: Static Renderer

- [x] SceneView resolves at the approved version.
- [x] The bundled GLB is visibly rendered on the POCO.
- [x] Existing AR behavior has not been replaced yet.
- [x] `.\gradlew testDebugUnitTest`
- [x] `.\gradlew assembleDebug`

If this checkpoint fails because of a library/version incompatibility, stop and
update the spec before changing the renderer version.

## Phase 2: Deterministic Face Pose

### Task 3: Expose MediaPipe facial transformation matrices

**Description:** Enable facial transformation-matrix output and translate each
valid result into an immutable presentation-layer raw pose. Keep the existing
one-face, live-stream behavior and handle missing/malformed matrices safely.

**Acceptance criteria:**

- [x] Face Landmarker explicitly requests facial transformation matrices.
- [x] A valid detection emits exactly one finite 4x4 raw matrix plus the minimal
  landmarks/timestamp needed for scale and guidance.
- [x] No face or an absent/malformed matrix emits a safe non-tracking state and
  never reaches renderer code.

**Verification:**

- [x] `.\gradlew testDebugUnitTest --tests *FaceTransformationTest --tests *FaceRotationTest`
- [x] Manual: camera analysis reports tracking/no-face transitions without a
  crash on the POCO.
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task 2.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/presentation/ar/FaceLandmarkerHelper.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/model/FaceFrame.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/tracking/FaceTransformation.kt`
- `app/src/test/java/com/eyecare/app/presentation/ar/tracking/FaceTransformationTest.kt`

**Estimated scope:** Medium (4 files).

### Task 4: Map MediaPipe pose into renderer coordinates

**Description:** Build a pure mapper from the validated raw face pose and
calibration into renderer translation, pitch, yaw, roll, and scale. Encode the
front-camera mirror and axis conventions explicitly.

**Acceptance criteria:**

- [x] Neutral, translated, yawed, pitched, and rolled fixture matrices map to
  deterministic renderer poses within declared tolerances.
- [x] Mirroring and provisional round-frame calibration are explicit inputs,
  not UI constants.
- [x] Non-finite or physically invalid input is rejected without producing a
  renderer pose.

**Verification:**

- [x] `.\gradlew testDebugUnitTest --tests *FacePoseMapperTest`
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task 3.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/presentation/ar/model/FacePose.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/tracking/FacePoseMapper.kt`
- `app/src/test/java/com/eyecare/app/presentation/ar/tracking/FacePoseMapperTest.kt`

**Estimated scope:** Medium (3 files).

### Task 5: Stabilize pose without hiding tracking loss

**Description:** Add a pure, timestamp-aware stabilizer that reduces small pose
jitter, reacts to normal movement, and resets after no-face or a large time gap.

**Acceptance criteria:**

- [x] Small input jitter is measurably reduced while a deliberate step change
  converges within the tested response window.
- [x] No-face, invalid input, or the configured timestamp gap resets history.
- [x] The stabilizer allocates no renderer/Android resource and is reusable
  across frames.

**Verification:**

- [x] `.\gradlew testDebugUnitTest --tests *PoseStabilizerTest`
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task 4.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/presentation/ar/tracking/PoseStabilizer.kt`
- `app/src/test/java/com/eyecare/app/presentation/ar/tracking/PoseStabilizerTest.kt`

**Estimated scope:** Small (2 files).

## Checkpoint B: Pose Foundation

- [x] Matrix extraction, mapping, mirroring, and stabilization tests pass.
- [x] Tracking models contain no SceneView, Filament, CameraX, or MediaPipe
  result types.
- [x] `.\gradlew testDebugUnitTest`
- [x] `.\gradlew assembleDebug`

## Phase 3: One-Frame Live Slice

### Task 6: Composite the 3D model over CameraX

**Description:** Place the transparent renderer above the existing CameraX
preview, feed it the stabilized pose, and retain the current 2D renderer as the
recoverable fallback. Use only the bundled round-frame descriptor.

**Acceptance criteria:**

- [x] One remembered GLB instance moves and rotates from live MediaPipe pose
  while CameraX remains visible underneath.
- [x] The front-camera preview and model use the same mirror convention.
- [x] Model load/tracking failure hides 3D cleanly and preserves the existing
  fallback instead of crashing or blocking exit.

**Verification:**

- [x] `.\gradlew testDebugUnitTest --tests *ArViewModelTest --tests *FacePoseMapperTest`
- [x] Manual: verify front view plus moderate yaw, pitch, roll, and distance
  changes on the POCO.
- [x] `.\gradlew assembleDebug`

**Dependencies:** Tasks 2–5.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/presentation/ar/rendering/FrameModelRenderer.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/ArTryOnScreen.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/ArViewModel.kt`
- `app/src/test/java/com/eyecare/app/presentation/ar/ArViewModelTest.kt`

**Estimated scope:** Medium (4 files).

### Task 7: Evaluate 3D device capability

**Description:** Introduce a pure capability decision plus Android facts
provider for API level, ABI, OpenGL ES, RAM, camera, and storage. A failed check
must select fallback without attempting renderer initialization.

**Acceptance criteria:**

- [x] Unit tests cover every declared feature-floor boundary and multiple
  simultaneous failures.
- [x] Unsupported capability includes a stable reason suitable for UI copy.
- [x] POCO facts resolve as supported, while the app's API 26–28 catalog path
  remains available.

**Verification:**

- [x] `.\gradlew testDebugUnitTest --tests *ArCapabilityTest`
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task 6.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/presentation/ar/capability/ArCapability.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/capability/AndroidArCapabilityProvider.kt`
- `app/src/test/java/com/eyecare/app/presentation/ar/capability/ArCapabilityTest.kt`

**Estimated scope:** Medium (3 files).

### Task 8: Consolidate try-on orchestration state

**Description:** Replace the independent public permission, face, variant, and
asset flows with one exhaustive `ArTryOnUiState`. Keep permission launch and
renderer/camera ownership in Compose while the view model owns transitions.

**Acceptance criteria:**

- [x] Tests cover checking capability, permission, loading, searching, tracking,
  recoverable error, unsupported, and variant selection transitions.
- [x] The view model exposes no mutable flow, DTO, MediaPipe result, or renderer
  node.
- [x] A late asset/face result cannot move an unsupported or disposed session
  back into tracking.

**Verification:**

- [x] `.\gradlew testDebugUnitTest --tests *ArViewModelTest`
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task 7.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/presentation/ar/model/ArTryOnUiState.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/ArViewModel.kt`
- `app/src/test/java/com/eyecare/app/presentation/ar/ArViewModelTest.kt`

**Estimated scope:** Medium (3 files).

## Checkpoint C: Live State Pipeline

- [x] The bundled model follows live pose on the POCO.
- [x] Unsupported devices bypass renderer initialization.
- [x] State-transition tests and full unit suite pass.
- [x] `.\gradlew assembleDebug`

## Phase 4: Recovery, Lifecycle, and Gate

### Task 9: Present explicit guidance, disclosure, and fallback

**Description:** Render distinct UI for permission, unsupported, loading,
searching, recoverable error, and tracking. Always expose the image-based path
and show the approved non-clinical disclosure while tracking.

**Acceptance criteria:**

- [x] Every specified state has distinct copy and an appropriate retry,
  settings, fallback, or exit action.
- [x] Tracking displays “Visual preview only. Final fit is confirmed at the
  clinic.”
- [x] UI tests prove unsupported/error states retain a catalog-image path.

**Verification:**

- [x] `.\gradlew connectedDebugAndroidTest -Pandroid.testInstrumentationRunnerArguments.class=com.eyecare.app.presentation.ar.ArTryOnScreenTest`
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task 8.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/presentation/ar/ArTryOnScreen.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/components/ArStatusOverlay.kt`
- `app/src/androidTest/java/com/eyecare/app/presentation/ar/ArTryOnScreenTest.kt`

**Estimated scope:** Medium (3 files).

### Task 10: Harden camera and renderer lifecycle

**Description:** Make pause/disposal and repeated screen entry deterministic.
Remove per-frame production logging from the measured path and add device
coverage for repeated entry/background/foreground.

**Acceptance criteria:**

- [x] Camera analysis executor, MediaPipe landmarker, model instance, and
  renderer resources are released exactly once at their lifecycle boundary.
- [x] Ten enter/exit cycles plus one background/foreground cycle do not create a
  duplicate camera, crash, or invalid-engine error.
- [x] The measured path performs no per-frame debug string formatting/logging.

**Verification:**

- [x] `.\gradlew connectedDebugAndroidTest -Pandroid.testInstrumentationRunnerArguments.class=com.eyecare.app.presentation.ar.ArLifecycleTest`
- [x] Manual: repeat the lifecycle script on the POCO and inspect Logcat for
  camera/Filament failures.
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task 9.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/presentation/ar/CameraPreviewView.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/FaceLandmarkerHelper.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/rendering/FrameModelRenderer.kt`
- `app/src/androidTest/java/com/eyecare/app/presentation/ar/ArLifecycleTest.kt`

**Estimated scope:** Medium (4 files).

### Task 11: Calibrate the round frame against the physical product

**Description:** Use the POCO and physical round frame to finalize bridge
anchor, authored-axis rotation, and scale. Store the values in the explicit
bundled descriptor and record the comparison; do not bury corrections inside
the composable.

**Acceptance criteria:**

- [x] Front-view center, total width, outer height, and recognizable silhouette
  are compared against the recorded physical measurements.
- [x] Final finite calibration values live in one named descriptor and are
  traceable in the evidence record.
- [x] The physical comparison records pass/fail observations without claiming
  clinical fit.

**Verification:**

- [x] Manual: run the calibration checklist on the POCO with the physical frame.
- [x] `.\gradlew testDebugUnitTest --tests *FacePoseMapperTest`
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task 10; user must have the POCO and physical frame.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/presentation/ar/model/BundledFrameAsset.kt`
- `docs/evidence/3d-frame-round-calibration.md`

**Estimated scope:** Small (2 files).

### Task 12: Execute and record the feasibility gate

**Description:** Run the approved 60-second POCO test before the time cutoff and
record a binary PASS or FAIL against all four gates. This task changes no
application behavior.

**Acceptance criteria:**

- [x] Evidence records build, device, GLB checksum/version, initialization time,
  median FPS, lifecycle result, movement observations, and elapsed project time.
- [x] PASS is recorded only at median FPS ≥24 with recognizable stable
  attachment and no crash/leak/blocking failure.
- [x] The document selects exactly one next branch and does not weaken a failed
  criterion after measurement.

**Verification:**

- [x] `.\gradlew testDebugUnitTest`
- [x] `.\gradlew lintDebug`
- [x] `.\gradlew assembleDebug`
- [x] Manual: stakeholder reviews and signs off the recorded PASS/FAIL.

**Dependencies:** Task 11.

**Files likely touched:**

- `docs/evidence/3d-frame-try-on-feasibility.md`

**Estimated scope:** Extra small (1 file plus device run).

## Checkpoint D: Branch Gate

- [x] Tasks 1–12 are complete.
- [x] The five-day/20% time box is recorded.
- [x] Exactly one of PASS or FAIL is selected.
- [x] No remote/backend/multi-asset work started before this checkpoint.

## PASS Branch: Remote 3D Delivery

Execute this section only when Task 12 records PASS.

### Task P1: Add the typed AR asset contract to Android

**Description:** Add the approved nullable `ar` DTO/domain structure and map it
at the frame repository boundary. Preserve legacy fields for one compatibility
release but never interpret the legacy reference as GLB.

**Acceptance criteria:**

- [x] Ready, null, and malformed/bounded contract fixtures decode or fail as
  specified using Kotlinx Serialization.
- [x] DTOs map into immutable domain asset/calibration models only at the
  repository boundary.
- [x] Existing cached/API frames without `ar` remain image-browsable and do
  not become falsely AR-ready.

**Verification:**

- [x] `.\gradlew testDebugUnitTest --tests *FrameDtosTest --tests *FrameRepositoryArMappingTest`
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task 12 PASS and a frozen backend response example.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/data/remote/dto/FrameDtos.kt`
- `app/src/main/java/com/eyecare/app/domain/model/Frame.kt`
- `app/src/main/java/com/eyecare/app/data/repository/FrameRepositoryImpl.kt`
- `app/src/test/java/com/eyecare/app/data/remote/dto/FrameDtosTest.kt`
- `app/src/test/java/com/eyecare/app/data/repository/FrameRepositoryArMappingTest.kt`

**Estimated scope:** Medium (5 files).

### Task P2: Define and test trusted asset-cache policy

**Description:** Define the domain-facing load result and pure validation/cache
policy for HTTPS, version/checksum keys, 10 MiB limits, and file promotion. This
task contains no network implementation.

**Acceptance criteria:**

- [x] Policy rejects non-HTTPS, invalid SHA-256, non-positive versions, declared
  oversize, actual oversize, and mismatched checksums.
- [x] A cache key is deterministic from variant ID, version, and checksum and
  cannot contain path traversal.
- [x] Results distinguish cached, downloaded, unsupported, and recoverable
  failure without exposing file implementation details to presentation.

**Verification:**

- [x] `.\gradlew testDebugUnitTest --tests *ArAssetPolicyTest`
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task P1.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/domain/repository/ArAssetRepository.kt`
- `app/src/main/java/com/eyecare/app/domain/model/ArAssetLoadResult.kt`
- `app/src/main/java/com/eyecare/app/data/ar/ArAssetPolicy.kt`
- `app/src/test/java/com/eyecare/app/data/ar/ArAssetPolicyTest.kt`

**Estimated scope:** Medium (4 files).

### Task P3: Implement bounded download and atomic cache

**Description:** Implement the asset repository using the existing trusted
OkHttp client and app cache. Stream to a temporary file, enforce size, verify
SHA-256, and promote atomically. Preserve the last known-good file.

**Acceptance criteria:**

- [x] Network success returns only a fully verified local asset; interruption,
  oversize, or checksum mismatch never promotes the temporary file.
- [x] A valid cached version works offline, while a corrupt cache entry is
  evicted without touching unrelated cache data.
- [x] DI exposes the domain repository without Room or presentation depending on
  OkHttp/file internals.

**Verification:**

- [x] `.\gradlew testDebugUnitTest --tests *RemoteArAssetRepositoryTest`
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task P2.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/data/ar/RemoteArAssetRepository.kt`
- `app/src/main/java/com/eyecare/app/di/ArModule.kt`
- `app/src/test/java/com/eyecare/app/data/ar/RemoteArAssetRepositoryTest.kt`

**Estimated scope:** Medium (3 files).

### Task P4: Load remote assets through try-on state

**Description:** Replace the feasibility descriptor only when a ready typed
asset exists. Feed verified local assets to the renderer and preserve loading,
retry, cached-offline, and image-fallback behavior.

**Acceptance criteria:**

- [x] The view model requests a typed asset for the selected ready variant and
  reaches tracking only after verification and renderer readiness.
- [x] Offline cached success and recoverable download/parse failure follow the
  tested UI states without falling back to the legacy reference.
- [x] The bundled model remains explicitly limited to feasibility/demo fallback
  and is not shown as an unrelated production variant.

**Verification:**

- [x] `.\gradlew testDebugUnitTest --tests *ArViewModelTest`
- [x] Manual: load once online, reopen offline, then exercise retry after a
  simulated failed download.
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task P3 and the backend patient contract endpoint.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/presentation/ar/ArViewModel.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/ArTryOnScreen.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/rendering/FrameModelRenderer.kt`
- `app/src/test/java/com/eyecare/app/presentation/ar/ArViewModelTest.kt`

**Estimated scope:** Medium (4 files).

### Task P5: Make variant switching identity-safe

**Description:** Key asset/render/reserve state by variant ID, version, and
checksum. Clear reservability immediately when selection changes and restore it
only when the matching model reaches ready/tracking.

**Acceptance criteria:**

- [x] Rapid A→B→A switching cannot allow a late B result to replace A.
- [x] Reserve is disabled during switch/load and becomes enabled only for the
  exact visible variant.
- [x] Switching to a variant without a ready asset presents image fallback
  without displaying the previous GLB under the new label.

**Verification:**

- [x] `.\gradlew testDebugUnitTest --tests *ArViewModelTest`
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task P4.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/presentation/ar/ArViewModel.kt`
- `app/src/test/java/com/eyecare/app/presentation/ar/ArViewModelTest.kt`

**Estimated scope:** Medium (2 files).

### Task P6: Produce and approve the other two pilot assets

**Description:** After exact catalog variants are selected, run the approved
capture/generation/cleanup/physical-review checklist for the rectangular and
cat-eye/browline candidates. This is an asset operation, not Android coding.

**Acceptance criteria:**

- [ ] Each GLB is mapped to one active variant ID and has measurements,
  version, checksum, steward, independent reviewer, and approval decision.
- [ ] Each passes the structural limits and physical-identity comparison before
  publication.
- [ ] Rejected output is not uploaded as ready and does not lower the study
  criteria.

**Verification:**

- [ ] Manual: complete both asset records and physical comparisons.
- [ ] Backend: both approved immutable versions are patient-visible as ready.
- [ ] Android: switch among all ready variants without identity mismatch.

**Dependencies:** Task P5, exact variant IDs, named steward/reviewer, and backend
publication capability.

**Files likely touched:**

- `docs/evidence/ar-assets/<rectangular-variant-id>.md`
- `docs/evidence/ar-assets/<cat-eye-or-browline-variant-id>.md`

**Estimated scope:** Small documentation footprint plus external asset work.

## Checkpoint E-PASS: Remote Three-Variant Slice

- [ ] Android consumes only typed ready GLB assets.
- [ ] Downloads are bounded, checksummed, atomic, and cacheable.
- [ ] Switching is identity-safe.
- [ ] Up to three approved physical variants are published.
- [ ] Full unit suite, lint, and debug build pass.

## FAIL Branch: Bounded 2D/2.5D Delivery

Execute this section only when Task 12 records FAIL.

### Task F1: Polish the measured fallback without a 3D claim

**Description:** Stop remote/3D expansion and improve the existing Canvas
overlay using the stable 2D face geometry, calibrated proportions, recovery
states, and approved disclosure. Remove or isolate unused SceneView entry points
without deleting feasibility evidence.

**Acceptance criteria:**

- [ ] Front view, roll, distance changes, no-face, permission, and asset failure
  remain usable with the correct image/variant.
- [ ] UI and report call the result 2D/2.5D, not 3D, and retain the clinic-fit
  disclosure.
- [ ] Renderer-prototype failure cannot crash or block fallback/reservation.

**Verification:**

- [ ] `.\gradlew testDebugUnitTest --tests *FaceRotationTest --tests *ArViewModelTest`
- [ ] `.\gradlew connectedDebugAndroidTest -Pandroid.testInstrumentationRunnerArguments.class=com.eyecare.app.presentation.ar.ArTryOnScreenTest`
- [ ] Manual: run the fallback motion/recovery script on the POCO.
- [ ] `.\gradlew assembleDebug`

**Dependencies:** Task 12 FAIL.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/presentation/ar/FrameOverlayRenderer.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/ArTryOnScreen.kt`
- `app/src/main/java/com/eyecare/app/presentation/ar/ArViewModel.kt`
- `app/src/test/java/com/eyecare/app/presentation/ar/FaceRotationTest.kt`
- `app/src/androidTest/java/com/eyecare/app/presentation/ar/ArTryOnScreenTest.kt`

**Estimated scope:** Medium (5 files).

## Common Completion Tasks

These follow Task P5 on PASS or Task F1 on FAIL.

### Task C1: Route “Reserve this frame” to the existing flow

**Description:** Add the reserve action at the try-on boundary and navigate with
the exact displayed frame/variant IDs to the existing appointment-linked
reservation screen. Reuse existing authorization and eligibility routing.

**Acceptance criteria:**

- [x] Reserve is visible only for an unambiguous selected/displayed variant and
  sends that exact ID to `CreateFrameReservation`.
- [x] Existing patient-link and appointment eligibility gates still apply.
- [x] Back navigation returns safely without creating a reservation implicitly.

**Verification:**

- [x] `.\gradlew testDebugUnitTest --tests *PatientFeatureIntentTest --tests *ArViewModelTest`
- [x] Manual: open try-on, reserve, select an eligible appointment, and confirm
  the resulting reservation item uses the displayed variant.
- [x] `.\gradlew assembleDebug`

**Dependencies:** Task P5 PASS or Task F1 FAIL.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/presentation/ar/ArTryOnScreen.kt`
- `app/src/main/java/com/eyecare/app/presentation/navigation/NavGraph.kt`
- `app/src/test/java/com/eyecare/app/presentation/navigation/PatientFeatureIntentTest.kt`

**Estimated scope:** Medium (3 files).

### Task C2: Enforce the coordinated Android maximum of three

**Description:** Change the Android domain limit to three only after the backend
accepts no more than three new items. Preserve reading/removing legacy four- and
five-item reservations and surface the server's `422` validation.

**Acceptance criteria:**

- [ ] New/add flows stop at three and use consistent copy derived from the
  domain constant.
- [ ] Existing reservations with four/five items remain readable and removable,
  are never truncated, and cannot accept another item.
- [ ] Tests cover counts 0–5, duplicate items, held reservations, and server
  validation.

**Verification:**

- [ ] `.\gradlew testDebugUnitTest --tests *CreateFrameReservationViewModelTest --tests *FrameReservationEligibilityTest`
- [ ] `.\gradlew assembleDebug`

**Dependencies:** Task C1 and the deployed/tested backend maximum-three change.

**Files likely touched:**

- `app/src/main/java/com/eyecare/app/domain/model/FrameReservation.kt`
- `app/src/test/java/com/eyecare/app/presentation/reservations/CreateFrameReservationViewModelTest.kt`
- `app/src/test/java/com/eyecare/app/presentation/reservations/FrameReservationEligibilityTest.kt`

**Estimated scope:** Medium (3 files).

### Task C3: Synchronize the implemented API contract documentation

**Description:** Update the Android-side API contract only after the matching
backend behavior exists. Document the typed nullable `ar` response on PASS,
legacy deprecation policy, and reservation maximum three. On FAIL, document
only the reservation change and leave remote AR unclaimed.

**Acceptance criteria:**

- [ ] Every documented field/validation rule is backed by implemented backend
  tests and an Android consumer test.
- [ ] PASS and FAIL documentation accurately reflect the selected branch.
- [ ] No staff-only processing error, quarantine path, or private metadata is
  shown in the patient response.

**Verification:**

- [ ] Compare backend contract-test fixtures with `docs/API_CONTRACT.md`.
- [ ] `.\gradlew testDebugUnitTest --tests *FrameDtosTest --tests *FrameReservationDtosTest`
- [ ] `.\gradlew assembleDebug`

**Dependencies:** Task C2; on PASS also Tasks P1–P5 and backend publication.

**Files likely touched:**

- `docs/API_CONTRACT.md`

**Estimated scope:** Extra small (1 file).

## Backend Work Package (Separate Repository)

These are required for the final PASS branch and for the reservation-limit
change, but they are not implementation-ready in this workspace because the
backend source tree is unavailable. Approval of this Android Tasks document
does not authorize blind backend edits. Once the backend repository is
provided, apply the same planning skill to create exact-file tasks for:

1. **B1 — Asset persistence and patient resource:** migration/model for variant
   asset versions, calibration, state, checksum, and nullable ready-only
   patient `ar` response.
2. **B2 — Secure upload quarantine:** authorized multipart endpoint/admin
   action, 10 MiB stream limit, GLB magic/version/chunk/parser checks, texture
   and triangle bounds, opaque filenames, and quarantine storage.
3. **B3 — Review and atomic publication:** steward/reviewer metadata,
   independent approval, immutable publish/replace/disable, audit log, and
   last-known-good retention.
4. **B4 — Reservation maximum three:** server validation for new writes plus
   preservation/removal behavior for existing four/five-item reservations.
5. **B5 — Backend verification:** authorization, malicious/malformed upload,
   ready-only patient exposure, failed replacement, immutable version, and
   reservation compatibility tests.

Backend B1–B3/B5 are skipped on FAIL. B4/B5 reservation tests are required on
both branches before Task C2.

## Phase 6: Final Validation and Capstone Evidence

### Task V1: Run the final automated and device regression

**Description:** Execute the full Android quality suite and the available device
matrix against the selected branch. Record unsupported-device fallback rather
than treating missing minimum hardware as a silent pass.

**Acceptance criteria:**

- [ ] Unit, lint, debug build, and relevant physical-device instrumentation
  checks pass.
- [ ] POCO results and every additional device list OS, RAM, GPU, build, asset,
  load/FPS observations, and fallback behavior.
- [ ] No claim is made for an untested minimum/typical device class.

**Verification:**

- [ ] `.\gradlew testDebugUnitTest`
- [ ] `.\gradlew lintDebug`
- [ ] `.\gradlew assembleDebug`
- [ ] Manual: execute and sign the device matrix.

**Dependencies:** Task C3 and the selected branch checkpoint.

**Files likely touched:**

- `docs/evidence/3d-frame-device-matrix.md`

**Estimated scope:** Extra small documentation footprint plus device runs.

### Task V2: Conduct the physical-match and confidence study

**Description:** Run the approved study with at least 15 participants and the
available physical pilot frames. Record only non-face study responses and
aggregate results.

**Acceptance criteria:**

- [ ] At least 15 participants complete blind matching, 1–5 similarity and
  stability ratings, and before/after willingness-to-reserve questions.
- [ ] Results report the 80% match threshold and median ≥4 thresholds without
  removing failures or changing criteria afterward.
- [ ] The report distinguishes verified 3D or fallback results and repeats that
  final fit is confirmed at the clinic.

**Verification:**

- [ ] Manual: validate anonymized response count and calculation sheet.
- [ ] Compare the result table against every success criterion in the approved
  spec.
- [ ] `.\gradlew assembleDebug`

**Dependencies:** Task V1, physical pilot frames, and recruited participants.

**Files likely touched:**

- `docs/evidence/3d-frame-user-study.md`
- capstone report/deck files when their locations are known

**Estimated scope:** Small documentation footprint plus human study.

## Final Checkpoint

- [ ] The selected PASS or FAIL branch is fully implemented and named honestly.
- [ ] The reservation journey uses the exact displayed variant.
- [ ] Backend and Android enforce three for new writes without damaging legacy
  reservations.
- [ ] Camera/face data remains on-device and unretained.
- [ ] Automated, device, asset, and participant evidence maps to the spec.
- [ ] `.\gradlew testDebugUnitTest`
- [ ] `.\gradlew lintDebug`
- [ ] `.\gradlew assembleDebug`

## Tasks Approval Gate

After stakeholder approval, advance the spec, plan, and this document to
`Phase: Implement`. Begin with Task 1 only. Implementation must use the
project's incremental-implementation, test-driven-development,
source-driven-development, and context-engineering workflows. Stop at each
checkpoint and at Task 12's human PASS/FAIL decision.
