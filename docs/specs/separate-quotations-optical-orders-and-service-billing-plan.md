# Plan: Separate Quotations, Optical Orders, and Service Billing

## Status

Approved by the project owner on 2026-08-03. Phase 2 (**Plan**) is complete.
This approval authorizes Phase 3 (**Tasks**) only; implementation must wait for
separate approval of the resulting task breakdown.

## Outcome

The commerce area will have four explicit responsibilities:

```text
Quotation (proposal)
    -> Confirm Sale
        -> Optical Order (physical products only, when products exist)
        -> Billing Record (products plus explicitly performed services)

Direct product sale
    -> Optical Order
    -> Billing Record

Encounter/direct service
    -> Billing Record only
```

Staff will enter one **Optical Orders** workspace and switch between separate
**Orders** and **Quotations** sections. Mobile patients will receive separate
Quotation and Optical Order representations rather than the existing combined
eyewear aggregate.

## Planning Constraints

- The application has not been deployed; local and demo commerce data is
  disposable.
- No compatibility routes, response aliases, backfills, dual writes, or legacy
  read paths will be built.
- `job_orders`, `job_order_items`, and the `JobOrder` model remain the internal
  fulfillment implementation. Staff and API terminology is **Optical Order**.
- Persisted Job Order status values remain unchanged to avoid an unnecessary
  enum rewrite. UI and API labels map them to Confirmed, Processing, Ready for
  Pickup, Completed, and Cancelled.
- No reusable Services catalog, retained-work resource, tax engine, refund
  workflow, or official-invoice integration is introduced.
- Existing unrelated patient, appointment, encounter, prescription, inventory,
  authentication, privacy, and messaging behavior remains in scope for
  regression protection only.
- No package dependency changes are required.

## Architecture Decisions

### 1. Admin workspace

Use a Filament 5 cluster named **Optical Orders** with top sub-navigation. This
provides one sidebar destination without a custom wrapper page:

```text
Optical Orders
    [ Orders ] [ Quotations ]
```

- **Orders** is the default cluster resource and is backed only by `JobOrder`.
- **Quotations** is backed only by `Quotation`.
- The current quotation-backed `OpticalOrderResource` is replaced by the
  JobOrder-backed resource rather than retained as an aggregate.
- The hidden duplicate Job Order resource is consolidated into that resource.
- Both resources keep independent routes, filters, tables, forms, and detail
  pages while sharing the cluster navigation.

The cluster is registered through Filament discovery/configuration following
the installed v5 conventions. The existing **Fulfillment & Finance** navigation
group remains the parent location.

### 2. Canonical commerce schema

Replace only the commerce migration chain with a coherent fresh-install path;
do not rewrite unrelated application migrations.

#### Quotations

`quotations` continues to own patient, optional Encounter and Prescription
context, proposal status, totals, discount, validity, presentation, and
acceptance metadata.

`quotation_items` contains:

- `item_type`: Product or Service only;
- description, quantity, unit price, and amount snapshots;
- nullable Product Variant and Lens Category links for Product rows;
- no revision, compatibility, or eyewear-key fields.

Database constraints and model validation enforce that Service rows have no
Product Variant or Lens Category relationship.

#### Optical Orders

`job_orders` continues to own patient, optional Quotation, Encounter,
Prescription, Frame Reservation, fulfillment mode, supplier flag/invoice,
status, total, and operational timestamps.

`job_order_items` becomes structurally Product-only. It therefore does not need
an item-type discriminator. Each row stores the committed Product description,
quantity, unit price, amount, and optional Product Variant or Lens Category.

The source Quotation relationship is unique so a Quotation cannot create more
than one Optical Order. A Service-only Quotation legitimately has no Job Order.

#### Billing

`billing_records` remains the receivable and adds nullable `quotation_id` next
to its nullable Job Order and Encounter context.

`billing_record_items` stores immutable Product or Service snapshots plus a
required source classification:

| Source kind | Required origin | Allowed type |
|---|---|---|
| `optical_order` | `job_order_item_id` | Product |
| `quotation` | `quotation_item_id` | Service |
| `encounter` | `encounter_id` | Service |
| `direct_service` | no operational FK | Service |

A quoted Service may also retain optional Encounter context, but its canonical
source kind remains `quotation`. Unique origin constraints make Product and
quoted-Service snapshotting idempotent.

The inactive `orders`, `billings`, `billing_items`, and `service_records`
tables, revision-era tables/columns, eyewear keys, `legacy_other`, and unused
models are excluded from the replacement schema.

### 3. Financial calculations

Money continues to be calculated in integer cents in actions and persisted as
two-decimal values under existing conventions.

```text
JobOrder.total_amount       = sum of committed Product item amounts
BillingRecord.subtotal      = sum of all Product and Service charge amounts
BillingRecord.total_amount  = subtotal - one checkout-level discount
BillingRecord.balance       = total - posted payments
```

