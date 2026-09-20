# Implementation Plan: Mobile Accessory Order Requests

**Specification:** `docs/specs/mobile-accessory-order-requests-spec.md`
**Status:** Updated for approved decisions; implementation deferred
**Planning date:** 2026-09-20
**Decision date:** 2026-09-20

## Overview

Add a request-first mobile accessory purchase journey on top of the canonical
Optical Order, Billing, inventory, and verified-rating domains. Pending requests
do not reserve stock. Staff acceptance creates and commits one pending-payment
Optical Order; a private proof submitted within 30 minutes moves it to staff
payment review; acceptance queues normal fulfillment, while rejection or expiry
reuses cancellation and exact inventory reversal.

## Architecture decisions

1. **Dedicated request aggregate.** Add `AccessoryOrderRequest` and immutable
   request items. Do not reuse appointment requests or retired commerce tables.
2. **Canonical order only after acceptance.** The accepted request points to one
   `JobOrder`; existing order/billing/inventory snapshots remain authoritative.
3. **Two additive pre-fulfillment statuses.** `pending_payment` and
   `payment_review` precede the current `queued` lifecycle without changing
   staff-created order defaults.
4. **No server cart or reservation subsystem.** Android owns the editable cart.
   Acceptance commits stock through current lot-aware order movements.
5. **One private proof.** A dedicated proof record/disk keeps payment evidence
   separate from messages and BillingPayment, which remains accepted money only.
6. **Contract addition, not replacement.** New accessory/request/proof routes
   and additive Optical Order fields preserve existing v1 consumers.
7. **Reuse verified ratings.** Extend catalog aggregation/filtering over the
   existing rating records; defer the internal `FrameRating` rename.
8. **Separate clinical and retail reads.** Prescription UI calls the curated
   accessory catalog placement; prescription resources remain clinical only.
9. **Manual discount resolution.** Patients may declare Senior Citizen/PWD
   status, but authorized reviewers verify it and use existing Optical Order
   discount controls. No product eligibility rules engine or discount-document
   upload is added.
10. **No pending-request timer.** One pending request is allowed per account;
    it remains until staff acts or the patient cancels it.
11. **Deployment-owned payment destination.** The clinic GCash details come
    from runtime configuration and are never committed to source.

## Dependency graph

```text
Approved specification
    -> request/proof schema + typed states
        -> accessory catalog and request submission
            -> staff request review + canonical order conversion
                -> proof upload + staff verification
                    -> expiry + notifications + patient tracking
                        -> curated placement + rating filters
                            -> contract/docs/full verification
```

## Phase 1: Foundation

### Task 1: Add the immutable order-request aggregate

**Description:** Add request/item schema, typed status, models, relationships,
factories, request numbering, and invariants without exposing routes.

**Acceptance criteria:**

- Request rows preserve account, Patient, status, catalog subtotal, discount
  declaration, resolution, and unique resulting-order linkage; items preserve
  live variant linkage and snapshots.
- Request/item history is non-deletable through application UI and monetary
  fields use two-decimal casts.
- Model/factory tests prove numbering, relationships, casts, and one resulting
  order per request.

**Verification:**

- `vendor/bin/sail artisan test --compact tests/Feature/OrderRequests/AccessoryOrderRequestModelTest.php`
- `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** None

**Files likely touched:** migration(s), `app/Enums/AccessoryOrderRequestStatus.php`,
`app/Models/AccessoryOrderRequest.php`, request-item model, two factories, one
focused test. Split schema and model commits if the slice exceeds five files.

**Estimated scope:** Medium, split into schema and model sub-slices

### Task 2: Add pending-payment order and proof state

**Description:** Extend canonical Optical Orders with the two pre-fulfillment
states/deadline and add the private one-proof aggregate and storage disk.

**Acceptance criteria:**

- Existing JobOrders still default to `queued`; mobile orders may use
  `pending_payment` and `payment_review` with a nullable deadline.
- One private proof can belong to one order and records submission/review
  metadata without becoming a BillingPayment.
- The transition map admits only the specification's paths and the proof disk
  is private under local and configurable remote drivers.

**Verification:**

- `vendor/bin/sail artisan test --compact tests/Feature/OrderRequests/OrderPaymentStateTest.php tests/Feature/OrderRequests/PaymentProofStorageTest.php`
- `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 1

**Files likely touched:** migration(s), `JobOrderStatus`, `JobOrder`, proof
status/model/factory, filesystem config, focused tests. Split state and storage
into separate commits.

**Estimated scope:** Medium, split into state and storage sub-slices

## Checkpoint A: Domain foundation

- [ ] New migrations apply and roll back cleanly.
- [ ] Existing staff-created orders remain queued and existing transition tests pass.
- [ ] Request and proof ownership/uniqueness constraints are database-backed.
- [ ] No legacy commerce table is referenced.

