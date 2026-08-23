# Spec: AR Asset Admin Studio

## Status

**Approved for implementation planning — 2026-08-23.**

This specification replaces the staff-facing, two-person sequence of Upload,
Submit for review, Approve physical review, and Publish with one variant-scoped
workspace and one authorized operator. It does not replace the existing remote
GLB patient contract, immutable version history, private quarantine, or rollback
model described in `docs/3d-frame-try-on-spec.md` and
`docs/BACKEND_CONTEXT.md`.

## Approved Assumptions

1. One active staff member or administrator may upload, calibrate, physically
   review, approve, and publish the same 3D model.
2. The workspace is a dedicated Filament page scoped to one Product and one
   frame Product Variant, reached from Products → Variants.
3. The page uses a Vite-bundled, exact-version installation of Three.js rather
   than a CDN script.
4. Staff receive both a free-orbit model inspection view and an approximate
   reference-head view.
5. Visual manipulation and precise numeric fields remain synchronized.
6. The existing private quarantine, server-side validation, HTTPS immutable
   publication, checksum verification, audit trail, disablement, replacement,
   and rollback behavior remain mandatory.
7. The patient API and Android application are unchanged by this feature.

## Objective

Build an **AR Asset Admin Studio** that lets one authorized clinic operator add
or replace a frame variant's GLB, inspect it live, adjust calibration visually,
attest that it matches the physical product, and publish it without leaving one
workspace.

The feature solves the current administrative friction:

- the happy path requires four separate actions across two people;
- the upload success message says the model is queued for review even though a
  separate submission is still required;
- calibration requires fifteen numbers, four of which already exist on the
  variant;
- `validated` and `approved` are presented with the same status label; and
- a calibration mistake can reject an otherwise valid GLB and force a new
  upload.

Success means an authorized operator can complete the normal first-publication
or replacement flow in one page and one final submission, while a patient sees
only the same ready-only, immutable `ar` contract that exists today.

## Users and Authorization

### Authorized

- active users whose role is `staff`;
- active users whose role is `admin`; and
- active dual-role clinic users for whom `isStaff()` is true.

The uploader may approve and publish their own candidate. `uploaded_by`,
`approved_by`, and `published_by` may therefore reference the same User, and
the corresponding audit events remain separate.

### Not authorized

- patients;
- inactive users;
- guests; and
- optometrist-only users who are neither staff nor administrators.

The existing `ArAssetAuthorizer` remains the single role and active-account
authority for this feature. The Filament page, private-preview controller, and
every domain action must invoke it independently. Hiding a link or button is
not an authorization boundary.

The page must also verify that:

- the Product is a frame;
- the Product Variant belongs to the Product in the route; and
- the Product Variant is not soft-deleted when a new model is published.

Historical version viewing remains available when the Product or Variant is
inactive, but a new publication requires an active Product and Variant.

## User Experience

### Entry point

Replace the seven AR actions currently shown in each frame-variant row with one
primary action:

> **Manage 3D model**

The action opens a custom Product resource page with a route conceptually
equivalent to:

```text
/admin/products/{product}/variants/{variant}/3d-model
```

The page is not a sidebar navigation item. Staff reach it in the context of the
variant they are already managing.

### Page composition

On a desktop or front-desk laptop, the studio uses a two-column Operate layout:

```text
┌──────────────────────────────────┬────────────────────────┐
│ Live preview                     │ Candidate and controls │
│                                  │                        │
│ [Model] [Reference face]         │ File / current version │
│                                  │ Physical measurements  │
│ Orbit canvas                     │ Transform controls     │
│                                  │ Physical-match review  │
│ View shortcuts and diagnostics   │                        │
├──────────────────────────────────┴────────────────────────┤
│ Status / validation result             Validate & publish │
└───────────────────────────────────────────────────────────┘
```

- The preview is the visual focal point and receives roughly two-thirds of the
  width on wide screens.
- The control column remains visible without horizontal scrolling.
- Below the desktop breakpoint, the preview stacks above the form.
- The final action and current status remain visible in a sticky page footer.
- The page preserves the existing Filament visual language, Instrument Sans,
  Clinical Blue action color, bordered surfaces, dark mode, and text-plus-color
  status treatment.

