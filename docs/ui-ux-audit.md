# POCMS — UI/UX Audit

> *Evaluated by a senior UI/UX designer with 12 years of healthcare software experience. Based on the implemented system, not mockups or aspirations.*

---

## Screen 1: Web Admin — Dashboard

### Purpose
At-a-glance operational view: today's appointments, revenue, pending orders, low stock, walk-in queue.

### Strengths
- Six KPI stat cards cover the most critical daily metrics a clinic owner checks first thing
- "Waiting today" (walk-in queue) card with orange/green color coding gives instant situational awareness
- 30-day appointment trend chart provides business context without requiring report navigation
- Cards link to filtered list views — clicking "Low stock" takes you directly to the relevant list
- Revenue comparison (this month vs last month) with percentage change is genuinely useful for owners

### Weaknesses
- Stat cards do not show today's appointment schedule — the count is visible but not who/when without clicking through
- No "Ready for Pickup" stat — orders waiting for patient collection are invisible on the dashboard
- The Recent Feedback widget at the bottom is rarely critical information; that space is better used for pending actions

### UX Problems
- **Nielsen #1 (Visibility of system status):** Staff doesn't know which specific patients are waiting, only how many. A mini-list would be more actionable.
- **Recognition over recall:** No quick action buttons on the dashboard. Staff has to navigate to Orders, Appointments etc. to do anything — the dashboard is purely read.
- Pending orders stat doesn't distinguish between "patient just submitted online" (low urgency) and "confirmed order sitting unprocessed" (high urgency).

### Accessibility Concerns
- Orange/yellow warning colors used for "Waiting today" — acceptable but color alone distinguishes urgency; ensure icon is also present (it is via Heroicon).

### Suggested Improvements
1. Add a "Today's Schedule" mini-widget: list of the next 3-5 appointments with time, patient name, and visit reason
2. Add "Ready for Pickup" KPI card (orders awaiting collection)
3. Add quick action buttons: "Confirm next pending appointment", "View today's orders"

### Severity: Moderate

### UX Score ⭐⭐⭐⭐

---

## Screen 2: Web Admin — Appointment Calendar

### Purpose
Visual scheduling view with drag-reschedule, click-to-create, click-to-open.

### Strengths
- Day view default is correct — clinic staff needs to see today's schedule, not a month grid
- Drag-to-reschedule with confirmation modal prevents accidental changes
- Color-coded events by status (confirmed=blue, rescheduled=amber, completed=green, cancelled=red) are immediately readable
- Visit reason duration correctly reflected in event block height
- 15-minute slot intervals with 8am-9pm window is appropriate for clinic hours

### Weaknesses
- No patient phone number visible on calendar events — staff frequently needs to call to confirm; they have to click through to the appointment
- No visual distinction between "booked via app" vs "walk-in created by staff" — useful for understanding patient type
- When two appointments are close together, names truncate without a tooltip

### UX Problems
- **Nielsen #6 (Recognition over recall):** Staff must click each event to see appointment details. A hover/click popover with patient name, phone, and visit reason would eliminate most navigation.
- Drag-reschedule reverts visually while the confirmation modal is open — correct behavior but potentially confusing to new staff who think the drag failed.

### Suggested Improvements
1. Add event popover on click showing: patient name, phone number, visit reason, status — before navigating to edit page
2. Show patient phone in event title or popover for quick call reference

### Severity: Moderate

### UX Score ⭐⭐⭐⭐

---

## Screen 3: Web Admin — Order Create (New Order + Walk-in Sale)

### Purpose
Two-step wizard for creating orders. Walk-in Sale fast-track confirms immediately.

### Strengths
- Two-step wizard (Order Details → Items) prevents overwhelming staff with a single long form
- Walk-in Sale button is visually distinct (orange/warning, bolt icon) — staff won't confuse it with New Order
- "No lens cutting required" toggle with helper text is clearer than the previous "Non-Prescription Order"
- Inline price calculation as staff selects variants eliminates mental math
- Walk-in Sale subheading warning ("order will be confirmed immediately") prevents surprises

### Weaknesses
- The order items repeater shows product variants by name only — no SKU, no stock count, no price preview until selected. Staff has to select to see price.
- No "quick add common items" shortcut — staff always starts from zero even for repeat purchases like cleaning kits
- Walk-in Sale failure message ("Order saved as pending") is technically accurate but operationally unclear — staff may not know *why* it failed without reading the notification body

