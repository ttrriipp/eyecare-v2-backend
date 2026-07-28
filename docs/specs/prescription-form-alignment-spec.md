# Spec: Clinic Prescription Form Alignment

## Status

Draft for project-owner review on 2026-07-28. This is Phase 1 of the
spec-driven workflow. It does not authorize implementation.

Once approved, this specification supersedes the prescription-field definition
in `docs/specs/clinic-workflow-redesign-spec.md` where the two conflict.

## Confirmed Assumptions

1. The clinic's actual paper prescription is authoritative for the
   clinic-facing field set and layout.
2. The paper form contains only the patient's name, prescription date, the
   measurement grid, and remarks. It does not contain PD or an expiry date.
3. `O.D.` identifies the right-eye row, `O.S.` identifies the left-eye row,
   `SPH` means sphere, and the clinic has confirmed that `CX` means cylinder.
4. The first writable position in each OD/OS row has no confirmed clinical
   meaning. The system must preserve it as a neutral value associated with the
   printed `O.D.` or `O.S.` label and must not silently call it axis, acuity,
   power, or another clinical measurement.
5. The main and `ADD` portions are distinct measurement groups. Each group has
   an OD row and an OS row, and each row has the same three writable positions:
   neutral eye value, SPH, and CX.
6. Patient name is displayed from the prescription's linked patient and is not
   independently editable while creating the prescription.
7. Prescription date is system-controlled, defaults to the date of
   finalization in the clinic timezone, and is displayed as `Date`.
8. The existing encounter-scoped finalization, immutable amendment history,
   encryption, optometrist authorization, and current-version rules remain in
   force.
9. Patient/mobile access remains read-only.
10. The application is not deployed and existing prescription data is
    disposable. The implementation may replace the inaccurate development
    schema and reseed data rather than maintain a legacy contract.
11. The clinic's prescription is a small portrait form approximately one
    quarter of a whole 8.5-by-13-inch yellow sheet, not A4, A5 landscape, or a
    wallet card.
12. The printed form begins with the clinic brand, address, telephone number,
    mobile number, and normal opening days/hours.
13. The clinic's confirmed prescription-header contact numbers are telephone
    `(047) 613 6214` and mobile `(0921) 385 4260`.

## Objective

Align prescription capture, display, printing, and patient API output with the
form Padilla Optical Clinic actually uses.

The optometrist must be able to record the paper form without being required to
enter fields the clinic does not use and without the software inventing a
clinical meaning for an unlabeled position.

The clinic-facing prescription must use this structure:

```text
Name: [linked patient name]
Date: [finalization date]

        Value             SPH              CX
O.D.    [________]        [________]       [________]
O.S.    [________]        [________]       [________]

ADD
O.D.    [________]        [________]       [________]
O.S.    [________]        [________]       [________]

Remarks:
[_________________________________________________]
```

`Value` in this specification is an internal neutral description only. The
clinic UI and print layout should visually follow the paper form and must not
introduce an unconfirmed clinical label above that position.

## Functional Requirements

### Prescription identity

- A prescription belongs to exactly one patient and one encounter.
- The patient name shown on screen and in print is derived from the linked
  patient.
- The date is derived from prescription finalization in `Asia/Manila`.
- The author is the authenticated optometrist who finalizes the prescription.
- Linkage, author, and finalization metadata remain system-controlled and are
  not additional fields on the printed clinic form.

### Main measurement group

The main group contains:

- OD neutral value;
- OD sphere;
- OD cylinder;
- OS neutral value;
- OS sphere;
- OS cylinder.

### ADD measurement group

The `ADD` group repeats the same shape:

- ADD OD neutral value;
- ADD OD sphere;
- ADD OD cylinder;
- ADD OS neutral value;
- ADD OS sphere;
- ADD OS cylinder.

The ADD group is optional as a whole. When unused, its fields may remain blank.
The implementation plan must define validation that permits a clinically useful
partial row without forcing fabricated zero values.

### Remarks

- The user-facing label is `Remarks`, not `Notes`.
- Remarks are optional, multiline, and encrypted at rest.
- Amendments copy the previous remarks and all measurement fields before the
  optometrist makes an explicit correction.

### Fields excluded from the target prescription

The clinic-facing form, printout, and patient API must not include:

- PD;
- expiry date;
- expiry reminders;
- a separately labelled axis field;
- prism or base fields;
- the current scalar `od_add` and `os_add` representation.

Existing axis values must not be repurposed as the neutral fields without clinic
confirmation. Because current data is disposable, the implementation plan may
remove obsolete columns and dependent expiry behavior rather than migrate
ambiguous values.

