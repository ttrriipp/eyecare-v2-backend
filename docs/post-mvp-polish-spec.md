# Spec: Post-MVP Polish & Adjustments

Status: In Progress — 5/17 tasks complete
Phase: Planning complete, 17 tasks defined

## Assumptions

1. This builds on top of the completed 29-task MVP — adjusting/extending, not rewriting.
2. The Android mobile app is a separate repo; these changes are backend + Filament admin only.
3. The API contract changes (messaging rework) will be coordinated with Developer B but aren't blocked by them.
4. No new Composer/npm dependencies without approval.
5. The demo timeline is the priority — these adjustments strengthen the defense demo.
6. "UX-friendly status management" means Filament table/page actions with confirmation modals.
7. The messaging rework replaces the current conversation model (breaking API change acceptable since mobile app isn't deployed).
8. Feedback stays private (staff + customer only), not public-facing.
9. Soft deletes added to key business models.
10. Existing test suite stays green throughout — no breaking existing tests without replacement.

## Objective

Adjust the completed MVP backend to close quality gaps, improve admin UX for the defense demo, and rework the messaging system to match a real clinic workflow.

Primary outcomes:

- Staff can manage appointments and orders through quick action buttons instead of edit-form dropdowns.
- Messaging becomes a persistent chat thread per customer with flexible context linking.
- Admin can manage visit reasons, categories, and see auto-generated SKUs/slugs.
- Billings get number format and due dates.
- Audit coverage extends to product and user management actions.
- Feedback is explicitly private with staff reply.

## Tech Stack

Same as MVP — no new dependencies.

- PHP 8.5, Laravel 13, Filament 5, Pest 4, MySQL, Sail

## Commands

```
Build frontend:     vendor/bin/sail npm run build
Run tests:          vendor/bin/sail artisan test --compact
Run filtered:       vendor/bin/sail artisan test --compact --filter=SomeName
Fresh seed:         vendor/bin/sail artisan migrate:fresh --seed --no-interaction
Format PHP:         vendor/bin/sail bin pint --dirty --format agent
Route list:         vendor/bin/sail artisan route:list --except-vendor
```

## Boundaries

- **Always:** Run affected tests after each task. Run pint after PHP edits. Keep existing tests green (adapt, don't delete). Update seeders if schema changes.
- **Ask first:** Adding dependencies. Changing auth flow. Removing existing API endpoints.
- **Never:** Break the demo seed flow. Store biometrics. Remove tests without replacement. Hard-delete business records after soft deletes are added.

## Decisions

1. One persistent conversation per customer (not per staff pair).
2. Context links are per-message (polymorphic to Appointment, Order, Product).
3. Soft deletes on 8 business models: Product, ProductVariant, Order, Billing, Appointment, Prescription, Conversation, Feedback.
4. Order/billing numbers are global auto-increment with year prefix.
5. Staff can create appointments and orders from Filament.
6. Feedback is private; no public customer-to-customer visibility. Can be extended to public later with an `is_public` flag if needed.
7. Messaging rework is a breaking API change (acceptable pre-deployment).

## Success Criteria

- [ ] Staff can confirm/reschedule/cancel/complete appointments via table row actions with confirmation modals.
- [ ] Staff can transition order status via table row actions with confirmation modals.
- [ ] Visit reasons are CRUD-manageable by admin in Filament.
- [ ] Categories are CRUD-manageable by admin in Filament.
- [ ] Product slugs auto-generate from name on create, editable on edit.
- [ ] Variant SKUs auto-generate on creation (`VAR-XXXXXX`), editable.
- [ ] Billing records have a `billing_number` (`BIL-YYYY-XXXXXX`) auto-generated and a `due_date` column.
- [ ] Order records have an `order_number` (`ORD-YYYY-XXXXXX`) auto-generated.
- [ ] Key business models have soft deletes.
- [ ] Messaging is reworked: one persistent conversation per customer, context links (product/order/appointment) attachable per message.
- [ ] Chat-style Filament page replaces the table-based conversation resource.
- [ ] Feedback is private; customer submits, staff views and replies. No public visibility.
- [ ] Audit logs cover product changes and user management actions.
- [ ] All new/changed behavior has Pest tests.
- [ ] Existing test suite remains green (adapted where needed).

## Implementation Plan

### Architecture Decisions

- Soft deletes use Laravel's `SoftDeletes` trait; no custom implementation.
- Order/billing number generation uses a `creating` model event with DB-level unique constraint.
- SKU format: `VAR-XXXXXX` (zero-padded global sequence).
- Slug format: `Str::slug($name)` with uniqueness suffix if collision.
- Messaging context links use a polymorphic `message_context_links` table.
- Chat UI uses a custom Filament page with Livewire, not a standard Resource.
- Status actions use Filament table actions calling existing Action classes.

### Dependency Graph

```
Schema Adjustments (soft deletes, columns)
    │
    ├── Auto-generation logic (slugs, SKUs, numbers)
    │
    ├── Admin CRUD (visit reasons, categories)
    │
    ├── Admin create flows (appointments, orders)
    │       │
    │       └── UX status actions (appointments, orders)
    │
    └── Messaging rework (schema → API → chat UI)

Audit extensions ← depends on product/user management existing
Feedback enforcement ← independent
Seeder/test adaptation ← depends on all above
```

### Task List

#### Phase A: Schema & Auto-Generation

##### Task A1: Soft Deletes Migration ✅

**Description:** Add `deleted_at` to business models.

**Acceptance criteria:**
- [x] `products`, `product_variants`, `orders`, `billings`, `appointments`, `prescriptions`, `conversations`, `feedback` tables have `deleted_at`.
- [x] Models use `SoftDeletes` trait.
- [x] Existing queries still work (Eloquent excludes soft-deleted by default).

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact`
- [x] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`

**Dependencies:** None

**Files likely touched:**
- New migration file
- `app/Models/Product.php`
- `app/Models/ProductVariant.php`
- `app/Models/Order.php`
- `app/Models/Billing.php`
- `app/Models/Appointment.php`
- `app/Models/Prescription.php`
- `app/Models/Conversation.php`
- `app/Models/Feedback.php`

**Estimated scope:** S

---

##### Task A2: Order Number & Billing Number ✅

**Description:** Add auto-generated number columns with format `ORD-YYYY-XXXXXX` / `BIL-YYYY-XXXXXX`.

**Acceptance criteria:**
- [x] `orders.order_number` and `billings.billing_number` columns exist, unique, not null.
- [x] Numbers auto-generate on model creation via `creating` boot hook.
- [x] Sequence is global auto-increment with year prefix.
- [x] Existing seeder records get valid numbers.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=Order`
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=Billing`

**Dependencies:** None

**Files likely touched:**
- New migration file
- `app/Models/Order.php`
- `app/Models/Billing.php`
- Seeder updates

**Estimated scope:** S

---

##### Task A3: Billing Due Date

**Description:** Add `due_date` column to billings.

**Acceptance criteria:**
- [x] `billings.due_date` nullable date column exists.
- [x] Staff can set due date when generating billing or editing.
- [x] Filament billing form includes due date field.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=Billing`

**Dependencies:** None

**Files likely touched:**
- New migration file
- `app/Models/Billing.php`
- Billing Filament form schema

**Estimated scope:** S

---

##### Task A4: Product Slug Auto-Generation

**Description:** Add `slug` to products, auto-generated from name.

**Acceptance criteria:**
- [x] `products.slug` unique column exists.
- [x] Auto-generates from name on create via `Str::slug()`.
- [x] Editable on update.
- [x] Product API returns slug.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=Product`

**Dependencies:** None

**Files likely touched:**
- New migration file
- `app/Models/Product.php`
- Filament product form
- `app/Http/Resources/ProductResource.php`

**Estimated scope:** S

---

##### Task A5: Variant SKU Auto-Generation

**Description:** Add auto-generated SKU to product variants.

**Acceptance criteria:**
- [x] `product_variants.sku` unique column exists.
- [x] Auto-generates as `VAR-XXXXXX` (zero-padded global sequence) on create.
- [x] Editable by staff after creation.
- [x] Returned in API responses.

**Verification:**
- [x] Tests pass: `vendor/bin/sail artisan test --compact --filter=Product`

**Dependencies:** None

**Files likely touched:**
- New migration file
- `app/Models/ProductVariant.php`
- Filament product form (variant section)
- `app/Http/Resources/ProductVariantResource.php`

**Estimated scope:** S

---

#### Checkpoint: Phase A

- [x] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [x] `vendor/bin/sail artisan test --compact`
- [x] All business models have soft deletes
- [x] Order/billing numbers generate correctly
- [x] Slugs and SKUs auto-generate

---

#### Phase B: Admin CRUD & Create Flows

##### Task B1: Visit Reason Filament Resource

**Description:** Admin can CRUD visit reasons.

**Acceptance criteria:**
- [ ] Filament resource with list, create, edit pages for visit reasons.
- [ ] Name field required, unique.
- [ ] Only admin/staff can access.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=VisitReason`

**Dependencies:** None

**Files likely touched:**
- New Filament resource + pages
- `tests/Feature/Filament/VisitReasonResourceTest.php`

**Estimated scope:** S

---

##### Task B2: Category Filament Resource

**Description:** Admin can CRUD categories.

**Acceptance criteria:**
- [ ] Filament resource with list, create, edit for categories.
- [ ] Name required, unique.
- [ ] Only admin/staff can access.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=CategoryResource`

**Dependencies:** None

**Files likely touched:**
- New Filament resource + pages
- `tests/Feature/Filament/CategoryResourceTest.php`

**Estimated scope:** S

---

##### Task B3: Admin Create Appointment

**Description:** Staff/admin can create appointments on behalf of walk-in/phone customers.

**Acceptance criteria:**
- [ ] `CreateAppointment` page registered in `AppointmentResource`.
- [ ] Staff selects customer, visit reason, date/time.
- [ ] Created appointment starts as `pending` or `confirmed` (staff choice via status field).
- [ ] Uses existing validation and workflow logic.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=AppointmentResource`

**Dependencies:** Task A1 (soft deletes on appointments)

**Files likely touched:**
- `app/Filament/Resources/Appointments/OrderResource.php`
- `app/Filament/Resources/Appointments/Pages/CreateAppointment.php`
- Appointment form schema
- Test updates

**Estimated scope:** S

---

##### Task B4: Admin Create Order

**Description:** Staff/admin can create orders on behalf of walk-in customers.

**Acceptance criteria:**
- [ ] `CreateOrder` page registered in `OrderResource`.
- [ ] Staff selects customer, adds order items (variant + lens type + quantity).
- [ ] Price snapshots from current variant pricing.
- [ ] Created order starts as `requested` or `under_review`.
- [ ] Reuses existing order item snapshot logic.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=OrderResource`

**Dependencies:** Tasks A1, A2 (soft deletes, order numbers)

**Files likely touched:**
- `app/Filament/Resources/Orders/OrderResource.php`
- `app/Filament/Resources/Orders/Pages/CreateOrder.php`
- Order form schema
- Test updates

**Estimated scope:** M

---

#### Checkpoint: Phase B

- [ ] `vendor/bin/sail artisan test --compact`
- [ ] Visit reasons and categories are manageable in Filament
- [ ] Staff can create appointments and orders from admin panel

---

#### Phase C: UX-Friendly Status Actions

##### Task C1: Appointment Status Actions

**Description:** Replace edit-form status changes with table row actions and page header actions.

**Acceptance criteria:**
- [ ] Table row actions: Confirm, Reschedule, Cancel, Complete (with confirmation modals).
- [ ] Actions only show for valid transitions (can't confirm already-completed).
- [ ] Uses existing `UpdateAppointmentStatus` action.
- [ ] SMS notification records still created for confirm/reschedule/cancel.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=AppointmentResource`

**Dependencies:** Task B3

**Files likely touched:**
- `app/Filament/Resources/Appointments/Tables/AppointmentsTable.php`
- `app/Filament/Resources/Appointments/Pages/EditAppointment.php`
- Test updates

**Estimated scope:** M

---

##### Task C2: Order Status Actions

**Description:** Replace edit-form order status changes with table row actions.

**Acceptance criteria:**
- [ ] Table row actions for each valid transition: Review, Confirm, Preparing, Ready for Pickup, Complete, Cancel.
- [ ] Actions show contextually based on current status.
- [ ] Uses existing `UpdateOrderStatus` action.
- [ ] Inventory and billing side-effects still trigger correctly.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=OrderResource`

**Dependencies:** Task B4

**Files likely touched:**
- `app/Filament/Resources/Orders/Tables/OrdersTable.php`
- `app/Filament/Resources/Orders/Pages/EditOrder.php`
- Test updates

**Estimated scope:** M

---

#### Checkpoint: Phase C

- [ ] `vendor/bin/sail artisan test --compact`
- [ ] Appointment status transitions work via action buttons
- [ ] Order status transitions work via action buttons
- [ ] SMS and inventory side-effects still fire correctly

---

#### Phase D: Messaging Rework

##### Task D1: New Messaging Schema

**Description:** Replace current conversation model with persistent single-conversation-per-customer + per-message context links.

**Acceptance criteria:**
- [ ] Each customer has exactly one conversation (created on first message or registration).
- [ ] `message_context_links` table: `message_id`, `contextable_type`, `contextable_id` (polymorphic).
- [ ] Supported context types: `Appointment`, `Order`, `Product`.
- [ ] Old `conversations.appointment_id` / `order_id` FKs removed.
- [ ] Existing message and attachment structure preserved.

**Verification:**
- [ ] Fresh seed succeeds: `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`

**Dependencies:** Task A1 (soft deletes on conversations)

**Files likely touched:**
- New migration file(s)
- `app/Models/Conversation.php`
- `app/Models/Message.php`
- New `app/Models/MessageContextLink.php`
- Factory updates

**Estimated scope:** M

---

##### Task D2: Messaging API Rework

**Description:** Rewrite customer messaging API for persistent conversation + context links.

**Acceptance criteria:**
- [ ] `GET /conversations` → returns the customer's single conversation (or creates it).
- [ ] `POST /conversations/{conversation}/messages` accepts optional `contexts[]` array (each with `type` and `id`).
- [ ] `GET /conversations/{conversation}/messages` returns messages with their context links.
- [ ] Attachments still work as before.
- [ ] Authorization: customer can only access their own conversation.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=Messaging`

**Dependencies:** Task D1

**Files likely touched:**
- `app/Http/Controllers/Api/ConversationController.php`
- `app/Http/Requests/Api/StoreMessageRequest.php`
- `app/Http/Resources/MessageResource.php`
- `routes/api.php`
- `tests/Feature/Api/MessagingTest.php` (rewritten)

**Estimated scope:** M

---

##### Task D3: Filament Chat Page

**Description:** Replace table-based conversation resource with a chat-style custom page.

**Acceptance criteria:**
- [ ] Staff sees a list of customer conversations (sidebar or panel).
- [ ] Selecting a conversation shows messages as a chat thread (newest at bottom).
- [ ] Staff can reply inline.
- [ ] Context links displayed as badges/tags on messages.
- [ ] Attachment metadata visible.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=ConversationResource`

**Dependencies:** Task D2

**Files likely touched:**
- New custom Filament page (replaces existing resource)
- Blade/Livewire view for chat layout
- `tests/Feature/Filament/ConversationResourceTest.php` (rewritten)

**Estimated scope:** L

---

#### Checkpoint: Phase D

- [ ] `vendor/bin/sail artisan test --compact --filter=Messaging`
- [ ] `vendor/bin/sail artisan test --compact --filter=ConversationResource`
- [ ] Customer has single persistent conversation
- [ ] Context links attach to individual messages
- [ ] Chat UI works in Filament

---

#### Phase E: Audit, Feedback & Finalization

##### Task E1: Audit Log Extensions

**Description:** Add audit hooks for product and user management.

**Acceptance criteria:**
- [ ] Product create/update/delete logs an audit entry.
- [ ] User create/role-change logs an audit entry.
- [ ] Uses existing `CreateAuditLog` action.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=AuditLog`

**Dependencies:** None (existing audit infrastructure)

**Files likely touched:**
- Relevant Filament resources or model observers
- `tests/Feature/AuditLogRecordingTest.php` (extended)

**Estimated scope:** S

---

##### Task E2: Feedback Privacy Enforcement

**Description:** Confirm feedback is private-only and staff reply is visible only to the submitting customer.

**Acceptance criteria:**
- [ ] Feedback API `index` returns only the authenticated customer's own feedback.
- [ ] Staff reply field exists and is settable from Filament.
- [ ] No public listing of other customers' feedback.
- [ ] Filament feedback view shows all feedback for staff review.

**Verification:**
- [ ] Tests pass: `vendor/bin/sail artisan test --compact --filter=Feedback`

**Dependencies:** None

**Files likely touched:**
- `app/Http/Controllers/Api/FeedbackController.php`
- `app/Filament/Resources/Feedback/FeedbackResource.php`
- Test verification

**Estimated scope:** S

---

##### Task E3: Seeder & Test Adaptation

**Description:** Update seeders and fix any tests broken by schema changes across all phases.

**Acceptance criteria:**
- [ ] `migrate:fresh --seed` succeeds with all new columns, numbers, soft deletes.
- [ ] Demo workflow seed includes order/billing numbers, due dates, messaging context links.
- [ ] All tests pass.
- [ ] Pint clean.

**Verification:**
- [ ] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** All previous tasks

**Files likely touched:**
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/ClinicWorkflowSeeder.php`
- `database/seeders/CatalogSeeder.php`
- `database/factories/*.php`
- Any test files needing adaptation

**Estimated scope:** M

---

#### Checkpoint: Complete

- [ ] `vendor/bin/sail artisan migrate:fresh --seed --no-interaction`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `vendor/bin/sail npm run build`
- [ ] `vendor/bin/sail artisan route:list --except-vendor`
- [ ] Full seeded defense flow works without manual database edits

---

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Messaging rework breaks existing tests | Med | Replace tests entirely with new ones matching new schema |
| Soft deletes break existing queries | Low | Eloquent handles automatically; add `withTrashed()` only where needed |
| Order/billing number collisions | Low | DB-level unique index + model-level generation in `creating` hook |
| Chat UI scope creep (too much frontend work) | Med | Keep it functional for demo, not pixel-perfect; use Livewire not custom JS |
| Status action transitions get complex | Low | Reuse existing Action classes; only add UI layer |

## Summary

| Phase | Tasks | Effort |
|-------|-------|--------|
| A: Schema & Auto-Generation | 5 | S each |
| B: Admin CRUD & Create Flows | 4 | S-M |
| C: UX Status Actions | 2 | M each |
| D: Messaging Rework | 3 | M-L |
| E: Audit, Feedback, Finalization | 3 | S-M |
| **Total** | **17** | |

## Review Gate

Plan approved. Implementation executes one task at a time with listed tests and checkpoints. Split tasks further if they grow beyond five files or one focused session.