The accepted Quotation discount is copied once to the Billing Record and is not
allocated across individual lines. The plan does not change taxes, refunds, or
payment correction semantics.

### 4. Domain actions and transaction boundary

Introduce one orchestration action, `ConfirmQuotationSale`, to replace the
overlapping quotation-to-order flows. It accepts the Quotation, actor,
explicit performed-Service item IDs, due date, fulfillment settings, optional
Frame Reservation, and optional deposit details.

Inside one database transaction it will:

1. lock and validate the Quotation;
2. accept it once;
3. create one Optical Order only when Product lines exist;
4. copy only Product lines;
5. commit only catalog-backed Product inventory;
6. convert the selected Frame Reservation only when an Order exists;
7. resolve one open Billing Record;
8. snapshot Order products and explicitly selected quoted Services;
9. apply the Quotation discount and due date once;
10. record an optional deposit last, after reviewed-charge acknowledgement.

Idempotency comes from source relationships and unique constraints, not text
comparison. A retry returns the existing records without duplicating charges,
inventory movements, reservations, or payments.

Smaller actions will retain one concern each:

- resolve an open checkout before any posted payment;
- append Optical Order Product items;
- append selected quoted Service items;
- add Encounter Service charges;
- add immediate Direct Service charges;
- recalculate totals;
- record, correct, and void payments under existing rules;
- advance or cancel fulfillment independently of payment.

The old action that copies every Quotation line, the duplicate Job Order
creation action, and mixed-item immediate-completion paths are removed once
their replacement tests pass.

### 5. Direct transactions

The **New direct order** form accepts Product rows only. Corrective Product
rules, inventory rules, fulfillment mode, supplier requirements, and optional
Prescription context remain enforced by domain actions.

Encounter charges stay in the Encounter page's separate Billing section. A
staff-only direct Service action creates or reuses a chargeable Billing Record
without fabricating a Quotation, Encounter, or Optical Order. These two Service
entry paths share validation and snapshot formatting but retain distinct
provenance.

### 6. Payment locking

All charge-appending actions reject a Billing Record after its first posted
payment. Every admin entry point that can create that first payment or deposit
must display the complete charge summary and require explicit acknowledgement
that the charge set will be finalized.

If a later Service must be charged after payment, the resolver creates a new
Billing Record. Existing void-and-reissue and payment-correction boundaries are
preserved.

### 7. Patient API replacement

Keep API v1 and replace the unreleased contracts in place:

```text
GET  /api/v1/quotations
GET  /api/v1/quotations/{quotation}
GET  /api/v1/optical-orders
GET  /api/v1/optical-orders/{opticalOrder}
POST /api/v1/optical-order-items/{item}/rating
```

Use controllers plus Eloquent API Resources and existing pagination envelopes.
Route model binding is scoped by the authenticated account's active Patient
link; unauthorized or hidden records resolve as not found.

Quotation responses expose presented proposal Product and Service lines,
status, validity, totals, and optional generated Optical Order reference. Draft
Quotations remain staff-only.

Optical Order responses expose:

- public Order number and patient-safe status label;
- Product items only;
- source Quotation reference when present;
- fulfillment and pickup timestamps appropriate for the patient;
- order Product total;
- payment summary containing checkout discount, overall total, amount paid,
  remaining balance, due date, and aggregate
  `other_clinic_charges_amount`.

They do not expose Service descriptions, Encounter data, prescription details,
supplier invoice data, internal notes, or staff identities.

Remove the patient `/job-orders`, `/eyewear`, and `/billing-records` routes,
their controllers/resources/aggregate services, compatibility identifiers, and
tests. The renamed rating route receives equivalent ownership and dispensed-
Order protection.

### 8. Factories and seed data

Factories gain explicit states for Product and Service Quotation lines,
product-only Optical Orders, and each Billing item provenance. Invalid
mixed-state factory defaults are removed.

Seeders will demonstrate at least:

- a Presented mixed Quotation;
- a confirmed prepared Optical Order with Product-only items;
- a Service-only accepted Quotation with a bill and no Order;
- a Combined Billing Record with payment summary;
- a direct Product Order and an Encounter Service charge.

Seed data must not rely on removed tables, aliases, revision models, or
`legacy_other`.

## Implementation Sequence

### Increment 1: Baseline and safety characterization

Run the existing focused suites and record failures already caused by stale
revision resources. Add target schema and domain tests before changing
behavior. Verify the configured database environment before any reset command.

Exit condition: the current behavior and unrelated regression surface are
known, and the first target tests fail for the intended reasons.

### Increment 2: Clean commerce schema and model invariants

Replace the commerce migration chain, enums, relationships, casts, factories,
and seeders. Remove invalid schema paths only after the fresh-schema tests cover
the target tables and constraints.

Exit condition: a fresh testing database can represent all approved
transactions and cannot represent a Service as an Optical Order item.

### Increment 3: Billing provenance and lock rules

