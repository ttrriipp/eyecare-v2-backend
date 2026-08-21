# Spec: Consultation UI Terminology

**Status:** Approved
**Approved:** 2026-08-21
**Created:** 2026-08-21
**Related domain spec:** `docs/specs/encounter-workflow-spec.md`

## Objective

Replace the clinical term **Encounter** with the more familiar term
**Consultation** everywhere it is presented to a user, without renaming the
existing backend domain, database schema, routes, or public API contract.

The primary users are clinic staff, optometrists, and administrators working in
the Filament panel. Patients using the Android application should also see
**Consultation** wherever the application presents this concept. The change is
successful when users can navigate and operate the complete clinical workflow
without seeing **Encounter** as interface copy, while existing integrations and
stored data continue to work unchanged.

This is a terminology-only change. It must not alter permissions, validation,
state transitions, clinical behavior, or record identity.

## Approved Assumptions

1. The canonical user-facing singular and plural terms are **Consultation** and
   **Consultations**.
2. The misspelling **Consulation** must not appear anywhere.
3. Internal PHP and database terminology remains Encounter-based, including
   class names, namespaces, variables, relationships, enum cases, action names,
   table names, columns, foreign keys, factories, and audit event values.
4. Existing route names and paths remain unchanged, including
   `/admin/encounters`, `encounters.print`, and any `encounter` route or query
   parameter.
5. Existing API endpoints, JSON keys, identifiers, and values remain unchanged,
   including `encounter_id`. Human-readable API messages may use
   **Consultation** when they are intended for display.
6. The stored `encounter_number` and its existing generated value remain
   unchanged. Its visible field label becomes **Consultation #** or
   **Consultation Number**.
7. Android source code is outside this repository. This backend change may
   update user-facing text emitted by the server, but native Android screen
   labels must be updated in the Android project separately.
8. The established Encounter workflow, permissions, statuses, and clinical
   rules remain authoritative and unchanged.

## Terminology Contract

| Existing user-facing wording | Replacement |
|---|---|
| Encounter | Consultation |
| Encounters | Consultations |
| Encounter # | Consultation # |
| Encounter Number | Consultation Number |
| Encounter Details | Consultation Details |
| Start Encounter | Start Consultation |
| Transfer Encounter | Transfer Consultation |
| Print Encounter | Print Consultation |
| Void Encounter | Void Consultation |
| Encounter started | Consultation started |
| Encounter transferred | Consultation transferred |
| Encounter voided | Consultation voided |
| Cannot start/transfer/void encounter | Cannot start/transfer/void consultation |
| Active Encounter / Active Encounters | Active Consultation / Active Consultations |
| My Encounters | My Consultations |
| Encounter History | Consultation History |
| Encounter Record | Consultation Record |

Grammatical capitalization must follow the surrounding interface. For example,
sentence copy uses `consultation`, while navigation labels and headings use
`Consultation`.

Clinical subterms such as **History**, **Examination**, **Assessment & Plan**,
**Review & Complete**, **Correction**, **Supplement**, and **Additional Clinical
Note** remain unchanged unless the surrounding copy currently uses Encounter.

## User-Facing Surfaces

The terminology replacement applies to all human-facing copy owned by this
repository:

- Filament navigation labels, navigation badges, resource labels, page titles,
  breadcrumbs, tabs, table headings, field labels, empty states, and helper
  text;
- Filament actions, modal headings, modal descriptions, confirmation labels,
  success notifications, and error notifications;
- dashboard statistics and consultation-specific widgets;
- Patient Record relation-manager labels and links;
- appointment, prescription, quotation, billing, and other related screens
  when they display the clinical source as Encounter;
- completed clinical-record print views, including the HTML title, section
  headings, labels, and footer;
- user-readable validation and exception messages that can be surfaced by the
  Filament panel or mobile application;
- humanized Audit Log presentation when an Encounter audit event is displayed
  as interface copy; and
- automated tests that assert any of the preceding user-visible wording.

The implementation must review copy in related resources, not only files under
`app/Filament/Resources/Encounters`, because Encounter wording is also exposed
by widgets, Patient Records, billing source descriptions, print templates, and
cross-workflow notifications.

## Explicitly Unchanged Backend Contracts

The following must not be renamed or migrated:

- `App\Models\Encounter` and all `Encounter*` PHP classes;
- `app/Actions/Encounters`, `app/Filament/Resources/Encounters`, and existing
  PHP namespaces or filenames;
- the `encounters` and `encounter_addenda` tables;
- `encounter_id`, `encounter_number`, Encounter relationships, and database
  constraints;
- `EncounterStatus`, `EncounterAddendumType`, `EncounterTransferReason`, and
  persisted enum values;
