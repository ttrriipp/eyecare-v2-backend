# Active Plan

`planning-and-task-breakdown` and `spec-driven-development` both expect the
current plan at `tasks/plan.md` and the current checklist at `tasks/todo.md`,
because downstream commands look there.

This repository runs several simplification projects concurrently, so each one
keeps its own feature-named pair and this file points at whichever is active.

## Active

None.

## Ready, deliberately deferred

**Dead Code and Unreachable Feature Removal** — parked 2026-08-13

- Spec: `docs/specs/dead-code-removal-spec.md`
- Plan: `tasks/dead-code-removal-plan.md`
- Checklist: `tasks/dead-code-removal-todo.md`

Fully specified and planned; 9 tasks ready to run. Held until the rest of the
system is final, because it is pure cleanup and gains nothing from landing
early — while re-running its audit is cheap.

**Re-verify before starting.** Its audit has been wrong five times across two
passes, and every wrong answer came from a search taken at face value. Any
work that lands between now and then can invalidate it again, so re-run the
four axes rather than trusting the recorded results.

It is purely subtractive — nothing user-visible changes, because every surface
it touches is already unreachable.

## Completed

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
