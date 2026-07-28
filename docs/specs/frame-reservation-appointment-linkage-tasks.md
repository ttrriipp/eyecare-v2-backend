# Tasks: Required Appointment Link for Frame Reservations

## Status

Phase 3 draft for project-owner review on 2026-07-28. Complete these tasks in
order after approval. Use test-driven and incremental implementation for every
task.

Source documents:

- `docs/specs/frame-reservation-appointment-linkage-spec.md`
- `docs/specs/frame-reservation-appointment-linkage-plan.md`

## Progress

- [ ] R1 — Establish the required database and model relationship
- [ ] R2 — Implement transactional reservation creation rules
- [ ] R3 — Reconcile the patient reservation API
- [ ] R4 — Integrate Appointment cancellation and no-show cleanup
- [ ] R5 — Reconcile the Filament reservation display
- [ ] R6 — Reconcile seed data and living documentation
- [ ] R7 — Run final reservation regression and review

## R1 — Establish the required database and model relationship

**Description:** Make Appointment linkage mandatory in the canonical schema,
expose reservation history from Appointment, and make the default factory
produce matching records.

**Acceptance criteria:**

- [ ] `appointment_id` is non-null and restricts hard deletion.
- [ ] Factory-created reservations always share the Appointment patient.
- [ ] Tests no longer recognize standalone reservations as valid.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Reservations/FrameReservationTest.php`

**Dependencies:** None.

**Files likely touched:**

- `database/migrations/2026_07_25_230000_create_frame_reservations_table.php`
- `database/factories/FrameReservationFactory.php`
- `app/Models/Appointment.php`
- `tests/Feature/Reservations/FrameReservationTest.php`

**Estimated scope:** Medium.

## R2 — Implement transactional reservation creation rules

**Description:** Introduce a reusable action that owns eligibility, matching
patient, duplicate-active-reservation, distinct-variant, locking, and atomic
item creation rules.

**Acceptance criteria:**

- [ ] Valid scheduled Appointments create one reservation with one to five
  distinct active frame variants.
- [ ] Past, terminal, checked-in mobile, and duplicate-active cases are rejected
  without partial writes.
- [ ] Concurrent requests cannot create two active reservations.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Reservations/CreateFrameReservationTest.php`

**Dependencies:** R1.

**Files likely touched:**

- `app/Actions/Reservations/CreateFrameReservation.php`
- `app/Http/Requests/Api/StoreFrameReservationRequest.php`
- `app/Models/FrameReservation.php`
- `tests/Feature/Reservations/CreateFrameReservationTest.php`

**Estimated scope:** Medium.

## R3 — Reconcile the patient reservation API

**Description:** Route patient creation through the action and return safe,
embedded Appointment context from every reservation response.

**Acceptance criteria:**

- [ ] `appointment_id` is required and another patient's Appointment returns
  `404`.
- [ ] Owned conflicts return `409`; malformed inputs return `422`.
- [ ] Responses contain Appointment display context and no internal commercial
  or inventory data.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameReservationTest.php`

**Dependencies:** R2.

**Files likely touched:**

- `app/Http/Controllers/Api/FrameReservationController.php`
- `app/Http/Resources/FrameReservationResource.php`
- `tests/Feature/Api/V1/FrameReservationTest.php`

**Estimated scope:** Medium.

## Checkpoint A — Creation path

- [ ] R1–R3 focused suites pass together.
- [ ] The patient API contract is stable before lifecycle integration.
- [ ] No unrelated test was removed or weakened.

## R4 — Integrate Appointment cancellation and no-show cleanup

**Description:** Add one reusable cleanup action and invoke it inside the
existing Appointment transition transactions.

**Acceptance criteria:**

- [ ] Active linked reservations end as cancelled.
- [ ] Prepared allocation is restored exactly once with inventory history.
- [ ] Cleanup failure rolls back cancellation/no-show; terminal reservations
  remain unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentReservationCleanupTest.php`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Reservations/ReservationLifecycleTest.php`

**Dependencies:** R3.

**Files likely touched:**

- `app/Actions/Reservations/CancelReservationsForAppointment.php`
- `app/Actions/Appointments/CancelAppointment.php`
- `app/Actions/Appointments/MarkAppointmentNoShow.php`
- `tests/Feature/Appointments/AppointmentReservationCleanupTest.php`
- `tests/Feature/Reservations/ReservationLifecycleTest.php`

**Estimated scope:** Medium.

## R5 — Reconcile the Filament reservation display

**Description:** Make Appointment identity and schedule consistently visible
and remove the obsolete null-link `Walk-in` presentation.

**Acceptance criteria:**

- [ ] Every row shows its Appointment number and schedule.
- [ ] No `Walk-in` placeholder remains.
- [ ] Existing prepare, release, and view actions still work.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/FrameReservationResourceTest.php`

**Dependencies:** R4.

**Files likely touched:**

- `app/Filament/Resources/FrameReservations/Tables/FrameReservationsTable.php`
- `tests/Feature/Filament/FrameReservationResourceTest.php`

**Estimated scope:** Small.

## R6 — Reconcile seed data and living documentation

**Description:** Replace any standalone seeded reservations and document the
final required-link API behavior.

**Acceptance criteria:**

- [ ] All seeded reservations use matching eligible Appointments.
- [ ] API and backend documentation agree with response/error behavior.
- [ ] The documented route count remains accurate.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Seeders`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php`

**Dependencies:** R5.

**Files likely touched:**

- `database/seeders/ClinicWorkflowSeeder.php`
- `database/seeders/DashboardDemoSeeder.php`
- `docs/API_CONTRACT.md`
- `docs/BACKEND_CONTEXT.md`

**Estimated scope:** Medium.

## R7 — Run final reservation regression and review

**Description:** Format, run all affected suites and the complete test suite,
then audit the implementation against the approved spec.

**Acceptance criteria:**

- [ ] Focused reservation, inventory, Appointment, API, Filament, and seeder
  suites pass.
- [ ] Pint reports no remaining formatting changes.
- [ ] Full Pest suite passes and every specification success criterion is met.

**Verification:**

- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Reservations tests/Feature/Api/V1/FrameReservationTest.php tests/Feature/Filament/FrameReservationResourceTest.php`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `git diff --check`

**Dependencies:** R6.

**Files likely touched:** None beyond formatting corrections.

**Estimated scope:** Small.

## Completion Checkpoint

- [ ] All R1–R7 checkboxes are complete.
- [ ] Reservation specs, plans, tasks, code, tests, and documentation agree.
- [ ] The implementation is committed before Billing Record work begins.