## Phase 2: Patient catalog and request journey

### Task 3: Expose the patient-safe accessory catalog

**Description:** Add active-link-only accessory list/detail resources with
patient-safe availability, images, pagination, search, and base sorting.

**Acceptance criteria:**

- Only active accessory Products/variants appear; exact stock, lots, expiry,
  cost, and internal fields never appear.
- Availability uses usable expiry-tracked stock and returns only the closed
  patient-safe enum.
- List/detail ownership tier, validation, pagination, and deterministic ordering
  match the API contract.

**Verification:**

- `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AccessoryCatalogTest.php`
- `vendor/bin/sail artisan route:list --path=api/v1/accessories --except-vendor`
- `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Checkpoint A

**Files likely touched:** controller, API resource(s), form request or validation
object, routes, focused API test.

**Estimated scope:** Medium

### Task 4: Submit and read an owned multi-item request

**Description:** Add request submission/list/detail/cancel endpoints and the
transactional submission action.

**Acceptance criteria:**

- A linked patient submits 1-20 distinct active accessory variants with
  quantities 1-5; all prices/subtotals/snapshots are server-derived, and an
  optional bounded Senior Citizen/PWD declaration promises no discount.
- Submission changes no stock and creates no order, billing, or payment row;
  one pending request per account is enforced under a lock.
- List/detail/cancel are ownership-safe, paginated where applicable, and return
  stable response/error shapes.

**Verification:**

- `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AccessoryOrderRequestTest.php tests/Feature/OrderRequests/SubmitAccessoryOrderRequestTest.php`
- `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 1 and 3

**Files likely touched:** store form request, submission/cancellation actions,
controller, resource, routes, focused API/action tests. Implement submission and
read/cancel as separate commits.

**Estimated scope:** Medium, split into two vertical sub-slices

## Checkpoint B: Request contract

- [ ] Android can browse accessories and submit/list/view/cancel a request.
- [ ] Request submission has zero inventory or canonical-commerce side effects.
- [ ] Cross-account access is consistently `404`.
- [ ] Duplicate variants, client prices, non-accessories, inactive/unusable
      variants, and a second pending request are rejected.

## Phase 3: Staff decision and canonical conversion

### Task 5: Build the Order Requests Filament resource

**Description:** Add the Optical-group queue and read-only detail surface before
enabling irreversible acceptance.

**Acceptance criteria:**

- Pending requests sort first and show immutable patient/items/subtotal data,
  the discount declaration, and current availability.
- Only staff/admin reviewers see commerce decision actions; optometrist-only
  accounts cannot accept or reject.
- The resource exposes no create/edit/delete/bulk mutation path.

**Verification:**

- `vendor/bin/sail artisan test --compact tests/Feature/Filament/AccessoryOrderRequestResourceTest.php`
- `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 4

**Files likely touched:** policy, resource, table, schema/infolist, list/view
pages, focused Filament test. Split table/view from authorization if needed.

**Estimated scope:** Medium

### Task 6: Accept or reject a request atomically

**Description:** Add staff actions that either reject the immutable request or
convert it into one pending-payment JobOrder with billing and committed stock.

**Acceptance criteria:**

- Acceptance re-locks/revalidates account link, state, catalog lifecycle, and
  usable stock; the reviewer resolves any declared discount, confirms the final
  amount, creates exactly one order/bill, and commits exact FEFO stock.
- Replay returns the existing accepted result and creates no duplicate order,
  billing, inventory, audit, or notification effects.
- Rejection requires a reason and produces no order/billing/inventory side
  effects; staff-created Optical Order behavior remains unchanged.
- Discount handling reuses existing Optical Order controls and authorization;
  no product-eligibility inference or new discount-document workflow exists.

**Verification:**

- `vendor/bin/sail artisan test --compact tests/Feature/OrderRequests/ReviewAccessoryOrderRequestTest.php tests/Feature/OpticalOrders/CreateOpticalOrderTest.php tests/Feature/Inventory/InventoryAndPrivacyCharacterizationTest.php`
- `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 2 and 5

**Files likely touched:** acceptance/rejection actions, shared optical-order
builder or narrow mobile builder, inventory status guard, Filament actions,
focused concurrency/idempotency tests.

**Estimated scope:** Medium, split acceptance and UI wiring

## Checkpoint C: Commerce integrity

- [ ] Accepted request creates one canonical order and one active bill.
- [ ] Any discount declaration is manually resolved before the final payable
      amount and 30-minute timer are confirmed.
- [ ] Inventory is committed once from deterministic usable lots.
- [ ] Unavailable stock fails atomically with the request still pending.
- [ ] Existing direct Optical Order and cancellation suites remain green.

