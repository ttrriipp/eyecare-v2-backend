# Task Breakdown: Clinic Workflow Redesign

## Status

Approved by the project owner on 2026-07-25. Implementation resumed at Task 02.

This is Phase 3 (Tasks) of the approved spec-driven workflow. It replaces the
earlier 78-task compatibility plan and the interim 24-task outline with 40
single-session tasks across the same eight clean-cutover milestones.

## Sizing and Execution Rules

- Tasks are dependency-ordered vertical slices, not horizontal database/API/UI
  phases.
- Each feature task targets roughly 1–5 primary files and is estimated XS, S,
  or M.
- A path ending in `*` identifies a bounded group of generated Filament files
  or obsolete files. Mechanical removal must be performed in batches of at most
  five files, with verification after each batch.
- Obsolete tests are removed only after replacement tests prove the approved
  behavior.
- Every code task follows: Boost documentation search → failing Pest test →
  smallest implementation → focused tests → Pint.
- All PHP, Artisan, Composer, and Node commands run through Sail.
- Do not begin a later milestone while its dependency checkpoint is failing.
- The approved specification remains authoritative. If implementation reveals
  a domain decision not covered there, update the spec and obtain approval
  before changing code.

## Dependency Graph

```text
01 Roles
  └─02 Patient identity
      ├─03 Patient onboarding
      ├─04 Patient authorization
      └─05 Patient panel
          └─06 Audit/privacy baseline
              └─Milestone 1
                  └─07–10 Scheduling
                      └─11–16 Clinical workflow
                          ├─17–20 Reservations
                          └─21–25 Quotations/job orders
                              └─26–29 Invoices/dispensing
                                  ├─30 Complaints
                                  └─31–32 Ratings
                                      └─33–40 Integration/release
```

## Milestone 1: Patients, Accounts, Roles, and Privacy Baseline

### Task 01: Establish canonical roles and optometrist capability

**Status:** Completed on 2026-07-25; focused affected suites passed.

**Description:** Keep `admin`, `staff`, and `patient` as the role catalog and
make optometrist capability valid only for eligible clinic accounts.

**Acceptance criteria:**

- [x] Seeded roles are exactly `admin`, `staff`, and `patient`.
- [x] Flagged patient accounts never receive optometrist capability.
- [x] Registration and active role guards use `patient`.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/RoleCatalogTest.php tests/Feature/RolePermissionsTest.php tests/Feature/Api/AuthTest.php
```

**Dependencies:** None.

**Primary files:** `app/Models/User.php`,
`database/factories/UserFactory.php`, `database/seeders/RoleSeeder.php`,
`tests/Feature/RoleCatalogTest.php`, `tests/Feature/RolePermissionsTest.php`.

**Estimated scope:** Completed M.

### Task 02: Create the independent patient identity

**Status:** Completed on 2026-07-25.

**Description:** Introduce a patient model containing clinic identity and
demographics, with an optional unique link to a login account.

**Acceptance criteria:**

- [x] A patient can exist without a user account.
- [x] One account links to at most one patient in the initial design.
- [x] Patient factories cover linked, unlinked, and walk-in records.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Patients/PatientModelTest.php
```

**Dependencies:** Task 01.

**Primary files:** `database/migrations/*_create_patients_table.php`,
`app/Models/Patient.php`, `app/Models/User.php`,
`database/factories/PatientFactory.php`,
`tests/Feature/Patients/PatientModelTest.php`.

**Estimated scope:** M.

**Implementation note:** Patient numbers use a system-generated `PAT-`-prefixed
ULID under a unique constraint. Deleting an account nulls its link without
deleting the clinical identity, and soft-deleted patients retain their account
uniqueness for chart integrity.

### Task 03: Replace registration with patient onboarding

**Description:** Register a patient account and its linked patient identity in
one transaction while keeping staff-created account-less patients possible.

**Acceptance criteria:**

- [x] Mobile registration creates one patient-role account and one linked
      patient.
- [x] Failed patient creation rolls back account creation.
- [x] The response contains only the patient-safe account/profile contract.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/AuthTest.php tests/Feature/Api/PatientProfileTest.php
```

**Dependencies:** Task 02.

**Primary files:** `app/Http/Controllers/Api/AuthController.php`,
`app/Http/Requests/Api/RegisterPatientRequest.php`,
`app/Http/Resources/PatientProfileResource.php`, `routes/api.php`,
`tests/Feature/Api/PatientProfileTest.php`.

**Estimated scope:** M.

### Checkpoint 1A: Patient identity and onboarding

- [x] Tasks 01–03 focused tests pass.
- [x] Linked and account-less patient factories are valid.
- [x] Registration rolls back cleanly on patient-creation failure.

### Task 04: Enforce patient-record authorization

**Description:** Protect patient records through policies and account-scoped
queries, including negative cross-patient tests.

**Acceptance criteria:**

- [x] A patient account may view/update only its linked non-clinical profile.
- [x] Clinic users receive only the access allowed by the role/capability
      matrix.
- [x] Changing a route identifier cannot expose another patient.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Patients/PatientAuthorizationTest.php
```

**Dependencies:** Tasks 02–03.

**Primary files:** `app/Policies/PatientPolicy.php`,
`app/Http/Controllers/Api/PatientProfileController.php`,
`app/Http/Requests/Api/UpdatePatientProfileRequest.php`, `routes/api.php`,
`tests/Feature/Patients/PatientAuthorizationTest.php`.

