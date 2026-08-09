# Spec: Practical Clinical Encounter Workflow

**Status:** Approved
**Approved:** 2026-08-09
**Source:** `docs/ideas/encounter-workflow.md`

## Objective

Refine the existing encounter workflow into a fast, trustworthy clinical record
owned by the treating optometrist. The feature must clearly record what the
optometrist reviewed, examined, concluded, and recommended without introducing
a hospital-grade EHR, device integration, or configurable clinical templates.

The primary user is the optometrist working in the Filament panel during or
immediately after a consultation. Staff and administrators may perform approved
operational actions and view records under the existing role contract, but they
must not gain clinical authorship.

The feature succeeds when scheduled patients and walk-ins follow the same
encounter lifecycle, the assigned optometrist can complete a four-step
autosaving wizard, completed records cannot be silently rewritten, and every
clinical action is attributable and authorized on the server.

## Approved Assumptions

1. Encounter authoring is a Filament web-panel workflow. No patient or mobile
   encounter-authoring API is added.
2. Scheduled patients and walk-ins use the same encounter lifecycle. A walk-in
   receives a walk-in Appointment before check-in.
3. Patient Intake is not part of the active workflow. New encounters do not
   create, submit, verify, attach, or require a `PatientIntake`.
4. The appointment's brief reason for visit may prefill the encounter's chief
   complaint at check-in.
5. The existing status values remain `planned`, `in_progress`, `completed`, and
   `cancelled`. The UI may label `planned` as **Waiting**.
6. Appointment type is contextual information displayed in the encounter; it
   does not select a different schema or wizard.
7. Prescription, referral, follow-up, and supporting-test results are optional
   encounter outcomes.
8. The autorefractor receives no dedicated fields, required checkbox, upload,
   OCR, or direct integration.
9. Completion continues to fulfill the related Appointment.
10. A completed Encounter is immutable. Later clinical clarification is stored
    as an append-only addendum.
11. The existing role specification remains authoritative: `admin` is never
    implicitly clinical, while `admin + optometrist` receives both capabilities
    under one auditable identity.
12. Detailed structured examination measurements remain deferred until the
    clinic's real paper form and workflow are validated.

## User Stories

- As staff, I can check in a scheduled patient or create/check in a walk-in so
  the patient appears in the waiting encounter queue.
- As staff, I can assign or reassign the provider before the consultation begins
  without editing clinical content.
- As the assigned optometrist, I can start my encounter, save progress between
  wizard steps, resume an interrupted draft, review the complete note, and
  complete the visit.
- As the assigned optometrist, I can issue a prescription when appropriate, but
  I can complete a valid encounter without one.
- As an administrator or current treating optometrist, I can explicitly
  transfer an in-progress encounter to another active optometrist with a reason
  category and audit trail.
- As an authorized panel user, I can view a completed encounter without being
  able to modify the signed clinical record.
- As an optometrist, I can append an attributable correction or supplementary
  note without overwriting the original encounter.
- As an authorized panel user, I can print the completed encounter and its
  addenda as one clearly attributed record.

## Recommended Workflow

```text
Scheduled appointment or walk-in appointment
    -> staff confirms identity and checks the patient in
    -> system creates one planned encounter for the appointment
    -> appointment reason may prefill chief complaint
    -> assigned optometrist starts the encounter
    -> History
    -> Examination
    -> Assessment & Plan
    -> Review & Complete
    -> encounter becomes read-only and appointment becomes fulfilled
    -> later corrections are append-only addenda
```

## State Machine

