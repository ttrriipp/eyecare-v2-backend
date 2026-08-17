# Spec: Reservation-First 3D Frame Try-On

Status: Approved by stakeholder on 2026-08-15
Phase: Implement
Last updated: 2026-08-15

## Objective

Enable a remote patient to preview a small, curated set of real clinic frames in
3D, shortlist a physical variant, and reserve it for an upcoming appointment.
The preview supports product discovery; it does not promise clinical fit,
prescription suitability, or exact measurements from the patient's face.

The capstone outcome is the complete, demonstrable journey:

```text
Browse catalog
    -> choose a physical frame variant
    -> preview its calibrated 3D model on the face
    -> reserve the same variant for an upcoming appointment
    -> confirm physical fit at the clinic
```

This specification supersedes the 2D PNG-overlay direction in Phase 4 of
`docs/specs/android-mvp-spec.md`. It refines
`docs/ideas/3d-frame-try-on.md`; that idea document remains the discovery
record, while this file is the implementation authority after approval.

## Product Decisions

1. The pilot covers at most three real, AR-enabled catalog variants.
2. The first vertical slice uses one locally bundled, measured GLB. Remote
   delivery and two more models come only after that slice passes its gate.
3. CameraX remains the camera source. MediaPipe Face Landmarker remains the
   tracking source and must expose its facial transformation matrices.
4. A SceneView/Filament `Scene`, composited over the camera preview, renders
   the GLB. ARCore plane tracking is not required for face-attached eyewear.
5. Android consumes GLB only. USDZ is outside the pilot.
6. AI may create the draft model, but a human validates it against the physical
   product before publication.
7. Camera frames, selfies, landmarks, and face-derived pose data remain on the
   device and are neither uploaded nor retained.
8. New reservations accept no more than three frame variants. The backend and
   Android limit change together; no client-only limit is allowed.
9. POCO X8 Pro is the primary capstone demonstration and performance device.
   It is not evidence of minimum-device compatibility by itself.
10. If the 3D feasibility gate fails within the time box, the shipped capstone
    uses the existing CameraX/MediaPipe flow with a polished 2D/2.5D overlay.

## Users and Responsibilities

### Patient

- Browses catalog images whether or not 3D is supported.
- Starts try-on only for a published, AR-ready variant.
- Sees loading, guidance, disclosure, and recovery states.
- Reserves the selected physical variant for an eligible upcoming appointment.

### AR Asset Steward

One named project teammate owns the operational checklist. Prior 3D modelling
experience is not required. The steward:

- records the physical measurements and source variant ID;
- captures the required reference photographs;
- produces the AI-generated draft and limited cleanup export;
- runs technical validation and records the asset version/checksum;
- submits the candidate for physical-product review; and
- publishes, replaces, or disables the approved asset through the staff tool.

### Physical Match Reviewer

A second teammate or clinic representative compares the candidate with the
physical frame. The reviewer rejects materially wrong lens shape, bridge,
rim/temple style, color, or proportions. One person must not both produce and
approve the same asset.

## Scope

### Included

- One measured round-frame GLB as the first feasibility asset.
- Up to three visually distinct, full-rim physical variants for the final pilot.
- Live front-camera preview with 3D translation, pitch, yaw, roll, and scale.
- Stabilization that reduces jitter without visibly lagging normal head motion.
- Local model loading for the feasibility slice and versioned remote loading
  for the completed pilot.
- Variant selection, unsupported/failure fallback, and a reservation action.
- Staff-managed upload, validation, calibration, publication, replacement, and
  disablement in the backend/admin system.
- A coordinated reservation maximum change from five to three for new writes.
- A capstone user study covering visual recognition, perceived stability, and
  reservation confidence.

### Excluded

- Full-catalog coverage, patient-uploaded models, in-app model generation, iOS,
  USDZ, social sharing, beauty filters, and automatic product photography.
- Pupillary-distance, face-size, prescription, or clinical-fit measurement.
- A guarantee that virtual scale predicts the physical fit.
- Perfect hair, ear, lens, or temple occlusion.
- Runtime dependence on Meshy or another generation vendor.
- A general-purpose 3D asset processing platform.

## Pilot Frame Selection

