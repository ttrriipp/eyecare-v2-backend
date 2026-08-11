# Spec: Unlinked Mobile Account Clinic Inquiries

**Status:** Approved
**Phase:** Specify, Plan, and Tasks approved — implementation explicitly deferred
**Date:** 2026-08-11
**Approved:** 2026-08-11

## Objective

Allow an authenticated patient-role mobile account to contact the clinic before
the account is verified and linked to a Patient record. The feature extends the
existing single-thread messaging experience instead of creating a separate
inquiry subsystem.

The primary users are:

- unlinked patient-role mobile accounts asking general clinic questions;
- linked patient accounts continuing to use the same mobile chat experience;
- staff, administrators, and optometrists responding through the Filament
  Conversations page; and
- patient-linking workflows that promote or detach conversation ownership
  safely.

Success means an unlinked account can exchange general text messages with the
clinic without receiving any Patient, appointment, clinical, prescription,
optical-order, or billing access. Linking may safely associate the account's
current conversation with the verified Patient. Unlinking must immediately
remove mobile access to the patient-associated thread without deleting its
history.

## Approved Product Decisions and Assumptions

1. Reuse `conversations`, `messages`, and attachments; do not introduce
   separate inquiry/ticket tables.
2. A patient-role mobile account has at most one current account-owned
   conversation. `GET /api/v1/conversation` continues to create it lazily.
3. An unlinked conversation is account-owned and has no Patient association.
4. A linked conversation is account-owned and may also be associated with the
   verified Patient.
5. A Patient may retain more than one historical conversation after an account
   unlink/relink cycle. The mobile account still sees at most its one current
   account-owned conversation.
6. Structured message context linking is retired for both linked and unlinked
   accounts. Patients identify a frame, appointment, or order in plain text.
   Android may prefill a product name, SKU, appointment number, or order number
   into that text without creating a database relationship.
7. Existing `message_context_links` rows are preserved temporarily but are no
   longer accepted, returned by the mobile API, or rendered as context cards.
   Physical table/model removal is a separate cleanup after confirming that no
   deployed consumer or required historical workflow uses them.
8. Unlinked accounts cannot upload or download message attachments.
   Linked-account messaging retains the existing private attachment capability.
9. Staff, administrators, and optometrists may operate the Conversations page.
   Routine new-message notifications go to staff and administrators so
   optometrists may participate without being interrupted by every inquiry.
10. The existing singular `/api/v1/conversation` routes remain unchanged and
    move from the active-link route group to the authenticated account-only
    group. No new mobile endpoint is introduced.
11. Conversation ownership fields and capabilities are additive, but retiring
    the accepted/returned `contexts` field is an approved coordinated contract
    change. Android must stop sending and rendering structured contexts at the
    same release boundary; route paths remain stable.
12. No new dependency is required.

## Scope

### Included

- account-owned conversation persistence;
- linked and unlinked conversation capabilities;
- safe association when a patient link is approved or an invitation accepted;
- safe detachment when an account is unlinked;
- unchanged singular mobile routes with additive resource fields;
- panel-user identification and warning for unlinked conversations;
- retirement of structured message context linking;
- per-account authorization and non-disclosing attachment checks;
- focused API, linking, migration, security, and Filament tests; and
- reconciliation of the backend context and API contract documentation.

### Deferred or excluded

- a separate ticketing system, departments, assignment queues, priorities,
  service-level targets, subjects, or inquiry categories stored in the
  database;
- automated medical advice, chatbot responses, or clinical triage;
- group conversations or multiple concurrent threads for one mobile account;
- push notifications, typing indicators, online presence, and real-time socket
  delivery;
- allowing unlinked accounts to upload files or attach identity documents;
- allowing staff to link a Patient directly from the chat page;
- modifying the separate Android repository; and
- changing privacy-request retention and erasure policy; and
- physically dropping the legacy `message_context_links` table or model.

## Capability Matrix