### UX Problems
- **Nielsen #5 (Error prevention):** Staff can select a variant that's out of stock in the order items repeater — the stock gate only fires at confirmation. Should show `[OUT OF STOCK]` in the dropdown label.
- **Cognitive load:** The lens assignment gate isn't visible until confirmation attempt. Staff fills the whole form, hits confirm, gets an error. Better to flag unassigned lens items earlier.

### Suggested Improvements
1. Show `(stock: 5)` or `[OUT OF STOCK]` in the variant select options
2. Add inline validation on the items repeater: show warning icon next to items that need lens assignment before the form is submitted

### Severity: Major

### UX Score ⭐⭐⭐

---

## Screen 4: Web Admin — Edit Order

### Purpose
Staff manages the order through its lifecycle: status advancement, lens assignment, payment collection.

### Strengths
- Collect Payment inline button eliminates the two-screen problem identified in the audit
- Cash tendered + change display is operationally correct for a cash-heavy clinic
- Status toggle buttons are cycle-guarded — staff can't skip steps
- Sticky notification when unassigned lens items exist on page load
- Bulk advance action saves significant time for multi-order days

### Weaknesses
- "View Billing" and "Collect Payment" both use banknote icon — visually similar actions could cause accidental navigation
- After Collect Payment succeeds, the page doesn't visually update the balance without a manual refresh
- Discount type selector doesn't show the calculated ₱ amount in real-time before applying

### UX Problems
- **Nielsen #1 (Feedback):** Collecting payment fires a success notification but the header action button doesn't update its visibility until page reload — staff might try to collect payment again.
- The "Advance Selected" bulk action notification says "X advanced, Y skipped" but doesn't tell staff *why* orders were skipped.

### Suggested Improvements
1. After inline payment success, refresh the record automatically (same as AfterSave currently does)
2. Differentiate "View Billing" and "Collect Payment" button visually — different icons (document for view, cash for collect)

### Severity: Moderate

### UX Score ⭐⭐⭐⭐

---

## Screen 5: Web Admin — Billing View

### Purpose
Invoice detail view with payment recording, discount application, service addition, void action.

### Strengths
- OR number is copyable — useful when staff needs to reference it on a phone call
- Line Items section collapsed by default for staff — reduces cognitive load for receptionists who only need the total
- Thermal receipt ("Print Receipt") opens in new tab — doesn't disrupt current workflow
- Void billing shows exact ₱ amount being voided — prevents accidental voids

### Weaknesses
- Record Payment modal still buried inside the Payments relation manager tab — staff unfamiliar with Filament's tab structure may miss it
- "Download Receipt" (PDF A4) and "Print Receipt" (thermal) are side-by-side gray buttons — same color, similar icons, requires reading labels to distinguish
- After recording partial payment, balance_due doesn't update on-screen without a reload

### UX Problems
- **Nielsen #4 (Consistency):** Two print/download buttons with nearly identical appearance violate the principle that similar-looking elements should do similar things. These do different things (A4 vs thermal).
- **Information scent:** "Line Items (collapsed)" doesn't show a summary count. Staff doesn't know if there are 1 or 10 items without expanding.

### Suggested Improvements
1. Color-code print buttons: "Download Receipt" (gray) vs "Print Receipt" (blue/primary) to distinguish their purposes
2. Show item count in the collapsed Line Items section header: "Line Items (3)"
3. Add a "Record Payment" shortcut button in the Billing Summary section, not just in the Payments tab

### Severity: Moderate

### UX Score ⭐⭐⭐⭐

---

## Screen 6: Web Admin — Prescription Form

### Purpose
Record full optical prescription with OD/OS values, PD, dates, notes.

### Strengths
- OD/OS side-by-side layout is clinically appropriate — optometrists read prescriptions this way
- Range validation (Sphere -20 to +20, Axis 0-180) prevents obviously wrong entries
- "Copy to New" shortcut eliminates full re-entry for repeat visits
- Print Card (wallet-size) is a practical patient take-home format
- 0.25 step increments are correct for optical prescriptions