The exact three catalog SKUs are deferred until suitable inventory is found.
This does not block the one-frame feasibility slice. The preferred final set is:

1. full-rim round or oval;
2. full-rim rectangular; and
3. full-rim cat-eye or browline.

Each selected frame must be physically available, uniquely mapped to an active
product variant, visually distinct from the others, photographable from four
sides, and free of unusually reflective, transparent, rimless, semi-rimless,
or thin-wire construction. If fewer than three assets pass validation, the
study and presentation must state the achieved coverage rather than lowering
the acceptance criteria silently.

## First Asset and Calibration Baseline

The current round physical frame supplies the first calibration baseline:

| Measurement | Value |
|---|---:|
| Total frame width | 123 mm |
| Outer frame height | 48 mm |
| Lens width | 50 mm |
| Lens height | 45 mm |
| Bridge width | 20 mm |
| Temple length | 140 mm |

The current textured asset is
`app/src/main/assets/models/round_frame_textured.glb`. Its provisional renderer
scale is `x = 0.123`, `y = 0.144565`, and `z = 0.123`. The non-uniform Y value
corrects the source mesh proportions against the measured 48 mm outer height.
These values are a reproducible starting point, not final visual calibration.
Anchor translation and axis rotation remain adjustable metadata until device
comparison establishes the bridge-centered pose.

## Functional Requirements

### Catalog and entry

- The frame response identifies whether a variant has a published GLB.
- **Try in 3D** appears only when the feature capability check passes and the
  chosen variant has a ready asset.
- An unavailable or unsupported try-on must not block viewing product images or
  reserving the variant through the ordinary flow.
- Switching variants must never display one variant's model under another
  variant's name or reservation ID.

### Camera and tracking

- Request camera permission only when the patient enters try-on.
- Analyze at most one face and attach the frame only to that face.
- Enable MediaPipe facial transformation-matrix output and convert its pose into
  the renderer coordinate system through a separately testable mapper.
- Keep tracking, smoothing, calibration, and rendering as separate components.
- Pause analysis and rendering when the screen is not in the foreground.
- Release CameraX, MediaPipe, and renderer resources when the screen is disposed.
- Mirror the preview and model consistently for the front camera.
- Show guidance when the face is absent, too close, too far, or poorly framed.

### Rendering and interaction

- Load one model instance at a time and reuse it across pose updates.
- Apply physical calibration plus per-asset anchor/rotation corrections.
- Show a progress state while a model downloads or initializes.
- Do not allow reserve until the selected variant and displayed model agree.
- Show: **Visual preview only. Final fit is confirmed at the clinic.**
- Provide retry for transient model errors and a direct image-based fallback.
- A renderer failure must not crash the catalog or reservation experience.

### Reservation

- **Reserve this frame** carries the selected variant ID into the existing
  appointment-linked frame-reservation flow.
- An eligible patient can add the variant to a reservation for an upcoming
  appointment, subject to availability and server validation.
- Duplicate variants remain rejected by the server and client presentation.
- The server remains the authority for availability and reservation capacity.
- The post-change limit for new additions and new reservations is three items.
- Existing reservations containing four or five items remain readable and may
  have items removed; they are never truncated automatically. No addition is
  allowed until the count is below three.

## UI States

The view model exposes a `StateFlow` backed by a sealed UI state. At minimum:

- `CheckingCapability`
- `Unsupported(reason)`
- `RequestingPermission`
- `LoadingAsset(progress?)`
- `SearchingForFace`
- `Tracking(selectedVariant, disclosureVisible)`
- `RecoverableError(message)`
- `Reserving`
- `Reserved`

Camera permission denial, unsupported hardware, missing/failed assets, offline
first load, no face, and reservation validation errors must have distinct copy
and recovery actions.

## Technical Stack

