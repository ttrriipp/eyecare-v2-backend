# Spec: Dead Code and Unreachable Feature Removal

## Status

**Specification, plan, and checklist approved 2026-08-14. Phase 4
implementation is explicitly deferred by the owner and has not started.**

First revision (2026-08-12) corrected two audit findings — message attachments
are live and are no longer in scope, and the privacy question was resolved by
the owner.

Second revision (2026-08-13), taken after the commerce project landed,
corrected three more:

- `inventory_movement_statuses` **was already dropped** in June by migration
  `2026_06_19_003812`. Only two dead tables remain.
- Patient intakes have **three display surfaces**, not zero.
- `intake-form.blade.php` is an **orphan view** with no page class behind it.

Third revision (2026-08-13), same day, corrected the second: those three
surfaces are **themselves unregistered** and unreachable.
`HealthRecordRelationManager` is not in `PatientResource::getRelations()`, no
page class backs `intake-form.blade.php`, and the print view is linked only
from that orphan view. The second revision's claim of a live blank-column
defect is withdrawn — nobody can see those columns.

Fourth revision (2026-08-14) reconciled the specification with the current
worktree:

- `notification_channels` and `notification_templates` were already removed by
  `2026_06_24_124601_drop_unused_notification_tables`; no new notification-table
  migration is needed.
- The newer `ScenarioCoverageSeeder` also creates complaint records and must be
  trimmed alongside `ClinicWorkflowSeeder`.
- The proposed `Patient` / `JobOrder` complaint-relation removal was deleted
  from the checklist because those inverse relations do not exist.
- `EncounterCheckInTest` was added to the intake-related test updates.

This revision changes the specification only. No deletion, schema change, or
other implementation step has been run.

The spec is therefore **purely subtractive**, as originally scoped. No open
questions.

## Assumptions

1. The change remains purely subtractive; no replacement UI, workflow, or API
   is built as part of this work.
2. Existing unrelated worktree changes are preserved and excluded from the
   cleanup.
3. Destructive migration verification runs only against the testing
   environment.
4. The 25 unrelated full-suite failures recorded on 2026-08-14 are the
   comparison baseline; this work introduces no additional failures.
5. Tests are deleted only when they exclusively assert behavior removed by this
   specification. A still-applicable rule must retain equivalent coverage.
6. Removing the manually reachable but unlinked
   `appointments.health-record.print` route is an accepted compatibility break;
   the live encounter print route remains available.

## Objective

Remove tables, models, actions, and tests that no supported application workflow
reaches, plus one unlinked legacy print route whose removal the owner approved.

This is distinct from `docs/specs/commerce-model-simplification-spec.md`, which
removes *sophistication that runs* — real code doing more than a small clinic
needs. This spec removes code that **does not participate in a supported
workflow**: schema already dropped or bypassed, backends with no front door,
and orphaned display surfaces.

That distinction matters for risk. No supported workflow consumes this code,
so the dominant failure mode is not a normal workflow regression but a missed
reference. The known exception is the authenticated health-record print URL:
it is unlinked but manually reachable, and its removal is explicitly accepted.
The verification strategy is built around that boundary.

## Audit Method and Findings

Each candidate was checked for: a migration, a model, references in `app/`,
a Filament surface, routes, tests, factories, and seeders. A feature counts as
unreachable when **neither client exposes a supported path to trigger it**.

| Subsystem | Migration | Model | App refs | UI | Tests | Verdict |
|---|:--:|:--:|:--:|:--:|:--:|---|
| `notification_channels` | dropped | none | 0 | none | none | **Already gone** |
| `notification_templates` | dropped | none | 0 | none | none | **Already gone** |
| `inventory_movement_statuses` | dropped | none | 0 | none | none | **Already gone** |
| Patient intakes | yes | yes | 7 | **3 orphan surfaces** | 3 files | Bypassed and unreachable |
| `intake-form.blade.php` | — | — | 0 | none | none | Orphan view |
| Complaints | yes | yes | 3 | none | 1 file | No front door |
| Message attachments | yes | yes | 8 | **yes** | 1 file | **Live — not dead** |
| Privacy compliance | yes | yes | 6 | none | 2 files | Kept, not actioned |

### Corrections to the initial audit

Two findings from the first pass were wrong. Both are recorded because the
method that produced them is the method this spec relies on.

