# Spec: Patient Eyewear Aggregate API

## Status

Approved by the project owner on 2026-07-29. Phase 1 of the spec-driven
workflow is complete.

Phase 2 may now produce an implementation plan. Phase 3 will produce
independently checkable tasks. Routes, migrations, application code, tests,
and the authoritative API documentation remain gated until those phases are
separately approved.

## Objective

Add one patient-facing, read-only **Eyewear** API that presents a quotation,
its resulting Job Order, dispensing progress, and its active Billing Record as
one coherent transaction.

The clinic continues using Quotations, Job Orders, Billing Records, and
Payments as separate internal records. Android must not join the three legacy
paginated patient APIs or infer their relationships.

The patient experience is:

```text
Estimate
    -> Preparation
    -> Ready for Pickup
    -> Dispensed

Payment status is displayed separately and never changes the progress group.
```

Success means Android can build its Eyewear list and detail specification from
this contract alone, including stable deep links, exact money values, patient
authorization, partial transactions, and deterministic pagination.

## Confirmed Decisions and Assumptions

1. Authentication remains Laravel Sanctum bearer-token authentication.
2. Only the authenticated user's linked Patient record may be queried.
3. The API uses snake_case, the established `/api/v1` prefix, standard Laravel
   pagination, and ISO-8601 timestamps with an explicit offset.
4. The list defaults to `filter=current` when `filter` is omitted.
5. `per_page` defaults to `15` and accepts integers from `1` through `50`.
6. Money is always serialized as a string with exactly two decimal places.
7. A stable canonical key is persisted as `eyw_{26-character ULID}`. This
   requires an indexed `eyewear_key` column on Quotations and Job Orders but no
   new aggregate table.
8. An estimate receives its key when created. A Job Order created from that
   estimate inherits the same key. A Job Order without a linked quotation
   receives its own key. Existing development records are backfilled using the
   same rule.
9. `GET /eyewear/{key}` accepts the canonical key and the compatibility alias
   `jo_{job_order_id}`. A successful alias lookup returns the canonical key in
   `data.key`. Aliases are patient-scoped and never bypass ownership checks.
10. A voided or soft-deleted Billing Record is not an active financial claim.
    It produces `payment_status = null` and `balance_due = null`, is ignored
    for list `total_amount` precedence, and does not produce a
    `payment_summary` section. The legacy Billing Record endpoint remains
    available temporarily if Android still needs explicit void history during
    migration.
11. Only posted payments appear. Reversed payments and all correction,
    recorder, reversal, and internal-note data remain clinic-internal.
12. The description is the first nonblank item description in ascending item
    ID order. When more items exist, append ` + N more`. The deterministic
    fallback is `Eyewear transaction`.
13. `activity_at` is the latest patient-visible event timestamp, not a generic
    `updated_at`. Payment activity may update ordering but never Current versus
    History membership.
14. Soft-deleted Quotations and Job Orders are excluded. An active Job Order
    remains visible even if its linked quotation is unavailable, in which case
    the estimate section is omitted.
15. Once a Job Order exists, its status is authoritative for aggregate
    progress even if the linked quotation has an inconsistent status.

## API Surface

```http
GET /api/v1/eyewear?filter=current|history&page=1&per_page=15
GET /api/v1/eyewear/{key}
```

Both routes are:

- authenticated;
- patient-scoped;
- rate-limited by the existing authenticated API middleware;
- read-only;
- additive to the existing versioned API.

There are no Eyewear create, accept, edit, preparation-progress, dispensing,
payment, or deletion routes.

## Aggregate Identity and Duplicate Prevention

### Canonical key

The API returns only the opaque `eyewear_key` value:

```text
eyw_01K1D7H4R1V87GJ7D2GCB9QT4X
```

Android treats it as an opaque string and must not parse the prefix or ULID.
The key is not an authorization secret.

### Key propagation

```text
Estimate-only:
Quotation.eyewear_key = eyw_A

Complete linked transaction:
Quotation.eyewear_key = eyw_A
JobOrder.eyewear_key  = eyw_A

Job-order-only:
JobOrder.eyewear_key  = eyw_B
```