## Phase 4: Payment proof and expiration

### Task 7: Upload one owned private payment proof

**Description:** Add the multipart patient endpoint, private storage action, and
additive Optical Order payment fields/instructions.

**Acceptance criteria:**

- Only the owner of an unexpired `pending_payment` order may upload one valid
  JPG/JPEG/PNG proof within file/dimension caps and with sender/reference
  metadata.
- Successful upload changes the order to `payment_review`; late, foreign,
  invalid, or duplicate uploads create no second object/row and do not extend
  the deadline.
- API responses never expose proof paths or reviewer/internal fields, and a
  separate per-account upload throttle bounds multipart abuse.

**Verification:**

- `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/OrderPaymentProofTest.php tests/Feature/OrderRequests/PaymentProofStorageTest.php tests/Feature/Api/V1/OpticalCommercePrivacyTest.php`
- `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 6

**Files likely touched:** upload form request/action, API controller/route,
OpticalOrder resource, storage tests, API tests.

**Estimated scope:** Medium

### Task 8: Review payment proofs in Filament

**Description:** Extend Optical Orders with payment-stage tabs, protected proof
access, and accept/reject actions.

**Acceptance criteria:**

- Authorized staff/admin can download a private proof with attachment/nosniff
  headers and inspect the expected order total; optometrist-only and unrelated
  users cannot access the file or actions.
- Acceptance records exactly one full posted GCash payment and moves the order
  to `queued` without a duplicate payment notification or reused accepted
  GCash reference.
- Rejection requires a reason, marks proof rejected, cancels the order, restores
  exact stock, and voids the unpaid bill atomically.

**Verification:**

- `vendor/bin/sail artisan test --compact tests/Feature/Filament/OrderPaymentProofReviewTest.php tests/Feature/BillingRecords/PaymentLifecycleTest.php tests/Feature/Inventory/InventoryAndPrivacyCharacterizationTest.php tests/Feature/JobOrders/ContactLensJobOrderCancellationTest.php`
- `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 7

**Files likely touched:** proof policy/access controllers, review actions,
Optical Order table/page, payment action integration, focused Filament/action tests.

**Estimated scope:** Medium, split protected access and decisions

### Task 9: Expire unpaid accepted orders safely

**Description:** Add an idempotent command/action and every-minute schedule for
expired `pending_payment` orders only.

**Acceptance criteria:**

- Expiration locks candidates, cancels each order once, reverses exact stock,
  and voids unpaid billing.
- `payment_review`, queued, paid, cancelled, and terminal orders are never
  expired by the command.
- Scheduling uses `withoutOverlapping()` and repeated runs are harmless.

**Verification:**

- `vendor/bin/sail artisan test --compact tests/Feature/OrderRequests/ExpireUnpaidAccessoryOrdersTest.php tests/Feature/Security/PilotDeploymentPreflightTest.php`
- `vendor/bin/sail artisan schedule:list`
- `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 8

**Files likely touched:** expiration action, Artisan command, console schedule,
factory state, focused scheduler/action test.

**Estimated scope:** Medium

## Checkpoint D: Payment lifecycle

- [ ] The 30-minute timer starts only on staff acceptance.
- [ ] Timely proof permanently stops automatic expiration.
- [ ] Payment acceptance is exactly-once and queues existing fulfillment.
- [ ] Proof rejection and no-proof expiry restore inventory exactly once.

## Phase 5: Discovery, notifications, and contract handoff

### Task 10: Add request/payment notifications

**Description:** Add after-commit, deduplicated patient and staff notifications
for request submission/decision, proof submission/decision, and expiry.

**Acceptance criteria:**

- Only active staff/admin recipients get operational bells; optometrist-only
  accounts are excluded.
- Patient notifications contain closed kinds/mobile actions and link to the
  request or resulting order as appropriate.
- Bodies/audits contain no item details, proof metadata, GCash identifiers,
  sender names, or rejection narrative.

**Verification:**

- `vendor/bin/sail artisan test --compact tests/Feature/Notifications/AccessoryOrderNotificationTest.php tests/Feature/Notifications/AdminPatientActionNotificationTest.php tests/Feature/Notifications/PatientClinicActionNotificationTest.php`
- `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Tasks 6-9

**Files likely touched:** patient/admin notification actions, notification enums,
mobile action/resource mapping, focused tests.

**Estimated scope:** Medium

### Task 11: Add rating filters and prescription placement

**Description:** Extend the accessory catalog with database aggregate rating
sort/filter behavior and staff-curated Care Accessories placement.

**Acceptance criteria:**

- Average/count include all star values, unrated remains `null`, and rating,
  most-rated, minimum-rating, and rated/unrated queries are deterministic.
