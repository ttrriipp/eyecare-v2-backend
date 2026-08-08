# Task List: Panel Role Model

Status: Approved — implementation not started
Plan status: Approved 2026-08-08
Task list approved: 2026-08-08

Spec: `docs/specs/panel-role-model-spec.md`

Implementation is approved but has not started. Begin only on an explicit
future implementation request.

## Phase 1: Additive Foundation

### Task 1: Add the role-assignment pivot and deterministic backfill

**Description:** Generate a forward migration through Sail that creates
`role_user`, creates the fixed `optometrist` role if needed, and backfills all
legacy users according to the approved five-state mapping while retaining
`users.role_id` and `users.is_optometrist` for the transition.

**Acceptance criteria:**

- [ ] The pivot has foreign keys and a unique `(role_id, user_id)` constraint.
- [ ] Every approved legacy state maps to the expected target role set.
- [ ] Unknown or invalid legacy data aborts before legacy columns are changed.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/RoleMigrationTest.php`

**Dependencies:** None

**Files likely touched:**

- `database/migrations/*_create_role_user_and_backfill_assignments.php`
- `tests/Feature/RoleMigrationTest.php`

**Estimated scope:** Small (2 files)

### Task 2: Add the many-to-many role domain contract

**Description:** Add `User::roles()` and `Role::users()` many-to-many
relationships plus centralized fixed-name helpers and scopes. Retain only the
minimum temporary compatibility needed until all callers are migrated.

**Acceptance criteria:**

- [ ] Helpers correctly identify admin, optometrist, staff, patient, panel,
  and dual-role users.
- [ ] `scopeOptometrists()` includes active sole and dual-role optometrists and
  excludes inactive/non-optometrist accounts.
- [ ] Role names are centralized and not duplicated in new authorization code.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/RoleCatalogTest.php`

**Dependencies:** Task 1

**Files likely touched:**

- `app/Models/User.php`
- `app/Models/Role.php`
- `tests/Feature/RoleCatalogTest.php`

**Estimated scope:** Medium (3 files)

### Task 3: Centralize valid and audited role synchronization

**Description:** Add one transactional action for runtime role changes. It
validates approved role sets, blocks self-role mutation and last-admin
removal, syncs assignments, refreshes role state, and writes old/new role
names to `user.role_changed` audit metadata. Remove pivot-incompatible role
auditing from the model observer.

**Acceptance criteria:**

- [ ] All five valid role sets are accepted and all invalid sets are rejected.
- [ ] Self-role changes and last-active-admin removal are rejected.
- [ ] A successful change is immediately visible and emits one audit record
  with old/new role names.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Security/RoleAssignmentTest.php`

**Dependencies:** Task 2

**Files likely touched:**

- `app/Actions/Users/SyncUserRoles.php`
- `app/Observers/UserObserver.php`
- `app/Enums/AuditEvent.php`
- `tests/Feature/Security/RoleAssignmentTest.php`

**Estimated scope:** Medium (4 files)

### Task 4: Update fixed-role seeding and user factories

**Description:** Seed exactly four fixed system roles and update factories to
attach explicit role sets after persistence, including sole optometrist and
dual-role owner states, without creating unsupported combinations.

**Acceptance criteria:**

- [ ] Role seeding is idempotent and yields exactly four fixed names.
- [ ] Admin, optometrist, staff, patient, and admin-optometrist factories
  create the expected assignments.
- [ ] The optometrist factory retains provider-hour setup.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/RoleCatalogTest.php`

**Dependencies:** Tasks 1–2

**Files likely touched:**

- `database/seeders/RoleSeeder.php`
- `database/factories/UserFactory.php`
- `database/factories/RoleFactory.php`
- `tests/Feature/RoleCatalogTest.php`

**Estimated scope:** Medium (4 files)

## Checkpoint: Foundation

- [ ] Tasks 1–4 focused tests pass.
- [ ] Legacy columns remain present pending caller migration.
- [ ] All approved role sets work through model helpers.

## Phase 2: Application Authorization Contract

### Task 5: Move patient account creation to exclusive role assignments

**Description:** Replace `role_id` writes in registration, invitation
acceptance, and authentication compatibility paths with the exclusive patient
role assignment while preserving transactional account creation.

**Acceptance criteria:**

- [ ] Every new/reused patient account has exactly the `patient` role.
- [ ] Registration and invitation responses remain externally unchanged.
- [ ] No patient creation path can attach a panel role.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AuthContractTest.php tests/Feature/Api/V1/AuthContractCharacterizationTest.php tests/Feature/Api/V1/AcceptPatientInvitationTest.php`

**Dependencies:** Tasks 2 and 4

**Files likely touched:**

- `app/Actions/Auth/RegisterPatientAccount.php`
- `app/Actions/PatientAccounts/AcceptPatientInvitation.php`
- `app/Http/Controllers/Api/AuthController.php`
- `tests/Feature/Api/V1/AuthContractTest.php`
- `tests/Feature/Api/V1/AcceptPatientInvitationTest.php`

**Estimated scope:** Medium (5 files)

### Task 6: Preserve patient API guards and role serialization

**Description:** Replace singular-role reads at patient API boundaries with
`isPatient()` and keep the serialized role value exactly `patient`.

**Acceptance criteria:**

- [ ] Patient-only requests still authorize patients and reject every panel
  role set.
- [ ] Mobile account/profile/user resources still emit `role: "patient"`.
- [ ] No role array or panel assignment is exposed through the mobile API.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/PatientLinkAccessMatrixTest.php tests/Feature/Api/V1/SubmitAppointmentRequestTest.php tests/Feature/Api/V1/AuthContractTest.php`

**Dependencies:** Tasks 2 and 5

**Files likely touched:**

- `app/Http/Resources/UserResource.php`
- `app/Http/Resources/PatientAccountResource.php`
- `app/Http/Resources/PatientProfileResource.php`
- `app/Http/Requests/Api/StoreAppointmentRequest.php`
- `app/Http/Requests/Api/AppointmentAvailabilityRequest.php`

**Estimated scope:** Medium (5 files)

### Task 7: Update common operational policies

**Description:** Authorize all three panel roles for shared operations while
preserving admin-only payment correction and other privileged boundaries.

**Acceptance criteria:**

- [ ] Staff, optometrist, admin, and dual-role users receive shared access.
- [ ] Patient-only users receive no panel policy access.
- [ ] Admin-only policy abilities remain admin-only.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Authorization/PanelRolePermissionTest.php`

**Dependencies:** Task 2

**Files likely touched:**

- `app/Policies/BillingRecordPolicy.php`
- `app/Policies/QuotationPolicy.php`
- `app/Policies/ComplaintPolicy.php`
- `app/Policies/FrameReservationPolicy.php`
- `tests/Feature/Authorization/PanelRolePermissionTest.php`

**Estimated scope:** Medium (5 files)

### Task 8: Separate patient, privacy, and prescription policy authority

**Description:** Move remaining policies to centralized helpers, ensuring
prescription viewing is shared, prescription authorship is optometrist-only,
and archive/privacy operations are admin-only.

**Acceptance criteria:**

- [ ] All panel roles can view permitted patient/clinical records.
- [ ] Only optometrists can create/finalize/amend prescriptions.
- [ ] Plain admins retain admin-only patient/privacy powers but no prescription
  authorship.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Authorization/PanelRolePermissionTest.php tests/Feature/Encounters/PrescriptionLifecycleTest.php`

**Dependencies:** Tasks 2 and 7

**Files likely touched:**

- `app/Policies/PatientPolicy.php`
- `app/Policies/PrivacyRequestPolicy.php`
- `app/Policies/PrescriptionPolicy.php`
- `tests/Feature/Authorization/PanelRolePermissionTest.php`

**Estimated scope:** Medium (4 files)

### Task 9: Update operational workflow action authorization

**Description:** Replace singular-role allowlists in quotation, order, and
inventory actions so all panel roles can perform approved common operations.

**Acceptance criteria:**

- [ ] Staff, optometrist, admin, and dual-role users pass common workflow
  guards.
- [ ] Patient users are rejected server-side.
- [ ] Existing workflow state and inventory invariants remain unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Quotations/CreateQuotationTest.php tests/Feature/JobOrders/CreateJobOrderTest.php tests/Feature/OpticalOrders/CreateDirectOpticalOrderTest.php tests/Feature/Inventory/InventoryLedgerTest.php`

**Dependencies:** Tasks 2 and 4

**Files likely touched:**

- `app/Actions/Quotations/CreateQuotation.php`
- `app/Actions/JobOrders/CreateJobOrder.php`
- `app/Actions/OpticalOrders/CreateDirectOpticalOrder.php`
- `app/Actions/Inventory/RecordInventoryMovement.php`

**Estimated scope:** Medium (4 files)

### Task 10: Update panel recipients, reports, and conversation routing

**Description:** Include optometrists in shared panel recipients/reports,
keep admin-specific summaries admin-only, and replace patient-versus-panel
conversation routing with centralized helpers.

**Acceptance criteria:**

- [ ] Shared panel reporting/navigation includes staff, optometrist, and admin.
- [ ] Admin-only daily-summary behavior remains admin-only.
- [ ] Conversation participants and recipients preserve patient/panel privacy.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/DailySummaryTest.php tests/Feature/ConversationTest.php`

**Dependencies:** Tasks 2 and 4

**Files likely touched:**

- `app/Console/Commands/SendDailySummaryCommand.php`
- `app/Filament/Pages/Reports/AppointmentsReport.php`
- `app/Filament/Pages/Reports/ReorderReport.php`
- `app/Http/Controllers/Api/ConversationController.php`
- `tests/Feature/DailySummaryTest.php`

**Estimated scope:** Medium (5 files)

## Checkpoint: Application Contract

- [ ] Tasks 5–10 focused tests pass.
- [ ] Patient API responses have no contract drift.
- [ ] The role permission matrix passes for common and privileged operations.

## Phase 3: Provider and Clinical Workflows

### Task 11: Replace provider eligibility checks

**Description:** Make appointment provider assignment, availability, provider
hours, and provider absences depend on an active `optometrist` role rather
than the legacy boolean.

**Acceptance criteria:**

- [ ] Sole and dual-role optometrists are eligible providers.
- [ ] Plain admins, staff, patients, and inactive optometrists are rejected.
- [ ] Existing schedule-conflict and availability behavior is unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ProviderAvailabilityTest.php tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php`

**Dependencies:** Tasks 2 and 4

**Files likely touched:**

- `app/Actions/Appointments/AssignAppointmentOptometrist.php`
- `app/Actions/Appointments/EvaluateAppointmentAvailability.php`
- `app/Actions/Appointments/UpdateProviderHours.php`
- `app/Actions/Appointments/CreateScheduleOverride.php`
- `tests/Feature/Appointments/ProviderAvailabilityTest.php`

**Estimated scope:** Medium (5 files)

### Task 12: Enforce explicit optometrist authority in clinical actions

**Description:** Update encounter start/completion and prescription
finalization so only an optometrist can author clinical transitions. Preserve
assigned-provider rules; remove the legacy concept of an admin-optometrist
override in favor of explicit role membership.

**Acceptance criteria:**

- [ ] Plain admins and staff fail all clinical authorship guards.
- [ ] Sole and dual-role optometrists pass appropriate clinical guards.
- [ ] Encounter assignment/state and prescription lifecycle invariants remain
  intact.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/PrescriptionLifecycleTest.php tests/Feature/Filament/EncounterResourceTest.php`

**Dependencies:** Tasks 2, 8, and 11

**Files likely touched:**

- `app/Actions/Encounters/StartEncounter.php`
- `app/Actions/Encounters/CompleteEncounter.php`
- `app/Actions/Prescriptions/FinalizePrescription.php`
- `tests/Feature/Encounters/PrescriptionLifecycleTest.php`

**Estimated scope:** Medium (4 files)

### Task 13: Update Encounter Filament authorization

**Description:** Replace raw role/boolean checks on Encounter pages and tables
with policy/helper checks and verify UI actions match server-side clinical
authority.

**Acceptance criteria:**

- [ ] Clinical actions are hidden and forbidden for staff and plain admins.
- [ ] Sole and dual-role optometrists see only valid state-dependent actions.
- [ ] Viewing encounters remains available to all panel roles.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterResourceTest.php`

**Dependencies:** Task 12

**Files likely touched:**

- `app/Filament/Resources/Encounters/Pages/EditEncounter.php`
- `app/Filament/Resources/Encounters/Tables/EncountersTable.php`
- `tests/Feature/Filament/EncounterResourceTest.php`

**Estimated scope:** Medium (3 files)

### Task 14: Update Prescription Filament authorization

**Description:** Align prescription create/view/schema behavior with the
policy so only optometrists can author while every panel role retains approved
read access.

**Acceptance criteria:**

- [ ] Plain admins and staff cannot reach or submit authoring flows.
- [ ] Sole and dual-role optometrists can create/finalize/amend as allowed by
  encounter state.
- [ ] Quotation navigation from prescriptions retains shared panel access.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/PrescriptionResourceTest.php`

**Dependencies:** Tasks 8 and 12

**Files likely touched:**

- `app/Filament/Resources/Prescriptions/Pages/CreatePrescription.php`
- `app/Filament/Resources/Prescriptions/Pages/ViewPrescription.php`
- `app/Filament/Resources/Prescriptions/Schemas/PrescriptionForm.php`
- `tests/Feature/Filament/PrescriptionResourceTest.php`

**Estimated scope:** Medium (4 files)

### Task 15: Enforce availability ownership boundaries

**Description:** Make clinic-wide hours and any-provider overrides admin-only,
while optometrists may manage only their own provider hours and absences.
Staff receive no availability-management access.

**Acceptance criteria:**

- [ ] Plain admins can manage clinic-wide and any-provider availability but
  cannot gain clinical workflow access.
- [ ] Optometrists can manage only their own provider hours/absences.
- [ ] Staff and patients cannot manage availability.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/Availability`

**Dependencies:** Tasks 2 and 11

**Files likely touched:**

- `app/Filament/Clusters/Availability/Pages/AvailabilityClusterPage.php`
- `app/Filament/Clusters/Availability/Pages/ClinicHours.php`
- `app/Filament/Clusters/Availability/Pages/OptometristHours.php`
- `app/Filament/Clusters/Availability/Pages/ScheduleOverrides.php`

**Estimated scope:** Medium (4 files)

## Checkpoint: Clinical Separation

- [ ] Tasks 11–15 focused tests pass.
- [ ] Plain-admin clinical denial and dual-role clinical success are proven.
- [ ] Availability permissions match the approved matrix.

## Phase 4: Team Account Management and Demo Data

### Task 16: Build the Team Accounts role form and save flow

**Description:** Replace `role_id` plus Optometrist toggle with a constrained
role-set input and custom create/edit hooks backed by `SyncUserRoles`. Preserve
password lifecycle behavior.

**Acceptance criteria:**

- [ ] Create/edit accepts only admin, optometrist, staff, or
  admin+optometrist panel role sets.
- [ ] Invalid, empty, patient, and redundant panel combinations are rejected.
- [ ] Password creation/reset and forced-change behavior remain unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/UserResourceTest.php tests/Feature/Security/AccountLifecycleTest.php`

**Dependencies:** Tasks 3–4

**Files likely touched:**

- `app/Filament/Resources/Users/Schemas/UserForm.php`
- `app/Filament/Resources/Users/Pages/CreateUser.php`
- `app/Filament/Resources/Users/Pages/EditUser.php`
- `tests/Feature/Filament/UserResourceTest.php`
- `tests/Feature/Security/AccountLifecycleTest.php`

**Estimated scope:** Medium (5 files)

### Task 17: Update Team Accounts listing and lifecycle safeguards

**Description:** Rename the resource to Team Accounts, include all panel
roles, display/filter multiple roles clearly, and update activation plus
last-admin safeguards for pivot assignments.

**Acceptance criteria:**

- [ ] Only admins can access Team Accounts.
- [ ] Staff, optometrist, admin, and dual-role records display correctly;
  patients are excluded.
- [ ] Self-role, last-active-admin, deactivation, and optometrist eligibility
  safeguards pass.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/UserResourceTest.php tests/Feature/Security/AccountLifecycleTest.php tests/Feature/Filament/AdminNavigationStructureTest.php`

**Dependencies:** Task 16

**Files likely touched:**

- `app/Filament/Resources/Users/UserResource.php`
- `app/Filament/Resources/Users/Tables/UsersTable.php`
- `tests/Feature/Filament/UserResourceTest.php`
- `tests/Feature/Security/AccountLifecycleTest.php`
- `tests/Feature/Filament/AdminNavigationStructureTest.php`

**Estimated scope:** Medium (5 files)

### Task 18: Update canonical demo accounts

**Description:** Seed a dual-role owner, a sole optometrist, a plain staff
account, and patient data idempotently, and update canonical seeder assertions.

**Acceptance criteria:**

- [ ] The owner has admin+optometrist, the clinician has optometrist only, and
  the operational account has staff only.
- [ ] Existing demo emails are migrated predictably or replaced without
  duplicate accounts.
- [ ] Re-running seeders preserves valid assignments.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Seeders/CanonicalSeederTest.php`

**Dependencies:** Tasks 3–4

**Files likely touched:**

- `database/seeders/DemoUserSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- `tests/Feature/Seeders/CanonicalSeederTest.php`

**Estimated scope:** Medium (3 files)

### Task 19: Update workflow and dashboard demo seeders

**Description:** Replace singular staff-role queries in supporting demo
seeders, preserve provider assignment semantics, and integrate carefully with
the user's existing `ClinicWorkflowSeeder.php` changes.

**Acceptance criteria:**

- [ ] Dashboard records use an eligible panel actor.
- [ ] Clinical demo records use an optometrist.
- [ ] The user's unrelated `ClinicWorkflowSeeder.php` modifications remain
  intact.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Seeders/ClinicWorkflowSeederTest.php`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Seeders/DashboardDemoSeederTest.php`

**Dependencies:** Tasks 4, 11, and 18

**Files likely touched:**

- `database/seeders/DashboardDemoSeeder.php`
- `database/seeders/ClinicWorkflowSeeder.php`
- `database/seeders/ProviderHoursSeeder.php`
- `tests/Feature/Seeders/DashboardDemoSeederTest.php`
- `tests/Feature/Seeders/ClinicWorkflowSeederTest.php`

**Estimated scope:** Medium (5 files)

## Checkpoint: Account Administration

- [ ] Tasks 16–19 focused tests pass.
- [ ] Team Accounts accepts only approved assignments.
- [ ] Demo data represents all intended account types.

## Phase 5: Compatibility Cleanup and Contract Migration

### Task 20: Update patient and appointment regression fixtures

**Description:** Replace direct `role_id` and optometrist-boolean test setup
with explicit factory states across patient appointment/API regression tests.

**Acceptance criteria:**

- [ ] Tests use `patient()`, `staff()`, `optometrist()`, or dual-role factory
  states rather than removed columns.
- [ ] Patient request identity, linking, availability, and appointment behavior
  remain unchanged.
- [ ] No test manufactures an invalid patient+panel combination.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/PatientLinkAccessMatrixTest.php tests/Feature/Api/V1/SubmitAppointmentRequestTest.php tests/Feature/Appointments/AppointmentRequestIdentityMatchingTest.php tests/Feature/Appointments/LinkAppointmentRequestToPatientTest.php`

**Dependencies:** Tasks 4–6 and 11

**Files likely touched:**

- `tests/Feature/Api/V1/PatientLinkAccessMatrixTest.php`
- `tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`
- `tests/Feature/Appointments/AppointmentRequestIdentityMatchingTest.php`
- `tests/Feature/Appointments/LinkAppointmentRequestToPatientTest.php`

**Estimated scope:** Medium (4 files)

### Task 21: Update Filament patient-link regression fixtures

**Description:** Move patient-account setup in Filament link/review/request
tests to the new exclusive patient role contract.

**Acceptance criteria:**

- [ ] All linked/unlinked patient fixtures have only the patient role.
- [ ] Panel actors use explicit panel factory states.
- [ ] Link review and appointment-request UI behavior remains unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/PatientLinkAccountTest.php tests/Feature/Filament/PatientLinkRequestReviewTest.php tests/Feature/Filament/ViewAppointmentRequestTest.php`

**Dependencies:** Tasks 4–6

**Files likely touched:**

- `tests/Feature/Filament/PatientLinkAccountTest.php`
- `tests/Feature/Filament/PatientLinkRequestReviewTest.php`
- `tests/Feature/Filament/ViewAppointmentRequestTest.php`

**Estimated scope:** Medium (3 files)

### Task 22: Update remaining domain and end-to-end fixtures

**Description:** Replace remaining legacy flag/role setup in clinical,
appointment, quotation, and end-to-end tests with explicit role states.

**Acceptance criteria:**

- [ ] Receptionist fixtures use `staff()` and clinician fixtures use
  `optometrist()`.
- [ ] Owner/admin clinical scenarios use the explicit dual-role state.
- [ ] Existing domain and end-to-end behavior remains covered.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Filament/CheckInActionTest.php tests/Feature/Filament/QuotationCreationTest.php tests/Feature/Encounters/IntakeVerificationTest.php tests/Feature/EndToEnd/ClinicWorkflowTest.php`

**Dependencies:** Tasks 4 and 7–15

**Files likely touched:**

- `tests/Feature/Filament/AppointmentResourceTest.php`
- `tests/Feature/Filament/CheckInActionTest.php`
- `tests/Feature/Filament/QuotationCreationTest.php`
- `tests/Feature/Encounters/IntakeVerificationTest.php`
- `tests/Feature/EndToEnd/ClinicWorkflowTest.php`

**Estimated scope:** Medium (5 files)

### Task 23: Update remaining patient, model, and rating fixtures

**Description:** Replace the remaining direct role-column assertions and
fixture setup in model, patient-link, and visit-rating tests with the target
relationship and explicit factory states.

**Acceptance criteria:**

- [ ] Patient model assertions use the exclusive patient role relationship.
- [ ] Appointment/rating provider fixtures use explicit optometrist states.
- [ ] Patient-link domain tests no longer write `role_id` directly.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/AppointmentModelTest.php tests/Feature/Patients/PatientModelTest.php tests/Feature/Patients/ReviewPatientLinkRequestTest.php tests/Feature/Patients/SubmitPatientLinkRequestTest.php tests/Feature/Ratings/SaveVisitRatingTest.php`

**Dependencies:** Tasks 4–6 and 11–12

**Files likely touched:**

- `tests/Feature/AppointmentModelTest.php`
- `tests/Feature/Patients/PatientModelTest.php`
- `tests/Feature/Patients/ReviewPatientLinkRequestTest.php`
- `tests/Feature/Patients/SubmitPatientLinkRequestTest.php`
- `tests/Feature/Ratings/SaveVisitRatingTest.php`

**Estimated scope:** Medium (5 files)

### Task 24: Remove remaining legacy application references

**Description:** Run a repository-wide compatibility scan and update remaining
controllers, requests, resources, queries, or relation-loading strings not
covered by earlier slices. Keep this task constrained to five files per pass.

**Acceptance criteria:**

- [ ] No active application caller reads/writes `role_id` or
  `is_optometrist`.
- [ ] No caller uses `hasOptometristCapability()` or the singular `role`
  relationship.
- [ ] Common panel queries include optometrists; patient queries remain
  exclusive.

**Verification:**

- [ ] `rg -n "role_id|is_optometrist|hasOptometristCapability|whereHas\('role'|->role\b" app database/seeders tests`
- [ ] Run focused tests corresponding to every file changed in each pass.

**Dependencies:** Tasks 5–23

**Files likely touched:**

- `app/Http/Controllers/Api/AppointmentController.php`
- `app/Http/Requests/Api/UpdateAppointmentStatusRequest.php`
- `app/Http/Requests/Api/UpdateAppointmentContactNoteRequest.php`
- `app/Http/Requests/Api/StoreConversationRequest.php`
- Up to one additional file identified by the static scan per pass

**Estimated scope:** Medium (maximum 5 files per pass)

### Task 25: Remove legacy role columns and compatibility code

**Description:** After the zero-reference scan, generate a contraction
migration that verifies all user assignments, drops `users.role_id` and
`users.is_optometrist`, and removes temporary model/factory compatibility.

**Acceptance criteria:**

- [ ] Upgrade migration refuses to drop columns if any user lacks a valid role
  set.
- [ ] Fresh and upgraded schemas end with no legacy role columns.
- [ ] Rollback deterministically restores legacy state if reversible.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/RoleMigrationTest.php tests/Feature/RoleCatalogTest.php`

**Dependencies:** Tasks 1–24

**Files likely touched:**

- `database/migrations/*_drop_legacy_user_role_columns.php`
- `app/Models/User.php`
- `database/factories/UserFactory.php`
- `tests/Feature/RoleMigrationTest.php`
- `tests/Feature/RoleCatalogTest.php`

**Estimated scope:** Medium (5 files)

## Checkpoint: Legacy Removal

- [ ] Tasks 20–25 focused tests pass.
- [ ] Static scans find no active legacy access.
- [ ] Fresh and upgrade migration paths produce valid role assignments.

## Phase 6: Documentation and Release Verification

### Task 26: Record the accepted role architecture

**Description:** Add the next repository ADR and update canonical context and
the spec phase/status after implementation is verified. Historical specs stay
unchanged.

**Acceptance criteria:**

- [ ] The ADR records the chosen multi-role design and rejected alternatives.
- [ ] `BACKEND_CONTEXT.md` describes four system roles, three panel roles,
  approved combinations, migration result, and current permission matrix.
- [ ] The spec status reflects actual verification rather than planned work.

**Verification:**

- [ ] `git diff --check -- docs/decisions docs/BACKEND_CONTEXT.md docs/specs/panel-role-model-spec.md`

**Dependencies:** Task 25

**Files likely touched:**

- `docs/decisions/002-separate-administrative-and-clinical-authority.md`
- `docs/BACKEND_CONTEXT.md`
- `docs/specs/panel-role-model-spec.md`

**Estimated scope:** Medium (3 files)

### Task 27: Run final formatting and verification

**Description:** Format all modified PHP, run focused role/security/clinical
suites, run the full suite, scan for legacy symbols, and review the diff for
unrelated worktree damage.

**Acceptance criteria:**

- [ ] Pint completes successfully.
- [ ] Focused and full Pest suites pass.
- [ ] Legacy-reference scan is clean except historical migrations/docs and
  deliberate migration tests.

**Verification:**

- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/RoleMigrationTest.php tests/Feature/RoleCatalogTest.php tests/Feature/Authorization/PanelRolePermissionTest.php tests/Feature/Filament/UserResourceTest.php tests/Feature/Security/AccountLifecycleTest.php tests/Feature/Filament/EncounterResourceTest.php tests/Feature/Filament/PrescriptionResourceTest.php tests/Feature/Filament/Availability`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `git diff --check`
- [ ] `git status --short`

**Dependencies:** Task 26

**Files likely touched:**

- None beyond formatter changes to already modified PHP files

**Estimated scope:** Small

## Final Review Gate

- [ ] Every task and checkpoint is complete.
- [ ] Every success criterion in the approved spec is satisfied.
- [ ] Human reviews the implementation before the feature is considered done.
