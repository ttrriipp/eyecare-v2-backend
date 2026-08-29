# Active Plan

`planning-and-task-breakdown` and `spec-driven-development` both expect the
current plan at `tasks/plan.md` and the current checklist at `tasks/todo.md`,
because downstream commands look there.

This repository runs several simplification projects concurrently, so each one
keeps its own feature-named pair and this file points at whichever is active.

## Active

**Reports Feature** — approved 2026-08-30; implementation intentionally paused

- Spec: `docs/specs/reports-feature-spec.md`
- Plan: `tasks/reports-feature-plan.md`
- Checklist: `tasks/reports-feature-todo.md`

The proposed scope is an admin-only Filament Reports cluster with Financial,
Appointments, Optical Orders, and Feedback pages. It uses canonical current
tables, keeps the patient API unchanged, and explicitly separates period flows
from current snapshots. The owner approved the administrator-only access model,
four internal report pages, metric definitions, and aggregate CSV scope, then
explicitly paused implementation until further instruction.

## Previous active entry (preserved)

**Contact-Lens Expiry Tracking** — implemented and verified 2026-08-28

- Spec: `docs/specs/contact-lens-expiry-tracking-spec.md`
- Plan: `tasks/contact-lens-expiry-tracking-plan.md`
- Checklist: `tasks/contact-lens-expiry-tracking-todo.md`

The approved scope keeps expiry lot-aware in the backend but simple for the
clinic owner: contact-lens receiving adds only lot number and expiry month,
sales allocate automatically by multi-lot FEFO, expired stock is blocked, and
the existing Inventory screen gains concise read-only expiry visibility.
Development stock is disposable, so no reconciliation workflow is built.

## Earlier active entry (preserved)

**Patient Account Self-Service Profile Editing** — implemented and verified

- Spec: `docs/specs/patient-account-self-service-profile-spec.md` (approved
  2026-08-28)
- Plan: `tasks/patient-account-self-service-profile-plan.md` (implemented and
  verified 2026-08-28)
- Checklist: `tasks/patient-account-self-service-profile-todo.md` (implemented
  and verified 2026-08-28)

The approved specification separates account-owned identity from the clinic's
Patient record. The plan permits self-service account names and step-up-gated
date of birth, keeps contacts/password on their verified workflows, prohibits
Patient writes, and expires stale pending link requests. Backend implementation
and focused verification are complete; deployment and Android changes remain
separate approval gates.

## Earlier active entry (preserved)

**Replace Frame Reservations with Saved Frames** — remediation complete 2026-08-26

- Spec: `docs/specs/saved-frames-replacement-spec.md` (approved 2026-08-26)
- Decision: `docs/decisions/003-replace-frame-reservations-with-saved-frames.md`
- Plan: `tasks/saved-frames-replacement-plan.md` (approved 2026-08-26)
- Checklist: `tasks/saved-frames-replacement-todo.md` (approved 2026-08-26;
  remediation implementation complete)

The replacement is account-owned, never holds stock, and is visible to clinic
staff only through the Patient's current account link. The project owner
authorized remediation implementation after verification found incomplete
migration safety, read-model, staff UI, test, and documentation work. The
affected Saved Frames suite is green; the full suite still has unrelated
pre-existing failures in other domains.

## Earlier proposed entry (preserved)

**Appointment Calendar and Request Schedule Review** — proposed 2026-08-21

- Spec: `docs/specs/appointment-calendar-and-request-schedule-review-spec.md`
- Plan: `tasks/appointment-calendar-and-request-schedule-review-plan.md`
- Checklist: `tasks/appointment-calendar-and-request-schedule-review-todo.md`

Its named files are unchanged; switching the active pointer does not reinterpret
or discard its existing progress.

## Completed (latest)

**AR Measured-Width Calibration Follow-up** — implemented 2026-08-24

- Spec: `docs/specs/ar-asset-admin-studio-spec.md`
- Plan: `tasks/ar-asset-admin-studio-plan.md`
- Checklist: `tasks/ar-asset-admin-studio-todo.md`

