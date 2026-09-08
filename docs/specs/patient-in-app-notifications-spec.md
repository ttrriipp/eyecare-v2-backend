# Spec: Patient In-App Notifications for Clinic Actions

## Status

Approved on 2026-09-08 and implementation-aligned with the existing typed
Android notification contract on 2026-09-09.

This specification extends the patient notification feed documented in
`docs/API_CONTRACT.md` section 15b. It does not replace the separately shipped
admin alerts in `docs/specs/admin-patient-action-notifications-spec.md`.

## Objective

Notify a patient in the Android in-app inbox when a clinic action materially
changes their appointment, available care record, payment, or optical-order
outcome. Keep the feed useful by excluding internal workflow updates and events
the patient just performed themselves.

The existing notification list, unread-count, mark-read, and mark-all-read API
endpoints remain the delivery surface.

## Recipient Rules

- Send to the account currently linked to the affected patient.
- Appointment-request outcomes are sent to the account that submitted the
  request, including when patient linking has not yet been completed.
- If the patient has no linked account, complete the clinic operation without
  creating a notification.
- Never send a patient notification to staff or to another patient's account.
- Staff replies remain account-scoped and may be delivered even when the
  account does not currently have an active patient link.

## Notification Events

| Clinic event | Stable `kind` | Patient title | Typed `mobile_action` | Legacy related record/path |
|---|---|---|---|---|
| Accept a new appointment request | `appointment_confirmed` | Appointment Confirmed | `appointment` + ID | Appointment/path |
| Reject an appointment request | `appointment_request_declined` | Appointment Request Declined | `appointment_request` + ID | Request/path |
| Approve a rebooking or otherwise reschedule an appointment | `appointment_rescheduled` | Appointment Rescheduled | `appointment` + ID | Appointment/path |
| Clinic cancels a confirmed appointment | `appointment_cancelled` | Appointment Cancelled | `appointment` + ID | Appointment/path |
| Complete a consultation and finalize a prescription | `prescription_available` | Prescription Available | `prescription` + ID | Prescription/path |
| Complete a consultation without a prescription | `visit_completed` | Visit Completed | `appointment` + ID when available | Appointment/path when available |
| Confirm an optical order for later fulfillment | `optical_order_confirmed` | Optical Order Confirmed | `optical_order` + ID | Order/path |
| Mark an optical order ready for dispensing | `optical_order_ready` | Order Ready for Pickup | `optical_order` + ID | Order/path |
| Cancel an optical order | `optical_order_cancelled` | Optical Order Cancelled | `optical_order` + ID | Order/path |
| Record a standalone payment | `payment_recorded` | Payment Recorded | `optical_order` + ID, or `null` | Order/path when available; otherwise billing record/null |
| Correct a posted payment | `payment_updated` | Payment Updated | `optical_order` + ID, or `null` | Order/path when available; otherwise billing record/null |
| Dispense an optical order | `optical_order_released` | Order Released | `optical_order` + ID | Order/path |
| Staff sends a conversation message | `new_message` | New Message | `conversation` without an ID | Conversation/path |

An immediate order that is created and dispensed in one successful operation
produces only **Order Released**. A deposit recorded while confirming an order,
or a pickup payment recorded while dispensing it, is folded into that final
order notification instead of producing a second payment alert.

## Intentionally Silent Events

Do not notify the patient for:

- submitting or cancelling their own appointment request;
- cancelling their own confirmed appointment;
- check-in, no-show, encounter start, draft saves, transfers, or internal
  consultation edits;
- quotation creation, edits, acceptance, or decline, because quotations are not
  exposed by the patient API;
- the internal `in_progress` optical-order transition;
- billing charge edits, recalculation, voiding, due-date reminders, or overdue
  reminders;
- inventory, catalog, saved-frame, rating, audit, or reporting activity; or
- OTP, login, profile, password, or contact-management activity.

Security notices and scheduled payment reminders need separate channel,
frequency, and recovery decisions and are outside this first release.

## API Payload Contract

The current notification resource remains backward-compatible. New patient
notifications must populate all navigation fields:

```json
{
  "id": "uuid",
  "kind": "appointment_confirmed",
  "type": "App\\Notifications\\PatientDatabaseNotification",
  "title": "Appointment Confirmed",
  "body": "Your appointment APT-2026-000123 is confirmed for Sep 10, 2026 9:00 AM.",
  "mobile_action": {
    "type": "appointment",
    "id": 123
  },
  "action_url": "/appointments/123",
  "related_type": "appointment",
  "related_id": 123,
  "read_at": null,
  "created_at": "2026-09-08T10:00:00+08:00"
}
```

- `kind` is a stable snake-case product enum and is independent of PHP class
  names, display titles, event keys, and URLs.
- `mobile_action` is the canonical Android navigation instruction. Its closed
  `type` set is `appointment`, `appointment_request`, `prescription`,
  `optical_order`, and `conversation`. Detail actions require an integer `id`;
  conversation omits it. A notification without a safe app destination uses
  `null`.
- Android maps only known `mobile_action.type` values to typed destinations,
  treats unknown values as non-actionable, and never opens `action_url`.
- `type` is the stored Laravel notification class name and must not drive
  client behavior.
- `action_url` is a patient-app-relative path, never a Filament/admin URL and
  never an absolute filesystem or API-internal URL. It is retained only as
  additive compatibility metadata.
- `related_type` is one of `appointment`, `appointment_request`,
  `prescription`, `optical_order`, `billing_record`, or `conversation`.
- `related_id` identifies the owned record. It may be null only when the
  related type has no stable record identifier.
- Older or generic stored notifications remain readable as `kind: "unknown"`
  with `mobile_action: null` and may have null legacy navigation fields.

## Content and Privacy

- Include only the minimum context needed to recognize the event: public record
  number, appointment date/time, order status, or payment amount.
- Do not include clinical findings, diagnosis, prescription values, reason for
  visit, private notes, rejection/cancellation details, message contents,
  payment method, reference number, or staff identity.
- A payment notification is a status confirmation, not an official receipt.
- Bodies must remain understandable when the related record is later cancelled,
  superseded, or otherwise unavailable.

## Delivery, Failure, and Duplication

- Use Laravel database notifications through the existing account notification
  table and feed API. Push, SMS, email, broadcast, and websocket delivery are
  not added by this specification.
- Queue notifications after the surrounding transaction commits. A rolled-back
  clinic operation creates no notification.
- Notification delivery failure must not roll back or make the successful
  clinic operation appear to fail.
- A successful domain transition creates at most one patient notification for
  the same event. Idempotent retries remain silent.
- Use a deterministic internal event key based on the event and source record,
  such as `optical_order.ready:42` or `payment.recorded:91`, to guard against
  accidental duplicate dispatch. The event key does not need to be exposed by
  the API.
- Existing appointment SMS behavior remains unchanged and may coexist with the
  in-app notification.

## Implementation Shape

- Keep event selection in the domain actions that own each successful state
  transition.
- Centralize patient-account resolution and queued dispatch so missing links,
  after-commit behavior, duplicate protection, and failure handling are
  consistent.
- Replace or reconcile the existing `AppointmentRescheduled`, unused
  `AppointmentStatusChanged`, and `NewMessageReceived` payloads with this
  contract rather than creating parallel behavior.
- Update `docs/API_CONTRACT.md` and `docs/BACKEND_CONTEXT.md` when the behavior
  ships. No database migration or dependency change is expected.

## Testing Strategy

Focused Pest feature coverage must prove:

1. every included clinic event notifies the affected linked account once;
2. unrelated accounts and unlinked patients receive nothing;
3. stable kinds, typed mobile actions, titles, safe bodies, and additive legacy
   metadata match the contract;
4. failed and rolled-back operations create no notification;
5. idempotent retries and coalesced order/payment flows do not duplicate alerts;
6. intentionally silent events remain silent;
7. feed ownership, unread count, and read-state behavior continue to work; and
8. existing admin notifications and appointment SMS behavior do not regress.

## Success Criteria

1. Patients receive timely in-app alerts for all approved clinic-driven events.
2. Every actionable alert opens a patient-visible Android destination when one
   exists.
3. Notification content contains no private clinical or operational details.
4. Clinic workflows succeed independently of notification delivery.
5. Focused tests pass and the public API documentation matches the shipped
   behavior.

## Approval

The event table, database-only delivery choice, and exclusion of
patient-invisible quotations and routine internal stages were approved in
conversation on 2026-09-08.