### Preview mode: Model

The Model view provides:

- orbit, pan, and zoom;
- front, rear, left, right, top, and reset-camera shortcuts;
- light and dark neutral backgrounds;
- optional wireframe and bounding-box overlays;
- translate, rotate, and scale gizmos;
- a visible indication of the active manipulation mode; and
- diagnostics for file size, triangle count, texture dimensions, and GLB
  validation state when those server results are available.

There is no automatic rotation. This keeps the workspace calm, avoids motion
for users who prefer reduced motion, and prevents the model from moving while
staff compare it with a physical frame.

### Preview mode: Reference face

The Reference face view places the frame on a neutral, stylized mannequin head
built from local Three.js primitives. It must not use a real person, a patient
photo, a webcam, face tracking, or a remotely downloaded asset.

The view applies the candidate's `scale`, `anchor`, and `rotation_degrees`
through one named JavaScript calibration adapter. It displays this disclosure
beside the mode selector:

> **Approximate visual preview only. Final fit is confirmed at the clinic.**

This view helps identify obviously incorrect scale, origin, or rotation. It is
not required to reproduce Android camera tracking exactly and must not claim
physical, pupillary-distance, or clinical-fit accuracy.

### File selection and preview

- Accept one `.glb` with the existing 10 MiB maximum.
- The browser file chooser advertises `.glb`,
  `model/gltf-binary`, and `application/octet-stream`.
- A newly selected file is parsed directly from its local `ArrayBuffer`; it is
  not made public and does not require a server URL to preview.
- Client parsing provides fast feedback but never replaces server validation.
- A retained, non-public candidate is loaded only through the authenticated
  preview response defined below.
- Selecting another file replaces only the current unsaved browser selection.
  It does not create a version until the final server submission.

### Calibration form

The form contains the existing public calibration contract:

- total frame width;
- outer frame height;
- lens width;
- lens height;
- bridge width;
- temple length;
- scale X/Y/Z;
- anchor X/Y/Z; and
- rotation X/Y/Z in degrees.

Behavior:

- lens width, lens height, bridge width, and temple length are prefilled from
  the Product Variant when present;
- variant values are suggestions and remain visibly reviewable before publish;
- a calibration preset is never silently selected;
- choosing the existing round-frame preset is an explicit operator decision;
- advanced transform fields are collapsed initially but their summary remains
  visible;
- gizmos, sliders, and numeric fields update one shared calibration state;
- numeric fields are the precise and authoritative UI representation;
- Reset is available per transform and for the complete candidate; and
- browser-session undo and redo cover calibration changes only and are not
  persisted after navigation.

If there is no selected preset, all required values must be supplied. Anchor
and rotation may be explicitly set to zero; scale values must be finite and
non-zero. Server normalization remains authoritative.

### Physical-match attestation

The operator must confirm each item before publication:

- correct Product Variant and SKU;
- silhouette and lens openings match the physical frame;
- bridge, rims, and temple style match;
- color and material are reasonably represented;
- proportions are not materially misleading; and
- reference-head placement has been reviewed.

The final checkbox states:

> I compared this model with the physical frame and confirm that it represents
> this catalog variant.

The final **Validate & publish** button remains disabled until the GLB is
locally previewable, required calibration is complete, every checklist item is
confirmed, and browser WebGL support is available.

## Workflow and Lifecycle

### First publication or replacement

```text
choose GLB
    → local browser preview
    → calibrate and attest
    → Validate & publish
    → server preflight
    → private quarantine + structural validation
    → calibration validation
    → approval attributed to the same operator
    → immutable publication and atomic variant-pointer swap
    → published success state
```

One coordinator action owns this UI operation and delegates to the existing
single-purpose lifecycle actions. The coordinator does not duplicate GLB
parsing, storage, checksums, version allocation, locking, publication, or audit
logic.

The existing internal transitions remain:

```text
quarantined → validated → approved → published
```

They may occur within one request. Keeping the statuses avoids a database
migration, preserves history, and leaves `approved` available as a recoverable
state if immutable publication fails after validation and approval.

