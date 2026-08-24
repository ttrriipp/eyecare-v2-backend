# Spec: One-Person AR Asset Publication

## Status

**Implemented — 2026-08-24.**

This revision replaces the proposed live-preview Admin Studio with a smaller
server-driven workflow. Live browser preview, Three.js, WebGL, a private
candidate-preview route, and a dedicated Filament page are deferred.

Automated Filament, lifecycle, measured-width, and patient-contract
verification is complete.
The configured environment has no Chrome DevTools MCP server, so a real-browser
smoke check remains an environment follow-up rather than implementation
evidence.

## Proposed Direction

1. One active staff member or administrator may upload, calibrate, physically
   review, approve, and publish the same frame-variant GLB.
2. The normal workflow uses one state-aware Filament action from Products →
   Variants and one final submit.
3. Existing server-side GLB validation, private quarantine, immutable
   publication, checksums, audit events, version history, disablement, and
   rollback remain mandatory.
4. The patient API, Android contract, database schema, file limits, and public
   storage configuration remain unchanged. Internal lifecycle statuses may be
   added for safe administrative cleanup; they are never exposed through the
   patient contract.
5. The operator reviews the GLB using the clinic's existing modeling tools and
   physical frame. This backend does not claim to visually verify the model.

## Objective

Let one authorized clinic operator publish a valid first or replacement GLB
without handing the work to another account or invoking separate Upload,
Submit for review, Approve, and Publish actions.

Success means the operator can:

1. open one **Manage 3D model** action for a frame variant;
2. choose one GLB or resume the variant's one actionable candidate;
3. complete calibration and one explicit physical-match attestation; and
4. select **Validate & publish** to run the existing internal lifecycle.

The publication states remain:

```text
quarantined → validated → approved → published
```

They may occur in one request so existing records, audits, and recovery
semantics stay compatible.

Unpublished candidates may instead be marked `discarded` by an authorized
administrator action. This preserves the asset row and audit subject while
removing its private quarantine file. Published, superseded, and disabled
versions are not discardable; use **Disable 3D model** or **Rollback** for
those lifecycle states.

## Users and Authorization

Authorized operators are active users for whom `isStaff()` or `isAdmin()` is
true. Patients, inactive users, guests, and optometrist-only users remain
unauthorized.

`ArAssetAuthorizer` remains the authority at every domain boundary. The
Filament action being hidden is not an authorization control.

The same operator may be recorded in `uploaded_by`, `approved_by`, and
`published_by`. Same-actor approval is permitted only when the one-person
coordinator explicitly requests it; direct approval keeps its safer default.

New publication also requires an active frame Product and active Product
Variant. Existing history remains available when publication is unavailable.

## Operator Experience

### Primary action

Each frame variant exposes one primary **Manage 3D model** action.

- With no candidate, it accepts one `.glb` up to 10 MiB.
- With a `quarantined`, `validated`, or `approved` candidate, it resumes that
  candidate and does not accept a second hidden upload.
- With a published model and no candidate, it starts a replacement while the
  current patient model remains active.
- More than one non-terminal candidate blocks publication with a resolution
  message rather than choosing one silently.

The outgoing Submit, Approve, and Publish row actions are removed from the
normal UI. **Discard 3D upload** is available for an unpublished
`quarantined`, `validated`, `approved`, or `rejected` candidate. Version
history, Disable, and Rollback remain secondary operations.

### Calibration

The modal contains the existing public calibration contract:

- frame width, outer frame height, lens width, lens height, bridge width, and
  temple length in millimeters;
- scale x/y/z;
- anchor x/y/z; and
- rotation x/y/z in degrees.

Available lens width, lens height, bridge, and temple measurements prefill from
the Product Variant without selecting a model-specific preset. Existing
candidate calibration takes priority when resuming. The reviewed round-frame
preset remains an explicit choice and is never silently applied.

- For separated-object models, the operator may enter the measured rendered
  width to apply a uniform server-side scale correction.

Calibration remains editable for a new upload or a quarantined candidate. A
validated or approved candidate displays its persisted calibration read-only;
the coordinator uses those saved values rather than accepting an unreviewed
change after validation.

All values are revalidated by `ArCalibration`. Dimensions must be positive,
all values finite, and scale values non-zero.

