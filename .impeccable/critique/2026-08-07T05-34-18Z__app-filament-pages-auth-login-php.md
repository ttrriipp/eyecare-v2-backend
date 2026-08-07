---
target: the login screen
total_score: 19
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 2
timestamp: 2026-08-07T05-34-18Z
slug: app-filament-pages-auth-login-php
---
Method: dual-agent (A: design-review agent · B: detector-evidence agent)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Inherits Filament's native loading/disabled-button states untouched — fine. |
| 2 | Match System / Real World | 1 | Field labeled "Username" (Login.php:16) but is strict type="email" with Laravel ->email() validation — confirmed live via curl. |
| 3 | User Control and Freedom | 1 | getPasswordFormComponent() (Login.php:23-31) drops the base class's password-reset hint link entirely — yet AdminPanelProvider.php:40 calls ->passwordReset(), so the feature is live but unreachable from this screen. |
| 4 | Consistency and Standards | 1 | Login layout runs its own color/type system in a local <style> block. Detector: 7 advisory findings, all in login.blade.php — 8 of 9 distinct hex/rgba values undocumented against DESIGN.md tokens. |
| 5 | Error Prevention | 3 | Rate limiting, timebox, validation all inherited from Filament's base Login.php untouched. |
| 6 | Recognition Rather Than Recall | 3 | Three fields, no memory burden, straightforward layout. |
| 7 | Flexibility and Efficiency | 2 | Autofocus/autocomplete retained; nothing else differentiates for repeat daily users. |
| 8 | Aesthetic and Minimalist Design | 2 | box-shadow and a 900-weight, 2.25rem, letter-spaced uppercase heading are ornamental against a documented flat, weight-based system. Detector confirms font-size is 2x the documented heading scale (2.25rem vs 1.125rem), weight 900 vs 600. |
| 9 | Error Recovery | 2 | Default Filament validation messaging is fine, but combined with issue #3, a failed login plus no reset link is a dead end. |
| 10 | Help and Documentation | 1 | No support contact, no "contact your admin" for a locked-out user. |
| **Total** | | **19/40** | **Poor** |

## Design Specificity Verdict

LLM assessment: Bespoke but for the wrong product-era. login.blade.php was authored with real intent but against docs/specs/custom-login-page.md, a spec that predates DESIGN.md and was never reconciled with it. It opts out of the real brand mark (Login::hasLogo() returns false), discarding the biconvex lens/eye SVG for a plain bold black wordmark.

Deterministic scan: detect.mjs exit code 2, 7 advisory findings, all in login.blade.php — 1 undocumented radius (line 11), 1 undocumented font-size (line 12), 5 undocumented colors (lines 11, 13, 24x2, 26). Confirmed via curl that the rendered submit button carries fi-color-primary (Clinical Blue), not the "full-width black Login button" the spec calls for — implementation has drifted from its own spec too.

Visual overlays: Not available — no browser automation tool exposed in this environment. Evidence came from detect.mjs's static scan plus curl against the running Sail instance.

## Overall Impression

The substrate is sound (Filament's rate limiting, MFA, validation, timebox all untouched) but the skin was designed once against a superseded spec and never revisited. This is the one screen with zero shared code with the rest of the panel, and the cheapest place to accumulate drift silently — it already has.

## What's Working

1. Minimal field set — email, password, remember; no unnecessary friction.
2. Responsive collage degrades cleanly — images hidden under 768px, form recenters without breakage.
3. Auth plumbing untouched — rate limiting, MFA, timebox, validation all correctly inherited from Filament's base Login page.

## Priority Issues

[P0] Locked-out staff have no way back in
Why it matters: AdminPanelProvider.php:40 enables ->passwordReset(), but the login screen's overridden getPasswordFormComponent() drops the hint link that would surface it. Converts a routine forgotten-password moment into a support escalation for less tech-fluent staff.
Fix: Restore a ->hint() reset link on the password field, styled to match the bespoke card.
Suggested command: /impeccable harden

[P1] Two design languages collide on one screen
Why it matters: 8 of 9 colors in the login layout's <style> block are undocumented against DESIGN.md; heading runs at 2x documented size and 900 vs 600 weight; real logo component bypassed for a reinvented wordmark. First screen users see doesn't match what they see one click later.
Fix: Swap plain-text "EYECARE" for the actual logo.blade.php lockup; replace local color/shadow/radius values with DESIGN.md tokens; bring heading within the documented type ramp.
Suggested command: /impeccable polish

[P1] "Username" mislabels a strict email field
Why it matters: Field is type="email" with Laravel ->email() validation, but labeled "Username" — teaches the wrong mental model right before rejecting non-email input.
Fix: Relabel to "Email" or "Work Email."
Suggested command: /impeccable clarify

[P2] Tonal mismatch in the tagline
Why it matters: "When elegance meets convenience" reads as retail/e-commerce copy, dissonant with the panel's operational-efficiency voice everywhere else.
Fix: Drop it or replace with something functional/reassuring.
Suggested command: /impeccable clarify

## Persona Red Flags

Jordan (First-Timer): Sees "Username" but the field silently rejects anything without an @, no inline explanation. If she mistypes her password, no visible recovery path and no support contact anywhere on the page.

Riley (Stress Tester): Deliberately tries "forgot password" flows — no entry point despite the feature existing at the routing level (->passwordReset()). Also worth probing whether the bespoke <style> block's !important background overrides survive a hard refresh mid-multi-factor-challenge state.

Sam (Accessibility-Dependent): Tagline color #6b7280 on white sits right at the WCAG AA boundary (~4.6:1) — passes, but with little margin, worth rechecking once the palette is reconciled with DESIGN.md tokens.

## Minor Observations

- autocomplete="on" on the email input is generic; autocomplete="username" or "email" gives password managers a stronger signal.
- docs/screenshots/login_concept.png shows a person's photo and a visible "Forgot password?" link, both since removed per the spec's decisions — that asset is now stale as a reference.

## Questions to Consider

- Now that passwordReset() is wired up panel-wide, was removing the reset link ever revisited, or is it a stale decision from before the feature existed?
- Who owns reconciling docs/specs/custom-login-page.md against DESIGN.md — should this screen keep a separate spec long-term?
- If the button already silently drifted from "black" to Clinical Blue without anyone noticing, what does that say about how this screen gets tested visually?
