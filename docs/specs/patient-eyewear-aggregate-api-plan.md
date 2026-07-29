# Implementation Plan: Patient Eyewear Aggregate API

## Status

Draft Phase 2 plan for project-owner review on 2026-07-29.

The Phase 1 specification is approved in
`docs/specs/patient-eyewear-aggregate-api-spec.md`. This plan does not authorize
implementation. Phase 3 task breakdown remains gated on approval of this plan.

## Overview

Implement two authenticated, patient-scoped, read-only endpoints that combine
the patient-visible parts of Quotations, Job Orders, Billing Records, and
posted Payments into one stable Eyewear transaction.

The implementation will preserve the separate clinic records and existing
patient endpoints. It will add stable lifecycle keys, centralize aggregate
mapping, paginate a de-duplicated database query, serialize through dedicated
API Resources, and reconcile the authoritative documentation only after the
contract tests pass.

## Current Baseline

- The API contains 33 documented `/api/v1` routes.
- Quotations have a patient-safe API Resource, but Job Orders and Billing
  Records currently use raw model serialization.
- Quotations link to Job Orders through:
  `job_orders.quotation_revision_id -> quotation_revisions.quotation_id`.
- Billing Records have a unique `job_order_id`.
- Encounters link to Appointments through nullable `appointment_id`.
- There is no expected-completion field.
- Quotation, Job Order, and Billing list endpoints paginate independently.
- The worktree contains unrelated approved UI and workflow changes. Eyewear
  implementation must not mix those changes into its commits.

## Architecture Decisions

### 1. Persist the lifecycle key on existing source records

Add a required, unique `eyewear_key` string to both `quotations` and
`job_orders`.

```text
Quotation created       -> generate eyw_{ULID}
Job Order from estimate -> copy Quotation key
Job Order without quote -> generate eyw_{ULID}
```

Rationale:

- the estimate key remains stable after a Job Order is created;
- Job-order-only records remain addressable;
- one unique Job Order key prevents two Job Orders from representing the same
  aggregate;
- no new aggregate table is introduced;
- lookup uses indexed values rather than reversible or recomputed identifiers.

The migration will add nullable columns, backfill Quotations first, copy keys
to linked Job Orders, generate keys for unlinked Job Orders, verify uniqueness,
and then make both columns required. Because the system is undeployed and data
is replaceable, conflicting development records may be reported for cleanup
rather than silently merged.

Model creation hooks provide a safe key when records are created outside the
normal action. `CreateJobOrder` explicitly copies the Quotation key so the
normal lifecycle cannot generate a different one.

### 2. Keep lookup identity separate from authorization

Canonical detail lookup accepts `eyw_{ULID}`. Migration lookup additionally
accepts `jo_{numeric_id}`.

Both lookup paths begin inside the authenticated Patient scope. The controller
does not resolve a global model and authorize afterward. Missing and
cross-patient keys therefore follow the same `404` path.

The response always returns the canonical key.

### 3. Centralize aggregate mapping

Use an Eyewear service namespace under the existing `app/Services` directory;
do not create a new top-level application directory.

The logical responsibilities are:

```text
ListPatientEyewear
    Builds the de-duplicated, filtered, ordered paginator.

FindPatientEyewear
    Resolves a canonical key or Job Order alias within patient scope.

BuildEyewearAggregate
    Applies description, date, progress, payment, money, and section rules.

EyewearAggregate
    Typed read model shared by list and detail Resources.
```

List and detail must call the same aggregate builder. Progress, payment,
description, total precedence, and timestamp rules must not be duplicated in
controllers or Resources.

Exact class names may be adjusted in Phase 3 to match neighboring conventions,
but the responsibility boundaries remain fixed.

### 4. Paginate a database-level union before hydration

Do not load all patient transactions into PHP before pagination.

Build two compatible query branches:

1. non-deleted Job Orders, which are authoritative once present;
2. non-draft, non-deleted Quotations that do not have a non-deleted Job Order
   through any revision.

Each branch returns only the fields needed to identify and order a candidate:

```text
source_type
source_id
eyewear_key
progress
activity_at
```

Combine with `UNION ALL`, apply Current or History filtering, then order by:

```text
activity_at DESC, eyewear_key ASC
```

Apply the length-aware paginator to this combined query. Hydrate only the
source IDs on the requested page with bounded eager loads, preserve paginator
order, and map them through `BuildEyewearAggregate`.

This structure provides:

- deterministic page-number pagination;
- no linked Quotation/Job Order duplicate;
- no N+1 relation loading;
- a stable place to add query-plan indexes if evidence requires them.

