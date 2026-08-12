# Spec: Dead Code and Unreachable Feature Removal

## Status

**Draft 2026-08-12. Approval pending.**

Revised the same day after two audit findings were corrected — message
attachments are live and are no longer in scope, and the privacy question was
resolved by the owner. No open questions remain.

## Objective

Remove tables, models, actions, and tests that no running code path reaches.

This is distinct from `docs/specs/commerce-model-simplification-spec.md`, which
removes *sophistication that runs* — real code doing more than a small clinic
needs. This spec removes code that **does not run at all**: schema with no
model, backends with no user interface, and a subsystem the application
explicitly bypasses.

That distinction matters for risk. Nothing here has a live consumer, so the
dominant failure mode is not "behavior changes" but "something turns out to be
referenced after all." The verification strategy is built around that.

## Audit Method and Findings

Each candidate was checked for: a migration, a model, references in `app/`,
a Filament surface, routes, tests, factories, and seeders. A feature counts as
unreachable when **no user of either client can trigger it**.

| Subsystem | Migration | Model | App refs | UI | Tests | Verdict |
|---|:--:|:--:|:--:|:--:|:--:|---|
| `notification_channels` | yes | none | 0 | none | none | Dead schema |
| `notification_templates` | yes | none | 0 | none | none | Dead schema |
| `inventory_movement_statuses` | yes | none | 0 | none | none | Dead schema |
| Patient intakes | yes | yes | 7 | none | 3 files | Bypassed |
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
model. `Complaint` is referenced only by `ComplaintPolicy`,
`RestartComplaintWorkflow`, and its own model.

## Scope

### 1. Dead schema — no model, no references

`notification_channels`, `notification_templates`, and
`inventory_movement_statuses` exist only as migrations. Nothing reads or writes
them and no seeder populates them.

`inventory_movement_statuses` deserves a note: the running code uses
`inventory_movement_types`, a *different* table. A near-identical dead table
sitting beside the live one is an active hazard — it is exactly the kind of
thing a future reader wires up by mistake.

**Changes:** one migration dropping all three tables.

### 2. Patient intakes — bypassed by the encounter wizard

The four-step encounter wizard replaced intake capture. `CheckInAppointment`
explicitly writes `'patient_intake_id' => null`, so **no encounter created by
the application can ever have an intake.** The subsystem is reachable only by
records that predate the wizard, and per ADR-002 no such records exist outside
disposable development data.

**Changes:**

- Drop `patient_intakes`; drop `encounters.patient_intake_id`.
- Delete `PatientIntake`, `IntakeStatus`, `Encounter::intake()`,
  `Appointment::intake()`, `Patient` intake relations.
- Delete `app/Actions/Intakes/` (`VerifyPatientIntake`,
  `ReturnIntakeForCorrection`).
- Delete `AuditLegacyPatientIntakes` and the `encounters:audit-legacy-intakes`
  command — a readiness check for a cleanup this spec performs.
- Delete `PatientIntakeFactory`, `PatientIntakeTest`,
  `IntakeVerificationTest`, `LegacyIntakeCleanupAuditTest`.
- Update `EncounterLifecycleCharacterizationTest` and `ClinicWorkflowSeeder`
  to drop intake setup.
- `routes/web.php` eager-loads `intake.submittedBy` / `intake.verifiedBy` on
  the appointment print view — remove those and any template output.

`IntakeVerificationTest` asserts a role boundary (staff may verify an intake
but that does not authorize clinical findings). Confirm the equivalent boundary
is covered by an encounter test before deleting; write one if not.

### 3. Complaints — no way to file one

A `complaints` table, model, policy, and `RestartComplaintWorkflow` exist. No
Filament resource, no API route, no relation manager. The workflow it
implements — a complaint spawning a new appointment and encounter — cannot be
started by anyone.

