# Consultation UI Terminology Checklist

**Spec:** `docs/specs/consultation-ui-terminology-spec.md`
**Plan:** `tasks/consultation-ui-terminology-plan.md`
**Status:** Implemented 2026-08-21

## Phase 1: Core Consultation Surface

- [x] Task 1: Rename core resource presentation
  - Acceptance: Resource labels, table labels/actions, form headings, and badge
    expectations display Consultation without changing internal symbols.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterResourceTest.php tests/Feature/AdminNavigationBadgeTest.php`
  - Files: Encounter resource, table, schema, and focused tests (maximum 5).

- [x] Task 2: Rename consultation workflow actions and feedback
  - Acceptance: Breadcrumbs, actions, modals, and notifications display
    Consultation; workflow behavior is unchanged.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterResourceTest.php tests/Feature/Filament/EncounterTransferActionTest.php`
  - Files: Encounter edit page and focused Filament tests (3).

- [x] Task 3: Rename navigation and dashboard presentation
  - Acceptance: Clinical navigation and all dashboard statistics/actions display
    Consultation while widget internals remain Encounter-based.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Filament/DashboardTest.php tests/Feature/Filament/AdminNavigationStructureTest.php`
  - Files: Dashboard, two widgets, and two tests (5).

### Checkpoint 1

- [x] Phase 1 focused tests pass.
- [x] Core UI contains no user-facing Encounter wording.
- [x] Internal routes, filters, and domain symbols are unchanged.

## Phase 2: Related Workflow Surfaces

- [x] Task 4: Rename Appointment integration wording
  - Acceptance: Check-in, start, and view actions use Consultation while action
    keys and redirects remain unchanged.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Filament/CheckInActionTest.php`
  - Files: Appointment edit/table and two tests (4).

- [x] Task 5: Rename Patient Record relationship wording
  - Acceptance: Patient Record shows Consultations and Consultation source
    labels using unchanged relationships.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Filament/PatientLinkedRecordsTest.php`
  - Files: Two relation managers and one test (3).

- [x] Task 6: Rename Prescription presentation wording
  - Acceptance: Prescription list/view labels and warnings use Consultation;
    routes and relationship names remain unchanged.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Filament/PrescriptionResourceTest.php`
  - Files: Prescription view/table and one test (3).

- [x] Task 7: Rename Billing source presentation
  - Acceptance: Billing source labels and filters display Consultation while
    persisted `encounter` values remain unchanged.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/BillingRecordRelationshipsTest.php`
  - Files: Two models, Billing table, and one test (4).

- [x] Task 8: Rename printed clinical-record wording
  - Acceptance: Printed title, details heading, and footer use Consultation;
    route and audit behavior remain unchanged.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Encounters/EncounterPrintTest.php tests/Feature/Encounters/EncounterPrintAuditTest.php`
  - Files: Print Blade view and print test (2).

### Checkpoint 2

- [x] Phase 2 focused tests pass.
- [x] Related screens and print output use Consultation consistently.
- [x] No machine-readable source or route contract changed.

## Phase 3: User-Readable Validation Messages

- [x] Task 9: Rename start and assignment validation messages
  - Acceptance: Human-readable messages use Consultation with unchanged keys,
    permissions, transitions, and audit events.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Encounters/StartEncounterTest.php tests/Feature/Encounters/AssignEncounterOptometristTest.php`
  - Files: Two actions and two tests (4).

- [x] Task 10: Rename draft and addendum validation messages
  - Acceptance: Draft/additional-note errors use Consultation without changing
    domain behavior.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Encounters/SaveEncounterDraftTest.php tests/Feature/Encounters/CreateEncounterCorrectionTest.php tests/Feature/Encounters/CreateEncounterSupplementTest.php`
  - Files: Two actions and three tests (5).

- [x] Task 11: Rename completion validation messages
  - Acceptance: Completion errors use Consultation; transaction and required
    fields are unchanged.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Encounters/CompleteEncounterTest.php`
  - Files: Completion action and test (2).

- [x] Task 12: Rename transfer and void validation messages
  - Acceptance: Transfer/void errors use Consultation; authorization, status,
    and audit behavior are unchanged.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Encounters/TransferEncounterTest.php tests/Feature/Encounters/VoidEncounterTest.php`
  - Files: Two actions and two tests (4).

- [x] Task 13: Rename cross-domain clinical-source messages
  - Acceptance: Prescription and Quotation validation copy uses Consultation
    with unchanged keys and identifiers.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Encounters/PrescriptionLifecycleTest.php tests/Feature/Quotations/CreateQuotationTest.php`
  - Files: Two actions and two tests (4).

### Checkpoint 3

- [x] Phase 3 focused tests pass.
- [x] User-readable error copy uses Consultation consistently.
- [x] State machines and audit values remain unchanged.

## Phase 4: Regression Guard and Reconciliation

- [x] Task 14: Add targeted terminology guard and reconcile context
  - Acceptance: A focused test rejects user-facing Encounter/Consulation copy
    without flagging internal symbols; context records the UI/backend split.
  - Verify: `vendor/bin/sail artisan test --compact tests/Feature/Filament/ConsultationTerminologyTest.php`
  - Files: New terminology test and `docs/BACKEND_CONTEXT.md` (2).

- [x] Task 15: Run final focused verification and formatting
  - Acceptance: All scoped tests and Pint pass; final diff contains no contract,
    dependency, schema, or unrelated changes.
  - Verify: commands listed under Task 15 in the implementation plan.
  - Files: No new scope; only formatter changes to already modified PHP files.

### Checkpoint 4

- [x] All specification success criteria are satisfied.
- [x] Focused test suite passes after Pint.
- [x] Presentation scan finds no residual user-facing Encounter or Consulation.
- [x] Android-native wording is recorded as a separate repository follow-up.

The focused implementation suites pass. A few broader characterization tests
retain unrelated pre-existing failures (for example, stale Frame Ratings and
Billing navigation expectations, appointment text sizing, and a finalized
prescription fixture); those failures do not involve terminology changes.
