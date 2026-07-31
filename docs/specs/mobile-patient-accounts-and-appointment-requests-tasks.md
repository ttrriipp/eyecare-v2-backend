# Tasks: Mobile Patient Accounts, Linking, Appointment Requests, Encounter Consultation, and Optical Orders

## Status

Phase 3 task list drafted for project-owner review on 2026-07-31 and amended
the same day with the approved date-of-birth, Frame Reservation presentation,
and unified Optical Orders decisions.

This task list implements the approved specification and technical plan:

- `docs/specs/mobile-patient-accounts-and-appointment-requests-spec.md`
- `docs/specs/mobile-patient-accounts-and-appointment-requests-plan.md`

Phase 4 implementation remains unauthorized until this task list is
separately approved. Each implementation task begins with or updates a focused
Pest test, uses Laravel Sail for commands, and ends with the stated
verification.

## Working Rules

- Follow the dependency order. Do not begin a task whose dependencies are
  incomplete.
- Use `vendor/bin/sail artisan make:* --no-interaction` for Laravel files.
- Use Laravel Boost `search-docs` before each code-changing slice.
- Keep controllers and Filament actions thin; shared application actions own
  mutations and transaction boundaries.
- Preserve unrelated user-owned changes, especially the existing edits in the
  Appointment form, Appointment Filament test, and admin theme.
- Do not add packages, edit deployed migrations, expose raw contact values in
  indexes/logs, or remove legacy intake data before the cleanup gate.
- Run `vendor/bin/sail bin pint --dirty --format agent` after every PHP slice.

## Phase A: Contract and Regression Baseline

### Task 1: Freeze the coordinated mobile API contract

**Description:** Replace the obsolete mobile registration, direct-booking,
appointment-type, and intake contract sections with exact request, response,
error, ownership, and idempotency schemas for the approved API.

**Acceptance criteria:**

- [ ] Every proposed `/api/v1` endpoint has an exact request and response
  example, including registration date of birth and linked/unlinked behavior.
- [ ] Breaking routes are marked for coordinated removal and all machine error
  codes are documented; Eyewear/Billing examples allow payment state before
  dispensing.
- [ ] No Android UI implementation is added to this repository.

**Verification:**

- [ ] Review `docs/API_CONTRACT.md` against the approved spec and plan.
- [ ] `git diff --check`

**Dependencies:** None.

**Files likely touched:**

- `docs/API_CONTRACT.md`
- `tests/Feature/Api/V1/ApiRouteContractTest.php`

**Estimated scope:** Small.

### Task 2: Characterize current authentication and patient-link access

**Description:** Lock down the current staff authentication behavior and the
patient route exposure that must be preserved or intentionally replaced.

**Acceptance criteria:**

- [ ] Tests distinguish staff/admin login from patient mobile authentication.
- [ ] Tests identify which current clinical routes expose data through
  `patients.user_id`.
- [ ] Existing behavior passes before replacement work begins.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AuthContractTest.php tests/Feature/Api/V1/ApiRouteContractTest.php`

**Dependencies:** Task 1.

**Files likely touched:**

- `tests/Feature/Api/V1/AuthContractTest.php`
- `tests/Feature/Api/V1/ApiRouteContractTest.php`
- `tests/Feature/Patients/PatientModelTest.php`

**Estimated scope:** Medium.

### Task 3: Characterize scheduling and direct staff booking

**Description:** Preserve the current clinic/provider-hours, override,
availability, direct staff booking, walk-in, and schedule-lock behavior before
introducing request holds.

**Acceptance criteria:**

- [ ] Tests cover availability from clinic/provider hours and overrides.
- [ ] Tests prove staff-created and walk-in appointments remain direct
  Appointments.
- [ ] Existing concurrency protection is captured.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/AppointmentSchedulingTest.php tests/Feature/AppointmentScheduleLockTest.php tests/Feature/Appointments`

**Dependencies:** Task 1.

**Files likely touched:**

- `tests/Feature/AppointmentSchedulingTest.php`
- `tests/Feature/AppointmentScheduleLockTest.php`
- `tests/Feature/Appointments/ProviderAvailabilityTest.php`
- `tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php`

**Estimated scope:** Medium.

### Task 4: Characterize check-in, intake, and Encounter lifecycle

**Description:** Capture the existing intake-to-Encounter data flow,
prescription relationship, and premature Appointment fulfillment behavior so
the approved cutover is explicit.

**Acceptance criteria:**

- [ ] Tests identify what check-in copies and when an Encounter is created.
- [ ] Tests capture the current start/complete state transitions.
- [ ] Existing prescription and encrypted clinical data remain covered.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters`

**Dependencies:** Task 1.

**Files likely touched:**

- `tests/Feature/Encounters/EncounterCheckInTest.php`
- `tests/Feature/Encounters/CheckInTransactionTest.php`
- `tests/Feature/Encounters/PatientIntakeTest.php`
- `tests/Feature/Encounters/PrescriptionLifecycleTest.php`

**Estimated scope:** Medium.

## Checkpoint A: Baseline

- [ ] Tasks 1-4 pass without changing production behavior.
- [ ] The coordinated Android-breaking contract is explicit.
- [ ] Existing dirty worktree changes remain intact.

## Phase B: Shared Security and Additive Data Foundations

### Task 5: Add contact normalization and blind-index configuration

**Description:** Introduce configuration and typed services for canonical email,
Philippine E.164 phone, normalized matching names, and keyed HMAC lookup
hashes.

**Acceptance criteria:**

- [ ] Normalization is deterministic and rejects invalid phone/email input.
- [ ] Blind indexes use a dedicated configured key and never raw contact data.
- [ ] Known normalization and collision-safety cases pass.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Unit/PatientAccounts/ContactIdentityTest.php`

**Dependencies:** Checkpoint A.

**Files likely touched:**

- `config/patient_accounts.php`
- `app/Actions/PatientAccounts/NormalizeContact.php`
- `app/Actions/PatientAccounts/CreateContactLookupHash.php`
- `tests/Unit/PatientAccounts/ContactIdentityTest.php`

**Estimated scope:** Medium.

### Task 6: Add structured patient-account names and verified contacts

**Description:** Add nullable structured names to users and create the
encrypted, uniquely indexed patient account contact model.

**Acceptance criteria:**

- [ ] Existing staff/admin users remain valid.
- [ ] Patient contacts encrypt values and enforce account/type and lookup-hash
  uniqueness.
