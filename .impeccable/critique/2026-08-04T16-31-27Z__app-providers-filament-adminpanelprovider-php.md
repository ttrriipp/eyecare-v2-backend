---
target: the sidebar navigation
total_score: 16
max_score: 36
na_heuristics: 9
p0_count: 1
p1_count: 3
timestamp: 2026-08-04T16-31-27Z
slug: app-providers-filament-adminpanelprovider-php
---
Method: dual-agent (A: design review · B: detector + rendered-DOM evidence), both run as isolated sub-agents.

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2 | Current location is shown visually, but zero `getNavigationBadge()` exists anywhere — pending appointment requests, unread conversations, and ready-for-pickup orders are invisible until clicked. |
| 2 | Match System / Real World | 2 | Leaf labels are strong ("Billing & Payments", "Inventory History"), but group headings actively lie: "Patients & Clinical" contains neither, "Schedule" contains only Availability. |
| 3 | User Control and Freedom | 2 | Groups collapse and persist in localStorage with no "expand all" and no desktop collapse toggle — a mis-click can hide Optical indefinitely. |
| 4 | Consistency and Standards | 1 | An undeclared group, 6 ungrouped resources, one icon shared by 3 resources, 1 solid icon among 20 outlined, and sort scales spanning 1–41. |
| 5 | Error Prevention | 3 | `canViewAny()` gating is applied identically across all 6 admin-only resources; staff never see a door they can't open. |
| 6 | Recognition Rather Than Recall | 2 | All 25 items carry text labels (real win), but 6 sit under no heading at all and global search covers only 3 of 21 resources. |
| 7 | Flexibility and Efficiency | 1 | No pinning, no `navigationItems()` shortcuts, and an identical sidebar for optometrist and receptionist despite different jobs. |
| 8 | Aesthetic and Minimalist Design | 2 | Chrome is clean and on-brand; content is 11 top-level blocks, 5 of which hold a single item. |
| 9 | Error Recovery | n/a | The sidebar renders no error states; gated resources are hidden rather than errored. |
| 10 | Help and Documentation | 1 | No group descriptions or tooltips; "Encounters", "Link Requests", "Inventory History" go unexplained to a new receptionist. |
| **Total** | | **16/36** | **Poor (44%) — the IA layer needs an overhaul; the visual layer does not** |

Heuristic 9 scored `n/a` (no error states exist in this surface), so the applicable maximum is 36, not 40.

## Design Specificity Verdict

**LLM assessment: interchangeable.** The brand layer is specific and genuinely good — the biconvex-lens-as-eye mark in `logo.blade.php:13-23` is a designer's decision, brand-blue in both modes, `aria-hidden` with a real text wordmark beside it. The *navigation model* is not. "Catalog & Inventory / Finance / Communication / Reports / Administration / Settings" is stock SaaS taxonomy that would fit a hardware store unchanged. Nothing in the sidebar encodes the clinic's actual loop — walk-in → exam → Rx → quotation → order → dispense → pay — and that loop is currently scattered across five separate blocks in non-workflow order.

Icons confirm it. `OutlinedShoppingBag` serves three unrelated resources (`FrameReservationResource.php:19`, `OpticalOrderResource.php:22`, `ProductResource.php:24`). `OutlinedClipboardDocumentList` serves both Prescriptions (`:23`) and Audit Logs (`AuditLogResource.php:21`). A Philippine clinic bills in pesos while `BillingRecordResource.php:21` uses `OutlinedCurrencyDollar`. And the one authentically optical glyph — `OutlinedEye` — is spent on Lens Categories, admin-only, near the bottom.

**Deterministic scan: coverage unavailable, not clean.** The detector exited 0 with `[]` on `resources/views` — but it enumerated **zero files**. Its `SCANNABLE_EXTENSIONS` set (`detector/node/file-system.mjs:26-30`) omits `.php`/`.blade.php`, and all 18 files under `resources/views` are Blade. Positive controls confirmed the detector works (it genuinely read and passed `theme.css`, and a `.html` copy of the logo scanned clean). Do not read this as sidebar validation — the sidebar is rendered by vendor Filament templates and the detector never inspected a single nav file.

