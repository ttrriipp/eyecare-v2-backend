# Specification: Direct Messaging Hardening

> Status: **proposed**, awaiting owner approval. Drafted 2026-08-15.
> Plan: `tasks/direct-messaging-hardening-plan.md`
> Checklist: `tasks/direct-messaging-hardening-todo.md`
>
> Scope: patient↔clinic direct messaging (`conversations`, `messages`) across the
> mobile API and the Filament `Conversations` chat page.

## Problem

Messaging shipped as a working transport and stopped there. The read-status layer
was designed, migrated, cast, serialized, and consumed — but never written to.
Everything downstream of that column is decorative.

| Capability | Documented | Built | Works |
|---|---|---|---|
| Patient sends a message | ✅ | ✅ | ✅ |
| Staff replies | ✅ | ✅ | ✅ |
| Patient sees unread count | ✅ | ✅ | ❌ never decrements |
| Patient marks messages read | ✅ `ANDROID_CONTEXT.md:157` | ❌ | ❌ no endpoint exists |
| Staff sees which threads are waiting | — | ❌ | ❌ shows total, not unread |
| Staff inbox ordered by activity | — | ❌ | ❌ ordered by thread creation |
| Inbox archive | ✅ `BACKEND_CONTEXT.md:521` | ⚠️ model only | ❌ unreachable + unfiltered |
| Patient notified of a staff reply | ⚠️ implied | ⚠️ controller exists | ❌ not routed |
| `sender_type` / `download_url` on messages | ✅ contract | ❌ | ❌ |
| Send throttle 10/min | ✅ contract | ❌ | ❌ inherits 120/min |

## Evidence

- **`messages.read_at` is a dead column.** Migrated (`2026_06_10_134403`), cast
  (`app/Models/Message.php:74`), serialized (`app/Http/Resources/MessageResource.php:24`),
  counted (`app/Http/Resources/ConversationResource.php:29-33`). The only write in the
  entire codebase is `database/seeders/ClinicWorkflowSeeder.php:236`. **`unread_count`
  can only ever increase.**
- **A documented endpoint was never built.** `docs/ANDROID_CONTEXT.md:154-157` lists four
  `/conversations/{id}/…` routes. None exist; the shipped routes are singular
  `/conversation/…` (`routes/api.php:82-84`), and mark-read was never built at all.
- **The staff inbox has no work signal.** `ConversationChatPage::conversations()` (`:59-66`)
  orders by `latest()` — `created_at`, i.e. when the thread was *opened* — and the sidebar
  pill shows `messages_count` (blade `:38-42`), a total, not an unread count.
- **Inbox archive is inert.** `ConversationChatPage::conversations()` never filters
  `inbox_archived_at`, and the only page exposing `archiveInbox`
  (`Pages/EditConversation.php:19`) is not in `ConversationResource::getPages()`, which
  registers `index` alone (`:24-29`).
- **Eight unreachable Filament files.** `CreateConversation`, `EditConversation`,
  `ListConversations`, `ViewConversation`, `MessagesRelationManager`, `ConversationForm`,
  `ConversationInfolist`, `ConversationsTable`. `ConversationResource` declares no
  `getRelations()`, so the relation manager is unreachable too. Out of scope for the
  completed dead-code-removal project (2026-08-14), which covered intake, complaint, and
  `inventory_movement_statuses` only.
- **The notification feed is dead code.** `app/Http/Controllers/Api/NotificationController.php`
  and `NotificationResource` are fully written and referenced by zero routes.
  `App\Notifications\NewMessageReceived` is likewise unused —
  `ConversationController::notifyStaffOfMessage()` (`:105-121`) inlines a Filament
  notification instead, and loads **every** staff and admin user with no `is_active`
  filter, so deactivated accounts still receive them.
- **No real-time infrastructure.** No `config/broadcasting.php`, no Reverb/Pusher in
  `composer.json`, no Echo in `package.json`. Staff UI polls at `wire:poll.5s`.

## Resolved scope

| Decision | Choice | Rationale |
|---|---|---|
| Real-time transport | **Out of scope.** Keep `wire:poll.5s` + client polling | One thread per patient at single-clinic volume. Reverb means a new daemon, TLS/proxy config, and a Sanctum-token auth path — real deployment weight for sub-second latency nobody perceives. Revisit only on a concrete latency complaint. |
| Patient read model | Keep per-message `messages.read_at` | Already in the shipped contract and `MessageResource`; changing its shape forces an Android cutover for no gain. |
| Staff read model | New `conversations.staff_last_read_at` watermark | Staff never need per-message receipts, and a watermark avoids writing N rows every time an inbox thread is opened. The asymmetry is deliberate and must be documented. |
| Read granularity | Conversation-level bulk mark | One thread per account. Per-message endpoints buy nothing. |
| Message pagination | **Deferred, requires coordinated Android cutover** | Changing the default response shape is breaking. Sequenced last behind its own authorization gate. |
| Inbox archive | **Wire it up**, then delete the unreachable pages | It is documented as a shipped lifecycle pattern; either honour that or retract the claim. Wiring is cheaper than a doc retraction plus a column drop. |
| Push notifications (FCM) | **Out of scope** | No push infra exists. Routing the in-app feed is the 80% at 5% of the cost. |
| Attachment behaviour | **Out of scope** | Upload/download work correctly. Only the missing `download_url` field is in scope. |
| Message search | **In scope**, added 2026-08-15 | Neither party can find a message by content today, only by scrolling. MySQL `FULLTEXT` index on `messages.body`, scoped to one conversation, returned in the cursor shape Task 16 establishes. Chronological order, not relevance-ranked — an MVP, not a search product. |

## Out of scope

Reverb/WebSockets · FCM or any push transport · typing indicators · multiple threads per
patient · message editing or deletion · staff-initiated new threads · attachment behaviour
beyond the missing `download_url` · encryption of message bodies (`body` is plain text
today; a real decision, but separate from read status).

## Acceptance

1. A patient marking their thread read drives `unread_count` to 0, and it stays 0 until
   staff send again.
2. Staff can see, from the navigation and the inbox list, which threads have unanswered
   patient messages, ordered by most recent activity.
3. Archiving removes a thread from the default inbox; a new message restores it.
4. Both parties learn about new messages without watching a screen.
5. `API_CONTRACT.md` §15, `ANDROID_CONTEXT.md`, and `BACKEND_CONTEXT.md` describe the
   endpoints that actually exist.
6. No unreachable Filament class remains under `app/Filament/Resources/Conversations/`.
7. A message can be found by content, scoped to its own conversation, without paging through
   the entire history by hand.