### UI and print behavior

- Prescription creation remains available only from an in-progress encounter.
- The form presents the main measurement grid first, followed by the clearly
  separated `ADD` grid, followed by Remarks.
- OD and OS rows remain visually aligned so an optometrist can compare both
  eyes without moving between separate cards.
- The read-only view and amendment form preserve the same field order.
- The printed prescription uses a compact portrait custom page. The
  implementation baseline is 4.25 by 6.5 inches, subject to one physical
  100%-scale validation with the clinic's printer and paper.
- The print header contains the clinic logo/brand and name, address, telephone
  number, mobile number, and normal opening days/hours.
- Clinic identity and contact values come from one authoritative application
  configuration. Normal weekly opening days/hours come from the existing
  `clinic_hours` records; date-specific closures and early-closing exceptions
  are not printed in the permanent header.
- Superseded prescription versions remain non-printable.

### Patient API

- Prescription endpoints remain read-only, authenticated, and scoped to the
  linked patient.
- The response exposes explicit main and ADD groups so Android does not have to
  infer meaning from legacy flat fields.
- The neutral values use neutral contract names and documentation.
- PD and expiry fields are removed from the target API contract.
- Version linkage and `is_current` remain available for history integrity.

Target measurement fragment:

```json
{
  "measurements": {
    "main": {
      "od": {
        "value": null,
        "sphere": "-2.00",
        "cylinder": "-0.50"
      },
      "os": {
        "value": null,
        "sphere": "-1.75",
        "cylinder": "-0.25"
      }
    },
    "add": {
      "od": {
        "value": null,
        "sphere": null,
        "cylinder": null
      },
      "os": {
        "value": null,
        "sphere": null,
        "cylinder": null
      }
    }
  },
  "remarks": null
}
```

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5 / Livewire 4
- Laravel Sanctum 4
- Pest 4 / PHPUnit 12
- Laravel Sail
- Existing PDF service and Blade print templates

No new dependency is expected.

## Commands

```bash
# Start the development environment
vendor/bin/sail up -d

# Inspect relevant routes
vendor/bin/sail artisan route:list --path=prescriptions --except-vendor

# Run focused prescription tests
vendor/bin/sail artisan test --compact tests/Feature/Encounters/PrescriptionLifecycleTest.php
vendor/bin/sail artisan test --compact tests/Feature/Filament/PrescriptionResourceTest.php
vendor/bin/sail artisan test --compact tests/Feature/PrintingTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/WorkflowReadsTest.php

# Format changed PHP files
vendor/bin/sail bin pint --dirty --format agent

# Run the full regression suite before commit
vendor/bin/sail artisan test --compact
```

## Project Structure

```text
app/Models/Prescription.php
    Encrypted prescription data and version relationships

app/Actions/Prescriptions/
    Finalization and amendment integrity rules

app/Filament/Resources/Prescriptions/
    Optometrist create, view, amend, and list workflows

app/Http/Resources/PrescriptionResource.php
    Patient-safe read-only API representation

database/migrations/
    Canonical prescription schema

database/factories/ and database/seeders/
    Replacement development prescription data

resources/views/pdf/
    Compact portrait clinic prescription print template

config/clinic.php
    Authoritative clinic brand, address, telephone, and mobile details

tests/Feature/
    Lifecycle, authorization, Filament, API, encryption, and print coverage

docs/
    Living backend and Android API contracts
```

## Code Style

Use explicit, descriptive names and preserve neutral semantics for the
unconfirmed positions:

```php
#[Fillable([
    'main_od_value',
    'main_od_sphere',
    'main_od_cylinder',
    'add_od_value',
    'add_od_sphere',
    'add_od_cylinder',
])]
class Prescription extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'main_od_value' => 'encrypted',
            'main_od_sphere' => 'encrypted',
            'main_od_cylinder' => 'encrypted',
            'add_od_value' => 'encrypted',
            'add_od_sphere' => 'encrypted',
            'add_od_cylinder' => 'encrypted',
        ];
    }
}
```

The final physical column names are a planning decision, but they must remain
explicit per group and eye. Do not store the clinical grid as opaque JSON merely
to avoid defining a stable contract.

Follow existing Laravel, Filament, PHP, and Pest conventions. Use `CX` in the
clinic-facing form and `cylinder` in code and API names.

## Testing Strategy

Use Pest feature tests with factories and Livewire/Filament helpers.

Required coverage:

1. An optometrist can finalize the main group and optional ADD group from an
   in-progress encounter.