### Preflight and partial failure

Before creating a new candidate, the coordinator checks known publication
requirements, including a configured HTTPS asset base URL and usable disk
configuration.

- Invalid form calibration creates no ArAsset record.
- A structurally invalid GLB may be retained as `rejected` with its existing
  human-readable validation note and audit event.
- A publication-storage failure leaves the candidate `approved`, keeps the
  previous published version active, and presents **Retry publication**.
- A database failure after writing a quarantine object must remove that orphan
  object.
- A replacement never changes the current patient model until the new version
  is published successfully.

### Existing candidates

The studio can resume existing records created by the outgoing workflow:

| Latest state | Studio behavior |
|---|---|
| `quarantined` | Load private preview, complete or correct calibration, then publish |
| `validated` | Load preview, attest, approve, and publish with the current operator |
| `approved` | Load preview and retry publication |
| `rejected` | Show the failure and require a new candidate file |
| `published` | Show the current version and offer Replace model |
| `superseded` / `disabled` | Show in version history and allow existing rollback rules |

Only one non-terminal candidate is actionable per variant. While one exists,
the studio resumes it instead of creating another hidden candidate. A secondary
**Discard candidate** action may mark the active non-terminal candidate as
`rejected` with the operator reason “Discarded before publication,” then permit
a new upload. Discarding never affects the currently published model.

### Secondary operations

Version history, Disable, Rollback, and Discard candidate live in a secondary
actions menu inside the studio. They retain their existing confirmation,
authorization, integrity, and audit rules.

## Private Preview Contract

No upload or lifecycle endpoint is added to `/api/v1`. Filament and Livewire own
the mutation flow.

A retained quarantine candidate requires one staff-only web response:

```http
GET /admin/ar-assets/{arAsset}/preview
```

Contract:

- middleware: `web`, authenticated panel session, password-change enforcement;
- authorization: active staff/admin through `ArAssetAuthorizer` on every call;
- resource check: the asset must be a previewable staff state and its private
  file must exist;
- success: `200` streamed response with
  `Content-Type: model/gltf-binary`, inline disposition,
  `X-Content-Type-Options: nosniff`, and `Cache-Control: private, no-store`;
- unauthorized: `403`;
- missing record, disallowed state, or missing file: `404`;
- no quarantine path, validation error, actor identity, or storage metadata is
  serialized to JavaScript; and
- the route does not accept a disk, path, URL, or filename from the request.

Published versions continue using their immutable HTTPS URL. Patient-facing
responses remain unchanged.

## Frontend Architecture

### Dependency

Add `three` as an exact production dependency. The version selected for this
spec is `0.185.1`, the current stable npm release at specification time. Install
scripts remain disabled during installation and the lockfile change must be
reviewed before use.

Use only the modules needed by the studio:

- `WebGLRenderer` and core scene primitives;
- `GLTFLoader`;
- `OrbitControls`; and
- `TransformControls`.

Do not add React, Vue, a second 3D library, a CDN dependency, a Draco decoder,
a KTX2 decoder, or remote environment textures. The preview must support the
same uncompressed, embedded-resource GLB subset accepted for Android. If the
server accepts a feature the admin viewer or Android renderer cannot load, the
server allowlist must be tightened rather than silently publishing an
unpreviewable candidate.

### Filament and Vite integration

- Implement the studio as a custom Product resource page and Blade view.
- Build a dedicated ES-module Vite entry for the studio.
- Register the built module through Filament's asset manager.
- Initialize it only when the studio root element exists; Three.js must not add
  rendering work to unrelated admin pages.
- Keep domain mutations in Livewire/PHP. JavaScript owns rendering, local
  parsing, manipulation, and synchronization events only.
- Use one pure calibration-state module so rendering controls and numeric form
  fields cannot develop different transform conventions.

### Resource cleanup

On file replacement, Livewire navigation, or page teardown:

- stop the animation loop;
- disconnect resize observers and input listeners;
- abort pending loads where supported;
- revoke every generated object URL;
- dispose geometries, materials, textures, and the renderer; and
- remove the old model before attaching the next one.

