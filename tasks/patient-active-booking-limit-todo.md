# One Active Patient Booking Journey — Checklist

Related plan: patient-active-booking-limit-plan.md  
Status: Implementation pending

## 1. Characterization

- [ ] Update Pest coverage for the current maximum-active-request behavior.
- [ ] Cover new request, rebooking, ownership, request update, and staff review.
- [ ] Record the expected behavior for expired and stale requests.
- [ ] Add reliable concurrency coverage or focused transaction/lock integration
  coverage.

Checkpoint:

- [ ] Existing behavior is understood and protected before production code changes.

## 2. Shared eligibility

- [ ] Define the active appointment query semantics.
- [ ] Add an eligibility result value object.
- [ ] Add a shared evaluator action or service.
- [ ] Cover actionable pending requests.
- [ ] Cover expired and stale requests.
- [ ] Cover future scheduled appointments.
- [ ] Cover past scheduled appointments.
- [ ] Cover checked-in appointments.
- [ ] Cover fulfilled, cancelled, and no-show appointments.
- [ ] Cover rebooking of the same scheduled appointment.

Checkpoint:

- [ ] One evaluator produces all submission and response eligibility decisions.

## 3. One pending request

- [ ] Set appointment_requests.max_active_per_account to 1.
- [ ] Move the new-request check inside a database transaction.
- [ ] Lock the patient account before checking and creating.
- [ ] Apply the same safe path to rebooking.
- [ ] Keep ACTIVE_REQUEST_LIMIT_REACHED.
- [ ] Return max_active_requests as 1.
- [ ] Use a singular user-facing message.
- [ ] Include owned active_request_id if useful to the client.

Checkpoint:

- [ ] Two concurrent submissions can create at most one actionable request.

## 4. Active appointment conflict

- [ ] Block a new request for a future scheduled appointment.
- [ ] Block a new request for a checked-in appointment.
- [ ] Allow a new request after fulfilled, cancelled, or no-show.
- [ ] Allow a new request when a scheduled appointment is already in the past.
- [ ] Add the ACTIVE_APPOINTMENT_EXISTS domain exception and API mapping.
- [ ] Return HTTP 422 without leaking another patient's data.

Checkpoint:

- [ ] New request eligibility matches the agreed booking-journey policy.

## 5. Rebooking

- [ ] Allow rebooking of the patient's own future scheduled appointment.
- [ ] Treat that appointment and rebooking request as one journey.
- [ ] Reject a second pending rebooking request for the same appointment.
- [ ] Reject rebooking when another actionable request exists.
- [ ] Preserve ownership, availability, and timing validation.

Checkpoint:

- [ ] Rescheduling remains possible without permitting overlapping journeys.

## 6. Staff acceptance

- [ ] Lock the patient account while accepting a pending request.
- [ ] Recheck a new request for active-appointment conflicts.
- [ ] Leave records unchanged when acceptance fails.
- [ ] Allow valid rebooking acceptance to move the existing appointment.
- [ ] Keep authorized direct staff appointment creation unrestricted.

Checkpoint:

- [ ] A state change between patient submission and staff review cannot create a
  second active appointment.

## 7. API metadata

- [ ] Add meta.booking_eligibility to the appointment-request list response.
- [ ] Add can_submit_new_request.
- [ ] Add blocking_reason.
- [ ] Add owned active_request_id where applicable.
- [ ] Add owned appointment_id where applicable.
- [ ] Add can_request_rebooking.
- [ ] Test active_request_exists.
- [ ] Test scheduled_appointment_exists.
- [ ] Test checked_in_appointment_exists.
- [ ] Test the eligible null state.

Checkpoint:

- [ ] Android can render the correct booking action without duplicating rules.

## 8. Documentation

- [ ] Update docs/API_CONTRACT.md to describe one active booking journey.
- [ ] Document ACTIVE_REQUEST_LIMIT_REACHED with a maximum of 1.
- [ ] Document ACTIVE_APPOINTMENT_EXISTS.
- [ ] Document booking_eligibility and blocking reasons.
- [ ] Document the same-appointment rebooking exception.
- [ ] Document the staff workflow boundary.

## 9. Existing data

- [ ] Verify existing conflicting records are not mutated.
- [ ] Verify affected accounts cannot create another self-service journey.
- [ ] Verify they become eligible after active records are resolved.
- [ ] Do not add a destructive data migration.

## 10. Android companion work

- [ ] Create a corresponding task in the Android repository.
- [ ] Consume booking_eligibility.
- [ ] Replace or disable Request Appointment when blocked.
- [ ] Link pending state to the existing request.
- [ ] Link scheduled state to reschedule or cancel.
- [ ] Handle both authoritative backend 422 errors.
- [ ] Refresh eligibility after booking-state changes.

Checkpoint:

- [ ] The Android interface guides the patient, while the backend remains the
  enforcement boundary.

## 11. Verification

- [ ] Run SubmitAppointmentRequestTest.
- [ ] Run AppointmentRequestRebookingTest.
- [ ] Run AppointmentRequestOwnershipTest.
- [ ] Run UpdateAppointmentRequestTest.
- [ ] Run ReviewAppointmentRequestTest.
- [ ] Run AppointmentRequestRebookingReviewTest.
- [ ] Run any other affected tests.
- [ ] Run vendor/bin/sail bin pint --dirty --format agent.
- [ ] Confirm API contract examples match actual responses.
- [ ] Confirm no unrelated files changed.

## Done

- [ ] One pending request is enforced transactionally.
- [ ] Active scheduled and checked-in appointments block new requests.
- [ ] Same-appointment rebooking remains available.
- [ ] Staff acceptance is protected from intervening conflicts.
- [ ] Existing data is grandfathered.
- [ ] API metadata, errors, tests, and documentation are complete.
- [ ] Android companion work is ready or tracked separately.