- audit event/action values such as `encounter.started`;
- route paths, route names, request parameters, and query parameters containing
  `encounter`;
- API endpoint paths, request fields, response keys, and machine-readable error
  codes; and
- internal comments, PHPDoc, test descriptions, variable names, method names,
  and technical documentation where **Encounter** identifies the backend
  domain rather than interface copy.

No database migration, compatibility alias, redirect, new dependency, or
domain-model refactor is part of this change.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5
- Livewire 4
- Pest 4 / PHPUnit 12
- Laravel Pint 1
- Laravel Sail

## Commands

All project commands run through Laravel Sail:

```text
Start:  vendor/bin/sail up -d
Test:   vendor/bin/sail artisan test --compact <affected-test-files>
Format: vendor/bin/sail bin pint --dirty --format agent
Build:  vendor/bin/sail npm run build
Dev:    vendor/bin/sail composer run dev
```

The implementation plan must identify the smallest relevant Pest test set after
the affected UI files are inventoried. A frontend build is required only if the
implementation changes Blade/CSS/JavaScript assets that are compiled by Vite;
plain PHP label changes do not require an unnecessary asset build.

## Project Structure

```text
app/Filament/Resources/Encounters/       Internal Encounter resource; visible copy becomes Consultation
app/Filament/Resources/Patients/         Patient Record consultation tab and related labels
app/Filament/Widgets/                    Consultation dashboard and statistics copy
app/Actions/                             Internal actions remain named Encounter; displayed messages may change
resources/views/filament/encounters/     Consultation print copy; path remains unchanged
tests/Feature/                           Pest coverage for user-visible terminology and unchanged behavior
docs/specs/                              This approved terminology specification
```

## Code Style

Continue using internal Encounter identifiers while setting explicit
user-facing labels:

```php
TextColumn::make('encounter_number')
    ->label('Consultation #');

Action::make('startEncounter')
    ->label('Start Consultation');
```

Do not rename the field or action key merely to match its visible label. Follow
existing Filament conventions, use explicit return types, and format modified
PHP files with Pint.

## Testing Strategy

Testing uses Pest feature tests through Laravel Sail.

1. Add or update focused assertions for the Consultation resource labels,
   navigation, breadcrumbs, actions, widgets, Patient Record tab, related
   source labels, and print output.
2. Add a terminology guard covering the intended human-facing surfaces so a
   future UI change does not reintroduce visible Encounter wording. The guard
   must use an explicit set of UI files or rendered components; it must not
   reject legitimate internal Encounter identifiers.
3. Preserve existing workflow tests proving check-in, start, transfer,
   completion, addenda, printing, billing, and authorization behavior.
4. Run the smallest affected test files first, then any directly related
   Encounter/Filament tests identified during implementation.
5. Run Pint after modifying PHP files.

No browser test is required solely for string replacement if rendered
component and print-response tests cover the changed output. Runtime browser
verification should be added if Filament derives a label unexpectedly or a
navigation/page title cannot be reliably asserted at the component level.

## Boundaries

### Always

- Use **Consultation** consistently in all user-facing copy.
- Preserve the existing Encounter backend and public contract.
- Review cross-workflow surfaces rather than performing a folder-local rename.
- Update focused tests before considering the change complete.
- Run commands through Sail and format modified PHP with Pint.

### Ask First

- Renaming an endpoint, route, JSON key, table, column, PHP class, namespace,
  action key, audit event, or stored identifier;
- changing the `encounter_number` format or prefix;
- changing clinical behavior, permissions, statuses, or workflow; or
- modifying the separate Android repository.

### Never

- Run a repository-wide Encounter-to-Consultation replacement;
- add a database migration for this terminology-only change;
- change machine-readable contracts to make implementation names match labels;
- delete or weaken Encounter workflow tests; or
- alter unrelated files or the existing uncommitted `config/ar.php` change.

## Success Criteria

- Filament navigation uses **Consultations** and no intended staff-facing
  clinical-workflow surface displays **Encounter** as interface terminology.
- All applicable actions, modals, notifications, validation messages, widgets,
  Patient Record labels, related-source labels, and print copy use
  **Consultation** consistently.
- The misspelling **Consulation** appears nowhere in user-facing copy.
- `encounter_number` is displayed with a Consultation label while stored values
  remain unchanged.
- Internal PHP names, database schema, routes, audit values, and API keys remain
  unchanged.
- Existing clinical workflow behavior and authorization remain unchanged.
- Focused terminology and workflow tests pass through Sail.
- Modified PHP files pass Laravel Pint formatting.

## Open Questions

None. Any request to rename an internal or machine-readable Encounter contract
is a scope change requiring explicit approval and a revised specification.