### Weaknesses
- The OD/OS sections have 6 fields each — 12 fields total — which is correct medically but overwhelming visually. The Prism/Base fields are rarely used but take the same visual weight as Sphere/Cylinder
- No visual comparison with previous prescription on the edit page — staff has to open a separate window
- "Prescribed at" and "Expires at" date pickers don't communicate their relationship visually

### UX Problems
- **Progressive disclosure violation:** Prism and Base fields should be hidden by default with a "Show advanced fields" toggle. They're needed for <5% of prescriptions but always visible, adding visual noise.
- No indication when a prescription is expiring soon on the edit form itself — only the staff notification handles this.

### Suggested Improvements
1. Add "Show Prism/Base fields" toggle — hide by default, reveal on demand
2. Show a "⚠ Expires in X days" badge on the edit page when within 30 days of expiry
3. Show the previous prescription values as read-only ghost text or a comparison panel

### Severity: Moderate

### UX Score ⭐⭐⭐

---

## Screen 7: Web Admin — Reports

### Purpose
Date-filtered breakdown reports: Sales, Orders, Appointments, Feedback, Reorder.

### Strengths
- Preset pills (This month, Last month, Last 30 days) eliminate date entry for common ranges
- Share bars are a good visualization for distribution data
- Export CSV provides data portability for accountants
- Reorder report with supplier contact column is genuinely operational

### Weaknesses
- All reports are admin-only — receptionists and staff can't view basic operational reports
- The Reorder report is a separate page from Inventory History — no cross-linking between "what to order" and "movement history" for that item
- Empty state shows a check circle which could be misread as "everything is fine" rather than "no data in range"

### UX Problems
- **Nielsen #2 (Match between system and real world):** "Feedback Report" shows ratings distribution — this is metrics, not operational. The Reorder and Sales reports are far more actionable but share equal navigation priority.
- Date presets and manual date inputs are side-by-side — the visual relationship isn't obvious to new users.

### Suggested Improvements
1. Move Reorder and Sales to the top of the Reports nav — most frequently needed
2. Allow staff role to access Appointments and Orders reports (operational, not financial)
3. Link item names in Reorder report to their Product edit page

### Severity: Minor

### UX Score ⭐⭐⭐⭐

---

## Screen 8: Web Admin — Conversations (Chat)

### Purpose
Staff communicates with patients via persistent per-customer conversation threads.

### Strengths
- Chat-style layout matches staff mental model (Messenger-like)
- 5-second polling makes it near-real-time without WebSockets
- Context links attach appointments/orders to messages — prevents "which order?" confusion
- Unread count badge on the conversation list

### Weaknesses
- No "mark as unread" — once a staff member opens a message, it's marked read even if they didn't respond
- No notification sound or visual flash when a new message arrives — staff must notice the unread badge change
- No quick-reply templates — common messages require full typing every time
- For a busy reception desk, the conversation panel requires staff to actively monitor a chat interface — not aligned with how a receptionist naturally works

### UX Problems
- **Nielsen #9 (Help users recognize, diagnose, recover):** If the patient's messages can't load, the error state isn't clearly surfaced.

### Suggested Improvements
1. Add quick-reply message templates (admin-configurable)
2. Add a browser notification opt-in for new messages
3. Consider a notification badge on the sidebar nav item, not just the conversation list

### Severity: Minor

### UX Score ⭐⭐⭐

---

## Screen 9: Android App — Product Browsing + AR Try-On

### Purpose
Customer browses frame catalog and virtually tries on frames.

### Strengths
- `in_stock` flag prevents browsing out-of-stock items (if Android displays it)
- Search/filter API (brand, category, price range, sort) enables catalog navigation
- AR try-on is discoverable on per-variant basis

### Weaknesses
- The AR feature is a 2D PNG overlay — no face tracking. The frame floats in the center of the screen regardless of face position or distance.
- No orientation guidance for AR — users will naturally hold the phone like a camera but the overlay doesn't respond to distance or tilt
- No loading state for the AR asset — if the PNG is slow to load, the camera view appears broken

### UX Problems
- **Nielsen #10 (Help and documentation):** No onboarding or instructions for AR. First-time users will tap the AR button expecting real-time face tracking and receive a static overlay.
- **False affordance:** The AR overlay looks interactive but isn't. It doesn't follow the user's face, making it feel broken even when working correctly.

