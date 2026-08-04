---
name: Eyecare — Padilla Optical Clinic Admin
description: A calm, high-contrast Filament 5 admin panel for clinic staff, lightly branded in clinical blue.
colors:
  clinical-blue: "#4F8DD7"
  surface: "#FFFFFF"
  surface-dark: "#0F172A"
  ink: "#0F172A"
  ink-dark: "#F1F5F9"
  muted: "#64748B"
  border: "#E2E8F0"
  border-dark: "rgb(255 255 255 / 0.1)"
  status-success: "#16A34A"
  status-success-bg: "#F0FDF4"
  status-warning: "#D97706"
  status-warning-bg: "#FFFBEB"
  status-danger: "#DC2626"
  status-danger-bg: "#FEF2F2"
  status-neutral: "#4B5563"
  status-neutral-bg: "#F3F4F6"
typography:
  body:
    fontFamily: "'Instrument Sans', ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "'Instrument Sans', ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: 1.4
  heading:
    fontFamily: "'Instrument Sans', ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: 1.3
rounded:
  sm: "0.375rem"
  md: "0.5rem"
  lg: "0.75rem"
spacing:
  sm: "0.5rem"
  md: "0.75rem"
  lg: "1rem"
components:
  button-primary:
    backgroundColor: "{colors.clinical-blue}"
    textColor: "#FFFFFF"
    rounded: "{rounded.md}"
    padding: "0.5rem 1rem"
  badge-strength-strong:
    backgroundColor: "{colors.status-success-bg}"
    textColor: "{colors.status-success}"
    rounded: "{rounded.sm}"
    padding: "0.125rem 0.5rem"
  badge-strength-moderate:
    backgroundColor: "{colors.status-warning-bg}"
    textColor: "{colors.status-warning}"
    rounded: "{rounded.sm}"
    padding: "0.125rem 0.5rem"
  badge-strength-weak:
    backgroundColor: "{colors.status-neutral-bg}"
    textColor: "{colors.status-neutral}"
    rounded: "{rounded.sm}"
    padding: "0.125rem 0.5rem"
  card-match-row:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.lg}"
    padding: "0.75rem"
---

# Design System: Eyecare — Padilla Optical Clinic Admin

## Overview

**Creative North Star: "The Calm Clinical Blue"**

This is Filament 5's own design language, deliberately left mostly stock, with one confident brand decision layered on top: a single blue (`#4F8DD7`) that shows up in the sidebar mark, primary buttons, links, and focus states, against Filament's default Slate neutrals. There is no bespoke component system underneath it — the honest description of this panel is "Filament defaults plus a calm, trustworthy blue," not a from-scratch design language. That restraint is deliberate: staff already know how Filament panels behave, and unlearning nothing is a feature for a daily-use clinic tool.

Where this project does go further than stock Filament is in a handful of hand-built informational patterns introduced for identity/duplicate matching (Create Patient, Create Appointment, Patient Link Requests, Appointment Requests) — bordered result cards and match-strength badges rendered as raw HTML inside `Placeholder` components. These are the system's signature components and should be reused, not reinvented, anywhere a similar "is this the same person" judgment call needs surfacing to staff.

The audience skews toward less tech-fluent clinic staff and owners, so the tiebreak always favors clarity and confidence over density: an action's result should be obvious at a glance (a real "Approved" state, a badge that unmistakably reads as a warning), not just technically present in small, low-contrast text.

**Key Characteristics:**
- Flat, bordered surfaces — Filament's default `fi-section` card, not custom elevation.
- One accent color used deliberately sparingly: primary buttons, links, and the logo mark, never as a wash across a whole screen.
- Status is always color **plus** an icon or label text — never color alone.
- Warm and reassuring over minimal: legible sizes, generous padding, and unambiguous confirmation states, even where a denser admin theme would compress them.

## Colors

A single brand accent against Filament's default Slate neutral scale, plus the four standard semantic status colors used consistently for match-strength, badges, and record status across the panel.

### Primary
- **Clinical Blue** (`#4F8DD7`): The one accent color in the system. Used for the logo mark, primary action buttons, links inside custom HTML (duplicate/candidate cards), and Filament's own primary-color-driven states (focus rings, active nav item, checked toggles). Confirmed in `AdminPanelProvider::panel()->colors(['primary' => Color::hex('#4F8DD7')])`.

### Neutral
- **Surface** (`#FFFFFF` light / `#0F172A` dark): Card and page background.
- **Ink** (`#0F172A` light / `#F1F5F9` dark): Primary text.
- **Muted** (`#64748B`): Secondary text — meta lines, timestamps, helper text.
- **Border** (`#E2E8F0` light / `rgb(255 255 255 / 0.1)` dark): Card borders, dividers between list rows. Filament's `gray` palette is set to `Color::Slate`; these are Slate's light/dark edge values.

### Status (used for badges, match-strength, and record state)
- **Success** (text `#16A34A` on bg `#F0FDF4`): Strong candidate matches, "Approved"/"Paid"/"Linked" states.
- **Warning** (text `#D97706` on bg `#FFFBEB`): Moderate candidate matches, "Pending"/duplicate-found banners.
- **Danger** (text `#DC2626` on bg `#FEF2F2`): Rejections, voided records, overdue balances.
- **Neutral** (text `#4B5563` on bg `#F3F4F6`): Weak candidate matches, inactive/cancelled states.

### Named Rules
**The One Accent Rule.** Clinical Blue is the only color used to mean "act on this" (buttons, links). It never doubles as a status color — status always comes from the success/warning/danger/neutral set, so "this needs action" and "this is a warning" are never visually confusable.

## Typography