**Message attachments are live.** The first pass concluded there was no upload
path. There is: `ConversationController::store()` writes uploaded files to the
`attachments` disk path and creates `MessageAttachment` rows;
`GET /api/v1/conversation/attachments/{attachment}` downloads them;
`MessageAttachmentPreviewController` serves `/attachments/{attachment}/preview`;
`MessageResource` and `ConversationResource` expose them, the latter through
`can_upload_attachments`; and the Filament `ConversationChatPage` and
`MessagesRelationManager` both display them. Patient-to-clinic image messaging
works end to end.

> **Correction (2026-08-15):** `MessagesRelationManager` was cited as evidence
> that message attachments are live in the Filament UI. This was wrong —
> `ConversationResource` declares no `getRelations()`, so the relation manager
> was unreachable. The conclusion holds via `ConversationChatPage` and the other
> four references. `MessagesRelationManager` was deleted as part of the direct
> messaging hardening spec (T9).

The error came from searching Filament for paths matching the *class* name
`MessageAttachment`. The UI references the *relation* name `attachments`, so a
path search found nothing and "no UI" was inferred from a filename miss. **A
path search is not a reachability proof.** Section 5's verification procedure
greps relation names, table names, and route names — not just class names —
because of this.

**Complaints was a false positive in the other direction.** An early pass
reported `Complaint` as referenced from `HealthRecordRelationManager` and
`EncounterForm`. Those files match the string **"Chief Complaint"**, the
clinical narrative field on encounters, which is unrelated to the `Complaint`
model. In application code, `Complaint` is referenced only by
`ComplaintPolicy`, `RestartComplaintWorkflow`, and its own model. Demo records
are created by both `ClinicWorkflowSeeder` and `ScenarioCoverageSeeder`.

**Patient intakes: wrong twice, in opposite directions.**

The first pass searched `app/Filament` for the class name `PatientIntake`,
found only an eager-load, and concluded "no UI". That missed three surfaces
reading the **relation** name `intake` — the same axis-1 error as message
attachments.

The second pass found those surfaces and declared them live. That was wrong
too: it confirmed each surface reads `intake` but never asked whether anything
reaches the surface. None of them are registered.
`HealthRecordRelationManager` is absent from `PatientResource::getRelations()`,
no page class backs `intake-form.blade.php`, and the print view is linked only
from that orphan view. See §2 for the full table.

The right answer was reached only on the third pass, and only by walking
**outward** from each surface to its caller instead of inward from a symbol to
its references. Both failures share a shape: a search was run, it returned
something, and the something was treated as an answer.

Hence §4's fourth axis. A grep proves a reference exists; it does not prove a
user can get there. **Reachability is a path, not a match.**

## Scope

### 1. Already-removed schema and matched migration pairs

`notification_channels` and `notification_templates` have no model, runtime
reference, or seeder. They were already dropped by migration
`2026_06_24_124601_drop_unused_notification_tables`, so their absence requires
no new migration. The historical create/drop migrations remain unchanged.

`inventory_movement_statuses` **is already gone.** Migration
`2026_06_19_003812_drop_inventory_movement_statuses_table` dropped it in June;
the first audit pass read the `create` migration and missed the `drop`. The
running code uses `inventory_movement_types`, a different and live table.

What remains of it is a create/drop migration pair that cancels out on every
`migrate:fresh`. Per ADR-002 there is no deployed database whose history must
be preserved, so both files should be deleted rather than left as a matched
pair of no-ops. This is the only place in the spec where migration *files* are
deleted rather than a new drop migration being added, and it is safe precisely
because the table no longer exists in any environment.

**Changes:** delete only the two `inventory_movement_statuses` migration files.
Do not add a duplicate notification-table drop migration.

### 2. Patient intakes — bypassed by the encounter wizard

The four-step encounter wizard replaced intake capture. `CheckInAppointment`
explicitly writes `'patient_intake_id' => null`, so **no encounter created by
the application can ever have an intake.** The subsystem is reachable only by
records that predate the wizard, and per ADR-002 no such records exist outside
disposable development data.

#### The three display surfaces are themselves orphaned

Three surfaces read `intake`. The second revision reported them as live; that
was wrong, and the error is instructive — it confirmed each surface reads the
`intake` relation but never checked whether anything reaches the surface.

| Surface | Reads | Reachable? |
|---|---|---|
| `HealthRecordRelationManager` ("Visit History") | `intake.chief_complaint`, `intake.allergies` | **No** — not in `PatientResource::getRelations()` |
| `intake-form.blade.php` | `$this->getIntake()` | **No** — no page class defines it |
| `health-record-print.blade.php` (`routes/web.php:42`) | six `$intake?->` fields | Route registered; linked only from `intake-form.blade.php:31` |