2. A non-optometrist cannot author or amend prescription values.
3. Patient, encounter, date, and author linkage are derived server-side.
4. Neutral, sphere, cylinder, and remarks values are encrypted at rest.
5. Blank ADD rows do not receive fabricated zero values.
6. An amendment copies every measurement and remarks field, requires a reason,
   creates one linear successor, and does not mutate the prior version.
7. The read-only view and print show all supplied values in the approved order.
8. PD, expiry, axis, prism, and base labels are absent from clinic-facing
   output.
9. Superseded versions cannot be printed and are excluded from the patient list
   endpoint.
10. Patient API resources expose the approved grouped response and cannot leak
    another patient's prescription.
11. Removed expiry behavior no longer schedules notifications or exposes stale
    fields.
12. Replacement seed data exercises main-only and main-plus-ADD prescriptions.

Run focused tests during implementation and the complete suite before commit.
No manual verification may substitute for automated lifecycle, authorization,
API, and print assertions.

## Boundaries

### Always

- Keep prescription authorship restricted to optometrist-capable users.
- Derive patient, encounter, author, and date linkage server-side.
- Encrypt all clinical measurement values, remarks, and amendment reasons.
- Preserve immutable finalized versions and auditable amendment chains.
- Use the clinic's labels and order without inventing clinical terminology.
- Update tests, seed data, `docs/BACKEND_CONTEXT.md`, and
  `docs/API_CONTRACT.md` when implementation is complete.
- Run focused tests, Pint for changed PHP files, and the full regression suite.

### Ask first

- Assigning a clinical meaning to the neutral OD/OS value positions.
- Adding any field not present on the clinic form.
- Adding a package or changing external dependencies.
- Changing encounter, quotation, job-order, or invoice lifecycle rules.
- Starting implementation before this specification and its technical plan are
  approved.

### Never

- Treat current axis values as the unlabeled clinic values by assumption.
- Require PD or expiry merely because legacy columns exist.
- Allow patients or receptionists to author prescriptions.
- Expose amendment reasons or internal audit metadata to patients.
- Store clinical measurements unencrypted.
- Mutate or delete a finalized prescription to make a correction.
- Remove failing tests to make the suite pass.

## Success Criteria

1. The optometrist form, read-only view, amendment screen, printout, and patient
   API all represent the same two-group OD/OS structure.
2. Each main and ADD eye row supports a neutral value, SPH, and CX in the
   clinic's order.
3. No screen, print, request, response, or validation rule requires PD or
   expiry.
4. No clinic-facing prescription output presents axis, prism, or base unless a
   future approved spec adds it.
5. Name and date appear on the printout but are derived from trusted linked
   records rather than independently entered.
6. Remarks use the clinic's wording and remain encrypted.
7. Existing encounter-scoped finalization, role authorization, version
   integrity, and current-only patient listing continue to pass.
8. API documentation gives Android an exact, patient-safe response contract.
9. Automated tests prove field capture, encryption, authorization, amendment
   copying, print ordering, API output, and removal of expiry behavior.
10. The full test suite passes after obsolete development data is replaced.
11. The compact portrait print begins with the clinic brand, configured
    address, telephone, mobile, and current normal weekly opening
    days/hours.

## Open Questions

1. What is the clinical meaning of the first writable position in each OD/OS
   row? Until the clinic answers, it remains a neutral `value` and must retain
   the paper form's unlabeled presentation.
2. Does the 4.25-by-6.5-inch portrait baseline need minor adjustment for the
   clinic's actual paper cutting and printer margins? This is resolved by a
   100%-scale physical print, not by changing the form back to A4 or A5.
3. Does the clinic want every main SPH field required, or should all measurement
   positions permit blank values when the optometrist determines they are not
   applicable? The recommended default is nullable fields with a finalization
   rule requiring at least one main measurement or a remark.

## Phase 2: Technical Implementation Plan

### Status

Draft for project-owner review on 2026-07-28. Phase 1 was approved on
2026-07-28. Implementation and task breakdown remain unauthorized until this
plan is approved.

### Approved planning defaults

Unless the clinic later provides a different answer:

1. The unknown OD/OS positions use neutral internal `value` names and remain
   visually unlabeled on the clinic form and print.
2. All twelve measurement positions are nullable. Finalization requires at
   least one main-group measurement or nonblank remarks.
3. The single supported print format starts at 4.25 by 6.5 inches in portrait,
   matching approximately one quarter of a whole 8.5-by-13-inch yellow sheet.
   The final dimensions may be adjusted only from a physical 100%-scale print.
4. The separate wallet-card print is retired because it is not part of the
   clinic's described filing workflow.
