# Task Checklist: Unlinked Mobile Account Clinic Inquiries

**Status:** Approved — implementation explicitly deferred
**Specification:** `docs/specs/unlinked-account-inquiries-spec.md`
**Plan:** `tasks/plan.md`
**Specification approved:** 2026-08-11
**Plan approved:** 2026-08-11
**Tasks approved:** 2026-08-11
**Implementation:** Not started — explicitly deferred by user

## Execution Rules

- Do not implement any task until the user gives a new Phase 4 implementation
  instruction; checklist approval alone does not override the explicit deferral.
- Implement tasks in dependency order and stop when a checkpoint fails.
- Before every Laravel, Filament, Livewire, migration, or Pest code change,
  use the project's version-aware documentation search when available.
- Use Sail-prefixed Artisan generators with `--no-interaction` for new Laravel
  files where an appropriate generator exists.
- Write or update the focused Pest test before changing behavior.
- Keep each task within the listed files unless a newly discovered dependency
  is reported before expanding scope.
- Preserve the user's unrelated dirty-worktree changes.
- Run `vendor/bin/sail bin pint --dirty --format agent` after PHP changes.
- Do not add dependencies, routes, context-link replacements, unlinked uploads,
  automated replies, or real-time messaging.
- Do not drop `message_context_links` or delete existing messages, attachments,
  or legacy context rows.

## Phase A: Protect and Establish Conversation Ownership

## Task 1: Characterize linked messaging boundaries

**Description:** Strengthen focused tests around the currently valid linked
conversation flow before changing ownership. Protect singular-thread behavior,
cross-patient isolation, response fields, and private attachment membership.

**Acceptance criteria:**

- [ ] A linked patient account resolves one conversation and sees only messages
  from that conversation.
- [ ] Another patient cannot read the conversation or download its attachment;
  ownership failures remain non-disclosing.
- [ ] Existing `patient_id`, message ordering, and private attachment behavior
  are characterized without changing production code.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/ConversationTest.php tests/Feature/Api/V1/MessagingRatingTest.php`

**Dependencies:** None

**Files likely touched:**

- `tests/Feature/ConversationTest.php`
- `tests/Feature/Api/V1/MessagingRatingTest.php`

**Estimated scope:** Small

## Task 2: Establish the conversation ownership schema

**Description:** Add the additive/transforming migration for account ownership,
Patient history, and deterministic backfill before application code begins
using the new states.

**Acceptance criteria:**

- [ ] `account_user_id` is nullable, uniquely indexed when set, references
  `users`, and existing linked conversations are backfilled from
  `patients.user_id` without changing conversation IDs.
- [ ] `patient_id` becomes nullable and non-unique while retaining an index and
  restricted foreign key so one Patient may have historical threads.
- [ ] Conflicting backfill ownership fails clearly; messages, attachments, and
  legacy context rows are not deleted or rewritten.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Database/ConversationOwnershipMigrationTest.php`

**Dependencies:** Task 1

**Files likely touched:**

- `database/migrations/*_add_account_ownership_to_conversations_table.php`
- `tests/Feature/Database/ConversationOwnershipMigrationTest.php`

**Estimated scope:** Small

## Task 3: Model current and historical conversation states

**Description:** Express account-owned, linked, and detached historical states
through Eloquent relationships and reusable factory states without changing
the mobile API yet.

**Acceptance criteria:**

- [ ] Conversation exposes nullable `account()` and `patient()` relationships;
  User exposes one current Conversation and Patient exposes many historical
  Conversations.
- [ ] Factory states create unlinked current, linked current, and detached
  historical conversations with valid ownership combinations.
- [ ] Tests prove one account cannot own two current rows while one Patient may
  retain multiple historical rows.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/ConversationOwnershipTest.php`

**Dependencies:** Task 2

**Files likely touched:**

- `app/Models/Conversation.php`
- `app/Models/User.php`
- `app/Models/Patient.php`
- `database/factories/ConversationFactory.php`
- `tests/Feature/ConversationOwnershipTest.php`

**Estimated scope:** Medium

## Checkpoint A: Ownership foundation

- [ ] Tasks 1–3 focused tests pass.
- [ ] Existing records are backfilled deterministically without history loss.
- [ ] Valid linked, unlinked, and detached ownership states are representable.
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/ConversationTest.php tests/Feature/ConversationOwnershipTest.php tests/Feature/Database/ConversationOwnershipMigrationTest.php`

