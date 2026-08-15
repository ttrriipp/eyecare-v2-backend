# Implementation Plan: Direct Messaging Hardening

> Spec: `docs/specs/direct-messaging-hardening-spec.md` (shipped 2026-08-15)
> Checklist: `tasks/direct-messaging-hardening-todo.md`
> Status: **shipped**, 2026-08-15. All 18 tasks complete.

## Overview

Messaging works as a transport but has no read state, no staff work signal, and no
notification path, and no way to find a message by content. `messages.read_at` is written
by nothing outside a seeder, so the mobile `unread_count` only ever increases. This plan
closes read status on both sides, gives staff an inbox that surfaces waiting threads,
routes the already-written notification feed, reconciles three documentation files with
the shipped routes, removes eight unreachable Filament classes, retires message contexts
outright, and adds full-text search scoped to a single conversation. Real-time transport
is explicitly rejected; polling is retained.

## Architecture decisions

- **Asymmetric read model.** Patient side keeps per-message `messages.read_at` — it is
  already in the shipped contract and `MessageResource`, so changing its shape would force
  an Android cutover for nothing. Staff side gets a `conversations.staff_last_read_at`
  watermark, because staff never need per-message receipts and a watermark avoids writing
  N rows every time someone opens a thread.
- **Bulk, conversation-level marking.** One thread per account makes per-message read
  endpoints pure overhead. `MarkConversationRead` issues one `UPDATE`, no model events,
  no N+1, and is idempotent by construction.
- **Polling stays.** Rejecting Reverb is a decision, not an omission — see the spec's
  scope table. Do not re-litigate it inside implementation.
- **Archive gets wired rather than removed.** `BACKEND_CONTEXT.md:521` documents it as a
  shipped lifecycle pattern. Wiring costs less than retracting the doc and dropping a column.
- **Deletion follows relocation.** `EditConversation` holds the only `archiveInbox` call
  site. Its action moves to the chat page (Task 6) before the file is deleted (Task 8),
  never the reverse.
- **Contexts are retired, not hidden.** Open Question 2 resolved 2026-08-15: message
  contexts are being removed entirely, not just kept off the API surface. Task 12 drops the
  field; Task 13 drops `MessageContextLink`, its relation, and its table.
- **Search is chronological, not ranked.** MySQL `FULLTEXT` supports relevance scoring, but
  tuning it is its own project. T17 orders matches newest-first, the same mental model as
  the rest of the thread — a deliberate MVP choice, not an oversight.
- **Search reuses the pagination shape, not the pagination code.** T17 returns
  `next_cursor`/`has_more` in the same envelope T16 defines, so Android writes one paging
  client instead of two. That is why search is sequenced after pagination — consistency,
  not a technical dependency.

## Dependency graph

```
T1  MarkConversationRead + route          ← patient slice, no schema needed
      │
      ▼                                    ← CHECKPOINT A (patient unread is correct)
T2  staff_last_read_at migration + model helpers
      │
      ├──► T3  chat page marks read on select
      │          │
      │          ▼
      │    T4  unread pill in the sidebar
      │          │
      ├──────────┼──► T5  inbox ordered by last message
      │          │
      │          └──► T6  archive filter, toggle, and action
      │                     │
      ▼                     │
T7  navigation badge        │
      │                     │
      ▼                     ▼               ← CHECKPOINT B (feature usable in clinic)
T8  delete unreachable Pages (depends on T6)
T9  delete unreachable Schemas/Tables/RelationManager
      │
      ▼
T10 route the notification feed
T11 unify NewMessageReceived + notify patient + scope to active staff
      │
      ▼                                     ← CHECKPOINT C (both parties notified)
T12 MessageResource: sender_type, download_url, drop contexts field
      │
      ▼
T13 delete MessageContextLink, its relation, and its table
T14 conversation-send throttle
T15 documentation reconciliation
      │
      ▼                                     ← CHECKPOINT D (docs match code)
T16 cursor pagination — authorized; confirm Android readiness before merge
      │
      ▼                                     ← CHECKPOINT E (pagination shipped)
T17 FULLTEXT index + search action + endpoint (reuses T16's cursor shape)
T18 document the search endpoint
```

## Slicing rationale

T1 is a complete vertical slice on its own: the patient-side bug is fixed end to end
without touching schema, because `messages.read_at` already exists. That is why it comes
first — highest value, lowest risk, no migration.

T2 is the one deliberately thin foundation task. It exists because T3–T7 all consume the
same two counter helpers, and defining them five times inline is how they drift. It is
verifiable in isolation through unit tests on the model.

T8/T9 are split purely to respect the five-file ceiling; both are deletions of classes
proven unreachable by `ConversationResource::getPages()`.