5. The grouped patient API replaces the inaccurate flat development contract;
   no compatibility layer is needed before deployment.
6. `prescribed_at` remains the trusted finalization date and is serialized as
   `date`. The database column does not need to be renamed.
7. The encrypted `notes` column is replaced by encrypted `remarks` so the data
   model, form, print, and API use the same clinic terminology.

### Target data model

Retain the current prescription identity and integrity columns:

```text
id
patient_id
encounter_id
appointment_id
previous_prescription_id
amendment_reason
created_by
prescribed_at
remarks
created_at
updated_at
deleted_at
```

Replace the clinical measurement columns with:

```text
main_od_value
main_od_sphere
main_od_cylinder
main_os_value
main_os_sphere
main_os_cylinder

add_od_value
add_od_sphere
add_od_cylinder
add_os_value
add_os_sphere
add_os_cylinder
```

All twelve measurement columns, `remarks`, and `amendment_reason` are nullable
encrypted text. The finalization action supplies the cross-field rule that at
least one main value or remarks must be present.

Remove:

```text
od_sphere
od_cylinder
od_axis
od_add
os_sphere
os_cylinder
os_axis
os_add
pd
expires_at
last_expiry_notified_at
notes
```

This is a development-only canonical schema replacement. Update the canonical
prescription migration, remove the obsolete prescription-expiry migration and
expiry index, then rebuild with `migrate:fresh --seed`. Do not attempt to map
axis, scalar ADD, PD, expiry, or notes values from disposable seed data.

Frame-reservation expiry is a separate operational concept and remains
unchanged.

### Component map and dependencies

```text
Canonical schema
    -> Prescription model/factory/seeder
        -> finalization and amendment copying
            -> Filament form/view/tables/health-record workspace
            -> patient API resource/contract
            -> compact portrait print service/template and clinic header
                -> focused and full regression verification
                    -> living documentation

Expiry column removal
    -> remove prescription expiry command
    -> remove only its scheduler entry
    -> preserve frame-reservation expiry behavior
```

### Implementation sequence

#### 1. Establish the schema and model contract

- Update the canonical prescriptions migration to the twelve approved
  measurement columns and `remarks`.
- Remove prescription-only expiry schema, index, and notification migration
  artifacts.
- Update `Prescription` fillable fields and encrypted casts.
- Keep all identity, encounter linkage, soft deletion, and version-chain
  relationships intact.
- Update canonical-schema tests first so the intended physical schema is
  executable and explicit.

Checkpoint:

- `migrate:fresh --seed` succeeds.
- Canonical schema tests prove the exact included and excluded columns.
- Raw database values for all clinical fields differ from plaintext.

#### 2. Align factory and seed data

- Replace current axis, scalar ADD, PD, and expiry factory output.
- Provide valid states for main-only and main-plus-ADD prescriptions.
- Update `ClinicWorkflowSeeder` to demonstrate the clinic form faithfully.
- Ensure seeded prescriptions still belong to coherent patients, encounters,
  appointments, and optometrists.

Checkpoint:

- Factory-created prescriptions satisfy the new finalization shape.
- Seeded UI records include both an unused ADD group and a populated ADD group.

#### 3. Harden finalization and amendment behavior

- Restrict accepted creation data to the twelve fields and `remarks`.
- Add cross-field validation requiring at least one main measurement or
  remarks.
- Do not coerce blank inputs to zero.
- Copy every measurement and remarks field when starting an amendment.
- Retain server-derived patient, encounter, appointment, author, and
  `prescribed_at`.
- Retain locking, one-original-per-encounter, linear successor, authorization,
  audit, and encrypted amendment-reason behavior.

Checkpoint:

- Lifecycle tests pass for initial finalization, blank rejection, amendments,
  concurrent/duplicate protection, authorization, and encryption.

#### 4. Rebuild the Filament presentation around the paper grid

- Replace separate Right Eye and Left Eye cards with two aligned sections:
  `Prescription` and `ADD`.
- Render each section as OD and OS rows with the neutral position, SPH, and CX
  in the clinic's order.
- Do not show a fabricated header for the neutral position.
- Rename Notes to Remarks.
- Remove PD, expiry, axis, and scalar ADD controls and expiry warnings.
- Update the prior-version comparison to show both complete groups.
- Update prescription list and patient relation columns to useful retained
  information such as patient, date, author, encounter, and version status.
- Update the appointment Health Record workspace to use the same read-only
  grid.

Checkpoint:

- Filament feature tests prove the right fields and actions are visible,
  excluded fields are absent, immutable views remain disabled, and amendments
  are prefilled completely.
