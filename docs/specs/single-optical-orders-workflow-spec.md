# Spec: Single Optical Orders Workflow

## Status

Revised Phase 1 (**Specify**) draft for project-owner review on 2026-08-03.
The project owner confirmed that the application has not been deployed and that
legacy Quotation behavior does not need support. The previously approved
compatibility specification, plan, and task breakdown are superseded.

This revision was approved by the project owner on 2026-08-03. Phase 1 is
complete. The replacement Phase 2 (**Plan**) was approved on 2026-08-03.
Phase 3 (**Tasks**) remains subject to project-owner review before
application-code changes.

## Confirmed Product Decisions

1. **Optical Orders** is the only staff-facing commercial and fulfillment
   workflow.
2. A new transaction begins as an editable, staff-only Draft Optical Order.
3. Staff confirms the Draft after the patient agrees to proceed.
4. The only internal commercial states are `draft` and `accepted`.
5. Presented, Declined, Expired, Share Estimate, Awaiting Decision, and formal
   quotation validity are removed rather than maintained as compatibility
   behavior.
6. `Quotation` remains the internal model and table for the Draft commercial
   record because replacing it would cost more than it saves. Staff and patient
   interfaces call the record an Optical Order.
7. The hidden Filament Quotation resource and quotation presentation/decision
   actions are removed.
8. `/api/v1/quotations` is removed. `/api/v1/eyewear` is the sole patient order-
   tracking contract.
9. Patient Eyewear contains confirmed Job Order lifecycles only. Estimate-only
   progress and history states are removed.
10. There are no production records or deployed clients to migrate. Local,
    test, and demo data may be recreated using the normal migration and seeding
    workflow.

## Objective

Provide the smallest clinic workflow that covers real optical transactions:

```text
Create Optical Order
    -> save editable Draft
    -> review items and price with patient
    -> Confirm Sale
    -> Complete now OR Prepare for pickup
    -> collect payment through Billing & Payments
```

The application should not preserve unused quotation concepts merely because
they already exist in code. Internal Quotation storage remains only as an
implementation detail of the Draft-to-Confirmed Optical Order aggregate.

### Users

- Clinic staff and administrators using the Filament panel.
- Linked patients tracking confirmed orders through `/api/v1/eyewear`.

### Desired outcome

- One staff navigation item, list, form, and detail workflow.
- One patient order endpoint and lifecycle vocabulary.
- No dormant presentation, estimate-decision, or legacy API branches.
- No replacement aggregate or unnecessary database migration.

## Scope

### Included

- Direct Draft-to-Confirmed Optical Order workflow.
- Draft editing and soft-delete discard behavior.
- Order-oriented stages, tabs, filters, actions, dashboard, and permissions.
- Removal of `presented`, `declined`, and `expired` application states.
- Removal of Share Estimate, Decline Estimate, Valid Until, and quotation-only
  staff pages.
- Removal of Quotation mobile routes, controller/resource serialization, and
  estimate-only Eyewear behavior.
- Removal or rewriting of tests that assert deleted behavior, with replacement
  coverage for the supported workflow.
- Updates to API and backend documentation.

### Out of scope

- Renaming the `quotations` or `quotation_items` tables.
- Creating an `optical_orders` table or model.
- Renaming `job_orders.quotation_id`, existing generated identifiers, or stable
  eyewear keys.
- Formal estimates, quotation PDFs, price-validity expiry, or patient quotation
  decisions.
- Native Android implementation beyond consuming the revised API contract.
- Changes to approved fulfillment, Billing Record, payment, inventory,
  reservation, Encounter-charge, or patient privacy rules.
- Production-data conversion or public API deprecation periods.

## Domain Model and Rules

### Internal storage

`Quotation` remains the editable commercial root before confirmation:

```text
Quotation (internal Draft Optical Order)
    -> Quotation Items
    -> Confirm Sale
    -> accepted Quotation + Job Order + Billing Record
```

The internal naming is contained within the model, relationships, and existing
actions. It must not produce a separate Quotations navigation or mobile domain.

### Supported lifecycle

```text
Draft -> Confirmed -> Processing -> Ready for Pickup -> Completed
                   \-------------------------------> Completed now
Confirmed | Processing -> Cancelled
Draft -> Discarded through soft delete
```

| Staff stage | Internal source |
|---|---|
| Draft | `Quotation.status = draft`, no Job Order |
| Confirmed | `Quotation.status = accepted`, Job Order `queued` |
| Processing | Job Order `in_progress` |
| Ready for Pickup | Job Order `ready_for_dispensing` |
| Completed | Job Order `dispensed` |
| Cancelled | Job Order `cancelled` |

Rules:

- A Draft is editable and visible only to staff.
- A Draft creates no Job Order, Billing Record, payment, inventory commitment,
  reservation conversion, or patient Eyewear entry.