### Measured-width adjustment

For a separated-object GLB, the operator may enter the complete frame width
measured after all relevant node/world transforms are applied, using the
current renderer scale. The field is UI-only and is never stored as a second
physical measurement. The server computes:

```text
factor = frame_width_mm / measured_rendered_width_mm
adjusted_scale = current_scale × factor
```

The factor is applied uniformly to `scale.x`, `scale.y`, and `scale.z` so an
existing non-uniform proportion correction is preserved. A model already
measuring 123 mm is unchanged; a 61.5 mm result doubles the current scale. The
operator must measure the complete frame, not one separated mesh, and must not
bake the same correction into both the GLB node transforms and calibration.
The physical dimensions remain unchanged. The adjustment is available only
while calibration is editable; validated, approved, and published assets still
require a replacement/version rather than in-place mutation.

### Physical-match attestation

One required checkbox states:

> I compared this GLB with the physical frame and confirm that it represents
> this catalog variant, including its silhouette, bridge, material, color, and
> proportions.

The checkbox is enforced by the Filament form and again by the coordinator so
a tampered Livewire request cannot bypass it.

The final button is **Validate & publish**. No browser preview or WebGL support
is required.

## Domain Workflow

Add `PublishArAssetCandidate` as the coordinator. It delegates to the existing
single-purpose actions and does not duplicate GLB parsing, calibration
normalization, storage, checksum generation, locking, publication, or audit
logic.

```php
final class PublishArAssetCandidate
{
    public function handle(
        ProductVariant $variant,
        ?UploadedFile $file,
        array $calibration,
        bool|int|string|null $physicalMatchConfirmed,
        User $actor,
        mixed $measuredRenderedWidthMm = null,
    ): ArAsset;
}
```

Before creating a candidate, the coordinator:

- authorizes the actor;
- verifies the attestation;
- normalizes calibration;
- verifies the Product and Variant may publish;
- when supplied, validates the measured transformed width and adjusts the
  renderer scale from the physical frame width;
- checks the HTTPS asset base URL and publication disk; and
- serializes candidate selection and creation per variant, rejecting legacy
  ambiguity when more than one actionable candidate exists.

It then creates or resumes the candidate and advances only the applicable
states. `ApproveArAsset` receives an explicit self-approval option from this
coordinator; its default call path continues rejecting uploader self-approval.
When that exception is used, the approval audit metadata records that
separation of duties was explicitly bypassed by the one-person workflow.

## Failure and Recovery

- Invalid form calibration, missing attestation, a missing file when no
  actionable candidate exists, or known publication preflight failure creates
  no candidate.
- A missing, non-finite, or non-positive measured rendered width is rejected
  before a new candidate is created when the adjustment field is used.
- Structurally invalid GLB data may retain the existing rejected record and
  human-readable validation reason, but never reaches public storage.
- Invalid calibration on an existing structurally valid candidate leaves it
  quarantined and correctable instead of rejecting the GLB.
- A failed upload database transaction removes its orphaned private object.
- Publication failure leaves the candidate approved and retryable.
- A replacement never changes the current patient model until immutable
  publication and the variant-pointer swap both succeed.
- The same primary action resumes `quarantined`, `validated`, or `approved`
  candidates. Rejected candidates require a new GLB.
- **Discard 3D upload** changes an unpublished candidate to `discarded`, keeps
  its row and audit history, and removes its private quarantine object. A
  failed object deletion is reported for operations follow-up; it does not
  make the candidate publishable.

No candidate row is destructively deleted. Existing immutable published
history remains available for rollback.

## Patient Contract

No `/api/v1` route or resource shape changes.

- `product_variants.published_ar_asset_id` remains the patient-facing pointer.
- Only a checksum-valid, non-expired `published` asset produces `ar.status:
  ready`.
- Quarantined, validated, approved, rejected, and discarded candidates remain
  private.
- Variant `images` remain the 2D fallback.
- `ar_eligible` and `ar_asset_reference` remain unchanged legacy fields.

## Security Boundaries

### Always

- authorize in the Filament action and every invoked domain action;
- validate extension, size, GLB structure, embedded resources, triangle and
  texture limits, calibration, and attestation server-side;