- A manual browser check confirms aligned rows at common desktop widths after
  automated tests pass.

#### 5. Replace the patient API representation

- Return the grouped `measurements.main` and `measurements.add` object approved
  in Phase 1.
- Rename `notes` to `remarks`.
- Remove PD, expiry, axis, and legacy flat measurement keys.
- Preserve prescription ID, appointment linkage, previous-version linkage,
  current-version flag, and date.
- Keep list pagination, patient ownership scoping, and historical show behavior
  unchanged.

Checkpoint:

- API tests assert the complete exact JSON shape, nullable values, pagination,
  current-only list behavior, ownership isolation, and absence of every retired
  key.

#### 6. Consolidate printing to the clinic form

- Change `PdfService::prescriptionPrintout()` to an initial custom
  4.25-by-6.5-inch portrait page.
- Add one clinic configuration source for the logo/brand, clinic name, address,
  telephone, and mobile values. Do not duplicate these literals across Blade
  templates.
- Configure the known prescription-form address as `#2 Angelita Aquino Bldg.,
  J.P. Rizal St., Poblacion, Balanga City, Bataan`, telephone as
  `(047) 613 6214`, and mobile as `(0921) 385 4260`.
- Format the existing enabled `clinic_hours` weekly records into concise normal
  opening-day/hour text for the header. Do not include temporary closures or
  date-specific early-closing exceptions.
- Replace the current A4-styled table with a compact header containing the
  clinic brand, address, telephone, mobile, and opening days/hours, followed by
  Name, Date, main OD/OS grid, ADD OD/OS grid, Remarks, and signature/license
  area.
- Remove the wallet-card method, Blade template, route, and Filament action.
- Retain authentication and current-version print authorization.
- Keep the display compact and black-and-white printer friendly even when
  clinic branding uses blue accents on screen.

Checkpoint:

- Print tests assert the custom portrait paper configuration, every required
  clinic-header element, weekly-hours formatting, correct clinical field order,
  patient name/date derivation, retained authorization, and absence of PD,
  expiry, axis, prism, base, and wallet-card output.
- Print one physical sample at 100% scale and confirm readability, printer
  margins, paper cut, and folder fit before declaring the dimensions final.

#### 7. Retire prescription expiry behavior

- Remove `CheckPrescriptionExpiryCommand`.
- Remove only `Schedule::command('prescriptions:check-expiry')`.
- Remove prescription expiry warnings and tests.
- Preserve `ExpirePreparedReservations`, reservation `expires_at`, and
  reservation scheduling behavior.
- Search the repository for every retired prescription field after changes.

Checkpoint:

- The prescription expiry command is no longer registered or scheduled.
- Reservation expiry tests still pass.
- Repository search finds no runtime prescription dependency on the retired
  fields.

#### 8. Reconcile documentation and run final verification

- Update the prescription section of
  `docs/specs/clinic-workflow-redesign-spec.md`.
- Update `docs/BACKEND_CONTEXT.md` with the shipped schema, UI, print, and
  lifecycle behavior.
- Replace the prescription examples and field notes in
  `docs/API_CONTRACT.md`.
- Record the neutral field semantics as awaiting clinic terminology, without
  presenting it as a backend uncertainty to Android.
- Run Pint on changed PHP files, all focused prescription suites, reservation
  regression tests, and the complete test suite.

Checkpoint:

- Documentation matches tested runtime output.
- `git diff --check` is clean.
- The full suite passes before commit.

### Parallel and sequential work

The schema/model contract must be completed first because every other component
depends on its names and shape. Finalization logic must precede UI and API
assertions.

After the model and action checkpoints pass, these workstreams are logically
independent and may be implemented separately:

- Filament and Health Record presentation;
- patient API resource and contract tests;
- PDF layout and print tests;
- prescription-expiry retirement.

They must converge before seed reconciliation, documentation, and the full
regression checkpoint. Given the small number of tightly related files and the
existing dirty prescription worktree, sequential incremental implementation is
safer than simultaneous edits to shared model and tests.

### Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Neutral field is later identified as a known measurement | Medium | Use isolated `*_value` columns and contract keys so a later approved rename is mechanical |
| Removing expiry accidentally affects frame reservations | High | Scope searches and tests by model; retain reservation command, column, resource, and lifecycle tests |
| Amendment copying misses one of twelve values | High | Centralize the allowed/copy field list and assert every field in one lifecycle dataset |
| UI alignment becomes difficult to scan | Medium | Use two row-oriented grids and perform one browser check after Livewire tests |
| Android has started using the old flat response | Medium | Reconcile `API_CONTRACT.md` before Android integration; backend is not deployed, so do not carry both contracts |
| Compact print clips on a clinic printer | Medium | Start at 4.25 by 6.5 inches portrait and require one 100%-scale physical validation |
| Header contact details become duplicated or stale | Medium | Keep clinic identity/contact in one configuration and derive normal weekly hours from `clinic_hours` |
| Existing uncommitted lifecycle changes are overwritten | High | Preserve the dirty worktree, inspect diffs before each edit, and implement on top of the approved lifecycle behavior |
| Nullable measurements permit an empty clinical record | Medium | Enforce at least one main measurement or remarks in the domain action, not only in Filament |

### Phase 2 verification gate

Before Phase 3 task breakdown:

- [x] Major components and dependencies are identified.
- [x] Implementation order and checkpoints are defined.
- [x] Risks and mitigations are documented.
- [x] Parallelizable work is separated from sequential prerequisites.
- [x] The project owner approved this technical plan on 2026-07-28.

## Phase 3: Implementation Tasks

### Status

Draft for project-owner review on 2026-07-28. Phases 1 and 2 are approved.
These tasks do not authorize implementation until the Phase 3 verification gate
is approved.

### Implementation protocol

- Preserve all existing uncommitted prescription-lifecycle work. Do not reset,
  revert, or overwrite it.
- Before Task 1, inspect the current diff and run the existing focused
  prescription tests to establish the working baseline.
- Implement tasks in order using test-driven development. Add or update the
  focused test before completing each behavior change.
- Search version-specific Laravel and Filament documentation before changing
  framework code.
- Run every command through Laravel Sail.
- Mark a task `[x]` only after all its acceptance and verification checkboxes
  pass.
- Stop at a checkpoint if its verification fails; do not continue into
  dependent work.

### Task 1: Replace the canonical prescription schema

**Description:** Establish the approved physical database contract and remove
obsolete prescription-only expiry storage without changing reservation expiry.

**Acceptance criteria:**

- [ ] The canonical `prescriptions` table contains the twelve nullable encrypted
      measurement storage columns and nullable `remarks`.
- [ ] Axis, scalar ADD, PD, prescription expiry, expiry-notification, and
      `notes` columns are absent.
- [ ] Identity, encounter linkage, author, version chain, timestamps, and soft
      deletion remain unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan migrate:fresh --no-interaction`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Database/CanonicalSchemaTest.php`

**Dependencies:** None.

**Files likely touched:**

- `database/migrations/2026_06_09_063305_create_prescriptions_table.php`
- `database/migrations/2026_06_29_214656_add_performance_indexes.php`
- `database/migrations/2026_06_29_215611_add_last_expiry_notified_at_to_prescriptions.php` (remove)
- `app/Models/Prescription.php`
- `tests/Feature/Database/CanonicalSchemaTest.php`

**Estimated scope:** Medium, 5 files.

### Task 2: Align finalization and amendment input

**Description:** Make encounter-scoped finalization and amendments accept,
validate, encrypt, and copy the approved prescription shape.

**Acceptance criteria:**

- [ ] Creation accepts only the twelve measurements and `remarks`, with no
      blank-to-zero coercion.
- [ ] At least one main measurement or nonblank remark is required.
- [ ] Amendments prefill and copy all approved fields while preserving the
      existing immutable linear version rules.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Encounters/PrescriptionLifecycleTest.php`
- [ ] Raw-database assertions prove clinical values and remarks are encrypted.

**Dependencies:** Task 1.

**Files likely touched:**

- `database/factories/PrescriptionFactory.php`
- `app/Actions/Prescriptions/FinalizePrescription.php`
- `app/Filament/Resources/Prescriptions/Pages/CreatePrescription.php`
- `app/Filament/Resources/Prescriptions/Schemas/PrescriptionForm.php`
- `tests/Feature/Encounters/PrescriptionLifecycleTest.php`

**Estimated scope:** Medium, 5 files.

### Checkpoint A: Data and lifecycle foundation

- [ ] Tasks 1 and 2 are checked complete.
- [ ] `vendor/bin/sail artisan migrate:fresh --no-interaction` succeeds.
- [ ] Canonical schema and prescription lifecycle suites pass together.
- [ ] Existing optometrist authorization, encounter linkage, audit, locking,
      and version integrity have not regressed.

### Task 3: Align prescription pages and lists

**Description:** Present the paper-aligned main and ADD grids consistently on
the create, read-only, amendment, list, and patient-history surfaces.

**Acceptance criteria:**

- [ ] Main and ADD sections show aligned OD/OS rows in neutral-value, SPH, CX
      order and use `Remarks`.
- [ ] Prior-version comparison includes every approved field and no retired
      field or expiry warning.
- [ ] Lists use retained operational columns rather than PD or expiry.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/PrescriptionResourceTest.php`
- [ ] Filament tests assert approved fields exist, retired fields are absent,
      finalized fields are disabled, and amendments are fully prefilled.