**Visual overlays: none.** No browser automation tool is exposed in this session, so no overlay was injected and no screenshots were captured. The app *is* serving (`http://localhost/admin/login` → 200), but `WebFetch` rejects localhost after upgrading to HTTPS, and Filament v5's Livewire login refuses a plain form POST (405). As a substitute, the authenticated dashboard was rendered server-side through Laravel's HTTP kernel (132KB of real DOM), which yields markup but not pixels — **collapse behavior at 900px remains unverified.**

## Overall Impression

The chrome is production-grade and the restraint is correct. The information architecture underneath it was never authored — it accumulated. Twenty-five items land in eleven top-level blocks, five of which hold exactly one item, while the three most-used records in the entire clinic (Patient Records, Encounters, Prescriptions) sit in a **nameless block at the top** next to patient-app telemetry like Frame Ratings.

The single biggest opportunity: the group taxonomy already exists and is nearly right — it just isn't wired up. Four one-line `$navigationGroup` additions would collapse 11 blocks to 8 and make every heading truthful. This is a cheap fix with a disproportionate payoff.

## What's Working

1. **`canViewAny()` gating is genuinely well-executed.** Six resources use one identical pattern (`AuditLogResource.php:25-28`, `UserResource.php:31-34`, `BrandResource.php:29-32`, `LensCategoryResource.php:29-32`, `ProductCategoryResource.php:29-32`, `SmsNotificationResource.php:27-30`). For a less tech-fluent audience this is exactly right: it removes 7 items and 3 entire groups from a receptionist's mental model for free, and nobody ever clicks into a denial.

2. **The brand lockup earns its space.** `logo.blade.php:13-23` is the strongest specificity signal in the panel and the one place where craft is unmistakable.

3. **Leaf labels show real editorial instinct.** "Inventory History" instead of "Inventory Movements", "Billing & Payments" instead of "Billing Records", "Patient Records" instead of "Patients". Someone was thinking about the receptionist. That instinct simply never reached the group headings.

## Priority Issues

### [P0] Six resources have no group and land in an unlabeled block above everything

`PatientResource.php:27-33`, `EncounterResource.php:23-31`, `PrescriptionResource.php:25-27`, `AppointmentResource.php:24-28`, `FrameReservationResource.php:21-27`, `FrameRatingResource.php:21-27` all omit `$navigationGroup`. Filament sorts a blank-label group to `-1` — first, with no heading.

**Why it matters:** The clinic's three most-used records sit in a nameless list beside patient-app telemetry, while the two groups that *should* hold them are near-empty. "Patients & Clinical" contains only Appointment Requests — a scheduling object. "Schedule" contains only Availability. A receptionist learns "Patient Records is the 6th unlabeled thing" positionally rather than semantically, which is exactly the wrong learning model for this audience. Worse, once she reads two headings that don't describe their contents, she stops trusting headings at all.

**Fix:** Add `$navigationGroup = 'Schedule'` to Appointments; `'Patients & Clinical'` to Patients, Encounters, Prescriptions; move Appointment Requests to `'Schedule'`; move Frame Reservations and Frame Ratings to `'Optical'`. Leave only Dashboard ungrouped. 11 blocks → 8, every heading truthful.

**Suggested command:** `/impeccable shape`

### [P1] `Accounts & Access` is undeclared and renders dead last

Set in `UserResource.php:29`, `PatientAccountResource.php:27`, `PatientLinkRequestResource.php:23`, but missing from `navigationGroups()` at `AdminPanelProvider.php:54-64`. Confirmed at runtime: 9 groups declared, **10 labeled groups render**. Filament pushes undeclared groups past every declared one.

