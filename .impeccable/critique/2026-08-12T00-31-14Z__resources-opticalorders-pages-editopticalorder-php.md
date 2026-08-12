---
target: the optical order edit page
total_score: 26
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 2
timestamp: 2026-08-12T00-31-14Z
slug: resources-opticalorders-pages-editopticalorder-php
---
Method: dual-agent (A: general-purpose design review · B: general-purpose detector/browser evidence)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Status badge/timeline are good, but the `Cancel` success toast says "Order cancelled" even when a manual refund is now owed — the system knows more than it tells the user. |
| 2 | Match System / Real World | 3 | Strong domain vocabulary (OD/OS/PD, "Ready for Pickup") but raw back-office terms (`supplier_invoice_number`, "admin override") go untranslated for a less-tech-fluent staff audience. |
| 3 | User Control and Freedom | 3 | Every consequential action sits behind a confirmation modal, but there's no undo for cancel/dispense — defensible for audit reasons, just never explained as such. |
| 4 | Consistency and Standards | 2 | Clinical Blue (`primary`) doubles as the `InProgress` status color, directly against DESIGN.md's own "One Accent Rule"; `info` (unthemed Tailwind blue) creates a second near-identical blue; `Start` is colored `warning` for a routine forward step. |
| 5 | Error Prevention | 2 | `Mark Ready` and `Dispense` stay clickable even when the backend will certainly reject them (unverified spec, missing billing record) — unlike `Start`, which correctly mirrors its backend gate. |
| 6 | Recognition Rather Than Recall | 3 | Sidebar surfaces Linked Records, Payment, Timeline, and Approved/Verified-by right on the page — staff don't need to hunt other screens. |
| 7 | Flexibility and Efficiency of Use | 2 | `supplier_invoice_number` sensibly pre-fills in Mark Ready, but every single status transition forces a full modal round-trip with no lighter path for high-frequency use. |
| 8 | Aesthetic and Minimalist Design | 3 | Clean 2/3+1/3 split, consistent section-per-concern structure; the ~16-field Eyewear Specification section is visually flat despite its size. |
| 9 | Help Recognize/Diagnose/Recover from Errors | 3 | Domain error messages are specific and human, but several error keys don't map to a visible field, so they land only as a toast the user must mentally translate. |
| 10 | Help and Documentation | 2 | Almost no inline help for PD/segment-height/fitting-height abbreviations, aside from a good `admin_override` helper text. |
| **Total** | | **26/40** | **Acceptable** |

Deterministic CLI scan (`detect.mjs`) found zero rule hits against `app/Filament/Resources/OpticalOrders/` — clean. This is expected: the issues below are business-logic and design-system-conformance problems a generic static scanner isn't built to catch, not evidence the page is flawless.

## Design Specificity Verdict

**LLM assessment**: This is not a generic "Order" CRUD screen in a clinic skin. The domain modeling is real: OD/OS/PD (binocular vs. monocular), fitting height, segment height, lens design/material/refractive-index snapshots, frame source, supplier-invoice tracking gated on external-supplier status, and a genuine two-step optometrist-approve → staff-verify QA gate before a corrective job can proceed. Status labels are translated into patient-facing language rather than leaking enum values, and the admin-only balance-override sub-form encodes a real accountability structure. That specificity is undercut by execution seams: the highest-stakes action (Cancel) gets the least bespoke treatment of any header action, the color system quietly violates its own documented rule twice, and the page leans entirely on stock Filament components with no extra authorial effort beyond labels/enums — competent, but "authored" mainly in its data model, not its interaction design.

**Deterministic scan**: Clean (0 findings, exit 0) on the OpticalOrders resource files. No false positives to reconcile since nothing fired.

**Visual overlays**: Not available. No browser automation tool (Chrome DevTools MCP, Playwright, Puppeteer) is exposed in this environment, and `WebFetch` can't hold an authenticated Livewire session or inject scripts — both assessments independently confirmed this and reported the fallback rather than fabricating screenshots or overlay findings. This critique is source-grounded only; live rendering, focus order, and actual contrast/spacing were not visually verified.

## Overall Impression