**Body/Display Font:** Instrument Sans (with `ui-sans-serif, system-ui, sans-serif` fallback)

**Character:** A single humanist sans used at every weight the panel needs (400/500/600) — there is no separate display face. Legibility and a slightly warmer, less mechanical feel than Filament's default Inter stack are the whole point of the swap (confirmed in `resources/css/filament/admin/theme.css`).

### Hierarchy
- **Heading** (600, 1.125rem, 1.3 line-height): Section titles, page titles, modal headings.
- **Body** (400, 0.875rem, 1.5 line-height): Form labels' content, table cells, placeholder text, card body copy.
- **Label** (500, 0.75rem, 1.4 line-height): Form field labels, badge text, small meta lines (e.g. "PAT-2026-000001 · DOB Jan 1, 1990").

### Named Rules
**The No-Display-Face Rule.** Don't introduce a second typeface or an oversized display size for emphasis. Emphasis comes from weight (600) and color (status/accent), not scale — this is a working tool, not an editorial page.

## Layout

Standard Filament resource layout: a 3-column grid on wide forms (2/3 main content, 1/3 sidebar of contextual `Section`s — Timeline, Linked Records, Clinical Assignment, etc.), collapsing to a single column on narrower viewports via Filament's own responsive grid classes. Tables are full-width with Filament's default row density and toolbar. No custom breakpoints or container widths have been introduced; layout rhythm is whatever Filament's grid/section spacing provides by default.

## Elevation & Depth

Mostly flat. Filament 5 sections render as a bordered surface with a light `shadow-sm` at rest — there is no custom shadow scale, no hover-lift, and no layered/lifted material system in this project. Depth communicates through borders and background/surface contrast (e.g. the warning-tinted duplicate-match card against the white page), not through shadow intensity.

### Named Rules
**The Border-Over-Shadow Rule.** When a custom component needs to separate from its surroundings (a match card, a banner), reach for a border and a tinted background first; add shadow only if Filament's own default section shadow doesn't already provide enough separation.

## Shapes

Two radius steps, borrowed from Filament's defaults and reused in every custom component built this session: **cards/sections** use a larger, calmer corner (`rounded-lg`, 0.75rem — e.g. the duplicate-match card, the strength-badge list container), and **badges/pills/small controls** use a tighter corner (`rounded-md`, 0.375–0.5rem). No sharp corners and no fully-pill (`rounded-full`) shapes are used except the small circular icon chip inside a match-card row.

## Components

### Buttons
- **Shape:** `rounded-md` (0.5rem), Filament default button shape.
- **Primary:** Clinical Blue background, white text, `0.5rem 1rem` padding — Filament's own primary button styling, untouched.
- **Hover / Focus:** Filament's default darken-on-hover and focus-ring behavior; not customized.
- **Secondary / Ghost / Danger:** Filament defaults (gray and red respectively); not customized.

### Badges (match-strength)
- **Style:** Small rounded-`sm` pill, semantic background/text pairing (success/warning/neutral — see Colors), `Str::headline()`-cased label ("Strong", "Moderate", "Weak").
- **State:** Static/informational only — not interactive, not selectable.
- **Usage:** Appears on every ranked-candidate list (Create Patient/Appointment duplicate checks, Appointment Request and Patient Link Request candidate matches). Always paired with the matched person's name and a link to their record — never shown as a bare badge with no identifying context.

### Cards / Containers
- **Corner Style:** `rounded-lg` (0.75rem).
- **Background:** White (light) / slate-900 (dark) for the default Filament section; a tinted warning background (`#FFFBEB` light) for the duplicate-match summary card specifically, to read as "needs a look" before the user reads any text.
- **Shadow Strategy:** Filament's default `shadow-sm`, not amplified.
- **Border:** 1px, Slate-200 (light) / `white/10` (dark); warning-tinted border on the duplicate-match card.
- **Internal Padding:** `0.75rem` per row.

### Signature Component: Duplicate/Candidate Match Card
The panel's one genuinely custom pattern (`App\Filament\Support\PatientDuplicateMatchCard`, plus inline equivalents on the Patient Link Request and Appointment Request pages). A bordered card, warning-tinted header ("Possible existing records found") when matches exist, then one row per match: a small circular icon chip, the person's name (bold), a muted meta line (patient number · DOB), and a chevron that links out to the full record in a new tab. Rows are separated by a hairline divider, not repeated card borders. When strength scoring is available (Patient Link Requests, Appointment Requests), a strength badge sits before the name instead of the generic icon chip. Empty state is a dashed-border, muted-text card ("No matches yet — fill in name, phone, email, or date of birth"), never a blank space, so staff always get a definite answer.

## Do's and Don'ts

### Do:
- **Do** keep Clinical Blue reserved for actions and brand marks; never repurpose it as a status color.
- **Do** pair every status/match-strength badge with a plain-language label (never color-only signaling), matching the confirmed WCAG 2.1 AA baseline.
- **Do** reuse the duplicate/candidate match card pattern for any future "is this the same person" surface rather than building a new one.
- **Do** favor Filament's default component styling for anything that isn't an identity-matching surface — most of this panel should look and behave like stock Filament.

### Don't:
- **Don't** introduce a second accent color or a display typeface; the one-blue, one-typeface system is deliberate.
- **Don't** add shadow-heavy "lifted" cards or hover-elevation effects — this system is flat by confirmed choice.
- **Don't** compress match-strength or status information into color-only chips with no text, even to save space — the target audience (older clinic staff/owners) needs the label, not just the tint.
- **Don't** invent new spacing/radius scales for a one-off component; reuse `rounded.md`/`rounded.lg` and Filament's existing section padding.
