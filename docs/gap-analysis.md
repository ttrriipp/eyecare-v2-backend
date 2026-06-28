# Workflow Checklist: Online Optical Management System with Augmented Reality for Padilla Optical Clinic

> Legend: ✅ Done | ⚠️ Partial/Different | ❌ Not implemented | 📱 Android-side only (backend not involved)

---

## A. Account and Access Workflows

### Customer Registration Workflow

* ✅ Customer opens the Android mobile application.
* ✅ Customer selects "Register."
* ⚠️ Customer enters name, email, phone number, address, and password. — **address field not in `users` table or `POST /register`**
* ✅ System validates required fields.
* ⚠️ System checks if email or phone number already exists. — **email uniqueness checked; phone uniqueness not validated**
* ✅ System creates a customer account.
* ✅ System shows successful registration message. — token returned; Android shows success
* ✅ Customer can log in after registration.

### Customer Login Workflow

* ✅ Customer opens the mobile application.
* ⚠️ Customer enters email/phone number and password. — **email only; phone number login not supported**
* ✅ System validates credentials.
* ✅ System redirects customer to the mobile dashboard.
* ✅ System prevents access if credentials are invalid.

### Admin/Staff Login Workflow

* ✅ Admin/staff opens the web application.
* ✅ Admin/staff enters username/email and password.
* ✅ System validates credentials.
* ✅ System checks user role.
* ✅ System redirects authorized users to the admin dashboard.
* ✅ System blocks unauthorized access to admin pages.

### Logout Workflow

* ✅ User selects "Logout."
* ✅ System ends the active session/token.
* ✅ System redirects user to the login page.
* ✅ User cannot access private pages after logging out.

---

## B. Customer Profile Workflows

### Customer Profile Management Workflow

* ✅ Customer views personal profile. — `GET /user`
* ❌ Customer updates allowed profile details. — **no `PATCH /user` endpoint**
* ❌ System validates updated information.
* ❌ System saves profile changes.
* ❌ System displays updated profile information.

### Admin Customer Management Workflow

* ✅ Admin views customer list. — Patients resource
* ✅ Admin searches or filters customers.
* ✅ Admin views selected customer details.
* ✅ Admin updates customer information if needed.
* ✅ System saves changes and records update date.

---

## C. Product and Inventory Workflows

### Product/Frame Creation Workflow

* ✅ Admin opens product management module.
* ✅ Admin selects "Add Product."
* ⚠️ Admin enters product name, brand, category, frame type, material, color, price, and stock quantity. — **material and color are stored as `attributes` JSON on the variant, not dedicated fields**
* ✅ Admin uploads product image.
* ✅ Admin uploads or links AR asset if available.
* ✅ System validates product information.
* ✅ System saves product record.
* ✅ Product becomes available in the customer mobile catalog if active.

### Product/Frame Update Workflow

* ✅ Admin selects an existing product.
* ✅ Admin updates product details, price, stock, image, or AR asset.
* ✅ System validates changes.
* ✅ System saves updated product information.
* ✅ Updated product details appear in admin and mobile app.

### Product Deactivation Workflow

* ✅ Admin selects a product.
* ✅ Admin chooses deactivate/archive. — Visibility toggle
* ❌ System asks for confirmation. — **no confirmation modal; toggle fires immediately**
* ✅ Product is hidden from customer browsing.
* ✅ Product record remains available for order history.

### Inventory Monitoring Workflow

* ✅ Admin views product inventory. — Inventory History resource
* ✅ System displays current stock quantity.
* ✅ System identifies low-stock products. — dashboard widget
* ✅ Admin updates stock after new inventory arrives. — Adjust Stock action
* ✅ System reflects updated stock in product listings.

---

## D. AR Try-On Workflows

### AR Frame Try-On Workflow

* 📱 Customer opens product catalog.
* 📱 Customer selects an eyeglass frame.
* 📱 Customer taps "Try On."
* 📱 System checks whether the device supports AR.
* 📱 System requests camera permission.
* 📱 Customer grants camera permission.
* 📱 System opens AR camera view.
* 📱 Selected frame is displayed on the customer's face.
* 📱 Customer can preview the frame virtually.
* 📱 Customer can return to product details or proceed to order.
* ✅ Backend: AR asset served via `{APP_URL}/storage/{ar_asset_reference}`, `ar_eligible` flag in API response.

### AR Unsupported Device Workflow

* 📱 Customer taps "Try On."
* 📱 System detects unsupported AR device or missing AR service.
* 📱 System displays a message explaining that AR is unavailable.
* 📱 System provides normal product image preview as fallback.
* ✅ Backend: product images always returned as fallback data.

