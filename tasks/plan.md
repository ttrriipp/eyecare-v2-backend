# Active Plan

`planning-and-task-breakdown` and `spec-driven-development` both expect the
current plan at `tasks/plan.md` and the current checklist at `tasks/todo.md`,
because downstream commands look there.

This repository runs several simplification projects concurrently, so each one
keeps its own feature-named pair and this file points at whichever is active.

## Active

**One-Person AR Asset Publication** — revised 2026-08-23

- Spec: `docs/specs/ar-asset-admin-studio-spec.md`
- Plan: `tasks/ar-asset-admin-studio-plan.md`
- Checklist: `tasks/ar-asset-admin-studio-todo.md`

This plan replaces the normal staged AR lifecycle actions with one state-aware
Filament modal where one authorized staff/admin operator can validate and
publish a GLB. Live preview and its frontend infrastructure are deferred.
Implementation has not started and remains gated on approval of the revised
specification, plan, and checklist.

## Previous active entry (preserved)

**Appointment Calendar and Request Schedule Review** — proposed 2026-08-21

- Spec: `docs/specs/appointment-calendar-and-request-schedule-review-spec.md`
- Plan: `tasks/appointment-calendar-and-request-schedule-review-plan.md`
- Checklist: `tasks/appointment-calendar-and-request-schedule-review-todo.md`

Its named files are unchanged; switching the active pointer does not reinterpret
or discard its existing progress.

## Completed (latest)

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
