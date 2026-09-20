# Task Checklist: Mobile Accessory Order Requests

**Status:** Decisions approved; implementation deferred
**Specification:** `docs/specs/mobile-accessory-order-requests-spec.md`
**Plan:** `tasks/mobile-accessory-order-requests-plan.md`

## Review gates

- [ ] Project owner authorizes implementation after reviewing the updated
      specification, plan, and checklist.
- [x] Professor confirms that the request-first workflow matches the required
      “Order Request” concept.
- [x] Use manual reviewer verification for declared Senior Citizen/PWD status;
      add no product eligibility rules engine or discount-document upload.
- [x] Allow one pending request per account, one proof submission per accepted
      order, patient cancellation while pending, and no pending-request expiry.
- [ ] Record the deployment-owned GCash account and private proof-storage config.

## Execution rules

- Use Laravel Boost `search-docs` before each Laravel, Filament, or Pest slice.
- Use `vendor/bin/sail artisan make:* --no-interaction` for generated classes.
- Add a failing focused Pest expectation before changing behavior.
- Implement tasks in dependency order and stop for each checkpoint review.
- Run `vendor/bin/sail bin pint --dirty --format agent` after PHP changes.
- Do not touch unrelated existing working-tree changes.
- Do not implement Android code in this repository.
- Do not write to legacy `orders`, `order_items`, `payments`, or `billings`.

## Phase 1: Foundation

### Task 1: Immutable order-request aggregate

- [ ] Add request/item tables with keys, indexes, catalog subtotal, discount
      declaration, snapshots, and unique resulting-order linkage.
- [ ] Add typed status, models, relationships, factories, and request numbering.
- [ ] Prove casts, history, ownership links, and uniqueness with focused tests.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OrderRequests/AccessoryOrderRequestModelTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

### Task 2: Pending-payment and private-proof state

- [ ] Add `pending_payment` and `payment_review` without changing the `queued`
      default for staff-created orders.
- [ ] Add `payment_expires_at`, proof schema/model/status/factory, and private disk.
- [ ] Prove allowed transitions, one proof per order, and private storage defaults.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OrderRequests/OrderPaymentStateTest.php tests/Feature/OrderRequests/PaymentProofStorageTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

## Checkpoint A: Domain foundation

- [ ] Migrations apply and roll back cleanly.
- [ ] Existing JobOrders still default to `queued`.
- [ ] Request/proof uniqueness is database-backed.
- [ ] Existing Optical Order transition tests remain green.

## Phase 2: Patient catalog and request journey

### Task 3: Accessory catalog

- [ ] Add active-link-only list/detail routes and resources.
- [ ] Return patient-safe availability without exact stock, lot, expiry, or cost.
- [ ] Add bounded search/filter/pagination and deterministic base sorts.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AccessoryCatalogTest.php`
- [ ] `vendor/bin/sail artisan route:list --path=api/v1/accessories --except-vendor`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

### Task 4: Submit/list/view/cancel requests

- [ ] Validate 1-20 distinct accessories and quantity 1-5 per variant.
- [ ] Derive all prices/totals/snapshots server-side without changing stock.
- [ ] Accept only `none`, `senior_citizen`, or `pwd` as a declaration and make
      clear that submission does not calculate or promise a discount.
- [ ] Enforce active link, ownership, one pending request, stable errors, and
      paginated current/history reads.
- [ ] Permit idempotent owner cancellation only while pending.
- [ ] Do not schedule expiry for pending requests.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AccessoryOrderRequestTest.php tests/Feature/OrderRequests/SubmitAccessoryOrderRequestTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

## Checkpoint B: Request contract

- [ ] Mobile request flow works without server cart persistence.
- [ ] Submission creates no JobOrder, BillingRecord, payment, or movement.
- [ ] Cross-account access is `404`.
- [ ] Invalid/inactive/non-accessory/unusable items are rejected.

## Phase 3: Staff decision and canonical conversion

### Task 5: Filament Order Requests queue

- [ ] Add Optical-group index/view pages with pending-first ordering.
- [ ] Show immutable items/subtotal, the discount declaration, and current
      patient-safe availability.
- [ ] Allow no create/edit/delete or bulk mutations.
- [ ] Restrict decision actions to active staff/admin reviewers.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/AccessoryOrderRequestResourceTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

### Task 6: Atomic accept/reject

- [ ] Re-lock request/link/catalog/usable stock during acceptance.
- [ ] Require an authorized reviewer to resolve any discount declaration using
      existing Optical Order controls and confirm the final payable amount.
- [ ] Create exactly one pending-payment JobOrder and unpaid BillingRecord.
- [ ] Commit exact FEFO accessory inventory and set the 30-minute deadline.
- [ ] Make acceptance replay-safe and rejection side-effect-free.
- [ ] Keep staff-created Optical Orders unchanged.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OrderRequests/ReviewAccessoryOrderRequestTest.php tests/Feature/OpticalOrders/CreateOpticalOrderTest.php tests/Feature/Inventory/InventoryAndPrivacyCharacterizationTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

## Checkpoint C: Commerce integrity

- [ ] One accepted request maps to one order and one active bill.
- [ ] The accepted order and bill record the reviewer-confirmed subtotal,
      discount, and final payable amount.
- [ ] Concurrent acceptance cannot oversell.
- [ ] Insufficient stock rolls back everything and leaves the request pending.
- [ ] Cancellation can reverse the exact committed lots once.

## Phase 4: Payment proof and expiration

### Task 7: Patient proof upload

- [ ] Add multipart proof endpoint for owned, unexpired pending-payment orders.
- [ ] Validate JPG/JPEG/PNG up to 5 MB and 8,000 by 8,000 pixels plus
      sender/reference metadata.
- [ ] Store privately, create one proof, and move order to `payment_review`.
- [ ] Expose additive safe payment status/instructions without storage details.
- [ ] Add a separate per-account proof-upload throttle and cleanup stored
      objects after a failed database write.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/OrderPaymentProofTest.php tests/Feature/OrderRequests/PaymentProofStorageTest.php tests/Feature/Api/V1/OpticalCommercePrivacyTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

### Task 8: Staff proof review

- [ ] Add Awaiting Payment/Payment Review tabs and authorized attachment-only
      private proof download.
- [ ] Accept into one full posted GCash payment and `queued` order.
- [ ] Block references already used by an accepted proof or posted payment.
- [ ] Reject with reason into cancelled order, exact stock reversal, and void bill.
- [ ] Deny optometrist-only review and avoid duplicate payment notifications.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Filament/OrderPaymentProofReviewTest.php tests/Feature/BillingRecords/PaymentLifecycleTest.php tests/Feature/Inventory/InventoryAndPrivacyCharacterizationTest.php tests/Feature/JobOrders/ContactLensJobOrderCancellationTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

### Task 9: Unpaid-order expiration

- [ ] Add idempotent locked expiration action/command for overdue
      `pending_payment` only.
- [ ] Reuse cancellation, exact reversal, and unpaid-bill voiding.
- [ ] Schedule every minute with `withoutOverlapping()`.
- [ ] Prove `payment_review` never auto-expires.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/OrderRequests/ExpireUnpaidAccessoryOrdersTest.php tests/Feature/Security/PilotDeploymentPreflightTest.php`
- [ ] `vendor/bin/sail artisan schedule:list`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