| Capability | Unlinked account | Linked account | Authorized panel user |
|---|---:|---:|---:|
| Open own current conversation | Yes | Yes | N/A |
| Read messages in own current conversation | Yes | Yes | Authorized panel view |
| Send text up to 5,000 characters | Yes | Yes | Yes |
| Create structured context links | No | No | No |
| Upload/download attachments | No | Yes | Authorized thread only |
| Access Patient or clinical records through messaging | No | No new access | Existing panel permissions only |
| Link a Patient from the chat page | No | N/A | No |

Messaging never grants access to another domain resource. Product,
appointment, and order references in message text are informational only and
must not be interpreted as authorization.

## User Workflows

### Unlinked account starts an inquiry

1. The authenticated patient-role account opens **Contact Clinic**.
2. Android calls `GET /api/v1/conversation`.
3. The backend returns the account's current conversation or creates one with
   `account_user_id` set and `patient_id = null`.
4. The response identifies the access level as `general_inquiry` and reports
   that attachments are unavailable.
   Android also identifies the channel as general information only—not an
   emergency service or a clinical consultation.
5. The account sends a text message through the existing message endpoint.
6. Staff and administrators receive the existing database notification, and
   every authorized panel role sees an
   **Unlinked account — general inquiry only** warning in Filament.
7. An authorized panel user may respond, but the page provides no Patient
   chart, appointment, prescription, order, or billing context for that
   account.

Suggested inquiry topics such as appointments, services/pricing, frames, and
account linking are Android presentation shortcuts. They are not persisted as
a new backend category in this release.

### Account becomes linked

The link may be activated through either staff approval of a
`PatientLinkRequest` or acceptance of a clinic invitation.

Inside the same transaction that activates `patients.user_id`:

1. locate the account-owned conversation, if one exists;
2. set its `patient_id` to the verified Patient;
3. do not rewrite, copy, or delete earlier messages; and
4. preserve server-derived account ownership.

If the account has no conversation, no conversation is created during linking;
the next conversation request creates it with both ownership references. If
the Patient has an older patient-only conversation, it remains historical and
is not merged automatically into the account's current thread.

After linking, the conversation resource reports `linked_patient` capabilities.
Normal linked-account attachment rules then apply.

### Account is unlinked

Inside the same transaction that clears `patients.user_id`:

1. revoke the account's existing mobile tokens using the current unlink flow;
2. clear `account_user_id` from the conversation associated with that account
   and Patient;
3. retain `patient_id`, all messages, attachments, and any legacy context-link
   rows as clinic history; and
4. retain the existing unlink audit event without message bodies or attachment
   data in audit metadata.

The detached historical conversation is no longer accessible to the mobile
account. If that account authenticates again while unlinked and contacts the
clinic, it receives a new general-inquiry conversation. No historical clinical
messages are exposed merely because an account was previously linked.

## Data Model

### `conversations`

Change the ownership model to:

| Column | Rule |
|---|---|
| `id` | Existing primary key |
| `account_user_id` | Nullable FK to `users`, unique while non-null, deletion restricted |
| `patient_id` | Nullable FK to `patients`, indexed but no longer unique, deletion restricted |
| `created_at`, `updated_at`, `deleted_at` | Existing timestamps and archive support |

Application invariant: every non-archived conversation has at least one of
`account_user_id` or `patient_id`. The valid states are:

| State | `account_user_id` | `patient_id` |
|---|---:|---:|
| Unlinked general inquiry | Set | Null |
| Current linked conversation | Set | Set |
| Historical thread after unlink | Null | Set |

`account_user_id` is the mobile authorization boundary. `patient_id` provides
clinic-side association and must never be accepted from the mobile request.
Whether the account is currently linked is derived from the active
`patients.user_id` relationship, not merely from a non-null historical
`conversation.patient_id` value.

### Existing-data migration

The migration must be deterministic and additive:

1. add nullable `account_user_id`;
2. make `patient_id` nullable and remove its unique constraint;
3. for each existing conversation whose Patient has `user_id`, copy that
   value into `account_user_id`;
4. retain conversations for Patients without accounts as patient-only
   historical conversations; and
5. preserve all conversation IDs, messages, attachments, legacy context links,
   and timestamps.

The migration must fail rather than silently choose an owner if existing data
violates the one-linked-account invariant.

### Relationships

