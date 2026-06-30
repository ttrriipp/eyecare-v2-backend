# POCMS — Optical Clinic Workflow Audit

> *Evaluated from the perspective of a small independent optical clinic owner in the Philippines with 15+ years of experience. This is operational feedback, not engineering feedback.*

---

## Workflow 1: Appointment Booking

### Summary
Patients book via the Android app; staff see and manage appointments in the Filament panel with a calendar view, conflict detection, and drag-reschedule.

### What Works
- Conflict detection per visit reason duration (Eye Exam 30 min, Contact Lens Fitting 60 min) — this is actually correct
- Walk-in quick-create (name + phone only) is realistic for a Filipino clinic where half your day is walk-ins
- Staff can drag appointments on the calendar to reschedule with confirmation modal
- SMS notifications on confirm/reschedule/cancel
- 24-hour reminder command (`appointments:send-reminders`)

### Problems
- The app requires the patient to have a smartphone, create an account, and remember their login. In the Philippines, most optical clinic patients are 40+. They will call or message on Facebook Messenger instead.
- The conflict window is fixed per visit reason — but a real clinic doesn't have neat 30-minute slots. One patient takes 20 minutes, the next takes 45. You can't predict this.
- No concept of multiple doctors/optometrists. A clinic with two optometrists can't assign slots per doctor — everything goes into one calendar.
- No "tentative" or "unconfirmed" booking status — in practice, many patients book verbally and confirm later.

### Missing Features
- Walk-in queue view — a simple "patients currently waiting" list would help staff manage peak hours without touching the calendar
- Doctor/optometrist assignment separate from "staff" (currently staff = receptionist + doctor in the same pool)
- "Bulk book" for follow-up series (e.g., 3 fitting appointments weekly)

### Real Clinic Comparison
Most Philippine optical clinics still manage appointments via Facebook Messenger, Viber, or a physical logbook. Patients send a message: "Pwede ba bukas ng 10am?" The staff replies. No app involved. Walk-ins dominate.

### Suggested Improvements
1. Add a simple walk-in queue counter on the dashboard
2. Add an optometrist field (FK to staff with a role filter) separate from assigned staff
3. Consider a WhatsApp/Messenger webhook integration as a booking channel

### Practicality Score ⭐⭐⭐

### Owner Verdict
The calendar is genuinely useful for staff to see the day's schedule. The drag-reschedule is a nice touch. But the app-based booking will see near-zero adoption from actual patients — they'll keep messaging on Facebook. The value here is staff-side scheduling, not patient self-service. Worth keeping for staff, not worth marketing to patients as a feature.

---

## Workflow 2: Prescription Management

### Summary
Staff encode prescriptions with full OD/OS/PD fields. Prescriptions are linked to appointments. Encrypted at rest. Printable as PDF. Expiry alerts sent to staff 30 days before.

### What Works
- Full OD/OS table (Sphere, Cylinder, Axis, Add, Prism, Base, PD) — complete and correct for Philippine optometry
- Encryption at rest addresses DPA concerns for health data
- Print Prescription PDF is genuinely useful — patient takes the printout to another clinic or lens grinder
- Expiry alerts (30 days) prompt staff to reach out for rebooking
- Prescription history per patient enables comparison across visits

### Problems
- **The most impractical workflow in the entire system.** In a real clinic, the optometrist writes the prescription by hand on a form, gives the patient a copy, and the staff manually records it in a logbook or Excel. Nobody expects receptionists to open a web form and type spheres and cylinders between patients.
- `od_axis` is encrypted as a string — numerical validations are lost. In practice, axis is always 0–180. A staff member could type "1800" and the system accepts it silently.
- No integration with optometry equipment (autorefractometer) — data entry is fully manual.
- The "previous prescription" chain is a nice technical feature but adds cognitive load. Staff don't think in terms of linked prescription history.

