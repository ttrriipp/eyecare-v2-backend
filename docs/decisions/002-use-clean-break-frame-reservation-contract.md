# ADR-002: Use a Clean-Break Frame Reservation Contract

## Status

Accepted

## Date

2026-08-12

## Context

The existing development implementation contains a six-value reservation
status enum, including `tried_on`, and exposes internal status through the
patient API. An initial simplification plan retained those values and added a
second lifecycle field so deployed clients and historical records could
migrate safely. It also proposed an idempotent command and staged rollout for
historical tried-on allocations.

The project owner confirmed that the system has not been deployed. There is no
production reservation data and no released mobile-client contract that needs
backward compatibility. Carrying migration and compatibility paths would add
code, tests, rollout gates, and future cleanup without protecting a real user.

## Decision

Ship the final reservation model directly:

- remove `tried_on` from application code and documented contracts;
- do not create a reconciliation command or compatibility mapping;
- allow owners to reset disposable development data explicitly;
- return normalized patient `status` values `requested`, `ready`, or `closed`
  plus a nullable outcome instead of raw persistence status; and
- remove legacy Quotation selector fallbacks rather than preserving them.

This is a scope decision, not an inventory-safety relaxation. Closure and sale
remain transactional, locked, idempotent, and covered by competing-operation
tests.

## Alternatives Considered

### Keep the staged compatibility rollout

Rejected because it protects no deployed data or client. It would leave dead
branches in the first release and require a second cleanup project.

### Rewrite development rows through a migration

Rejected because disposable development data does not justify permanent
production migration logic. A reset is clearer and keeps the final code free
of obsolete states.

### Keep raw status and add a lifecycle alias

Rejected because there is no released API consumer. One normalized patient
status avoids duplicate sources of truth and prevents persistence vocabulary
from becoming a public contract.

## Consequences

- The implementation checklist is smaller and deployment is one release.
- `tried_on`, historical-null repair, reconciliation commands, raw-status
  aliases, and later deprecation work disappear.
- Any disposable environment with obsolete reservation values must be reset
  explicitly before using the final application.
- A future discovery of real persistent data or released clients invalidates
  this assumption and requires a newly approved migration plan before deploy.
- Inventory locking, audit, authorization, privacy, Appointment integration,
  and Quotation detachment remain unchanged in scope.
