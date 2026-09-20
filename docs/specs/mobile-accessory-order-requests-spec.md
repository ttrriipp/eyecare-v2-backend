# Spec: Mobile Accessory Order Requests

**Status:** Product decisions approved; implementation deferred
**Planning date:** 2026-09-20
**Decision date:** 2026-09-20

## Objective

Let an authenticated mobile account with an active Patient link submit a
multi-item request for in-stock accessories, let clinic staff accept or reject
that request, and collect full GCash payment through one private proof upload
before the accepted order enters the existing Optical Order fulfillment flow.

The feature is intentionally a request-first workflow. A submitted request does
not reserve stock, create billing, or become an Optical Order. Staff acceptance
revalidates the immutable request snapshot, creates the canonical Optical Order
and Billing Record, commits inventory, and starts a 30-minute payment window.
The patient pays only after that acceptance boundary.

Success means linked patients can complete the request-to-pickup journey without
overselling stock, duplicating orders or payments, or creating a second commerce
system beside Optical Orders and Billing.

## Approved product decisions

1. Only accounts with an active Patient link may browse the mobile accessory
   catalog, submit a request, upload proof, or view the resulting order.
2. A request may contain multiple distinct accessory variants. The mobile cart
   remains client-side and does not reserve inventory.
3. Only active `accessory` Products and active variants are eligible. Frames,
   contact lenses, lens packages, lens options, services, and custom products
   are rejected at the API boundary and again during staff acceptance.
4. Fulfillment is clinic pickup only. Accepted orders use prepared fulfillment,
   never immediate dispensing or an external supplier.
5. Staff acceptance, not request submission, starts the 30-minute payment
   window and commits inventory through the existing lot-aware
   `CommitJobOrderInventory` path. Accessories already use expiry-tracked lots,
   so allocation remains deterministic FEFO and cancellation restores the
   source lots through the existing reversal path.
6. Payment is full-balance GCash only for the MVP. No cash, deposits, partial
   payments, bank transfer, cards, checks, delivery fees, vouchers, or loyalty
   points are introduced by this feature. The payable balance is the final
   amount confirmed by an authorized reviewer after any approved discount.
7. One private JPG, JPEG, or PNG proof of at most 5 MB may be submitted
   before the deadline. Submission stops expiration; staff verification has no
   patient-facing countdown.
8. Staff verify the transfer against the clinic's real GCash transaction
   history. The uploaded file is supporting evidence, not authoritative proof
   that money was received.
9. Only active staff or administrators may accept/reject requests and
   accept/reject payment proofs. Optometrist-only accounts may not perform
   commerce decisions.
10. Product ratings remain verified-purchase ratings: only a dispensed catalog
    item linked to a ProductVariant is rateable. Accessory catalog aggregates
    and filters reuse the existing rating records and patient/order-item rating
    endpoint.
11. The prescription screen may surface staff-curated Care Accessories, but
    those products are not prescription-bound, clinically personalized, or
    represented as medically necessary or compatible with measurements.
12. An account may have only one pending accessory request. Pending requests do
    not expire automatically; the patient may cancel while pending, and staff
    may accept or reject them.
13. A patient may declare a Senior Citizen or PWD discount request. The system
    does not infer legal eligibility by product or add a new discount rules
    engine. An authorized reviewer verifies the request using the clinic's
    existing process and confirms the final amount before acceptance.
14. Payment instructions use one clinic-owned GCash account supplied through
    deployment configuration. Account details are never committed to source.

## Approved workflow constraints

1. A patient may have at most one `pending` accessory order request at a time.
   Accepted requests and active Optical Orders do not prevent a later request.
2. Staff cannot edit quantities, substitute variants, reprice lines, or
   partially accept a request. They accept the complete snapshot or reject it
   with a patient-visible reason.
3. Prices are snapshotted server-side when the request is submitted and are
   honored if staff accepts it. A clinic that cannot honor the snapshot rejects
   the request and asks the patient to submit a new one.
4. Patients may cancel only a still-pending request. After acceptance they may
   allow the unpaid order to expire or contact the clinic.
5. There is one proof submission and no self-service resubmission in the MVP.
   A reviewer who is not certain that no transfer occurred leaves the proof in
   review and escalates outside the normal action flow.