### Missing Features
- Minimum/maximum validation on optical values (SPH typically -20 to +20, CYL -10 to +10, Axis 0–180)
- Quick-copy from previous prescription with ability to modify deltas
- "Prescription card" printout format — most Philippine clinics use a small wallet-sized card, not A4 PDF

### Real Clinic Comparison
Filipino optical clinics use a pre-printed prescription pad. The optometrist writes by hand. The receptionist keeps a logbook or photographs the prescription. Digitizing this is theoretically correct but faces adoption resistance unless it's faster than handwriting, which it isn't.

### Suggested Improvements
1. Add range validation on OD/OS numerical fields (even though they're stored encrypted, validate before saving)
2. Add a "duplicate from last visit" shortcut that copies the previous prescription as a starting point
3. Consider a smaller, practical print format (credit card size)

### Practicality Score ⭐⭐⭐

### Owner Verdict
The data model is correct and the PDF printout is useful. But in a busy clinic, asking staff to encode full prescriptions between patients is unrealistic without an incentive. The adoption path is: optometrist transcribes after consultations, not in real-time. Worth keeping, but don't expect it to replace paper on day one.

---

## Workflow 3: Order Management

### Summary
Customers order frames via Android app. Staff review, assign lenses, confirm stock, and process through a 5-step status chain: Requested → Confirmed → Processing → Ready for Pickup → Completed.

### What Works
- Prescription gate (can't confirm an order requiring a prescription without one on file) — prevents a real mistake
- Lens assignment gate before confirmation — forces staff to specify exactly which lens will be used
- Inventory deduction on confirm, reversal on cancel — correct accounting
- Discount types (Senior Citizen 20%, PWD 20%) — required in the Philippines
- Bulk advance orders action — saves clicks for staff processing multiple orders

### Problems
- **5 status steps is too many for a walk-in transaction.** A customer walks in, picks a frame, gets measured, pays. In the system this is: create order → assign lens → confirm → process → ready for pickup → complete. That's 5 clicks minimum plus a lens assignment modal. Real transaction time: 3 minutes in a notebook, potentially 5+ minutes in this system.
- The `is_non_prescription` field is confusing for both customers and staff. "Is this non-prescription?" is not how optical clinics think. They think: "Does this patient need lenses?"
- No partial fulfillment — if a frame and two accessories are in one order and one is out of stock, the entire order is blocked. In practice, clinics would process the available items and note the backordered one.
- No cash collection/payment recording during ordering — payment happens separately in billings. Staff would need to switch to the Billing section to record cash. That's two screens for one transaction.
- The Android ordering workflow (submit → wait for staff review) doesn't match how Filipino customers buy frames. They don't order online and wait. They come in and decide.

### Missing Features
- Direct "Walk-in sale" shortcut that skips the request step and goes straight to confirmed
- Partial fulfillment with backordering
- Inline payment recording at order completion (don't force staff to navigate to Billings for a simple cash transaction)

### Real Clinic Comparison
In most Philippine optical clinics, the transaction goes: customer chooses frame → optometrist or staff checks prescription → price is quoted → customer pays → frame is sent to lab for lens cutting → customer returns in 3–7 days for pickup. The "order" is often just a paper job order form.

### Suggested Improvements
1. Add a fast-track "Walk-in Sale" action that bypasses the requested step
2. Replace `is_non_prescription` with "Requires lens cutting?" (patient-friendly language)
3. Add inline payment at order completion ("Collect payment now?" modal)

### Practicality Score ⭐⭐

### Owner Verdict
The inventory gates are genuinely valuable — they prevent a real operational problem (ordering a lens that's not in stock). But the overall workflow is designed around an e-commerce model (online ordering, fulfillment pipeline) when optical clinic transactions are predominantly face-to-face. For a clinic with no online ordering, this is overkill. For a clinic that wants to accept online frame reservations, it's functional but clunky.

---

## Workflow 4: Inventory Management

### Summary
Inventory is tracked per product variant. Manual stock adjustments via "Adjust Stock" action with movement types (restock, manual adjustment, order commitment, order reversal). Reorder report shows items below threshold.

### What Works
- Movement history with previous/new stock — full audit trail
- Low stock threshold alerts via database notifications
- Reorder report (items below threshold sorted by deficit) — directly answers "what do I order today?"
- Read-only stock field with explicit Adjust Stock prevents accidental edits — important for a multi-staff environment
- `in_stock` flag on API prevents customers from ordering unavailable items

### Problems
- **No supplier tracking.** The reorder report tells you what to order but not who to order from, at what price, or with what lead time. In a real clinic, you need to call your frame rep or send a Viber message to your supplier. The system has no way to record this.
- Frame inventory in optical clinics is fundamentally different from standard retail. You don't "restock" the same frame indefinitely — frames go out of style, suppliers discontinue SKUs, and you often get one of each variant. The current model treats frames like canned goods with predictable restocking.
- No "reserved" or "on hold" status for frames being fitted. A customer picks Frame A today, but pickup is next week after lens cutting. That frame should be reserved, not just deducted from stock on order confirmation.
- No damaged/defective product workflow. Staff can use "manual_adjustment" but there's no explicit damage recording.

### Missing Features
- Supplier contact information linked to products/brands
- Reserved/on-hold stock status during lens cutting period
- Damaged goods write-off with reason
- Lens blanks inventory separate from finished lens inventory

### Real Clinic Comparison
Most small Philippine optical clinics keep a simple tally in a notebook or Excel: "Frame X: 5 pcs in stock." Inventory management at this scale doesn't need sophisticated movement tracking — it needs a fast way to check "do I have this frame?" and "what do I need to reorder?"

### Suggested Improvements
1. Add a supplier contact field on brands/categories
2. Add "Reserved" as a virtual stock status during orders in processing
3. Add a damage write-off movement type with required reason

### Practicality Score ⭐⭐⭐

### Owner Verdict
Better than what most small clinics have. The reorder report alone is worth it. But the lack of supplier tracking means the system stops at "here's what you need" and you still have to manage the actual procurement the old way — phone calls and Viber messages. That's the most painful part and it's still unsolved.

---

## Workflow 5: Billing and Payments

### Summary
Encounter-based billing groups services and products under one appointment. Billings are auto-generated on order confirmation. Staff can add service line items. PDF receipt generation. Discounts for Senior Citizen/PWD.

### What Works
- Encounter grouping (one billing per appointment visit) is technically correct and mirrors how some larger clinics invoice
- PDF receipt printout — patients expect a receipt
- Senior Citizen and PWD discounts with correct 20% computation — legally required
- Void with full audit trail — protects against accidental data deletion
- Partial payment recording (issued → partially paid → paid status chain)

### Problems
- **This billing model will confuse a receptionist who previously issued handwritten receipts.** The concept of "billing items," "service records," "encounter grouping," and "balance due" is hospital/insurance-grade complexity for a clinic that usually writes "₱2,500 — paid cash" on a piece of paper.
- Two-screen problem: order confirmation auto-generates a billing, but to record payment the staff must navigate from Orders to Billings. This is two separate resources. In a fast transaction, this is a UX failure.
- No OR number (Official Receipt number). Philippine BIR requires ORs for all sales transactions. The billing number (`BIL-2026-000001`) is internal — it's not an OR. This is a legal compliance gap.
- No cash change computation. When a customer pays ₱3,000 for a ₱2,500 order, staff needs to record ₱500 change. The system has no change field.
- No receipt printer integration. The PDF is nice but requires a computer and printer setup. Most small clinics use thermal receipt printers (80mm paper).

### Missing Features
- OR number field (BIR-compliant sequential receipt numbering)
- Cash tendered + change computation in the payment recording modal
- "Record payment and go back to order" shortcut — avoid the two-screen problem
- Thermal receipt format (80mm layout) in addition to A4 PDF

### Real Clinic Comparison
A small Philippine optical clinic uses an official receipt book (BIR-registered) and writes the receipt by hand or prints from an existing POS system. The concept of "encounter-based billing" is unknown to 90% of small clinic owners — they just add up the charges and write a total.

### Suggested Improvements
1. Add OR number field to billings (auto-incrementing, admin-configurable prefix)
2. Add cash tendered + change fields to the Record Payment modal
3. Simplify the billing view for receptionists — a single "Total: ₱X, Paid: ₱Y, Balance: ₱Z" summary is enough for daily operations; the full encounter detail can be hidden under a toggle

### Practicality Score ⭐⭐

### Owner Verdict
The billing model is technically correct and would suit a clinic with an actual accountant who understands encounter-based invoicing. For a 2-person clinic where the owner is also the cashier, this is overwhelming. The missing OR number is a real legal problem. I would not use this without simplifying the payment recording flow significantly.

---

## Workflow 6: AR Try-On

### Summary
Customers use the Android app to overlay frame images on the camera feed. Staff upload transparent PNG files per frame variant. The overlay is a static 2D image, not 3D.

### What Works
- It looks impressive in a demo
- Low server impact (all processing is client-side)
- Requires no special hardware

### Problems
- **It's a 2D PNG overlay.** The frame doesn't follow the face when the customer moves. It sits in the center of the screen. This is not AR in any meaningful sense — it's a floating sticker.
- Frame sizing doesn't scale with face distance from camera. A large frame looks the same at 30cm and 60cm away.
- Lighting conditions in a clinic (fluorescent overhead lighting) wash out the overlay on most phone cameras.
- The customer still has to come to the clinic to try the physical frame. The online try-on doesn't replace the in-person experience.
- Maintaining AR assets (uploading a correctly cropped transparent PNG for every frame variant) is staff work with no clear return. A clinic with 200 frame variants would need to photograph and edit 200 PNGs.

### Missing Features
- Face detection to position the overlay correctly
- Size scaling based on face geometry

### Real Clinic Comparison
Filipino optical clinic customers try on physical frames from the display rack. They ask their companion, look in the mirror, try multiple frames. The AR try-on doesn't replicate this because it lacks tactile feedback, size accuracy, and social interaction ("Bagay ba sa'kin?"). The in-person frame try-on is a social and sensory experience that a 2D overlay cannot compete with.

### Suggested Improvements
Replace with a high-quality product photo gallery with multiple angles. Better product photos will drive more purchase intent than a technically limited overlay.

### Practicality Score ⭐

### Owner Verdict
I would not invest a single peso into maintaining AR assets for this. The staff time required to upload, crop, and manage PNGs for every frame is real work with no measurable return. If the Android team wants AR, they need face mesh tracking (ARCore). What's there now is a marketing demo, not a clinical tool.

---

## Workflow 7: Patient Mobile App

### Summary
Customers register, browse frames, try AR, book appointments, track orders, view prescriptions, and message staff via the app.

### What Works
- Appointment booking is accessible 24/7 — patients can book at midnight if they want
- Prescription history access is genuinely valuable — patients often lose their paper prescription and need the grade for another purchase
- Order status tracking reduces "tawag kung anong nangyari sa order ko" (follow-up calls)
- The conversation system gives patients a direct line to staff without switching to Messenger

### Problems
- **Filipino optical clinic patients will not install a dedicated app for a clinic they visit once or twice a year.** App installs require trust, storage space, and willingness to create yet another account. The adoption barrier is extremely high.
- The app requires an internet connection for every function. In areas with spotty signal (many parts of the Philippines outside Metro Manila), the app simply doesn't work.
- Older patients (40+, the core optical clinic demographic) are uncomfortable with app-based workflows. They prefer calling or messaging on Facebook.
- The login requirement (email + password) will cause abandonment. A large portion of Filipino clinic patients use only a phone number, not an email address.
- No push notifications — without push, the app has no way to tell the patient their order is ready. They have to actively open the app and check.

### Missing Features
- Phone number + OTP login (no email required) — the standard in Philippine mobile apps
- SMS deep links as a fallback for patients who don't open the app
- Offline mode for viewing existing prescriptions and appointments

### Real Clinic Comparison
Filipino patients communicate with clinics via Facebook Messenger or SMS. A dedicated app competes with Messenger for a use case where Messenger already wins. The prescription access feature is the strongest reason to install the app — but even that requires convincing the patient to register first.

### Suggested Improvements
1. Add phone number + OTP authentication alongside email/password
2. Push notifications via Firebase (currently missing — the #1 reason patients would open the app regularly)
3. For prescription access specifically: consider a QR code on the printed prescription that opens a no-login prescription viewer

### Practicality Score ⭐⭐

### Owner Verdict
I would use the backend admin panel heavily. I would not actively promote the Android app to patients because I know my patients. They'll ask me to send their prescription via Messenger. The app would benefit from push notifications and phone number login before I'd recommend it to my customers.

---

## Workflow 8: Dashboard and Reports

### Summary
Dashboard has KPI stats (today's appointments, revenue, pending orders, unpaid billings, low stock), a 30-day appointment trend chart, and recent feedback. Five report pages (Sales, Orders, Appointments, Feedback, Reorder) with date filtering and CSV export.

### What Works
- Revenue this month vs last month comparison — I check this first thing in the morning
- Low stock variants count with color coding — actionable at a glance
- Reorder report sorted by deficit — exactly what I need on purchasing day
- CSV export on reports — I can bring this to my accountant
- Dashboard data is cached so it loads fast even with a year of data

### Problems
- **The dashboard doesn't show today's unpicked orders.** Orders that are "Ready for Pickup" but haven't been collected are the most operationally important thing on a given day — patients who haven't been called yet, orders sitting in the cabinet.
- No daily appointment list on the dashboard itself. The stat says "5 appointments today" but I have to click through to see who and when.
- The 30-day chart shows all non-cancelled appointments — no-show rate is invisible. I need to see my no-show rate because it directly affects revenue.
- Reports are admin-only. Staff should be able to see basic reports to manage their own tasks.
- No end-of-week or end-of-month summary I can print for the accountant.

### Missing Features
- "Ready for Pickup" widget — list of orders awaiting collection with patient contact
- Today's appointment schedule on the dashboard itself (not just the count)
- No-show tracking ("Mark as No-Show" action on appointments)
- Staff-accessible simplified reports

### Real Clinic Comparison
Most small Philippine clinic owners check their cash drawer, their appointment book, and their pending orders every morning. The dashboard approximates this but misses the "pending pickups" which is a real daily pain point.

### Suggested Improvements
1. Add a "Ready for Pickup" stat widget linking to filtered orders list
2. Show today's schedule as a mini-list widget (3–5 appointments with time and name)
3. Add no-show tracking ("Mark as No-Show" action on appointments that sets a terminal status)

### Practicality Score ⭐⭐⭐⭐

### Owner Verdict
This is the strongest part of the system for a clinic owner. The revenue tracking alone justifies the system — I finally know exactly how much I'm making each month. The reorder report saves me from guessing during supplier calls. I'd use the dashboard every single day. Just add the pending pickups widget and it's nearly perfect for daily operations.

---

## Overall Impression

The system was clearly designed by someone who understands optical clinic domain logic — the prescription gates, lens assignment gates, encounter billing, and inventory movement tracking are all technically correct. What it lacks is operational empathy for the staff who will actually use it at 10am with three patients waiting.

The admin panel is strong. The patient app needs significant UX rethinking for the Philippine market.

---

## Biggest Strengths

1. **Prescription gates and inventory gates on orders** — prevents the two most common and costly errors in optical clinics (confirming an order without a prescription on file, and selling a lens that isn't in stock). This alone saves money.

2. **Dashboard revenue visibility** — small clinic owners often have no idea what their monthly revenue is. The sparklines, month-over-month comparison, and report pages provide financial clarity that a paper logbook never could.

3. **Reorder report + low stock alerts** — the single most practical inventory feature. Sorted by deficit, immediately actionable during supplier calls.

---

## Biggest Weaknesses

1. **Billing model complexity** — encounter-based invoicing with service records, billing items, and two-screen payment recording is too sophisticated for a 2-person clinic. The missing OR number is a legal compliance gap that makes this unsuitable for production use in the Philippines.

2. **No phone number + OTP login** — the patient app requires email registration, which is a near-fatal adoption barrier in the Philippine market where many patients only have a phone number and use Facebook as their primary identity.

3. **No-show and walk-in queue management** — the system assumes all patients book through the app, but Philippine clinics are walk-in dominated. There's no queue view, no no-show tracking, and no fast walk-in transaction path.

---

## Features to Remove (or Deprioritize)

1. **AR Try-On** — a 2D PNG overlay is not AR. The maintenance burden (uploading cropped PNGs for every variant) is ongoing staff work with no measurable return. Replace with high-quality product photos.

2. **Prescription Upload (if not fully implemented)** — the concept is right but the implementation complexity is high for a feature that could be handled with a WhatsApp photo instead.

3. **Conversation system for staff-patient messaging** — the right idea in the wrong channel. Patients are on Facebook Messenger. Duplicating that functionality in a private app nobody will install is a waste of maintenance effort. Focus the messaging budget on SMS reminders instead.

---

## Features to Add

1. **OR number (Official Receipt)** — BIR compliance. Non-negotiable for legal operation in the Philippines. Auto-incrementing with configurable prefix (e.g., "OR-2026-000001").

2. **Phone number + OTP login** — removes the email requirement. The #1 adoption barrier for the patient app in the Philippine market.

3. **Walk-in queue / No-show tracking** — a simple "patients currently waiting" counter and a "Mark as No-Show" action on appointments. Low effort, high operational value.

4. **Ready for Pickup dashboard widget** — the most important daily operational view for a clinic with a lens cutting workflow. "Which orders are done and waiting for the patient?"

5. **Cash tendered + change in payment modal** — prevents cashier mental math errors and makes the payment recording flow complete.

---

## Deployment Readiness

**Needs minor workflow changes**

The core data model and admin panel are production-viable with the following minimum fixes before deployment:
- OR number on billings (legal requirement)
- Cash tendered + change on payment recording
- Simplify or hide billing complexity for receptionist role
- Phone number login for the patient app

---

## Final Verdict

> *"If I owned a small independent optical clinic in the Philippines, would I invest in and use this system?"*

**Yes — for the admin panel. No — for the patient-facing app as currently built.**

The admin panel would replace my paper logbook, Excel spreadsheet, and accounting notebook with something genuinely better. The prescription gates, inventory tracking, revenue reports, and reorder alerts would pay for themselves within the first month in prevented errors and saved purchasing time. I would use the dashboard every morning.

The patient app I would not actively push to my patients yet. My 50-year-old regulars will not install a dedicated app. They'll keep messaging me on Facebook. The app needs phone number login and push notifications before I'd even show it to a patient. Without those two features, it's a demo that nobody will use.

The billing system needs an OR number before I can legally replace my receipt book. That's not optional in the Philippines.

The AR feature I would disable immediately and never mention to a panelist — it will either impress them as a concept or embarrass the team when they ask how it actually works. Be honest that it's a proof of concept. Channel that maintenance effort into better product photos instead.

**Bottom line:** Strong technical foundation, real operational value in the admin panel, needs 3–4 targeted improvements before I'd stake my clinic's operations on it.