- Discarding a Draft uses existing soft deletes and creates no patient history.
- Confirm Sale changes the internal state to `accepted`, locks commercial
  fields, snapshots items, and runs the already approved atomic fulfillment and
  billing workflow.
- Confirm Sale remains idempotent.
- Commercial items and prices are immutable after confirmation; approved
  cancellation, void, correction, and reissue rules apply.
- No supported code creates or interprets `presented`, `declined`, or `expired`.

### Removed Quotation behavior

The implementation removes, rather than deprecates:

- `QuotationStatus::Presented`, `Declined`, and `Expired`;
- presentation and decision actions that exist only for those states;
- the hidden Filament Quotation resource and pages;
- Quotation-specific policy methods with no remaining caller;
- estimate-validity and presentation fields from normal forms and workflows;
- quotation-only dashboard, filter, navigation, and permission wording;
- estimate-only patient progress values and list branches;
- Quotation API routes, controller, resource, and contract tests.

Code or fields still required by the Draft/Confirmed internal aggregate remain.
Removal must be usage-driven rather than a name-based deletion sweep.

## Admin UI

### Navigation and list

- Keep one **Optical Orders** navigation item.
- Do not register or retain a separate Quotation resource.
- Primary tabs are:

```text
All | Drafts | Confirmed | Processing | Ready for Pickup | Completed | Cancelled
```

- Stage, fulfillment, and payment filters use Order terminology.
- Dashboard shows **Draft Optical Orders** instead of Quotations Pending.
- The existing internal `quotation_number` may continue to display as
  **Order #**; renumbering is unnecessary.

### Draft form and actions

- The page title is **New Optical Order**.
- Saving creates a Draft.
- The form does not request Valid Until or quotation decision data.
- Notes clearly state when they become patient-visible after confirmation.
- Draft actions are **Edit**, **Confirm Sale**, and **Discard Draft**.
- Share Estimate and Decline Estimate do not exist.

### Confirmation and fulfillment

Confirm Sale continues to use approved behavior:

- Complete sale now or Prepare for pickup;
- optional external supplier/lab context;
- prescription validation when applicable;
- due date and optional deposit;
- first-payment charge-finalization warning;
- item snapshotting, Billing Record resolution, reservation conversion, and
  inventory commitment;
- immediate or prepared fulfillment stages.

## Patient API

### Supported contract

`/api/v1/eyewear` and `/api/v1/eyewear/{key}` remain the patient order contract.

- Only confirmed Job Order lifecycles are returned.
- Drafts and discarded Drafts return no Eyewear result.
- Current results include Processing and Ready for Pickup.
- History includes Completed and Cancelled.
- Existing item, order-total, fulfillment, and approved payment-summary privacy
  behavior remains.
- The quotation-derived `estimate` detail section is removed. Confirmed items
  remain available from the existing Job Order-backed preparation section.
- No estimate-available, estimate-declined, or estimate-expired progress exists.

### Removed contract

Remove:

```text
GET /api/v1/quotations
GET /api/v1/quotations/{quotation}
```

Also remove Quotation controller/resource code and the Quotation sections from
the API contract and route summary. This is a direct pre-launch cleanup, not a
versioned deprecation.

## Data and Migration Strategy

No production compatibility migration is required.

- Do not edit deployed migration files.
- Do not add a migration merely to translate unsupported local statuses.
- The current schema may retain unused presentation metadata columns when
  dropping them would add migration cost without runtime complexity.
- Factories and seeders create only Draft or accepted commercial records.
- Automated tests use fresh databases.
- A developer with obsolete local data may recreate it with the project's
  normal reset-and-seed workflow; implementation must not reset data
  automatically.

Retaining harmless nullable columns is cheaper than a cleanup migration.
Retaining active application branches, routes, resources, and tests is not.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5 / Livewire 4 / Tailwind CSS 4
- Sanctum 4
- Pest 4 / PHPUnit 12
- MySQL through Laravel Sail
- No new dependency

## Commands

```text
Inspect routes:       vendor/bin/sail artisan route:list --except-vendor
Optical tests:        vendor/bin/sail artisan test --compact tests/Feature/OpticalOrders tests/Feature/Quotations
Eyewear tests:        vendor/bin/sail artisan test --compact --filter=Eyewear
Filament tests:       vendor/bin/sail artisan test --compact tests/Feature/Filament/QuotationCreationTest.php tests/Feature/Filament/DashboardTest.php
Full tests:           vendor/bin/sail artisan test --compact
Format PHP:           vendor/bin/sail bin pint --dirty --format agent
Build assets:         vendor/bin/sail npm run build
```

## Project Structure