6. A successful-transfer dispute, refund, or unmatched transfer remains an
   administrator-handled exception using existing payment correction/audit
   practices; no automated refund state machine is added.
7. The request records only a patient declaration of `none`, `senior_citizen`,
   or `pwd`. This feature adds no product-level discount-eligibility flag and no
   discount-document upload. The reviewer verifies eligibility using existing
   clinic records or the clinic's manual process, applies the existing Optical
   Order discount controls, and confirms the final payable amount. Existing
   authorization for applying positive discounts remains unchanged.
8. A pending request remains open until staff acts or the patient cancels it.
   There is no scheduled expiry for the pre-acceptance request state.
9. The clinic-owned GCash account name and number are deployment inputs rather
   than hard-coded product decisions. Placeholder configuration is acceptable
   outside production.

## Domain model

### Accessory Order Request

Create a dedicated `accessory_order_requests` aggregate rather than using the
legacy `orders` tables or overloading `appointment_requests`.

Required request fields:

- `request_number` using `ORQ-YYYY-NNNNNN`
- `user_id` and `patient_id`
- `status`: `pending`, `accepted`, `rejected`, or `cancelled`
- immutable catalog `subtotal_amount`
- `requested_discount_type`: `none`, `senior_citizen`, or `pwd`
- nullable unique `job_order_id` after acceptance
- nullable `resolved_by`, `resolved_at`, `rejection_reason`, and `cancelled_at`
- timestamps

Each `accessory_order_request_item` stores the requested ProductVariant ID plus
an immutable description, quantity, unit price, line amount, and catalog
snapshot. The live foreign key is used for acceptance revalidation; the
snapshot preserves history when catalog data later changes.

Request rows and items are historical records. They are not soft-deleted and
have no patient or staff edit action.

### Optical Order payment stages

Extend `JobOrderStatus` additively:

```text
pending_payment -> payment_review -> queued -> in_progress
                                           -> ready_for_dispensing
                                           -> dispensed
pending_payment -> cancelled
payment_review  -> cancelled
```

Existing staff-created orders still begin at `queued`. Mobile request
acceptance creates a prepared Optical Order at `pending_payment`, commits its
inventory, creates its unpaid Billing Record and charge snapshots, and sets
`payment_expires_at` to exactly 30 minutes after acceptance in
`config('app.timezone')`.

`pending_payment` may transition only to `payment_review` or `cancelled`.
`payment_review` may transition only to `queued` or `cancelled`. Existing
transitions beginning at `queued` remain unchanged.

### Payment proof

Create one `order_payment_proofs` row per accepted Optical Order:

- unique `job_order_id`
- submitting `user_id`
- `status`: `pending`, `accepted`, or `rejected`
- private `file_path`, original name, MIME type, and size
- patient-entered sender name and GCash reference number
- nullable reviewer, reviewed timestamp, and bounded rejection reason
- timestamps

Proof objects use a dedicated logical private filesystem disk. No public URL,
storage path, internal reviewer note, or moderation field appears in the
patient API. Authorized staff/admin access uses authenticated preview/download
routes and never changes object visibility.

## State and transaction rules

### Submit request

1. Lock the authenticated account while enforcing the one-pending-request
   limit.
2. Require 1-20 distinct item rows and quantities from 1-5 per variant.
3. Load variants in deterministic ID order and validate active Product,
   variant, brand/category lifecycle, `product_type = accessory`, and current
   usable stock. Submission does not allocate lots or decrement stock.
4. Derive prices, descriptions, item kind, and snapshots server-side. Ignore no
   client-supplied price because the API does not accept one.
5. Record the bounded discount declaration without calculating or promising a
   discount at submission time.
6. Persist the request and items atomically and notify clinic staff after
   commit.

### Accept request

1. Lock request, linked account/Patient relationship, and request items.
2. Confirm the request is still pending and the account is still linked to the
   same Patient.
3. Revalidate every live variant as an active accessory with sufficient usable
   stock. Staff cannot bypass unavailable or expired lots.
4. Review the patient's discount declaration, verify it through the clinic's
   existing manual process, and select the applicable existing Optical Order
   discount. The acceptance screen shows the resulting final payable amount
   before confirmation. No product-level eligibility engine is consulted.
5. Create one `JobOrder` in `pending_payment`, recreate its accessory line
   snapshots from the immutable request items, and commit inventory under the
   existing variant/lot locks.
