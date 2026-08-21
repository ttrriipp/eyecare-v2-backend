# Implementation Plan: Consultation UI Terminology

## Overview

Apply the approved `Encounter` → `Consultation` terminology contract to every
human-facing surface owned by this backend while preserving all Encounter-based
implementation and machine-readable contracts. Work proceeds in small vertical
slices so each surface is tested as it changes and the application remains
usable after every task.

**Specification:** `docs/specs/consultation-ui-terminology-spec.md`  
**Approved:** 2026-08-21

## Inventory Summary

User-facing wording exists in these areas:

1. the Encounter Filament resource, form, table, edit workflow, and actions;
2. Clinical navigation, dashboard actions, and statistics widgets;
3. Appointment check-in/start/view actions;
4. Patient Record and Prescription relationship surfaces;
5. Billing source labels and filters;
6. completed clinical-record print output;
7. validation messages emitted by Encounter, Prescription, and Quotation
   domain actions; and
8. tests that assert the current wording.

Internal occurrences in PHP symbols, route paths, database identifiers, enum
values, audit values, test descriptions, and technical comments are explicitly
out of scope.

## Architecture Decisions

- **Explicit presentation labels:** Keep internal names such as
  `EncounterResource`, `encounter_number`, and `startEncounter`; set their
  visible labels explicitly to Consultation wording. This prevents contract
  drift and avoids compatibility aliases.
- **No terminology abstraction:** Do not add a service, enum, translation
  registry, or dependency for one approved English term. Existing explicit
  Filament labels are the simplest consistent pattern.
- **Human-readable errors are UI:** Validation array keys remain `encounter`,
  but their message text uses `consultation` because Filament and mobile clients
  may display it directly.
- **Targeted regression guard:** Add a focused Pest guard over an explicit set
  of presentation files and rendered labels. It must distinguish visible string
  literals from legitimate internal Encounter identifiers.
- **Technical identifiers remain stable:** Routes, URLs, JSON keys, audit event
  values, stored numbers, class names, filenames, namespaces, schema, and
  behavior do not change.
- **Sequential implementation:** The slices share Filament tests and terminology
  expectations, so sequential work is lower risk than parallel edits.

## Dependency Graph

```text
Approved terminology contract
    -> core Consultation resource labels
        -> navigation and dashboard wording
        -> Appointment integration wording
        -> Patient and Prescription wording
        -> Billing source wording
        -> print wording
    -> user-readable domain validation wording
        -> Encounter actions
        -> Prescription and Quotation actions
    -> targeted terminology regression guard
        -> final focused suite and formatting
        -> context-document reconciliation
```

No database, route, or API dependency changes are required.

## Phase 1: Core Consultation Surface

### Task 1: Rename core resource presentation

Update the resource metadata, list table, and form headings/labels while keeping
all Encounter implementation symbols intact. Update focused resource and badge
assertions test-first.

**Files likely touched:**

- `app/Filament/Resources/Encounters/EncounterResource.php`
- `app/Filament/Resources/Encounters/Tables/EncountersTable.php`
- `app/Filament/Resources/Encounters/Schemas/EncounterForm.php`
- `tests/Feature/Filament/EncounterResourceTest.php`
- `tests/Feature/AdminNavigationBadgeTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterResourceTest.php tests/Feature/AdminNavigationBadgeTest.php
```

### Task 2: Rename consultation workflow actions and feedback

Update the edit page breadcrumb, action labels, modal copy, success/error
notifications, and completed-record note wording.

**Files likely touched:**

- `app/Filament/Resources/Encounters/Pages/EditEncounter.php`
- `tests/Feature/Filament/EncounterResourceTest.php`
- `tests/Feature/Filament/EncounterTransferActionTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterResourceTest.php tests/Feature/Filament/EncounterTransferActionTest.php
```

### Task 3: Rename navigation and dashboard presentation

Update the Clinical navigation expectation, dashboard action, and active/total
Consultation statistics without renaming widget classes, event names, query
parameters, or data keys.

**Files likely touched:**

- `app/Filament/Pages/Dashboard.php`
- `app/Filament/Widgets/StatsOverviewWidget.php`
- `app/Filament/Widgets/EncounterStatsWidget.php`
- `tests/Feature/Filament/DashboardTest.php`
- `tests/Feature/Filament/AdminNavigationStructureTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/DashboardTest.php tests/Feature/Filament/AdminNavigationStructureTest.php
```

### Checkpoint 1

- Core resource, actions, navigation, and dashboard display Consultation.
- Focused Phase 1 tests pass.
- Encounter classes, routes, filters, and persisted values are unchanged.

## Phase 2: Related Workflow Surfaces