The page's data model and business rules are genuinely clinic-specific and mostly well thought through (graceful hiding of the eyewear section for non-corrective orders, a correctly gated balance-override flow). What's missing is the same rigor carried into the UI layer: several header actions promise things the backend will refuse, the one documented color rule in this codebase's design system is violated on this exact page, and the highest-stakes action in the whole page (cancelling an order with money already on it) drops a signal the domain layer explicitly computed for it. The single biggest opportunity: make the header actions honest about when they'll succeed (mirror `Start`'s pattern everywhere), and surface the "refund still owed" state that already exists in the code but never reaches the screen.

## What's Working

1. **Non-corrective orders degrade cleanly.** The entire Eyewear Specification section is conditioned on `$record->eyewearSpecification !== null` — a sunglasses-only order simply doesn't render 16 irrelevant fields. Deliberate, not accidental.
2. **The admin balance-override flow is well-built defensive UX.** Gated on both `isAdmin()` and an actual outstanding balance, with required sub-fields (reason, due date) that only appear once toggled — correct progressive disclosure for a genuinely sensitive exception path.
3. **Status is consistently color + label**, never color alone, applied the same way on this page and the list table — the one DESIGN.md accessibility rule that's followed everywhere status appears.

## Priority Issues

**[P1] Cancel silently drops the "refund still owed" signal**
- **Why it matters**: `CancelOpticalOrder::handle()` returns `has_posted_payments` specifically so the caller can react, but `EditOpticalOrder`'s cancel callback discards it and shows the same generic "Order cancelled" success toast regardless. In a financial handoff tool, this is precisely the moment staff need reassurance or a warning — instead it produces false confidence, and a cancelled order with money on it becomes invisible until someone stumbles onto the billing record.
- **Fix**: Check `has_posted_payments` in the action callback; when true, show a `->warning()` notification stating a refund/payment reversal must be handled manually, ideally linking to the billing record.
- **Suggested command**: `/impeccable harden`

**[P1] Mark Ready and Dispense stay clickable when the backend will reject them, unlike Start**
- **Why it matters**: `start`'s `->visible()` correctly re-checks the same rule `UpdateJobOrderStatus` enforces (spec approved). `markReady` omits the equivalent check against `isVerified()` even though the backend will reject it — so staff fill out an entire modal (typing a supplier invoice number) before a late rejection. Worse, the supplier-invoice update commits *before* the status transition, so a rejected "Mark Ready" still silently persists that field change. `Dispense` has the same gap: visible purely on status, with no check for billing-record presence.
- **Fix**: Mirror `start`'s pattern on `markReady` and `dispense` — add the missing checks to `->visible()` (or disable with a tooltip explaining why), and wrap the invoice update and status transition in one DB transaction so a rejected transition can't leave a partial write behind.
- **Suggested command**: `/impeccable harden`

**[P2] Color system violates its own "One Accent Rule" and mixes in a second unthemed blue**
- **Why it matters**: DESIGN.md is explicit that Clinical Blue "never doubles as a status color," yet `InProgress` renders with `->color('primary')` on this page and the list table. Separately, `verifyEyewear`, `markReady`, and the `fulfillment_mode` badge use `->color('info')`, which resolves to Filament's default unthemed blue — a second near-identical blue with no relationship to the brand accent. `start` uses `->color('warning')` for a routine forward step, reading as "caution" when nothing risky is happening.
- **Fix**: Give `InProgress` a non-primary color, theme or replace the `info` usages, and recolor `start` to something progression-neutral.
- **Suggested command**: `/impeccable polish`

**[P2] Destructive Cancel sits undifferentiated next to same-weight positive actions**
- **Why it matters**: In the `InProgress` state, `Verify Eyewear`, `Mark Ready`, and `Cancel` render as same-size adjacent buttons with no grouping or divider. For staff moving quickly through dozens of orders a day, a red Cancel between two blue progression buttons is a real mis-click risk — and it's the only header action without a custom `modalHeading()`, making its confirmation feel less deliberately built than its siblings.
- **Fix**: Move `Cancel` into a secondary/grouped position (e.g. an `ActionGroup` "More actions" menu) or visually separate it, and give it an explicit `modalHeading()` for consistency.
- **Suggested command**: `/impeccable layout`

**[P2] Layout collapses to single-column exactly on the hardware this app targets**
- **Why it matters**: `Grid::make(3)` only applies 3 columns at the `lg` (1024px) breakpoint. `AdminPanelProvider`'s own code comment notes the full sidebar runs taller than a 1366×768 front-desk laptop — the exact viewport class where usable content width can plausibly dip under 1024px with the nav expanded, dropping the sidebar (Balance Due, Linked Records, Timeline) below a potentially long Eyewear Specification section right when staff most need the balance figure visible.
- **Fix**: Verify actual behavior at 1024–1280px on real front-desk hardware; consider a `md`-level fallback that keeps the sidebar alongside content sooner.
- **Suggested command**: `/impeccable adapt`

## Persona Red Flags

**Alex (Power User, dozens of orders/day)**: Hits the Mark Ready failure bug directly — repeated wasted round-trips on a routine, high-frequency action. Every transition, including the low-risk Start, requires a confirmation modal with no faster path. No default payment method pre-selected in the Dispense modal despite cash almost certainly being the dominant method — a small repeated-click tax at high frequency.

**Sam (Accessibility-Dependent User)**: The 12 disabled `TextInput`/`Select` fields shown for a *verified* Eyewear Specification are read-only form controls, not `Placeholder`/`TextEntry` content — disabled inputs are frequently skipped or announced differently by assistive tech than static text, unlike the `Placeholder`-based rendering already used elsewhere on the same page (Order Details, Payment, Timeline). Sam reviewing a verified order risks missing that lens/PD data exists at all. Status *is* correctly color+text, so no color-only violation was found. Focus order, modal focus-trapping, and live-region announcements could not be verified without a live browser — flagged as unverified, not passed.

**Riley (Deliberate Stress Tester)**: $0 balance, long product descriptions, and no-eyewear-spec orders are all handled well (override toggle disappears, description wraps, section hides cleanly). But "no billing record yet at Ready-for-Dispensing" hits the same Dispense gap as the P1 above — the button stays live and fails only after a full modal fill. No character counter on 1000-char-capped notes fields, so a long note truncates silently with no live feedback.

## Minor Observations

- `Cancel` is the only header action without a `modalHeading()` — inconsistent with the other five.
- `override_due_date` uses `minDate(today())` with no upper bound — not necessarily wrong, just unconstrained.
- The "Notes" section is a single `Textarea` in its own `Section`; could plausibly live inside "Order Details" without losing clarity.

## Questions to Consider

- If none of the six header actions use the brand's own primary color, is there actually a "primary CTA" on this page — or does every state present 2–3 equally-weighted buttons with no visual nomination of the recommended next step?
- `CancelOpticalOrder` already computes whether a refund is now the staff's responsibility — was that value always meant to reach the UI and just never wired up, or was discarding it an intentional scope cut? Is a cancelled-with-money-owed order discoverable anywhere else in the panel today?
- Given `Start` already re-implements its backend gate in `->visible()`, was `Mark Ready`'s missing equivalent an oversight, or an unstated assumption that verification isn't actually required before Ready?