**Dependencies:** Task 2.

**Files likely touched:**

- `app/Filament/Resources/Prescriptions/Pages/ViewPrescription.php`
- `app/Filament/Resources/Prescriptions/Tables/PrescriptionsTable.php`
- `app/Filament/Resources/Patients/RelationManagers/PrescriptionsRelationManager.php`
- `tests/Feature/Filament/PrescriptionResourceTest.php`

**Estimated scope:** Medium, 4 files.

### Task 4: Align the combined Health Record workspace

**Description:** Show the finalized prescription within the appointment Health
Record using the same two-grid structure as the prescription resource.

**Acceptance criteria:**

- [ ] Health Record displays all supplied main and ADD values in clinic order.
- [ ] It labels Remarks correctly and omits PD, expiry, axis, and scalar ADD.
- [ ] Existing patient, appointment, intake, encounter, and access behavior is
      unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/EndToEnd/ClinicWorkflowTest.php`
- [ ] The workflow test asserts both a populated value and an omitted retired
      label on the rendered Health Record.

**Dependencies:** Task 2.

**Files likely touched:**

- `app/Filament/Resources/Appointments/Pages/HealthRecord.php`
- `resources/views/filament/resources/appointments/pages/health-record.blade.php`
- `tests/Feature/EndToEnd/ClinicWorkflowTest.php`

**Estimated scope:** Medium, 3 files.

### Checkpoint B: Optometrist web workflow

- [ ] Tasks 3 and 4 are checked complete.
- [ ] Filament prescription and end-to-end clinic workflow tests pass together.
- [ ] A browser check confirms both measurement grids remain readable and
      aligned at the panel's common desktop width.
- [ ] Creation still starts only from an eligible in-progress encounter.

### Task 5: Replace the patient prescription API shape

**Description:** Ship the grouped, read-only Android contract and remove every
retired flat clinical key.

**Acceptance criteria:**

- [ ] Responses contain `measurements.main`, `measurements.add`, and `remarks`
      with documented nullable values.
- [ ] IDs needed for linkage, `date`, `previous_prescription_id`, and
      `is_current` remain.
- [ ] Ownership isolation, pagination, current-only list behavior, and
      historical show behavior remain unchanged.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/WorkflowReadsTest.php`
- [ ] Exact-JSON assertions prove retired keys cannot leak.

**Dependencies:** Task 2.

**Files likely touched:**

- `app/Http/Resources/PrescriptionResource.php`
- `tests/Feature/Api/V1/WorkflowReadsTest.php`

**Estimated scope:** Small, 2 files.

### Task 6: Build the compact portrait prescription print

**Description:** Replace the current A4-oriented output with the clinic's
compact portrait form and authoritative header.

**Acceptance criteria:**

- [ ] Paper is configured initially as 4.25 by 6.5 inches in portrait.
- [ ] Header contains clinic brand/name, confirmed address, telephone
      `(047) 613 6214`, mobile `(0921) 385 4260`, and formatted normal weekly
      clinic hours.
- [ ] Name, Date, main grid, ADD grid, Remarks, and signature/license area fit
      in the approved order without retired fields.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/PrintingTest.php`
- [ ] Tests assert paper dimensions, clinic-header values, weekly-hours
      formatting, field order, and print authorization.
- [ ] A generated sample is ready for the later physical 100%-scale check.

**Dependencies:** Tasks 2 and 5.

**Files likely touched:**

- `config/clinic.php`
- `app/Services/ClinicHoursFormatter.php`
- `app/Services/PdfService.php`
- `resources/views/pdf/prescription.blade.php`
- `tests/Feature/PrintingTest.php`

**Estimated scope:** Medium, 5 files.

### Task 7: Retire the wallet-card print

**Description:** Remove the extra print format so the current prescription has
one unambiguous clinic-approved output.

**Acceptance criteria:**

- [ ] The wallet-card Filament action, service method, route, and Blade template
      are removed.
- [ ] The compact prescription route remains authenticated and current-version
      only.
- [ ] Superseded versions remain non-printable.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/PrintingTest.php tests/Feature/Filament/PrescriptionResourceTest.php`
- [ ] Route assertions prove the card endpoint is absent and the retained print
      endpoint preserves authorization.

**Dependencies:** Task 6.

