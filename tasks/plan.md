# Active Plan

`planning-and-task-breakdown` and `spec-driven-development` both expect the
current plan at `tasks/plan.md` and the current checklist at `tasks/todo.md`,
because downstream commands look there.

This repository runs several simplification projects concurrently, so each one
keeps its own feature-named pair and this file points at whichever is active.

## Active

None.

## Proposed, awaiting approval

**Direct Messaging Hardening** — drafted 2026-08-15

- Spec: `docs/specs/direct-messaging-hardening-spec.md`
- Plan: `tasks/direct-messaging-hardening-plan.md`
- Checklist: `tasks/direct-messaging-hardening-todo.md`

18 tasks in seven phases, no task over five files. Closes read status on both
sides (`messages.read_at` is currently written by nothing but a seeder, so the
mobile `unread_count` only ever increases), gives staff an inbox that surfaces
waiting threads, routes the already-written notification feed, reconciles three
documents with the shipped routes, removes eight unreachable Filament classes
under `app/Filament/Resources/Conversations/`, deletes `MessageContextLink`
and its table now that message contexts are retired outright, and adds
conversation-scoped full-text search (Phase 7, added 2026-08-15) using the
cursor pagination Task 16 establishes.

Real-time transport is explicitly rejected in the spec's scope table; polling
is retained. Do not re-litigate it during implementation.

All four open questions in the plan were resolved by the owner on 2026-08-15:
one clinic-wide staff read watermark (not per-staff rows); message contexts
removed entirely, model and table included (Task 13, new); Android is ready
whenever the backend is, so Task 16 (message pagination) no longer needs a
separate authorization round — it stays sequenced last and still requires
confirming Android readiness immediately before merge; and new-message
notifications stay patient↔clinic only. Still awaiting owner approval to begin
implementation.

## Completed

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