`PatientResource::getRelations()` lists `PrescriptionsRelationManager`,
`AppointmentsRelationManager`, `EncountersRelationManager`,
`OpticalOrdersRelationManager`, `BillingRelationManager`, and
`InvitationHistoryRelationManager`. `HealthRecordRelationManager` is absent, so
"Visit History" is not a tab anywhere in the panel.

`app/Filament/Resources/Appointments/Pages/` holds only
`AppointmentRequestsPage`, `CreateAppointment`, `EditAppointment`, and
`ListAppointments` — none define `getIntake()`, so nothing renders
`intake-form.blade.php`.

The print route is the only partial exception: it is a real registered route, so
a signed-in panel user who typed the URL would reach it. But no UI element links
to it except the orphan view, so in practice it is unreachable too.

**Consequence: no repoint is needed, and there is no user-visible defect to
fix.** All three surfaces are deleted outright along with the model. The second
revision's claim that this spec had become "no longer purely subtractive" is
withdrawn — it is subtractive after all.

#### If the print view is wanted, that is a separate build

The print view is the one piece with arguable value: a printable health record
is a plausible clinic need, and the encounter already carries every field it
wants (`2026_07_31_132034_add_consultation_fields_to_encounters_table` added
`chief_complaint`, `past_ocular_history`, `past_surgical_history`,
`past_medical_history`, `allergies`, and `medications`, all encrypted, all
populated by the wizard).

But wiring it up means giving it a real entry point, sourcing it from the
encounter, and deciding where it belongs in the panel — that is a feature, not
cleanup, and it does not belong in a removal spec. Note also that
`EncounterPrintTest` and a `/encounters/{encounter}/print` route already exist,
so a working encounter printout may already cover the need.

**Default: delete it.** Raise a separate spec if the clinic wants an
appointment-level health-record printout.

**Changes:**

- Delete `HealthRecordRelationManager`, `intake-form.blade.php`, and
  `health-record-print.blade.php`.
- Delete the `appointments.health-record.print` route from `routes/web.php`.
- Drop `patient_intakes`; drop `encounters.patient_intake_id`.
- Delete `PatientIntake`, `IntakeStatus`, `Encounter::intake()`,
  `Appointment::intake()`, `Patient::intakes()`.
- Remove `patient_intake_id` from `Encounter`'s `Fillable` attribute,
  `EncounterFactory`, and `CheckInAppointment`.
- Remove the `'intake'` eager-load from `EncounterResource::getEloquentQuery()`.
- Delete `app/Actions/Intakes/` (`VerifyPatientIntake`,
  `ReturnIntakeForCorrection`).
- Delete `AuditLegacyPatientIntakes` and the `encounters:audit-legacy-intakes`
  command — a readiness check for a cleanup this spec performs.
- Remove `AuditEvent::IntakeSubmitted`, `IntakeVerified`, and
  `IntakeReturnedForCorrection`.
- Delete `PatientIntakeFactory`, `PatientIntakeTest`,
  `IntakeVerificationTest`, `LegacyIntakeCleanupAuditTest`.
- Update `EncounterLifecycleCharacterizationTest` and `EncounterCheckInTest` to
  remove intake-only setup and assertions.

`IntakeVerificationTest` asserts a role boundary (staff may verify an intake
but that does not authorize clinical findings). `PrescriptionLifecycleTest:45`
appears to cover the equivalent boundary on the clinical side; confirm it
before deleting, and write the missing case if it does not.

### 3. Complaints — no way to file one

A `complaints` table, model, policy, and `RestartComplaintWorkflow` exist. No
Filament resource, no API route, no relation manager. The workflow it
implements — a complaint spawning a new appointment and encounter — cannot be
started by anyone.

**Changes:** drop `complaints`; delete `Complaint`, `ComplaintStatus`,
`ComplaintPolicy`, `app/Actions/Complaints/`, `ComplaintFactory`,
`ComplaintRestartTest`. Remove `ClinicWorkflowSeeder::seedComplaint()` and
`ScenarioCoverageSeeder::seedComplaintStatuses()`, including their imports and
call sites. These seeders are the only code paths that create complaints.

### 4. Verification procedure

Because two of this audit's findings were wrong in opposite directions, a
symbol must be proven unreachable on **four** axes before deletion, not one:

1. **Class name** — `grep -rn "\bClassName\b" app/ routes/ database/ tests/`
2. **Relation name** — the accessor other code actually calls
   (`attachments`, `intake`, `complaints`)