The implementation will inspect the generated SQL and MySQL `EXPLAIN`. New
composite indexes are added only when the plan shows they are useful; the
unique key and existing patient foreign-key indexes are the baseline.

### 5. Select the correct commercial snapshot

For a Job Order, the estimate section uses the exact linked
`quotation_revision_id`, not the Quotation's current latest revision.

For an estimate-only aggregate, use the latest visible revision.

Job-order-only records do not synthesize an estimate section.

### 6. Treat active Billing Records explicitly

The aggregate loads at most one Billing Record through the unique Job Order
relationship.

```text
active   = not soft-deleted and status != voided
inactive = absent, soft-deleted, or voided
```

Only active Billing Records participate in:

- list total precedence;
- `payment_status`;
- `balance_due`;
- `payment_summary`;
- payment-derived `activity_at`.

Only posted Payments are loaded and serialized. A voided Billing Record remains
available through the temporary legacy endpoint but is intentionally absent
from the Eyewear financial summary.

### 7. Use dedicated Resources and request validation

Add:

- one Form Request for list query validation and defaults;
- one controller with `index` and `show`;
- a summary Resource;
- a detail Resource that reuses the summary mapping and conditionally merges
  sections.

Resources serialize every monetary value through one two-decimal formatter.
They never serialize Eloquent models directly.

Nullable sections are omitted with conditional resource merging. They are not
returned as `null` or empty placeholder objects.

### 8. Preserve compatibility

Register only:

```text
GET /api/v1/eyewear
GET /api/v1/eyewear/{key}
```

Keep the six existing Quotation, Job Order, and Billing Record GET routes
unchanged during Android migration.

No redirects or behavior changes are added to the legacy endpoints in this
phase.

## Dependency Order

```text
Approved specification
    |
    v
Stable key schema and generation
    |
    v
Model relationships and key propagation
    |
    v
Typed aggregate builder
    |                    \
    v                     v
Detail lookup          List union query
    \                     /
     v                   v
       API Resources + Controller
                 |
                 v
          Routes + contract tests
                 |
                 v
       Documentation reconciliation
```

Key persistence must precede list and detail work. The aggregate builder must
precede Resources so list and detail cannot establish competing mapping logic.
Documentation becomes authoritative only after implementation output is
verified.

## Implementation Stages

### Stage A: Stable identity foundation

Add and backfill the canonical keys, update model fillable state and factories,
and make Job Order creation copy the Quotation key.

Verification checkpoint:

- existing and newly created records have valid keys;
- estimate-to-Job-Order progression preserves the key;
- Job-order-only records receive a key;
- uniqueness rejects duplicate lifecycle Job Orders;
- migration rollback restores the prior schema.

### Stage B: Aggregate domain mapping

Implement the typed aggregate builder and cover:

- progress precedence and every status mapping;
- active/voided/absent Billing behavior;
- exact decimal formatting;
- description precedence and fallback;
- consultation, created, and activity timestamps;
- selected revision correctness;
- conditional detail sections.

This is the highest semantic-risk stage and should be completed before query
and HTTP concerns are added.

Verification checkpoint:

- estimate-only, job-order-only, linked complete, cancelled, dispensed with
  balance, paid, and voided scenarios all produce exact expected arrays;
- sensitive fields do not enter the aggregate read model.

### Stage C: Deterministic list query

Implement the two-branch union, Current/History filtering, duplicate exclusion,
activity ordering, tie-breaking, and length-aware pagination.

Verification checkpoint:

- one linked lifecycle creates one row;
- every filter contains exactly its approved progress values;
- payment state does not affect membership;
- repeated queries with equal activity timestamps retain the same order;
- page boundaries and totals are correct;
- query count remains bounded.

### Stage D: Patient HTTP contract

Add query validation, patient-scoped key and alias resolution, Resources,
controller methods, and the two GET routes.

Verification checkpoint:

- exact list envelope and complete/partial detail JSON match the specification;
- unauthenticated access returns `401`;
- cross-patient keys and aliases return `404`;
- invalid queries return `422`;
- only GET routes exist;
- recursive forbidden-field assertions pass.

### Stage E: Compatibility and documentation closure

Keep the legacy endpoints green, update the context and API contract, add all
examples, update the endpoint appendix, and change the route count from 33 to
35.

Verification checkpoint:

- route-list output and appendix match;
- examples match test fixtures and actual serialization;
- existing legacy endpoint tests pass;
- `BACKEND_CONTEXT.md` and `API_CONTRACT.md` describe the same key, enum,
  nullability, pagination, and void behavior;