- [ ] Factory states represent verified primary and secondary contacts.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/PatientAccounts/PatientAccountContactTest.php`

**Dependencies:** Task 5.

**Files likely touched:**

- `database/migrations/*_add_patient_account_names_to_users_table.php`
- `database/migrations/*_create_patient_account_contacts_table.php`
- `app/Models/PatientAccountContact.php`
- `database/factories/PatientAccountContactFactory.php`
- `tests/Feature/PatientAccounts/PatientAccountContactTest.php`

**Estimated scope:** Medium.

### Task 7: Add purpose-bound OTP challenge persistence

**Description:** Add OTP purpose/channel enums and the encrypted,
single-consumption challenge model with expiry and attempt state.

**Acceptance criteria:**

- [ ] Plain OTP codes are never persisted.
- [ ] Challenge states support expiry, invalidation, exhaustion, and
  consumption.
- [ ] Factory states cover pending and terminal challenges.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Auth/OtpChallengeModelTest.php`

**Dependencies:** Task 5.

**Files likely touched:**

- `database/migrations/*_create_otp_challenges_table.php`
- `app/Models/OtpChallenge.php`
- `app/Enums/OtpPurpose.php`
- `database/factories/OtpChallengeFactory.php`
- `tests/Feature/Auth/OtpChallengeModelTest.php`

**Estimated scope:** Medium.

### Task 8: Add patient-link request and candidate persistence

**Description:** Add auditable staff-reviewed link attempts and staff-only
candidate rankings without changing the active `patients.user_id` link.

**Acceptance criteria:**

- [ ] Pending and terminal link requests are separate from the active link.
- [ ] Candidate evidence is non-clinical and never serialized by default.
- [ ] One account cannot acquire two concurrent active link requests.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Patients/PatientLinkRequestModelTest.php`

**Dependencies:** Task 6.

**Files likely touched:**

- `database/migrations/*_create_patient_link_requests_table.php`
- `database/migrations/*_create_patient_link_candidates_table.php`
- `app/Models/PatientLinkRequest.php`
- `app/Models/PatientLinkCandidate.php`
- `tests/Feature/Patients/PatientLinkRequestModelTest.php`

**Estimated scope:** Medium.

### Task 9: Add record-specific patient invitation persistence

**Description:** Add single-use, expiring invitations tied to one Patient and
one encrypted contact destination.

**Acceptance criteria:**

- [ ] Only a secret digest is stored.
- [ ] Invitation states cover pending, accepted, expired, revoked, and failed.
- [ ] Issuance can identify and replace an existing active
  patient/destination invitation.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Patients/PatientInvitationModelTest.php`

**Dependencies:** Tasks 5 and 6.

**Files likely touched:**

- `database/migrations/*_create_patient_invitations_table.php`
- `app/Models/PatientInvitation.php`
- `app/Enums/PatientInvitationStatus.php`
- `database/factories/PatientInvitationFactory.php`
- `tests/Feature/Patients/PatientInvitationModelTest.php`

**Estimated scope:** Medium.

### Task 10: Add appointment-request persistence

**Description:** Add the request aggregate with encrypted reason/snapshot,
provisional duration, expiration, resolution metadata, and unique conversion
target.

**Acceptance criteria:**

- [ ] Linked and unlinked request rows are representable.
- [ ] `appointment_id` is nullable and unique.
- [ ] Pending, accepted, rejected, cancelled, and expired factory states pass.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentRequestModelTest.php`

**Dependencies:** Task 6.

**Files likely touched:**

- `database/migrations/*_create_appointment_requests_table.php`
- `app/Models/AppointmentRequest.php`
- `app/Enums/AppointmentRequestStatus.php`
- `database/factories/AppointmentRequestFactory.php`
- `tests/Feature/Appointments/AppointmentRequestModelTest.php`

**Estimated scope:** Medium.

### Task 11: Add patient lookup hashes and Appointment visit reason

**Description:** Add non-unique clinic contact lookup hashes and the encrypted
Appointment reason copied from accepted requests.

**Acceptance criteria:**

- [ ] Existing Patient contacts are backfilled through the canonical
  normalizer.
- [ ] Duplicate clinic contact hashes are allowed.
- [ ] Appointment reasons are encrypted and nullable for legacy/direct rows.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Patients/PatientContactLookupTest.php tests/Feature/AppointmentModelTest.php`

**Dependencies:** Tasks 5 and 10.

**Files likely touched:**

- `database/migrations/*_add_contact_lookup_hashes_to_patients_table.php`
- `database/migrations/*_add_reason_for_visit_to_appointments_table.php`
- `app/Models/Patient.php`
- `app/Models/Appointment.php`
- `tests/Feature/Patients/PatientContactLookupTest.php`

**Estimated scope:** Medium.

### Task 12: Add Encounter consultation fields and backfill

**Description:** Add encrypted history/plan fields and wizard progress data,
then backfill existing Encounters from linked patient intakes without dropping
legacy structures.

**Acceptance criteria:**

- [ ] Existing Encounter clinical data and linked intake histories survive the
  migration.
- [ ] Re-running the backfill is safe.
- [ ] Rollback of additive schema is tested where safe.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/EncounterIntakeBackfillTest.php`

**Dependencies:** Task 4.

**Files likely touched:**

- `database/migrations/*_add_consultation_fields_to_encounters_table.php`
- `database/migrations/*_backfill_encounter_consultation_fields.php`
- `app/Models/Encounter.php`
- `database/factories/EncounterFactory.php`
- `tests/Feature/Encounters/EncounterIntakeBackfillTest.php`

**Estimated scope:** Medium.

## Checkpoint B: Additive Foundations

- [ ] Tasks 5-12 pass.
- [ ] Migrations are additive and preserve existing accounts and clinical data.
- [ ] The full existing suite still passes.

## Phase C: Patient Authentication and Contact Management

### Task 13: Implement throttled OTP issuance

**Description:** Implement enumeration-safe challenge issuance with purpose,
destination, IP, cooldown, and daily limits.

**Acceptance criteria:**

- [ ] Issue responses do not disclose whether a contact already exists.
- [ ] Resend invalidates earlier pending challenges for the same boundary.
- [ ] All approved throttles use blind-index limiter keys.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Auth/IssueOtpChallengeTest.php`

**Dependencies:** Tasks 5 and 7.

**Files likely touched:**

- `app/Actions/Auth/IssueOtpChallenge.php`
- `app/Http/Requests/Api/IssueOtpRequest.php`
- `app/Http/Controllers/Api/OtpChallengeController.php`
- `routes/api.php`
- `tests/Feature/Auth/IssueOtpChallengeTest.php`

**Estimated scope:** Medium.

### Task 14: Deliver OTPs through encrypted after-commit jobs

**Description:** Add purpose-aware email/SMS delivery behind the existing
provider boundary with sanitized failure state, explicit timeout, and backoff.

**Acceptance criteria:**

- [ ] Jobs are encrypted and dispatched only after transaction commit.
- [ ] Queue payloads, logs, and notification rows contain no plaintext OTP or
  unmasked destination.
- [ ] Provider failure permits a safe resend.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Auth/DeliverOtpChallengeTest.php`

**Dependencies:** Task 13.

**Files likely touched:**

- `app/Jobs/DeliverOtpChallenge.php`
- `app/Notifications/PatientOtp.php`
- `app/Actions/Auth/DispatchOtpChallenge.php`
- `config/services.php`
- `tests/Feature/Auth/DeliverOtpChallengeTest.php`

**Estimated scope:** Medium.

### Task 15: Implement single-use OTP verification

**Description:** Verify purpose-bound codes under row locks and provide one
idempotent consumption boundary for registration, login, recovery, contacts,
and invitations.

**Acceptance criteria:**

- [ ] Expired, invalidated, exhausted, wrong-purpose, and replayed challenges
  fail safely.
- [ ] Concurrent valid submissions produce one consumption result.
- [ ] Attempt and verification throttles enforce the approved limits.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Auth/VerifyOtpChallengeTest.php`

**Dependencies:** Task 13.

**Files likely touched:**

- `app/Actions/Auth/VerifyOtpChallenge.php`
- `app/Http/Requests/Api/VerifyOtpRequest.php`
- `app/Http/Controllers/Api/OtpChallengeController.php`
- `tests/Feature/Auth/VerifyOtpChallengeTest.php`

**Estimated scope:** Medium.

### Task 16: Register a patient mobile account after OTP

**Description:** Replace direct User+Patient registration with creation of only
a patient-role User and its verified primary contact after registration OTP.

**Acceptance criteria:**

- [ ] Registration requires names, date of birth, 12-character confirmed
  password, privacy notice version, OTP, and device metadata.
- [ ] It creates no Patient and no duplicate User for an owned contact.
- [ ] The response follows the frozen contract and issues one labelled token.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/PatientRegistrationTest.php`

**Dependencies:** Tasks 6, 14, and 15.

**Files likely touched:**

- `app/Actions/Auth/RegisterPatientAccount.php`
- `app/Http/Requests/Api/RegisterPatientAccountRequest.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Resources/PatientAccountResource.php`
- `tests/Feature/Api/V1/PatientRegistrationTest.php`

**Estimated scope:** Medium.

### Task 17: Implement password login, OTP step-up, and device tokens

**Description:** Authenticate a patient password without immediately issuing a
token, then issue/rotate a device-labelled Sanctum token after login OTP.

**Acceptance criteria:**

- [ ] Password responses remain enumeration-safe.
- [ ] Same-installation replacement, 30-day expiry, and maximum-five token
  rules pass.
- [ ] Staff/admin login behavior is unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/PatientLoginTest.php`

**Dependencies:** Tasks 14-16.

**Files likely touched:**

- `app/Actions/Auth/BeginPatientLogin.php`
- `app/Actions/Auth/IssuePatientDeviceToken.php`
- `app/Http/Controllers/Api/AuthController.php`
- `routes/api.php`
- `tests/Feature/Api/V1/PatientLoginTest.php`

**Estimated scope:** Medium.

### Task 18: Implement password recovery and token revocation

**Description:** Reset patient passwords after recovery OTP and support
logout-current/logout-all with the approved revocation rules.

**Acceptance criteria:**

- [ ] Recovery changes only the OTP-authorized account password.
- [ ] Recovery revokes other patient tokens and cannot replay the challenge.
- [ ] Current/all logout endpoints revoke the intended tokens idempotently.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/PatientPasswordRecoveryTest.php`

**Dependencies:** Tasks 15 and 17.

**Files likely touched:**

- `app/Actions/Auth/RecoverPatientPassword.php`
- `app/Actions/Auth/RevokePatientTokens.php`
- `app/Http/Controllers/Api/AuthController.php`
- `tests/Feature/Api/V1/PatientPasswordRecoveryTest.php`

**Estimated scope:** Medium.

### Task 19: Implement secondary contact management

**Description:** Let authenticated patient accounts add, verify, select, and
remove email/phone contacts without changing clinic Patient demographics.

**Acceptance criteria:**

- [ ] A second contact becomes usable only after its own OTP.
- [ ] Exactly one verified primary contact remains.
- [ ] Removing/replacing contacts cannot orphan login or update Patient fields.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/PatientContactManagementTest.php`

**Dependencies:** Tasks 15-18.

**Files likely touched:**

- `app/Actions/PatientAccounts/ManagePatientContact.php`
- `app/Http/Requests/Api/ManagePatientContactRequest.php`
- `app/Http/Controllers/Api/PatientAccountContactController.php`
- `routes/api.php`
- `tests/Feature/Api/V1/PatientContactManagementTest.php`

**Estimated scope:** Medium.

### Task 20: Enforce account-only and active-link route boundaries

**Description:** Add one active-link middleware/policy boundary and retrofit
all clinical mobile routes before they can return patient data.

**Acceptance criteria:**

- [ ] Unlinked users may access only account, linking, invitation, and request
  routes.
- [ ] Linked users can access only records owned by their active Patient.
- [ ] Ownership-sensitive misses return the frozen non-disclosing errors.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/PatientLinkAccessMatrixTest.php`

**Dependencies:** Tasks 16-19.

**Files likely touched:**

- `app/Http/Middleware/RequireActivePatientLink.php`
- `bootstrap/app.php`
- `routes/api.php`
- `app/Http/Resources/PatientAccountResource.php`
- `tests/Feature/Api/V1/PatientLinkAccessMatrixTest.php`

**Estimated scope:** Medium.

### Task 21: Migrate existing patient accounts to verified contacts

**Description:** Provide a controlled compatibility migration for existing
patient-role Users without silently trusting conflicting or invalid contacts.

**Acceptance criteria:**

- [ ] Unambiguous existing contacts become compatibility contact rows under
  the approved verification policy.
- [ ] Conflicts are reported for staff review, not merged or overwritten.
- [ ] Existing staff/admin authentication data is untouched.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/PatientAccounts/ExistingAccountMigrationTest.php`

**Dependencies:** Tasks 6 and 20.

**Files likely touched:**

- `app/Console/Commands/MigratePatientAccountContacts.php`
- `app/Actions/PatientAccounts/MigrateExistingPatientContact.php`
- `tests/Feature/PatientAccounts/ExistingAccountMigrationTest.php`
- `routes/console.php`

**Estimated scope:** Medium.

## Checkpoint C: Authentication

- [ ] Tasks 13-21 pass, including replay, enumeration, and concurrency cases.
- [ ] Staff/admin authentication remains unchanged.
- [ ] An unlinked account cannot read clinical data.

## Phase D: Linking, Invitations, and Duplicate-Safe Patient Creation

### Task 22: Rank patient candidates and submit link requests

**Description:** Search normalized clinic data using the unlinked account's
registration name and date of birth, store staff-only candidates, and return
only a generic mobile state.

**Acceptance criteria:**

- [ ] Exact contact/name/date-of-birth evidence ranks highest but never links
  automatically.
- [ ] No-match, uncertain, and duplicate outcomes disclose no patient details.
- [ ] Repeated submission maintains one active request per account.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Patients/SubmitPatientLinkRequestTest.php`

**Dependencies:** Tasks 8, 11, and 20.

**Files likely touched:**

- `app/Actions/PatientAccounts/RankPatientCandidates.php`
- `app/Actions/PatientAccounts/SubmitPatientLinkRequest.php`
- `app/Http/Controllers/Api/PatientLinkRequestController.php`
- `routes/api.php`
- `tests/Feature/Patients/SubmitPatientLinkRequestTest.php`

**Estimated scope:** Medium.

### Task 23: Approve or reject a staff-reviewed patient link

**Description:** Add policy-protected, audited staff actions that activate
exactly one `patients.user_id` link or close the request without linking.

**Acceptance criteria:**

- [ ] Approval rechecks account and Patient eligibility under row locks.
- [ ] Concurrent approval cannot create a second link.
- [ ] Admin-only unlink requires a reason and revokes clinical access.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Patients/ReviewPatientLinkRequestTest.php`

**Dependencies:** Task 22.

**Files likely touched:**

- `app/Actions/PatientAccounts/ReviewPatientLinkRequest.php`
- `app/Actions/PatientAccounts/UnlinkPatientAccount.php`
- `app/Policies/PatientLinkRequestPolicy.php`
- `tests/Feature/Patients/ReviewPatientLinkRequestTest.php`

**Estimated scope:** Medium.

### Task 24: Issue, resend, revoke, and expire patient invitations

**Description:** Let authorized staff manage contact-specific invitations with
seven-day expiry and five-minute resend cooldown.

**Acceptance criteria:**

- [ ] Invitation issuance uses only a contact recorded on the selected Patient.
- [ ] Resend revokes/replaces the prior secret and respects cooldown.
- [ ] Expiry/revocation is idempotent and audited without secret leakage.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Patients/ManagePatientInvitationTest.php`

**Dependencies:** Tasks 9, 14, and 23.

**Files likely touched:**

- `app/Actions/PatientAccounts/IssuePatientInvitation.php`
- `app/Actions/PatientAccounts/RevokePatientInvitation.php`
- `app/Jobs/DeliverPatientInvitation.php`
- `app/Policies/PatientInvitationPolicy.php`
- `tests/Feature/Patients/ManagePatientInvitationTest.php`

**Estimated scope:** Medium.

### Task 25: Accept an invitation and activate the link

**Description:** Verify the invited destination by OTP and atomically create
or reuse the patient account before activating the record-specific link.

**Acceptance criteria:**

- [ ] Destination, secret, OTP purpose, expiry, account, and Patient are
  rechecked under locks.
- [ ] Repeated acceptance is idempotent and never links a different Patient.
- [ ] Already-linked conflicts fail closed without revealing another account.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AcceptPatientInvitationTest.php`

**Dependencies:** Tasks 16, 20, and 24.

**Files likely touched:**

- `app/Actions/PatientAccounts/AcceptPatientInvitation.php`
- `app/Http/Requests/Api/AcceptPatientInvitationRequest.php`
- `app/Http/Controllers/Api/PatientInvitationController.php`
- `routes/api.php`
- `tests/Feature/Api/V1/AcceptPatientInvitationTest.php`

**Estimated scope:** Medium.

### Task 26: Centralize duplicate-safe Patient creation

**Description:** Introduce one duplicate search and confirmed creation boundary
used by all staff entry points, including mobile-request resolution.

**Acceptance criteria:**

- [ ] Search precedes creation and returns possible duplicates.
- [ ] Creation requires an explicit authorized confirmation when candidates
  exist.
- [ ] Creating a Patient never creates a User or sets a password.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Patients/CreatePatientSafelyTest.php`

**Dependencies:** Task 11.

**Files likely touched:**

- `app/Actions/Patients/SearchPatientDuplicates.php`
- `app/Actions/Patients/CreatePatientAfterDuplicateReview.php`
- `app/Policies/PatientPolicy.php`
- `tests/Feature/Patients/CreatePatientSafelyTest.php`

**Estimated scope:** Medium.

### Task 27: Add Patient admin app-access and invitation controls

**Description:** Update the Filament Patient flow to use duplicate-safe
creation and show link/invitation state without raw `user_id` editing.

**Acceptance criteria:**

- [ ] Patient creation requires duplicate review.
- [ ] Edit/view surfaces show account state and authorized invitation actions.
- [ ] Staff cannot create credentials or activate an account for a patient.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/PatientResourceTest.php`

**Dependencies:** Tasks 23-26.

**Files likely touched:**

- `app/Filament/Resources/Patients/Pages/CreatePatient.php`
- `app/Filament/Resources/Patients/Pages/EditPatient.php`
- `app/Filament/Resources/Patients/Schemas/PatientForm.php`
- `app/Filament/Resources/Patients/PatientResource.php`
- `tests/Feature/Filament/PatientResourceTest.php`

**Estimated scope:** Medium.

## Checkpoint D: Identity Linking

- [ ] Tasks 22-27 pass.
- [ ] Only an exact valid invitation can link without staff review.
- [ ] All other links are staff-reviewed and one-to-one.
- [ ] Patient creation remains independent from mobile account creation.

## Phase E: Availability and Appointment Requests

### Task 28: Refactor availability around generic schedule blocks

**Description:** Generalize the existing evaluator so Appointments and
unexpired pending request holds consume capacity through the same
clinic/provider-hours rules.

**Acceptance criteria:**

- [ ] Existing Appointment availability tests remain green.
- [ ] Expired request rows never block capacity even before pruning.
- [ ] A caller can exclude its own block during reschedule or acceptance.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ScheduleBlockAvailabilityTest.php tests/Feature/AppointmentSchedulingTest.php`

**Dependencies:** Tasks 3 and 10.

**Files likely touched:**

- `app/Actions/Appointments/ScheduleBlock.php`
- `app/Actions/Appointments/EvaluateAppointmentAvailability.php`
- `app/Actions/Appointments/ListAvailableAppointmentSlots.php`
- `app/Actions/Appointments/ClinicSchedule.php`
- `tests/Feature/Appointments/ScheduleBlockAvailabilityTest.php`

**Estimated scope:** Medium.

### Task 29: Expose request availability and submit a held request

**Description:** Return server-generated 30-minute slots and let linked or
unlinked accounts submit a preferred slot plus free-text reason.

**Acceptance criteria:**

- [ ] Patients select a returned slot and cannot submit a free-typed
  unavailable time.
- [ ] Submission creates a 24-hour hold and enforces two active requests per
  account.
- [ ] Linked requests snapshot `patient_id`; unlinked requests do not create a
  Patient.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`

**Dependencies:** Tasks 20 and 28.

**Files likely touched:**

- `app/Actions/Appointments/SubmitAppointmentRequest.php`
- `app/Http/Requests/Api/StoreAppointmentRequest.php`
- `app/Http/Controllers/Api/AppointmentRequestController.php`
- `routes/api.php`
- `tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`

**Estimated scope:** Medium.

### Task 30: List, view, and cancel owned appointment requests

**Description:** Add patient-safe request resources and ownership-scoped
listing, detail, and cancellation.

**Acceptance criteria:**

- [ ] Responses expose no match candidates, internal notes, or other accounts.
- [ ] Only a cancellable pending request can be cancelled.
- [ ] Cross-account IDs return the frozen non-disclosing response.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php`

**Dependencies:** Task 29.

**Files likely touched:**

- `app/Actions/Appointments/CancelAppointmentRequest.php`
- `app/Http/Controllers/Api/AppointmentRequestController.php`
- `app/Http/Resources/AppointmentRequestResource.php`
- `routes/api.php`
- `tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php`

**Estimated scope:** Medium.

### Task 31: Expire appointment requests and prune terminal records

**Description:** Add idempotent scheduled maintenance for request expiry and
approved retention without making correctness depend on command timing.

**Acceptance criteria:**

- [ ] Due pending requests become expired without touching terminal requests.
- [ ] The command is scheduled every minute with overlap protection.
- [ ] Retention pruning deletes only records older than configured policy.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ExpireAppointmentRequestsTest.php`

**Dependencies:** Task 30.

**Files likely touched:**

- `app/Console/Commands/ExpireAppointmentRequests.php`
- `app/Actions/Appointments/ExpireAppointmentRequests.php`
- `routes/console.php`
- `tests/Feature/Appointments/ExpireAppointmentRequestsTest.php`

**Estimated scope:** Medium.

### Task 32: Resolve an unlinked request to an existing or new Patient

**Description:** Let staff link the originating account to an eligible
existing Patient or create a duplicate-reviewed Patient and then resolve the
request.

**Acceptance criteria:**

- [ ] Existing Patient resolution uses the same link eligibility checks.
- [ ] New Patient resolution uses the centralized duplicate-safe action.
- [ ] Request and originating account are linked atomically after confirmation.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ResolveAppointmentRequestPatientTest.php`

**Dependencies:** Tasks 23, 26, and 29.

**Files likely touched:**

- `app/Actions/Appointments/ResolveAppointmentRequestPatient.php`
- `app/Policies/AppointmentRequestPolicy.php`
- `tests/Feature/Appointments/ResolveAppointmentRequestPatientTest.php`

**Estimated scope:** Medium.

### Task 33: Accept or reject an appointment request idempotently

**Description:** Let staff select the internal Appointment Type, optionally
adjust to another valid generated slot, and convert the request exactly once.

**Acceptance criteria:**

- [ ] Acceptance rechecks final type duration and schedule capacity under the
  canonical locks while excluding the request's hold.
- [ ] It creates one scheduled Appointment, copies the reason, and returns the
  same Appointment on repeat.
- [ ] Rejection releases the hold and both outcomes notify after commit.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRequestTest.php`

**Dependencies:** Tasks 28 and 32.

**Files likely touched:**

- `app/Actions/Appointments/AcceptAppointmentRequest.php`
- `app/Actions/Appointments/RejectAppointmentRequest.php`
- `app/Notifications/AppointmentRequestReviewed.php`
- `tests/Feature/Appointments/ReviewAppointmentRequestTest.php`

**Estimated scope:** Medium.

### Task 34: Preserve confirmed-appointment APIs under the new contract

**Description:** Remove direct patient booking/type-selection operations while
keeping linked patients' confirmed appointment list, detail, and rescheduling
safe and duration-derived.

**Acceptance criteria:**

- [ ] Direct mobile Appointment creation and patient-selectable types are gone.
- [ ] Confirmed appointment reads remain active-link and ownership scoped.
- [ ] Rescheduling uses the Appointment's internal type duration.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentContractTest.php tests/Feature/Api/V1/ApiRouteContractTest.php`

**Dependencies:** Tasks 20, 29, and 33.

**Files likely touched:**

- `routes/api.php`
- `app/Http/Controllers/Api/AppointmentController.php`
- `app/Http/Requests/Api/RescheduleAppointmentRequest.php`
- `app/Http/Resources/AppointmentResource.php`
- `tests/Feature/Api/V1/AppointmentContractTest.php`

**Estimated scope:** Medium.

## Checkpoint E: Appointment Requests

- [ ] Tasks 28-34 pass, including concurrent submit/accept cases.
- [ ] Patients choose server-generated preferred slots.
- [ ] A request never behaves as a confirmed Appointment before staff accepts.
- [ ] Direct staff and walk-in booking still pass.

## Phase F: Unified Filament Appointment Experience

### Task 35: Add the staff Appointment Request queue and review page

**Description:** Add a policy-backed hidden resource or custom page for the
request model with readiness, masked contact, reason, preferred slot, expiry,
and resolution actions.

**Acceptance criteria:**

- [ ] Staff can filter and review pending/terminal requests.
- [ ] Review actions call shared application actions.
- [ ] Candidate/contact visibility follows authorization and masking rules.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRequestResourceTest.php`

**Dependencies:** Tasks 30, 32, and 33.

**Files likely touched:**

- `app/Filament/Resources/AppointmentRequests/AppointmentRequestResource.php`
- `app/Filament/Resources/AppointmentRequests/Pages/ListAppointmentRequests.php`
- `app/Filament/Resources/AppointmentRequests/Pages/ViewAppointmentRequest.php`
- `app/Filament/Resources/AppointmentRequests/Tables/AppointmentRequestsTable.php`
- `tests/Feature/Filament/AppointmentRequestResourceTest.php`

**Estimated scope:** Medium.

### Task 36: Integrate Requests into the single Appointments destination

**Description:** Present Requests, Today, Upcoming, History, and Calendar from
one visible Appointments navigation destination while keeping separate model
queries.

**Acceptance criteria:**

- [ ] Only one Appointments navigation item is visible.
- [ ] Requests have a pending badge and do not appear as confirmed calendar
  events.
- [ ] Existing Appointment tabs/calendar/direct-create behavior remains.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Filament/AppointmentRequestResourceTest.php`

**Dependencies:** Task 35.

**Files likely touched:**

- `app/Filament/Resources/Appointments/AppointmentResource.php`
- `app/Filament/Resources/Appointments/Pages/ListAppointments.php`
- `app/Filament/Resources/Appointments/Widgets/AppointmentCalendarWidget.php`
- `app/Filament/Resources/AppointmentRequests/AppointmentRequestResource.php`
- `tests/Feature/Filament/AppointmentResourceTest.php`

**Estimated scope:** Medium.

### Task 37: Simplify the Appointment form to operational scheduling

**Description:** Reconcile the user-owned Appointment UI changes while keeping
only patient, assignment, schedule, reason, attendance, lifecycle, and Open
Encounter behavior.

**Acceptance criteria:**

- [ ] The form contains no Patient Health Record or intake UI.
- [ ] Staff-adjusted scheduling uses server availability and confirmed type
  duration.
- [ ] Existing dirty form/theme/test changes are preserved or deliberately
  integrated.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php`
- [ ] `vendor/bin/sail npm run build`

**Dependencies:** Tasks 34 and 36.

**Files likely touched:**

- `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`
- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `app/Filament/Resources/Appointments/Support/AppointmentTime.php`
- `resources/css/filament/admin/theme.css`
- `tests/Feature/Filament/AppointmentResourceTest.php`

**Estimated scope:** Medium.

### Task 38: Add contextual Frame Reservation controls

**Description:** Show the confirmed Appointment's Frame Reservation status,
items, and authorized operational actions while preserving the dedicated
cross-appointment Frame Reservations queue.

**Acceptance criteria:**

- [ ] Appointment rows show a reservation badge and the Appointment page shows
  one compact reservation card when applicable.
- [ ] Prepare, release, and open actions reuse the existing reservation actions
  and mutate the same Frame Reservation shown in the operational queue.
- [ ] Appointment Requests and unlinked accounts cannot create or display a
  clinical Patient's reservation.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentFrameReservationTest.php tests/Feature/Filament/FrameReservationResourceTest.php`

**Dependencies:** Task 37.

**Files likely touched:**

- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `app/Filament/Resources/Appointments/Tables/AppointmentsTable.php`
- `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`
- `tests/Feature/Filament/AppointmentFrameReservationTest.php`
- `tests/Feature/Filament/FrameReservationResourceTest.php`

**Estimated scope:** Medium.

## Checkpoint F: Admin Scheduling

- [ ] Tasks 35-38 pass.
- [ ] Staff use one Appointments destination without merging model storage.
- [ ] Requests never pollute the confirmed operational calendar.
- [ ] Frame Reservations remain one record visible through contextual and
  operational views.

## Phase G: Unified Optical Orders

### Task 39: Link a Job Order to its converted Frame Reservation

**Description:** Add a nullable unique relationship that makes one
Frame Reservation conversion traceable from its resulting Job Order without
creating an Optical Order table.

**Acceptance criteria:**

- [ ] Existing Job Orders and reservations remain valid without the link.
- [ ] One Frame Reservation cannot be converted into multiple Job Orders.
- [ ] Model relationships and factory states represent linked and unlinked
  orders.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/FrameReservationJobOrderLinkTest.php`

**Dependencies:** Task 38.

**Files likely touched:**

- `database/migrations/*_add_frame_reservation_id_to_job_orders_table.php`
- `app/Models/JobOrder.php`
- `app/Models/FrameReservation.php`
- `database/factories/JobOrderFactory.php`
- `tests/Feature/OpticalOrders/FrameReservationJobOrderLinkTest.php`

**Estimated scope:** Medium.

### Task 40: Transfer reserved inventory into the Job Order

**Description:** Convert requested or prepared Frame Reservations into order
commitments under row locks without deducting prepared stock twice.

**Acceptance criteria:**

- [ ] Prepared selected variants receive a net-zero
  reservation-release/order-commitment transfer.
- [ ] Prepared variants not selected by the accepted order are released.
- [ ] Unprepared variants are committed exactly once and retries are
  idempotent.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/ConvertFrameReservationInventoryTest.php`

**Dependencies:** Task 39.

**Files likely touched:**

- `app/Actions/Reservations/ConvertFrameReservationToJobOrder.php`
- `app/Actions/JobOrders/CommitJobOrderInventory.php`
- `app/Models/InventoryMovement.php`
- `tests/Feature/OpticalOrders/ConvertFrameReservationInventoryTest.php`

**Estimated scope:** Medium.

### Task 41: Accept and start an Optical Order atomically

**Description:** Add one idempotent transaction that accepts the latest
quotation revision, creates its Job Order and Billing Record, optionally
records a deposit, and converts an eligible reservation.

**Acceptance criteria:**

- [ ] One action creates or returns exactly one accepted Quotation, queued Job
  Order, and unpaid/partially-paid Billing Record.
- [ ] An optional deposit cannot exceed the balance and is recorded through
  the existing append-only payment action.
- [ ] Concurrent/repeated submission creates no duplicate fulfillment,
  billing, payment, or reservation conversion.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php`

**Dependencies:** Task 40.

**Files likely touched:**

- `app/Actions/OpticalOrders/AcceptAndStartOpticalOrder.php`
- `app/Actions/JobOrders/CreateJobOrder.php`
- `app/Actions/BillingRecords/CreateBillingRecord.php`
- `app/Actions/BillingRecords/RecordBillingPayment.php`
- `tests/Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php`

**Estimated scope:** Medium.

### Task 42: Use existing billing during dispensing and cancellation

**Description:** Stop creating Billing Records at dispensing and make order
cancellation preserve deposit history through explicit authorized
reversal/refund handling.

**Acceptance criteria:**

- [ ] Dispensing requires and links the existing Billing Record and may record
  a final payment without creating another record.
- [ ] Unpaid cancellation reverses inventory and voids billing idempotently.
- [ ] Orders with posted payments cannot silently cancel or void without an
  explicit audited reversal/refund decision.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/OpticalOrderBillingLifecycleTest.php`

**Dependencies:** Task 41.

**Files likely touched:**

- `app/Actions/BillingRecords/DispenseJobOrder.php`
- `app/Actions/OpticalOrders/CancelOpticalOrder.php`
- `app/Actions/BillingRecords/CorrectBillingPayment.php`
- `app/Actions/JobOrders/UpdateJobOrderStatus.php`
- `tests/Feature/OpticalOrders/OpticalOrderBillingLifecycleTest.php`

**Estimated scope:** Medium.

### Task 43: Build the Optical Orders operational queue

**Description:** Add one visible Quotation-anchored Filament resource with
aggregate tabs for estimate, preparation, pickup, payment, and completion.

**Acceptance criteria:**

- [ ] Each Optical Order row shows patient, fulfillment stage, payment state,
  total, and age/due context.
- [ ] Tabs include Draft Estimates, Awaiting Decision, In Preparation, Ready
  for Pickup, Payment Due, and Completed.
- [ ] Queries eager-load aggregate relationships and remain policy-scoped.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrderResourceTest.php`

**Dependencies:** Tasks 41 and 42.

**Files likely touched:**

- `app/Filament/Resources/OpticalOrders/OpticalOrderResource.php`
- `app/Filament/Resources/OpticalOrders/Pages/ListOpticalOrders.php`
- `app/Filament/Resources/OpticalOrders/Tables/OpticalOrdersTable.php`
- `tests/Feature/Filament/OpticalOrderResourceTest.php`

**Estimated scope:** Medium.

### Task 44: Build the aggregate Optical Order detail workflow

**Description:** Present estimate, fulfillment, reservation, payment, and
dispensing as one timeline with context-sensitive actions over the separate
records.

**Acceptance criteria:**

- [ ] Staff can save draft, present, accept/start, prepare, mark ready, record
  payment, dispense, and cancel without changing resources.
- [ ] Fulfillment and payment appear as separate statuses.
- [ ] Every mutation delegates to the shared audited application actions.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrderWorkflowTest.php`

**Dependencies:** Task 43.

**Files likely touched:**

- `app/Filament/Resources/OpticalOrders/Pages/EditOpticalOrder.php`
- `app/Filament/Resources/OpticalOrders/Schemas/OpticalOrderForm.php`
- `app/Filament/Resources/OpticalOrders/Support/OpticalOrderPresenter.php`
- `tests/Feature/Filament/OpticalOrderWorkflowTest.php`

**Estimated scope:** Medium.

### Task 45: Add contextual Create/Open Optical Order actions

**Description:** Let staff enter the same Optical Order workflow from its
Appointment, Encounter, or Frame Reservation with eligible context prefilled.

**Acceptance criteria:**

- [ ] Contextual actions prefill Patient, Encounter, Prescription, and reserved
  frame without exposing raw foreign-key editing.
- [ ] Existing aggregate context opens the same Optical Order instead of
  creating a duplicate.
- [ ] Authorization is consistent from all three entry points.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/OpticalOrderContextActionsTest.php`

**Dependencies:** Task 44.

**Files likely touched:**

- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `app/Filament/Resources/Encounters/Pages/EditEncounter.php`
- `app/Filament/Resources/FrameReservations/Pages/EditFrameReservation.php`
- `tests/Feature/Filament/OpticalOrderContextActionsTest.php`

**Estimated scope:** Medium.

### Task 46: Audit compatibility and hide separate primary navigation

**Description:** Identify active legacy Job Orders without a Quotation, retain
safe fallback access for them, and make Optical Orders the only primary
estimate/fulfillment/billing navigation destination.

**Acceptance criteria:**

- [ ] The audit reports unanchored active Job Orders without changing them.
- [ ] Quotation, Job Order, and Billing Record pages remain directly
  authorized and reachable for compatibility.
- [ ] Only Optical Orders is visible as their primary navigation destination.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders/LegacyOpticalOrderAuditTest.php`

**Dependencies:** Tasks 43-45.

**Files likely touched:**

- `app/Console/Commands/AuditLegacyOpticalOrders.php`
- `app/Filament/Resources/Quotations/QuotationResource.php`
- `app/Filament/Resources/JobOrders/JobOrderResource.php`
- `app/Filament/Resources/BillingRecords/BillingRecordResource.php`
- `tests/Feature/OpticalOrders/LegacyOpticalOrderAuditTest.php`

**Estimated scope:** Medium.

## Checkpoint G: Optical Orders

- [ ] Tasks 39-46 pass.
- [ ] Estimate, fulfillment, payment, and dispensing use one staff workflow.
- [ ] Separate audit records and legacy fallback access remain intact.
- [ ] Prepared reservation conversion produces no net duplicate stock change.
- [ ] Deposits are visible before dispensing and never silently erased.

## Phase H: Encounter Consultation Cutover

### Task 47: Initialize the Encounter clinical draft at check-in

**Description:** Create or reuse one planned Encounter at check-in and prefill
its chief complaint from the Appointment reason, with legacy verified intake
copy only during the compatibility window.

**Acceptance criteria:**

- [ ] Check-in is idempotent and preserves the unique Appointment/Encounter
  relationship.
- [ ] Reason prefills but does not lock the clinician-authored complaint.
- [ ] Legacy data is copied without creating or editing a new PatientIntake.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/EncounterCheckInTest.php tests/Feature/Encounters/CheckInTransactionTest.php`

**Dependencies:** Tasks 12 and 34.

**Files likely touched:**

- `app/Actions/Encounters/CheckInAppointment.php`
- `app/Models/Encounter.php`
- `tests/Feature/Encounters/EncounterCheckInTest.php`
- `tests/Feature/Encounters/CheckInTransactionTest.php`

**Estimated scope:** Medium.

### Task 48: Correct Encounter start and completion transactions

**Description:** Keep the Appointment checked in while consultation is active,
then complete the Encounter and fulfill the Appointment atomically.

**Acceptance criteria:**

- [ ] Start changes only planned Encounter to in-progress.
- [ ] Completion validates required clinical fields and changes both states in
  one transaction.
- [ ] Concurrent/repeated actions are idempotent and cannot diverge states.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/EncounterLifecycleTest.php`

**Dependencies:** Task 47.

**Files likely touched:**

- `app/Actions/Encounters/StartEncounter.php`
- `app/Actions/Encounters/CompleteEncounter.php`
- `app/Models/Encounter.php`
- `tests/Feature/Encounters/EncounterLifecycleTest.php`

**Estimated scope:** Medium.

### Task 49: Build Consultation and Examination wizard stages

**Description:** Convert the Encounter edit page to a full-page resumable
Wizard whose first two stages validate and persist their own clinical drafts.

**Acceptance criteria:**

- [ ] Consultation & History and Examination save independently before
  navigation.
- [ ] Query-string and database progress restore the last valid stage.
- [ ] Unauthorized or completed Encounters cannot mutate drafts.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterWizardDraftTest.php`

**Dependencies:** Tasks 12 and 48.

**Files likely touched:**

- `app/Filament/Resources/Encounters/Pages/EditEncounter.php`
- `app/Filament/Resources/Encounters/Schemas/EncounterForm.php`
- `app/Actions/Encounters/SaveEncounterWizardStep.php`
- `tests/Feature/Filament/EncounterWizardDraftTest.php`

**Estimated scope:** Medium.

### Task 50: Add Prescription & Plan and Review & Complete stages

**Description:** Finish the Wizard with an optional link to the separate
prescription workflow, persisted plan, validated review, and explicit
completion confirmation.

**Acceptance criteria:**

- [ ] Prescription remains a separate related audited record and is optional.
- [ ] Review shows persisted data rather than one unsaved payload.
- [ ] Complete calls the atomic lifecycle action and then renders read-only.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterWizardCompletionTest.php tests/Feature/Encounters/PrescriptionLifecycleTest.php`

**Dependencies:** Tasks 45 and 49.

**Files likely touched:**

- `app/Filament/Resources/Encounters/Pages/EditEncounter.php`
- `app/Filament/Resources/Encounters/Schemas/EncounterForm.php`
- `app/Actions/Encounters/CompleteEncounter.php`
- `tests/Feature/Filament/EncounterWizardCompletionTest.php`
- `tests/Feature/Encounters/PrescriptionLifecycleTest.php`

**Estimated scope:** Medium.

### Task 51: Retire patient intake routes and Appointment intake UI

**Description:** Remove the coordinated mobile intake operations and
Appointment intake page/action after Encounter writes are live, while keeping
legacy data structures for audit compatibility.

**Acceptance criteria:**

- [ ] All three patient intake API routes are absent.
- [ ] Appointment pages have no intake/PHR action or route.
- [ ] Existing legacy intake rows remain readable for authorized audit and
  backfill checks.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/ApiRouteContractTest.php tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Encounters/EncounterIntakeBackfillTest.php`

**Dependencies:** Tasks 47-50.

**Files likely touched:**

- `routes/api.php`
- `app/Filament/Resources/Appointments/AppointmentResource.php`
- `app/Filament/Resources/Appointments/Pages/IntakeForm.php`
- `app/Http/Controllers/Api/PatientIntakeController.php`
- `tests/Feature/Api/V1/ApiRouteContractTest.php`

**Estimated scope:** Medium.

## Checkpoint H: Clinical Consultation

- [ ] Tasks 47-51 pass.
- [ ] Appointment remains checked in until Encounter completion.
- [ ] All clinical drafts live on Encounter and survive stage navigation.
- [ ] Prescription remains optional and separate.

## Phase I: Cleanup, Operations, and Release

### Task 52: Add safe pruning for OTPs, tokens, invitations, and history

**Description:** Add idempotent scheduled pruning commands that use configured
retention and never remove active workflow rows.

**Acceptance criteria:**

- [ ] OTP, expired token, invitation/link, and request pruning honor approved
  defaults.
- [ ] Commands use overlap protection and can be re-run safely.
- [ ] Tests freeze time around every retention boundary.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Console/PatientAccountPruningTest.php`

**Dependencies:** Checkpoints C, D, and E.

**Files likely touched:**

- `app/Console/Commands/PrunePatientAccountData.php`
- `app/Actions/PatientAccounts/PrunePatientAccountData.php`
- `config/patient_accounts.php`
- `routes/console.php`
- `tests/Feature/Console/PatientAccountPruningTest.php`

**Estimated scope:** Medium.

### Task 53: Prove legacy intake cleanup readiness

**Description:** Add an audit command/test that identifies any future
Appointment or Encounter still dependent on unmigrated intake before
destructive cleanup is considered.

**Acceptance criteria:**

- [ ] The audit reports exact counts without exposing clinical narrative.
- [ ] Cleanup is blocked when any dependency remains.
- [ ] Passing the audit does not itself delete data or schema.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/LegacyIntakeCleanupAuditTest.php`

**Dependencies:** Task 51.

**Files likely touched:**

- `app/Console/Commands/AuditLegacyPatientIntakes.php`
- `app/Actions/Encounters/AuditLegacyPatientIntakes.php`
- `tests/Feature/Encounters/LegacyIntakeCleanupAuditTest.php`

**Estimated scope:** Medium.

### Task 54: Remove legacy intake runtime code

**Description:** After Task 53 passes in the target environment and the
coordinated Android release is complete, remove obsolete runtime classes and
relationships while retaining the legacy table until separately approved.

**Acceptance criteria:**

- [ ] No route, page, action, policy, or active model relationship uses
  PatientIntake.
- [ ] No active/future workflow depends on legacy rows.
- [ ] The table is not dropped by this task.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters tests/Feature/Api/V1/ApiRouteContractTest.php`
- [ ] `rg 'PatientIntake|patient_intakes|patient_intake_id' app routes`

**Dependencies:** Task 53 and explicit cleanup confirmation at implementation
time.

**Files likely touched:**

- `app/Models/PatientIntake.php`
- `app/Policies/PatientIntakePolicy.php`
- `app/Actions/Intakes/VerifyPatientIntake.php`
- `app/Http/Resources/PatientIntakeResource.php`
- `app/Models/Encounter.php`

**Estimated scope:** Medium.

### Task 55: Drop the legacy intake schema in a later release

**Description:** Only after runtime cleanup has shipped and production audit
remains clean, add a reversible-as-practical migration that removes
`encounters.patient_intake_id` and `patient_intakes`.

**Acceptance criteria:**

- [ ] A fresh install and an upgraded populated database both migrate.
- [ ] The pre-drop audit is a documented release prerequisite.
- [ ] No deployed migration is edited.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/LegacyIntakeSchemaRemovalTest.php`

**Dependencies:** Task 54, a separate release window, and explicit destructive
schema approval.

**Files likely touched:**

- `database/migrations/*_drop_legacy_patient_intakes.php`
- `tests/Feature/Encounters/LegacyIntakeSchemaRemovalTest.php`

**Estimated scope:** Small.

### Task 56: Reconcile documentation and run release verification

**Description:** Update the backend context and delivered API contract, then
run focused security, API, Filament, migration, full-suite, formatting, and
frontend-build verification.

**Acceptance criteria:**

- [ ] Documentation matches delivered routes, schema, roles, and workflows.
- [ ] No deprecated intake or direct mobile booking contract remains.
- [ ] All approved success criteria have an automated or explicit manual
  verification result.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `vendor/bin/sail npm run build`
- [ ] `git diff --check`

**Dependencies:** Tasks 52-54. Task 55 is a later-release gate and is not
required for the first safe production cutover.

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`
- `docs/API_CONTRACT.md`
- `docs/specs/mobile-patient-accounts-and-appointment-requests-spec.md`
- `docs/specs/mobile-patient-accounts-and-appointment-requests-plan.md`
- `docs/specs/mobile-patient-accounts-and-appointment-requests-tasks.md`

**Estimated scope:** Medium.

## Final Checkpoint

- [ ] Tasks 1-54 and 56 are complete for the first release.
- [ ] Task 55 remains explicitly gated to a later release.
- [ ] Full tests, Pint, and frontend build pass through Sail.
- [ ] No raw OTP, invitation secret, or unmasked contact appears in storage,
  logs, queue payloads, cache keys, or audit metadata.
- [ ] Linked and unlinked access matrices, one-to-one linking, request
  conversion, and Encounter completion hold under concurrency.
- [ ] The project owner reviews the delivered behavior before deployment.

## Phase 3 Approval Gate

Approval of this task list authorizes Phase 4 implementation in dependency
order. Implementation will use incremental and test-driven development and
will pause at the named checkpoints if the approved contract, security model,
migration strategy, or user-owned dirty files require a material change.
