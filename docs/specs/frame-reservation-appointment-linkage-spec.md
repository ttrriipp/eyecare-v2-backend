# Spec: Required Appointment Link for Frame Reservations

## Status

Approved by the project owner on 2026-07-28. Phase 1 of the spec-driven
workflow is complete. Implementation remains gated on approval of the Phase 2
plan and Phase 3 task breakdown.

This change is intentionally separate from the Internal Billing Record
Simplification specification. It affects appointment and frame-reservation
lifecycle rules, not billing.

## Confirmed Assumptions

1. A Frame Reservation represents frames selected for an actual clinic visit.
2. Every Frame Reservation must belong to exactly one Appointment.
3. The reservation patient and appointment patient must always be the same.
4. A walk-in is represented by an Appointment created by the clinic before a
   reservation is created.
5. Mobile patients cannot reserve a frame without first having an eligible
   appointment.
6. Existing records are development or seeded data and may be replaced. No
   production backfill for reservations with a null appointment is required.
7. This requirement does **not** mean every Appointment must have a Frame
   Reservation. An Appointment may have zero reservations.

## Objective

Remove standalone Frame Reservations and make the Appointment the required
clinical context for each reservation.

The target patient flow is:

```text
Patient has or books an eligible Appointment
    -> browses frames
    -> selects up to five frame variants
    -> chooses the eligible Appointment
    -> creates the Frame Reservation
    -> clinic prepares the selected frames for that visit
```

The target walk-in flow is:

```text
Clinic creates walk-in Appointment
    -> checks in patient when appropriate
    -> Frame Reservation is linked to that Appointment
```

## Domain Rules

### Required relationship

- `frame_reservations.appointment_id` is required and cannot be null.
- The linked Appointment must exist.
- `frame_reservations.patient_id` must equal the linked Appointment's
  `patient_id`.
- The application enforces the cross-table patient invariant in the
  transactional creation action.
- The foreign key prevents an Appointment from being hard-deleted while a
  reservation references it. Normal Appointment removal continues to use soft
  deletion.

### Eligible appointments

For a patient creating a reservation through the mobile API, the Appointment
must:

- belong to the authenticated patient's Patient record;
- have status `scheduled`;
- not be soft-deleted;
- have a scheduled end time that has not passed.

The scheduled end time is:

```text
scheduled_at + duration_minutes
```

Using the end time permits a same-day patient to reserve shortly before or
during the booked slot while preventing reservations against historical
appointments.

For any future clinic-side creation flow, an authorized clinic user may link a
reservation to a matching patient's Appointment in either `scheduled` or
`checked_in` status. That future interface is outside this specification; the
same domain action must enforce the rule when it is added.

Appointments in these states cannot receive a new reservation:

- `fulfilled`
- `cancelled`
- `no_show`

### Reservation count

- one Appointment may have at most one active Frame Reservation;
- that reservation may contain one to five distinct frame variants;
- active statuses are `requested`, `prepared`, and `tried_on`;
- terminal statuses are `converted`, `released`, and `cancelled`;
- after a terminal reservation, a new reservation may be created for the same
  eligible Appointment when operationally necessary.

This avoids multiple competing reservation records for one visit while
preserving the existing multi-frame try-on behavior.

### Duplicate variants

- A frame variant may appear only once within a reservation.
- The request continues to accept between one and five items.
- Only active variants of active frame products are eligible.

## Appointment Lifecycle Integration

### Rescheduling

- Rescheduling an Appointment does not create or relink the reservation.
- Its reservation remains attached to the same Appointment record and follows
  the new schedule.
- The patient API reflects the updated Appointment schedule.

### Cancellation

When a linked Appointment is cancelled:

- active `requested` reservations become `cancelled`;
- active `prepared` reservations release their allocated stock and then become
  `cancelled`;
- active `tried_on` reservations become `cancelled`, with any applicable
  allocation released;
- terminal reservations remain unchanged;
- Appointment cancellation and reservation cleanup occur in one database
  transaction;
- inventory movements and audit records remain intact.

Patient cancellation of a reservation without cancelling the Appointment
continues to be allowed only in the currently approved cancellable reservation
states.

### No-show