T12/T13 split for the same reason and for a second one: T12 is a reversible API contract
change (drop a response key, drop an eager load); T13 is an irreversible schema drop. Mixing
a same-deploy-revertable change with a migration that deletes a table is worse than two
small commits, and T12 already sits at four files without it.

T17/T18 split for the same file-cap reason. T17 alone — migration, action, controller,
route, test — is already five files; folding in the doc update would push it to six.

## Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| `wire:poll.5s` re-stamps `staff_last_read_at` every tick, so a thread never reads as unread while open | High — silently defeats T3 | Write only when `selectedConversationId` actually changes, not on every render. Explicit acceptance criterion and test in T3. |
| Clinic-wide watermark means one staff member opening a thread clears it for everyone | Medium — shared-inbox ambiguity | Confirmed acceptable by owner 2026-08-15 (Open Question 1). Single-clinic with 2–3 staff; revisit only if staffing grows. |
| Deleting `EditConversation` before relocating its archive action loses the only call site | Medium | T8 declares a hard dependency on T6. |
| Navigation badge adds a count query to every panel page load | Low | Same indexed scope as the inbox list; badge returns null at zero, matching `AppointmentRequestResource:36-48`. |
| `AdminNavigationStructureTest` locks group and item order and may trip on badge changes | Low | Re-run it in T7's verification, not just the Conversation suite. |
| T12 drops `contexts` while `ConversationChatPage:83-88` still eager-loads `contextLinks.contextable` | Medium — removing the field without the eager load is a silent N+1 | Both edits land in T12, in that order within the same change. |
| T13 drops the `message_context_links` table while T12's field/eager-load removal hasn't shipped | High — the relation would still be reachable while the backing data is gone | T13 declares a hard dependency on T12; never reorder them. |
| T16 changes the default response shape of `GET /conversation/messages` | High — breaks the Android client | Owner confirmed 2026-08-15 the Android side is ready whenever the backend is (Open Question 3); no separate authorization ask remains. T16's acceptance criteria still require confirming Android readiness immediately before merge, since "ready" was stated ahead of the actual cutover. |
| An unscoped `FULLTEXT` query could leak matches from another patient's conversation | High — cross-account data exposure | T17 requires the query to filter on the resolved, ownership-checked `conversation_id` before the `whereFullText()` clause, never after. Explicit test: search in conversation A must return nothing from conversation B even on an exact text match. |
| MySQL's default `innodb_ft_min_token_size` (3) silently drops short-word matches (e.g. searching "ok") | Low — confusing empty results, not incorrect ones | Documented as a known MVP limitation in T18, not solved by app code. |

## Open questions — resolved 2026-08-15

1. **Is one clinic-wide staff watermark correct, or does each staff member need their own
   read state?** **Resolved: clinic-wide**, as recommended. T2 is unblocked.
2. **Do `MessageContextLink` and its table die with the `contexts` API field?**
   **Resolved: yes, entirely.** T12 removes the API surface; T13 (new) deletes the relation,
   model, and table in a dedicated migration.
3. **When is the Android client ready to consume cursor pagination?** **Resolved: ready
   whenever the backend is.** T16 no longer needs a separate authorization round; its
   acceptance criteria still require confirming readiness immediately before merge, since
   this answer precedes the actual cutover by an unknown interval.
4. Should `NewMessageReceived` also fire for staff→staff activity (e.g. transfer), or stay
   patient↔clinic only? **Resolved: patient↔clinic only.** T11's acceptance criteria already
   assumed this; no change needed there.

## Verification

Per repo convention, after every task:

```
vendor/bin/sail artisan test --compact --filter=Conversation
vendor/bin/sail bin pint --dirty --format agent
```

T7 additionally requires `tests/Feature/Filament/AdminNavigationStructureTest.php`.
T10–T11 additionally require the new `tests/Feature/NotificationApiTest.php`.
T17 additionally requires `vendor/bin/sail artisan migrate --env=testing` to confirm the
`FULLTEXT` index migration rolls back cleanly.

## Suggested commit sequence

1. `feat: mark conversation messages read from the mobile API` (T1)
2. `feat: add staff read watermark to conversations` (T2)
3. `feat: surface unread conversations to staff` (T3–T5)
4. `feat: wire conversation inbox archiving` (T6)
5. `feat: badge unread conversations in the navigation` (T7)
6. `refactor: remove unreachable conversation resource classes` (T8–T9)
7. `feat: route the patient notification feed` (T10–T11)
8. `fix: reconcile the conversation API with its contract` (T12–T15)
9. `feat: paginate conversation messages` (T16)
10. `feat: search conversation messages` (T17–T18)
