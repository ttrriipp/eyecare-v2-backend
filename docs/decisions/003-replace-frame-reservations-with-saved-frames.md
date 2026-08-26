# ADR-003: Replace Frame Reservations with Account-Owned Saved Frames

## Status

Accepted

## Date

2026-08-26

## Context

The current Frame Reservations workflow lets a linked patient select up to
three variants for an appointment. Staff acceptance decrements available stock
and may hold those units until clinic close on the appointment date, even when
the patient does not arrive or another checked-in patient is ready to buy the
same frame.

There is no deposit or purchase commitment behind that hold. For a small
clinic with low per-variant quantities, this creates a direct opportunity cost:
an absent future customer can prevent a present customer from buying available
inventory.

The patient-facing value is not the inventory hold itself. The useful job is to
remember which frames the patient liked while browsing or using AR and make
those preferences visible to clinic staff during a visit.

## Decision

Replace Frame Reservations with account-owned Saved Frames:

- saving records interest only and never changes inventory;
- Saved Frames belong to `User`, so authenticated unlinked accounts may use
  the feature while browsing the account-only frame catalog;
- staff sees the same records as Preferred Frames only through the Patient's
  current account link;
- preferences persist until the account removes them and have no hard count
  limit;
- the Patient Record shows the full list, while Appointment and Consultation
  surfaces show the latest three;
- unavailable preferences remain visible and labelled; and
- stock is committed only through the existing Optical Order workflows.

The reservation API, appointment integration, scheduler, Filament resource,
and active reservation tables will be retired after any accepted holds are
released and eligible choices are converted.

This decision supersedes ADR-002's decision to retain a minimal two-state
reservation contract. ADR-002's no-production-client premise still governs the
clean client cutover unless evidence changes before deployment.

## Alternatives Considered

### Keep reservations but shorten the hold

Rejected because any no-deposit hold can still block an immediate sale. A
shorter expiry reduces but does not remove the commercial conflict and retains
the acceptance, expiry, inventory-ledger, scheduler, and no-show complexity.

### Require a deposit for reservations

Potentially valid as a future commercial feature, but rejected for this scope.
It introduces payment, refund, cancellation, expiry, and customer-expectation
rules that are unnecessary for the AR preference job.

### Store preferences on Patient

Rejected because frame browsing and AR are intentionally available before an
account has an active Patient link. Patient ownership would either block that
journey or require later copying and ownership reconciliation.

### Bind a non-holding shortlist to an Appointment

Rejected because preferences naturally survive rescheduling and may be useful
across visits. Appointment ownership would recreate expiry and lifecycle rules
without producing inventory value.

### Allow staff to manage preferences

Rejected because the records express patient interest. Staff mutation would
blur authorship and risk turning the feature back into a preparation workflow.

## Consequences

- A saved frame can be sold before the saving patient visits; this is explicit
  and intended.
- The clinic gains preference context without sacrificing available stock.
- Unlinked accounts retain a useful AR/catalog workflow, while staff privacy
  remains bounded by the current Patient link.
- Saved Frames is simpler than reservations: one row per account/variant, no
  status machine, appointment, expiry, stock collaborator, or scheduled sweep.
- The `/api/v1` contract changes and requires coordinated Android work. No
  compatibility adapter is retained under the accepted no-released-client
  premise.
- Existing accepted holds require a verified release before reservation tables
  can be removed.
- Future genuine reservations must be designed as a separate deposit-backed
  commercial capability and must not change Saved Frames semantics.
