---
target: the Clinic Availability page
total_score: 21
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 2
timestamp: 2026-08-05T02-00-12Z
slug: app-filament-pages-availability-php
---
Method: dual-agent (A: design review · B: detector + rendered-DOM evidence), both run as isolated sub-agents.

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 1 | The `enabled` Toggles aren't `->live()` (`Availability.php:253`, `:283`) but the TimePickers' visibility depends on them — toggling a day off produces no visible change until an unrelated round-trip. Reads as broken. |
| 2 | Match System / Real World | 2 | This is a config surface; staff arrive with a status question ("is Dr. Santos in today?"). Nothing on the page answers that directly. |
| 3 | User Control and Freedom | 1 | Overrides can only be added or deleted, never edited (`:317`, `:526`) — a typo requires delete-and-recreate. |
| 4 | Consistency and Standards | 2 | Confirmed by rendered DOM: the two Save buttons are byte-identical in class markup, distinguishable only by label text. Section titled "Optometrist Hours" (`:271`) saves as "Save **Provider** Hours" (`:300`). Weekly TimePickers omit `->native(false)` (`:255-263`) while the modal's do (`:349`, `:359`) — two different time-input UIs on one page. |
| 5 | Error Prevention | 3 | Real strengths here: `minDate(today())`, cross-validation against clinic hours, transaction-wrapped saves. Weak spot: nothing prevents saving one section while leaving the other's edits stranded. |
| 6 | Recognition Rather Than Recall | 1 | The weekly-hours grid and the overrides list never cross-reference each other. A closure on a given date supersedes what the grid shows for that weekday, with zero visual link between them. |
| 7 | Flexibility and Efficiency | 2 | No bulk actions — no "copy to all days," no "same as clinic hours." Configuring one optometrist means touching 14 individual time fields by hand. |
| 8 | Aesthetic and Minimalist Design | 2 | Confirmed structural defect, not a taste call: the empty spacer `Placeholder` sits in *column 3, between Open and Close* (`:259`, `:289`) — splitting the one pair of fields that should read as a unit. |
| 9 | Error Recovery | 3 | Day-prefixed validation messages ("Tuesday: Opening time must be before closing time") are genuinely good — but they land in a toast, not next to the offending row. |
| 10 | Help and Documentation | 4 | The best-written part of the page. Section descriptions do real work — `:305` states outright that overrides take priority over the weekly hours shown just above. |
| **Total** | | **21/40** | **Acceptable (53%) — solid backend logic, unauthored UI layer** |

## Design Specificity Verdict

**LLM assessment: 3/10 — a generic business-hours form with clinic vocabulary pasted on.** Every weekday renders with identical visual weight despite this being a Philippine optical clinic with a genuinely asymmetric week (Sunday likely closed, Saturday likely short) — nothing in the layout makes the *exception* day easier to spot than the five identical ones. Every clinic day defaults to enabled 09:00–17:00, including Sunday, with no clinic-specific defaults. Override dates render as bare `M j, Y` with no weekday name, forcing whoever's reading it to do date arithmetic in their head to check whether a closure lands on the day they care about. The one place clinic-specific insight actually shows up — an optometrist can't close the whole clinic, per the authorization rules built earlier — is expressed *only* in a rejection toast after the fact, never shaped into what the form offers in the first place. Swap "Optometrist" for "Stylist" and this is a barbershop's hours page.

**Deterministic scan: two false negatives, one genuine clean result.** Scanning `Availability.php` and its Blade wrapper directly both returned `[]` — but the detector's scannable-extensions list doesn't include `.php`, so neither invocation opened a single file; `[]` here means "nothing was inspected," not "nothing was found." The Blade wrapper is trivially clean on its own merits (four lines, no markup). To get real coverage of the hand-built override-list HTML, Assessment B extracted the actual method's live output (via reflection against a seeded page instance, covering all three override types) into a genuine `.html` file and scanned *that* — which came back authentically clean. So the custom HTML in `renderOverridesList()` has no detector-catchable defects; the issues below are structural/UX, which a detector was never going to catch regardless.