## Phase B: Deliver Account-Level Mobile Messaging

## Task 4: Resolve and expose the current account conversation

**Description:** Introduce the canonical current-thread resolver, move
Conversation reads into the account-only API tier, and return explicit linked
or general-inquiry capabilities while retaining singular route paths.

**Acceptance criteria:**

- [ ] An authenticated patient-role account resolves exactly one current thread
  by `account_user_id`; an unlinked account receives or lazily creates a thread
  with `patient_id = null`.
- [ ] Linked and unlinked responses consistently include `access_level`,
  nullable `patient_id`, `can_upload_attachments`, and
  `can_create_context_links = false` without exposing link-review data.
- [ ] Concurrent or archived-thread resolution returns/restores the canonical
  row instead of creating a duplicate; non-patient accounts are rejected.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/UnlinkedConversationTest.php tests/Feature/Api/V1/PatientLinkAccessMatrixTest.php`

**Dependencies:** Task 3

**Files likely touched:**

- `app/Actions/Conversations/ResolveAccountConversation.php`
- `app/Http/Controllers/Api/ConversationController.php`
- `app/Http/Resources/ConversationResource.php`
- `routes/api.php`
- `tests/Feature/Api/V1/UnlinkedConversationTest.php`

**Estimated scope:** Medium

## Task 5: Enforce text-only sending and retire contexts

**Description:** Permit unlinked text messages, prohibit structured contexts
for every account, remove context output, and add the approved per-account send
limit without changing route paths.

**Acceptance criteria:**

- [ ] Linked and unlinked patient accounts may send a server-attributed,
  escaped plain-text body of at most 5,000 characters in their current thread.
- [ ] Any `contexts` input returns HTTP 422 before resolving a Product,
  Appointment, Patient, or Optical Order; new `message_context_links` rows are
  never created and contexts disappear from message responses.
- [ ] The eleventh submission by one account within one minute returns HTTP 429
  without creating a partial Message, while reads retain the general limit.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/UnlinkedConversationTest.php tests/Feature/ConversationTest.php`

**Dependencies:** Task 4

**Files likely touched:**

- `app/Http/Requests/Api/StoreMessageRequest.php`
- `app/Http/Controllers/Api/ConversationController.php`
- `app/Http/Resources/MessageResource.php`
- `routes/api.php`
- `tests/Feature/Api/V1/UnlinkedConversationTest.php`

**Estimated scope:** Medium

## Task 6: Protect linked-only private attachments

**Description:** Preserve the existing private attachment workflow for linked
accounts while making uploads and downloads unavailable to unlinked or detached
accounts.

**Acceptance criteria:**

- [ ] An unlinked upload returns HTTP 422 before a file or Message is stored;
  a valid linked upload retains existing type, size, and private-storage rules.
- [ ] A linked account downloads only attachments from its current
  account-owned Conversation and active Patient link.
- [ ] Cross-account, detached-thread, unlinked, missing-file, and guessed-ID
  download failures return 404 without revealing ownership details.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/ConversationTest.php tests/Feature/Api/V1/UnlinkedConversationTest.php`

**Dependencies:** Task 5

**Files likely touched:**

- `app/Http/Controllers/Api/ConversationController.php`
- `tests/Feature/ConversationTest.php`
- `tests/Feature/Api/V1/UnlinkedConversationTest.php`

**Estimated scope:** Medium

## Checkpoint B: Mobile messaging contract

- [ ] Tasks 4–6 focused tests pass.
- [ ] Unlinked accounts can use only their current text thread.
- [ ] Linked private attachments work; every forbidden attachment path is
  non-disclosing.
- [ ] Route paths and total route count remain unchanged.
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/ConversationTest.php tests/Feature/Api/V1/UnlinkedConversationTest.php tests/Feature/Api/V1/MessagingRatingTest.php tests/Feature/Api/V1/PatientLinkAccessMatrixTest.php tests/Feature/Api/V1/RouteContractTest.php`

