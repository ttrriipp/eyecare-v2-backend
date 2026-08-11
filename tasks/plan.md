# Implementation Plan: Unlinked Mobile Account Clinic Inquiries

**Status:** Approved
**Specification:** `docs/specs/unlinked-account-inquiries-spec.md`
**Specification approved:** 2026-08-11
**Plan approved:** 2026-08-11
**Task breakdown:** Approved 2026-08-11
**Implementation:** Not started — explicitly deferred by user

## Overview

Extend the existing singular patient conversation into an account-owned clinic
inquiry that works before a Patient link exists. The implementation preserves
the existing route paths and message history, introduces a nullable unique
mobile-account owner on conversations, safely associates that thread during
link approval or invitation acceptance, and detaches mobile ownership during
unlinking while retaining Patient history.

Unlinked accounts receive text-only general messaging. Linked accounts retain
private attachments. Structured Product, Appointment, and Optical Order context
links are retired for every account, although existing context rows remain in
the database until a separately approved cleanup. Staff, administrators, and
optometrists may reply in Filament; routine notifications remain limited to
staff and administrators.

## Planning Gate

This approved Phase 2 document defines architecture, dependency order,
vertical slices, risks, and verification checkpoints. The Phase 3 checklist in
`tasks/todo.md` is also approved. The user explicitly deferred Phase 4, so no
application-code change is authorized until a new implementation instruction.

## Current-State Constraints

The plan modifies these existing behaviors rather than rebuilding messaging:

- `conversations.patient_id` is currently required and unique, so one
  Conversation belongs to one Patient and cannot represent an unlinked account.
- All singular Conversation routes currently require
  `require.patient.link`; an unlinked account receives
  `ACTIVE_PATIENT_LINK_REQUIRED` before the controller runs.
- `ConversationController` resolves the thread through
  `$request->user()->patient` and authorizes attachment access by Patient ID.
- `StoreMessageRequest` currently accepts a 5,000-character body, one private
  attachment, and up to five structured contexts.
- Context input and resolution are inconsistent (`order` validation versus
  `job_order` resolution), reinforcing the approved retirement rather than
  expansion of the feature.
- `ConversationResource` exposes `patient_id` and does not describe client
  capabilities.
- The Filament chat assumes every conversation has a Patient and eagerly loads
  and renders context cards.
- New-message database notifications already target staff and administrators;
  optometrists can currently reach the resource through general panel access
  but are not notification recipients.
- `ReviewPatientLinkRequest`, `AcceptPatientInvitation`, and
  `UnlinkPatientAccount` already own the transactional Patient-link mutations.
- Conversations use `SoftDeletes`. A unique account owner must therefore
  account for archived rows rather than accidentally creating a duplicate.
- Attachments are stored privately and use the singular ownership-checked
  download route; that privacy boundary must be preserved.

## Architecture Decisions

### 1. Make the mobile account the current-thread owner

Add nullable unique `conversations.account_user_id`, referencing `users` with
deletion restricted. Make `patient_id` nullable, remove its unique constraint,
retain an index, and restrict deletion.

The supported ownership states are:

```text
Unlinked current inquiry     account_user_id = set   patient_id = null
Linked current conversation account_user_id = set   patient_id = set
Historical detached thread  account_user_id = null  patient_id = set
```

Only `account_user_id` authorizes mobile access. `patient_id` is a clinic-side
association and historical reference. No mobile field may set either value.

### 2. Backfill without rewriting history

For every existing Conversation whose Patient has a linked `user_id`, copy the
User ID to `account_user_id`. Patient-only conversations remain historical.
Preserve conversation/message IDs, timestamps, attachments, and legacy context
rows.

The migration must detect conflicting account ownership before adding the
unique constraint and fail clearly instead of selecting or merging a thread.
No migration may delete messages to make the constraint pass.

### 3. Resolve one current account thread centrally

Introduce one reusable account-conversation resolver used by all three mobile
Conversation endpoints and attachment authorization. It:

- requires an authenticated active patient-role account;
- resolves only by `account_user_id`;
- derives the current Patient from `users -> patient`;
- creates a thread lazily when none exists;
- includes archived rows when checking the unique owner; and
- restores the same account-owned thread when appropriate rather than
  colliding with the unique constraint or creating a second thread.

Creation and restoration must be concurrency-safe. A duplicate-key race is
resolved by re-reading the canonical account-owned row, not by returning a
second conversation.

### 4. Keep Patient linking and conversation ownership atomic

Link-request approval and invitation acceptance associate an existing
account-owned conversation with the verified Patient inside their current
Patient-link transaction. They do not create a conversation merely because a
link was approved and do not merge patient-only historical threads.

Unlinking clears `account_user_id` from the current Patient-associated thread
before clearing `patients.user_id`, within the same transaction that revokes
tokens and records the audit event. The detached thread retains `patient_id`
and all content. A later unlinked inquiry creates a new account-owned thread.

Dedicated small actions should encapsulate association and detachment so both
link entry points share the same locking and invariants without duplicating
controller or Filament logic.