| Concern | Decision |
|---|---|
| Language/UI | Kotlin, Jetpack Compose, Material 3 |
| Architecture | MVVM + Clean (`data -> domain -> presentation`) |
| State | `sealed interface` exposed through `StateFlow`; no LiveData |
| DI | Hilt |
| Camera | Existing CameraX 1.5.0 pipeline |
| Face tracking | Existing MediaPipe Tasks Vision 0.10.35 Face Landmarker |
| 3D rendering | `io.github.sceneview:sceneview:4.18.0` feasibility pin |
| 3D runtime | Google Filament through SceneView |
| Network/JSON | Retrofit + OkHttp + Kotlinx Serialization; never Gson |
| Asset format | Binary glTF 2.0 (`.glb`) |
| Tests | JUnit 5, MockK, Turbine, MockWebServer, Compose UI tests |

The SceneView version is deliberately pinned for the first spike because its
published dependency stack aligns with the project's Compose generation. A
successful clean build and POCO device smoke test are required before the pin
becomes final; dependency upgrades are not part of the first slice.

## Device Capability and Performance

The app retains `minSdk = 26`; unsupported devices retain catalog and
reservation functionality. The 3D feature floor is:

- Android 10 / API 29 or newer;
- 64-bit ARM;
- OpenGL ES 3.0 or newer;
- at least 4 GB total RAM;
- a working front camera; and
- enough free storage for a validated model and cache overhead.

The POCO X8 Pro is the primary/reference capstone device. It has ample memory
and a modern GPU, so its success cannot be presented as proof that the declared
minimum works. Until a second, less capable phone is found, minimum-device
compatibility remains an explicitly unverified claim and the runtime fallback
is mandatory.

For a 60-second normal-use session on the POCO X8 Pro, the feasibility slice
must:

- sustain a median of at least 24 rendered frames per second;
- avoid an app crash, renderer reset, or out-of-memory failure;
- keep the frame recognizable and attached during front view, moderate yaw,
  pitch, roll, and normal distance changes; and
- initialize the bundled model within 3 seconds after camera permission is
  already granted.

Thirty FPS is preferred. Measurements must use a release-like build or a debug
build with logging reduced; per-frame log output is not valid for performance
measurement.

## Asset Production and Publication

### Capture checklist

For each physical variant, the steward records the variant ID and the six
measurements in this spec's first-asset table. Capture front, back, left, and
right images under diffuse lighting, at matched distance, on a plain contrasting
background. Additional three-quarter views may be supplied to the generator.

### Authoring checklist

1. Generate a draft with Meshy or another multi-image-to-3D tool.
2. Export GLB; do not integrate the generation service into the app/backend.
3. In Blender, set a bridge-centered origin, apply transforms, correct the
   agreed forward/up axes, remove unrelated geometry, and simplify materials.
4. Prefer one mesh/material where practical, no animation, and JPEG/PNG textures
   no larger than 2048 x 2048.
5. Target 5 MiB or less and enforce an upload maximum of 10 MiB.
6. Record physical dimensions, calibration, generator/tool, version, checksum,
   steward, reviewer, and approval date.

### Physical acceptance checklist

The reviewer compares the GLB and physical frame for silhouette, lens openings,
bridge, rims, temple style, and color. A model fails if any difference would
reasonably make a patient mistake it for another pilot frame. Cosmetic detail
may be simplified when product identity remains clear.

### Upload and publication flow

```text
authorized staff selects GLB + variant
    -> server streams file into quarantine
    -> structural and policy validation
    -> calibration metadata validation
    -> physical-review approval recorded
    -> immutable version published
    -> patient API exposes ready version
```

- The mobile patient app never uploads catalog models.
- Upload belongs in the backend's authenticated staff/admin interface.
- A replacement is a new immutable version. The previous ready version remains
  active until the replacement passes validation and is published atomically.
- Disabling an asset removes try-on availability but does not remove the frame
  or its ordinary catalog images.
- Rejected/quarantined files are not served from a public URL.

## API and Data Contract

The existing `ar_eligible`/`ar_asset_reference` fields are ambiguous, and the
documented `.usdz` example is incompatible with Android's current bitmap
loader. Do not silently reinterpret `ar_asset_reference`. Introduce a typed,
nullable `ar` object on each patient-visible variant:

```json
{
  "id": 42,
  "sku": "ROUND-BLK-50",
  "ar": {
    "status": "ready",
    "asset": {
      "url": "https://cdn.example.test/ar/variants/42/v2/model.glb",
      "format": "glb",
      "version": 2,
      "byte_size": 5256552,
      "sha256": "64-lowercase-hex-characters"
    },
    "calibration": {
      "frame_width_mm": 123.0,
      "outer_frame_height_mm": 48.0,
      "lens_width_mm": 50.0,
      "lens_height_mm": 45.0,
      "bridge_width_mm": 20.0,
      "temple_length_mm": 140.0,
      "scale": { "x": 0.123, "y": 0.144565, "z": 0.123 },
      "anchor": { "x": 0.0, "y": 0.0, "z": 0.0 },
      "rotation_degrees": { "x": 0.0, "y": 0.0, "z": 0.0 }
    }
  }
}
```

Contract rules:

- `ar` is `null` when no ready asset exists. Patient responses do not expose
  quarantine paths, staff names, or processing errors.
- `status` is `ready` in a non-null patient object; detailed `processing`,
  `failed`, and `disabled` states belong to the authorized staff contract.
- `url` is HTTPS and points to an immutable version; the cache key is variant ID
  plus version and checksum.
- `format` is the closed enum `glb` for this API version.
- Dimensions are positive decimal millimetres. Scale components are finite and
  positive. Anchor and rotation values are finite and server-bounded.
- DTOs map to domain models at the repository boundary. UI and domain layers do
  not depend on serialized DTO types.
- During one backward-compatibility release, the backend may continue emitting
  the legacy fields unchanged. Android prefers `ar`; the legacy reference is
  not treated as a GLB. Removal requires a separately documented deprecation.
- The backend `POST /frame-reservations` item array changes from `max:5` to
  `max:3` in the same release as the Android domain constant and API contract.
  A server `422` remains authoritative if another client is outdated.

The backend repository is outside this Android workspace. Updating
`docs/API_CONTRACT.md` alone does not implement this contract; backend code,
storage, authorization, validation, and contract tests must land before remote
asset delivery is considered complete.

## Security and Privacy Requirements

### Upload boundary

- Require authenticated, authorized catalog-management access; never accept
  patient model uploads.
- Allow only `.glb`; do not trust the filename or client MIME type.
- Enforce a 10 MiB request/file limit before expensive parsing.
- Verify the GLB `glTF` magic bytes, supported version, declared length, chunk
  bounds, and successful glTF parsing.
- Reject external resource URIs, unsupported embedded image types, non-finite
  transforms, excessive geometry, textures over 2048 x 2048, and malformed
  files. The initial policy ceiling is 100,000 triangles.
- Generate opaque server-side storage names; retain the original filename only
  as non-executable metadata.
- Store uploads outside an executable/public application path, quarantine until
  validation completes, and publish only the validated immutable copy.
- Calculate SHA-256 server-side and audit upload, validation, approval,
  publication, replacement, disablement, actor, and timestamp.
- Keep the last known-good asset active if a replacement fails.

### Android download boundary

- Accept only the typed HTTPS URL from the trusted API origin policy.
- Enforce the declared and actual size ceiling, verify SHA-256 before loading,
  write atomically, and never pass a partial download to the renderer.
- Treat model parsing/renderer errors as recoverable and delete the bad cache
  entry without deleting unrelated app data.
- Do not log URLs containing credentials; production asset URLs should not carry
  long-lived bearer credentials.

### Face data

- Process camera images and landmarks in memory on-device.
- Do not persist frames, screenshots, landmarks, transformation matrices, or
  inferred face measurements.
- Do not send face-derived telemetry. Product/variant actions may use ordinary
  analytics only if the existing project privacy policy permits them.
- Do not store tokens or health data in Room.

## Android Project Structure

The implementation follows the existing package layout and introduces narrow
interfaces rather than placing renderer/network logic in the composable:

```text
app/src/main/java/com/eyecare/app/
  data/
    remote/dto/                 # typed AR DTOs beside FrameDtos
    repository/                 # DTO -> domain mapping, asset repository impl
  domain/
    model/                      # ArAsset, ArCalibration, capability/result types
    repository/                 # asset loading contract if networked
  presentation/ar/
    ArTryOnScreen.kt            # Compose states and user actions
    ArViewModel.kt              # StateFlow orchestration
    FaceLandmarkerHelper.kt     # MediaPipe lifecycle/results only
    tracking/                   # matrix conversion and pose stabilization
    rendering/                  # SceneView adapter and model lifecycle
    capability/                 # feature-floor evaluation
    model/                      # presentation-only pose/UI models
app/src/main/assets/models/     # feasibility fixture only, not final catalog
app/src/test/                   # pure mapping, state, policy, and math tests
app/src/androidTest/            # camera/renderer/Compose device integration
```