**Visual overlays: none — but real rendered DOM, not a guess.** No browser/screenshot tool was available. Rather than fabricate a visual pass, Assessment B authenticated as the seeded admin and rendered the live page through Laravel's own HTTP kernel, producing 303,681 bytes of genuine server-rendered DOM. That census is the hard evidence behind several findings below: 14 toggles, 28 time inputs, two identically-styled Save buttons, and — most tellingly — **172,284 bytes of document (57% of the page) separating the "Add Override" button from the Schedule Overrides section it populates.**

## Overall Impression

The backend work done today is sound — transaction safety, cross-validation, audit logging, real business rules. None of that shows up in the interface. This page reads as three unrelated admin forms stapled together in build order rather than designed as one surface: a weekly config table, a second near-identical weekly config table, and a bolted-on list feature whose "add" button lives at the opposite end of the page from its own list. The single biggest opportunity is also the cheapest fix: the Provider Hours grid has a live, confirmed layout bug — not a polish nitpick — sitting in the default first-run state every optometrist sees.

## What's Working

1. **Role-aware defaults are quietly excellent.** A non-admin optometrist opens the page already selected as themselves (`:62-63`), sees only "Provider Absence" in the override type list, and has their own `user_id` locked in on the add-override form. The permission model is expressed *in the form*, not just enforced after submission — this is the one place the page was actually designed around who's using it.
2. **Section descriptions teach the mental model in one line.** `:305`, "These take priority over the weekly hours above," and `:272`'s constraint-before-violation phrasing are doing real work that most admin forms skip entirely.
3. **Day-scoped validation errors.** Prefixing the failure with the weekday name turns an anonymous rejection into something a person can act on immediately, and the transaction wrap means a bad day doesn't leave a half-written save behind.

## Priority Issues

**[P0] The Provider Hours grid physically scrambles.** `Availability.php:281-294` feeds 28 flat-mapped fields into a single `Grid::make(4)`, and every day defaults to disabled (`:104`) — meaning hidden TimePickers remove cells from the grid and reflow every subsequent day into the wrong column. Clinic Hours avoids this entirely by building one separate `Grid::make(4)` per weekday (`:252`). *Why it matters:* this is the default state every optometrist sees the first time they open the page — the very form meant to make their self-service configuration easy is visually broken on arrival. *Fix:* mirror the Clinic Hours structure exactly — one grid per day instead of one giant flat-mapped grid — and make the `enabled` Toggles `->live()` so hiding/showing actually happens live instead of needing an unrelated round-trip. → `/impeccable layout`

**[P1] Two Save buttons, no dirty-state indicator, silent data loss.** Confirmed identical in rendered markup — same classes, same color, distinguishable only by reading the label. `:266` and `:299`. *Why it matters:* a receptionist who fixes Saturday's clinic close time, then scrolls up to adjust an optometrist's hours, clicks whichever Save button is in view, and walks away believing everything saved — when only one section actually did. *Fix:* either one "Save Changes" action covering both sections in a single transaction, or keep two but disable each until its own section is actually dirty, with a visible unsaved-changes indicator on the section header. → `/impeccable clarify`

**[P1] The weekly grid and the overrides list never reference each other.** The Clinic Hours section — the very first thing on the page — shows a recurring pattern that any override below it can silently supersede, with zero visual link between the two. *Why it matters:* this is the page's actual reason for existing (per the section description's own admission at `:305`) and it isn't expressed in the layout at all — a reader has to hold "there's a closure on the 12th" in their head while scanning an unrelated weekly grid. *Fix:* a small "Today & Next 7 Days" strip at the top showing actual resolved hours per day with an override badge where one applies — this also reframes the page from pure configuration toward answering the question staff actually walk up with. → `/impeccable shape`

**[P2] The overrides list drifts from this app's own established list-card convention.** `renderOverridesList()` (`:189-243`) matches the sibling `PatientCandidateMatchCard` component on outer shell and empty-state copy almost verbatim, but drops the `size-9 rounded-full` icon chip and the row hover state that component uses — meaning the three color-coded override types (danger/warning/info, confirmed as Filament's standard semantic tokens) read as flat badge text instead of a scannable chip. *Why it matters:* a receptionist scanning for "is there a closure this week" is reading text-in-a-pill instead of a color shape, which is measurably slower to pre-attentively scan. *Fix:* extract a sibling `ScheduleOverrideCard` support component following the exact pattern of `PatientCandidateMatchCard` — same icon-chip shell, same hover, colored by type. → `/impeccable polish`