### Camera Permission Denied Workflow

* 📱 Customer taps "Try On."
* 📱 System requests camera permission.
* 📱 Customer denies permission.
* 📱 System displays permission explanation.
* 📱 System prevents AR from launching.
* 📱 Customer may continue browsing products without AR.

---

## E. Optical Record and Prescription Workflows

### Admin Optical Record Creation Workflow

* ✅ Admin opens customer profile. — Patients edit page
* ✅ Admin selects "Add Optical Record." — Prescriptions relation manager
* ✅ Admin enters prescription details.
* ✅ Admin enters prescription date and notes.
* ✅ System validates required optical record fields.
* ✅ System saves the optical record.
* ✅ Customer can view the optical record in the mobile app. — `GET /prescriptions`

### Admin Optical Record Update Workflow

* ✅ Admin opens existing optical record.
* ✅ Admin updates prescription details or notes.
* ✅ System validates changes.
* ✅ System saves updated optical record.
* ✅ System preserves record history or update timestamp. — `updated_at` on record

### Customer Prescription Upload Workflow

* ❌ Customer opens prescription upload section. — **no upload UI/endpoint**
* ❌ Customer uploads image or PDF of prescription.
* ❌ System validates file type and size.
* ❌ System marks uploaded prescription as pending verification.
* ❌ Admin reviews uploaded prescription.
* ❌ Admin approves, rejects, or converts it into an optical record.

### Customer Optical Record Viewing Workflow

* ✅ Customer opens optical records section.
* ✅ System displays only the logged-in customer's own records.
* ✅ Customer views prescription details.
* ✅ Customer cannot edit official optical records.

---

## F. Appointment Workflows

### Customer Appointment Booking Workflow

* ✅ Customer opens appointment module.
* ✅ Customer selects preferred date and time.
* ✅ Customer enters appointment purpose. — `visit_reason_id`
* ❌ System checks availability. — **no slot conflict detection; any datetime accepted**
* ✅ Customer submits appointment request.
* ✅ System creates appointment with pending status.
* ✅ Admin receives appointment request. — visible in Filament appointments list

### Admin Appointment Approval Workflow

* ✅ Admin views pending appointment requests.
* ✅ Admin accepts, reschedules, or rejects appointment.
* ✅ System updates appointment status.
* ⚠️ Customer receives appointment status update. — **status visible in `GET /appointments`; no push notification**
* ⚠️ SMS notification is sent if enabled. — **SMS record created but never actually sent to provider**

### Appointment Rescheduling Workflow

* ⚠️ Customer or admin requests schedule change. — **admin only; no customer-side reschedule endpoint**
* ❌ System displays available date and time options. — **date picker only; no slot availability shown**
* ✅ New schedule is selected.
* ✅ System updates appointment details.
* ⚠️ Customer receives updated appointment notification. — **SMS record created but not sent**

### Appointment Cancellation Workflow

* ⚠️ Customer or admin selects appointment to cancel. — **admin only; no customer cancellation endpoint**
* ✅ System asks for confirmation. — confirmation modal on cancel action
* ✅ System updates appointment status to cancelled.
* ⚠️ Customer receives cancellation notification. — **SMS record created but not sent**

---

## G. Ordering Workflows

### Customer Product Ordering Workflow

* ✅ Customer browses product catalog.
* ✅ Customer selects product/frame.
* ✅ Customer reviews product details.
* ✅ Customer adds product to order.
* ✅ Customer confirms order details.
* ⚠️ System validates stock availability. — **validated on admin confirmation, not at order submission**
* ✅ System creates order with pending status. — status `requested`
* ✅ Customer receives order confirmation. — order returned in response
* ✅ Admin receives new order notification. — visible in Filament orders list

### Admin Order Review Workflow

* ✅ Admin views pending orders.
* ✅ Admin opens order details.
* ✅ Admin checks customer information, selected product, and prescription if needed.
* ✅ Admin confirms or rejects order.
* ✅ System updates order status.
* ⚠️ Customer receives order status update. — **status visible in `GET /orders`; no push notification**

### Order Status Update Workflow

* ✅ Admin opens active order.
* ✅ Admin updates order status.
* ⚠️ Supported statuses include pending, confirmed, processing, ready, completed, and cancelled. — **statuses are: requested, confirmed, processing, ready_for_pickup, completed, cancelled (no "pending")**
* ✅ System saves new status.
* ✅ Customer sees updated status in mobile app.
* ❌ SMS notification is sent for major status changes. — **not implemented for orders**

### Order Cancellation Workflow