Production catalog GLBs are backend-managed and must not accumulate in the APK.
Only the single feasibility fixture may remain bundled, and it should be
removed or explicitly retained as a demo fallback before final release.

## Code Style

- Follow ktlint and the existing Compose conventions.
- Prefer immutable data classes, exhaustive sealed states, small pure mappers,
  constructor injection, and lifecycle-aware `StateFlow` collection.
- Keep pose math free of Android UI types so it can be unit tested.
- Never expose `MutableStateFlow`, DTOs, SceneView nodes, or MediaPipe result
  objects outside their owning layer.
- Do not perform file/network/model work in a composable body.
- Close camera, landmarker, streams, and renderer resources deterministically.

Representative pattern:

```kotlin
sealed interface ArTryOnUiState {
    data object CheckingCapability : ArTryOnUiState
    data class LoadingAsset(val progress: Float?) : ArTryOnUiState
    data class Tracking(val variantId: Int) : ArTryOnUiState
    data class Unsupported(val reason: String) : ArTryOnUiState
    data class Error(val message: String) : ArTryOnUiState
}

class ArViewModel @Inject constructor(
    private val loadArAsset: LoadArAssetUseCase,
) : ViewModel() {
    private val _state = MutableStateFlow<ArTryOnUiState>(
        ArTryOnUiState.CheckingCapability,
    )
    val state: StateFlow<ArTryOnUiState> = _state.asStateFlow()
}
```

## Commands

Run from the repository root in PowerShell:

```powershell
.\gradlew ktlintFormat
.\gradlew testDebugUnitTest
.\gradlew lintDebug
.\gradlew assembleDebug
```

Every implementation increment must end with `assembleDebug`. Run the focused
unit tests during development, then the full unit suite and lint before the
feature is declared ready. Device verification is additional and is not
replaced by a successful JVM build.

## Testing Strategy

### Unit tests

- Decode the new AR contract, including `ar = null`, unknown malformed fields,
  positive bounds, and DTO-to-domain mapping.
- Verify calibration conversion, renderer-axis conversion, mirroring, clamping,
  and smoothing with deterministic matrices.
- Verify capability decisions at each floor boundary.
- Verify view-model transitions for permission, load, no-face, retry, variant
  switch, renderer failure, and reservation results with Turbine.
- Change and test `MAX_RESERVATION_ITEMS` as three, including the legacy
  four/five-item read-and-remove behavior.
- Test checksum mismatch, oversize, interrupted download, atomic cache replace,
  and last-known-good fallback without loading untrusted data into the renderer.

### Instrumented and Compose tests

- Verify state-specific copy and actions, disclosure visibility, variant/model
  identity, and that unsupported devices retain image/reservation actions.
- Load the bundled GLB through the real renderer on a physical device.
- Exercise background/foreground and repeated screen entry for resource leaks.
- Camera behavior requires a physical-device run; emulator-only success is
  insufficient.

### Backend contract tests (backend repository)

- Authorization and upload-policy failures.
- Quarantine-to-ready transition and atomic replacement.
- Patient response never exposes non-ready asset data.
- Immutable URL/version/checksum behavior.
- Reservation maximum three and preservation of legacy reservations.

### Manual/device study

Use the POCO X8 Pro for the primary 60-second performance and motion script.
When additional phones become available, add one device near the feature floor
and one typical midrange phone. Record OS, RAM, GPU, app build, GLB version,
lighting, FPS summary, load time, failures, and observer rating.

For at least 15 participants:

- perform a blind match between each virtual frame and the three physical
  choices;
- rate shape/color similarity and placement stability from 1 to 5; and
- record willingness to reserve before and after try-on.

## Three-Tier Boundaries

### Always do