- derive quarantine and publication paths on the server;
- keep quarantine private and verify byte size and SHA-256 before publishing;
- preserve locking, immutable versions, audit attribution, and atomic pointer
  replacement; and
- escape operator-facing validation output.

### Ask first

- add or alter database columns or patient-facing status values;
- add a dependency;
- change the patient or Android contract;
- change file, triangle, or texture limits; or
- change public storage/CDN configuration.

### Never

- expose quarantine paths or unsigned quarantine URLs;
- accept a client-supplied storage path or model URL;
- treat the attestation as a substitute for GLB validation;
- silently apply a model-specific calibration preset;
- replace the current patient model before publication succeeds; or
- delete historical published versions.

## Project Structure

Expected changes remain within existing locations:

```text
app/Actions/ArAssets/PublishArAssetCandidate.php
app/Actions/ArAssets/DiscardArAsset.php
app/Actions/ArAssets/ApproveArAsset.php
app/Actions/ArAssets/SubmitArAssetForReview.php
app/Actions/ArAssets/UploadArAsset.php
app/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php
tests/Feature/RemoteFrameAssetTest.php
tests/Feature/RemoteFrameAssetActionsTest.php
tests/Feature/Api/V1/FrameCatalogTest.php
app/Enums/ArAssetStatus.php
app/Enums/AuditEvent.php
docs/BACKEND_CONTEXT.md
```

No JavaScript module, custom Blade page, controller, route, migration, or npm
dependency is planned.

## Testing Strategy

Pest feature tests cover:

- one active staff/admin performing upload through publication;
- direct approval retaining its self-approval guard unless explicitly
  coordinated;
- patient, inactive, guest, and optometrist-only authorization failures;
- required attestation and calibration validation before candidate creation;
- measured transformed-width adjustment and uniform scale-factor behavior;
- GLB validation and quarantine cleanup;
- first publication, replacement, resume, publication retry, disablement, and
  rollback;
- discarding unpublished candidates, private quarantine cleanup, and the
  protected published/superseded/disabled states;
- serialized candidate creation and legacy multiple-candidate ambiguity;
- separate uploaded, validated, approved, and published audit events attributed
  to the same operator, including explicit self-approval-exception metadata;
  and
- an unchanged patient frame response.

Verification commands:

```text
vendor/bin/sail artisan test --compact tests/Feature/RemoteFrameAssetTest.php
vendor/bin/sail artisan test --compact tests/Feature/RemoteFrameAssetActionsTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameCatalogTest.php
vendor/bin/sail bin pint --dirty --format agent
```

The final focused run on 2026-08-24 passed 65 tests and 332 assertions across
the three suites. Pint and `git diff --check` also passed. A real-browser smoke
run was not available because Chrome DevTools MCP is not configured in this
environment; the Livewire modal tests cover the server-rendered form, upload,
authorization, state transitions, and failure notifications.

## Success Criteria

- [x] A frame variant exposes one primary **Manage 3D model** action instead of
      separate upload, submit, approve, and publish actions.
- [x] One active staff/admin can publish a valid first version or replacement
      without another account.
- [x] Calibration reuses variant values where available and requires explicit
      values elsewhere.
- [x] A measured transformed width adjusts all scale axes by the server-computed
      ratio while leaving physical measurements unchanged.
- [x] Physical-match attestation is explicit and server-enforced.
- [x] Invalid preflight input creates no candidate; invalid GLB never reaches
      public storage.
- [x] An existing non-terminal candidate can be resumed safely.
- [x] Unpublished candidates can be discarded without deleting audit history or
      affecting a published patient model.
- [x] Failed publication remains retryable and preserves the patient pointer.
- [x] History, disable, rollback, integrity checks, and audit events remain.
- [x] Patient API and Android contracts are unchanged.
- [x] No preview runtime, frontend dependency, migration, or new route is
      introduced.
- [x] Focused Pest tests and Pint pass at every checkpoint.

## Deferred

- in-browser 3D preview;
- reference-head visualization;
- Three.js/WebGL and calibration gizmos;
- private candidate-preview route;
- dedicated Admin Studio page;
- bulk upload, automatic calibration, and Android changes.

## Open Questions

None. The owner approved the revised direction and measured-width adjustment;
the server-driven one-person workflow is implemented. Browser preview remains
explicitly deferred.