### 5. Preserve routes while changing their access tier

Move the four singular Conversation routes from the active-link group to the
authenticated account-only group. Require a patient-role account at the
request/action boundary so a panel account with a Sanctum token cannot use the
patient mobile messaging contract.

Keep route methods, paths, names, and total count unchanged. Add
`access_level`, `can_upload_attachments`, and
`can_create_context_links = false` to the Conversation response while keeping
existing non-context fields stable and making `patient_id` nullable.

### 6. Retire structured contexts at the API boundary

Mark `contexts` and related structured context fields as prohibited for linked
and unlinked senders. Remove new context creation and resource-context output.
Remove context eager loading and cards from the Filament chat. Do not drop the
legacy table/model or delete existing rows in this feature.

Android may prefill a safe Product name/SKU or an already-visible appointment
or order number into the plain-text body. The backend treats that text only as
message content, never authorization or a foreign-key instruction.

### 7. Gate private attachments by the active link

Unlinked uploads return HTTP 422 before storing a file. Attachment downloads
require all of:

- a patient-role account with an active Patient link;
- current ownership through `conversation.account_user_id`; and
- attachment membership in that exact conversation.

Ownership failures return 404. The implementation must not leak whether a
guessed attachment, Patient, or former conversation exists. Existing file type,
size, and private-storage rules remain unchanged for linked accounts.

### 8. Separate panel access from notification responsibility

Authorize `admin`, `staff`, and `optometrist` roles to view and reply through
the Filament Conversations resource. Patient accounts remain panel-ineligible.
Continue sending routine new-message notifications only to staff/admin.

The chat list and header show the account name for an unlinked current inquiry,
the Patient name for a patient-associated thread, and an explicit unlinked
warning when no Patient is associated. No chat action may bypass the formal
link-request or invitation workflow.

### 9. Keep rate limiting and rendering explicit

Retain the general account API throttle for reads and add a 10-per-minute limit
to message submission. Continue server-derived sender attribution, 5,000
character validation, plain-text rendering, Blade escaping, and private file
storage.

No dependency, WebSocket service, automated response, or new mobile route is
needed.

## Dependency Graph

```text
Existing behavior characterization
    -> Conversation ownership migration and relationships
        -> Account conversation resolver
            -> Account-only read contract
            -> Text send / attachment restrictions / context retirement
        -> Link approval and invitation association
            -> Safe unlink detachment
        -> Filament account/patient fallback and role policy
            -> Seed/demo reconciliation
                -> Contract documentation and final regression
```

The migration and model contract are foundational. API messaging and link
lifecycle work share that contract and must not proceed against the old
Patient-only ownership model. Final contract documentation follows tested
behavior.

## Implementation Slices

### Phase A: Protect and establish ownership

- **Task 1 — Characterize current messaging and link boundaries.** Add focused
  tests for current linked messaging, private attachment ownership, link
  isolation, and current response behavior before intentional changes.
- **Task 2 — Establish the ownership schema.** Add the ownership migration,
  deterministic backfill checks, constraints, and schema-level tests.
- **Task 3 — Model account-owned and historical conversations.** Add model
  relationships, factory states, and model-level uniqueness/history tests.

### Checkpoint A: Ownership foundation

- Existing linked conversation history is preserved.
- Migration and rollback constraints are understood and tested.
- One account cannot own two current conversations.
- One Patient may retain multiple historical conversations.

### Phase B: Deliver the account messaging slice

- **Task 4 — Resolve and expose the current account conversation.** Add the
  concurrency-safe resolver, move read routes to the account-only tier, return
  linked/unlinked capabilities, and replace the old unlinked access denial
  characterization.
- **Task 5 — Enforce text-only sending and retire contexts.** Support unlinked
  text, prohibit all structured contexts, remove context output, and apply the
  10-per-minute send throttle.
- **Task 6 — Protect linked-only attachments.** Preserve valid linked uploads
  and downloads while rejecting unlinked and cross-account file access.

### Checkpoint B: Mobile contract

- Unlinked accounts can open, read, and send text only in their current thread.
- Linked messaging and valid attachments remain functional.
- Cross-account and former-thread access is non-disclosing.
- Route paths and total route count are unchanged.

### Phase C: Integrate the Patient-link lifecycle

- **Task 7 — Associate a current inquiry during linking.** Share one action
  between link-request approval and invitation acceptance and prove that both
  associate without creating or merging unrelated conversations.
- **Task 8 — Detach mobile ownership during unlinking.** Detach within the
  current revocation/audit transaction and prove that reauthentication cannot
  recover the former patient-associated thread.

### Checkpoint C: Link safety

- Link and conversation mutations commit or roll back together.
- Unlinking revokes tokens and removes mobile thread ownership atomically.
- Historical messages remain available to authorized clinic users.

### Phase D: Complete clinic operation and reconciliation

- **Task 9 — Adapt the Filament chat and authorization.** Support account-name
  fallback, the unlinked warning, all three panel roles, text replies, linked
  attachments, and removal of context cards/eager loading.