### Task 4: Rename Appointment integration wording

Update staff-facing check-in, start, and view copy on Appointment pages and
tables. Keep `viewEncounter`, Encounter relations, redirects, and route names
unchanged.

**Files likely touched:**

- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- `app/Filament/Resources/Appointments/Tables/AppointmentsTable.php`
- `tests/Feature/Filament/AppointmentResourceTest.php`
- `tests/Feature/Filament/CheckInActionTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Filament/CheckInActionTest.php
```

### Task 5: Rename Patient Record relationship wording

Expose the existing `encounters` relationship as **Consultations** and use
Consultation labels for related prescription source fields.

**Files likely touched:**

- `app/Filament/Resources/Patients/RelationManagers/EncountersRelationManager.php`
- `app/Filament/Resources/Patients/RelationManagers/PrescriptionsRelationManager.php`
- `tests/Feature/Filament/PatientLinkedRecordsTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/PatientLinkedRecordsTest.php
```

### Task 6: Rename Prescription presentation wording

Update Prescription list/view labels and the voided-source warning. Keep the
Encounter relationship, create-route parameter, and URLs unchanged.

**Files likely touched:**

- `app/Filament/Resources/Prescriptions/Pages/ViewPrescription.php`
- `app/Filament/Resources/Prescriptions/Tables/PrescriptionsTable.php`
- `tests/Feature/Filament/PrescriptionResourceTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/PrescriptionResourceTest.php
```

### Task 7: Rename Billing source presentation

Update Billing Record source labels, table columns, colors, and filters to say
Consultation while retaining the `encounter` filter value and Encounter source
enum.

**Files likely touched:**

- `app/Models/BillingRecord.php`
- `app/Models/BillingRecordItem.php`
- `app/Filament/Resources/BillingRecords/Tables/BillingRecordsTable.php`
- `tests/Feature/BillingRecords/BillingRecordRelationshipsTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/BillingRecords/BillingRecordRelationshipsTest.php
```

### Task 8: Rename printed clinical-record wording

Update the document title, details heading, and footer to Consultation wording
without changing route bindings, record data, or audit behavior.

**Files likely touched:**

- `resources/views/filament/encounters/print.blade.php`
- `tests/Feature/Encounters/EncounterPrintTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Encounters/EncounterPrintTest.php tests/Feature/Encounters/EncounterPrintAuditTest.php
```

### Checkpoint 2

- Appointment, Patient, Prescription, Billing, and print surfaces display
  Consultation consistently.
- Focused Phase 2 tests and print audit tests pass.
- No route, relationship, source enum, or stored identifier changed.

## Phase 3: User-Readable Validation Messages

### Task 9: Rename start and assignment validation messages

Update only human-readable messages; preserve validation keys, actions, audit
events, authorization, and state logic.

**Files likely touched:**

- `app/Actions/Encounters/StartEncounter.php`
- `app/Actions/Encounters/AssignEncounterOptometrist.php`
- `tests/Feature/Encounters/StartEncounterTest.php`
- `tests/Feature/Encounters/AssignEncounterOptometristTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Encounters/StartEncounterTest.php tests/Feature/Encounters/AssignEncounterOptometristTest.php
```

### Task 10: Rename draft and addendum validation messages

Use Consultation wording for displayed draft/additional-note errors while
retaining Encounter action and model names.

**Files likely touched:**

- `app/Actions/Encounters/SaveEncounterDraft.php`
- `app/Actions/Encounters/CreateEncounterAddendum.php`
- `tests/Feature/Encounters/SaveEncounterDraftTest.php`
- `tests/Feature/Encounters/CreateEncounterCorrectionTest.php`
- `tests/Feature/Encounters/CreateEncounterSupplementTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Encounters/SaveEncounterDraftTest.php tests/Feature/Encounters/CreateEncounterCorrectionTest.php tests/Feature/Encounters/CreateEncounterSupplementTest.php
```

### Task 11: Rename completion validation messages

Update completion error text to Consultation wording without changing the
completion transaction or required-field contract.

**Files likely touched:**

- `app/Actions/Encounters/CompleteEncounter.php`
- `tests/Feature/Encounters/CompleteEncounterTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Encounters/CompleteEncounterTest.php
```

### Task 12: Rename transfer and void validation messages

Update transfer/void error text while preserving actor rules, status rules,
audit values, and state transitions.

**Files likely touched:**

- `app/Actions/Encounters/TransferEncounter.php`
- `app/Actions/Encounters/VoidEncounter.php`
- `tests/Feature/Encounters/TransferEncounterTest.php`
- `tests/Feature/Encounters/VoidEncounterTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Encounters/TransferEncounterTest.php tests/Feature/Encounters/VoidEncounterTest.php
```