When a linked Appointment is marked `no_show`, active reservations follow the
same cleanup rules as cancellation. Prepared stock must not remain allocated to
a missed Appointment.

### Fulfillment

- A fulfilled Appointment cannot receive a new Frame Reservation.
- Fulfillment does not silently discard an existing reservation.
- The normal reservation lifecycle must explicitly convert, release, or cancel
  the reservation as appropriate.

## Data Model

### Frame reservations

The canonical schema changes from:

```text
appointment_id nullable, null on delete
```

to:

```text
appointment_id required, foreign key, restrict on delete
```

The existing `patient_id` remains on the reservation for authorization,
patient-scoped queries, and integrity checks.

The Appointment model exposes:

```text
frameReservations(): HasMany
```

This remains a `HasMany` relationship because terminal reservation history may
exist even when only one active reservation is allowed.

The Frame Reservation factory creates a matching Appointment by default.
Explicit factory states may provide an existing Appointment while always
copying its patient.

## Patient API

### Create reservation

```text
POST /api/v1/frame-reservations
```

Request:

```json
{
  "appointment_id": 42,
  "items": [
    {
      "product_variant_id": 18
    }
  ]
}
```

Validation:

- `appointment_id`: required integer;
- Appointment must exist within the authenticated patient's scope;
- Appointment must satisfy the mobile eligibility rules;
- `items`: required array with one to five entries;
- `items.*.product_variant_id`: required, distinct, active frame variant.

Failures:

- missing or invalid request data: `422`;
- Appointment not owned by the patient: `404`, so another patient's record is
  not disclosed;
- owned but ineligible Appointment: `409`;
- another active reservation already exists for that Appointment: `409`;
- unauthenticated request: `401`.

Creation of the reservation and all reservation items occurs in one database
transaction.

### Reservation responses

Both reservation list and creation responses retain the patient-safe resource
and add the Appointment context Android needs:

```json
{
  "id": 7,
  "status": "requested",
  "expires_at": null,
  "created_at": "2026-07-28T10:30:00+08:00",
  "appointment": {
    "id": 42,
    "appointment_number": "APT-2026-000042",
    "status": "scheduled",
    "scheduled_at": "2026-07-30T09:00:00+08:00",
    "duration_minutes": 30
  },
  "items": []
}
```

The resource may retain `appointment_id` during the Android migration, but
`appointment` is authoritative display context. The response continues to
exclude patient identifiers, staff notes, inventory quantities, cost prices,
thresholds, deleted timestamps, and other internal commercial fields.

### Eligible Appointment selection

Android may use the existing patient Appointment list and filter it for
`scheduled` appointments whose scheduled end has not passed. The backend
remains authoritative and repeats all eligibility checks during reservation
creation.

If Android later needs a purpose-built eligible-appointments endpoint, that is
a separate API-contract change and is not required for this implementation.

## Web Panel

- Reservation tables and detail pages always show the linked Appointment number
  and schedule.
- Remove the `Walk-in` placeholder from the Appointment column because null
  links no longer exist.
- The linked Appointment is read-only after reservation creation.
- Existing prepare, release, and view actions remain, subject to the lifecycle
  rules above.
- No clinic-side Create Reservation page is added in this scope.

## Audit and Privacy

- Another patient's Appointment is never exposed through validation errors.
- Patient API queries remain scoped to the authenticated Patient record.
- Creation, cancellation, automatic release, and lifecycle transitions retain
  responsible actor and timestamps through the existing audit mechanisms.
- API resources expose only fields needed by the patient application.
- Inventory movements remain append-only and attributable.

## Project Structure

Expected implementation areas:

```text
app/
  Actions/
    Appointments/
      CancelAppointment.php
      MarkAppointmentNoShow.php
    Reservations/
      CreateFrameReservation.php
      CancelReservationsForAppointment.php
  Http/
    Controllers/Api/FrameReservationController.php
    Requests/Api/StoreFrameReservationRequest.php
    Resources/FrameReservationResource.php
  Models/
    Appointment.php
    FrameReservation.php
database/
  factories/
    FrameReservationFactory.php
  migrations/
    2026_07_25_230000_create_frame_reservations_table.php
  seeders/
tests/
  Feature/
    Api/V1/FrameReservationTest.php
    Appointments/
    Reservations/
docs/
  API_CONTRACT.md
  BACKEND_CONTEXT.md
```