6. Create one unpaid Billing Record and corresponding optical-order billing
   items using the confirmed subtotal, discount, and final payable amount.
7. Store the request's unique `job_order_id`, mark it accepted, record the
   reviewer/time, set the payment deadline, and write identifier/status-only
   audits in the same transaction.
8. After commit, notify the patient that the request was accepted and payment
   is due. The notification points to the resulting Optical Order.

Acceptance is idempotent for an already accepted request: it returns or links
to the one existing Optical Order and never creates another order, billing
record, inventory commitment, or notification.

### Reject or cancel request

- Staff may reject only a pending request and must provide a patient-visible
  reason of at most 1,000 characters.
- The owner may cancel only a pending request. Cancellation is idempotent.
- Neither path creates an Optical Order, billing record, payment, inventory
  movement, or stock change.

### Submit proof

1. Authorize through the active linked account and owned Optical Order.
2. Lock the order and require `pending_payment` with `now() <=
   payment_expires_at`.
3. Validate and privately store one file plus bounded sender name/reference.
4. Create the unique proof row and transition the order to `payment_review` in
   one logical operation. A storage or database failure must not leave a
   patient-visible proof record pointing at a missing object; if persistence
   fails after storage succeeds, delete the newly stored object.
5. Notify authorized clinic reviewers after commit.

The first submission returns `201`. A repeated submission returns the existing
proof state with `200` rather than creating a second row or replacing the
private object. It does not extend the original payment deadline.

### Accept proof

1. Lock proof, Optical Order, Billing Record, and request.
2. Require proof `pending`, order `payment_review`, no existing posted payment,
   full balance due, and a GCash reference not already used by an accepted proof
   or posted payment.
3. Record exactly one full-balance `BillingPayment` using `gcash`, the submitted
   reference number, reviewer identity, and `charges_reviewed = true`.
4. Mark proof accepted and transition the order to `queued` atomically.
5. Send one order-confirmed patient notification after commit. Suppress a
   separate payment-recorded notification for the same acceptance transaction.

### Reject proof or expire payment

- Staff may reject only after checking the clinic GCash ledger and must provide
  a bounded patient-visible reason. Rejection marks the proof rejected, cancels
  the Optical Order, reverses its exact inventory commitments, and voids the
  unpaid Billing Record atomically.
- A scheduled command runs every minute with `withoutOverlapping()`. It locks
  and cancels only `pending_payment` orders whose deadline has passed.
  `payment_review` orders never expire automatically because money may already
  have transferred.
- Both paths are idempotent and reuse the existing order cancellation and
  inventory reversal behavior.

## Mobile API contract

All routes below remain under `/api/v1`, use Sanctum, the existing clinical
throttle, and `require.patient.link`. Request submission and payment-proof
upload also use separate per-account throttles; the proof throttle permits at
most five attempts per minute so large multipart requests cannot consume the
general clinical budget unchecked.

### Accessory catalog

```text
GET /accessories
GET /accessories/{accessory}
```

The catalog returns active accessory Products with active variants. Variant
responses expose price, attributes, patient-safe images, and an `availability`
value (`available`, `low_stock`, or `unavailable`) without exact stock or lot
details. Expiry and purchase dates remain internal.

List query parameters:

| Parameter | Values |
|---|---|
| `search` | bounded product name/description search |
| `brand` | active brand ID |
| `category` | active category ID |
| `sort` | `name`, `newest`, `rating`, `most_rated` |
| `minimum_rating` | integer 1-5 |
| `rated` | `all`, `rated`, `unrated`; default `all` |
| `placement` | omitted or `prescription` |
| `page` | integer, minimum 1 |
| `per_page` | integer 1-50; default 15 |

Each Product includes nullable `average_rating` and integer `rating_count`.
Unrated Products return `average_rating: null`, never zero, and remain visible
by default. `placement=prescription` returns only active accessories marked by
staff for the Care Accessories surface; it does not inspect prescription data.

### Order request routes

```text
GET  /accessory-order-requests
POST /accessory-order-requests
GET  /accessory-order-requests/{accessoryOrderRequest}
POST /accessory-order-requests/{accessoryOrderRequest}/cancel
```

Submission payload:

```json
{
  "requested_discount_type": "none",
  "items": [
    { "product_variant_id": 42, "quantity": 2 },
    { "product_variant_id": 57, "quantity": 1 }
  ]
}
```

`requested_discount_type` is optional and defaults to `none`; it is a request
for manual review, not a promise or client-calculated discount. The response
returns request identity/status, immutable item snapshots, the catalog subtotal
as a two-decimal monetary string, resolution fields, timestamps, and a nullable
`order` summary with the confirmed discount and final amount after acceptance.
It never exposes cost price, exact stock, lot/expiry data, internal notes,
staff-only audit data, or proof storage paths.

The list is paginated and supports `filter=current|history`. `current` contains
pending requests and accepted requests whose resulting Optical Order is not
dispensed/cancelled. `history` contains rejected/cancelled requests and
accepted requests whose order is dispensed/cancelled. Ordering is
`created_at DESC, id DESC`.

### Payment-proof route

```text
POST /optical-orders/{jobOrder}/payment-proof
```

Multipart fields:

- `proof`: required JPG/JPEG/PNG, maximum 5 MB, maximum 8,000 by 8,000 pixels
- `sender_name`: required string, maximum 100
- `reference_number`: required string, maximum 100

The patient Optical Order resource additively exposes:

- `payment_expires_at`
- `payment_proof_status`: `not_submitted`, `pending`, `accepted`, or `rejected`
- `payment_instructions` only while awaiting payment: method, clinic account
  name/number, exact amount, order reference, and deadline

`pending_payment` and `payment_review` belong to the current Optical Order
filter. Internal proof metadata and rejection review notes are not exposed;
the patient-visible rejection reason is returned when applicable.

### Error semantics

Ownership failures return `404`. Boundary validation retains Laravel's
existing validation envelope. Workflow conflicts use HTTP 422 with stable
machine-readable codes:

- `ACTIVE_ORDER_REQUEST_EXISTS`
- `ACCESSORY_NOT_ORDERABLE`
- `ORDER_REQUEST_NOT_ACTIONABLE`
- `PAYMENT_WINDOW_EXPIRED`
- `ORDER_NOT_AWAITING_PAYMENT`

API field names and enum values remain snake_case to match the existing v1
contract. Existing routes and response fields are not removed or retyped.

## Staff workflow

### Order Requests resource

Add an **Order Requests** resource in the Optical navigation group with index
and view pages only.

- Pending rows sort first, then oldest submission first.
- The view shows patient, immutable line items, catalog subtotal, declared
  discount request, request age, and current availability without exposing
  exact lot details.
- **Accept** and **Reject** are visible only to active staff/admin reviewers.
- Accept is all-or-nothing and delegates to the domain action; the Filament
  page never writes order, billing, or inventory rows directly.
- Acceptance requires the authorized reviewer to resolve the discount request
  and confirm the resulting final payable amount. Positive discounts retain
  the existing Optical Order authorization rules.
- Accepted rows link to their resulting Optical Order.

### Optical Order payment review

Extend the existing Optical Orders resource with Awaiting Payment and Payment
Review tabs/status labels. A payment-review order shows the private proof,
sender name, GCash reference, expected amount, deadline, and request link.

Proof access is an authenticated download with `Content-Disposition:
attachment` and `X-Content-Type-Options: nosniff`; the application does not
render patient-supplied documents as inline HTML or make storage URLs public.

- **Accept Payment** records the full payment and queues fulfillment.
- **Reject Payment** requires a reason and cancels the unpaid order.
- Review actions are staff/admin only and unavailable to optometrist-only
  accounts.
- Existing staff-created Optical Order flows and `queued` onward actions remain
  unchanged.

## Notifications and privacy

Create queued, after-commit, deduplicated notifications for:

- patient submits an order request -> staff/admin bell alert
- staff accepts request -> patient payment-required alert
- staff rejects request -> patient request-declined alert
- patient submits proof -> staff/admin bell alert
- staff accepts proof -> patient order-confirmed alert
- proof rejection or payment expiry -> patient order-cancelled alert

Notification bodies contain request/order identifiers and safe statuses only.
They omit item details, sender name, account number, GCash reference, proof
filename/path, rejection narrative, and internal notes.

Proof files are private data. Validate real MIME content, reject SVG and office
documents, use generated storage names, and authorize every read. Audit entries
record IDs, transitions, timestamps, and actor IDs—not proof contents or
patient-entered payment identifiers.