- the final committed backend hash is recorded;
- only then announce **Contract finalized for Android specification**.

## Testing Approach

Implementation follows test-driven development in vertical slices:

1. write a failing test for one approved behavior;
2. implement the smallest behavior that passes;
3. format changed PHP;
4. run the focused test;
5. run the affected feature file at each checkpoint;
6. run the broader API and domain suites before documentation is finalized.

Primary feature coverage belongs in:

```text
tests/Feature/Api/EyewearApiTest.php
```

Focused domain or migration tests may be separated when that keeps individual
files understandable. Existing factories should gain explicit states rather
than tests constructing large inconsistent record graphs manually.

Database-refreshing tests must run sequentially in this repository because
parallel Sail test processes share the same `testing` database.

## Verification Commands

```bash
vendor/bin/sail artisan test --compact tests/Feature/Api/EyewearApiTest.php
vendor/bin/sail artisan test --compact tests/Feature/JobOrders/CreateJobOrderTest.php
vendor/bin/sail artisan test --compact tests/Feature/Api
vendor/bin/sail artisan route:list --path=api/v1 --except-vendor
vendor/bin/sail bin pint --dirty --format agent
```

Run migration checks against the testing environment, never by destructively
resetting an unidentified database.

## Commit Strategy

Before implementation:

1. inspect and preserve the existing dirty worktree;
2. finish or commit the already-approved Encounter, Health Record, Quotation,
   and Job Order changes separately;
3. do not stage with broad patterns that mix those changes into Eyewear work.

Expected Eyewear save points:

1. stable key migration and propagation;
2. aggregate mapping;
3. paginated list and detail API;
4. documentation reconciliation.

Each save point must be independently tested and reviewable. No commit should
mix unrelated UI work, generated output, or Android changes.

## Parallelization

Safe only after the stable-key foundation is complete:

- documentation examples may be drafted while API contract tests are being
  completed, but cannot be finalized independently;
- privacy/forbidden-field review may inspect completed Resources independently;
- Android specification work may begin only after backend contract
  finalization.

Must remain sequential:

- migration and model key propagation;
- aggregate mapping before list/detail serialization;
- shared-database Pest runs;
- final documentation and route-count reconciliation.

No parallel implementation against the same files or testing database is
recommended for this feature.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Linked records appear twice | High | Exclude Quotations with a Job Order from the estimate branch and enforce one Job Order per canonical key. |
| Key changes after progression | High | Persist on Quotation and copy atomically into Job Order. |
| Cross-patient alias enumeration | High | Resolve canonical and alias lookups inside Patient scope; return identical `404`. |
| Wrong quotation revision displayed | High | Use Job Order's linked revision whenever present. |
| Voided bill appears collectible | High | Exclude it from active financial mapping and document null summary behavior. |
| Float precision leaks into JSON | High | Format database decimals centrally as two-decimal strings. |
| Payment changes move a record between filters | High | Filter exclusively on progress; payment affects only display and activity ordering. |
| In-memory pagination gives incorrect totals | Medium | Paginate the database-level union before hydration. |
| Derived activity ordering is slow | Medium | Select explicit event timestamps, inspect SQL and `EXPLAIN`, then add evidence-backed indexes. |
| Empty sections confuse Android | Medium | Conditional Resource merging plus exact absence assertions. |
| Existing dirty work is mixed into feature commits | Medium | Separate save points and explicit-path staging only. |
| Documentation drifts from runtime JSON | Medium | Generate examples from approved fixtures and reconcile only after contract tests pass. |

## Rollback Strategy

- The two new routes are additive and can be removed without changing legacy
  endpoints.
- Aggregate services and Resources have no mutation side effects.
- Model key generation can be removed after routes are withdrawn.
- The migration rollback removes only the two new key columns and indexes.
- Existing clinic records and legacy patient APIs remain intact throughout.

If Android has begun storing canonical keys, do not roll back the key columns
without coordinating a mobile rollback; the keys are then part of the public
contract.

## Plan Acceptance Criteria

This Phase 2 plan is ready for approval when:

- stable key persistence and alias resolution have an agreed implementation
  path;
- database-level de-duplication and pagination are explicit;
- list and detail share one mapping source;
- billing void, money precision, revision selection, and timestamp rules are
  preserved;
- implementation order and checkpoints follow the dependency graph;
- risks, rollback, compatibility, and commit isolation are addressed;
- there are no unresolved technical decisions.

## Open Questions

None. The approved Phase 1 decisions are sufficient for Phase 3 task
breakdown.