- `Conversation::account()` belongs to `User` through `account_user_id`.
- `Conversation::patient()` remains nullable.
- `User::conversation()` returns the current account-owned conversation.
- `Patient::conversations()` is a `HasMany`, allowing detached historical
  threads after an unlink/relink cycle.
- `Message::sender()` and all existing message relationships remain unchanged.

## API Contract

### Route placement

These routes keep their paths and methods but move to the authenticated
account-only route group:

```text
GET  /api/v1/conversation
GET  /api/v1/conversation/messages
POST /api/v1/conversation/messages
GET  /api/v1/conversation/attachments/{attachment}
```

They require a valid Sanctum token and a patient-role account. They do not
require an active Patient link. Moving the routes does not change the total
route count.

### Conversation response

The conversation response is additive and consistent for linked and unlinked
accounts:

```json
{
  "data": {
    "id": 42,
    "patient_id": null,
    "access_level": "general_inquiry",
    "capabilities": {
      "can_upload_attachments": false,
      "can_create_context_links": false
    },
    "unread_count": 0,
    "created_at": "2026-08-11T10:00:00.000000Z"
  }
}
```

For a linked account:

- `patient_id` contains that account's verified Patient ID;
- `access_level` is `linked_patient`;
- `can_upload_attachments` is `true`; and
- `can_create_context_links` remains `false`.

`access_level` and the capability object are derived from the authenticated
account's current active Patient link. A stale or historical `patient_id` must
never grant linked capabilities.

The API must not expose the registration identity snapshot, candidate Patients,
link-review notes, medical data, billing data, or staff-only identifiers in the
conversation response.

### Reading messages

`GET /api/v1/conversation/messages` resolves the conversation exclusively by
the authenticated `account_user_id`. It never accepts or trusts a Patient or
conversation ID from the client. If no current conversation exists, it returns
an empty message collection through the same lazy conversation resolution used
by `GET /conversation`.

### Sending a message

The text request shape is:

```json
{
  "body": "Do you have the Vista Classic frame, SKU FRM-001, available?"
}
```

Rules:

- `body` is required, trimmed, plain text, and at most 5,000 characters;
- `sender_id`, `account_user_id`, and `patient_id` are always server-derived;
- `contexts` and all structured context fields are prohibited for both linked
  and unlinked accounts and return HTTP 422 when submitted;
- Android may prefill safe display text such as a product name/SKU or the
  account's already-visible appointment/order number into `body`; and
- stored message bodies are rendered through normal escaped Blade/Android text
  output, never as trusted HTML.

An unlinked request containing an attachment returns HTTP 422 with a
field-level validation error. Structured context input never triggers a lookup
of a Product, Appointment, Patient, or Optical Order identifier.

### Attachment download

An attachment is downloadable only when all are true:

1. the requester is a linked patient-role account;
2. the attachment belongs to the requester's current account-owned
   conversation; and
3. the file exists in private storage.

Every ownership failure returns 404 so attachment identifiers cannot reveal
another conversation's existence. Unlinked accounts receive the same
non-disclosing result.

### Rate limiting

- Conversation reads remain under the existing account API limit.
- `POST /api/v1/conversation/messages` receives a dedicated limit of 10
  submissions per account per minute.
- A throttled request returns the application's normal HTTP 429 response and
  does not create a partial message or attachment.

## Filament Contract

The Conversations page must:

- be available to `staff`, `admin`, and `optometrist` panel roles;
- list account-owned current conversations and patient-only historical
  conversations without assuming `patient_id` is present;
- show the account's display name for an unlinked conversation;
- show a prominent **Unlinked account — general inquiry only** badge and
  warning when `patient_id` is null;
- not show clinical, appointment, prescription, order, billing, or attachment
  controls for an unlinked conversation;
- allow every authorized panel role to send a text reply with the existing
  5,000-character boundary;
- stop rendering structured context cards while preserving legacy rows in the
  database; and
- never offer a shortcut that bypasses the formal patient-link approval or
  invitation flow.

Routine new-message notifications go to staff and administrators. Optometrists
may open and operate the resource but are not notified of every inquiry. No
panel-user mutation endpoint is added to the patient mobile API.