### Threat model and abuse cases

- **Spoofing:** a different account attempts to read a request/order/proof;
  mitigate with active-link middleware, owner-scoped lookup, `404` failures,
  and staff/admin policies on every proof read and decision.
- **Tampering:** the client submits prices, non-accessory IDs, altered totals,
  or replays acceptance/payment; mitigate with server-derived snapshots,
  database constraints, locked state transitions, and exactly-once tests.
- **Repudiation:** a patient or reviewer disputes submission/acceptance;
  retain immutable request/proof metadata and PII-safe actor/time audits.
- **Information disclosure:** proof objects, sender/reference data, exact stock,
  or internal notes leak; mitigate with private storage, response allowlists,
  download authorization, and notification/audit redaction.
- **Denial of service:** oversized carts/files or rapid multipart uploads;
  mitigate with item/quantity/file/dimension caps and separate per-account
  throttles.
- **Elevation of privilege:** optometrist-only or inactive staff performs a
  commerce decision; mitigate with explicit staff/admin policies in both UI
  visibility and domain actions.

## Product ratings and filtering

The current verified-purchase rules already permit any dispensed
ProductVariant-backed Optical Order item to be rated. The MVP therefore keeps
`POST /optical-order-items/{id}/rating` and its response shape stable.

Accessory catalog aggregates include hidden ratings' star values while
suppressing hidden comments, matching current frame behavior. Filtering and
sorting must use database aggregates and deterministic ID tie-breakers; they
must not load the whole rating set into application memory.

The internal `FrameRating`/`frame_ratings` names are acknowledged legacy
terminology. Renaming the table, model, policy, Filament resource, reports, and
tests is deferred because it adds migration risk without changing the mobile
capability. Patient-facing and new staff-facing copy should say **Product
Rating** where it applies to both frames and accessories.

## Prescription Care Accessories

Add a staff-managed boolean flag to accessory Products for prescription-page
placement. Android loads `GET /accessories?placement=prescription&per_page=6`
when rendering a prescription detail screen.

This deliberately keeps the clinical prescription resource unchanged. The
section heading is **Care Accessories** and supporting copy must say the items
are optional. The backend does not read encrypted measurements, infer medical
compatibility, or bind the resulting request/order to a Prescription.

## Commands

- Generate framework files: `vendor/bin/sail artisan make:* --no-interaction`
- Focused tests: `vendor/bin/sail artisan test --compact <test-files>`
- Full tests: `vendor/bin/sail artisan test --compact`
- Format PHP: `vendor/bin/sail bin pint --dirty --format agent`
- Inspect routes: `vendor/bin/sail artisan route:list --path=api/v1 --except-vendor`
- Run scheduler locally: `vendor/bin/sail artisan schedule:work`
- Build frontend assets when Filament assets change: `vendor/bin/sail npm run build`

## Project structure and style

- Domain state and actions: `app/Enums`, `app/Models`, `app/Actions`
- API validation/controllers/resources: `app/Http/Requests/Api`,
  `app/Http/Controllers/Api`, `app/Http/Resources/Api`
- Staff UI: `app/Filament/Resources`
- Schema/factories: `database/migrations`, `database/factories`
- Routes/scheduling: `routes/api.php`, `routes/console.php`
- Feature tests: `tests/Feature/Api/V1`, `tests/Feature/OpticalOrders`,
  `tests/Feature/OrderRequests`, and `tests/Feature/Filament`

All transitions belong in typed, single-purpose actions with explicit parameter
and return types. Controllers and Filament actions delegate rather than
duplicating transaction logic:

```php
public function handle(
    AccessoryOrderRequest $orderRequest,
    User $reviewer,
): JobOrder {
    return DB::transaction(function () use ($orderRequest, $reviewer): JobOrder {
        $lockedRequest = AccessoryOrderRequest::query()
            ->whereKey($orderRequest->id)
            ->lockForUpdate()
            ->firstOrFail();

        // Validate the locked state, create canonical records, and commit once.
    });
}
```

## Testing strategy

- Pest feature tests establish behavior before each implementation slice.
- API tests cover active-link enforcement, ownership, pagination, payload
  validation, catalog privacy, discount declaration, request lifecycle, proof
  upload, payment expiry, and stable error codes.