```text
app/Filament/Resources/OpticalOrders/  Sole staff workflow
app/Actions/Quotations/               Internal Draft create/update only
app/Actions/OpticalOrders/            Confirmation and fulfillment
app/Models/Quotation.php              Internal Draft commercial record
app/Models/JobOrder.php               Confirmed fulfillment record
app/Services/Eyewear/                 Confirmed patient order composition
routes/api.php                         Eyewear routes; no Quotation routes
tests/Feature/OpticalOrders/           Supported workflow coverage
tests/Feature/Eyewear/                 Confirmed patient tracking coverage
docs/                                  Revised backend and API contracts
```

Removed application areas are not kept as empty compatibility shells.

## Code Style

Use a non-persisted staff-stage resolver over the remaining internal states:

```php
private static function resolveWorkflowStage(Quotation $record): string
{
    if ($record->status === QuotationStatus::Draft) {
        return 'Draft';
    }

    return match ($record->jobOrder?->status) {
        JobOrderStatus::Queued => 'Confirmed',
        JobOrderStatus::InProgress => 'Processing',
        JobOrderStatus::ReadyForDispensing => 'Ready for Pickup',
        JobOrderStatus::Dispensed => 'Completed',
        JobOrderStatus::Cancelled => 'Cancelled',
        default => throw new LogicException('Accepted order requires a Job Order.'),
    };
}
```

- Use explicit enum comparisons and return types.
- Centralize staff-stage mapping.
- Keep financial and inventory mutations in existing transactional actions.
- Remove dead branches instead of retaining unreachable compatibility cases.
- Follow existing Filament and Pest conventions.

## Testing Strategy

### Domain tests

- New Optical Orders start as Draft.
- Drafts edit and discard without downstream side effects.
- Drafts confirm directly and idempotently.
- Only Draft and accepted internal statuses can be created.
- Confirmation preserves approved billing, payment, inventory, reservation,
  prescription, immediate, and prepared behavior.

### Filament tests

- Only Optical Orders is registered for the sales workflow.
- Tabs and filters use the supported Order stages.
- Draft actions are Edit, Confirm Sale, and Discard Draft.
- No Share Estimate, Decline Estimate, Awaiting Decision, Valid Until, or
  Quotation-specific staff page remains.
- Dashboard shows Draft Optical Orders.

### API tests

- Quotation routes return 404 because they are not registered.
- Drafts remain absent from Eyewear.
- Confirmed, Processing, Ready, Completed, and Cancelled behavior remains.
- Estimate-only filters and progress values are rejected or absent according to
  the revised Eyewear contract.
- Ownership and privacy boundaries remain enforced.

### Removal discipline

- Rewrite tests that cover a still-supported Draft or confirmed behavior.
- Remove tests only when their entire subject has been explicitly removed by
  this approved specification.
- Add route-absence and UI-action-absence assertions before deleting obsolete
  implementation files.
- Run focused tests after each task and the full suite at the final gate.

## Boundaries

### Always

- Keep Optical Orders as the only staff workflow.
- Keep Drafts private and side-effect free.
- Use Eyewear as the only patient order contract.
- Prove routes and UI actions are absent before deleting their implementation.
- Search installed-version Laravel and Filament documentation before code
  changes.
- Use Laravel Sail and add Pest coverage for every changed behavior.

### Ask first

- Replacing or renaming the internal Quotation tables/model.
- Adding or renaming persisted status values.
- Changing approved billing, payment, fulfillment, inventory, or reservation
  behavior.
- Resetting any developer's local database.
- Adding a formal quotation or patient estimate workflow later.

### Never

- Add compatibility branches for undeployed Quotation behavior.
- Edit deployed migration files.
- Expose a Draft to patients.
- Create billing, fulfillment, payment, or inventory effects for a Draft.
- Delete still-supported Draft/confirmation tests merely because their file or
  class name contains Quotation.
- Leave dead routes, resources, actions, policies, or estimate states as
  permanent zombie code.

## Success Criteria

1. Staff has one Optical Orders workflow and no Quotation workflow.
2. New transactions move directly from Draft to Confirmed.
3. Only `draft` and `accepted` remain as internal commercial status cases.
4. Presented, Declined, Expired, Share Estimate, Awaiting Decision, Valid Until,
   and quotation-decision behavior are absent.
5. `/api/v1/quotations` routes and their application implementation are absent.
6. Eyewear contains only confirmed-order lifecycles and no estimate-only states.
7. Drafts remain private and side-effect free.
8. Confirmation preserves all approved billing, payment, inventory,
   reservation, fulfillment, and privacy rules.
9. The internal Quotation storage remains without a replacement schema project.
10. Focused tests, the full Pest suite, Pint, route audit, and frontend build
    pass.

## Open Questions

There are no blocking questions under the confirmed pre-launch assumptions.
Creating formal estimates later would be a new feature, not restoration of a
compatibility layer.

## Revised Phase 1 Approval Gate

Approval of this revised specification authorizes a replacement Phase 2
(**Plan**) only. The obsolete compatibility plan and tasks must not be
implemented.