Creating a Job Order from a Quotation copies the existing key in the same
transaction. It never generates a replacement key for that lifecycle.

The aggregate list groups records by the canonical key. A linked Quotation and
Job Order therefore produce one item, not two. The implementation must reject
or deterministically flag conflicting keys rather than silently return
duplicates.

### Job Order aliases

Existing Android Job Order records contain a numeric Job Order ID. During
migration, Android may resolve that record through:

```http
GET /api/v1/eyewear/jo_17
```

The server resolves Job Order `17`, verifies that it belongs to the
authenticated patient, and returns the aggregate using its canonical
`eyw_...` key. Another patient's alias returns `404`.

Canonical keys are the only keys returned in list and detail response bodies.
Aliases are accepted only for lookup and are never pagination identities.

## List Contract

### Query parameters

| Parameter | Required | Validation | Default |
|---|---:|---|---|
| `filter` | No | `current` or `history` | `current` |
| `page` | No | Integer, minimum `1` | `1` |
| `per_page` | No | Integer, `1` through `50` | `15` |

Invalid values return the established Laravel `422` validation body. Unknown
query parameters may be ignored, but clients must not depend on them.

### Summary resource

| Field | Type | Contract |
|---|---|---|
| `key` | string | Required canonical opaque key. |
| `description` | string | Required patient-friendly deterministic description. |
| `consultation_at` | string or null | Appointment timestamp derived through Encounter to Appointment. |
| `created_at` | string | Required aggregate-origin timestamp and fallback display date. |
| `progress` | enum | Required aggregate progress. |
| `payment_status` | enum or null | `balance_due`, `paid`, or null. |
| `total_amount` | decimal string | Required, exactly two decimal places. |
| `balance_due` | decimal string or null | Active Billing Record balance, otherwise null. |
| `activity_at` | string | Required deterministic ordering timestamp. |

`created_at` must never be labeled or serialized as a consultation date.
Android displays `consultation_at` as the consultation date when non-null and
falls back to a separately labelled **Created** value otherwise.

### Progress mapping

Job Order progress takes precedence whenever a Job Order exists.

| Source state | Aggregate progress | Filter |
|---|---|---|
| Presented or accepted Quotation without Job Order | `estimate_available` | Current |
| Queued Job Order | `in_preparation` | Current |
| In-progress Job Order | `in_preparation` | Current |
| Ready-for-dispensing Job Order | `ready_for_pickup` | Current |
| Dispensed Job Order | `dispensed` | History |
| Declined Quotation without Job Order | `estimate_declined` | History |
| Expired Quotation without Job Order | `estimate_expired` | History |
| Cancelled Job Order | `cancelled` | History |
| Draft Quotation without Job Order | Excluded | Neither |

An inconsistent Draft Quotation with an active Job Order is included using the
Job Order state because Job Order precedence is authoritative.

Payment state never changes this mapping. A dispensed transaction with an
outstanding balance remains in History.

### Payment status

Only a non-deleted, non-voided Billing Record linked to the aggregate's Job
Order is active.

| Active Billing Record state | `payment_status` |
|---|---|
| `unpaid` or `partially_paid` | `balance_due` |
| `paid` | `paid` |
| No active Billing Record | null |

The implementation should assert that status and stored decimal totals agree,
but the response must not expose internal inconsistency details.

### Total precedence

`total_amount` is selected in this order:

1. active Billing Record `total_amount`;
2. Job Order `total_amount`;
3. selected Estimate revision `total`;
4. deterministic fallback `"0.00"` for a structurally incomplete historical
   record.

A voided Billing Record is ignored by this precedence.

### Consultation timestamp

Resolve:

```text
JobOrder.encounter.appointment.scheduled_at
    -> Quotation.encounter.appointment.scheduled_at
    -> null
```

No timestamp is inferred from prescription, quotation, or Job Order creation.

### Created timestamp

Resolve:

```text
Quotation.created_at
    -> JobOrder.created_at
    -> activity_at
```

