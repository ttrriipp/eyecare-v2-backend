# Checklist: Direct Messaging Hardening

> Spec: `docs/specs/direct-messaging-hardening-spec.md`
> Plan: `tasks/direct-messaging-hardening-plan.md`
> Status: **proposed**, awaiting owner approval to begin. **No task below has started.**
> All four open questions were resolved 2026-08-15 (clinic-wide watermark; contexts removed
> entirely, not just off the API; Android ready whenever the backend is; notifications stay
> patient↔clinic only) — see the plan's Open Questions section.
>
> 18 tasks in seven phases. No task touches more than five files. Phase 7 (message search)
> was added 2026-08-15, after the original six phases were drafted.

---

## Phase 1: Patient read path

### Task 1: Mark conversation messages read from the mobile API

**Description:** Add the missing write path for `messages.read_at`. A single bulk update
marks every message the caller did not send as read, which makes the already-shipped
`unread_count` field correct for the first time.

**Acceptance criteria:**

- [ ] `App\Actions\Conversations\MarkConversationRead` exists, matching the shape of the
      sibling `ResolveAccountConversation`, and returns the number of rows marked.
- [ ] `POST /api/v1/conversation/messages/read` sits in the account-only tier inside the
      `throttle:api-account` group; no active patient link is required.
- [ ] The update is one statement — messages `where sender_id != reader` and
      `read_at is null` — with no model iteration.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact --filter=Conversation`
- [ ] Marking read drives `unread_count` to 0 on the next `GET /conversation`.
- [ ] The caller's own messages are never marked; a second call returns `marked_count: 0`.
- [ ] An unlinked account can mark read; an account cannot mark another account's thread.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** None.

**Files likely touched:**
- `app/Actions/Conversations/MarkConversationRead.php` *(new)*
- `app/Http/Controllers/Api/ConversationController.php`
- `routes/api.php`
- `tests/Feature/ConversationTest.php`
- `tests/Feature/ConversationOwnershipTest.php`

**Estimated scope:** M (5 files)

### Checkpoint A: Patient unread is correct

- [ ] `unread_count` rises on a staff message and falls to 0 on mark-read.
- [ ] Focused conversation suite green; Pint clean.
- [ ] Review with owner before proceeding to the staff side.

---

## Phase 2: Staff read state

### Task 2: Add the staff read watermark

**Description:** Give the clinic side a read position. Staff never need per-message
receipts, so this is one nullable timestamp plus the two counter helpers that Tasks 3–7
all consume, defined once so they cannot drift.

**Unblocked 2026-08-15:** Open Question 1 resolved — clinic-wide watermark confirmed by the
owner. No per-staff read rows.

**Acceptance criteria:**

- [ ] Migration adds nullable `conversations.staff_last_read_at` after `inbox_archived_at`.
      `messages.read_at` is unchanged.
- [ ] `Conversation` casts it to `datetime` and exposes `unreadForPatient()` and
      `unreadForStaff()`; a null watermark means every patient message is unread.
- [ ] A `withUnreadForStaff()` query scope resolves counts for a whole inbox in one query.
- [ ] `BACKEND_CONTEXT.md`'s `conversations` row documents the new column and the
      deliberate patient/staff asymmetry.

**Verification:**

- [ ] `vendor/bin/sail artisan migrate --env=testing` succeeds and rolls back cleanly.
- [ ] `vendor/bin/sail artisan test --compact --filter=Conversation`
- [ ] Counters are correct for: null watermark, partial watermark, empty thread, and a
      thread where only the reader has spoken (must be 0, not the message count).
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** None (Task 1 is independent).

**Files likely touched:**
- `database/migrations/*_add_staff_read_watermark_to_conversations.php` *(new)*
- `app/Models/Conversation.php`
- `tests/Feature/ConversationReadStatusTest.php` *(new)*
- `docs/BACKEND_CONTEXT.md`

**Estimated scope:** S (4 files)

### Task 3: Stamp the watermark when staff open a thread

**Description:** Record the read position when a staff member selects a conversation,
without letting the 5-second poll re-stamp it on every tick.

**Acceptance criteria:**

- [ ] `selectConversation()` stamps `staff_last_read_at = now()` on the selected thread.
- [ ] A poll tick with an unchanged selection performs no write.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact --filter=ConversationChatPage`
- [ ] Selecting a thread with unread patient messages drops its staff unread count to 0.
- [ ] Re-rendering without changing selection leaves `staff_last_read_at` untouched.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 2.

**Files likely touched:**
- `app/Filament/Resources/Conversations/Pages/ConversationChatPage.php`
- `tests/Feature/ConversationChatPageTest.php`

**Estimated scope:** S (2 files)

### Task 4: Show unread counts in the inbox sidebar

**Description:** Replace the total-message pill with an unread pill. A total message count
is not a work signal; it tells staff how chatty a patient is, not who is waiting.

**Acceptance criteria:**

- [ ] The sidebar pill shows staff-unread count, is hidden at zero, and is visually
      emphasised above zero.
- [ ] Counts come from the Task 2 scope in the list query — no per-row query.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact --filter=ConversationChatPage`
- [ ] A thread with 20 read messages and 0 unread shows no pill.
- [ ] Manual check: send a patient message, confirm the pill appears without reload.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 2, 3.

**Files likely touched:**
- `app/Filament/Resources/Conversations/Pages/ConversationChatPage.php`
- `resources/views/filament/resources/conversations/pages/conversation-chat-page.blade.php`
- `tests/Feature/ConversationChatPageTest.php`

**Estimated scope:** S (3 files)

### Task 5: Order the inbox by most recent activity

**Description:** `conversations()` orders by `latest()` — thread creation. A thread with a
message 30 seconds ago sinks below one opened last month. With activity ordering, the
sidebar's "Started {{ diffForHumans }}" line also becomes misleading and is replaced by a
last-message preview.

**Acceptance criteria:**

- [ ] Ordering is by latest message descending, with `created_at` as the tiebreaker for
      threads that have none.
- [ ] The sidebar shows a last-message preview and its timestamp instead of the thread
      creation date.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact --filter=ConversationChatPage`
- [ ] An older thread with a newer message sorts above a newer, quiet thread.
- [ ] An empty thread still renders without error.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 2.

**Files likely touched:**
- `app/Filament/Resources/Conversations/Pages/ConversationChatPage.php`
- `resources/views/filament/resources/conversations/pages/conversation-chat-page.blade.php`
- `tests/Feature/ConversationChatPageTest.php`

**Estimated scope:** S (3 files)

### Task 6: Wire up inbox archiving

**Description:** `inbox_archived_at` is written by model methods that no reachable UI calls,
and the inbox never filters on it. Relocate the archive action from the unreachable
`EditConversation` to the chat page and make the list honour it. `Message::booted()`
already auto-restores on a new message; this task makes that observable.

**Acceptance criteria:**

- [ ] The inbox hides archived threads by default and exposes a toggle to show them.
- [ ] Archive and Restore actions are available from the chat page header.
- [ ] A new message on an archived thread returns it to the default inbox.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact --filter=ConversationChatPage`
- [ ] Archived thread disappears from the default list and reappears under the toggle.
- [ ] Sending to an archived thread clears `inbox_archived_at`.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 5 (same query and blade region).

**Files likely touched:**
- `app/Filament/Resources/Conversations/Pages/ConversationChatPage.php`
- `resources/views/filament/resources/conversations/pages/conversation-chat-page.blade.php`
- `tests/Feature/ConversationChatPageTest.php`

**Estimated scope:** S (3 files)

### Task 7: Badge unread conversations in the navigation

**Description:** Staff currently cannot tell a thread is waiting without opening the
Conversations page. Add a navigation badge following the established
`AppointmentRequestResource:36-48` pattern.

**Acceptance criteria:**

- [ ] `getNavigationBadge()` counts non-archived conversations with staff-unread messages
      and returns null at zero.
- [ ] `getNavigationBadgeColor()` returns `'warning'`, consistent with Appointment Requests.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact --filter=Conversation`
- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AdminNavigationStructureTest.php`
- [ ] Badge is absent at zero, present and correct above zero, and ignores archived threads.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 2.

**Files likely touched:**
- `app/Filament/Resources/Conversations/ConversationResource.php`
- `tests/Feature/ConversationChatPageTest.php`

**Estimated scope:** XS (2 files)

### Checkpoint B: Feature usable in the clinic

- [ ] Both sides see accurate unread state; inbox orders by activity; archiving works.
- [ ] Focused conversation suite and navigation structure test green; Pint clean.
- [ ] Manual end-to-end: patient sends → badge appears → staff opens → badge clears.
- [ ] Review with owner before proceeding.

---

## Phase 3: Unreachable class removal

### Task 8: Delete the unreachable Conversation pages

**Description:** `ConversationResource::getPages()` registers `index` alone. Four page
classes are unreachable. Out of scope for the completed 2026-08-14 dead-code-removal
project, which covered intake, complaint, and `inventory_movement_statuses` only.

**Acceptance criteria:**

- [ ] `CreateConversation`, `EditConversation`, `ListConversations`, and `ViewConversation`
      are deleted.
- [ ] Each is confirmed to have no remaining referent before deletion.
- [ ] `EditConversation`'s archive action already lives on the chat page (Task 6).

**Verification:**

- [ ] `grep -rn "CreateConversation\|EditConversation\|ListConversations\|ViewConversation" app/ tests/ routes/` returns nothing.
- [ ] `vendor/bin/sail artisan test --compact --filter=Conversation`
- [ ] `vendor/bin/sail artisan about` boots without a resolution error.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 6 (hard — relocation precedes deletion).

**Files likely touched:**
- `app/Filament/Resources/Conversations/Pages/CreateConversation.php` *(delete)*
- `app/Filament/Resources/Conversations/Pages/EditConversation.php` *(delete)*
- `app/Filament/Resources/Conversations/Pages/ListConversations.php` *(delete)*
- `app/Filament/Resources/Conversations/Pages/ViewConversation.php` *(delete)*

**Estimated scope:** S (4 files)

### Task 9: Delete the unreachable Conversation schemas, table, and relation manager

**Description:** `ConversationResource` declares no `getRelations()`, so
`MessagesRelationManager` is unreachable, as are the form, infolist, and table classes that
only the deleted pages consumed. Note that `docs/specs/dead-code-removal-spec.md:106-107`
cited `MessagesRelationManager` as evidence that message attachments are live — that
citation was wrong (the class never renders), though its conclusion holds via four other
references. Correct the historical note rather than restating it.

**Acceptance criteria:**

- [ ] `MessagesRelationManager`, `ConversationForm`, `ConversationInfolist`, and
      `ConversationsTable` are deleted.
- [ ] Message attachments still upload, download, preview, and render in the chat page.
- [ ] `docs/specs/dead-code-removal-spec.md` carries a dated correction to its
      `MessagesRelationManager` citation.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/ConversationTest.php tests/Feature/ConversationChatPageTest.php tests/Feature/ConversationOwnershipTest.php`
- [ ] Manual check: attachment upload and download still work end to end.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 8.

**Files likely touched:**
- `app/Filament/Resources/Conversations/RelationManagers/MessagesRelationManager.php` *(delete)*
- `app/Filament/Resources/Conversations/Schemas/ConversationForm.php` *(delete)*
- `app/Filament/Resources/Conversations/Schemas/ConversationInfolist.php` *(delete)*
- `app/Filament/Resources/Conversations/Tables/ConversationsTable.php` *(delete)*
- `docs/specs/dead-code-removal-spec.md`

**Estimated scope:** M (5 files)

---

## Phase 4: Notifications

### Task 10: Route the patient notification feed

**Description:** `NotificationController` and `NotificationResource` are fully written,
already authorize ownership, and are referenced by zero routes. This is wiring, not new code.

**Acceptance criteria:**

- [ ] Four routes registered in the account-only tier: index, unread count, mark read,
      mark all read.
- [ ] Ownership is enforced — marking another account's notification returns 403.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact --filter=Notification`
- [ ] `vendor/bin/sail artisan route:list --path=api/v1/notifications` lists four routes.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** None.

**Files likely touched:**
- `routes/api.php`
- `tests/Feature/NotificationApiTest.php` *(new)*

**Estimated scope:** XS (2 files)

### Task 11: Notify both parties of new messages

**Description:** Staff→patient is silent today; a patient learns of a reply only by opening
the app. Replace the inlined Filament notification with the unused `NewMessageReceived`
class so there is one definition, notify the patient on a staff reply, and stop notifying
deactivated staff accounts.

**Acceptance criteria:**

- [ ] `ConversationController::notifyStaffOfMessage()` uses `NewMessageReceived`.
- [ ] The staff recipient query filters `is_active`.
- [ ] A staff reply notifies the patient account (`User` already uses `Notifiable`).

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact --filter=Conversation`
- [ ] A patient message notifies active staff only; a deactivated staff account receives none.
- [ ] A staff reply produces a patient notification retrievable via Task 10's index route.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 10.

**Files likely touched:**
- `app/Http/Controllers/Api/ConversationController.php`
- `app/Filament/Resources/Conversations/Pages/ConversationChatPage.php`
- `app/Notifications/NewMessageReceived.php`
- `tests/Feature/ConversationTest.php`

**Estimated scope:** S (4 files)

### Checkpoint C: Both parties are notified

- [ ] Patient and staff each learn of new messages without watching a screen.
- [ ] Focused conversation and notification suites green; Pint clean.
- [ ] Review with owner before proceeding.

---

## Phase 5: Contract reconciliation

### Task 12: Align MessageResource with the published contract

**Description:** The contract documents `sender_type` and `attachments[].download_url`,
neither of which exists, while the resource still emits `contexts` for a feature the
contract says was retired and is rejected with 422.

**Acceptance criteria:**

- [ ] `sender_type` returns `patient` or `staff` (`API_CONTRACT.md:2090, 2114`).
- [ ] `attachments[].download_url` resolves the named route
      `conversation.attachments.download` (`:2100, 2123`).
- [ ] `contexts` is removed from the resource, and the now-unused
      `contextLinks.contextable` eager load is removed from `ConversationChatPage:83-88`
      in the same change.
- [ ] `API_CONTRACT.md:2030, 2140, 2158-2163, 2179, 2426` no longer describe `contexts` as
      a rejected-but-present field; they describe it as removed.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact --filter=Conversation`
- [ ] Response payload matches `API_CONTRACT.md` §15 field-for-field.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** None.

**Files likely touched:**
- `app/Http/Resources/MessageResource.php`
- `app/Filament/Resources/Conversations/Pages/ConversationChatPage.php`
- `tests/Feature/ConversationTest.php`
- `docs/API_CONTRACT.md`

**Estimated scope:** S (4 files)

### Task 13: Delete MessageContextLink and its table

**Description:** Open Question 2 resolved 2026-08-15: structured message contexts are being
removed entirely, not just kept off the API surface. `Message::contextLinks()`, the
`MessageContextLink` model, and the `message_context_links` table (created in
`2026_06_16_132305_rework_messaging_schema.php`) have no reachable caller once Task 12
lands. `StoreMessageRequest`'s `'contexts' => ['prohibited']` rule stays — it is what makes
`UnlinkedConversationTest:86` return 422, and that behaviour is unchanged by this task.

**Acceptance criteria:**

- [ ] `Message::contextLinks()` is removed.
- [ ] `app/Models/MessageContextLink.php` is deleted.
- [ ] A new migration drops `message_context_links`, reversible in its `down()`. The
      original `2026_06_16_132305_rework_messaging_schema.php` migration is left untouched,
      matching the dead-code-removal precedent of an explicit cleanup migration rather than
      rewriting shipped history.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact --filter=Conversation`
- [ ] `vendor/bin/sail artisan migrate --env=testing` rolls back cleanly.
- [ ] `grep -rn MessageContextLink app/` returns nothing.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 12. The response field and eager load must be gone before the table
they read from disappears — never reorder these two.

**Files likely touched:**
- `app/Models/Message.php`
- `app/Models/MessageContextLink.php` *(deleted)*
- `database/migrations/*_drop_message_context_links_table.php` *(new)*

**Estimated scope:** XS (3 files)

### Task 14: Apply the documented send throttle

**Description:** `API_CONTRACT.md:2142` has promised 10 requests/minute on message send
since it was written. The route inherits the 120/minute account bucket.

**Acceptance criteria:**

- [ ] `RateLimiter::for('conversation-send', …)` is defined at 10/min alongside the existing
      five limiters in `AppServiceProvider:44-60`, reusing the shared `apiLimit()` helper.
- [ ] `POST conversation/messages` carries the limiter; other conversation routes do not.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact --filter=Conversation`
- [ ] The 11th send in a minute returns 429 with `Retry-After` and creates no message.
- [ ] Reading messages is unaffected at the same rate.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** None.

**Files likely touched:**
- `app/Providers/AppServiceProvider.php`
- `routes/api.php`
- `tests/Feature/ConversationTest.php`

**Estimated scope:** XS (3 files)

### Task 15: Reconcile the documentation

**Description:** Three documents describe messaging endpoints that do not exist or omit
ones that do.

**Acceptance criteria:**

- [ ] `ANDROID_CONTEXT.md:154-157` uses the singular shipped route shape and lists the
      real mark-read route.
- [ ] `API_CONTRACT.md` §15 documents mark-read and corrects `read_at` to state it is set
      by the bulk endpoint, not per message.
- [ ] `BACKEND_CONTEXT.md` records the route count, `staff_last_read_at`, and the
      patient/staff read-model asymmetry.

**Verification:**

- [ ] `vendor/bin/sail artisan route:list --path=api/v1/conversation` matches the contract
      exactly.
- [ ] Documented route count equals the actual count.

**Dependencies:** Tasks 1, 12, 13, 14.

**Files likely touched:**
- `docs/ANDROID_CONTEXT.md`
- `docs/API_CONTRACT.md`
- `docs/BACKEND_CONTEXT.md`

**Estimated scope:** XS (3 files)

### Checkpoint D: Docs match code

- [ ] Every documented messaging endpoint exists; every existing one is documented.
- [ ] Full focused suite green; Pint clean.

---

## Phase 6: Pagination

> Owner confirmed 2026-08-15 the Android client is ready whenever the backend is (Open
> Question 3), so this no longer needs a separate authorization round. It is still sequenced
> last because it changes the default response shape of a shipped endpoint — the same
> treatment `BACKEND_CONTEXT.md` records for the `appointment_type_id` and two-stage auth
> cutovers — and its acceptance criteria require confirming Android readiness immediately
> before merge, since "ready" was stated ahead of the actual cutover.

### Task 16: Paginate conversation messages

**Description:** `GET /conversation/messages` returns every message a thread has ever held
(`ConversationController:42`), documented as unpaginated at `API_CONTRACT.md:2079`. Fine at
year one, not at year three.

**Acceptance criteria:**

- [ ] Cursor pagination, newest-first, default page size 50, exposing `next_cursor` and
      `has_more`.
- [ ] Composite index `(conversation_id, created_at)` added in the same migration.
- [ ] The Android client is confirmed ready before merge.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact --filter=Conversation`
- [ ] A 200-message thread returns 50 with a working cursor; the last page reports
      `has_more: false`.
- [ ] `vendor/bin/sail artisan migrate --env=testing` rolls back cleanly.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 15.

**Files likely touched:**
- `database/migrations/*_add_conversation_message_index.php` *(new)*
- `app/Http/Controllers/Api/ConversationController.php`
- `tests/Feature/ConversationTest.php`
- `docs/API_CONTRACT.md`

**Estimated scope:** S (4 files)

### Checkpoint E: Pagination shipped

- [ ] Android reads `next_cursor`/`has_more` correctly on a real 200+ message thread.
- [ ] Focused conversation suite green; Pint clean.

---

## Phase 7: Message search

> Added 2026-08-15. Neither party can find a message by content today, only by scrolling —
> nobody asked for this until now, but it rides naturally on Task 16's cursor shape, so it's
> sequenced right after it rather than as a separate later project.

### Task 17: Search conversation messages

**Description:** Add a search endpoint scoped to a single conversation, backed by a MySQL
`FULLTEXT` index on `messages.body`. Results come back newest-first in the same cursor
envelope Task 16 defines (`next_cursor`, `has_more`), so Android writes one paging client,
not two. Chronological order, not relevance-ranked — tuning MySQL's relevance weighting is
its own project, not an MVP requirement.

**Acceptance criteria:**

- [ ] Migration adds a `FULLTEXT` index on `messages.body`.
- [ ] New `app/Actions/Conversations/SearchConversationMessages.php` follows the existing
      `app/Actions/Conversations/` convention (alongside `ResolveAccountConversation`, etc.).
- [ ] The query filters on the resolved, ownership-checked `conversation_id` *before* the
      `whereFullText()` clause — never scans across conversations.
- [ ] `GET /conversation/messages/search?q=` returns matches newest-first, paginated
      identically to Task 16.
- [ ] An empty or whitespace-only `q` returns `422`, not an unfiltered full-text scan.

**Verification:**

- [ ] `vendor/bin/sail artisan test --compact --filter=Conversation`
- [ ] A search for a word present in exactly one message returns exactly that message.
- [ ] A search scoped to conversation A returns nothing from conversation B, even on an
      exact text match — the cross-account leakage case from the plan's risk table.
- [ ] `vendor/bin/sail artisan migrate --env=testing` rolls back cleanly.
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 16 — reuses its cursor envelope; not a technical coupling, a
consistency one.

**Files likely touched:**
- `database/migrations/*_add_fulltext_index_to_messages_body.php` *(new)*
- `app/Actions/Conversations/SearchConversationMessages.php` *(new)*
- `app/Http/Controllers/Api/ConversationController.php`
- `routes/api.php`
- `tests/Feature/ConversationTest.php`

**Estimated scope:** S (5 files)

### Task 18: Document the search endpoint

**Description:** `API_CONTRACT.md` §15 needs the new route, its query parameter, its
response shape, and the known `innodb_ft_min_token_size` limitation (short words like "ok"
silently match nothing) so nobody re-discovers it as a bug later.

**Acceptance criteria:**

- [ ] `API_CONTRACT.md` §15 documents `GET /conversation/messages/search`, its `q` parameter,
      the cursor response shape, and the short-word limitation.

**Verification:**

- [ ] `vendor/bin/sail artisan route:list --path=api/v1/conversation` matches the documented
      route.

**Dependencies:** Task 17.

**Files likely touched:**
- `docs/API_CONTRACT.md`

**Estimated scope:** XS (1 file)

---

## Definition of done

- [ ] Every acceptance criterion above is met.
- [ ] `vendor/bin/sail artisan test --compact --filter=Conversation` is green.
- [ ] `vendor/bin/sail bin pint --format agent` reports no changes.
- [ ] `docs/BACKEND_CONTEXT.md`, `docs/API_CONTRACT.md`, and `docs/ANDROID_CONTEXT.md`
      describe the shipped state.
- [ ] `tasks/plan.md` and `tasks/todo.md` are updated to reflect completion.