**Why it matters:** On a 1366×768 front-desk laptop the sidebar is roughly 1,280px tall, so Settings and Accounts & Access are permanently below the fold — with no desktop collapse and no scroll affordance. Staff Accounts and Link Requests are onboarding tasks that should be findable, not hunted.

**Fix:** Add `'Accounts & Access'` between `'Communication'` and `'Reports'` in the `navigationGroups()` array. One line.

**Suggested command:** `/impeccable layout`

### [P1] Zero navigation badges — the sidebar reports nothing

No `getNavigationBadge()` exists anywhere in `app/Filament/`.

**Why it matters:** The sidebar is the only always-visible surface in the panel, and it carries no signal. Pending appointment requests, unread conversations, pending patient link requests, and orders ready for pickup are all silent. `databaseNotifications()` provides a bell but not per-area counts. This is the reassurance gap: a receptionist has no idea three patients are waiting on link requests because nothing ever tells her a number.

**Fix:** Add `getNavigationBadge()` + `getNavigationBadgeColor()` to AppointmentRequestResource (pending), ConversationResource (unread), PatientLinkRequestResource (pending), OpticalOrderResource (ready for pickup). Use `warning`/`danger` — brand blue is reserved for active-nav per the design system, so badges must not use it.

**Suggested command:** `/impeccable harden`

### [P1] Accessibility gaps that inheriting Filament does *not* protect you from

This is where the two assessments **contradicted each other**, and the evidence side won. Assessment A praised the zero-custom-CSS restraint on the theory that Filament's tested focus rings and keyboard order survive intact. The rendered-DOM census disproves the premise:

- **`aria-current` occurrences: 0.** Across all of `resources/views/` and the full 132KB authenticated DOM. Active state is CSS-class-only (`'fi-active' => $active`, `components/sidebar/item.blade.php:28`). A screen-reader user cannot tell which page they are on.
- **Both `<nav>` landmarks are unlabeled.** `fi-sidebar-nav` and `fi-topbar` carry no `aria-label`, so they are indistinguishable when navigating by landmark.
- **Group headers are not keyboard-operable.** `components/sidebar/group.blade.php:29-40` renders a `<div class="fi-sidebar-group-btn">` with `x-on:click` and no `role`, no `tabindex`. Only the chevron icon-button carries `aria-expanded` (`:55`).
- **No skip-to-content link** anywhere in views or lang files.

**Why it matters:** The stated baseline is WCAG 2.1 AA. `aria-current` on the active nav item is table stakes for that, and it is simply absent in Filament v5.6.6 (pinned in `composer.lock`). "We left it stock so it's accessible" is not true here — stock is the gap.

**Fix:** These are vendor-template gaps, so fix them at the panel level rather than by forking views: add `aria-label` to the nav landmarks and an `aria-current="page"` binding on active items via a `renderHook` or a published view override, plus a skip-to-content link. Verify with a real screen reader afterward, not by inspection.

**Suggested command:** `/impeccable audit`

### [P2] Icon collisions defeat pre-attentive scanning, and search covers 3 of 21 resources

`OutlinedShoppingBag` × 3 (`FrameReservationResource.php:19`, `OpticalOrderResource.php:22`, `ProductResource.php:24`); `OutlinedClipboardDocumentList` × 2 (`PrescriptionResource.php:23`, `AuditLogResource.php:21`); solid `ShoppingCart` (`ReorderReport.php:17`) among 20 outlined. Meanwhile `globalSearchResourceOptIn()` is on (`AdminPanelProvider.php:44`) with only Patients, Products, and Appointments opted in.

**Why it matters:** A less tech-fluent receptionist navigates by icon shape before reading; three identical bags force a full text read every time. And search — the standard escape hatch from a deep sidebar — cannot find an order number, quotation number, or encounter number, despite every one of those resources declaring a `$recordTitleAttribute`. A patient at the counter quoting their order number cannot be looked up.

