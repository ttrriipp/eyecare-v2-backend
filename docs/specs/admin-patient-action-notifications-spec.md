# Spec: Admin Notifications for Patient App Actions

## Status

Approved in conversation on 2026-09-06. The admin notification contract shipped
with the appointment reschedule-request workflow on 2026-09-08.

This specification supersedes only the patient-originated database-notification
events in `docs/specs/notifications-and-search-spec.md`. Its global-search rules
and non-patient inventory notifications remain unchanged.

## Objective

Give active clinic staff and administrators an actionable Filament bell alert
when a patient action requires awareness or follow-up, without turning routine
mobile activity into notification noise.

Success means every patient-created review queue and every material change to a
confirmed appointment is visible from the bell, each alert links to the relevant
admin screen, inactive users receive nothing, and failed domain operations never
emit an alert.

## Notification Contract

| Patient event | Title | Filament status | Destination |
|---|---|---|---|
| Submits an appointment request | New Appointment Request | info | Appointment request review |
| Cancels a pending appointment request | Appointment Request Cancelled | warning | Appointment request details |
| Submits a patient-link request | New Patient Link Request | info | Patient-link request review |
| Sends a conversation message | New Message | info | Conversation inbox |
| Cancels a confirmed appointment | Appointment Cancelled by Patient | warning | Appointment edit page |
| Submits an appointment reschedule request | Appointment Reschedule Requested | info | Reschedule-request review page |
| Withdraws an appointment reschedule request | Appointment Reschedule Request Withdrawn | info | Reschedule-request details |
| Creates or materially revises a 1-2 star visit rating | Low Visit Rating | danger | Visit rating details |
| Creates or materially revises a 1-2 star frame rating | Low Frame Rating | danger | Frame rating edit page |

All events notify active users holding the `staff` or `admin` role. An
optometrist receives an alert only when that account also holds one of those
operational roles.

Low-rating alerts are emitted for a new 1-2 star rating or when the rating or
comment of an existing low rating changes. Identical retries do not create
another alert. Ratings of 3-5 stars do not create bell alerts.

### Patient-facing reschedule outcomes

The reschedule-request workflow has a separate patient notification contract:

- Approval sends the existing `AppointmentRescheduled` database notification
  and an `appointment_rescheduled` queued SMS record after the appointment and
  immutable history commit.
- Rejection sends a database notification and queued SMS containing only the
  staff-entered patient-safe rejection reason and the original appointment
  time.
- Automatic expiry sends the database notification only.
- Withdrawal sends no patient-facing outcome notification; it may create the
  informational admin withdrawal alert above.

Encrypted patient `reason_details` is never copied into any of these payloads.

## Delivery and Failure Rules

- Use Laravel database notifications rendered with Filament's notification
  payload format.
- Notifications are queued and dispatched after the surrounding database
  transaction commits.
- A notification failure must not roll back or make the successful patient API
  mutation appear to have failed.
- Every admin notification includes a **View** action that marks the alert read
  and opens the relevant Filament destination.
- Repeating the idempotent patient-link request submission must not notify staff
  again for the already-pending request.
- Admin alert payloads remain separate from the mobile notification-feed
  contract. The coordinated mobile cutover removes immediate patient
  rescheduling and adds the request routes documented in the appointment
  reschedule workflow specification.
- Existing patient-facing appointment and message notifications remain intact.

## Excluded Activity

Do not alert administrators for:

- registration, OTP, login, logout, password, profile, or contact changes;
- browsing catalogs, appointments, prescriptions, or orders;
- saving or removing preferred frames;
- read receipts or notification read-state changes;
- ratings of 3-5 stars; or
- accepting an invitation that staff already initiated.

These events are either routine, private security activity, passive preference
data, or already represented by the normal persisted workflow.

## Tech Stack and Commands

- PHP 8.5, Laravel 13, Filament 5, Pest 4.
- Focused tests:
  `vendor/bin/sail artisan test --compact tests/Feature/Notifications/AdminPatientActionNotificationTest.php`
- Related regression tests:
  `vendor/bin/sail artisan test --compact tests/Feature/ConversationTest.php tests/Feature/Api/V1/SubmitAppointmentRequestTest.php tests/Feature/Api/V1/AppointmentRequestOwnershipTest.php`
- Format modified PHP:
  `vendor/bin/sail bin pint --dirty --format agent`
- Full verification:
  `vendor/bin/sail artisan test --compact`

## Project Structure

- `app/Notifications/` contains the queued database-notification payloads.
- `app/Actions/Notifications/` owns active staff/admin recipient selection.
- Existing appointment, request, link, conversation, and rating actions trigger
  notifications only after their domain mutation succeeds.
- `tests/Feature/Notifications/` contains the cross-workflow notification
  contract coverage.

## Testing Strategy

Pest feature coverage must prove:

1. each included patient event notifies every active staff/admin account once;
2. inactive staff, inactive administrators, patients, and optometrist-only
   accounts do not receive admin alerts;
3. notification payloads contain the approved title, body context, Filament
   status, and View action URL;
4. failed or ineligible mutations produce no notification;
5. repeated patient-link submission and identical low-rating retries do not
   duplicate alerts;
6. 3-5 star ratings and excluded routine actions remain silent; and
7. existing patient-facing notifications and API response shapes do not change.

## Implementation Order

1. Add failing cross-workflow Pest coverage.
2. Add a queued, after-commit Filament-compatible admin notification and a
   single recipient-selection action.
3. Connect appointment-request and patient-link-request actions.
4. Reconcile message and confirmed-appointment notification paths.
5. Add low-rating alert rules.
6. Run focused regressions, Pint, and the full test suite.

## Boundaries

### Always

- Resolve Filament URLs through resource/page URL helpers.
- Keep notification bodies operational and free of medical detail, identity
  snapshots, message contents, and rating comments.
- Preserve audit logs, SMS records, patient notifications, and response shapes.

### Ask first

- Adding email, SMS, push, broadcast, or websocket delivery.
- Adding notification preferences or schema columns.
- Notifying optometrist-only accounts.

### Never

- Notify inactive accounts.
- Include sensitive patient-submitted content in an admin notification body.
- Add notifications for every mobile interaction.
- Depend on Android changes for delivery.

## Success Criteria

1. The nine approved event types create actionable admin database
   notifications.
2. Recipient filtering and low-rating noise controls match this specification.
3. Notifications are queue-safe and transaction-safe.
4. No migration, dependency, Android, or public API change is required.
5. Focused and full tests pass, and Pint reports clean formatting.

## Open Questions

None. Any event, recipient, channel, or rating-threshold expansion requires new
approval.
