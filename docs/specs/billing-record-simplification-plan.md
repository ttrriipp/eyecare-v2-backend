# Implementation Plan: Internal Billing Record Simplification

## Status

Approved by the project owner on 2026-07-28. Phase 2 is complete.
Implementation remains gated on approval of the Phase 3 task breakdown.

Source specification:
`docs/specs/billing-record-simplification-spec.md`

## Overview

Replace the Invoice replica vertically rather than maintaining a compatibility
layer. Establish the minimal ledger model first, migrate dispensing and payment
behavior onto it, then replace the Filament and patient API surfaces before
removing every remaining Invoice reference.

## Architecture Decisions

1. **Clean replacement:** rename the domain, tables, classes, routes, UI, tests,
   factories, and seed data. Do not retain aliases or deprecated Invoice
   endpoints because the system is not deployed.
2. **No item-copy table:** Billing Record totals come from the linked Job Order;
   item history remains in Job Order items.
3. **One record per Job Order:** a unique `job_order_id` plus transactional
   dispensing makes record creation idempotent.
4. **Ledger-derived balance:** posted, unreversed payments derive `amount_paid`,
   `balance_due`, and normal status under row lock.
5. **Append-only corrections:** reverse the original payment and optionally
   create a replacement; never edit or delete the posted history.
6. **Explicit voiding:** a dedicated action requires actor, reason, and time,
   retains all related history, and blocks later payments.
7. **Single dispensing transaction:** Job Order transition, dispensing event,
   Billing Record creation, and optional initial payment succeed or fail
   together.
8. **Patient API replacement:** remove `/invoices`; add ownership-scoped,
   read-only `/billing-records` endpoints and resources.
9. **No official document:** no physical number, BIR field, tax replica, or
   Billing Record print action is retained.

## Dependency Graph

```text
Billing schema + enums + models
    -> payment/correction/void actions
    -> dispensing integration
        -> Filament workflow
        -> patient API
    -> reporting/notifications/seeds
        -> remove Invoice domain
            -> documentation + full regression
```

## Implementation Sequence

### Stage A: Ledger foundation

- Replace the canonical Invoice tables with `billing_records` and
  `billing_payments`.
- Remove `invoice_items`.
- Introduce Billing Record/payment enums, models, relationships, policies,
  factories, and number generation.
- Rename dispensing-event linkage to `billing_record_id`.

Checkpoint:

- schema contains only approved internal ledger fields;
- relationships and factories produce valid Job Order-backed records;
- no BIR or copied-item field exists.

### Stage B: Payment integrity

- Replace invoice payment actions with Billing Record actions.
- Recalculate balances and statuses under a locked Billing Record.
- Implement append-only correction/reversal metadata.
- Implement explicit voiding and elevated authorization.

Checkpoint:

- unpaid, partially paid, paid, and voided transitions pass;
- overpayment and invalid mutation produce no partial writes;
- actor, timestamps, reasons, and original/replacement history are preserved.

### Stage C: Dispensing vertical slice

- Replace invoice issuance with Billing Record creation in the dispensing
  action.
- Remove the official-number input and issued/draft semantics.
- Add optional initial payment fields to the dispensing modal.
- Keep Job Order transition, event, record, and payment in one transaction.

Checkpoint:

- dispensing creates exactly one Billing Record;
- repeated/concurrent dispensing cannot duplicate it;
- optional initial payment correctly sets status and balance;
- transaction failure rolls back the full operation.

### Stage D: Clinic-facing Billing Records

- Replace the Invoice Filament resource with Billing Records.
- Show Job Order reference, patient, totals, status, payment timeline, and
  operational notes.
- Provide authorized payment, correction, and void actions.
- Remove official invoice, tax, sale type, copied items, and print language.

Checkpoint:

- the optometrist/reception workflow supports installment tracking;
- privileged actions respect existing policies;
- no user-facing text implies an official invoice.

### Stage E: Patient API replacement

- Replace controllers, resources, routes, policies, and tests with
  `/api/v1/billing-records`.
- Return paginated ownership-scoped records and patient-safe posted payment
  history.
- Remove `/api/v1/invoices` without compatibility redirects.

Checkpoint:

- patients can read only their own records and posted payments;
- patients cannot mutate financial records;
- correction reasons, staff identity, notes, and audit metadata stay private;
- route-contract tests reflect the intentional endpoint replacement.

### Stage F: Secondary consumers and cleanup

- Update Job Order, Patient, Encounter, dispensing, reporting, daily summary,
  audit events, notifications, factories, and seeders.
- Remove all obsolete Invoice PHP classes, factories, tests, and directories
  only after their Billing Record replacements pass.
- Reconcile `API_CONTRACT.md` and `BACKEND_CONTEXT.md`.

Checkpoint:

- repository search finds no runtime Invoice/BIR replica references;
- seeded scenarios cover all Billing Record statuses;
- dashboard/revenue calculations use posted Billing Payments.

### Stage G: Final verification

- Run focused ledger, dispensing, Filament, API, route-contract, reporting,
  seeder, and end-to-end suites.
- Run Pint on modified PHP files.
- Run the complete Pest suite.
- Review the result against every approved success criterion.

## Sequential and Independent Work

Stages A through C are strictly sequential. Stages D and E both depend on the
stable ledger/dispensing contract and can be reasoned about independently, but
should be implemented sequentially in this shared worktree. Stage F must wait
until all replacement surfaces pass so obsolete classes are not removed too
early.

Complete the Frame Reservation track first. Then implement Billing Records so
the larger rename runs against a stable baseline and its broad regression is
not mixed with reservation failures.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Broad rename leaves mixed terminology | Confusing runtime and Android contract | Use repository-wide reference inventory before and after each stage |
| Concurrent payment or dispensing | Incorrect balance or duplicate record | Unique Job Order key, transactions, and row locks |
| Correction mutates history | Lost financial integrity | Explicit reversal metadata and immutable original payment |
| Optional initial payment partially saves | Job Order and balance disagree | One transaction for dispensing, event, record, and payment |
| Removing endpoints breaks Android assumptions | Client integration failure | Update route contract and API contract in the same stage |
| Deleting old classes too early | Large cascading failures | Remove only after replacements and focused tests pass |
| Invoice terminology survives in seeded/demo data | Misleading UI | Include factories, seeders, notifications, and reports in final reference sweep |

## Verification Gates

The Phase 3 task breakdown must preserve these gates:

1. Ledger schema/model tests pass before action migration.
2. Payment integrity passes before dispensing integration.
3. Dispensing passes before Filament or API replacement.
4. Replacement surfaces pass before obsolete Invoice files are removed.
5. Full regression and zero-runtime-reference review pass before commit/handoff.

## Phase 2 Exit Criteria

- Clean replacement without compatibility aliases is approved.
- Ledger, correction, void, and dispensing transaction boundaries are approved.
- `/invoices` removal and `/billing-records` replacement are approved.
- Implementation ordering and cleanup gate are approved.
- Risks have concrete verification coverage.
- The plan is ready to be decomposed into Phase 3 checkbox tasks.