* ⚠️ Customer requests cancellation or admin cancels order. — **customer cannot cancel; admin only**
* ✅ System checks if cancellation is still allowed. — staff can only cancel `requested`; admin can cancel up to `ready_for_pickup`
* ✅ Admin confirms cancellation.
* ✅ System updates order status to cancelled.
* ✅ System restores stock if needed. — `order_reversal` movement
* ⚠️ Customer receives cancellation update. — **visible in API; no push/SMS notification**

---

## H. Billing and Payment Recording Workflows

### Billing Record Creation Workflow

* ✅ Admin opens confirmed order.
* ⚠️ Admin creates billing record or invoice. — **auto-created on order confirmation; no manual create step**
* ✅ System calculates total amount.
* ✅ Admin enters payment details if available. — Record Payment action
* ✅ System saves billing record.
* ✅ Customer can view billing status in mobile app. — `GET /billing/{id}`

### Payment Recording Workflow

* ✅ Customer pays directly at the clinic.
* ✅ Admin opens billing record.
* ✅ Admin records amount paid, payment method, and payment date.
* ✅ System updates payment status to unpaid, partial, or paid.
* ✅ Customer sees updated payment status.
* ❌ SMS notification is sent if enabled. — **not implemented for billing**

### Invoice Viewing Workflow

* ✅ Customer opens order or billing section.
* ✅ System displays invoice details.
* ✅ Customer views amount due, amount paid, payment status, and order reference.

---

## I. Direct Messaging Workflows

### Customer Message Workflow

* ✅ Customer opens messaging module.
* ✅ Customer creates a message or inquiry.
* ✅ Customer optionally links message to an order. — `contexts[]` array supports Appointment, Order, Product
* ✅ System sends message to admin inbox.
* ✅ Message is stored in conversation history.

### Admin Reply Workflow

* ✅ Admin opens message inbox. — Conversations resource
* ✅ Admin views customer message.
* ✅ Admin writes reply.
* ✅ System sends reply to customer.
* ✅ Customer sees reply in mobile app.

### Message Read Status Workflow

* ❌ User opens a message.
* ❌ System marks message as read. — **no `read_at` field on messages**
* ❌ Unread message count is updated.

---

## J. Feedback and Ratings Workflows

### Customer Feedback Submission Workflow

* ✅ Customer opens completed order.
* ✅ Customer selects rating.
* ✅ Customer enters optional comment.
* ✅ System validates feedback.
* ✅ System saves feedback record.
* ✅ Admin can view feedback in the web app.

### Admin Feedback Review Workflow

* ✅ Admin opens feedback and ratings module.
* ✅ Admin views customer ratings and comments.
* ⚠️ Admin filters feedback by date, rating, or order. — **rating filter only; no date or order filter**
* ❌ Admin uses feedback for reporting and service improvement. — **no reports module**

---

## K. SMS Notification Workflows

### Order SMS Notification Workflow

* ❌ Order status changes.
* ❌ System checks if SMS notification is required.
* ❌ System prepares SMS message.
* ❌ System sends SMS through the SMS provider.
* ❌ System records SMS status as sent or failed.

### Appointment SMS Notification Workflow

* ✅ Appointment is approved, rescheduled, or cancelled. — record created in `sms_notifications`
* ❌ System sends SMS notification to customer. — **record stays `queued`; no actual dispatch to Semaphore**
* ✅ System stores SMS delivery result. — status field exists; not updated in practice

### Billing SMS Notification Workflow

* ❌ Billing or payment status changes.
* ❌ System sends SMS update to customer.
* ❌ System records SMS notification history.

### Failed SMS Workflow

* ❌ SMS provider returns failed status.
* ❌ System records failure reason.
* ❌ Admin can view failed SMS logs.
* ❌ Admin may resend notification if needed.

---

## L. Record Import Workflows

### Existing Customer Record Import Workflow

* ❌ Admin prepares existing clinic records.
* ❌ Admin imports customer records into the system.
* ❌ System validates imported data.
* ❌ System identifies duplicate or incomplete records.
* ❌ Admin reviews import errors.
* ❌ System saves valid records.

### Existing Product/Inventory Import Workflow

* ❌ Admin prepares product or inventory records.
* ❌ Admin imports records into the system.
* ❌ System validates product names, prices, and stock quantities.
* ❌ System saves valid product records.
* ❌ Admin reviews imported inventory.

---

## M. Reports and Monitoring Workflows

### Dashboard Summary Workflow

* ✅ Admin opens dashboard.
* ⚠️ System displays total customers, products, orders, appointments, sales, and pending tasks. — **appointment stats, pending orders, low stock, unpaid billings shown; no total customers or products count widget**
* ✅ Admin can quickly access major modules.