3. **Table name** — `grep -rn "table_name"` across `app/`, `database/`,
   `routes/`, `resources/views/`
4. **Route and view** — `artisan route:list`, plus a grep of
   `resources/views/`

A miss on any one axis is not evidence of death. Message attachments returned
nothing on axis 1 in Filament and everything on axes 2 through 4.

Record all four results in the task checklist per symbol.

### 5. Privacy compliance — kept, not actioned

**Owner decision, 2026-08-12: keep the backend, build no UI for now.**

`privacy_requests` and `privacy_incidents`, with `PrivacyRequest`,
`PrivacyIncident`, `PrivacyRequestType`, `PrivacyRequestPolicy`,
`ProcessPrivacyRequest`, `UpdatePrivacyIncident`, two factories, and two test
files remain exactly as they are. Nothing is deleted and nothing is built.

This keeps Data Privacy Act groundwork in place — plausibly a capstone
requirement — without spending effort on a surface nobody has asked for. The
tests keep the backend honest in the meantime.

**One documentation change is still required.**
`docs/BACKEND_CONTEXT.md`'s permission matrix advertises "Audit logs and
privacy administration" as an admin capability. Audit logs are real; privacy
administration is not reachable. The matrix must be corrected to say so, so the
docs stop promising a front door that does not exist. This is the only privacy
work in scope.

## Explicitly Out of Scope

- **Appointments consolidation.** At 2,815 lines across 29 actions it is the
  largest domain in the system and likely holds real duplication — three
  overlapping scheduling actions, a 263-line identity-snapshot builder, a
  191-line availability-impact evaluator. But that is *running* code with live
  consumers, so it needs its own investigation and its own spec. It is not
  dead code and does not belong here.
- Everything in the commerce spec.
- The six non-commerce legacy accommodations recorded in commerce spec §8; the
  owner elected to leave those alone.
- `sms_notifications`, which is live and has a Filament resource.

## Tech Stack

PHP 8.5, Laravel 13, Filament 5, MySQL via Sail, Pest 4 / PHPUnit 12. No
dependency change. Schema changes are drops only.

## Project Structure

```text
app/Models/                → −2 models (PatientIntake, Complaint)
                             Encounter, Appointment, Patient relations trimmed
app/Enums/                 → −2 enums (IntakeStatus, ComplaintStatus)
                             AuditEvent −3 cases
app/Actions/Intakes/       → deleted
app/Actions/Complaints/    → deleted
app/Actions/Encounters/    → −1 action (AuditLegacyPatientIntakes),
                             CheckInAppointment trimmed
app/Actions/Privacy/       → untouched
app/Policies/              → −1 policy (ComplaintPolicy)
app/Console/Commands/      → −1 command
app/Filament/              → −1 orphan relation manager, 1 eager-load trimmed
resources/views/           → −2 orphan views
database/factories/        → −2 factories, EncounterFactory trimmed
database/seeders/          → complaint blocks removed from ClinicWorkflowSeeder
                             and ScenarioCoverageSeeder
database/migrations/       → +1 drop migration (2 tables, 1 column)
                             −2 files (inventory_movement_statuses create/drop pair)
tests/Feature/             → −4 test files, 2 updated
routes/web.php             → health-record print route removed
docs/BACKEND_CONTEXT.md    → removals + permission-matrix correction
```

## Code Style