Implement source-specific Billing append actions, direct Service charging,
total reconciliation, unique-origin idempotency, and the posted-payment lock.

Exit condition: Product-only, Service-only, and Combined bills reconcile, and
all charge sources remain traceable without fake operational records.

### Increment 4: Quotation confirmation and direct Order workflows

Implement the atomic confirmation orchestrator, Product-only conversion,
explicit Service selection, Service-only acceptance, inventory/reservation
handling, direct Product Orders, and optional deposit sequencing.

Exit condition: all conversion variants, safe retries, validation failures, and
transaction rollbacks pass focused Pest tests.

### Increment 5: Filament workspace

Create the Filament cluster through the framework generator, consolidate the
Orders resource around `JobOrder`, repair the Quotation resource around direct
fields/items, and update Billing and Encounter charge panels.

Exit condition: Filament tests prove independent queries, correct tabs/actions,
explicit Service selection, source labels, traceability links, and first-payment
acknowledgement.

### Increment 6: Mobile API replacement

Add the canonical Optical Order resources/controllers/routes, tighten
Quotation serialization, rename the rating route, and remove old mobile
commerce surfaces.

Exit condition: ownership, visibility, pagination, privacy, summaries, and 404
behavior for removed routes pass API tests.

### Increment 7: Dead-code removal and documentation alignment

After replacement consumers pass, remove revision-era, eyewear aggregate,
compatibility, inactive commerce, mixed-item code, and obsolete tests. Replace
obsolete assertions with target behavior coverage rather than silently reducing
coverage. Update `BACKEND_CONTEXT.md`, API contract documentation, and the
superseded commerce spec/plan/task statuses to match the implemented system.

Exit condition: repository search finds no live references to removed commerce
concepts, except intentional historical notes in superseded documentation.

### Increment 8: Full verification and local reset

Run fresh migration/seed verification only after confirming the database is
local or testing, then execute the full regression, format, and asset gates.

Exit condition: clean install, seed, focused suites, full Pest, Pint, and the
production frontend build all pass.

## Dependency Order

```text
Schema and model invariants
    -> Billing provenance/actions
        -> Quotation confirmation and direct transactions
            -> Filament workspace
            -> Patient API
                -> dead-code removal
                    -> documentation and full verification
```

Filament and API work may proceed independently only after the domain actions
and response semantics are stable. Cleanup must wait until both replacement
consumers are verified.

## Test Strategy

Every implementation task begins with a failing or characterization Pest test
and runs the smallest relevant Sail command before advancing.

### Required focused coverage

- fresh commerce schema, constraints, factories, and seeders;
- Quotation lifecycle and edit-after-presentation behavior;
- Product-only, mixed, and Service-only confirmation;
- explicit versus unselected quoted Services;
- direct Product Orders, Encounter charges, and Direct Service charges;
- prescription, inventory, reservation, supplier, and fulfillment rules;
- idempotent retries and transaction rollback under partial failure;
- Billing reconciliation and first-payment locking;
- Filament resource isolation, forms, actions, navigation, and permissions;
- API active-link ownership, privacy, pagination, response envelopes, and
  removed-route 404s;
- Frame rating ownership and completed-Order eligibility;
- unrelated appointment, encounter, patient-account, and inventory regressions.

### Final gates

```text
vendor/bin/sail artisan migrate:fresh --seed --no-interaction
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
vendor/bin/sail artisan route:list --except-vendor
```

PHP, Artisan, Composer, and Node commands run through Sail. The destructive
migration command is forbidden until the active environment is positively
identified as local or testing.

## Risk Controls

| Risk | Control |
|---|---|
| Duplicate charges or inventory on retry | row locks, unique source constraints, source-ID idempotency tests |
| Quoted Service mistaken for performed work | unchecked-by-default confirmation list and explicit selected IDs |
| Payment freezes an incomplete bill | full summary plus required acknowledgement before first payment/deposit |
| Service leaks through mobile Order data | dedicated API Resource allowlist and privacy assertions |
| UI still mixes proposal and fulfillment states | separate model-backed Filament resources and query-isolation tests |
| Clean reset affects valuable data | environment verification; no reset outside local/testing |
| Removing compatibility reduces useful coverage | replacement tests land before obsolete tests/code are removed |
| Discount counted twice | Billing owns one checkout discount; reconciliation and retry tests |

## Rollback Strategy

Because there is no deployed production data, rollback is source-level rather
than data migration:

1. stop at the failing increment;
2. revert only that increment's application changes;
3. rebuild the local/testing database from the last coherent migration set;
4. rerun the focused characterization and regression suites.

No dual-schema rollback, backfill, or old mobile endpoint compatibility layer
will be maintained.

## Phase 2 Approval Gate

Project-owner approval confirms the architecture, implementation order, reset
strategy, and test boundaries above. It authorizes Phase 3 (**Tasks**) only and
does not authorize application-code implementation.