| Current state | Allowed transition | Actor | Preconditions and effects |
|---|---|---|---|
| none | `planned` | Staff, optometrist, or admin through check-in | Appointment is `scheduled`; exactly one Encounter is created and linked to it. |
| `planned` | `in_progress` | Optometrist | Appointment is `checked_in`; actor is active and either already assigned or claims an unassigned encounter as themselves. |
| `planned` | `cancelled` | Preserve existing authorized behavior | Cancellation occurs before clinical work starts. |
| `in_progress` | `completed` | Assigned optometrist | Completion contract passes; optional prescription finalizes atomically; Appointment becomes `fulfilled`. |
| `completed` | none | Nobody | Terminal and immutable; addenda are separate records. |
| `cancelled` | none | Nobody | Terminal and read-only. |

An optometrist must never select another user and start an encounter on that
user's behalf. If an unassigned encounter is started, the actor becomes its
treating optometrist. Assignment is synchronized to the related Appointment.

## Wizard Contract

### Step 1: History

- `chief_complaint` — required at completion; may be prefilled from the
  Appointment's reason for visit.
- `past_ocular_history` — optional.
- `past_surgical_history` — optional.
- `past_medical_history` — optional.
- `allergies` — optional.
- `medications` — optional.

### Step 2: Examination

- `findings` — required at completion; labeled **Examination summary/findings**.
- `supporting_test_results` — optional, device-neutral narrative for relevant
  tests and results.
- `remarks` — remove from general authoring when it duplicates assessment or
  plan; preserve existing values for historical display.

### Step 3: Assessment & Plan

- `assessment` — required at completion.
- `plan` — required at completion.
- Optional prescription draft using the existing prescription fields and
  finalization rules.
- Referral and follow-up may be recorded within `plan`; no separate referral or
  follow-up subsystem is introduced in this feature.

### Step 4: Review & Complete

- Display patient, Appointment type, treating optometrist, history, examination,
  supporting tests, assessment, plan, and optional prescription.
- Provide one explicit, confirmed **Complete Visit** action.
- Do not duplicate editable clinical fields in the review step.

Moving forward between steps saves the current draft and `last_wizard_step`.
Back navigation must not lose data. Reopening an in-progress Encounter resumes
at the last saved step. Draft saves permit incomplete clinical fields; the
completion boundary enforces the complete contract.

## Completion Contract

Completion requires all of the following:

- Encounter status is `in_progress`;
- related Appointment exists and is `checked_in`;
- actor is active and has the `optometrist` role;
- actor is the Encounter's assigned `optometrist_id`;
- `chief_complaint` is filled;
- `findings` is filled;
- `assessment` is filled;
- `plan` is filled.

On success, one database transaction must:

1. lock and re-read the Encounter and related Appointment;
2. recheck authorization, assignment, and states;
3. persist the final wizard state;
4. finalize a prescription only when draft prescription data exists and passes
   its own validation;
5. clear finalized prescription draft data;
6. set Encounter status, `completed_at`, and `completed_by`;
7. set Appointment status to `fulfilled` and stamp `fulfilled_at`; and
8. create audit entries containing identifiers and state metadata only.

If any operation fails, neither the Encounter nor Appointment may be partially
completed. Laravel's transaction retry facility should handle database
deadlocks; bespoke exception-message matching and manual sleeps are excluded.

## Provider Assignment and Transfer

### Before Start

- Staff, optometrists, and administrators may assign an active optometrist to a
  `planned` Encounter.
- Assignment updates both Encounter and related Appointment in one transaction.
- An active optometrist may start an unassigned Encounter as themselves, which
  assigns both records to that actor.
- An Encounter assigned to somebody else cannot be started until it is
  explicitly reassigned.

### After Start

An in-progress transfer uses an explicit **Transfer Encounter** action:

- initiator is the current assigned optometrist or an active administrator;
- target is a different active user with the `optometrist` role;
- a reason category is required;
- allowed reason values are `provider_unavailable`, `shift_change`,
  `patient_request`, `emergency`, and `other`;
- free-form clinical narrative is not stored in audit metadata;
- draft clinical and prescription data is preserved;
- Encounter and Appointment provider IDs change in one locked transaction;
- an `encounter.transferred` audit event records Encounter ID, Appointment ID,
  previous provider ID, new provider ID, reason category, actor ID, and time;