Follow the existing Laravel 13 conventions and the repository's PHP rules:
explicit parameter and return types, anonymous migration classes, typed
`Blueprint` closures, descriptive names, and curly braces for every control
structure. Use `Schema` operations rather than raw SQL and let Pint enforce
formatting.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropForeign(['patient_intake_id']);
            $table->dropColumn('patient_intake_id');
        });

        Schema::dropIfExists('patient_intakes');
        Schema::dropIfExists('complaints');
    }
};
```

Deletion work should simplify callers directly; do not introduce adapters,
compatibility shims, placeholder classes, or commented-out code.

## Testing Strategy

The risk is not regression but **discovering a reference after deletion**.
Verification is therefore proof-of-absence before removal, and suite integrity
after. No supported user workflow changes; the accepted direct-URL print-route
removal is the only compatibility break.

The one addition to the usual procedure: for each Filament class and Blade view
being deleted, confirm its *registration* is absent, not merely its references.
A relation manager must be absent from `getRelations()`; a page from
`getPages()`; a view must have no renderer. That check is what the first two
passes skipped.

1. **Before each deletion**, prove unreachability: grep the model and table
   name across `app/`, `routes/`, `database/`, and `resources/views/`, and
   confirm no Filament resource, page, relation manager, or widget targets it.
   Record the result in the task checklist.
2. **Do not delete a test that asserts a rule which still applies elsewhere.**
   `IntakeVerificationTest`'s role boundary is the known instance — confirm
   encounter coverage first.
3. After each phase: the affected suites are green.
4. Final: `migrate:fresh --env=testing` succeeds. Run
   `migrate:fresh --seed --env=testing` to prove no migration, seeder, or factory
   references a dropped table; any unrelated pre-existing seeder failure must
   be reported separately rather than repaired in this cleanup.
5. A closing grep proves every deleted symbol is absent from `app/`,
   `database/`, `routes/`, `resources/`, and `tests/`.

Deleted tests are deleted, never skipped.

### Readiness baseline (2026-08-14)

- The focused encounter, complaint, and conversation run is green: 244 tests,
  460 assertions.
- The full suite has 25 known failures outside this spec. Implementation must
  introduce no additional failures; repairing that baseline is separate work.
- The current development database contains no patient intakes and no encounter
  linked to one. Its four complaints are seeded demo records.

## Commands

```bash
vendor/bin/sail artisan test --compact tests/Feature/Encounters
vendor/bin/sail artisan test --compact tests/Feature/ConversationTest.php
vendor/bin/sail artisan test --compact
vendor/bin/sail artisan migrate:fresh --env=testing
vendor/bin/sail artisan migrate:fresh --seed --env=testing
vendor/bin/sail bin pint --dirty --format agent
```

## Boundaries

### Always

- prove unreachability by grep and UI inspection before deleting;
- drop schema and its code together — never one without the other;
- keep `migrate:fresh --env=testing` working after every phase and ensure a
  seeded run introduces no dead-code reference failure;
- update `docs/BACKEND_CONTEXT.md` in the same change.

### Ask first

- deleting anything with a Filament surface, an API route, or a live relation;
- deleting a test asserting a rule that still applies elsewhere;
- touching appointments, commerce, or the six recorded legacy items.

### Never

- delete `audit_logs`, `inventory_movements`, or any ledger table;
- leave a table without its model, or a model without its table;
- skip a test instead of deleting it;
- delete any privacy compliance code — the owner elected to keep it;
- declare a symbol dead from a class-name search alone.

## Success Criteria

1. `notification_channels` and `notification_templates` remain absent through
   the existing drop migration, no duplicate drop migration is added, and the
   `inventory_movement_statuses` create/drop migration pair is deleted.
2. `patient_intakes` and `encounters.patient_intake_id` are gone, along with
   every intake model, enum, relation, action, command, audit event, factory,
   orphan view, and test.
3. The three orphaned intake surfaces are gone: `HealthRecordRelationManager`,
   `intake-form.blade.php`, `health-record-print.blade.php`, and the
   `appointments.health-record.print` route.
4. `complaints` is gone with its model, enum, policy, action, factory, test,
   and both seeder blocks.
5. Message attachments are **untouched** and still work end to end: upload,
   download, preview, API exposure, and Filament display.
6. Privacy compliance code is untouched; only the permission matrix in
   `docs/BACKEND_CONTEXT.md` is corrected to stop advertising an unreachable
   capability.
7. No orphan remains in either direction: no table without a model, no model
   without a table, no Blade view without a renderer.
8. `migrate:fresh --env=testing` succeeds, the cleanup introduces no new full-
   suite failures, and Pint reports no changes. The seeded migration run either
   succeeds or fails only on a separately recorded pre-existing seeder issue.
9. `docs/BACKEND_CONTEXT.md` no longer documents anything removed, and its
   permission matrix matches what the panel can actually do.

## Open Questions

None.

The privacy question was resolved by the owner on 2026-08-12 — keep the
backend, build nothing, correct the permission matrix.

The spec is **purely subtractive.** The second revision briefly claimed
otherwise, having found three surfaces that display intake data and concluded
they needed repointing at the encounter. The third revision established that
none of those surfaces is registered, so nothing displays intake data to anyone
and no repoint is required. That claim is withdrawn.

One judgment call is recorded rather than left open: the health-record print
view is deleted rather than rebuilt. A printable health record is plausibly
useful, and the encounter carries every field it wants — but wiring it up means
giving it an entry point and a home in the panel, which is a feature. A working
`/encounters/{encounter}/print` route already exists and may cover the need.
If it does not, that is a separate spec.
