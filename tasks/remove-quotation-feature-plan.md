# Implementation Plan: Remove the Quotation Feature

**Status:** Proposed 2026-09-14
**Checklist:** `tasks/remove-quotation-feature-todo.md`
**Scope:** 17 tasks in 6 phases

## Overview

Remove Quotations as an active product concept and make Optical Orders the only
optical-sale workflow. Staff will create an Optical Order directly from a
patient, current prescription, or completed encounter; billing continues from
the order or through the existing encounter/direct-service paths.

This is a clean break. There is no deprecation window, compatibility endpoint,
or application-level quotation archive. The final contract migration deletes
quotation rows and tables after the application no longer reads them.

Live database baseline on 2026-09-14:

| Data | Rows |
|---|---:|
| `quotations` | 4 |
| `quotation_items` | 8 |
| `job_orders.quotation_id IS NOT NULL` | 2 |
| `billing_records.quotation_id IS NOT NULL` | 0 |
| `billing_record_items.quotation_item_id IS NOT NULL` | 0 |
| `billing_record_items.source_kind = quotation` | 0 |
| Quotation audit rows / metadata | 0 |
| Notification rows containing quotation text | 0 |

The data volume is negligible. The work is dominated by removing code
coupling across Filament, order creation, billing provenance, reports,
dashboards, seeders, tests, and the patient Optical Order API.

## Scope Boundary

Complete removal means:

- no quotation navigation, routes, global search, actions, widgets, or links;
- no quotation models, policy, enum, factories, seed data, or domain actions;
- no `source_quotation` field in the patient Optical Order API;
- no quotation billing source kind or quotation foreign-key columns;
- no `quotations` or `quotation_items` tables or quotation audit data; and
- no quotation behavior described by current product/API/context documents.

Historical migrations, superseded specs, completed task plans, and historical
ADRs may retain the word “quotation”. They are records of how the deployed
schema and design evolved, not active feature surfaces. Squashing all migration
history solely to remove the word is excluded because it raises deployment and
fresh-install risk without reducing runtime cost.

## Architecture Decisions

### 1. Optical Order becomes the only optical-sale aggregate

Promote `CreateDirectOpticalOrder` to `CreateOpticalOrder` and remove “Direct”
from page titles and tests. Patient, prescription, and encounter actions will
open that flow with context prefilled.

There is intentionally no replacement for draft proposals, delayed quotation
acceptance/decline, or quotation-specific discounts. Order creation already
supports discounts, deposits, immediate/prepared fulfillment, and billing.
Services continue through encounter billing or direct-service charges.

### 2. Preserve completed orders, delete only quotation history

The two linked Optical Orders remain business records. Their quotation link is
detached before `job_orders.quotation_id` is dropped. The migration must not
delete Job Orders, inventory movements, dispensing events, billing records, or
payments.

Financial safety takes precedence over automatic cleanup: the contract
migration must abort if any environment contains quotation-sourced billing
items, because deleting or relabeling posted financial lines silently would be
unsafe. The inspected database currently has none.

### 3. Rename shared behavior before deleting the quotation namespace

`BuildQuotationItemSnapshot` and `ValidateOpticalQuotation` are used by direct
Optical Orders. Move and rename them to Optical Order terminology, preserving
their behavior and useful tests, before removing `app/Actions/Quotations`.

### 4. Preserve encounter linkage during the replacement cutover

The Optical Order create page accepts an `encounter` query parameter today but
the action currently writes `encounter_id = null`. Fix and test that gap before
retargeting the completed-encounter action. Encounter pages then query
`JobOrder` directly instead of traversing Quotation.

### 5. Application cutover and schema destruction are separate releases

Release A removes all consumers while leaving the old nullable columns and
tables inert. After that release is fully deployed and verified, Release B
deletes quotation data, removes inbound foreign keys/columns, and drops the two
tables. This preserves rollback through the application cutover and isolates
the irreversible step.

The migration `down()` recreates the old structure for technical rollback, but
cannot recreate deleted rows. Take a short-lived database snapshot immediately
before Release B; no long-term quotation export is retained.

## Dependency Graph

```text
Characterize replacement behavior
              │
              ▼
Rename shared helpers ──► Promote canonical Create Optical Order
                                      │
                        ┌─────────────┴─────────────┐
                        ▼                           ▼
              Patient/prescription links     Encounter links + linkage
                        └─────────────┬─────────────┘
                                      ▼
                     Remove UI/API/report/billing consumers
                                      │
                                      ▼
                 Delete Filament + quotation domain + seed/test code
                                      │
                           Release A verification / bake
                                      │
                                      ▼
                 Destructive data/schema contract migration (Release B)
                                      │
                                      ▼
                       Current docs + final zero-runtime-ref audit
```