The field is required in the API even though legacy database timestamp columns
are technically nullable.

### Activity timestamp

Use the maximum available patient-visible event timestamp from:

- Quotation creation;
- selected revision presentation or acceptance;
- Job Order creation, start, ready, dispensing, or cancellation;
- active Billing Record `recorded_at`;
- posted Payment `recorded_at`.

Do not use staff-note edits, recorder activity, raw `updated_at`, audit logs, or
reversed-payment timestamps.

Order list results by:

```text
activity_at DESC, key ASC
```

This tie-breaker is mandatory. The paginator is a standard length-aware,
page-number paginator.

### Successful list response

```json
{
  "data": [
    {
      "key": "eyw_01K1D7H4R1V87GJ7D2GCB9QT4X",
      "description": "Classic Rectangle Frame + 1 more",
      "consultation_at": "2026-07-27T09:00:00+08:00",
      "created_at": "2026-07-27T10:00:00+08:00",
      "progress": "in_preparation",
      "payment_status": null,
      "total_amount": "8000.00",
      "balance_due": null,
      "activity_at": "2026-07-27T11:00:00+08:00"
    }
  ],
  "links": {
    "first": "http://localhost/api/v1/eyewear?filter=current&page=1&per_page=15",
    "last": "http://localhost/api/v1/eyewear?filter=current&page=1&per_page=15",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "links": [],
    "path": "http://localhost/api/v1/eyewear",
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

The final documentation examples will use the environment's canonical base
URL conventions rather than require Android to depend on `localhost`.

## Detail Contract

The detail response repeats the exact summary fields and adds only the sections
whose active source records exist.

### Estimate section

Include when a visible Quotation and selected revision exist.

If a Job Order exists, use the exact Quotation Revision referenced by
`job_orders.quotation_revision_id`. This preserves the accepted commercial
snapshot. For an estimate-only transaction, use the latest visible revision.

```json
{
  "quotation_number": "QUO-01K1D7...",
  "status": "accepted",
  "valid_until": "2026-08-03",
  "subtotal": "8500.00",
  "discount_amount": "500.00",
  "total": "8000.00",
  "items": [
    {
      "description": "Classic Rectangle Frame",
      "quantity": 1,
      "unit_price": "4500.00",
      "amount": "4500.00"
    }
  ]
}
```

Draft Quotations never produce an estimate-only aggregate.

### Preparation section

Include whenever a visible Job Order exists.

```json
{
  "job_order_number": "JO-2026-000017",
  "status": "ready_for_dispensing",
  "total_amount": "8000.00",
  "started_at": "2026-07-27T11:00:00+08:00",
  "ready_at": "2026-07-28T15:00:00+08:00",
  "items": [
    {
      "id": 31,
      "description": "Classic Rectangle Frame",
      "quantity": 1,
      "unit_price": "4500.00",
      "amount": "4500.00",
      "product_variant_id": 42
    }
  ]
}
```

`expected_completion_at` is omitted because the current model has no genuine
expected-completion value. It must not be copied or inferred from `ready_at`.
Adding the field later requires real persisted source data, an additive
contract update, and tests.

### Dispensing section

Include only when the Job Order is ready for dispensing or has been dispensed.

```json
{
  "status": "dispensed",
  "ready_at": "2026-07-28T15:00:00+08:00",
  "dispensed_at": "2026-07-29T10:00:00+08:00"
}
```

Do not expose recipient identity, dispenser identity, dispensing notes, or
internal event IDs.

### Payment summary section

Include only for an active Billing Record. Include posted Payments ordered by
`recorded_at ASC, id ASC`.

```json
{
  "billing_record_number": "BR-2026-000017",
  "status": "partially_paid",
  "total_amount": "8000.00",
  "amount_paid": "5000.00",
  "balance_due": "3000.00",
  "payments": [
    {
      "id": 44,
      "amount": "5000.00",
      "payment_method": "cash",
      "reference_number": null,
      "recorded_at": "2026-07-29T10:05:00+08:00"
    }
  ]
}
```

Payment method values remain the stored clinic values, currently including
`cash`, `gcash`, `bank_transfer`, and `card`.

### Complete linked detail response

```json
{
  "data": {
    "key": "eyw_01K1D7H4R1V87GJ7D2GCB9QT4X",
    "description": "Classic Rectangle Frame + 1 more",
    "consultation_at": "2026-07-27T09:00:00+08:00",
    "created_at": "2026-07-27T10:00:00+08:00",
    "progress": "dispensed",
    "payment_status": "balance_due",
    "total_amount": "8000.00",
    "balance_due": "3000.00",
    "activity_at": "2026-07-29T10:05:00+08:00",
    "estimate": {
      "quotation_number": "QUO-01K1D7H4R1V87GJ7D2GCB9QT4X",
      "status": "accepted",
      "valid_until": "2026-08-03",
      "subtotal": "8500.00",
      "discount_amount": "500.00",
      "total": "8000.00",
      "items": [
        {
          "description": "Classic Rectangle Frame",
          "quantity": 1,
          "unit_price": "4500.00",
          "amount": "4500.00"
        },
        {
          "description": "Single Vision Lens",
          "quantity": 1,
          "unit_price": "4000.00",
          "amount": "4000.00"
        }
      ]
    },
    "preparation": {
      "job_order_number": "JO-2026-000017",
      "status": "dispensed",
      "total_amount": "8000.00",
      "started_at": "2026-07-27T11:00:00+08:00",
      "ready_at": "2026-07-28T15:00:00+08:00",
      "items": [
        {
          "id": 31,
          "description": "Classic Rectangle Frame",
          "quantity": 1,
          "unit_price": "4500.00",
          "amount": "4500.00",
          "product_variant_id": 42
        },
        {
          "id": 32,
          "description": "Single Vision Lens",
          "quantity": 1,
          "unit_price": "4000.00",
          "amount": "4000.00",
          "product_variant_id": null
        }
      ]
    },
    "dispensing": {
      "status": "dispensed",
      "ready_at": "2026-07-28T15:00:00+08:00",
      "dispensed_at": "2026-07-29T10:00:00+08:00"
    },
    "payment_summary": {
      "billing_record_number": "BR-2026-000017",
      "status": "partially_paid",
      "total_amount": "8000.00",
      "amount_paid": "5000.00",
      "balance_due": "3000.00",
      "payments": [
        {
          "id": 44,
          "amount": "5000.00",
          "payment_method": "cash",
          "reference_number": null,
          "recorded_at": "2026-07-29T10:05:00+08:00"
        }
      ]
    }
  }
}
```

### Partial response rules

- Estimate-only: include `estimate`; omit `preparation`, `dispensing`, and
  `payment_summary`.
- Job-order-only, queued or in progress: include `preparation`; omit
  `estimate`, `dispensing`, and `payment_summary`.
- Ready Job Order: include `preparation` and `dispensing`.
- Dispensed Job Order with active Billing Record: include `preparation`,
  `dispensing`, and `payment_summary`, plus `estimate` when linked.
- Voided Billing Record: omit `payment_summary`, return
  `payment_status = null`, and return `balance_due = null`.

Empty placeholder objects and empty sections are prohibited. Item and payment
arrays may be empty only when the corresponding source record genuinely has no
visible child records.

## Authorization, Privacy, and Errors

### Patient isolation

Every list source query starts from the authenticated Patient ID. Detail lookup
must resolve the canonical key or alias inside the same patient scope.

Another patient's existing key or alias and a nonexistent key both return the
same `404` response. The API must not disclose cross-patient existence through
status, body, timing-dependent secondary queries, or alternative aliases.

### Excluded fields

The aggregate never returns:

- patient IDs or another patient's data;
- encounter, prescription, quotation-revision, or billing-record IDs;
- staff notes, internal notes, payment notes, Job Order notes, or quotation
  internal notes;
- recorder, presenter, accepter, optometrist, dispenser, reversal, or voider
  IDs;
- cost prices, inventory quantities, thresholds, targets, or inventory
  movement data;
- soft-delete timestamps, audit metadata, void reasons, correction reasons,
  or recipient identity.

The explicitly requested Job Order Item `id` and `product_variant_id` are
patient-safe references used for item display, frame detail, and rating
eligibility.

### Errors

| Condition | Status |
|---|---:|
| Missing or invalid Sanctum token | `401` |
| Patient profile absent | `404` |
| Key or alias absent/outside patient scope | `404` |
| Invalid filter/page/per_page | `422` |
| Authenticated API rate exceeded | `429` |

No route returns `403` for another patient's transaction; use `404` to prevent
record enumeration.

## Compatibility and Migration

Keep these existing endpoints temporarily:

```text
GET /api/v1/quotations
GET /api/v1/quotations/{quotation}
GET /api/v1/job-orders
GET /api/v1/job-orders/{jobOrder}
GET /api/v1/billing-records
GET /api/v1/billing-records/{billingRecord}
```

They are migration-only after the Eyewear contract is finalized. Their removal
requires a separately approved deprecation plan and confirmation that released
Android builds no longer call them.

The current API appendix contains 33 routes. Adding the two read-only Eyewear
routes changes the documented total to 35 while the six legacy read routes
remain.

## Tech Stack

- PHP 8.5
- Laravel 13.12
- Laravel Sanctum 4.3
- MySQL
- Pest 4.7 / PHPUnit 12.5
- Laravel Sail

No dependency addition is expected.

## Commands

All commands run through Sail:

```bash
vendor/bin/sail artisan route:list --path=api/v1 --except-vendor
vendor/bin/sail artisan test --compact tests/Feature/Api/EyewearApiTest.php
vendor/bin/sail bin pint --dirty --format agent
```

Any migration must use the normal Laravel generator with `--no-interaction`
before editing:

```bash
vendor/bin/sail artisan make:migration add_eyewear_keys_to_quotations_and_job_orders --no-interaction
```

## Project Structure

Expected implementation locations:

```text
routes/api.php
    Authenticated read-only routes.