- Preserve ordinary catalog images and reservation fallback.
- Keep face processing on-device and ephemeral.
- Map DTOs to domain models at the repository boundary.
- Validate every model before publication and again at the trusted download
  boundary.
- Identify the displayed and reserved variant with the same stable variant ID.
- Show the non-clinical preview disclosure.
- Keep backend and Android reservation limits aligned.

### Ask first

- Changing the three-frame pilot size, participant thresholds, device floor,
  renderer dependency/version, GLB limits, or performance cutoff.
- Adding a runtime third-party generation/upload service.
- Persisting or transmitting any camera or face-derived data.
- Changing API fields beyond the additive `ar` object and coordinated
  reservation validation.
- Expanding production scope beyond three variants.

### Never do

- Claim exact or clinical fit from this preview.
- Publish an AI-generated model without human physical-product review.
- Upload patient camera data or accept patient-supplied catalog models.
- Store tokens, health data, face data, or model binaries in Room.
- Silently reinterpret the legacy `.usdz`/bitmap reference as GLB.
- Ship a crash-prone 3D path without an image-based fallback.
- Delete or truncate valid legacy reservations to enforce the new limit.

## Feasibility Gate and Fallback

The first vertical slice has a hard time box: five focused working days or 20%
of the remaining implementation time, whichever is reached first.

It passes only if the measured round-frame GLB:

1. renders over the live front camera on the POCO X8 Pro;
2. maintains median performance of at least 24 FPS for 60 seconds;
3. remains recognizably attached during the defined movement script; and
4. does not crash, leak resources across repeated entry, or block fallback.

If it fails, stop expanding the 3D implementation. Ship a polished 2D/2.5D
overlay using the existing CameraX/MediaPipe path, retain the same catalog and
reservation journey, and present the 3D work honestly as a prototype with
measured limitations. The fallback is a planned capstone result, not an excuse
to continue an unbounded renderer/debugging effort.

## Success Criteria

- At least one frame passes the technical feasibility gate; the final target is
  three validated, published physical variants.
- At least 12 of 15 participants (80%) correctly match each tested virtual
  model to its physical counterpart.
- Median visual similarity and placement-stability ratings are each at least
  4 out of 5.
- The POCO X8 Pro sustains median 24 FPS during the defined session.
- A linked patient with an eligible upcoming appointment can reserve the exact
  displayed variant, and the server enforces a maximum of three new items.
- Authorized staff can publish, replace, and disable an asset without rebuilding
  Android, and a failed replacement leaves the old asset usable.
- Unsupported, offline, denied-permission, and renderer-failure states preserve
  image-based browsing and reservation.
- No camera frame or face-derived data is uploaded or retained.
- The written capstone report distinguishes verified results from untested
  compatibility and makes no physical-fit claim.

## Open Questions

These do not block the first feasibility slice:

1. Which exact three active catalog variant IDs meet the pilot criteria?
2. Who will be named AR Asset Steward and Physical Match Reviewer?
3. Which available phones will fill the minimum and typical test roles in
   addition to the POCO X8 Pro?
4. Which backend storage/CDN configuration will serve immutable GLB versions?

## Sources

- Local system context: `docs/BACKEND_CONTEXT.md`
- Current API contract: `docs/API_CONTRACT.md`
- Product discovery record: `docs/ideas/3d-frame-try-on.md`
- MediaPipe Face Landmarker for Android:
  <https://ai.google.dev/edge/mediapipe/solutions/vision/face_landmarker/android>
- MediaPipe Face Landmarker options:
  <https://ai.google.dev/edge/api/mediapipe/java/com/google/mediapipe/tasks/vision/facelandmarker/FaceLandmarker.FaceLandmarkerOptions.Builder>
- SceneView nodes and model loading: <https://sceneview.github.io/docs/nodes/>
- SceneView performance guidance: <https://sceneview.github.io/docs/performance/>
- POCO X8 Pro specifications:
  <https://www.mi.com/global/product/poco-x8-pro/specs/>
- glTF 2.0 specification:
  <https://registry.khronos.org/glTF/specs/2.0/glTF-2.0.html>
- OWASP File Upload Cheat Sheet:
  <https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html>