- only the new provider may subsequently edit or complete the Encounter.

A plain administrator may coordinate the transfer but cannot author clinical
fields or complete the Encounter. A dual-role owner may perform both operations
because their one account explicitly holds both roles.

## Addenda and Record Finality

Completed Encounter columns are not editable through forms, actions, mass
assignment, or direct Filament table controls. There is no reopen transition.

Create an append-only `encounter_addenda` record for post-completion notes:

| Column | Type and constraint |
|---|---|
| `id` | unsigned bigint primary key |
| `encounter_id` | required FK to `encounters`; deletion restricted |
| `sequence_number` | unsigned small integer; unique with `encounter_id` |
| `type` | string enum: `correction` or `supplement` |
| `reason` | required `TEXT`, encrypted cast |
| `content` | required `TEXT`, encrypted cast |
| `authored_by` | required FK to `users`; deletion restricted |
| `authored_at` | required timestamp |
| `created_at` | required timestamp |

No `updated_at`, soft-delete column, edit action, or delete action is provided.
Sequence assignment locks the parent Encounter to prevent duplicates.

- The completing optometrist may create a `correction` addendum.
- Any active optometrist with record access may create a `supplement`, which is
  visibly attributed to that optometrist and does not claim to rewrite the
  original author's note.
- Staff and plain administrators cannot create either type.
- Prescription corrections continue through the existing Prescription
  amendment workflow and cannot be encoded as an Encounter addendum.

The completed Encounter page displays addenda chronologically after the original
record. Each print includes Encounter number, original encounter date, original
author, a clear **Addendum — original record unchanged** label, sequence number,
type, authored date/time, author name and role, reason, and content. No new PRC
license or professional-credential fields are added.

## Data Model Changes

### `encounters`

Add nullable `TEXT` columns:

- `assessment`;
- `supporting_test_results`.

They remain nullable at the database level so drafts and existing rows are
valid, while completion validation requires `assessment` and does not require
`supporting_test_results`. Both use Laravel's `encrypted` cast and therefore are
not searchable.

Continue encrypting the existing clinical narrative columns, including
`chief_complaint`, histories, allergies, medications, `findings`, `remarks`, and
`plan`.

### `encounter_addenda`

Add the append-only table defined above, an `EncounterAddendum` model, an
`EncounterAddendumType` enum, and `Encounter::addenda()` relationship.

### Legacy Patient Intake

Remove the active Patient Intake lookup and attachment from
`CheckInAppointment`. New Encounter records leave `patient_intake_id` null and
the Encounter workflow does not eager-load or display Intake data.

Dropping the `patient_intakes` table, its remaining model/actions/views, or the
nullable legacy FK is a separate deprecation/cleanup decision and is not part of
this feature. No new consumer may be added.

## Internal Action Interfaces

The Filament layer delegates mutations to actions; it does not update clinical
or assignment columns directly.

```php
final class StartEncounter
{
    public function handle(Encounter $encounter, User $actor): Encounter
    {
        // Authorize, lock, transition, assign actor when unassigned, and audit.
    }
}

final class SaveEncounterDraft
{
    /** @param array<string, mixed> $data */
    public function handle(
        Encounter $encounter,
        User $actor,
        array $data,
        int $lastWizardStep,
    ): Encounter {
        // Validate the boundary, preserve draft semantics, and audit as needed.
    }
}

final class TransferEncounter
{
    public function handle(
        Encounter $encounter,
        User $actor,
        User $newOptometrist,
        EncounterTransferReason $reason,
    ): Encounter {
        // Authorize, lock, synchronize provider assignment, and audit.
    }
}

final class CreateEncounterAddendum
{
    public function handle(
        Encounter $encounter,
        User $actor,
        EncounterAddendumType $type,
        string $reason,
        string $content,
    ): EncounterAddendum {
        // Authorize, validate, allocate sequence, persist, and audit.
    }
}
```