**[P2] Zero-accessibility-attribute surface, confirmed by grep.** Zero `aria-*` or `role=` attributes anywhere in the file. Fourteen weekly TimePickers per section carry only generic labels ("Open"/"Close"/"Start"/"End") with no programmatic tie to their weekday — a screen-reader user tabbing through hears "Open, Close, Open, Close…" seven times with no way to know which day is which. The delete button's only accessible name is the bare word "Remove," with no indication of which date or type it removes. Fourteen empty spacer `Placeholder` elements are real nodes injected into tab order for pure visual padding. *Why it matters:* this is a flat WCAG 2.1 AA violation against the app's own stated baseline, not a nice-to-have. *Fix:* label every TimePicker with its weekday ("Wednesday — Open"), give the delete button an `aria-label` naming the specific override, and use `->hiddenLabel()` instead of empty-label placeholders so they don't register as real controls. → `/impeccable audit`

## Persona Red Flags

**Jordan (Confused First-Timer):** Clicks "Add Override" at the very top of the page with no idea what the word means and no inline explanation. Toggles a weekday off in the grid below and sees nothing happen (Toggle isn't `->live()`) — reasonably concludes the page is broken. Reads "Save Provider Hours" under a section titled "Optometrist Hours" and doesn't connect the two. Wants to fix a wrong time and discovers the only path is delete-then-recreate.

**Sam (Accessibility-Dependent):** The worst-affected persona here, confirmed by a flat zero on the accessibility grep. Tabbing through the weekly grids produces "Open, Close, Open, Close…" fourteen times with zero way to tell which day is being edited. The confirm-to-delete uses a native `wire:confirm` browser dialog, inconsistent with every other destructive action in this panel, which uses Filament's own modal. Every delete button announces itself only as "Remove."

**An optometrist configuring their own hours between patients:** Gets the least time and hits the most friction. Lands on Clinic Hours first (not theirs), scrolls past a section they have no authority to change, arrives at a grid that's visually scrambled by default (P0 above), sets fourteen individual time fields with zero bulk affordance, and only learns whether their entered hours actually fit inside clinic hours after submitting — nothing in the form itself hints at the constraint until it's violated.

## Minor Observations

- Naming drift: section says "Optometrist Hours," the Save button says "Provider Hours," the model is `ProviderHour`. Pick one word — "Optometrist" is what staff actually say.
- The overrides list filters to `>= today` but the section is titled "Schedule Overrides," not "Upcoming Overrides" — it silently drops history with no indication anything was filtered out.
- One spacer placeholder uses `->label('')` where the same file correctly uses `->hiddenLabel()` elsewhere — a small internal inconsistency.
- Override dates render as `Aug 13, 2026` with no weekday name; `Thu, Aug 13` removes the mental math entirely.
- `Textarea::make('reason')->columnSpanFull()` is a no-op inside a single-column modal.
- No cap or pagination on the overrides list — a clinic that front-loads a year of holidays gets an unbounded wall.
- The single header action ("Add Override") dominates the top-right of the page for a feature that's the third section down.

## Questions to Consider

1. Is this really one page, or three? Clinic Hours changes about once a year, Optometrist Hours changes per-hire, Overrides change weekly — why does someone adding a Christmas closure have to scroll past two forms she has no reason to touch?
2. Why is a configuration page the only answer to a status question? The real query a receptionist arrives with is "who's in today" — should the page open with a live "Today at Padilla Optical" summary and treat the config forms as secondary?
3. Should the weekly grid be an actual week, not a stacked list? Seven rows read as the shape of a database table, not the shape of a week — a 7-column strip would make "Sunday's closed, Saturday's short" a one-glance fact, and the spacer-column question disappears on its own.
4. The header-action placement for "Add Override" was a testing-tool workaround, not a design decision — should a test-framework limitation be allowed to set the information architecture, or is the better fix a proper test helper instead?
