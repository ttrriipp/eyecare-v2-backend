# Checklist: Exclusive Appointment Scheduling

**Status:** Implemented on 2026-09-17; full-suite pre-merge run remains pending.
**Plan:** `tasks/exclusive-appointment-scheduling-plan.md`

## Approval gates

- [x] Approve clinic-wide non-overlap, including partial overlaps—not only
  identical start times.
- [x] Confirm that back-to-back appointments remain allowed.
- [x] Keep the 15-minute appointment start grid and both availability APIs.
- [x] Keep pending requests non-binding.
- [x] Keep walk-ins as immediate queue arrivals outside scheduled-slot
  exclusivity.
- [x] Keep provider eligibility/absence checks, but remove provider-count
  capacity.
- [x] Preserve the public `capacity_reached` reason for compatibility while
  removing capacity wording from staff UI.

## Phase 0: Characterize

- [x] Replace parallel-provider booking tests with clinic-wide conflict tests.
- [x] Cover exact, leading, trailing, containing, and contained overlaps.
- [x] Cover adjacent intervals.
- [x] Cover cancelled and no-show release behavior.
- [x] Cover ignored appointment behavior during rescheduling.

## Phase 1: Simplify availability

- [x] Replace numeric capacity with binary interval occupancy.
- [x] Restrict blocking statuses to scheduled and checked-in.
- [x] Keep assigned-provider active/role/absence checks.
- [x] Require at least one eligible provider for unassigned appointments.
- [x] Remove numeric capacity methods and parameters.
- [x] Simplify day-scoped slot generation.
- [x] Keep query count bounded independently of generated slot count.
- [x] Verify both availability endpoints agree.
- [x] Keep linked rebooking exclusion.
- [x] Keep pending requests non-binding.

## Phase 2: Protect mutations

- [x] Verify direct staff creation locks and rechecks before insert.
- [x] Verify scheduled mobile creation locks and rechecks before insert.
- [x] Verify new request acceptance locks and rechecks before insert.
- [x] Verify staff rescheduling locks old/new dates in stable order.
- [x] Verify linked rebooking acceptance does the same.
- [x] Verify calendar drag/drop commits through the rescheduling action.
- [ ] Prove only one of two pending requests for the same interval can be
  accepted.
- [x] Retain deadlock retry behavior.

## Phase 3: Remove capacity presentation

- [x] Replace `Clinic capacity available` with `Time available`.
- [x] Replace `X of Y clinic slots available` with binary availability.
- [x] Replace capacity-reached staff labels with plain time-unavailable language.
- [x] Retain clear assigned-provider absence/ineligibility labels.
- [x] Rewrite obsolete numeric-capacity tests.
- [x] Remove stale capacity comments and test names.
- [x] Confirm no retained test expects simultaneous appointments.

## Phase 4: Reconcile and verify

- [x] Re-run the target-database active-overlap audit (no overlaps found).
- [ ] Stop for clinic review if conflicts exist; do not delete appointments.
- [x] Update `docs/BACKEND_CONTEXT.md`.
- [x] Update availability semantics in `docs/API_CONTRACT.md` without changing
  response shape.
- [x] Leave historical specifications unchanged.
- [x] Run the stale capacity-reference scan.
- [x] Run focused scheduling, request, API, Filament, and lock tests.
- [x] Run `vendor/bin/sail bin pint --dirty --format agent`.
- [ ] Run the full suite; reserve this for the pre-merge/CI parallel run per
  the layered workflow.
- [x] Review transaction ordering and every scheduled appointment write path.

## Done when

- [x] No active scheduled intervals overlap clinic-wide.
- [x] Provider count never creates simultaneous booking capacity.
- [x] Back-to-back bookings work.
- [x] Patient and staff availability decisions agree.
- [x] Concurrent writes use the existing date lock and authoritative recheck.
- [x] The 15-minute start grid remains and numeric clinic capacity is gone.