`CompleteEncounter` remains the sole completion boundary and is updated to
enforce the completion contract and assignment rules. All action methods use
explicit parameter and return types.

## Authorization Contract

Add an `EncounterPolicy` and enforce it in both Filament visibility and the
server-side actions:

| Capability | Staff | Optometrist | Admin | Admin + Optometrist |
|---|---:|---:|---:|---:|
| View encounters | Yes | Yes | Yes | Yes |
| Assign planned encounter | Yes | Yes | Yes | Yes |
| Start encounter | No | Assigned/self-claim only | No | Assigned/self-claim only |
| Edit in-progress clinical draft | No | Assigned only | No | Assigned only |
| Complete encounter | No | Assigned only | No | Assigned only |
| Transfer as current provider | No | Yes | No | Yes |
| Transfer as administrator | No | No | Yes | Yes |
| Add correction | No | Original completing provider only | No | Original completing provider only |
| Add supplement | No | Yes | No | Yes |
| Print completed encounter | Yes | Yes | Yes | Yes |

Hiding a Filament action is not an authorization boundary. Each action must
reject unauthorized direct Livewire invocation and stale state.

## Validation Rules

- Trim all narrative input.
- Apply a 10,000-character maximum to clinical narrative fields.
- Apply a 1,000-character maximum to addendum reasons.
- Require `last_wizard_step` to be between 1 and 4.
- Validate enum inputs using their enum classes.
- Resolve all users from persisted IDs; never trust role or assignment values
  submitted by the browser.
- Render clinical and addendum text through escaped Blade output; no raw HTML.
- Generic validation messages may identify missing fields but must not echo
  clinical content.

## Threat Model and Security Requirements

### Assets

- encrypted clinical history, findings, assessment, plan, and addenda;
- integrity and finality of the completed record;
- treating-provider and completion attribution;
- prescription and Appointment state consistency.

### Primary Abuse Cases and Controls

| Abuse case | Required control |
|---|---|
| Staff invokes hidden Livewire clinical actions | Policy plus action-level role and assignment checks |
| Optometrist edits or completes another provider's encounter | Assigned-provider check after row lock |
| Concurrent start, transfer, completion, or addendum sequence | Transaction and `lockForUpdate()` with database constraints |
| Completed note is silently changed | Read-only state, action denial, append-only addenda, audit event |
| Clinical narrative leaks through audit logs | Audit metadata allowlist containing identifiers/categories only |
| Encrypted data is accidentally queried or searched | No searchable configuration for encrypted columns |
| Addendum is edited or deleted | No update/delete actions, model/policy denial, no soft-delete path |
| Prescription succeeds while completion fails, or vice versa | One transactional completion boundary |

No new external service, file upload, dependency, public API, authentication
flow, or CORS behavior is introduced.

## Filament Experience

- Keep the existing four-step Wizard rather than replacing it with a one-page
  authoring form.
- Display a persistent header with Encounter number, patient, Appointment type,
  assigned optometrist, and start time.
- Planned and terminal records render as read-only views rather than disabled
  editable fields.
- In-progress records expose only the clinical wizard and actions authorized for
  the current actor.
- Move prescription authoring into the Assessment & Plan step while preserving
  its optional and draft behavior.
- The final review step summarizes current form state before completion.
- Transfer and addendum actions use explicit modal forms with server-side
  validation and confirmation.
- Do not add inline editable table columns for assignment, status, or clinical
  data; mutations use authorized actions.

## Printing

Provide an authenticated Encounter print route/view using existing Blade and
print conventions; no new PDF or rendering dependency is added.

- Only authorized panel roles may print.
- Only completed Encounters use the signed clinical print layout.
- Original clinical record appears first; addenda follow chronologically.
- Each print records an audit event without copying clinical content into audit
  metadata.