Only one GLB scene may be retained in memory at a time.

## Security and Threat Model

### Trust boundaries

| Boundary | Primary threats | Required controls |
|---|---|---|
| Staff-selected file → browser parser | malformed GLB, memory exhaustion | 10 MiB client check, one model, cleanup, failure isolation |
| Staff-selected file → Laravel | spoofed extension/MIME, malformed chunks, oversized geometry/textures | existing extension, byte, magic, chunk, texture, and triangle validation |
| Calibration state → Livewire/action | tampering, NaN/infinity, zero scale | server-side normalization and bounds; never trust disabled/read-only fields |
| Route ID → private preview | ID enumeration, privilege escalation, path injection | authentication, role authorization, route binding, server-derived path |
| Quarantine → public publication | tampering, stale or wrong version | checksum/size verification, immutable server-derived path, row locks |
| Third-party package → admin browser | supply-chain compromise | exact version, reviewed lockfile, no CDN, scripts disabled, npm audit/signature review |

The GLB must never be inserted into HTML, interpreted as JavaScript, passed to
`eval`, or allowed to supply external resource URLs. Canvas errors must be
rendered as escaped text. Existing server-side rejection of external buffers
and unsupported textures remains in force.

The preview route is same-origin and does not require a CORS change. No patient
or biometric data enters the studio.

## States and Error Handling

The page defines visible, text-labelled states for:

- no model uploaded;
- local file reading;
- browser parsing and initialization;
- preview ready;
- browser/WebGL unsupported;
- unsupported or malformed GLB;
- calibration incomplete or invalid;
- ready to publish;
- server validation in progress;
- server validation failed;
- publication in progress;
- published successfully;
- approved but publication failed, with retry;
- published model disabled; and
- retained version restored.

Errors name the corrective action and avoid raw exception messages, stack
traces, disk names, and paths. A rendering failure never deletes a selected
file, changes an ArAsset state, or affects the currently published version.

## Accessibility, Responsiveness, and Performance

- Meet the panel's WCAG 2.1 AA baseline.
- Every canvas operation has a labelled button or numeric-field alternative;
  the 3D gizmo is never the only way to calibrate.
- View shortcuts and transform-mode buttons are keyboard reachable and expose
  pressed/current state.
- Focus moves to the validation summary after a failed submission and to the
  success summary after publication.
- Status uses text and iconography in addition to color.
- The canvas has a concise accessible description plus a live textual summary
  of current scale, anchor, and rotation.
- The studio remains usable at 1024 px wide and stacks without horizontal page
  scrolling below that width.
- Three.js loads only on the studio page.
- A 10 MiB candidate must show progress and remain cancellable while loading.
- No more than one render loop and one loaded GLB may exist at once.
- No automatic animation runs when the page is idle or hidden.

## Compatibility and Data Model

- No database migration is required for the primary workflow.
- `ar_assets` remains append-only by version.
- Existing status values and actor/timestamp columns remain.
- The uploader-equals-approver restriction is removed from the approval action
  and its tests.
- Existing published, superseded, disabled, rejected, validated, approved, and
  quarantined records remain readable.
- `product_variants.published_ar_asset_id` remains the single patient-facing
  pointer.
- `ar_eligible` and `ar_asset_reference` remain unchanged legacy fields.
- `FrameVariantResource` and `ProductVariantResource` response shapes do not
  change.

## Project Structure

Expected implementation locations follow existing Product and AR conventions:

```text
app/Actions/ArAssets/
    PublishArAssetCandidate.php       # one-person coordinator
app/Filament/Resources/Products/Pages/
    ManageVariantArAsset.php          # variant-scoped studio page
app/Http/Controllers/
    ArAssetPreviewController.php      # authorized private stream
app/Services/ArAssets/
    existing authorizer, calibration, and GLB validator
resources/js/ar-asset-studio/
    index.js
    calibration-state.js
    viewer.js
resources/views/filament/resources/products/pages/
    manage-variant-ar-asset.blade.php
tests/Feature/Filament/
    ArAssetStudioTest.php
tests/Feature/
    ArAssetPreviewTest.php
resources/js/ar-asset-studio/
    calibration-state.test.js          # pure Node tests
```