The Manage 3D model workflow now accepts the complete transformed rendered
width for editable candidates and applies the server-computed physical-width
ratio uniformly to the renderer scale. Physical measurements and the patient
API remain unchanged; validated and published assets still require replacement.
Browser preview remains explicitly deferred.

**One-Person AR Asset Publication** — implemented 2026-08-23

- Spec: `docs/specs/ar-asset-admin-studio-spec.md`
- Plan: `tasks/ar-asset-admin-studio-plan.md`
- Checklist: `tasks/ar-asset-admin-studio-todo.md`

The Products → Variants panel now uses one state-aware **Manage 3D model**
modal. One active staff/admin operator can upload or resume a GLB, enter or
review calibration, attest to the physical match, and validate/publish it in
one controlled action. The existing private quarantine, immutable publication,
audits, history, disablement, rollback, and patient API contract remain intact.
Browser preview and its frontend infrastructure are explicitly deferred.

**Consultation UI Terminology** — implemented 2026-08-21

- Spec: `docs/specs/consultation-ui-terminology-spec.md`
- Plan: `tasks/consultation-ui-terminology-plan.md`
- Checklist: `tasks/consultation-ui-terminology-todo.md`

This is a presentation-only rename from Encounter to Consultation. Internal
classes, schema, routes, audit values, and API contracts remain Encounter-based.

**Direct Messaging Hardening** — implemented 2026-08-15

- Spec: `docs/specs/direct-messaging-hardening-spec.md`
- Plan: `tasks/direct-messaging-hardening-plan.md`
- Checklist: `tasks/direct-messaging-hardening-todo.md`

All 18 tasks across seven phases are complete. The shipped work closes read
status on both sides, gives staff an activity-ordered inbox with archiving,
routes the notification feed, reconciles the messaging documents, removes
eight unreachable Filament classes, retires message contexts, and adds
conversation-scoped MySQL FULLTEXT search with stable cursor pagination.

Real-time transport is explicitly rejected in the spec's scope table; polling
is retained. Do not re-litigate it during implementation.

All four open questions in the plan were resolved by the owner on 2026-08-15:
one clinic-wide staff read watermark (not per-staff rows); message contexts
removed entirely, model and table included (Task 13, new); Android is ready
whenever the backend is, so Task 16 (message pagination) no longer requires a
separate authorization round — it stayed sequenced last; and new-message
notifications stay patient↔clinic only. Implementation and verification are
complete.

## Completed (earlier projects)

**Dead Code and Unreachable Feature Removal** — implemented 2026-08-14

- Spec: `docs/specs/dead-code-removal-spec.md`
- Plan: `tasks/dead-code-removal-plan.md`
- Checklist: `tasks/dead-code-removal-todo.md`

All five phases landed in `1c603ea`…`8274c0f`, including the Phase 4 contract
migration that the checklist had gated behind separate authorization. Scope was
intake, complaint, and `inventory_movement_statuses`. Message attachments and
privacy code were protected and remain intact.

Its spec cited `MessagesRelationManager` as evidence that message attachments
are live; that class is unreachable, so the citation was wrong even though the
conclusion held via four other references. Corrected under Direct Messaging
Hardening Task 9.

**Commerce Model Simplification** — implemented 2026-08-13

- Spec: `docs/specs/commerce-model-simplification-spec.md`
- Plan: `tasks/commerce-model-simplification-plan.md`
- Checklist: `tasks/commerce-model-simplification-todo.md`

**Minimal Frame Reservations** — implemented 2026-08-12

- Spec: `docs/specs/frame-reservation-simplification-spec.md`
- Plan: `tasks/frame-reservation-simplification-plan.md`
- Checklist: `tasks/frame-reservation-simplification-todo.md`

## Noted, not specified

**Appointments consolidation** — 2,815 lines across 29 actions, the largest
domain in the system. Recorded as out of scope in the dead-code spec because it
is *running* code with live consumers, so it needs its own investigation.

---

Update the Active section when switching projects.