- User-supplied content is escaped.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5 / Livewire 4
- MySQL
- Pest 4 / PHPUnit 12
- Laravel encrypted Eloquent casts for clinical narrative
- Laravel policies, validation, transactions, and row locking
- Laravel Sail for all PHP, Artisan, Composer, and Node commands

No dependency changes are required.

## Commands

```bash
# Start the application services
vendor/bin/sail up -d

# Inspect migrations and routes
vendor/bin/sail artisan migrate:status
vendor/bin/sail artisan route:list --except-vendor

# Focused domain tests
vendor/bin/sail artisan test --compact tests/Feature/Encounters
vendor/bin/sail artisan test --compact tests/Feature/Filament/EncounterResourceTest.php

# Format changed PHP files
vendor/bin/sail bin pint --dirty --format agent

# Full regression suite before completion
vendor/bin/sail artisan test --compact

# Build frontend assets only if implementation changes bundled assets
vendor/bin/sail npm run build
```

## Project Structure

```text
app/Actions/Encounters/                    lifecycle mutation boundaries
app/Enums/                                 encounter/addendum/transfer enums
app/Filament/Resources/Encounters/         wizard, tables, pages, actions
app/Models/                                Encounter and EncounterAddendum
app/Policies/                              Encounter authorization policy
database/factories/                        encounter/addendum test factories
database/migrations/                       additive schema changes
resources/views/filament/encounters/       completion and print views
tests/Feature/Encounters/                  domain/action/encryption tests
tests/Feature/Filament/                    Filament workflow and role tests
docs/specs/                                this living specification
```

No new base directory may be introduced.

## Code Style

- Follow neighboring Laravel action, model, enum, migration, and Filament
  conventions.
- Use descriptive action and field names.
- Use PHP 8 constructor promotion where dependencies are injected.
- Use explicit parameter and return types.
- Use TitleCase enum cases.
- Use braces for every control structure.
- Prefer PHPDoc for array shapes and generic Eloquent relationships.
- Keep clinical state changes inside actions, not page closures.

Example boundary style:

```php
public function handle(
    Encounter $encounter,
    User $actor,
    EncounterTransferReason $reason,
    User $newOptometrist,
): Encounter {
    if (! $actor->isAdmin() && $encounter->optometrist_id !== $actor->id) {
        throw ValidationException::withMessages([
            'actor' => ['Only the treating optometrist or an administrator may transfer this encounter.'],
        ]);
    }

    return DB::transaction(function () use ($encounter, $actor, $reason, $newOptometrist): Encounter {
        // Lock, revalidate, persist, synchronize, and audit.
    });
}
```

## Testing Strategy

Use Pest feature tests, `RefreshDatabase`, factories, and existing
Filament/Livewire testing conventions.

### Domain and Schema Tests

- migration adds nullable `assessment` and `supporting_test_results` text
  columns and the constrained addenda table;
- clinical and addendum narrative is encrypted at rest and transparently
  decrypted by models;
- addendum sequence is unique within an Encounter;
- addenda have no supported update/delete path;
- check-in creates exactly one planned Encounter without attaching Intake;
- check-in copies Appointment provider and may prefill chief complaint.

### Authorization and Lifecycle Tests

- role matrix covers staff, optometrist, admin, and `admin + optometrist`;
- only an active assigned optometrist starts/edits/completes;
- unassigned Encounter may be claimed only by the optometrist starting it;
- nobody can start as another optometrist;
- planned assignment and in-progress transfer synchronize Appointment and
  Encounter providers;
- transfer preserves draft data and writes allowlisted audit metadata;
- stale or concurrent lifecycle actions cannot produce invalid transitions;
- plain admin can transfer operational ownership but cannot author or complete;
- completed and cancelled Encounters reject clinical updates.

### Wizard and Completion Tests

- all four wizard steps and expected fields are present;
- moving forward persists data and last step without completing;
- reopening resumes the saved step;
- completion rejects each missing required field;
- optional prescription, referral, follow-up, and supporting tests do not block
  completion;