**Fix:** One icon per concept (Optical Orders → `OutlinedRectangleStack`, Frame Reservations → `OutlinedBookmark`, Products keeps the bag, Reorder Report → outlined cart); consider spending `OutlinedEye` on Prescriptions rather than Lens Categories. Opt OpticalOrder, Quotation, BillingRecord, and Encounter into global search (gate Prescription carefully — clinical data).

**Suggested command:** `/impeccable polish`

## Persona Red Flags

**Alex (Power User):** No pinning, no `navigationItems()` shortcuts, and an identical sidebar for every role. Their 20-times-a-day path — Appointments → Encounters → Prescriptions → Quotations → Optical Orders → Billing — crosses **four** blocks in non-workflow order. Global search would be the bypass, but it misses `job_order_number` (`OpticalOrderResource.php:28`) and `encounter_number` (`EncounterResource.php:29`). The `is_optometrist` flag gates encounter and prescription *actions* but changes nothing in the sidebar, so the one role that could benefit from tailored nav gets none.

**Sam (Accessibility-Dependent):** Tabs into the sidebar and hits **7 links under no heading at all** — the unlabeled group emits no landmark or label, so the panel's most important items have zero announced context. 25 tab stops with no skip-to-content. No `aria-current`, so "where am I" is unanswerable. Group headers can't be collapsed by keyboard except via the chevron. No desktop collapse means a 200%-zoom user gets a fixed-width sidebar eating most of the viewport with no dismiss.

**Rosa, 58, clinic owner/receptionist (project-specific):** Trained once, reads a heading then its contents. "Schedule" holds only "Availability" — not appointments, which is what she wanted — and she has no reason to look in the headingless block above it. "Patients & Clinical" holds "Appointment Requests", so she concludes patient records live elsewhere and stops trusting headings entirely. If she accidentally collapses "Optical" the state persists with no visible cue and no expand-all. On the front-desk laptop, Settings and Accounts & Access are below the fold. And she never learns that three patients are waiting on link requests, because nothing in the sidebar ever shows a number.

## Minor Observations

- `OpticalOrdersCluster.php` is dead code — never discovered (`discoverClusters()` is not called), never referenced by any `$cluster` property.
- `shouldRegisterNavigation(): return true` in `AppointmentRequestResource.php:29-32` and `BillingRecordResource.php:29-32` are no-op overrides. Delete or implement.
- Settings' three items have no `$navigationSort` at all, so all coerce to `-1`, yielding the semantically arbitrary "Brands, Lens Categories, Categories".
- `AuditLogResource` sets `$navigationSort = 41` while being the sole member of "Administration" — the value does nothing.
- `OutlinedCalendar` (Appointments) and `OutlinedCalendarDays` (Appointments Report) are near-indistinguishable at 20px.
- Sort scales are incoherent *across* groups (1–5, 20/21, 30/35, 41) but happen to be internally consistent *within* each group — the least of the structural problems.
- `docs/ui-ux-audit.md` audits 10 screens and **never covers the sidebar or global IA**; its only nav-adjacent note is a sidebar-badge suggestion at `:253`, which this critique confirms and escalates to P1.

## Questions to Consider

1. If you deleted `navigationGroups()` entirely and let Filament auto-group, would any staff member notice? Five single-item groups suggest the groups aren't currently doing work.
2. The clinic's real object is a **visit**, not 25 tables. What would a six-item sidebar — Today / Patients / Clinical / Optical / Money / Setup — cost you, and what would it buy Rosa?
3. A patient is standing at the desk. Which is faster: your sidebar, or Ctrl+K? Today the answer is neither, because search covers 14% of resources. Which one are you actually investing in?
4. The one thing you added to Filament's chrome is a beautiful eye logo. The one thing you didn't add is any indication that something needs attention. For a clinic running under time pressure, is that the right trade?