The exact class boundary may be refined during planning after version-specific
Laravel documentation is checked. Creation and appointment cleanup must live in
reusable domain actions rather than only in controllers or Filament callbacks.

## Code Style

- Follow the existing Laravel 13, Filament 5, and PHP 8.5 conventions.
- Use explicit parameter and return types.
- Use PHP 8 constructor property promotion where dependencies are injected.
- Use curly braces for every control structure.
- Use Eloquent relationships and scoped queries rather than trusting raw
  request identifiers.
- Keep controllers thin.
- Use database transactions for multi-record state changes.
- Reuse existing reservation release logic while making the final status
  intentional.
- Do not add dependencies.

## Testing Strategy

Implementation follows test-driven development with focused Pest feature tests.

### Data integrity

- a reservation cannot be persisted without an Appointment;
- factory reservations always have matching patient and Appointment records;
- the Appointment relation exposes reservation history;
- hard deletion cannot orphan a reservation.

### Patient API

- a patient can create a reservation for their own upcoming scheduled
  Appointment;
- `appointment_id` is required;
- another patient's Appointment returns `404`;
- cancelled, no-show, fulfilled, deleted, and past Appointments are rejected;
- checked-in Appointments are rejected through the patient endpoint;
- duplicate variants are rejected;
- a duplicate active reservation is rejected;
- terminal history permits a new reservation while the Appointment remains
  eligible;
- creation and item persistence are atomic;
- responses include safe Appointment context and no internal inventory or
  commercial fields.

### Lifecycle

- rescheduling retains the reservation link;
- cancelling an Appointment cancels requested reservations;
- cancelling an Appointment releases prepared inventory exactly once;
- marking an Appointment no-show performs the same cleanup;
- terminal reservations are not mutated by Appointment cleanup;
- failure during cleanup rolls back the Appointment transition;
- fulfillment cannot create a new reservation.

### Web panel

- clinic users can see the linked Appointment;
- the table no longer presents a null Appointment as `Walk-in`;
- existing reservation actions remain authorized and functional.

Run the smallest affected suites during implementation, followed by the full
suite before handoff.

## Commands

```bash
# Start services
vendor/bin/sail up -d

# Inspect routes and version-specific framework behavior
vendor/bin/sail artisan route:list --path=api/v1/frame-reservations

# Run focused tests
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameReservationTest.php
vendor/bin/sail artisan test --compact tests/Feature/Reservations

# Format modified PHP files
vendor/bin/sail bin pint --dirty --format agent

# Run the full regression suite
vendor/bin/sail artisan test --compact
```

## Boundaries

Included:

- mandatory Appointment linkage;
- patient/Appointment ownership integrity;
- Appointment lifecycle eligibility;
- cancellation and no-show cleanup;
- patient-safe Appointment context in reservation responses;
- corresponding factory, seed, test, and documentation updates.

Excluded:

- requiring every Appointment to have a reservation;
- adding a clinic-side reservation creation page;
- changing the one-to-five frame selection limit;
- changing reservation inventory allocation semantics outside cancellation and
  no-show cleanup;
- adding lenses or accessories to Frame Reservations;
- Android implementation;
- Billing Record implementation.

## Success Criteria

1. No Frame Reservation can exist without an Appointment.
2. No reservation can link a patient to another patient's Appointment.
3. Mobile patients can reserve only for their own eligible Appointment.
4. Walk-ins use a real Appointment rather than a null-link shortcut.
5. Cancelled and no-show Appointments cannot leave prepared frame stock
   allocated.
6. Patient responses provide useful Appointment context without leaking
   internal or another patient's information.
7. Existing reservation lifecycle and inventory tests continue to pass after
   being updated to create valid Appointment-backed records.
8. The API contract and backend context document the final behavior.

## Resolved Decision

One Appointment may have at most one active Frame Reservation containing up to
five frames. This keeps the mobile flow and clinic preparation queue
unambiguous while preserving terminal history.