## Phase C: Integrate Patient Linking and Unlinking

## Task 7: Associate the current inquiry during Patient linking

**Description:** Reuse one transactional action from both approved linking
entry points so an existing account inquiry becomes Patient-associated without
creating or merging unrelated history.

**Acceptance criteria:**

- [ ] Link-request approval and invitation acceptance set the current
  account-owned Conversation's `patient_id` to the same verified Patient inside
  the Patient-link transaction.
- [ ] Linking does not create a Conversation when the account has none and does
  not claim or merge an older patient-only historical thread.
- [ ] Any association failure rolls back the Patient link, invitation/request
  state, and Conversation change together.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Patients/ReviewPatientLinkRequestTest.php tests/Feature/Api/V1/AcceptPatientInvitationTest.php`

**Dependencies:** Task 4

**Files likely touched:**

- `app/Actions/Conversations/AssociateAccountConversation.php`
- `app/Actions/PatientAccounts/ReviewPatientLinkRequest.php`
- `app/Actions/PatientAccounts/AcceptPatientInvitation.php`
- `tests/Feature/Patients/ReviewPatientLinkRequestTest.php`
- `tests/Feature/Api/V1/AcceptPatientInvitationTest.php`

**Estimated scope:** Medium

## Task 8: Detach mobile ownership during unlinking

**Description:** Extend the existing unlink transaction so the former account
cannot regain Patient-associated messages or files after its tokens are
revoked.

**Acceptance criteria:**

- [ ] Unlinking clears `account_user_id` from the account's Patient-associated
  thread while retaining `patient_id`, messages, attachments, and legacy
  context rows.
- [ ] Token revocation, Patient unlink, Conversation detachment, and audit entry
  commit or roll back together under locks.
- [ ] After reauthentication, the unlinked account receives a new empty inquiry
  and cannot read or download from the detached historical thread.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Patients/ReviewPatientLinkRequestTest.php tests/Feature/Api/V1/UnlinkedConversationTest.php`

**Dependencies:** Tasks 6 and 7

**Files likely touched:**

- `app/Actions/Conversations/DetachAccountConversation.php`
- `app/Actions/PatientAccounts/UnlinkPatientAccount.php`
- `tests/Feature/Patients/ReviewPatientLinkRequestTest.php`
- `tests/Feature/Api/V1/UnlinkedConversationTest.php`

**Estimated scope:** Medium

## Checkpoint C: Link lifecycle safety

- [ ] Tasks 7–8 focused tests pass.
- [ ] Both link entry points associate atomically.
- [ ] Unlinking removes mobile access without deleting clinic history.
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Patients/ReviewPatientLinkRequestTest.php tests/Feature/Api/V1/AcceptPatientInvitationTest.php tests/Feature/Filament/PatientLinkRequestReviewTest.php tests/Feature/Api/V1/UnlinkedConversationTest.php`

## Phase D: Complete Panel Operation and Contract Reconciliation

## Task 9: Adapt the Filament chat and authorization

**Description:** Make the existing chat page safe for nullable Patient
association, expose the approved panel-role capability, and retire context-card
presentation without altering the formal linking workflow.

**Acceptance criteria:**

- [ ] Admin, staff, and optometrist panel roles can list, select, and reply;
  patient accounts cannot enter the panel resource, and routine notifications
  remain staff/admin only.
- [ ] Unlinked conversations show the account name and an unmistakable
  **Unlinked account — general inquiry only** warning without Patient, clinical,
  attachment, or link-bypass controls.
- [ ] Linked/historical threads render messages and permitted attachments
  without eager-loading or displaying structured context cards.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/ConversationResourceTest.php`
- [ ] Manual: sign in as each panel role, inspect one linked and one unlinked
  thread, send a reply, and confirm warning/notification behavior.

**Dependencies:** Tasks 6 and 8

