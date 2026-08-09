# Task List: Practical Variable-Duration Appointment Scheduling

Status: Approved — implementation not started
Spec status: Approved 2026-08-08
Plan status: Approved 2026-08-08
Task list approved: 2026-08-08

Spec: `docs/specs/appointment-scheduling-redesign-spec.md`
Plan: `tasks/appointment-scheduling-plan.md`

Implementation has not started. Begin only on a separate explicit
implementation request.

## Standing Definition of Done

Every implementation task must:

- add or update the focused Pest expectation before changing behavior;
- use Sail for Artisan, PHP, Composer, Node, tests, and formatting;
- use Artisan or Filament generators for framework-owned files;
- search installed-version documentation before Laravel/Filament changes;
- preserve unrelated worktree changes;
- run the exact focused verification listed on the task; and
- run `vendor/bin/sail bin pint --dirty --format agent` after PHP changes
  before completing the enclosing checkpoint.

If a task needs more than approximately five files or changes an approved
contract, stop and split it or return to the specification gate.

## Phase 1: Characterization and Additive Data Foundation

### Task 1: Characterize preserved scheduling and request invariants

**Description:** Cover the behavior that must survive the redesign: confirmed
duration snapshots, clinic/provider boundaries, patient ownership, and legacy
type-less request readability. Do not lock tests to the retired capacity hold.

**Acceptance criteria:**

- [ ] Confirmed duration is independent of later type-default changes.
- [ ] Clinic/provider boundaries and request ownership are characterized.
- [ ] A legacy request with null new fields remains readable and reviewable.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/SchedulingCharacterizationTest.php tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php`

**Dependencies:** None

**Files likely touched:**

- `tests/Feature/Appointments/SchedulingCharacterizationTest.php`
- `tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php`

**Estimated scope:** Small (2 files)

### Task 2: Add patient-visible appointment-type metadata

**Description:** Generate the additive type migration and update the model and
factory for patient label, description, visibility, label fallback, and active
patient-visible selection.

**Acceptance criteria:**

- [ ] Existing types receive safe nullable metadata and visible defaults.
- [ ] The model exposes the approved active patient-visible contract.
- [ ] Application boundaries accept only 5-240 minutes in five-minute increments.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentTypeTest.php`

**Dependencies:** Task 1

**Files likely touched:**

- `database/migrations/*_add_patient_fields_to_appointment_types_table.php`
- `app/Models/AppointmentType.php`
- `database/factories/AppointmentTypeFactory.php`
- `tests/Feature/Appointments/AppointmentTypeTest.php`

**Estimated scope:** Medium (4 files)

### Task 3: Add request preference and review fields

**Description:** Generate the additive request migration and update its model
and factory for alternatives, preferred optometrist, encrypted referral
source, and review due time while preserving historical nulls.

**Acceptance criteria:**

