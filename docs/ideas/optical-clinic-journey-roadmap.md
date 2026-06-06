# Optical Clinic Journey Roadmap

## Guiding Strategy

Build one complete clinic journey before deepening individual modules. The project should always be demoable through this path:

Customer registers, books an appointment, staff confirms it, SMS is sent, staff records prescription details, customer browses products, customer tries a frame with native AR, customer submits an order request, staff processes the order, billing is created, customer messages staff, and customer leaves feedback.

## Team Split

Developer A should own the Laravel backend, Filament admin panel, database, API contracts, SMS integration, and backend tests.

Developer B should own the Android mobile app, native AR try-on, mobile API integration, mobile UI, and mobile-side testing.

Both developers should review API contracts, demo data, and end-to-end flows together. Agent-driven development should be used for small acceptance-based tasks, not broad vague modules.

## Agent-Driven Development Rules

- Give agents narrow tasks with clear acceptance criteria.
- Ask agents to follow existing project conventions and generate tests where practical.
- Review all generated migrations, authorization logic, validation rules, and API responses manually.
- Avoid letting agents invent new architecture, status names, or duplicate service patterns.
- Prefer vertical tasks such as "appointment booking API plus tests" over broad tasks such as "build appointment module."

## Definition of Done

A feature is done only when:

- The admin-side workflow works if staff/admin are involved.
- The mobile-side workflow works if customers are involved.
- API validation and authorization are implemented.
- Demo seed data exists.
- The feature can be shown in the capstone demo script.
- Critical backend behavior has Pest tests.
- Error states are handled clearly enough for a defense demo.

## Week 1 - Foundation and Scope Lock

Goal: create the technical foundation and prevent scope drift.

Deliverables:

- Finalize the demo workflow and status names.
- Confirm database tables needed for the MVP.
- Set up Laravel authentication, fixed roles, and base API structure.
- Set up Filament access for admin and staff.
- Create initial seed data for roles, statuses, visit reasons, brands, categories, lens types, and payment methods.
- Draft the mobile API contract for auth, appointments, products, AR assets, order requests, billing, messaging, and feedback.

Acceptance criteria:

- Admin and staff can log in to the web admin.
- Customer can register and log in through the API.
- Roles are enforced at a basic level.
- Seed data can recreate a usable demo environment.
- API response conventions are agreed before mobile screens are built.

## Week 2 - Appointments and Appointment SMS

Goal: complete the appointment workflow end to end.

Deliverables:

- Mobile appointment booking screen.
- API endpoints for creating and viewing customer appointments.
- Filament appointment management for staff.
- Appointment status workflow: pending, confirmed, rescheduled, cancelled, completed.
- Semaphore SMS integration for appointment confirmation and reminders.
- Appointment-related notification history.

Acceptance criteria:

- Customer can book an appointment from mobile.
- Staff can confirm or reschedule the appointment from admin.
- Customer receives Semaphore SMS for confirmed appointments.
- Customer can see appointment status in mobile.
- Staff can filter appointments by status and date.

## Week 3 - Product Catalog, Inventory Basics, and AR Asset Feed

Goal: make products visible and prepare AR-ready frame data.

Deliverables:

- Filament resources for categories, brands, products, variants, product images, and AR assets.
- Basic stock quantity and minimum stock level fields.
- Mobile product listing and product detail screens.
- API endpoints for product catalog and AR asset metadata.
- Demo frame products with images and AR-compatible asset references.

Acceptance criteria:

- Staff can create and edit products and variants in admin.
- Customer can browse active products in mobile.
- Customer can open product details with price, color, dimensions, and available frame images.
- AR-compatible products are identifiable from the API.
- Low stock items are visible in admin.

## Week 4 - Prescriptions and Native AR Prototype

Goal: prove the clinical and novelty parts of the system.

Deliverables:

- Filament prescription management linked to customer and appointment.
- Prescription fields for OD/OS values, PD, prescribed date, expiration date, and notes.
- Mobile prescription history view.
- Native Android AR try-on prototype for selected demo frames.
- Backend AR asset references connected to product variants.

Acceptance criteria:

- Staff can record a prescription after an appointment.
- Customer can view prescription history in mobile.
- Customer can launch AR try-on from an eligible product.
- AR try-on works for at least a small set of demo frames.
- No biometric data, face geometry, or facial landmarks are stored by the backend.

