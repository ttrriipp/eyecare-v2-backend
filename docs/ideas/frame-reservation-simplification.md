# Simplified Frame Reservations

> **Superseded by** `docs/specs/frame-reservation-simplification-spec.md`
> (approved 2026-08-12). The lifecycle described below was replaced by a
> two-state model carried by one nullable `accepted_at` timestamp.

## Problem Statement

How might we make frame reservations understandable and actionable for clinic staff while preserving inventory correctness and clear patient communication?

## Recommended Direction

Base the operational workflow on inventory state:

```text
Requested -> Ready -> Closed
```

- **Requested:** The clinic has received the candidate-frame choices, but no stock is allocated.
- **Ready:** Staff have prepared the frames and the stock is allocated.
- **Closed:** The reservation ended through a sale, cancellation, expiry, or manual release.

Remove `tried_on` from the operational lifecycle. Trying on frames is a visit event that does not independently change inventory or represent a reservation outcome. After the fitting, staff either complete the sale or release the frames. Any unresolved reservation closes automatically at clinic closing time on the appointment date as a fallback for no-shows or missed staff actions.

Staff and patients receive audience-appropriate labels instead of raw persistence values. Staff see the work that needs to happen and the closure outcome. Patients see whether the clinic has actually set frames aside and whether they need to take action.

## Staff Experience

Staff-facing lifecycle labels:

- **Needs Preparation** for a requested reservation
- **Ready for Appointment** while stock is allocated
- **Closed — Sold** when linked to an Optical Order
- **Closed — Cancelled** when the patient or appointment cancels
- **Closed — Expired** when the appointment-day deadline passes
- **Closed — Released** when staff manually end the hold

Primary staff actions are **Prepare Frames**, **Proceed with Frame**, and **Release Frames**. There is no **Mark Tried On** action.

## Patient Experience

Patient-facing lifecycle labels:

- **Request Received:** "The clinic has received your frame choices. They are not yet set aside."
- **Ready for Your Visit:** "These frames are set aside until [appointment-day clinic closing time]."
- **Purchased:** The reservation leaves the active list and links to the resulting Optical Order.
- **Cancelled:** The request was cancelled by the patient or with the appointment.
- **Reservation Ended:** The frames are no longer held because the reservation expired or the clinic released them.

The patient app must not label a requested reservation as "Reserved" because stock has not yet been allocated. It should not expose internal terms such as `prepared`, `converted`, or `released`.

## Expiration and Release Rules

- All unresolved reservations use clinic closing time on the appointment date as their deadline.
- Expiring a requested reservation closes it without an inventory movement.
- Expiring or manually releasing a ready reservation restores every allocated candidate frame.
- Staff normally close the reservation after the fitting by completing the sale or releasing the frames.
- Scheduled expiration is a fallback, not the normal try-on path.
- A quotation that is not accepted immediately does not continue holding stock. Its reservation backing is cleared while the selected Frame line remains a non-guaranteed quotation item. Stock availability is validated again when the sale is confirmed.
- A sold outcome should be derived from the linked Optical Order rather than maintained as duplicated truth where practical.

## Approved Assumptions

- [x] The clinic does not require a persisted "tried on" event for auditing or reporting.
- [x] Frames do not remain held while a patient considers an unaccepted quotation.
- [x] Appointment-day clinic closing time is the fallback deadline for both requested and ready reservations.
- [x] A quoted but released frame is not guaranteed and must pass a fresh stock check at sale confirmation.
- [x] An appointment retains one reservation row. A reversibly released row may be reopened while the Appointment remains eligible; sold, cancelled, no-show, and expired outcomes are terminal.

## MVP Scope

- Remove the staff-facing **Mark Tried On** action.
- Remove `tried_on` from the enum, actions, queries, tests, and UI.
- Present staff-facing statuses as **Needs Preparation**, **Ready for Appointment**, and **Closed** with an outcome.
- Record closure attribution sufficient to distinguish `sold`, `patient_cancelled`, `appointment_cancelled`, `expired`, and `clinic_released`.
- Expire both requested and ready reservations at appointment-day clinic closing time.
- Restore stock only when a closing reservation has allocated stock.
- Derive the sold outcome from the Optical Order relationship where practical.
- Provide patient-friendly presentation labels without exposing internal status terminology.
- Clear reservation backing from an unaccepted quotation after the fitting and revalidate frame availability at eventual sale confirmation.
- Expose one final patient API status contract rather than retaining raw
  persistence statuses or duplicate compatibility fields.
- Reopen the same reservation row only after `clinic_released`, `appointment_rescheduled`, or `last_frame_removed`; record the reactivation in the audit log.

## Not Doing (and Why)

- **Immediate allocation when a patient submits a request** — unreviewed requests should not hold scarce stock days before a visit.
- **Tracking try-on as a reservation status** — it does not represent inventory state or a terminal outcome.
- **Per-candidate item statuses** — the reservation only needs a candidate list and a selected quotation line.
- **Holding stock for an unaccepted quotation** — the approved workflow releases frames after the fitting when there is no immediate sale.
- **Legacy-data migration and compatibility shims** — the system has not been
  deployed, so development data may be reset and the final contract can ship
  directly.
- **A new reservation after the visit** — frame reservation remains strictly a before-the-visit tool.
- **A second reservation row for the same appointment** — reopening reuses the existing row and preserves the database uniqueness invariant.

## Resolved Decisions

- Patient notifications are deferred.
- A released reservation may reopen only after `clinic_released`, `appointment_rescheduled`, or `last_frame_removed`, while its Appointment remains scheduled and eligible. Reopening reuses the same row, replaces its candidates, recalculates expiry, and records an audit event.
- The application has not been deployed and has no production reservation data
  or released mobile contract. Implementation therefore uses a clean break:
  no tried-on reconciliation command, compatibility status, staged rollout, or
  historical-null repair. Development data may be reset if it conflicts with
  the final model.
- The patient API returns normalized `status` values `requested`, `ready`, or
  `closed`, plus a nullable closure `outcome`. It does not expose raw internal
  persistence values.