### Task 13: Rename cross-domain clinical-source messages

Update user-readable Prescription and Quotation validation messages to refer to
Consultations while preserving the `encounter` validation key and source IDs.

**Files likely touched:**

- `app/Actions/Prescriptions/FinalizePrescription.php`
- `app/Actions/Quotations/CreateQuotation.php`
- `tests/Feature/Encounters/PrescriptionLifecycleTest.php`
- `tests/Feature/Quotations/CreateQuotationTest.php`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Encounters/PrescriptionLifecycleTest.php tests/Feature/Quotations/CreateQuotationTest.php
```

### Checkpoint 3

- Every user-readable validation message in the approved scope uses
  Consultation.
- Phase 3 tests pass with unchanged validation keys and domain behavior.
- Audit events and state-machine tests remain unchanged and passing.

## Phase 4: Regression Guard and Reconciliation

### Task 14: Add the targeted terminology guard and reconcile context

Add a focused Pest test that checks the explicit presentation boundary without
flagging legitimate internal Encounter symbols. Record the UI/backend naming
decision once in `BACKEND_CONTEXT.md`; do not rewrite technical Encounter
documentation.

**Files likely touched:**

- `tests/Feature/Filament/ConsultationTerminologyTest.php`
- `docs/BACKEND_CONTEXT.md`

**Verification:**

```text
vendor/bin/sail artisan test --compact tests/Feature/Filament/ConsultationTerminologyTest.php
```

### Task 15: Run final focused verification and formatting

Run the combined affected test set, scan presentation files for residual
visible Encounter/Consulation literals, format dirty PHP files, and re-run the
focused suite if Pint changes code. Run a browser check only if a Filament label
is derived unexpectedly and cannot be proven through component tests.

**Files likely touched:** None beyond formatter changes to already modified PHP
files.

**Verification:**

```text
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail artisan test --compact tests/Feature/Filament/ConsultationTerminologyTest.php tests/Feature/Filament/EncounterResourceTest.php tests/Feature/Filament/AppointmentResourceTest.php tests/Feature/Filament/DashboardTest.php tests/Feature/Filament/PatientLinkedRecordsTest.php tests/Feature/Filament/PrescriptionResourceTest.php tests/Feature/Encounters/EncounterPrintTest.php tests/Feature/BillingRecords/BillingRecordRelationshipsTest.php tests/Feature/Encounters/StartEncounterTest.php tests/Feature/Encounters/AssignEncounterOptometristTest.php tests/Feature/Encounters/SaveEncounterDraftTest.php tests/Feature/Encounters/CreateEncounterCorrectionTest.php tests/Feature/Encounters/CreateEncounterSupplementTest.php tests/Feature/Encounters/CompleteEncounterTest.php tests/Feature/Encounters/TransferEncounterTest.php tests/Feature/Encounters/VoidEncounterTest.php tests/Feature/Encounters/PrescriptionLifecycleTest.php tests/Feature/Quotations/CreateQuotationTest.php
```

### Checkpoint 4: Complete

- All specification success criteria are satisfied.
- Focused tests pass after Pint formatting.
- Presentation scan finds neither residual user-facing Encounter wording nor
  the misspelling Consulation.
- Git diff contains no schema, route, API-key, audit-value, dependency, or
  unrelated changes.
- Native Android UI wording remains a separately identified follow-up.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Global replacement renames internal contracts | High | Make explicit label/message edits only; review the final diff for identifiers and routes. |
| Filament auto-derives a label from Encounter class/relationship names | Medium | Set explicit resource and relation-manager presentation labels; assert rendered labels. |
| Visible wording exists outside the Encounter resource | Medium | Use the inventoried cross-workflow slices and a final explicit presentation scan. |
| A terminology guard flags legitimate internal identifiers | Medium | Restrict it to explicit UI files and string/presentation assertions rather than the whole repository. |
| Changed validation text breaks exact-message tests | Low | Update the affected expectation test-first while preserving validation keys and behavior. |
| Android continues displaying Encounter | Medium | Document that native Android source is outside this repository; coordinate the same label change there separately. |
| Unrelated dirty work is overwritten | High | Preserve the existing `config/ar.php` modification and inspect scoped diffs throughout. |

## Open Questions

None. Internal or machine-readable renaming remains outside the approved scope.

## Planning Note

The planning skill references `.agents/references/definition-of-done.md`, but
that file is not present in this repository. The explicit AGENTS.md gates are
therefore authoritative: use Sail, search version-specific documentation before
code changes, add/update Pest coverage, run focused tests, and run Pint on dirty
PHP files.