- **Task 10 — Reconcile demo conversation data.** Update the workflow seed and
  its focused verification to create valid account-owned conversations.
- **Task 11 — Reconcile contracts and final integration.** Update backend,
  Android-facing API, and mobile MVP documentation, verify route/access
  matrices, and run the final focused regression suite.

### Checkpoint D: Complete

- Every specification success criterion has a passing automated test or an
  explicit Filament manual check.
- No context link is newly created or exposed.
- No unlinked account can access an attachment or former linked thread.
- Filament works for admin, staff, and optometrist roles.
- Backend context and Android-facing contract documentation match behavior.
- Pint reports no remaining formatting changes.

## Verification Strategy

Implementation follows test-driven, incremental slices. Each detailed task
will name its smallest focused command. Expected checkpoints include:

```bash
vendor/bin/sail artisan test --compact tests/Feature/ConversationTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/UnlinkedConversationTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/MessagingRatingTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/PatientLinkAccessMatrixTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AcceptPatientInvitationTest.php
vendor/bin/sail artisan test --compact tests/Feature/Patients/ReviewPatientLinkRequestTest.php
vendor/bin/sail artisan test --compact tests/Feature/Filament/PatientLinkRequestReviewTest.php
vendor/bin/sail artisan test --compact tests/Feature/Filament/ConversationResourceTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php
vendor/bin/sail artisan test --compact tests/Feature/Seeders/ClinicWorkflowSeederTest.php
vendor/bin/sail bin pint --dirty --format agent
```

The new `UnlinkedConversationTest` and `ConversationResourceTest` filenames are
planning targets; Phase 3 may combine them with an existing focused file only
when doing so keeps task scope and intent clear.

No ad hoc verification script or Tinker-created production record is needed.

## Parallelization and Sequencing

- Tasks 1–3 are sequential because characterization and schema must protect the
  model boundary.
- Tasks 4 and 7 both depend on Task 3 and conceptually could proceed in
  parallel, but they share Conversation ownership code; implement sequentially
  unless separate worktrees and explicit file ownership are established.
- Task 9 can begin after Tasks 3 and 6 stabilize the model and context-retirement
  contract. It must not guess the final API or ownership behavior.
- Task 10 follows the stable ownership model. Task 11 is last because contracts
  must describe verified behavior rather than planned behavior.

No sub-agent delegation is required by this plan.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Existing data maps two conversations to one account | High | Preflight and migration test the backfill; fail without merging/deleting data |
| Link/unlink race leaves stale mobile access | High | Lock Patient/account/conversation rows and mutate in the existing transaction |
| Soft-deleted account thread collides with unique owner | High | Resolve with `withTrashed()` and restore the canonical thread instead of inserting |
| Account is unlinked but can still download old attachments | High | Require current account ownership and active link on every download |
| Context removal breaks an Android build still sending `contexts` | Medium | Coordinate the approved contract cutover; return deterministic 422 and update contract docs |
| Patient-only historical thread is mistaken for the current mobile thread | Medium | Mobile resolution uses only `account_user_id`; Filament labels ownership state explicitly |
| Migration rollback cannot restore one-conversation-per-Patient after unlink/relink creates history | Medium | Back up before deployment; make rollback fail clearly on duplicates and use a forward fix rather than destructive merge |
| Panel user discloses clinical information in an unlinked thread | Medium | Prominent warning and no Patient/clinical controls; operational policy remains required for free text |
| Retained legacy context rows confuse future maintainers | Low | Document them as read-only legacy data and defer physical removal explicitly |
| Message spam or stored markup abuse | Medium | 10/min sender throttle, 5,000-character boundary, plain-text escaped rendering |

## Boundaries During Implementation

### Always

- Search version-specific Laravel/Filament/Pest documentation before code
  changes when the project documentation tool is available.
- Use Sail-prefixed generators and commands.
- Write the focused Pest test before each behavioral change.
- Preserve existing conversations, messages, attachments, and legacy context
  rows.
- Derive sender, account, Patient, and capability state on the server.
- Run focused tests and `vendor/bin/sail bin pint --dirty --format agent` after
  PHP changes.

### Ask first

- Add a dependency, route, message category, real-time service, automated
  response, or unlinked upload capability.
- Drop or delete legacy context data.
- Change account linking, token revocation, privacy retention, or the approved
  panel-role/notification matrix.
- Introduce another breaking Android contract change.

### Never

- Auto-link an account from matching identity data.
- Trust mobile-supplied owner, Patient, sender, or resource IDs.
- Merge or delete historical messages during link reconciliation.
- Expose attachments or former linked threads to an unlinked account.
- Use UI visibility as the authorization boundary.
- Log message bodies, attachment contents, contacts, or clinical narrative.

## Open Questions

None. All five specification questions were approved on 2026-08-11.

## Phase 4 Gate

The detailed checklist in `tasks/todo.md` is approved. Phase 4 implementation
remains explicitly deferred and requires a new user instruction before any
application-code task begins.