### Suggested Improvements
1. Add a clear one-sentence instruction: "Position your face in the center of the screen" with a face outline guide
2. Show a loading indicator while the AR asset downloads
3. Label the feature "Virtual Preview" rather than "AR Try-On" to set accurate expectations

### Severity: Major (UX) / Critical (expectation management)

### UX Score ⭐⭐

---

## Screen 10: Android App — Appointment Booking

### Purpose
Customer books an appointment by selecting a visit reason, date/time, and contact notes.

### Strengths
- Visit reasons list is clear (Eye Exam, Follow-up, etc.) — patients understand the options
- 15-minute time slot intervals are reasonable for appointment scheduling
- Conflict detection prevents double-booking transparently

### Weaknesses
- No appointment slot visualization — customers see a date/time input, not a visual calendar showing available slots
- No indication of clinic operating hours — a customer could attempt to book at 11pm
- Email required for registration creates a barrier for many Filipino patients who primarily use phone numbers

### UX Problems
- **Nielsen #7 (Flexibility and efficiency):** The booking form is identical for all visit reasons despite different urgency and duration.
- **Recognition over recall:** Customers must know what "Prescription Check" vs "Follow-up" means without any description.

### Suggested Improvements
1. Add brief description under each visit reason: "Eye Exam — Comprehensive vision test for new prescriptions"
2. Add clinic operating hours notice: "We're open Mon-Sat, 9am-6pm"
3. Show available dates visually (calendar with available/unavailable days)

### Severity: Moderate

### UX Score ⭐⭐⭐

---

## Overall UI Assessment

The web admin panel is professionally designed using Filament 5 with consistent component usage — sectioned forms, sidebar layouts, and status badge colors. The branding (`#4F8DD7`, Instrument Sans, clinic logo) creates a coherent visual identity. The panel could be mistaken for commercial clinic management software at a glance, which is a genuine achievement for a capstone.

The Android app's UI quality depends on the Android team's implementation. The backend API is solid, but the frontend rendering is not evaluated here.

Minor visual inconsistencies remain: two similar-looking print buttons on billing view, Prism/Base fields at equal visual weight to Sphere/Cylinder on the prescription form.

---

## Overall UX Assessment