## Checkpoint D: Payment lifecycle

- [ ] Timer starts only after staff acceptance.
- [ ] Timely proof stops expiration.
- [ ] Accepted proof posts one payment and enters normal fulfillment.
- [ ] Rejected/expired payment restores stock and voids unpaid billing once.

## Phase 5: Discovery, notifications, and handoff

### Task 10: Notifications

- [ ] Notify staff/admin after request and proof submission.
- [ ] Notify patient after request decision, proof acceptance, and cancellation.
- [ ] Add closed mobile action kinds and after-commit deduplication.
- [ ] Exclude items, sender, account, reference, proof, and reasons from bodies/audits.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Notifications/AccessoryOrderNotificationTest.php tests/Feature/Notifications/AdminPatientActionNotificationTest.php tests/Feature/Notifications/PatientClinicActionNotificationTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

### Task 11: Ratings and Care Accessories placement

- [ ] Add database aggregate rating sort/filter behavior to accessories.
- [ ] Keep unrated averages `null` and visible by default.
- [ ] Add staff-controlled prescription placement for accessories only.
- [ ] Return curated products through `placement=prescription` without reading
      or changing prescriptions.
- [ ] Keep existing frame and verified item-rating contracts compatible.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/AccessoryCatalogRatingFilterTest.php tests/Feature/Api/V1/FrameRatingTest.php tests/Feature/Filament/ProductListActionTest.php tests/Feature/Filament/VariantFormVisibilityTest.php`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`

### Task 12: Contract reconciliation and final verification

- [ ] Update `docs/API_CONTRACT.md` with routes, shapes, statuses, errors,
      privacy, timer semantics, and Android ordering.
- [ ] Update `docs/BACKEND_CONTEXT.md` with schema/actions/lifecycle changes.
- [ ] Update route/privacy tests and mark spec/plan/checklist implemented only
      after all behavior exists.
- [ ] Run focused/full verification and inspect the final diff.

**Verify:**

- [ ] `vendor/bin/sail artisan test --compact tests/Feature/Api/V1/RouteContractTest.php tests/Feature/Api/V1/OpticalCommercePrivacyTest.php`
- [ ] `vendor/bin/sail artisan test --compact`
- [ ] `vendor/bin/sail bin pint --dirty --format agent`
- [ ] `vendor/bin/sail npm run build`
- [ ] `git diff --check`

## Checkpoint E: Ready for Android handoff

- [ ] All specification success criteria have passing evidence.
- [ ] Professor/project owner approves the final request/order distinction.
- [ ] API contract is frozen for Android.
- [ ] GCash config, private proof storage, queue worker, and scheduler are ready.
- [ ] Full test suite, Pint, frontend build, and diff checks are green.