- [ ] Preferred provider is nullable, indexed, and uses `nullOnDelete`.
- [ ] Alternatives, provider preference, and review due cast predictably.
- [ ] Referral source is encrypted at rest.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentRequestModelTest.php`

**Dependencies:** Task 1

**Files likely touched:**

- `database/migrations/*_add_scheduling_preferences_to_appointment_requests_table.php`
- `app/Models/AppointmentRequest.php`
- `database/factories/AppointmentRequestFactory.php`
- `tests/Feature/Appointments/AppointmentRequestModelTest.php`

**Estimated scope:** Medium (4 files)

### Task 4: Transition the canonical appointment-type catalog

**Description:** Seed the six approved types idempotently, populate patient
metadata, and change untouched New Patient and Referral defaults from 30 to 45
minutes without overwriting clinic customization.

**Acceptance criteria:**

- [ ] Repeated seeding creates no duplicate canonical types.
- [ ] Clinic-customized defaults remain unchanged.
- [ ] Historical appointment duration snapshots remain unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Seeders/CanonicalSeederTest.php tests/Feature/Appointments/AppointmentTypeCatalogMigrationTest.php`

**Dependencies:** Task 2

**Files likely touched:**

- `database/seeders/AppointmentTypeSeeder.php`
- `tests/Feature/Seeders/CanonicalSeederTest.php`
- `tests/Feature/Appointments/AppointmentTypeCatalogMigrationTest.php`

**Estimated scope:** Medium (3 files)

## Checkpoint: Data Foundation

- [ ] `vendor/bin/sail artisan migrate --no-interaction`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/AppointmentTypeTest.php tests/Feature/Appointments/AppointmentRequestModelTest.php tests/Feature/Appointments/AppointmentTypeCatalogMigrationTest.php tests/Feature/Seeders/CanonicalSeederTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] Fresh and representative upgrade states preserve historical data.

## Phase 2: Patient Discovery and Provider-Aware Availability

### Task 5: Restore the patient appointment-type catalog endpoint

**Description:** Add the account-only route, thin controller, patient-safe
resource, and contract coverage for active patient-visible types.

**Acceptance criteria:**

- [ ] Linked and unlinked patient accounts can list visible active types.
- [ ] Inactive/internal-only types and internal labels are excluded.
- [ ] The response contains only the approved patient-safe fields.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentTypeCatalogTest.php`
- [ ] `vendor/bin/sail artisan route:list --except-vendor --path=api/v1/appointment-types`

**Dependencies:** Tasks 2 and 4

**Files likely touched:**

- `routes/api.php`
- `app/Http/Controllers/Api/AppointmentTypeController.php`
- `app/Http/Resources/AppointmentTypeResource.php`
- `tests/Feature/Api/V1/AppointmentTypeCatalogTest.php`

**Estimated scope:** Medium (4 files)

### Task 6: Add the patient-safe optometrist catalog endpoint

**Description:** Add an account-only endpoint listing active optometrists
through the central role scope with only stable ID and display name.

**Acceptance criteria:**

- [ ] Active sole and dual-role optometrists are listed once.
- [ ] Inactive/non-optometrist users are absent.
- [ ] Contact, role, schedule, and other private fields are excluded.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentOptometristCatalogTest.php`
- [ ] `vendor/bin/sail artisan route:list --except-vendor --path=api/v1/appointment-optometrists`

**Dependencies:** Task 1

**Files likely touched:**

- `routes/api.php`
- `app/Http/Controllers/Api/AppointmentOptometristController.php`
- `app/Http/Resources/AppointmentOptometristResource.php`
- `tests/Feature/Api/V1/AppointmentOptometristCatalogTest.php`

**Estimated scope:** Medium (4 files)

### Task 7: Correct provider capacity for each candidate interval

**Description:** Calculate eligible capacity per proposed interval rather than
once for the full day, preserving assigned and unassigned capacity rules.

**Acceptance criteria:**

- [ ] Partial shifts and absences affect only overlapping slots.
- [ ] One provider cannot receive overlapping confirmed appointments.
- [ ] Another eligible provider permits concurrent appointments.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ProviderAvailabilityTest.php tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php tests/Feature/Appointments/SchedulingCharacterizationTest.php`

**Dependencies:** Task 1

**Files likely touched:**

- `app/Actions/Appointments/EvaluateAppointmentAvailability.php`
- `app/Actions/Appointments/ListAvailableAppointmentSlots.php`
- `tests/Feature/Appointments/ProviderAvailabilityTest.php`
- `tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php`
- `tests/Feature/Appointments/SchedulingCharacterizationTest.php`

**Estimated scope:** Medium (5 files)

### Task 8: Make request availability variable-duration and non-blocking

**Description:** Require a visible type, accept an optional preferred
optometrist, use the shared cadence/duration engine, and exclude request holds.

**Acceptance criteria:**

- [ ] A 45-minute visit returns 45-minute intervals every 15 minutes.
- [ ] Preferred-provider queries use that provider's exact availability.
- [ ] Pending requests do not change availability; confirmed appointments do.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentContractTest.php tests/Feature/Appointments/ScheduleBlockAvailabilityTest.php tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php`

**Dependencies:** Tasks 2, 6, and 7

**Files likely touched:**

- `app/Http/Requests/Api/AppointmentRequestAvailabilityRequest.php`
- `app/Http/Controllers/Api/AppointmentRequestAvailabilityController.php`
- `app/Actions/Appointments/ListAppointmentRequestAvailabilitySlots.php`
- `tests/Feature/Api/V1/AppointmentContractTest.php`
- `tests/Feature/Appointments/ScheduleBlockAvailabilityTest.php`

**Estimated scope:** Medium (5 files)

## Checkpoint: Discovery and Availability

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentTypeCatalogTest.php tests/Feature/Api/V1/AppointmentOptometristCatalogTest.php tests/Feature/Api/V1/AppointmentContractTest.php tests/Feature/Appointments/ProviderAvailabilityTest.php tests/Feature/Appointments/ProviderAvailabilityScheduleTest.php tests/Feature/Appointments/ScheduleBlockAvailabilityTest.php`
- [ ] `vendor/bin/sail artisan route:list --except-vendor --path=api/v1`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] Patient discovery and variable-duration availability work end to end.

## Phase 3: Request Submission, Reads, and Expiration

### Task 9: Validate the expanded request-submission contract

**Description:** Validate required type, bounded distinct alternatives,
preferred provider, grid/future rules, and conditional referral source.

**Acceptance criteria:**

- [ ] Missing/hidden/inactive types and invalid providers fail with 422.
- [ ] Excess, duplicate, malformed, elapsed, and off-grid preferences fail.
- [ ] Referral source is required only for configured types.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`

**Dependencies:** Tasks 2, 3, 6, and 8

**Files likely touched:**

- `app/Http/Requests/Api/StoreAppointmentRequest.php`
- `tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`

**Estimated scope:** Small (2 files)

### Task 10: Persist variable-duration requests without holds

**Description:** Validate all preferences, snapshot type duration and referral
context, persist provider/alternatives, and calculate review due and expiry
while preserving identity and throttle safeguards.

**Acceptance criteria:**

- [ ] Every preference is currently valid for the same type/provider.
- [ ] Duration, alternatives, referral, review due, and expiry persist.
- [ ] Submission creates no schedule block or confirmed appointment.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SubmitAppointmentRequestTest.php tests/Feature/Appointments/ScheduleBlockAvailabilityTest.php`

**Dependencies:** Task 9

**Files likely touched:**

- `app/Actions/Appointments/SubmitAppointmentRequest.php`
- `app/Actions/Appointments/ResolveAppointmentRequestReviewDueAt.php`
- `app/Http/Controllers/Api/AppointmentRequestController.php`
- `tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`
- `tests/Feature/Appointments/ScheduleBlockAvailabilityTest.php`

**Estimated scope:** Medium (5 files)

### Task 11: Centralize expanded appointment-request responses

**Description:** Use one API resource for create, list, detail, and cancel,
keeping existing fields and adding the approved scheduling context.

**Acceptance criteria:**

- [ ] All request response paths share one documented shape.
- [ ] Legacy null fields serialize safely.
- [ ] Identity snapshots and private staff fields never appear.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AppointmentContractTest.php tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`

**Dependencies:** Task 10

**Files likely touched:**

- `app/Http/Resources/AppointmentRequestResource.php`
- `app/Http/Controllers/Api/AppointmentRequestController.php`
- `tests/Feature/Api/V1/AppointmentContractTest.php`
- `tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php`

**Estimated scope:** Medium (4 files)

### Task 12: Align request expiration and active-limit behavior

**Description:** Expire new requests after their latest preference, keep
review overdue separate, and preserve legacy timestamps.

**Acceptance criteria:**

- [ ] A request stays pending while at least one preference is future.
- [ ] The expiry command expires it after the final option passes.
- [ ] Review overdue neither expires it nor bypasses active limits.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ExpireAppointmentRequestsTest.php tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`

**Dependencies:** Tasks 3 and 10

**Files likely touched:**

- `app/Actions/Appointments/ExpireAppointmentRequests.php`
- `app/Models/AppointmentRequest.php`
- `tests/Feature/Appointments/ExpireAppointmentRequestsTest.php`
- `tests/Feature/Api/V1/SubmitAppointmentRequestTest.php`

**Estimated scope:** Medium (4 files)

## Checkpoint: Patient Request Flow

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/SubmitAppointmentRequestTest.php tests/Feature/Api/V1/AppointmentContractTest.php tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php tests/Feature/Appointments/ExpireAppointmentRequestsTest.php tests/Feature/Appointments/ScheduleBlockAvailabilityTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] The complete patient request flow passes without reserving capacity.

## Phase 4: Atomic Confirmation and Filament Review

### Task 13: Make request acceptance provider-assigned and concurrency-safe

**Description:** Require final provider/type/duration/start, lock the request
and schedule date, and recheck the provider interval in one retryable transaction.

**Acceptance criteria:**

- [ ] Every request-created appointment has a final active optometrist.
- [ ] Concurrent acceptance cannot overbook or resolve twice.
- [ ] Idempotent retry returns the accepted appointment.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRequestTest.php tests/Feature/AppointmentScheduleLockTest.php`

**Dependencies:** Tasks 7, 10, and 12

**Files likely touched:**

- `app/Actions/Appointments/AcceptAppointmentRequest.php`
- `app/Actions/Appointments/LockAppointmentScheduleDate.php`
- `tests/Feature/Appointments/ReviewAppointmentRequestTest.php`
- `tests/Feature/AppointmentScheduleLockTest.php`

**Estimated scope:** Medium (4 files)

### Task 14: Enforce referral and outside-preference confirmation

**Description:** Require and copy referral context and require a contact note
when the final start is outside all submitted preferences.

**Acceptance criteria:**

- [ ] Final type controls referral-source validation and copying.
- [ ] Outside-preference confirmation requires a contact note.
- [ ] Any failure rolls back and leaves the request pending.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRequestTest.php`

**Dependencies:** Task 13

**Files likely touched:**

- `app/Actions/Appointments/AcceptAppointmentRequest.php`
- `tests/Feature/Appointments/ReviewAppointmentRequestTest.php`

**Estimated scope:** Small (2 files)

### Task 15: Build the complete Filament request-review workflow

**Description:** Show all scheduling context and let reviewers reclassify,
override duration, assign a provider, and choose a final time with conditional
validation.

**Acceptance criteria:**

- [ ] Staff, optometrist, and admin can complete a valid review.
- [ ] Type changes reapply defaults before valid overrides.
- [ ] Availability/referral/contact failures render as form errors.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/ViewAppointmentRequestTest.php tests/Feature/Filament/AppointmentRequestResourceTest.php`

**Dependencies:** Tasks 11, 13, and 14

**Files likely touched:**

- `app/Filament/Resources/AppointmentRequests/Pages/ViewAppointmentRequest.php`
- `app/Filament/Resources/AppointmentRequests/Schemas/AppointmentRequestForm.php`
- `tests/Feature/Filament/ViewAppointmentRequestTest.php`
- `tests/Feature/Filament/AppointmentRequestResourceTest.php`

**Estimated scope:** Medium (4 files)

### Task 16: Align the Filament request queue

**Description:** Show type/duration/preference/provider/overdue context and
remove or redirect incomplete quick acceptance.

**Acceptance criteria:**

- [ ] Queue rows communicate tentative demand and overdue state.
- [ ] Filters use lifecycle state, not retired hold semantics.
- [ ] Acceptance requires the complete review workflow.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentRequestResourceTest.php tests/Feature/AdminNavigationBadgeTest.php`

**Dependencies:** Tasks 12 and 15

**Files likely touched:**

- `app/Filament/Resources/AppointmentRequests/Tables/AppointmentRequestsTable.php`
- `app/Filament/Resources/AppointmentRequests/AppointmentRequestResource.php`
- `app/Filament/Resources/AppointmentRequests/Pages/ListAppointmentRequests.php`
- `tests/Feature/Filament/AppointmentRequestResourceTest.php`
- `tests/Feature/AdminNavigationBadgeTest.php`

**Estimated scope:** Medium (5 files)

## Checkpoint: Staff Confirmation

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Appointments/ReviewAppointmentRequestTest.php tests/Feature/AppointmentScheduleLockTest.php tests/Feature/Filament/ViewAppointmentRequestTest.php tests/Feature/Filament/AppointmentRequestResourceTest.php tests/Feature/AdminNavigationBadgeTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] Confirmation is atomic and unavailable through incomplete shortcuts.

## Phase 5: Admin Configuration and Staff Scheduling

### Task 17: Add the admin-only Appointment Types resource shell

**Description:** Generate the smallest Filament v5 cluster resource and add
list, navigation, table, and server-side authorization without destructive deletion.

**Acceptance criteria:**

- [ ] Admins can access the type list under Availability.
- [ ] Staff and optometrists cannot navigate to or access it directly.
- [ ] Referenced types cannot be destructively deleted.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/Availability/AppointmentTypeResourceTest.php --filter='list|authorization|delete'`

**Dependencies:** Tasks 2 and 4

**Files likely touched:**

- `app/Filament/Clusters/Availability/Resources/AppointmentTypes/AppointmentTypeResource.php`
- `app/Filament/Clusters/Availability/Resources/AppointmentTypes/Pages/ListAppointmentTypes.php`
- `app/Filament/Clusters/Availability/Resources/AppointmentTypes/Tables/AppointmentTypesTable.php`
- `tests/Feature/Filament/Availability/AppointmentTypeResourceTest.php`

**Estimated scope:** Medium (4 files; split if the generator requires more)

### Task 18: Add appointment-type create, edit, and deactivate forms

**Description:** Implement all approved configuration fields and validations,
preserving historical use when types become inactive.

**Acceptance criteria:**

- [ ] Admin can configure labels, description, duration, referral, visibility, and state.
- [ ] Invalid durations fail and deactivation hides only new selection.
- [ ] Default edits do not change historical snapshots.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/Availability/AppointmentTypeResourceTest.php --filter='create|edit|deactivate|duration'`

**Dependencies:** Task 17

**Files likely touched:**

- `app/Filament/Clusters/Availability/Resources/AppointmentTypes/Schemas/AppointmentTypeForm.php`
- `app/Filament/Clusters/Availability/Resources/AppointmentTypes/Pages/CreateAppointmentType.php`
- `app/Filament/Clusters/Availability/Resources/AppointmentTypes/Pages/EditAppointmentType.php`
- `tests/Feature/Filament/Availability/AppointmentTypeResourceTest.php`

**Estimated scope:** Medium (4 files)

### Task 19: Align staff scheduled creation with type defaults

**Description:** Use active types, reactive/editable duration, conditional
referral source, and grid enforcement while preserving walk-ins.

**Acceptance criteria:**

- [ ] Scheduled creation snapshots the default or valid explicit duration.
- [ ] Referral and grid rules use configuration rather than type names.
- [ ] Walk-ins remain immediate and grid-exempt.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/AppointmentSchedulingTest.php`

**Dependencies:** Tasks 2, 7, and 18

**Files likely touched:**

- `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`
- `app/Filament/Resources/Appointments/Pages/CreateAppointment.php`
- `app/Actions/Appointments/CreateScheduledAppointment.php`
- `tests/Feature/Filament/AppointmentResourceTest.php`
- `tests/Feature/AppointmentSchedulingTest.php`

**Estimated scope:** Medium (5 files)

### Task 20: Revalidate schedule-defining appointment edits

**Description:** Route pre-check-in type, duration, provider, and time changes
through the date-lock/provider-aware invariant while preserving historical
off-grid records until deliberately changed.

**Acceptance criteria:**

- [ ] Type change applies its current default before an explicit override.
- [ ] Conflicting schedule changes fail without partial persistence.
- [ ] Checked-in schedule fields remain immutable.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/AppointmentSchedulingTest.php tests/Feature/AppointmentScheduleLockTest.php`

**Dependencies:** Tasks 7 and 19

**Files likely touched:**

- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `app/Filament/Resources/Appointments/Schemas/AppointmentForm.php`
- `app/Actions/Appointments/ScheduleAppointment.php`
- `tests/Feature/Filament/AppointmentResourceTest.php`
- `tests/Feature/AppointmentSchedulingTest.php`

**Estimated scope:** Medium (5 files)

## Checkpoint: Panel Scheduling

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/Availability/AppointmentTypeResourceTest.php tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/AppointmentSchedulingTest.php tests/Feature/AppointmentScheduleLockTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] Admin configuration and staff scheduling meet the approved contracts.

## Phase 6: Contract Reconciliation and Release Verification

### Task 21: Reconcile configuration and canonical documentation

**Description:** Remove active fixed-hold semantics and update the Android API
contract, backend context, and spec status to implemented behavior.

**Acceptance criteria:**

- [ ] API docs cover catalogs, preferences, non-reservation, and coordinated cutover.
- [ ] Backend context matches the implemented schema/workflow.
- [ ] Active configuration no longer claims a 30-minute hold or fixed 24-hour expiry.

**Verification:**

- [ ] `rg -n "hold_duration_minutes|24-hour capacity hold|Patient does not select appointment type|pending request holds" config app docs/API_CONTRACT.md docs/BACKEND_CONTEXT.md`
- [ ] `git diff --check -- config/patient_accounts.php docs/API_CONTRACT.md docs/BACKEND_CONTEXT.md docs/specs/appointment-scheduling-redesign-spec.md`

**Dependencies:** Tasks 5-20

**Files likely touched:**

- `config/patient_accounts.php`
- `docs/API_CONTRACT.md`
- `docs/BACKEND_CONTEXT.md`
- `docs/specs/appointment-scheduling-redesign-spec.md`

**Estimated scope:** Medium (4 files)

### Task 22: Run final formatting and regression verification

**Description:** Run migrations, routes, static checks, Pint, focused suites,
and the full Pest suite; fix only feature-caused regressions through their
owning task.

**Acceptance criteria:**

- [ ] Fresh/upgrade migrations, routes, focused checkpoints, and full suite pass.
- [ ] Modified PHP is formatted and retired hold assumptions are absent.
- [ ] No unrelated change is overwritten, committed, or deployed.

**Verification:**

- [ ] `vendor/bin/sail artisan migrate --no-interaction`
- [ ] `vendor/bin/sail artisan route:list --except-vendor --path=api/v1`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `git diff --check`

**Dependencies:** Task 21

**Files likely touched:**

- No planned source file; feature-caused regressions return to their owning task.

**Estimated scope:** Verification only

## Final Checkpoint

- [ ] All 14 success criteria in the approved specification are satisfied.
- [ ] All 22 tasks and phase checkpoints are complete.
- [ ] API documentation matches runtime routes and responses.
- [ ] Focused and full Pest tests pass.
- [ ] Dirty PHP files pass Pint.
- [ ] Unrelated user changes remain intact.
- [ ] Implementation is ready for review; no commit or deployment is automatic.