## Authorization and Threat Model

### Trust boundaries

- authenticated mobile message and attachment input;
- account-to-Patient link approval and invitation acceptance;
- account unlinking and token revocation;
- private attachment identifiers and files; and
- staff replies rendered in the mobile application.

### Protected assets

- Patient identity and existence;
- appointment, encounter, prescription, Optical Order, and billing data;
- private message and attachment contents; and
- clinic staff identity and actions.

### Required abuse-case protections

| Abuse case | Required protection |
|---|---|
| Guess another account's conversation or message ID | Singular account-resolved route; no client conversation ID |
| Submit guessed resource IDs through legacy context fields | Prohibit structured contexts before any identifier lookup |
| Download an attachment from another or formerly linked thread | Resolve through current account ownership and active link; return 404 |
| Retain clinical messages after staff unlinks the account | Revoke tokens and detach account ownership transactionally |
| Impersonate staff or another patient | Derive `sender_id` from authenticated actor |
| Spam clinic messaging | Dedicated per-account POST throttle and body-size limit |
| Inject executable markup | Treat messages as plain text and escape output |
| Infer link candidates or Patient existence | Never expose candidates or internal linking metadata in messaging responses |

The backend cannot determine whether arbitrary free text contains clinical
information. The Filament warning and absence of Patient context are therefore
mandatory operational controls for panel users replying to unlinked accounts.

## Tech Stack

- PHP 8.5
- Laravel 13
- Laravel Sanctum 4
- Filament 5 and Livewire 4
- MySQL through Laravel Sail
- Pest 4 and PHPUnit 12
- Laravel Pint 1

No package or dependency change is expected.

## Commands

All commands run through Sail:

```bash
vendor/bin/sail up -d
vendor/bin/sail artisan migrate
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/UnlinkedConversationTest.php
vendor/bin/sail artisan test --compact tests/Feature/ConversationTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/PatientLinkAccessMatrixTest.php
vendor/bin/sail artisan test --compact tests/Feature/Filament/ConversationResourceTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php
vendor/bin/sail bin pint --dirty --format agent
```

Run the smallest affected test set after each task. Run the broader messaging,
linking, and Filament tests at the final verification checkpoint.

## Project Structure

Expected implementation areas, subject to the approved plan:

```text
database/migrations/                    Conversation ownership migration
app/Models/                             Conversation/User/Patient relationships
app/Actions/PatientAccounts/            Link, invitation, and unlink transitions
app/Http/Controllers/Api/               Singular conversation behavior
app/Http/Requests/Api/                  Conditional message validation
app/Http/Resources/                     Additive conversation capabilities
app/Policies/                           Panel conversation authorization
app/Filament/Resources/Conversations/   Panel chat and warning states
routes/api.php                           Account-only route placement and throttle
tests/Feature/Api/V1/                   Contract, ownership, and abuse-case tests
tests/Feature/Filament/                 Panel UI authorization and states
docs/                                   Reconciled backend and API contracts
```

No new top-level application directory is introduced.

## Code Style

Implementation follows existing Laravel action and relationship conventions,
uses explicit parameter and return types, and derives identity server-side:

```php
public function resolveForAccount(User $account): Conversation
{
    return DB::transaction(fn (): Conversation => Conversation::query()
        ->firstOrCreate(
            ['account_user_id' => $account->id],
            ['patient_id' => $account->patient?->id],
        ));
}
```

The final implementation may use a dedicated action instead of this exact
snippet when required for locking or transition safety. Controllers remain
thin, Form Requests validate the external boundary, API Resources define the
response, and link/unlink mutations remain transactional.

## Testing Strategy

Use Pest feature tests and factories. No standalone verification script or
Tinker-created records are needed.

### Migration and model tests

- existing linked conversations receive the correct `account_user_id`;
- patient-only historical conversations remain intact;
- one account cannot own two current conversations;
- one Patient may retain multiple historical conversations; and
- messages, attachments, legacy context rows, and IDs survive migration.

### API contract tests