**Files likely touched:**

- `app/Policies/ConversationPolicy.php`
- `app/Filament/Resources/Conversations/ConversationResource.php`
- `app/Filament/Resources/Conversations/Pages/ConversationChatPage.php`
- `resources/views/filament/resources/conversations/pages/conversation-chat-page.blade.php`
- `tests/Feature/Filament/ConversationResourceTest.php`

**Estimated scope:** Medium

## Task 10: Reconcile demo conversation data

**Description:** Update seeded workflow conversations to satisfy account
ownership and preserve the linked clinic demonstration after the schema change.

**Acceptance criteria:**

- [ ] The workflow seeder creates a valid linked current Conversation with both
  account and Patient association and server-valid senders.
- [ ] Re-running the seeder remains idempotent and does not create a second
  current thread for the account.
- [ ] Seed verification covers message history and the new ownership fields.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Seeders/ClinicWorkflowSeederTest.php`

**Dependencies:** Tasks 3 and 9

**Files likely touched:**

- `database/seeders/ClinicWorkflowSeeder.php`
- `tests/Feature/Seeders/ClinicWorkflowSeederTest.php`

**Estimated scope:** Small

## Task 11: Reconcile contracts and run final integration

**Description:** Align the authoritative backend, API, and Android-facing
documentation with tested behavior, then run the complete focused regression
and build checks.

**Acceptance criteria:**

- [ ] Documentation classifies Conversation routes as account-only, defines
  nullable ownership/capabilities and linked-only attachments, removes new
  structured contexts, and preserves the unchanged route count.
- [ ] The Android-facing MVP contract removes context-link input/cards and
  describes text prefilling as presentation-only.
- [ ] The specification, plan, route contract, and implementation status agree;
  no unrelated API, dependency, or deferred feature changed.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/ConversationTest.php tests/Feature/ConversationOwnershipTest.php tests/Feature/Database/ConversationOwnershipMigrationTest.php tests/Feature/Api/V1/UnlinkedConversationTest.php tests/Feature/Api/V1/MessagingRatingTest.php tests/Feature/Api/V1/PatientLinkAccessMatrixTest.php tests/Feature/Api/V1/AcceptPatientInvitationTest.php tests/Feature/Patients/ReviewPatientLinkRequestTest.php tests/Feature/Filament/PatientLinkRequestReviewTest.php tests/Feature/Filament/ConversationResourceTest.php tests/Feature/Api/V1/RouteContractTest.php tests/Feature/Seeders/ClinicWorkflowSeederTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `vendor/bin/sail npm run build`

**Dependencies:** Tasks 9 and 10

**Files likely touched:**

- `docs/BACKEND_CONTEXT.md`
- `docs/API_CONTRACT.md`
- `docs/specs/android-mvp-spec.md`
- `docs/specs/unlinked-account-inquiries-spec.md`
- `tests/Feature/Api/V1/RouteContractTest.php`

**Estimated scope:** Medium

## Checkpoint D: Complete and ready for review

- [ ] Tasks 9–11 focused checks pass.
- [ ] Every specification success criterion is covered by automation or the
  listed Filament manual check.
- [ ] No structured context link is newly created or exposed.
- [ ] No unlinked account can access an attachment or detached thread.
- [ ] All three panel roles can operate Conversations with the approved warning
  and notification split.
- [ ] Final focused regression, Pint, and frontend build pass.

## Dependency Summary

```text
1 -> 2 -> 3 -> 4 -> 5 -> 6
               |          |
               +-> 7 -----+-> 8 -> 9 -> 10 -> 11
```

- Tasks 1–6 are sequential because they establish and consume the shared
  ownership/API contract.
- Task 7 may begin after Task 4, but Task 8 waits for both Tasks 6 and 7.
- Task 9 waits for attachment and unlink behavior so the UI does not guess
  capabilities.
- Documentation and final regression remain last.

## Phase 4 Implementation Gate

This checklist is approved. The user explicitly instructed that implementation
must not start yet, so every application-code task remains unchecked and
blocked until a new implementation instruction is given.
