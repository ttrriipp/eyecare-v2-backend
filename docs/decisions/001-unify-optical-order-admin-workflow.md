# ADR-001: Unify the Optical Order Admin Workflow

## Status

Accepted

## Date

2026-07-31

## Context

Clinic staff currently complete one eyewear sale across separate Quotation,
Job Order, and Billing Record resources. Those records have valid independent
responsibilities:

- Quotation preserves proposed prices and immutable presented revisions.
- Job Order preserves committed items, inventory, preparation, and dispensing.
- Billing Record preserves the receivable and append-only payment history.

Presenting each model as an equally prominent admin destination makes staff
translate the technical data model into a real-world workflow. Frame
Reservations add another transition: a frame may already be allocated before
the accepted quotation becomes a Job Order. The existing flow can therefore
double-decrement stock if it treats reservation allocation and order
commitment as unrelated deductions.

The clinic also needs to accept deposits before dispensing. Creating the
Billing Record only at dispensing cannot represent that workflow.

## Decision

Keep Quotation, Job Order, Billing Record, and Frame Reservation as separate
auditable models, but present them through one visible **Optical Orders**
Filament destination.

New Optical Orders use Quotation as the aggregate root and share the existing
stable `eyewear_key`. Staff work through one aggregate queue and detail
timeline with fulfillment and payment shown as separate status dimensions.
Appointment, Encounter, and Frame Reservation pages provide contextual
**Create/Open Optical Order** actions.

The **Accept & start order** command is one idempotent transaction that:

1. accepts the eligible latest quotation revision;
2. creates exactly one queued Job Order;
3. creates exactly one Billing Record;
4. optionally records a deposit;
5. converts an eligible Frame Reservation.

A prepared reservation transfers inventory into the Job Order through
offsetting reservation release and order commitment ledger entries. This
preserves a zero net stock change during conversion and leaves one reversible
Job Order commitment. Unselected prepared variants are released.

Dispensing uses the existing Billing Record and creates only the Dispensing
Event. Cancellation never erases posted payments; it requires explicit,
authorized reversal or refund handling.

Standalone Quotation, Job Order, and Billing Record pages remain
policy-protected compatibility surfaces but are hidden from primary navigation
only after an audit accounts for active legacy Job Orders without a Quotation.

## Alternatives Considered

### Merge all records into one Optical Order table

Rejected because estimate revisions, fulfillment state, inventory movements,
and payments have different audit and lifecycle rules. A merged table would
couple unrelated transitions and weaken historical integrity.

### Keep three equally visible admin resources

Rejected because it exposes persistence boundaries as the staff workflow and
requires repeated navigation to complete one sale.

### Create Billing only when dispensing

Rejected because it cannot record deposits when the patient confirms the
order.

### Deduct stock again when creating the Job Order

Rejected because a prepared Frame Reservation has already reduced available
stock. A second deduction would understate inventory.

## Consequences

- Staff receive one coherent workflow without sacrificing audit separation.
- New Job Orders gain a nullable unique Frame Reservation link.
- Order confirmation, billing creation, deposit recording, and reservation
  conversion require one carefully locked transaction.
- Patient-facing Eyewear aggregation remains compatible but may expose payment
  status before dispensing.
- Cancellation must coordinate inventory reversal and payment history.
- Legacy unanchored Job Orders require audit and fallback access before the
  standalone resources disappear from navigation.