Exact file splitting belongs to the implementation plan. No new top-level
application directory is permitted.

## Code Style

PHP follows the existing action pattern with constructor property promotion,
explicit parameter and return types, named arguments at call sites, and domain
logic outside Filament pages:

```php
final class PublishArAssetCandidate
{
    public function __construct(
        private readonly ArAssetAuthorizer $authorizer,
        private readonly ArCalibration $calibration,
        private readonly UploadArAsset $uploadArAsset,
        private readonly SubmitArAssetForReview $submitForReview,
        private readonly ApproveArAsset $approveArAsset,
        private readonly PublishArAsset $publishArAsset,
    ) {}

    public function handle(
        ProductVariant $variant,
        UploadedFile $file,
        array $calibration,
        bool $physicalMatchConfirmed,
        User $actor,
    ): ArAsset;
}
```

JavaScript uses ES modules, descriptive names, no framework-specific global
state, no inline scripts in Blade, and pure functions for calibration mapping.

## Testing Strategy

### Pest feature and action coverage

Cover at minimum:

- active staff and administrators can open the studio;
- guest, patient, inactive staff, and optometrist-only users cannot;
- a route variant must belong to its route Product and be a frame variant;
- the same active user can upload, attest, approve, and publish;
- attestation is mandatory and cannot be bypassed with a tampered Livewire
  request;
- valid local calibration is revalidated server-side;
- invalid calibration creates no new candidate;
- invalid extension, size, magic, chunks, external resources, texture bounds,
  or triangle bounds never reaches public storage;
- known publication preflight failure creates no new candidate;
- publication failure keeps the previous patient version active and exposes a
  retryable approved candidate;
- replacement, disablement, and rollback retain current behavior;
- a non-terminal candidate is resumed instead of silently orphaned;
- discarding a candidate never changes the published pointer;
- private preview authorizes each request and emits the required headers;
- private preview never accepts a client-supplied storage path;
- all lifecycle audit events preserve actor attribution; and
- the patient frame response is byte-for-contract equivalent before and after
  the admin workflow change.

Reuse the GLB fixtures and calibration helpers in
`tests/Feature/RemoteFrameAssetTest.php`. Follow the repository's existing
`RefreshDatabase` convention for adjacent AR feature tests.

### JavaScript coverage

Use Node's built-in test runner rather than adding another test dependency.
Test pure calibration state and coordinate conversion for:

- preset application;
- variant measurement prefill;
- numeric normalization;
- scale, anchor, and degree-to-radian conversion;
- reset;
- undo and redo; and
- rejection of NaN, infinity, and zero scale.

WebGL rendering receives a bounded manual runtime check on desktop light mode,
desktop dark mode, and the stacked narrow layout. The manual check does not
replace automated server, state, or build verification.

## Commands

All commands run through Laravel Sail:

```text
Discover Filament generator:
vendor/bin/sail artisan list --format=txt
vendor/bin/sail artisan make:filament-page --help

Install approved dependency during implementation:
vendor/bin/sail npm install --save-exact --ignore-scripts three@0.185.1

Focused PHP tests:
vendor/bin/sail artisan test --compact tests/Feature/Filament/ArAssetStudioTest.php
vendor/bin/sail artisan test --compact tests/Feature/ArAssetPreviewTest.php
vendor/bin/sail artisan test --compact tests/Feature/RemoteFrameAssetTest.php
vendor/bin/sail artisan test --compact tests/Feature/RemoteFrameAssetActionsTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameCatalogTest.php

JavaScript unit tests:
vendor/bin/sail node --test resources/js/ar-asset-studio/*.test.js

Routes, build, dependency audit, and formatting:
vendor/bin/sail artisan route:list --path=ar-assets --except-vendor
vendor/bin/sail npm run build
vendor/bin/sail npm audit --omit=dev
vendor/bin/sail bin pint --dirty --format agent
```

## Boundaries

### Always