app/Http/Controllers/Api/
    Patient-scoped list and detail controller.

app/Http/Requests/Api/
    List query validation.

app/Http/Resources/
    Summary and detail serialization.

app/Actions/Eyewear/ or app/Queries/Eyewear/
    Aggregate assembly and deterministic mapping.

app/Enums/
    Aggregate progress and payment-status enums if useful.

database/migrations/
    Persisted stable key columns and backfill.

tests/Feature/Api/
    Patient contract and authorization tests.

docs/
    Authoritative context, contract, and endpoint appendix updates.
```

No new top-level source directory or aggregate database table is proposed.

## Code Style

Follow existing Laravel naming, explicit type declarations, API Resources, and
patient-scoped Eloquent queries. Mapping must be centralized rather than
duplicated between list and detail.

Illustrative style:

```php
public function show(Request $request, string $key): JsonResponse
{
    $patient = $request->user()->patient;

    abort_unless($patient !== null, 404);

    $eyewear = $this->findPatientEyewear->handle($patient, $key);

    return response()->json([
        'data' => EyewearDetailResource::make($eyewear),
    ]);
}
```

The final class boundaries are a Phase 2 planning decision.

## Testing Strategy

Use focused Pest feature tests with `RefreshDatabase`, factories, Sanctum
authentication, exact JSON paths/types, and database-backed relationships.

Required coverage:

1. unauthenticated access returns `401`;
2. a patient sees only their aggregates;
3. another patient's canonical key and Job Order alias return `404`;
4. presented and accepted estimate-only records appear as
   `estimate_available`;
5. Draft estimates are excluded;
6. Job-order-only records produce preparation-only details;
7. complete linked records produce one aggregate, not duplicate list items;
8. every Quotation and Job Order status maps to the specified progress;
9. Current and History filters ignore payment state;
10. dispensed with balance due remains History with
    `payment_status = balance_due`;
11. paid Billing Records produce `payment_status = paid`;
12. absent and voided Billing Records produce null payment summary fields and
    omit the detail section;
13. Billing, Job Order, then Estimate total precedence uses exact decimal
    strings;
14. consultation timestamp follows Encounter to Appointment and remains null
    when unavailable;
15. created timestamp remains separately labelled contract data;
16. description precedence and fallback are deterministic;
17. partial detail sections are omitted rather than serialized as null or
    empty placeholders;
18. only posted Payments are returned in deterministic order;
19. pagination defaults, validation, envelope, page boundaries, activity
    ordering, and key tie-breakers are deterministic;
20. the persisted key survives estimate-to-Job-Order progression;
21. `jo_{id}` resolves to the canonical aggregate;
22. duplicate linked Quotation and Job Order records produce one list entry;
23. sensitive internal fields are absent recursively from list and detail;
24. only GET routes exist for Eyewear.

The implementation phase follows test-driven development: write failing
contract tests first, then implement the smallest passing slice.

## Documentation Deliverables

Before declaring the contract finalized:

1. update `docs/BACKEND_CONTEXT.md` with the aggregate, stable-key strategy,
   compatibility status, and new route count;
2. add exact request, response, pagination, enum, nullable/optional, error, and
   alias semantics to `docs/API_CONTRACT.md`;
3. add both routes to the complete endpoint appendix;
4. change the appendix route count from 33 to 35;
5. include complete list, estimate-only detail, job-order-only detail,
   complete linked detail, and voided-Billing examples;
6. record the implementing backend commit hash only after the implementation
   and documentation are committed;
7. explicitly state **Contract finalized for Android specification** only
   after tests and documentation agree with the committed code.

## Boundaries

### Always

- Scope every query to the authenticated patient.
- Serialize money as exact two-decimal strings.
- Preserve one canonical key across lifecycle progression.
- Use deterministic ordering and tie-breakers.
- Use the accepted Quotation Revision linked by the Job Order.
- Exclude internal and privacy-sensitive fields.
- Keep existing read endpoints during migration.
- Update tests and authoritative documentation with the implementation.

### Ask first

- Change the proposed canonical-key persistence or alias format.
- Add an aggregate table.
- Add a genuine expected-completion database field.
- Change Billing Record void semantics.
- Change authentication, rate limits, or existing endpoint behavior.
- Remove or deprecate legacy endpoints.
- Add dependencies.

### Never

- Require Android to join separate paginated APIs.
- Expose Draft Quotations.
- Let payment state determine Current or History.
- Infer consultation time from `created_at`.
- Infer expected completion from `ready_at`.
- Expose staff notes, costs, recorder IDs, or internal audit data.
- Add patient mutation routes for this aggregate.
- Treat the opaque key as the authorization boundary.

## Success Criteria

Phase 1 is approved when:

- the aggregate identity, alias, voided-Billing, pagination, description, and
  activity-ordering rules are explicitly accepted;
- every requested response field has a stable type and nullability contract;
- every progress and payment state has one deterministic mapping;
- partial response behavior is unambiguous;
- privacy and patient-isolation boundaries are explicit;
- success and error responses are implementable and testable;
- this specification is stored in version control.

Implementation is complete only when:

- both read-only routes match this approved contract;
- all required backend tests pass;
- the six legacy read endpoints remain available;
- `BACKEND_CONTEXT.md`, `API_CONTRACT.md`, appendix examples, and route count
  match the code;
- the implementation is committed and its hash is recorded; and
- the backend explicitly announces that the contract is finalized for Android.

## Approved Open-Question Decisions

The project owner approved the recommended answers on 2026-07-29:

1. Persist `eyw_{ULID}` keys on Quotations and Job Orders and accept
   `jo_{job_order_id}` as the migration alias.
2. Treat voided Billing Records as inactive: omit `payment_summary`, return
   null summary payment fields, and ignore the voided record for total
   precedence.
3. Default an omitted filter to `current`, default `per_page` to `15`, and
   enforce a maximum of `50`.
4. Include posted payment timestamps in `activity_at` ordering without ever
   allowing payment state to change Current or History membership.

There are no remaining Phase 1 open questions.