**Estimated scope:** M.

### Task 05: Move patient management to the Patient model

**Description:** Repoint the existing Filament patient resource from patient
role users to independent patient records.

**Acceptance criteria:**

- [x] Staff can create an account-less patient.
- [x] Patient search, view, and edit use Patient fields.
- [x] Account linking is optional and authorization is policy-backed.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/PatientResourceTest.php
```

**Dependencies:** Task 04.

**Primary files:** `app/Filament/Resources/Patients/PatientResource.php`,
`app/Filament/Resources/Patients/Schemas/PatientForm.php`,
`app/Filament/Resources/Patients/Tables/PatientsTable.php`,
`app/Filament/Resources/Patients/Pages/*`,
`tests/Feature/Filament/PatientResourceTest.php`.

**Estimated scope:** M.

### Task 06: Establish safe auditing and privacy acknowledgement

**Description:** Standardize audit event names/metadata and retain the privacy
notice version accepted by patient accounts.

**Acceptance criteria:**

- [x] Sensitive read, print, clinical, financial, moderation, and role events
      use canonical event names.
- [x] Audit metadata never copies clinical narrative or decrypted values.
- [x] Patient-account notice version and acknowledgement time are retained.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/AuditLogRecordingTest.php tests/Feature/Privacy/PrivacyNoticeTest.php
```

**Dependencies:** Tasks 03–05.

**Primary files:** `app/Enums/AuditEvent.php`,
`app/Actions/Audit/CreateAuditLog.php`,
`database/migrations/*_add_privacy_acknowledgement_to_users_table.php`,
`app/Models/User.php`, `tests/Feature/Privacy/PrivacyNoticeTest.php`.

**Estimated scope:** M.

### Checkpoint 1: Patient foundation

- [x] Tasks 01–06 focused tests pass.
- [x] `vendor/bin/sail artisan migrate:fresh --seed` succeeds.
- [x] Staff can create an account-less patient.
- [x] A linked patient account cannot access another patient.
- [x] The spec, plan, and implemented patient boundary agree.

## Milestone 2: Clinic Schedules and Appointments

### Task 07: Store recurring clinic hours

**Description:** Replace hard-coded weekday closure rules with recurring clinic
hours defaulted to 09:00–17:00 every day.

**Acceptance criteria:**

- [ ] Each weekday has an enabled/open/close record.
- [ ] Defaults seed all seven days as 09:00–17:00.
- [ ] Invalid or overlapping clinic hours are rejected.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Appointments/ClinicHoursTest.php
```

**Dependencies:** Checkpoint 1.

**Primary files:** `database/migrations/*_create_clinic_hours_table.php`,
`app/Models/ClinicHour.php`, `database/factories/ClinicHourFactory.php`,
`database/seeders/ClinicHoursSeeder.php`,
`tests/Feature/Appointments/ClinicHoursTest.php`.

**Estimated scope:** M.

### Task 08: Store provider availability and date overrides

**Description:** Model each optometrist's weekly schedule plus dated clinic or
provider closures/shortened hours.

**Acceptance criteria:**

- [ ] Only optometrist-capable accounts can own provider availability.
- [ ] Date overrides support closure, early closing, and provider absence.
- [ ] Conflicting rules are rejected deterministically.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Appointments/ProviderAvailabilityTest.php
```

**Dependencies:** Task 07.

**Primary files:** `database/migrations/*_create_provider_availability_tables.php`,
`app/Models/ProviderHour.php`, `app/Models/ScheduleOverride.php`,
`database/factories/ScheduleOverrideFactory.php`,
`tests/Feature/Appointments/ProviderAvailabilityTest.php`.

**Estimated scope:** M.

### Checkpoint 2A: Schedule sources

- [ ] Tasks 07–08 focused tests pass.
- [ ] Seven-day clinic defaults and two provider schedules seed correctly.
- [ ] Invalid/overlapping schedule rules are rejected.

### Task 09: Evaluate appointment availability from stored schedules

**Description:** Adapt the existing availability engine to combine clinic
hours, overrides, provider capacity, visit duration, and bookings.

**Acceptance criteria:**

- [ ] Slots outside effective clinic/provider availability are unavailable.
- [ ] Capacity reflects available optometrists, not a hard-coded number.
- [ ] Early closure and absence cases return stable reason codes.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentAvailabilityTest.php tests/Feature/AppointmentSchedulingTest.php
```

**Dependencies:** Tasks 07–08.

**Primary files:** `app/Actions/Appointments/EvaluateAppointmentAvailability.php`,
`app/Actions/Appointments/ListAvailableAppointmentSlots.php`,
`app/Actions/Appointments/AppointmentAvailabilityDecision.php`,
`app/Http/Controllers/Api/AppointmentAvailabilityController.php`,
`tests/Feature/Api/AppointmentAvailabilityTest.php`.

**Estimated scope:** M.

### Task 10: Book patients and assign providers

**Description:** Move scheduled/walk-in appointments to `patient_id`; patients
choose time only, while clinic users assign/reassign an available optometrist.

**Acceptance criteria:**

- [ ] Scheduled and walk-in records reference Patient.
- [ ] Patient requests cannot submit `optometrist_id`.
- [ ] Assignment/reassignment checks availability and audits the actor.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/AppointmentBookingTest.php tests/Feature/Appointments/ProviderAssignmentTest.php tests/Feature/Filament/AppointmentResourceTest.php
```

**Dependencies:** Task 09.

**Primary files:** `database/migrations/*_replace_appointment_customer_with_patient.php`,
`app/Models/Appointment.php`,
`app/Actions/Appointments/CreateScheduledAppointment.php`,
`app/Actions/Appointments/AssignOptometrist.php`,
`tests/Feature/Appointments/ProviderAssignmentTest.php`.

**Estimated scope:** M.

### Checkpoint 2: Scheduling

- [ ] Tasks 07–10 focused suites pass.
- [ ] Patient booking, walk-in, early close, absence, and reassignment pass.
- [ ] An override reports affected appointments without cancelling them.
- [ ] Filament schedule management is authorized for admin/optometrist users.

## Milestone 3: Intake, Encounters, Prescriptions, and Printing

### Task 11: Create patient intake records

**Description:** Store appointment type, patient-submitted demographics,
complaints, histories, allergies, and medications in a dedicated intake.

**Acceptance criteria:**

- [ ] Intake belongs to Patient and may optionally belong to an appointment.
- [ ] Clinical narrative fields use encrypted text storage.
- [ ] Draft, submitted, and verified states are constrained.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Encounters/PatientIntakeTest.php
```

**Dependencies:** Checkpoint 2.

**Primary files:** `database/migrations/*_create_patient_intakes_table.php`,
`app/Models/PatientIntake.php`, `database/factories/PatientIntakeFactory.php`,
`app/Enums/IntakeStatus.php`,
`tests/Feature/Encounters/PatientIntakeTest.php`.

**Estimated scope:** M.

### Task 12: Submit and verify intake

**Description:** Let patients edit their draft/submitted intake and clinic staff
verify it at check-in without granting clinical authorship.

**Acceptance criteria:**

- [ ] Patient writes are limited to owned, pre-check-in intake.
- [ ] Verification records verifier/time and locks the submitted snapshot.
- [ ] Non-optometrist verification does not authorize clinical findings.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/PatientIntakeTest.php tests/Feature/Encounters/IntakeVerificationTest.php
```

**Dependencies:** Task 11.

**Primary files:** `app/Actions/Intakes/VerifyPatientIntake.php`,
`app/Http/Controllers/Api/PatientIntakeController.php`,
`app/Http/Requests/Api/StorePatientIntakeRequest.php`,
`app/Http/Resources/PatientIntakeResource.php`,
`tests/Feature/Encounters/IntakeVerificationTest.php`.

**Estimated scope:** M.

### Task 13: Create encounters transactionally at check-in

**Description:** Create one encounter and intake snapshot when an attended
appointment is checked in.

**Acceptance criteria:**

- [ ] Check-in creates exactly one encounter for the appointment.
- [ ] Cancelled/no-show appointments cannot create encounters.
- [ ] Failure rolls back both status and encounter creation.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Encounters/EncounterCheckInTest.php
```

**Dependencies:** Task 12.

**Primary files:** `database/migrations/*_create_encounters_table.php`,
`app/Models/Encounter.php`, `database/factories/EncounterFactory.php`,
`app/Actions/Encounters/CheckInAppointment.php`,
`tests/Feature/Encounters/EncounterCheckInTest.php`.

**Estimated scope:** M.

### Checkpoint 3A: Intake and encounter creation

- [ ] Tasks 11–13 focused tests pass.
- [ ] Verified intake is snapshotted at check-in.
- [ ] Concurrent check-in cannot create duplicate encounters.

### Task 14: Finalize and amend encounter prescriptions

**Description:** Attach prescriptions to Patient and Encounter, restrict
clinical authorship to optometrists, and preserve finalized revisions.

**Acceptance criteria:**

- [ ] Only an optometrist can finalize a prescription.
- [ ] Finalized values are encrypted and cannot be edited in place.
- [ ] Amendments reference the prior prescription and retain actor/time/reason.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Encounters/PrescriptionLifecycleTest.php
```

**Dependencies:** Task 13.

**Primary files:** `database/migrations/*_link_prescriptions_to_encounters.php`,
`app/Models/Prescription.php`,
`app/Actions/Prescriptions/FinalizePrescription.php`,
`app/Policies/PrescriptionPolicy.php`,
`tests/Feature/Encounters/PrescriptionLifecycleTest.php`.

**Estimated scope:** M.

### Task 15: Build the optometrist encounter workspace

**Description:** Provide a single Filament workspace for verified intake,
encounter notes, and prescription finalization.

**Acceptance criteria:**

- [ ] Optometrists see the relevant visit context in one workflow.
- [ ] Receptionists may see intake/check-in state but not clinical authoring
      controls.
- [ ] Custom actions explicitly authorize and use lifecycle actions.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterResourceTest.php
```

**Dependencies:** Task 14.

**Primary files:** `app/Filament/Resources/Encounters/EncounterResource.php`,
`app/Filament/Resources/Encounters/Pages/*`,
`app/Filament/Resources/Encounters/Schemas/EncounterForm.php`,
`app/Filament/Resources/Encounters/Tables/EncountersTable.php`,
`tests/Feature/Filament/EncounterResourceTest.php`.

**Estimated scope:** M.

### Task 16: Track physical chart packets and print clinical forms

**Description:** Track the physical Health Record, prescription, and
autorefractor paper result, and add authorized A5/A4 print routes.

**Acceptance criteria:**

- [ ] Chart checkout/return/relocation/copy events are attributable.
- [ ] Health Record renders A5 landscape and Prescription A5 portrait, with A4
      fallback styles.
- [ ] Raw autorefractor output is not uploaded, only paper presence is tracked,
      and prescription `CX` remains neutral until the clinic approves its
      binding.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Encounters/PhysicalChartTest.php tests/Feature/Filament/ClinicalPrintTest.php
```

**Dependencies:** Tasks 14–15.

**Primary files:** `database/migrations/*_create_physical_chart_events_table.php`,
`app/Models/PhysicalChartEvent.php`, `app/Services/PdfService.php`,
`resources/views/print/*`, `tests/Feature/Filament/ClinicalPrintTest.php`.

**Estimated scope:** M.

### Checkpoint 3: Clinical records

- [ ] Tasks 11–16 focused suites pass.
- [ ] Scheduled and walk-in patients complete the same clinical flow.
- [ ] Receptionist clinical-finalization attempts are denied.
- [ ] Print, chart access, finalization, and amendment are audited.

## Milestone 4: Frame Catalog and Fitting Reservations

### Task 17: Expose the frame-only `/api/v1` catalog

**Description:** Version patient routes and constrain mobile product responses
to active frames and fitting-safe fields.

**Acceptance criteria:**

- [ ] `/api/v1/frames` returns frames only.
- [ ] Accessories, costs, exact inventory counts, and internal fields are
      absent.
- [ ] Old unversioned direct-order routes are not exposed.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameCatalogTest.php tests/Feature/Api/V1/RouteContractTest.php
```

**Dependencies:** Checkpoint 1.

**Primary files:** `routes/api.php`,
`app/Http/Controllers/Api/FrameController.php`,
`app/Http/Resources/FrameResource.php`,
`app/Http/Resources/FrameVariantResource.php`,
`tests/Feature/Api/V1/FrameCatalogTest.php`.

**Estimated scope:** M.

### Task 18: Create appointment-linked fitting reservations

**Description:** Store patient frame selections as reservations rather than
orders.

**Acceptance criteria:**

- [ ] Reservation belongs to Patient and an eligible upcoming appointment.
- [ ] Items reference active frame variants only.
- [ ] Requested, prepared, cancelled, expired, and converted states are
      constrained.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reservations/FrameReservationTest.php
```

**Dependencies:** Tasks 10 and 17.

**Primary files:** `database/migrations/*_create_frame_reservations_table.php`,
`app/Models/FrameReservation.php`, `app/Models/FrameReservationItem.php`,
`database/factories/FrameReservationFactory.php`,
`tests/Feature/Reservations/FrameReservationTest.php`.

**Estimated scope:** M.

### Checkpoint 4A: Catalog and reservation records

- [ ] Tasks 17–18 focused tests pass.
- [ ] `/api/v1` catalog contains frames only.
- [ ] Reservation records require an eligible appointment and active frame.

### Task 19: Reserve and release frames safely

**Description:** Provide patient reservation endpoints and transactional staff
allocation/release actions.

**Acceptance criteria:**

- [ ] Patient reservation limits and ownership are enforced.
- [ ] Prepared reservations allocate reversibly under a row lock.
- [ ] Cancellation, expiry, and no-show release allocation idempotently.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameReservationTest.php tests/Feature/Reservations/FrameAllocationTest.php
```

**Dependencies:** Task 18.

**Primary files:** `app/Http/Controllers/Api/FrameReservationController.php`,
`app/Http/Requests/Api/StoreFrameReservationRequest.php`,
`app/Actions/Reservations/PrepareFrameReservation.php`,
`app/Actions/Reservations/ReleaseFrameReservation.php`,
`tests/Feature/Reservations/FrameAllocationTest.php`.

**Estimated scope:** M.

### Task 20: Build the reservation queue and retire direct ordering

**Description:** Give staff a preparation queue, then remove patient order
creation entry points and their obsolete tests.

**Acceptance criteria:**

- [ ] Staff can prepare, reject, expire, and release reservations.
- [ ] Patients cannot create orders/job orders through any route.
- [ ] Direct-order API controllers/requests/routes/tests are removed after
      reservation coverage passes.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/FrameReservationResourceTest.php tests/Feature/Api/V1/RouteContractTest.php
vendor/bin/sail artisan route:list --path=api --except-vendor
```

**Dependencies:** Task 19.

**Primary files:** `app/Filament/Resources/FrameReservations/*`,
`app/Http/Controllers/Api/OrderController.php`,
`app/Http/Requests/Api/StoreOrderRequest.php`, `routes/api.php`,
`tests/Feature/Filament/FrameReservationResourceTest.php`.

**Estimated scope:** M plus bounded mechanical deletion.

### Checkpoint 4: Reservations

- [ ] Tasks 17–20 focused suites pass.
- [ ] Appointment-to-reservation and release journeys pass.
- [ ] Route scan proves no patient checkout or order creation.
- [ ] Full Pest suite and `vendor/bin/sail npm run build` pass.

## Milestone 5: Quotations, Job Orders, and Inventory

### Task 21: Create versioned quotation snapshots

**Description:** Store quotations as immutable revisions with snapshot items and
totals.

**Acceptance criteria:**

- [ ] Each revision snapshots descriptions, quantities, prices, discounts, and
      totals.
- [ ] Recalculating creates a revision instead of rewriting a presented one.
- [ ] Totals are deterministic and covered by calculation tests.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Quotations/QuotationRevisionTest.php
```

**Dependencies:** Checkpoints 3–4.

**Primary files:** `database/migrations/*_create_quotation_tables.php`,
`app/Models/Quotation.php`, `app/Models/QuotationRevision.php`,
`app/Models/QuotationItem.php`,
`tests/Feature/Quotations/QuotationRevisionTest.php`.

**Estimated scope:** M.

### Task 22: Enforce quotation lifecycle and decisions

**Description:** Present, revise, accept, decline, and expire quotations through
explicit actions, with in-person decisions recorded by staff.

**Acceptance criteria:**

- [ ] Only valid state transitions succeed.
- [ ] Acceptance identifies revision, recorder, patient decision, and time.
- [ ] Accepted quotations cannot be silently revised.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Quotations/QuotationLifecycleTest.php
```

**Dependencies:** Task 21.

**Primary files:** `app/Actions/Quotations/PresentQuotation.php`,
`app/Actions/Quotations/RecordQuotationDecision.php`,
`app/Enums/QuotationStatus.php`, `app/Policies/QuotationPolicy.php`,
`tests/Feature/Quotations/QuotationLifecycleTest.php`.

**Estimated scope:** M.

### Task 23: Deliver quotation panel and patient-safe status

**Description:** Add the clinic quotation workspace and read-only patient
status/detail API.

**Acceptance criteria:**

- [ ] Clinic users build and record decisions from encounter/reservation context.
- [ ] Patients see only their quotation snapshot/status.
- [ ] Mobile cannot accept, alter, or create quotations.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/QuotationResourceTest.php tests/Feature/Api/V1/QuotationTest.php
```

**Dependencies:** Task 22.

**Primary files:** `app/Filament/Resources/Quotations/*`,
`app/Http/Controllers/Api/QuotationController.php`,
`app/Http/Resources/QuotationResource.php`, `routes/api.php`,
`tests/Feature/Api/V1/QuotationTest.php`.

**Estimated scope:** M.

### Checkpoint 5A: Quotations

- [ ] Tasks 21–23 focused tests pass.
- [ ] Presented/accepted revisions remain immutable.
- [ ] Patient API is read-only for quotations.

### Task 24: Create job orders from accepted quotations

**Description:** Create a clinic-authored job order snapshot from exactly one
accepted quotation revision.

**Acceptance criteria:**

- [ ] Patient/API callers cannot create job orders.
- [ ] Job order snapshots patient, encounter/prescription, frame/lens details,
      accepted price, and the required finalized prescription.
- [ ] Duplicate creation from the same accepted revision is prevented.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/JobOrders/CreateJobOrderTest.php
```

**Dependencies:** Task 23.

**Primary files:** `database/migrations/*_create_job_order_tables.php`,
`app/Models/JobOrder.php`, `app/Models/JobOrderItem.php`,
`app/Actions/JobOrders/CreateJobOrder.php`,
`tests/Feature/JobOrders/CreateJobOrderTest.php`.

**Estimated scope:** M.

### Task 25: Commit inventory and deliver job-order operations

**Description:** Commit frame inventory when a job order is created, add
authorized status/cancellation operations, and retire the old Order surface.

**Acceptance criteria:**

- [ ] Inventory commitment/reversal is transactional and idempotent.
- [ ] Staff panel and patient API expose role-appropriate job-order operations.
- [ ] Old Order actions/resources/models/tests are removed in bounded batches.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/JobOrders/JobOrderInventoryTest.php tests/Feature/Filament/JobOrderResourceTest.php tests/Feature/Api/V1/JobOrderTest.php
```

**Dependencies:** Task 24.

**Primary files:** `app/Actions/JobOrders/CommitJobOrderInventory.php`,
`app/Actions/JobOrders/UpdateJobOrderStatus.php`,
`app/Filament/Resources/JobOrders/*`,
`app/Http/Controllers/Api/JobOrderController.php`,
`tests/Feature/JobOrders/JobOrderInventoryTest.php`.

**Estimated scope:** M plus bounded mechanical deletion.

### Checkpoint 5: Quotations and job orders

- [ ] Tasks 21–25 focused suites pass.
- [ ] Prescription-to-accepted-quotation-to-job-order journey passes.
- [ ] Reservation conversion/cancellation keeps inventory correct.
- [ ] No active legacy Order route, navigation item, or model consumer remains.

## Milestone 6: Invoices, Payments, and Dispensing

### Task 26: Create invoice snapshots and payment ledger

**Description:** Replace Billing with canonical invoice/item/payment records,
separating internal reference from physical Service Invoice number.

**Acceptance criteria:**

- [ ] Invoice items/tax/discount totals are immutable snapshots.
- [ ] Official physical number is nullable until dispensing and unique when
      recorded.
- [ ] Payments form an attributable append-only ledger.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Invoices/InvoiceLedgerTest.php
```

**Dependencies:** Checkpoint 5.

**Primary files:** `database/migrations/*_create_invoice_tables.php`,
`app/Models/Invoice.php`, `app/Models/InvoiceItem.php`,
`app/Models/InvoicePayment.php`,
`tests/Feature/Invoices/InvoiceLedgerTest.php`.

**Estimated scope:** M.

### Task 27: Record deposits, installments, and corrections

**Description:** Post payments/corrections through transactional actions and
derive paid/balance values from ledger history.

**Acceptance criteria:**

- [ ] Deposits may be recorded before dispensing.
- [ ] Overpayment/invalid reversal is rejected under a row lock.
- [ ] Corrections preserve the original payment and actor/reason.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Invoices/PaymentLifecycleTest.php
```

**Dependencies:** Task 26.

**Primary files:** `app/Actions/Invoices/RecordInvoicePayment.php`,
`app/Actions/Invoices/CorrectInvoicePayment.php`,
`app/Actions/Invoices/RecalculateInvoiceBalance.php`,
`app/Policies/InvoicePolicy.php`,
`tests/Feature/Invoices/PaymentLifecycleTest.php`.

**Estimated scope:** M.

### Checkpoint 6A: Invoice and payment ledger

- [ ] Tasks 26–27 focused tests pass.
- [ ] Deposits, installments, corrections, and derived balances are consistent.
- [ ] Physical Service Invoice number remains unset before dispensing.

### Task 28: Issue the physical invoice record at dispensing

**Description:** Record dispensing and the clinic's physical Service Invoice
number atomically when a job is ready.

**Acceptance criteria:**

- [ ] Only a ready job order may be dispensed.
- [ ] Dispensing records actor/time, physical number, and approved balance terms.
- [ ] Any failure rolls back dispensing and invoice issuance.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Invoices/DispensingTest.php
```

**Dependencies:** Task 27.

**Primary files:** `database/migrations/*_create_dispensing_events_table.php`,
`app/Models/DispensingEvent.php`,
`app/Actions/Invoices/DispenseJobOrder.php`,
`app/Notifications/JobOrderDispensed.php`,
`tests/Feature/Invoices/DispensingTest.php`.

**Estimated scope:** M.

### Task 29: Deliver invoice operations and retire Billing

**Description:** Add Filament/API/print invoice surfaces, then remove legacy
Billing code and receipts in bounded batches.

**Acceptance criteria:**

- [ ] Staff can record permitted payments, corrections, and dispensing.
- [ ] Patients see safe invoice/payment status only.
- [ ] Internal print is clearly a system copy of the physical booklet entry,
      and legacy Billing resources/actions/models/routes/tests are removed.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/InvoiceResourceTest.php tests/Feature/Api/V1/InvoiceTest.php tests/Feature/Invoices/InvoicePrintTest.php
```

**Dependencies:** Task 28.

**Primary files:** `app/Filament/Resources/Invoices/*`,
`app/Http/Controllers/Api/InvoiceController.php`,
`app/Services/PdfService.php`, `resources/views/pdf/invoice.blade.php`,
`tests/Feature/Invoices/InvoicePrintTest.php`.

**Estimated scope:** M plus bounded mechanical deletion.

### Checkpoint 6: Invoices and dispensing

- [ ] Tasks 26–29 focused suites pass.
- [ ] Deposit-to-dispensing-to-installment journey passes.
- [ ] Balance and correction integrity tests pass.
- [ ] Full Pest suite, fresh seeded rebuild, and production asset build pass.

## Milestone 7: Complaints and Transparent Ratings

### Task 30: Restart workflow from a complaint

**Description:** Link a complaint to the original dispensed transaction and a
new appointment/encounter without changing history.

**Acceptance criteria:**

- [ ] Complaint belongs to Patient and original job/dispensing context.
- [ ] Authorized staff can create/link the new visit.
- [ ] Original encounter, prescription, job order, and invoice stay unchanged.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Complaints/ComplaintRestartTest.php
```

**Dependencies:** Checkpoint 6.

**Primary files:** `database/migrations/*_create_complaints_table.php`,
`app/Models/Complaint.php`, `app/Actions/Complaints/RestartComplaintWorkflow.php`,
`app/Policies/ComplaintPolicy.php`,
`tests/Feature/Complaints/ComplaintRestartTest.php`.

**Estimated scope:** M.

### Task 31: Create verified frame ratings with revisions

**Description:** Permit one current rating for each frame dispensed to the
authenticated linked patient and retain rating revisions.

**Acceptance criteria:**

- [ ] Eligibility derives from dispensing, not merely appointment/order state.
- [ ] One current rating per patient/dispensed frame is enforced.
- [ ] Edits append attributable revisions.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Ratings/VerifiedFrameRatingTest.php
```

**Dependencies:** Tasks 17 and 28.

**Primary files:** `database/migrations/*_create_frame_rating_tables.php`,
`app/Models/FrameRating.php`, `app/Models/FrameRatingRevision.php`,
`app/Actions/Ratings/SaveFrameRating.php`,
`tests/Feature/Ratings/VerifiedFrameRatingTest.php`.

**Estimated scope:** M.

### Task 32: Moderate comments transparently

**Description:** Add patient rating API and staff moderation that hides
inappropriate text without manipulating star aggregates.

**Acceptance criteria:**

- [ ] Hidden comments retain their star in aggregate/distribution results.
- [ ] Moderation records reason, actor, and timestamps; clinic users cannot edit
      rating values.
- [ ] Patient and Filament surfaces enforce policy and privacy boundaries.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/FrameRatingTest.php tests/Feature/Filament/FrameRatingResourceTest.php
```

**Dependencies:** Task 31.

**Primary files:** `app/Actions/Ratings/ModerateFrameRating.php`,
`app/Http/Controllers/Api/FrameRatingController.php`,
`app/Http/Resources/FrameRatingResource.php`,
`app/Filament/Resources/FrameRatings/*`,
`tests/Feature/Filament/FrameRatingResourceTest.php`.

**Estimated scope:** M.

### Checkpoint 7: Complaints and ratings

- [ ] Tasks 30–32 focused suites pass.
- [ ] Complaint restart preserves all original history.
- [ ] Verified rating aggregates include moderated stars correctly.
- [ ] Cross-patient and staff-manipulation negative tests pass.

## Milestone 8: Integration, Seeds, Privacy, and Release Gate

### Task 33: Make the panel optometrist-first

**Description:** Reorder navigation/dashboard around today's clinical and
fulfillment work while hiding optometrist-only controls from receptionists.

**Acceptance criteria:**

- [ ] Dashboard prioritizes appointments, encounters, quotations, job orders,
      dispensing, and exceptions.
- [ ] Receptionist navigation/actions match non-clinical permissions.
- [ ] Obsolete Order/Billing/Feedback navigation and reports are gone.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Filament/DashboardTest.php tests/Feature/Filament/FilamentAccessTest.php tests/Feature/Filament/ReportsTest.php
```

**Dependencies:** Checkpoint 7.

**Primary files:** `app/Providers/Filament/AdminPanelProvider.php`,
`app/Filament/Widgets/StatsOverviewWidget.php`,
`app/Filament/Widgets/TodaysScheduleWidget.php`,
`app/Filament/Pages/Reports/*`, `tests/Feature/Filament/DashboardTest.php`.

**Estimated scope:** M plus bounded mechanical deletion.

### Task 34: Lock the patient API and notification contract

**Description:** Finalize `/api/v1`, patient-safe notifications/messages, and
rate limits.

**Acceptance criteria:**

- [ ] Route contract contains only approved patient-mobile operations.
- [ ] Notifications/messages contain no internal notes or clinical narrative.
- [ ] Ownership and rate-limit tests cover every patient-facing mutation.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php tests/Feature/Notifications/PatientNotificationTest.php tests/Feature/Api/RateLimitTest.php
```

**Dependencies:** Task 33.

**Primary files:** `routes/api.php`,
`app/Http/Resources/NotificationResource.php`,
`app/Notifications/*`, `app/Http/Resources/ConversationResource.php`,
`tests/Feature/Api/V1/RouteContractTest.php`.

**Estimated scope:** M.

### Task 35: Enforce the production panel MFA gate

**Description:** Configure Filament's built-in TOTP app authentication and
recovery codes, requiring it for production panel access.

**Acceptance criteria:**

- [ ] Production configuration denies panel access when MFA is not configured
      and satisfied.
- [ ] Development/testing behavior remains explicit and testable.
- [ ] MFA failure never falls back to a less secure authentication path.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Security/PanelMfaTest.php
```

**Dependencies:** Task 34.

**Primary files:** `database/migrations/*_add_filament_mfa_to_users_table.php`,
`app/Models/User.php`, `app/Providers/Filament/AdminPanelProvider.php`,
`tests/Feature/Security/PanelMfaTest.php`.

**Estimated scope:** S.

### Checkpoint 8A: Integrated access contracts

- [ ] Tasks 33–35 focused tests pass.
- [ ] Optometrist/receptionist panel permissions are distinct.
- [ ] `/api/v1`, notifications, and production MFA gates are locked.

### Task 36: Replace seed data and remove remaining legacy surface

**Description:** Seed a coherent fictional clinic workflow and delete remaining
obsolete customer/order/billing files, tests, schema, and terminology in
bounded batches.

**Acceptance criteria:**

- [ ] Seeds cover two optometrists, receptionist work, linked/unlinked patients,
      scheduling, clinical, reservation, commercial, dispensing, complaint, and
      rating flows.
- [ ] `migrate:fresh --seed` succeeds without patient-created orders.
- [ ] Route/model/schema/text scans find no unintended legacy surface.

**Verification:**

```bash
vendor/bin/sail artisan migrate:fresh --seed --no-interaction
vendor/bin/sail artisan test --compact tests/Feature/Seeders/ClinicWorkflowSeederTest.php
vendor/bin/sail artisan route:list --except-vendor
```

**Dependencies:** Task 35.

**Primary files:** `database/seeders/DatabaseSeeder.php`,
`database/seeders/ClinicWorkflowSeeder.php`,
`database/seeders/DemoUserSeeder.php`, `database/migrations/*`,
`tests/Feature/Seeders/ClinicWorkflowSeederTest.php`.

**Estimated scope:** M plus bounded mechanical deletion.

### Checkpoint 8B: Canonical rebuild

- [ ] Task 36 seeder and fresh-rebuild tests pass.
- [ ] Route, model, schema, and terminology scans show no obsolete workflow.
- [ ] The fictional seed demonstrates the approved end-to-end clinic journey.

### Task 37: Handle privacy-rights requests

**Description:** Record identity-verified access, correction, objection, and
erasure requests without promising an outcome that conflicts with legal
retention duties.

**Acceptance criteria:**

- [ ] Request type, identity verification, handler, disposition, and timestamps
      are attributable.
- [ ] Only authorized administrators/DPO designees can process requests.
- [ ] Clinical/financial history cannot be silently deleted through the request
      workflow.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Privacy/PrivacyRequestTest.php
```

**Dependencies:** Task 36.

**Primary files:** `database/migrations/*_create_privacy_requests_table.php`,
`app/Models/PrivacyRequest.php`,
`app/Actions/Privacy/ProcessPrivacyRequest.php`,
`app/Policies/PrivacyRequestPolicy.php`,
`tests/Feature/Privacy/PrivacyRequestTest.php`.

**Estimated scope:** M.

### Task 38: Configure retention review and legal holds

**Description:** Store category-specific review policies and legal holds
without inventing retention periods or enabling automatic deletion.

**Acceptance criteria:**

- [ ] Retention categories and review dates are configurable.
- [ ] Legal holds prevent disposal eligibility.
- [ ] Automatic purge remains disabled until an approved clinic schedule exists.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Privacy/RetentionPolicyTest.php
```

**Dependencies:** Task 37.

**Primary files:** `database/migrations/*_create_retention_tables.php`,
`app/Models/RetentionPolicy.php`, `app/Models/LegalHold.php`,
`app/Actions/Privacy/EvaluateRetention.php`,
`tests/Feature/Privacy/RetentionPolicyTest.php`.

**Estimated scope:** M.

### Task 39: Record privacy and security incidents

**Description:** Provide an attributable incident register and review workflow
for breach assessment and response coordination.

**Acceptance criteria:**

- [ ] Incident discovery, scope, handler, decisions, actions, and closure are
      retained.
- [ ] Incident details are access-controlled and audited.
- [ ] The workflow records decisions without auto-reporting externally.

**Verification:**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Privacy/PrivacyIncidentTest.php
```

**Dependencies:** Task 37.

**Primary files:** `database/migrations/*_create_privacy_incidents_table.php`,
`app/Models/PrivacyIncident.php`,
`app/Actions/Privacy/UpdatePrivacyIncident.php`,
`app/Filament/Resources/PrivacyIncidents/*`,
`tests/Feature/Privacy/PrivacyIncidentTest.php`.

**Estimated scope:** M.

### Checkpoint 8C: Privacy governance workflows

- [ ] Tasks 37–39 focused tests pass.
- [ ] Rights, retention/hold, and incident records are policy-protected.
- [ ] No automatic purge or external breach notification is enabled.

### Task 40: Complete release validation

**Description:** Update implemented context and run the complete technical,
privacy, recovery, and browser release checks.

**Acceptance criteria:**

- [ ] Full suite, Pint, assets, fresh seed, browser journeys, and backup restore
      pass.
- [ ] The implemented context and route/schema inventories match the system.
- [ ] Production remains blocked until DPO, PIA, retention, and `CX` decisions
      are formally resolved.

**Verification:**

```bash
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
vendor/bin/sail artisan migrate:fresh --seed --no-interaction
```

**Dependencies:** Tasks 36–39.

**Primary files:** `docs/BACKEND_CONTEXT.md`,
`tests/Feature/EndToEnd/ClinicWorkflowTest.php`,
`tests/Feature/Security/ProductionConfigurationTest.php`,
`tests/Feature/Privacy/BackupRestoreTest.php`.

**Estimated scope:** M.

### Checkpoint 8: Release readiness

- [ ] Every specification success criterion has evidence.
- [ ] Full Pest suite, Pint, production build, fresh seed, and browser checks
      pass.
- [ ] Backup restoration and incident workflow are demonstrated.
- [ ] No obsolete parallel workflow remains.
- [ ] Clinic governance approval is recorded before deployment.

## Parallelization Guidance

No parallel agent work is required. If the project owner later requests it:

- Tasks 07 and 08 may run in parallel after their schema contracts are fixed.
- Print layout work in Task 16 may run alongside Task 15 after Task 14.
- Complaint Task 30 and rating Task 31 may run in parallel after Checkpoint 6.
- Database migrations, shared route files, seeders, and legacy deletion remain
  sequential.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Patient boundary leaks health data | High | Policies, scoped route binding/resources, negative ownership tests |
| Encrypted fields become unsearchable | Medium | Encrypt narrative/clinical fields; keep only necessary operational identifiers queryable and access-controlled |
| Schedule overrides strand appointments | High | Preview impacted records; never auto-cancel |
| Stock drifts across reservation/job order | High | Transactions, row locks, idempotent release/reversal tests |
| Financial history becomes manipulatable | High | Snapshot invoices, append-only payments/corrections, actor attribution |
| Legacy cleanup damages aligned catalog work | Medium | Remove by consumer manifest; retain frame/catalog/inventory code that passes new tests |
| Task count grows through hypothetical features | Medium | Spec change and owner approval required before adding scope |

## Planning Verification

- [x] Approved specification covers objective, commands, structure, style,
      testing, boundaries, and success criteria.
- [x] Dependencies follow the real schema and codebase.
- [x] Every task has testable acceptance criteria.
- [x] Every task has explicit Sail verification.
- [x] Every task identifies dependencies and primary files.
- [x] Checkpoints follow every milestone.
- [x] Oversized compatibility work has been removed; mechanical deletion is
      bounded into at-most-five-file execution batches.
- [x] Project owner approves this task breakdown.
- [x] Implementation resumed at Task 02.