- Action tests cover locked acceptance, stock races, FEFO lot commitment,
  manual discount resolution, exact reversal, billing/payment idempotency, and
  rejection behavior.
- Filament tests act as a staff/admin before testing request and proof actions
  and prove optometrist-only denial.
- Storage tests use `Storage::fake()` and prove private visibility, allowed file
  types/dimensions, ownership, attachment-only download headers,
  missing-object behavior, and cleanup on failed writes.
- Notification tests prove recipient roles, deduplication, after-commit
  delivery, mobile actions, and absence of payment/proof data.
- Regression tests keep staff-created Optical Orders, dispensing, reports,
  frame ratings, and current mobile Optical Order responses green.

## Boundaries

### Always

- Revalidate ownership, request state, catalog lifecycle, and usable stock
  under locks at every irreversible transition.
- Derive prices and totals server-side and store immutable commercial snapshots.
- Use the existing canonical Optical Order, Billing, Payment, inventory ledger,
  notification, audit, and verified-rating boundaries.
- Keep proof objects private and payment identifiers out of notifications and
  audits.
- Rate-limit request submission and proof uploads independently of the general
  clinical API bucket.
- Add failing focused Pest coverage before behavior changes and run Pint after
  every PHP slice.

### Ask first

- Any discount or refund policy, second payment method, proof resubmission,
  automated product-level discount eligibility, discount-document upload,
  staff line editing/substitution, multiple simultaneous pending requests,
  pending-request expiry, delivery, or change to the 30-minute window.
- Any new dependency, public file visibility, exact-stock exposure, or change
  to existing staff-created Optical Order behavior.

### Never

- Accept payment before staff accepts the request and inventory is committed.
- Trust client prices, screenshot contents, or patient-supplied catalog labels.
- Create records in the retired legacy `orders`, `order_items`, `payments`, or
  `billings` tables.
- Auto-expire an order after proof submission.
- Log proof contents, storage paths, GCash references, or sender names.
- Describe Care Accessories as prescribed, required, or measurement-compatible.

## Success criteria

1. A linked patient can browse active accessories, submit one immutable
   multi-item request, list/view it, and cancel it while pending; another
   account receives `404` for that request.
2. Submission does not change stock or calculate a discount. Staff resolves any
   declared Senior Citizen/PWD request, confirms the final payable amount, and
   acceptance creates exactly one pending-payment Optical Order and Billing
   Record, commits the exact accessory quantities once using current FEFO
   rules, and starts a 30-minute deadline.
3. Rejection before acceptance creates no commerce or inventory records.
4. One valid proof submitted before the deadline moves the owned order to
   payment review without exposing the private object. Late or duplicate proof
   attempts cannot create additional rows or extend the deadline.
5. Staff acceptance records exactly one full GCash payment and moves the order
   to `queued`. Proof rejection or unpaid expiry cancels the order, restores
   exact inventory once, and voids the unpaid bill.
6. Existing `queued` onward Optical Order, billing, dispensing, notification,
   reporting, and rating behavior remains compatible.
7. Accessory catalog rating aggregates, sorting, rated/unrated filtering, and
   minimum-rating filtering are deterministic and preserve `null` for unrated
   products.
8. Prescription screens can request up to six curated optional accessories
   without changing or reading the clinical prescription payload.
9. Focused and full Pest suites pass, Pint is clean, and the authoritative API
   and backend context documents match the shipped contract.
10. Pending requests do not expire automatically, while the owner retains an
    idempotent cancellation path before staff acceptance.

## Not doing

- Server-persisted or cross-device carts
- Delivery, shipping addresses, fees, or tracking
- Frames, contact lenses, lenses, or prescription eyewear ordering
- Automatic payment gateways, webhooks, refunds, or fraud detection
- Partial payments, deposits, promotions, or loyalty points
- Automated product-level discount eligibility or a discount-document upload
- Patient proof resubmission or multiple proof files
- Staff edits, substitutions, or partial request acceptance
- Personalized clinical product recommendations
- Rating photos/videos or an internal rating-table rename
- Public proof URLs or proof access by optometrist-only accounts

## Deployment input still required

- Before production deployment, configure the exact clinic-owned GCash account
  name and number and verify that the clinic's manual discount-verification
  process satisfies its current legal and accounting obligations.
