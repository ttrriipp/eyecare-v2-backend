# Implementation Plan: Required Appointment Link for Frame Reservations

## Status

Approved by the project owner on 2026-07-28. Phase 2 is complete.
Implementation remains gated on approval of the Phase 3 task breakdown.

Source specification:
`docs/specs/frame-reservation-appointment-linkage-spec.md`

## Overview

Make Appointment linkage a database and domain invariant, then update the
patient creation path and Appointment terminal transitions around it. The work
is deliberately ordered to expose integrity failures early and keep every
checkpoint testable.

## Architecture Decisions

1. **Canonical schema replacement:** update the existing canonical migration
   because the database contains only replaceable development data.
2. **Domain action for creation:** move multi-record reservation creation from
   the API controller into a transactional action that derives the patient from
   the Appointment.
3. **Scoped ownership lookup:** resolve a patient's Appointment through their
   relationship so another patient's identifier produces `404`.
4. **Conflict errors:** use `409` for owned Appointments that are ineligible or
   already have an active reservation; use `422` only for malformed input.
5. **One active reservation:** enforce the rule transactionally with row
   locking. Retain `HasMany` history for terminal reservations.
6. **Central terminal cleanup:** Appointment cancellation and no-show call one
   reusable reservation-cleanup action inside their existing transactions.
7. **Intentional terminal status:** automatic Appointment cleanup ends active
   reservations as `cancelled`; inventory release remains represented by
   append-only inventory movements.
8. **Patient-safe resource:** embed only the linked Appointment display context
   in `FrameReservationResource`.

## Dependency Graph

```text
Canonical schema + factories
    -> creation invariant/action
        -> patient API contract/resource
    -> Appointment cleanup action
        -> cancellation integration
        -> no-show integration
    -> Filament display
        -> seed/document reconciliation
            -> full regression verification
```

## Implementation Sequence

### Stage A: Integrity foundation

- Change the canonical foreign key to required/restrict-on-delete.
- Add the Appointment reservation-history relationship.
- Make Frame Reservation factories Appointment-backed by default.
- Replace tests asserting that standalone reservations are valid.

Checkpoint:

- schema and model tests prove that null and orphaned links are impossible;
- reservation and inventory lifecycle tests use valid matching Appointments;
- no behavior outside reservations changes.

### Stage B: Transactional patient creation

- Introduce the reusable reservation-creation action.
- Require and validate `appointment_id` and distinct frame variants.
- Enforce ownership, scheduled/future eligibility, and one-active-reservation
  rules under transaction and lock.
- Keep the controller responsible only for authenticated context and response.

Checkpoint:

- all success, `401`, `404`, `409`, and `422` API cases pass;
- partial reservation/item writes are impossible;
- prepared stock behavior remains unchanged.

### Stage C: Appointment terminal transitions

- Introduce a single action that cancels active reservations and releases any
  prepared allocation exactly once.
- Invoke it from Appointment cancellation and no-show transitions inside their
  existing transactions.
- Preserve terminal reservation history.

Checkpoint:

- cancelling and marking no-show roll back if reservation cleanup fails;
- prepared stock is restored once;
- requested/tried-on records end as cancelled;
- rescheduling retains the original link.

### Stage D: Read surfaces and reconciliation

- Add safe Appointment context to the patient API resource.
- Require the Appointment relation in list/create loading.
- Remove the Filament `Walk-in` placeholder and display the Appointment number
  and schedule.
- Replace invalid seeded reservations.
- Reconcile `API_CONTRACT.md` and `BACKEND_CONTEXT.md`.

Checkpoint:

- the web list contains no unlinked state;
- Android receives sufficient Appointment context;
- internal inventory/commercial fields remain absent;
- route count changes only if explicitly documented (none expected).

### Stage E: Final verification

- Run focused API, reservation, inventory, Appointment lifecycle, Filament,
  seeder, and route-contract suites.
- Run Pint on modified PHP files.
- Run the complete Pest suite.
- Review the implementation against every success criterion in the approved
  specification.

## Sequential and Independent Work

Stages A through D are sequential because they share the schema, factories,
creation contract, and lifecycle actions.

After Stage C passes, Filament display work and documentation reconciliation
are logically independent, but they should still be landed in sequence in this
working tree to avoid conflicts with the Billing Record track.

The Billing Record implementation does not depend on this feature. This
reservation track should be completed first because it is smaller and gives
Android a stable booking/reservation contract sooner.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Existing tests create standalone reservations | High test churn | Fix the default factory first so dependent tests become valid mechanically |
| Concurrent duplicate requests | Multiple active reservations | Lock the Appointment and recheck active status inside the transaction |
| Cancellation restores stock twice | Incorrect inventory | Reuse idempotent release behavior and test repeated/terminal cleanup |
| Cross-patient identifier probing | Privacy leak | Resolve through patient scope and return `404` |
| Past-slot timezone errors | Valid reservation rejected | Compare in the configured `Asia/Manila` application timezone using scheduled end |
| Cancellation and cleanup diverge | Dead appointment retains allocation | Keep both mutations inside the same database transaction |

## Verification Gates

The Phase 3 task breakdown must preserve these gates:

1. Integrity foundation passes before API behavior changes.
2. API creation passes before Appointment transitions are integrated.
3. Terminal-transition tests pass before UI/document reconciliation.
4. Full regression passes before commit/handoff.

## Phase 2 Exit Criteria

- Architecture and ordering are approved.
- The one-active-reservation enforcement mechanism is accepted.
- Error semantics (`404`, `409`, `422`) are accepted.
- Cancellation/no-show cleanup behavior is accepted.
- Risks have concrete verification coverage.
- The plan is ready to be decomposed into Phase 3 checkbox tasks.