**Changes:** drop `complaints`; delete `Complaint`, `ComplaintStatus`,
`ComplaintPolicy`, `app/Actions/Complaints/`, `ComplaintFactory`,
`ComplaintRestartTest`, and the `Patient` / `JobOrder` complaint relations.

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

## Technical Context

PHP 8.5, Laravel 13, Filament 5, MySQL via Sail, Pest 4 / PHPUnit 12. No
dependency change. Schema changes are drops only.

## Project Structure

```text
app/Models/                → −2 models (PatientIntake, Complaint)
app/Enums/                 → −2 enums (IntakeStatus, ComplaintStatus)
app/Actions/Intakes/       → deleted
app/Actions/Complaints/    → deleted
app/Actions/Privacy/       → untouched
app/Policies/              → −1 policy (ComplaintPolicy)
app/Console/Commands/      → −1 command
database/factories/        → −2 factories
database/migrations/       → +1 drop migration (5 tables, 1 column)
tests/Feature/             → −4 test files, 2 updated
routes/web.php             → intake eager-loads removed
docs/BACKEND_CONTEXT.md    → removals + permission-matrix correction
```

## Testing Strategy

The risk here is not regression but **discovering a reference after deletion**.
Verification is therefore proof-of-absence before removal, and suite integrity
after.

1. **Before each deletion**, prove unreachability: grep the model and table
   name across `app/`, `routes/`, `database/`, and `resources/views/`, and
   confirm no Filament resource, page, relation manager, or widget targets it.
   Record the result in the task checklist.
2. **Do not delete a test that asserts a rule which still applies elsewhere.**
   `IntakeVerificationTest`'s role boundary is the known instance — confirm
   encounter coverage first.
3. After each phase: full suite green.
4. Final: `migrate:fresh --seed` succeeds, proving no migration, seeder, or
   factory references a dropped table.
5. A closing grep proves every deleted symbol is absent from `app/`,
   `database/`, `routes/`, `resources/`, and `tests/`.

Deleted tests are deleted, never skipped.

## Commands

```bash
vendor/bin/sail artisan test --compact tests/Feature/Encounters
vendor/bin/sail artisan test --compact tests/Feature/ConversationTest.php
vendor/bin/sail artisan test --compact
vendor/bin/sail artisan migrate:fresh --seed
vendor/bin/sail bin pint --dirty --format agent
```

## Boundaries

### Always

- prove unreachability by grep and UI inspection before deleting;
- drop schema and its code together — never one without the other;
- keep `migrate:fresh --seed` working after every phase;
- update `docs/BACKEND_CONTEXT.md` in the same change.

### Ask first

- deleting anything with a Filament surface, an API route, or a live relation;
- deleting a test asserting a rule that still applies elsewhere;
- touching appointments, commerce, or the six recorded legacy items.

### Never

- delete `audit_logs`, `inventory_movements`, or any ledger table;
- leave a table without its model, or a model without its table;
- skip a test instead of deleting it;
- delete privacy compliance before Open Question 1 is answered.

## Success Criteria

1. `notification_channels`, `notification_templates`, and
   `inventory_movement_statuses` no longer exist.
2. `patient_intakes` and `encounters.patient_intake_id` are gone, along with
   every intake model, action, command, factory, and test.
3. `complaints` is gone with its model, enum, policy, action, factory, and
   test.
4. Message attachments are **untouched** and still work end to end: upload,
   download, preview, API exposure, and Filament display.
5. Privacy compliance code is untouched; only the permission matrix in
   `docs/BACKEND_CONTEXT.md` is corrected to stop advertising an unreachable
   capability.
6. No orphan remains in either direction: no table without a model, no model
   without a table.
7. `migrate:fresh --seed` succeeds; full suite green; Pint reports no changes.
8. `docs/BACKEND_CONTEXT.md` no longer documents anything removed, and its
   permission matrix matches what the panel can actually do.

## Open Questions

None. The privacy question was resolved by the owner on 2026-08-12 — keep the
backend, build nothing, correct the permission matrix. The spec is purely
subtractive.