- use the existing AR domain actions for lifecycle effects;
- authorize the page, preview response, Livewire mutation, and domain action;
- validate the GLB and calibration server-side;
- keep quarantine private and publication paths server-generated;
- keep current patient images and the published version active through any
  candidate failure;
- preserve audit events and immutable version history;
- dispose browser rendering resources; and
- run focused tests, the JavaScript tests, Vite build, dependency audit, and
  Pint after implementation.

### Ask first

- add any dependency other than the approved exact Three.js version;
- add or alter a database column or status value;
- change the patient API or Android calibration contract;
- broaden accepted GLB extensions or compression formats;
- introduce webcam, image upload, biometric, or patient data;
- change public storage/CDN configuration; or
- change the existing 10 MiB, triangle, or texture limits.

### Never

- expose a quarantine path or unsigned public quarantine URL;
- trust the client preview or checklist as the validation boundary;
- silently apply a calibration preset;
- publish when WebGL preview was unavailable to the operator;
- fetch a user-supplied model URL from the server;
- load scripts, models, textures, or reference-head assets from a CDN;
- reinterpret the legacy `ar_asset_reference`; or
- claim the reference-head view predicts physical or clinical fit.

## Not Doing

- a two-person review or approval queue;
- a standalone/global AR Assets navigation resource;
- patient uploads;
- live webcam or MediaPipe face tracking in Filament;
- AI model generation or Blender automation;
- bulk upload or SKU filename matching;
- automatic calibration derived from mesh bounds;
- a general-purpose 3D editor;
- destructive deletion of historical versions;
- an Android UI or renderer change; or
- removal of legacy patient API fields.

## Success Criteria

- [ ] A frame variant exposes one **Manage 3D model** entry point instead of
      seven lifecycle actions.
- [ ] One authorized operator can publish a valid first version or replacement
      without another account.
- [ ] The operator sees the selected GLB before it is published.
- [ ] Model and Reference face modes both render the same calibration state.
- [ ] Gizmos, sliders, and numeric fields remain synchronized and reversible
      during the browser session.
- [ ] Four available physical measurements prefill from the variant without
      silently selecting a preset.
- [ ] Physical-match attestation is explicit, complete, and server-enforced.
- [ ] Invalid calibration does not force re-uploading a structurally valid GLB.
- [ ] A failed replacement never removes or changes the current patient model.
- [ ] A retained private candidate is accessible only to active staff/admin and
      never exposes its storage path.
- [ ] Existing immutable publication, integrity checking, version history,
      disablement, rollback, and audit behavior remain passing.
- [ ] Patient API response shapes and legacy AR fields remain unchanged.
- [ ] The studio is keyboard-operable without relying on a 3D gizmo, supports
      dark mode, and works without horizontal page scrolling at 1024 px.
- [ ] Three.js is page-scoped, exact-version locked, and leaves no model or
      render loop alive after navigation.
- [ ] Focused PHP and JavaScript tests, Vite build, dependency audit, and Pint
      complete successfully.

## Open Questions

No blocking product questions remain. The implementation plan must verify the
exact Filament 5 generator flags and choose the smallest file split that keeps
each task within the repository's normal five-file task ceiling.

## Source References

- Filament 5 custom resource pages:
  <https://filamentphp.com/docs/5.x/resources/custom-pages>
- Filament 5 file-upload security:
  <https://filamentphp.com/docs/5.x/forms/file-upload>
- Filament 5 security and per-request page authorization:
  <https://filamentphp.com/docs/5.x/advanced/security>
- Filament Vite asset registration pattern:
  <https://filamentphp.com/docs/5.x/widgets/charts>
- Laravel 13 file and streamed responses:
  <https://laravel.com/docs/13.x/responses>
- Three.js `GLTFLoader`:
  <https://threejs.org/docs/pages/GLTFLoader.html>
- Three.js `OrbitControls`:
  <https://threejs.org/docs/pages/OrbitControls.html>
- Three.js `TransformControls`:
  <https://threejs.org/docs/pages/TransformControls.html>
- Three.js `LoadingManager` and object-URL cleanup:
  <https://threejs.org/docs/pages/LoadingManager.html>
- Three.js npm package and selected stable version:
  <https://www.npmjs.com/package/three>