## Week 5 - Order Requests and Staff Processing

Goal: connect product interest to clinic operations.

Deliverables:

- Mobile order request flow from product detail or AR try-on.
- API endpoint for submitting order requests.
- Filament order management for reviewing, confirming, preparing, completing, or cancelling orders.
- Order item snapshots for price and selected product variant.
- Basic inventory deduction when an order is confirmed or completed, depending on final business rule.

Acceptance criteria:

- Customer can submit an order request without online checkout.
- Staff can review and process the order request in admin.
- Order totals are calculated consistently.
- Customer can track order status in mobile.
- Inventory changes are recorded when the chosen order milestone is reached.

## Week 6 - Billing, Manual Payments, and Direct Messaging

Goal: finish the operational loop after staff accepts an order.

Deliverables:

- Billing generation from confirmed orders.
- Manual payment tracking with statuses such as posted, voided, and reversed.
- Mobile billing status view.
- Basic customer-staff conversations.
- Message thread UI in mobile and admin.

Acceptance criteria:

- Staff can generate or update billing for an order.
- Staff can record manual payments.
- Billing balance updates correctly after payment records.
- Customer can view billing status in mobile.
- Customer and staff can exchange messages tied to an order, appointment, or general concern.

## Week 7 - Feedback, Audit Logs, Dashboard, and Hardening

Goal: complete promised management features and stabilize the system.

Deliverables:

- Mobile feedback and rating submission.
- Admin feedback view and staff reply.
- Basic audit logging for appointment changes, product changes, inventory changes, order changes, billing changes, and payment changes.
- Admin dashboard cards for appointments, pending orders, low stock, unpaid billings, and recent feedback.
- Backend tests for core workflows.

Acceptance criteria:

- Customer can submit rating and feedback after an appointment or order.
- Staff can view and reply to feedback.
- Important admin actions produce audit log entries.
- Dashboard gives a quick operational overview.
- Core workflow tests pass.

## Week 8 - Polish, Demo Data, and Defense Rehearsal

Goal: make the system presentable and reliable under demo conditions.

Deliverables:

- Full demo dataset with staff, customers, products, frame assets, appointments, orders, billings, messages, and feedback.
- End-to-end demo rehearsal script.
- UI polish for highest-traffic admin and mobile screens.
- Bug fixing and edge case cleanup.
- Final testing pass.

Acceptance criteria:

- The full demo workflow can be completed without manual database edits.
- Admin and mobile screens have realistic data.
- The team can perform a defense demo in under 10 minutes.
- Known limitations are documented and defensible.
- The final system matches the promised capstone features without overclaiming.

## Priority Cut Lines

If the team falls behind, cut these first:

- Message attachments.
- Advanced notification retry tracking.
- Advanced reports.
- Dynamic permission management UI.
- AR analytics.
- Product variants beyond what is needed for the demo.
- Complex inventory movement types.

Do not cut these unless the project is at serious risk:

- Customer registration and login.
- Appointment booking.
- Appointment SMS.
- Product browsing.
- Native AR try-on.
- Order request.
- Staff order processing.
- Billing status.
- Feedback and ratings.

## Recommended Demo Script

1. Admin logs in and shows dashboard.
2. Customer registers or logs in on mobile.
3. Customer books an appointment.
4. Staff confirms appointment in admin.
5. Customer receives appointment SMS.
6. Staff records prescription after the appointment.
7. Customer views prescription in mobile.
8. Customer browses frames.
9. Customer launches native AR try-on.
10. Customer submits order request.
11. Staff reviews and confirms the order.
12. Billing is created and payment status is updated manually.
13. Customer views order and billing status.
14. Customer sends a message to staff.
15. Staff replies in admin.
16. Customer submits feedback and rating.
17. Admin shows audit logs or dashboard summary.

## Best Immediate Next Tasks

1. Finalize exact status names for appointments, orders, billing, payments, and notifications.
2. Decide native AR implementation level: ARCore face tracking or simpler camera overlay.
3. Write the API contract before building mobile screens.
4. Build authentication and appointment booking first.
5. Prototype AR in the first two weeks instead of leaving it near the end.