- Staff can mark only accessory Products for prescription placement; the
  placement query returns active/in-stock patient-safe resources.
- Existing frame aggregate and item-rating behavior remains compatible; no
  prescription measurements are read and no prescription link is created.

**Verification:**

- `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AccessoryCatalogRatingFilterTest.php tests/Feature/Api/V1/FrameRatingTest.php tests/Feature/Filament/ProductListActionTest.php tests/Feature/Filament/VariantFormVisibilityTest.php`
- `vendor/bin/sail bin pint --dirty --format agent`

**Dependencies:** Task 3

**Files likely touched:** featured flag migration/model/form, accessory query and
resource, rating query helpers, focused API/Filament tests.

**Estimated scope:** Medium

### Task 12: Reconcile contracts and run final verification

**Description:** Freeze the Android handoff, update authoritative docs, and run
focused/full regression checks without implementing Android UI here.

**Acceptance criteria:**

- `API_CONTRACT.md` documents every route, payload, state, error, timer, privacy
  boundary, and deployment ordering; `BACKEND_CONTEXT.md` matches schema/actions.
- Route and privacy contract tests reflect the additive API and existing v1
  routes remain compatible.
- Focused suites, full suite, Pint, frontend build when needed, and diff checks
  pass before implementation is called complete.

**Verification:**

- `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php tests/Feature/Api/V1/OpticalCommercePrivacyTest.php`
- `vendor/bin/sail artisan test --compact`
- `vendor/bin/sail bin pint --dirty --format agent`
- `vendor/bin/sail npm run build`
- `git diff --check`

**Dependencies:** Tasks 1-11

**Files likely touched:** `docs/API_CONTRACT.md`, `docs/BACKEND_CONTEXT.md`,
route/privacy tests, plan/checklist status.

**Estimated scope:** Medium

## Checkpoint E: Implementation complete

- [ ] All specification success criteria are demonstrated by tests.
- [ ] Professor/project owner has reviewed the final request/order distinction.
- [ ] Backend and Android teams share one frozen v1 contract.
- [ ] Deployment has a configured private proof disk and clinic GCash details.
- [ ] Scheduler and queue workers are operational.
- [ ] Full verification is green and documentation matches runtime behavior.

## Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Patient pays for an unavailable item | High | Do not reveal payment instructions until locked staff acceptance commits stock. |
| Concurrent acceptances oversell an expiry-tracked accessory | High | Reuse deterministic variant/lot locks and FEFO commitment in one transaction. |
| Expiry races proof upload | High | Both lock the same JobOrder; only one valid state transition commits. |
| Proof screenshot is fake or duplicated | High | Treat it as evidence only; reviewer checks the real clinic GCash ledger/reference and accepted references cannot be reused. |
| Payment recorded twice on replay | High | Unique proof/order relation, locked billing, no existing posted payment, and idempotent action tests. |
| Cancellation restores the wrong lots | High | Reuse existing lot-linked commitment/reversal movements and regression tests. |
| Existing staff orders become payment-gated | High | Keep their default `queued`; only accepted mobile requests use new statuses. |
| Product ratings scope grows into a risky rename | Medium | Reuse existing verified-rating storage and defer internal renaming explicitly. |
| Private proof becomes publicly accessible | High | Dedicated private disk, generated names, authenticated reads, privacy tests, deployment preflight. |
| Manual proof review becomes an operational bottleneck | Medium | Dedicated payment-review tab and staff bell notification; measure review time during pilot. |
| Manual discount decision is applied inconsistently | High | Show the declaration during review, retain existing discount authorization, record the confirmed order/billing amounts, and require clinic compliance review before production. |

## Parallelization opportunities

- After Checkpoint A, accessory catalog work (Task 3) and request API work that
  depends only on Task 1 may proceed independently once resource shapes are
  frozen.
- After Task 7 freezes proof fields, protected staff proof viewing and
  notification payload tests may be prepared independently.
- Documentation and Android work must wait for the final backend contract; no
  parallel consumer assumptions before Task 12.

Schema migrations, shared state transitions, canonical order creation, payment
recording, and expiration must remain sequential because they mutate the same
aggregate boundaries.

## Decision status and remaining deployment inputs

1. Approved: request-first state diagram, multi-item requests, one pending
   request per account, one proof submission, patient cancellation, and no
   pre-acceptance request expiry.
2. Approved: manual reviewer resolution of declared Senior Citizen/PWD status;
   no product-level eligibility rules engine or discount-document upload.
3. Before production: deployment supplies clinic-owned GCash configuration,
   private proof storage, and reviewer roles.
4. Before Android integration: freeze the implemented response shapes and keep
   the editable cart local to Android.