### Sales/Billing Report Workflow

* ❌ Admin opens reports module.
* ❌ Admin selects sales or billing report.
* ❌ Admin filters by date range.
* ❌ System displays total sales, paid invoices, unpaid invoices, and partial payments.

### Order Report Workflow

* ❌ Admin selects order report.
* ❌ Admin filters by date or status.
* ❌ System displays order totals by status.

### Inventory Report Workflow

* ❌ Admin selects inventory report.
* ❌ System displays stock levels.
* ❌ System highlights low-stock or out-of-stock products.

### Appointment Report Workflow

* ❌ Admin selects appointment report.
* ❌ Admin filters by date or status.
* ❌ System displays pending, approved, completed, cancelled, and rescheduled appointments.

### Feedback Report Workflow

* ❌ Admin selects feedback report.
* ❌ System displays average rating and feedback summary.
* ❌ Admin filters feedback by date or rating.

---

## N. Security and Privacy Workflows

### Role-Based Access Workflow

* ✅ System checks user role before allowing access to each module.
* ✅ Customers can only access customer-facing features.
* ✅ Admin/staff can access management features.
* ✅ Unauthorized users are blocked.

### Customer Data Privacy Workflow

* ✅ Customer logs in.
* ✅ System displays only that customer's profile, orders, prescriptions, messages, appointments, and billing records.
* ✅ System prevents access to another customer's data.

### Password Security Workflow

* ✅ User creates or updates password.
* ⚠️ System validates password requirements. — **only `min:8` enforced; no complexity rules**
* ✅ System stores password securely using hashing.
* ✅ System never displays stored password.

### Audit/Activity Log Workflow

* ✅ Admin performs important action such as updating orders, billing, prescriptions, or inventory.
* ✅ System records user, action, date, and affected record.
* ✅ Admin can review activity logs if needed.

---

## O. System Maintenance Workflows

### Backup Workflow

* ❌ Admin or system administrator performs database backup.
* ❌ System stores backup securely.
* ❌ Backup can be restored if needed.

### Error Handling Workflow

* ✅ System detects an error.
* ✅ System displays user-friendly error message.
* ✅ System logs technical error details for developer review.
* ✅ System avoids exposing sensitive information.

### Data Validation Workflow

* ✅ User submits form.
* ✅ System validates required fields, formats, file types, and values.
* ✅ System shows clear validation messages.
* ✅ System only saves valid data.

---

## Summary of Gaps

### Missing (❌)

| Area | Gap |
|---|---|
| B | `PATCH /user` — customer cannot update own profile |
| E | Customer prescription upload workflow (upload → pending → admin review/approve) |
| F | Appointment slot availability check |
| F/G | Customer-side appointment and order cancellation endpoints |
| F | Customer-side reschedule request endpoint |
| G | SMS on order status changes |
| H | SMS on billing/payment changes |
| I | Message read/unread tracking (`read_at`, unread count) |
| K | SMS actual sending via Semaphore (appointment records created but never dispatched) |
| K | Order and billing SMS workflows |
| K | Failed SMS logging and retry in admin |
| L | Bulk import for customers and products |
| M | Full reports module (sales, orders, inventory, appointments, feedback) |
| O | Database backup tooling |

### Different from Spec (⚠️)

| Area | Difference |
|---|---|
| A | No `address` field on users; no phone number login |
| A | Phone uniqueness not validated on registration |
| C | Material/color are free-form JSON attributes, not dedicated fields |
| C | Product deactivation has no confirmation modal (immediate toggle) |
| F/G | Customer receives status updates by polling API, not push notification |
| G | Order status "pending" called "requested" in implementation |
| G | Stock validated on admin confirmation, not at customer order submission |
| H | Billing auto-created on order confirmation (no manual admin step) |
| J | Feedback date and order filters not yet added |
| M | Dashboard missing total customers and total products count widgets |
| N | Password only requires min 8 characters, no complexity rules |

### Priority Gaps

**P1 — Blocks core user workflows**
- `PATCH /user` — customer profile update
- Customer order cancellation (`requested` status)
- SMS actual sending (Semaphore integration)

**P2 — Expected but missing**
- Message read/unread tracking
- Customer appointment cancellation
- SMS for order status changes
- Feedback date filter

**P3 — Important for completeness**
- Customer prescription upload
- Reports module
- Failed SMS admin log + retry
- Appointment slot availability check

**P4 — Lower priority / infrastructure**
- Bulk import
- Database backup
- Phone number login
- Password complexity rules