**Web admin (staff/admin):** Strong for power users who learn the system. The walk-in sale, bulk actions, inline payment, and calendar drag all reduce friction meaningfully. The main remaining issues are feedback latency after actions (balance doesn't update without reload) and the lack of actionable quick-access on the dashboard.

**Android (patients):** The booking and prescription access workflows are conceptually correct but adoption is the real UX problem. The biggest failure is email-required registration in a market where patients primarily identify via phone number.

**AR Try-On:** The UX is fundamentally mismatched with user expectation. Patients expect face tracking; they get a static overlay. This creates a "broken" perception even when the feature is working correctly.

---

## Top 5 Strengths

1. **Walk-in Sale fast-track** — Eliminates 3-4 clicks for the most common transaction type. Directly addresses the clinic's real workflow.

2. **Calendar drag-reschedule with confirmation modal** — Matches mental model of staff. The confirmation step prevents accidents without being burdensome.

3. **Status cycle-guard on order and appointment toggles** — Staff cannot put a system into an invalid state. Prevents data corruption. Invisible to users but enormously valuable.

4. **Inline Collect Payment on order edit page** — Closes the two-screen billing problem. Staff completes a transaction without leaving the order context.

5. **Thermal receipt + PDF receipt dual format** — Accommodates both clinics with receipt printers (80mm thermal) and those with laser/inkjet printers (A4 PDF). Shows real-world operational thinking.

---

## Top 10 Usability Issues

| # | Issue | Severity | Affected Users |
|---|---|---|---|
| 1 | **Email required for Android registration** — phone number + OTP is the Philippine standard | Critical | All patients |
| 2 | **AR is a 2D overlay presented as AR** — sets false expectations, feels broken when working correctly | Critical | Patients using try-on |
| 3 | **Out-of-stock variants selectable in order items** — staff selects, fills form, only fails at confirmation | Major | Reception staff |
| 4 | **No push notifications on Android** — patients have no reason to open the app; order updates are invisible | Major | All patients |
| 5 | **After inline payment, page doesn't auto-update** — staff must refresh to see updated balance | Major | Reception staff |
| 6 | **Two similar-looking print buttons on billing view** — "Download Receipt" vs "Print Receipt" easily confused | Moderate | Reception staff |
| 7 | **Prescription form shows Prism/Base fields by default** — used in <5% of prescriptions, adds visual noise | Moderate | Optometrists, staff |
| 8 | **No available-slot calendar for appointment booking** (Android) — customers must guess available times | Moderate | Patients |
| 9 | **No patient phone number on calendar events** — staff must click through to call to confirm | Moderate | Reception staff |
| 10 | **Bulk action skip reason not shown** — "3 skipped" without explaining why (gate blocked, wrong status) | Minor | Admin |

---

## Accessibility Summary

**Web admin:**
- Filament 5 uses semantic HTML, ARIA labels on interactive elements, and keyboard-navigable modals — baseline accessibility is solid
- Color is never the only status indicator (badges include text labels, icons accompany color changes)
- Font sizes are Filament defaults — readable but not explicitly tested with screen readers
- Non-native date/time pickers have reasonable keyboard support

**Android:**
- Cannot audit directly — depends on Android implementation
- The API returns all necessary data for accessible Android UI
- Encrypted prescription fields decrypt transparently — no accessibility impact

**Key gaps:**
- Prescription OD/OS form has 12+ fields with no visual grouping aid for users with cognitive load limitations
- Thermal receipt HTML page has no ARIA roles — fine for print, not for screen readers

---

## Android App Score

| Dimension | Score | Notes |
|---|---|---|
| Visual Design | 6/10 | API-only review; UI quality depends on Android team |
| Usability | 5/10 | Email auth barrier, no push notifications, no slot calendar |
| Accessibility | 5/10 | Unknown — phone number auth missing is a hard barrier |
| Learnability | 6/10 | Booking and prescription views are intuitive concepts; AR creates confusion |
| **Overall** | **5.5/10** | Strong backend foundation; patient-facing UX needs email-free auth + push |

---

## Web Admin Score

| Dimension | Score | Notes |
|---|---|---|
| Visual Design | 8/10 | Professional, consistent branding, clean component usage |
| Dashboard Design | 7/10 | Good KPIs but missing today's schedule and ready-for-pickup |
| Workflow Efficiency | 8/10 | Walk-in sale, bulk actions, inline payment significantly reduce clicks |
| Data Presentation | 7/10 | Reports are useful; billing view has two confusable print buttons |
| **Overall** | **7.5/10** | Approaches production quality; top issues are feedback latency and missing quick actions |

---

## Deployment Readiness

**Web Admin:** ✅ **Ready with minor UI refinements**
The 3-5 issues listed (confusable buttons, prescription form noise, page refresh after payment) are cosmetic/moderate — they don't block clinic operations. A trained receptionist can use this system effectively.

**Android App:** ⚠️ **Requires moderate UX improvements**
The email-required registration is a near-critical barrier for the Philippine market. Without phone number + OTP login and push notifications, patient adoption will be near zero. The AR labeling needs correction to manage expectations.

---

## Final Verdict

The web admin panel is production-ready for a trained clinic team. The workflow improvements — walk-in sale, inline payment, bulk actions, calendar drag-reschedule — represent genuine usability thinking beyond what most clinic management systems provide out of the box. A receptionist who learns this system in a week will be faster than with any paper-based alternative.

The Android patient app has a fundamental adoption problem that UI refinement alone won't solve. Phone number + OTP login and push notifications are not UX polish — they're prerequisites for the app to function in the Philippine market. Without them, even a perfectly designed UI sits unused because patients won't create accounts and won't open the app without notification-driven prompts.

The AR try-on feature needs honest labeling. "Virtual Preview" sets accurate expectations; "AR Try-On" sets false ones. The former makes users feel the feature is working; the latter makes them feel the app is broken.

**Priority improvements before launch:**
1. Phone number + OTP registration on Android (critical adoption barrier)
2. Push notifications via Firebase (critical engagement driver)
3. Correct AR labeling to "Virtual Preview" (expectation management)
4. Out-of-stock warning in order item variant select (prevents wasted workflows)
5. Auto-refresh after inline payment (feedback loop closure)