- unauthenticated requests return 401;
- authenticated non-patient accounts cannot use the mobile conversation API;
- unlinked accounts can open, read, and send text in only their conversation;
- unlinked conversation responses contain no Patient data and advertise the
  restricted capabilities;
- linked responses preserve existing fields and capabilities;
- unlinked attachments fail without identifier disclosure;
- structured context input is rejected for linked and unlinked accounts
  without resolving the supplied identifier;
- cross-account message and attachment access returns 404; and
- route count and paths remain unchanged.

### Link lifecycle tests

- link-request approval associates the current account conversation;
- invitation acceptance associates the current account conversation;
- linking does not invent or merge unrelated historical messages;
- unlinking revokes tokens and detaches account ownership atomically;
- a reauthenticated unlinked account receives a new empty inquiry rather than
  its former patient-associated thread; and
- transaction failure leaves both patient link and conversation ownership
  unchanged.

### Filament tests

- staff, admin, and optometrist panel roles can see and reply to unlinked
  inquiries;
- an unlinked inquiry displays the warning and no Patient context;
- structured context cards are no longer rendered;
- patient accounts cannot access the panel resource; and
- reply validation and sender attribution are server-enforced.

## Boundaries

### Always

- Preserve existing message history and identifiers during migration.
- Derive conversation, sender, and Patient ownership on the server.
- Keep unlinked messaging separate from Patient/clinical authorization.
- Apply link and unlink changes transactionally with appropriate row locks.
- Return non-disclosing errors for ownership failures.
- Store attachments privately and escape message text.
- Update contract documentation and focused tests with implementation.

### Ask first

- Change the proposed one-current-conversation-per-account model.
- Permit unlinked attachments or restore structured context linking.
- Add a new mobile route or introduce another breaking contract change beyond
  the approved retirement of structured contexts.
- Add a package, real-time transport, automated reply, or external messaging
  integration.
- Change the account-link, unlink, token-revocation, or privacy-retention
  policies.
- Expand panel access beyond the three approved panel roles.

### Never

- Automatically link an account from matching name, phone, email, or birth
  date.
- Accept `account_user_id`, `patient_id`, or `sender_id` from a mobile request.
- Expose link candidates, clinical records, internal notes, or billing data to
  an unlinked account.
- Let an unlinked account retrieve attachments from a current or former
  patient conversation.
- Create new structured message context links.
- Delete or merge message history as a side effect of linking.
- Log message bodies, attachment contents, contact values, or clinical
  narrative in audit metadata.

## Success Criteria

- [ ] An authenticated unlinked patient-role account receives 200 from the
  singular conversation and message GET routes.
- [ ] The same account can send a valid text message and an authorized panel
  user can reply.
- [ ] Another mobile account cannot read that conversation, messages, or files.
- [ ] Unlinked accounts cannot upload or download attachments.
- [ ] Linked and unlinked accounts cannot create structured context links.
- [ ] The API clearly reports `general_inquiry` versus `linked_patient`
  capabilities without exposing unrelated Patient data.
- [ ] Link-request approval and invitation acceptance associate the existing
  account thread with the verified Patient.
- [ ] Unlinking revokes tokens and makes the patient-associated thread
  inaccessible to that account without deleting clinic history.
- [ ] Existing linked conversations and Android route paths continue to work.
- [ ] Every authorized panel role sees an unmistakable unlinked warning and no
  clinical context controls.
- [ ] Focused migration, API, linking, authorization, security, route-contract,
  and Filament tests pass.
- [ ] Pint reports no formatting changes required for modified PHP files.
- [ ] Backend and API contract documentation reflect the shipped behavior.

## Approval Record

The specification and all five product decisions were approved on 2026-08-11:

1. unlinking detaches mobile ownership while preserving the Patient-associated
   thread for clinic history;
2. structured message context linking is retired;
3. attachments require an active Patient link;
4. all three panel roles may reply, while routine notifications go to
   staff/admin; and
5. message submissions are limited to 10 per account per minute.

The approved Phase 2 plan is in `tasks/plan.md`, and the approved Phase 3
checklist is in `tasks/todo.md`. Phase 4 implementation is explicitly deferred
until the user gives a new implementation instruction.