- optional valid prescription and Encounter complete atomically;
- invalid prescription prevents all completion effects;
- successful completion records author/time and fulfills the Appointment;
- completed page is one read-only summary rather than a disabled wizard.

### Addendum and Print Tests

- original completing optometrist may create a correction;
- another active optometrist may create only a supplement;
- staff and plain admin cannot create addenda;
- addenda are chronological, attributable, and immutable;
- print output labels addenda and preserves the original record;
- prescription corrections cannot use Encounter addenda;
- print authorization and audit behavior match the contract.

Run focused tests during each implementation slice, Pint after PHP changes, and
the full Pest suite before declaring implementation complete. This specification
phase changes documentation only, so application tests are not required now.

## Boundaries

### Always

- Keep Appointment scheduling and Encounter clinical care as separate records.
- Authorize every clinical mutation by explicit `optometrist` role and assigned
  provider identity.
- Revalidate state and authorization inside transactional actions.
- Encrypt all new clinical narrative using `TEXT` columns and Eloquent encrypted
  casts.
- Keep completed records immutable and corrections append-only.
- Keep audit metadata free of clinical narrative.
- Preserve historical attribution when users are deactivated or roles change.
- Follow existing conventions and preserve unrelated worktree changes.
- Update this specification before changing its approved behavior.
- Update `docs/BACKEND_CONTEXT.md` only when implementation becomes true.

### Ask First

- Adding structured eye-examination measurements or diagnosis codes.
- Adding mobile pre-visit intake or any patient encounter-authoring endpoint.
- Adding autorefractor integration, OCR, clinical attachments, or uploads.
- Adding PRC license or professional credential fields.
- Adding dependencies or changing authentication/role behavior.
- Dropping legacy Patient Intake tables or importing real patient data.
- Allowing completed Encounters to reopen or changing the state machine.
- Allowing non-optometrists to author clinical content or addenda.

### Never

- Put full clinical intake into an appointment request.
- Treat an Appointment or machine output as proof of care or a final
  prescription.
- Allow an optometrist to start, edit, or complete another provider's Encounter
  without an explicit transfer.
- Treat plain `admin` as clinical authority.
- Rely only on hidden Filament controls for authorization.
- Store clinical narrative in unencrypted audit metadata.
- Silently edit, delete, or replace a completed Encounter or addendum.
- Delete tests to accommodate implementation behavior.

## Success Criteria

- Check-in creates exactly one `planned` Encounter for a scheduled or walk-in
  Appointment without requiring or attaching Intake.
- Appointment reason prefills chief complaint when present without overwriting a
  later optometrist edit.
- Assigned optometrist can complete the four-step wizard and resume saved drafts.
- Required completion fields are enforced only at completion.
- The form stores an encrypted assessment and optional encrypted device-neutral
  supporting-test narrative.
- Prescription remains optional and, when used, finalizes atomically with the
  Encounter.
- Successful completion records author/time and fulfills the Appointment.
- Staff and plain admin can view but cannot author or complete clinical content.
- Plain admin may coordinate an audited transfer without gaining clinical
  authorship.
- Provider assignment remains consistent between Encounter and Appointment.
- Completed Encounters are read-only and have no reopen path.
- Corrections and supplements are encrypted, append-only, attributable, ordered,
  authorized, viewable, and printable.
- No dedicated autorefractor fields, mobile intake workflow, structured exam
  catalog, diagnosis codes, file uploads, or new dependencies are introduced.
- Focused tests, the full Pest suite, and Pint pass after implementation.
- `docs/BACKEND_CONTEXT.md` reflects the shipped behavior.

## Open Questions

There are no blocking implementation questions for this MVP.

Future validation should compare the narrative Examination step against the
clinic's actual paper form and several representative visits. Any consistently
recorded measurements discovered through that validation require a separate
specification change and approval before becoming structured fields.