## Task List

### Phase 0: De-risk the replacement

- [ ] Task 0: Characterize the behaviors that must survive removal
- [ ] Task 1: Rename the shared item snapshot builder
- [ ] Task 2: Rename the shared optical-item validator
- [ ] Task 3: Promote direct order creation to canonical Optical Order creation

### Phase 1: Replace all quotation entry points

- [ ] Task 4: Retarget patient and prescription actions
- [ ] Task 5: Retarget encounter actions and preserve encounter linkage

### Checkpoint: Replacement path

- [ ] Patient, prescription, and completed-encounter actions create Optical
      Orders without a Quotation
- [ ] Corrective eyewear, fulfillment, inventory, discount, deposit, billing,
      and notification behaviors remain covered
- [ ] Focused Optical Order, encounter, prescription, and patient tests pass

### Phase 2: Remove cross-cutting consumers

- [ ] Task 6: Remove quotation from Optical Order and Billing Record UI
- [ ] Task 7: Remove `source_quotation` from the patient API
- [ ] Task 8: Remove quotation billing provenance and report labels
- [ ] Task 9: Remove quotation dashboard metrics and navigation assertions
- [ ] Task 10: Remove model and catalog lifecycle relations

### Checkpoint: No active consumers

- [ ] Quotation URLs are no longer generated from active screens
- [ ] The patient API contract contains no quotation field
- [ ] No dashboard, report, billing label, or eager load queries Quotations
- [ ] Full suite passes against the still-expanded database

### Phase 3: Delete the quotation subsystem

- [ ] Task 11: Unregister and delete the Filament Quotation resource
- [ ] Task 12: Delete quotation lifecycle/conversion domain code
- [ ] Task 13: Delete quotation models, policy, enum, factories, and seed data
- [ ] Task 14: Remove obsolete tests and relocate surviving invariant tests

### Checkpoint: Release A

- [ ] `rg` finds no active quotation reference in `app/`, `routes/`, `config/`,
      `database/factories/`, or `database/seeders/`
- [ ] Quotation Filament routes return 404 and no navigation item is registered
- [ ] `migrate:fresh --seed` and the full test suite pass
- [ ] Deploy and verify Release A before authorizing the contract migration

### Phase 4: Delete historical data and schema

- [ ] Task 15: Ship the destructive quotation contract migration as Release B

### Checkpoint: Release B

- [ ] `quotations` and `quotation_items` do not exist
- [ ] No quotation foreign-key columns or indexes remain
- [ ] Existing Job Orders, inventory movements, billing, and payments remain
- [ ] Migration `up`, `down`, and a second `up` pass on a database snapshot

### Phase 5: Reconcile current documentation and finish

- [ ] Task 16: Update current product/API/context documentation and supersede
      the quotation workflow decision

### Checkpoint: Complete

- [ ] Current documentation describes Optical Order → Billing only
- [ ] Runtime-reference audit passes; historical-only references are reviewed
- [ ] Full suite passes and Pint is clean
- [ ] Ready for code review and deployment sign-off

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Removing quotation conversion loses an order invariant | High | Characterize direct and quotation-derived behavior first, then retain relevant assertions under Optical Order tests |
| Completed encounters lose their order link | High | Add explicit `Encounter` support to canonical order creation before retargeting any UI action |
| External Android code expects `source_quotation` | High | Treat the field removal as a clean API contract break and coordinate the Android consumer before Release A |
| Financial rows exist in another environment despite the inspected database showing zero | High | Contract migration preflight aborts on quotation-sourced billing rows; resolve deliberately rather than mutating posted charges |
| Rolling deployment runs old code after tables are dropped | High | Separate consumer removal and schema drop into Release A and Release B, with a bake period between them |
| Irreversible data deletion blocks rollback | Medium | Take a short-lived pre-contract database snapshot and test structural `down()`; do not build an application archive/export path |
| Historical documents make a broad text scan look incomplete | Low | Define and audit active-runtime paths separately; mark current conflicting decisions superseded and leave historical records intact |

## Approval Gate

Implementation should begin only after approval of this clean-break behavior:

1. Optical Orders replace all “Create Quotation” entry points.
2. Draft/accept/decline proposal behavior disappears without replacement.
3. The patient API drops `source_quotation` rather than retaining a deprecated
   always-null field.
4. Quotation data is permanently deleted in Release B, while linked Orders and
   financial records are preserved.
