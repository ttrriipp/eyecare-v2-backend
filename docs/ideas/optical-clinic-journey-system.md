# Optical Clinic Journey System

## Problem Statement

How might we help an optical clinic manage the full customer journey from appointment booking to prescription, AR frame try-on, order request, billing, communication, and feedback through one connected admin and mobile system?

## Recommended Direction

Build an appointment-to-order request system with native AR try-on as the standout feature. The admin web app should handle clinic operations, while the mobile app should give customers a functional portal for booking, browsing, trying frames, requesting orders, tracking billing, messaging staff, and submitting feedback.

The strongest capstone story is not that the system has many modules. It is that the clinic can operate digitally from first appointment to final feedback. The system should prioritize one complete business workflow over deep enterprise features.

## Best Approaches

- Use the appointment-to-order workflow as the main vertical slice: register, book appointment, confirm appointment, create prescription, browse products, use AR try-on, submit order request, process order, create billing, message staff, and submit feedback.
- Treat the mobile order flow as an order request, not ecommerce checkout. Staff should review frame availability, prescription details, lens options, and billing before confirming the order.
- Use Semaphore SMS only for appointment-related events, such as appointment confirmation, rescheduling, cancellation, and reminders.
- Implement AR natively in the Android app. The backend should only store product/frame data and AR asset references, not face geometry, landmarks, or biometric identifiers.
- Keep admin functionality broad but practical. Filament resources should cover the promised modules, but the deepest workflows should be appointments, prescriptions, products, order requests, and billing.
- Use fixed roles for admin, staff, and customer. Avoid building a complex permission management interface unless the core workflow is already finished.
- Use agent-driven development for scoped implementation tasks with clear acceptance criteria, especially migrations, models, Filament resources, API endpoints, factories, seeders, and tests.

## Key Assumptions to Validate

- [ ] Native AR try-on can be completed within the timeline using a small set of demo frames.
- [ ] Semaphore SMS can be integrated reliably for appointment confirmation and reminder messages.
- [ ] Order requests are acceptable for the panel instead of full online checkout.
- [ ] The panel values an integrated clinic workflow more than advanced reporting, payment automation, or complex permissions.
- [ ] Two developers using agent-assisted development can finish both the Filament admin panel and functional Android customer app within the available timeline.

## MVP Scope

In scope: admin web app, mobile app, authentication, admin/staff/customer roles, appointments, prescriptions, product catalog, basic inventory, native AR try-on, order requests, staff order processing, billing, manual payment tracking, appointment SMS notifications through Semaphore, direct messaging, feedback and ratings, basic audit logs, and demo dashboards.

The MVP should prove the complete flow: customer books an appointment, staff confirms it, the customer receives SMS, staff records prescription details, customer tries frames through AR, customer submits an order request, staff processes the order, billing is created, and the customer can message staff and leave feedback.

## Not Doing

- Online payment gateway - billing and payment status are tracked manually.
- SMS for every system event - SMS is limited to appointment-related notifications.
- Multi-branch support - one clinic keeps the scope focused.
- Advanced accounting - unnecessary for the capstone workflow.
- Full ecommerce checkout - staff confirmation is more appropriate for optical orders.
- Production-grade AR analytics - AR only needs to prove try-on functionality.
- Complex dynamic permission management - fixed roles are enough for the MVP.
- Biometric storage - face geometry, landmarks, and biometric identifiers are explicitly out of scope.

## Open Questions

- Will the Android AR implementation use ARCore face tracking, a camera overlay, or a simpler frame placement method? whatever is best recommendation
- Will customers select lens type during the order request, or will staff assign lens details after review? best recommendation
- Which appointment events require SMS: confirmation only, reminders, cancellations, or reschedules? best recommendation
- What exact demo script should be optimized for the capstone defense?
"The optimized demo script will follow a single 'Happy Path' transaction, using pre-populated test accounts to save time. The flow will be:

    The Hook (Customer Mobile App): We will start already logged in as a patient. We will immediately open the AR Try-On feature to show the frame tracking live—this is our 'wow' moment.

    The Order (Customer Mobile App): The patient selects the frame they just tried on, requests a lens upgrade, and books an eye exam appointment.

    The Handoff (Admin Web Panel): We will switch to the Admin dashboard (already logged in). We will show the notification for the new order, have the admin review the request, assign the specific lens details, and approve the appointment.

    The Resolution: We will show the system triggering the SMS confirmation for the appointment, completing the cycle."