**Files likely touched:**

- `app/Filament/Resources/Prescriptions/Pages/ViewPrescription.php`
- `app/Services/PdfService.php`
- `resources/views/pdf/prescription-card.blade.php` (remove)
- `routes/web.php`
- `tests/Feature/PrintingTest.php`

**Estimated scope:** Medium, 5 files.

### Checkpoint C: Patient contract and physical output

- [ ] Tasks 5 through 7 are checked complete.
- [ ] API, printing, and Filament prescription suites pass together.
- [ ] `vendor/bin/sail artisan route:list --path=prescriptions --except-vendor`
      shows only the retained routes.
- [ ] A 100%-scale sample is generated for clinic validation. The software
      implementation may continue, but the paper dimensions remain provisional
      until the clinic confirms readable text, safe printer margins, correct
      portrait orientation, paper cut, and folder fit.

### Task 8: Retire prescription expiry scheduling

**Description:** Remove the obsolete prescription expiry command and schedule
while proving frame-reservation expiry remains intact.

**Acceptance criteria:**

- [ ] `prescriptions:check-expiry` is neither registered nor scheduled.
- [ ] No runtime prescription code references expiry or expiry-notification
      fields.
- [ ] Prepared frame reservations still expire through their existing command.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Reservations/ReservationLifecycleTest.php tests/Feature/PrescriptionExpiryRetirementTest.php`
- [ ] `vendor/bin/sail artisan schedule:list` contains no prescription-expiry
      entry.
- [ ] A scoped repository search finds no retired runtime prescription field.

**Dependencies:** Tasks 3, 5, and 7.

**Files likely touched:**

- `app/Console/Commands/CheckPrescriptionExpiryCommand.php` (remove)
- `routes/console.php`
- `tests/Feature/PrescriptionExpiryRetirementTest.php` (create)

**Estimated scope:** Medium, 3 files.

### Task 9: Replace prescription seed scenarios

**Description:** Make fresh development data demonstrate the approved main-only
and main-plus-ADD clinic workflow.

**Acceptance criteria:**

- [ ] Seed data contains coherent finalized prescriptions linked to valid
      patients, encounters, appointments, and optometrists.
- [ ] At least one seeded record leaves ADD blank and one populates it.
- [ ] No seeder supplies retired prescription fields.

**Verification:**

- [ ] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Seeders/ClinicWorkflowSeederTest.php tests/Feature/Seeders/CanonicalSeederTest.php`

**Dependencies:** Tasks 1 through 8.

**Files likely touched:**

- `database/seeders/ClinicWorkflowSeeder.php`
- `tests/Feature/Seeders/ClinicWorkflowSeederTest.php`
- `tests/Feature/Seeders/CanonicalSeederTest.php`

**Estimated scope:** Medium, 3 files.

### Task 10: Reconcile documentation and complete verification

**Description:** Make the approved workflow spec, living backend context, and
Android contract match the tested implementation, then run release-level
verification.

**Acceptance criteria:**

- [ ] The parent clinic workflow spec describes the approved prescription
      fields and compact portrait header.
- [ ] Backend context describes the final schema, expiry retirement, print
      behavior, and unchanged integrity rules.
- [ ] API contract contains the exact tested grouped JSON and no retired
      prescription keys.

**Verification:**

- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] All focused commands from Tasks 1 through 9 pass.
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `git diff --check`

**Dependencies:** Task 9.

**Files likely touched:**

- `docs/specs/clinic-workflow-redesign-spec.md`
- `docs/BACKEND_CONTEXT.md`
- `docs/API_CONTRACT.md`
- `docs/specs/prescription-form-alignment-spec.md`

**Estimated scope:** Medium, 4 files.

### Final checkpoint

- [ ] All ten tasks and Checkpoints A through C are checked complete.
- [ ] All Phase 1 success criteria are demonstrated by tests or the physical
      print check.
- [ ] The clinic has confirmed the 100%-scale physical sample, or the remaining
      paper-size validation is explicitly documented as a pre-deployment item.
- [ ] Focused suites and the full regression suite pass.
- [ ] Formatting and whitespace verification are clean.
- [ ] Documentation matches runtime behavior.
- [ ] The project owner has reviewed the completed implementation before a
      commit is created.

### Phase 3 verification gate

Before Phase 4 implementation:

- [x] Every task has explicit acceptance criteria.
- [x] Every task has focused verification commands.
- [x] Dependencies are identified and ordered.
- [x] No task is expected to touch more than five files.
- [x] Checkpoints occur between major dependency groups.
- [ ] The project owner has reviewed and approved this task breakdown.
