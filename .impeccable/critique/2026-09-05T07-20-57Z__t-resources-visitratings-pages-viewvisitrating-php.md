---
target: visit feedback detail page
total_score: 21
max_score: 40
na_heuristics:
p0_count: 0
p1_count: 2
timestamp: 2026-09-05T07-20-57Z
slug: t-resources-visitratings-pages-viewvisitrating-php
---
# Visit Feedback detail page critique

Method: dual-agent (A: Mill · B: Lagrange)

## Design Health Score

| # | Heuristic | Score | Key issue |
|---|---|---:|---|
| 1 | Visibility of System Status | 2/4 | No visible moderation/visibility state |
| 2 | Match System / Real World | 3/4 | Labels are natural, but visit/service context is thin |
| 3 | User Control and Freedom | 2/4 | Back navigation exists, but no moderation or investigation path |
| 4 | Consistency and Standards | 3/4 | Stock Filament patterns are consistent, but moderation differs from frame ratings |
| 5 | Error Prevention | 2/4 | Read-only helps, but hidden feedback can look active |
| 6 | Recognition Rather Than Recall | 2/4 | Generic identity and plain-text relations force manual lookup |
| 7 | Flexibility and Efficiency | 1/4 | No next/previous, triage, or batch-review aids |
| 8 | Aesthetic and Minimalist Design | 3/4 | Clean, but under-structured rather than intentionally minimal |
| 9 | Error Recovery | 1/4 | No actionable path for missing relations or problematic feedback |
| 10 | Help and Documentation | 1/4 | No explanation of rating scale, visibility, or next steps |
| **Total** |  | **21/40** | **Acceptable — significant improvements needed** |

## Design Specificity Verdict

The page is low-to-moderate in specificity. The data is EyeCare-specific—patient, appointment, visit date, optometrist, rating, and comment—but the composition is essentially a generic Filament record view. The target page only binds the resource; the visible UI lives in one anonymous section in VisitRatingInfolist.php.

The deterministic detector found 0 issues ([], exit code 0). That is a clean markup scan, not proof that the workflow is complete. It found no false positives. Browser inspection and overlay injection were unavailable because no Chrome DevTools, Playwright, or browser-tab tool is exposed, so responsive layout, focus order, contrast, and long-comment wrapping remain source-based risks.

## Overall Impression

It is calm and readable, but it feels like a terminal receipt rather than a useful feedback-review workspace. The biggest opportunity is to turn “read feedback” into “understand and resolve feedback”: show the review state, make the record identity obvious, and provide direct investigation/moderation paths.

## What’s Working

- Patient-originated feedback is correctly read-only; the resource disables creation.
- The two-column layout is compact while the comment gets full width.
- Terminology is mostly natural, and the list defaults to newest feedback first.

## Priority Issues

### [P1] The moderation loop is missing

The domain already supports hiding and restoring comments through ModerateVisitRating.php, but the detail page has no actions and the table exposes only “View.” A hidden comment also looks identical to an active comment because is_hidden, moderation reason, moderator, and moderation time are not shown.

Why it matters: staff cannot handle abusive or privacy-leaking comments from the Visit Feedback workflow.

Fix: show “Comment visible/hidden,” add Hide/Restore actions with a required reason, and display moderation metadata. Mirror the established Frame Ratings pattern.

Suggested command: $impeccable harden

### [P1] Every record has the same page identity

The header effectively says “View Visit Feedback” for every record. VisitRatingResource.php does not define a record title, and the page does not add a patient or appointment identifier.

Why it matters: staff opening several low-rated records cannot distinguish tabs or breadcrumbs quickly.

Fix: use a stable title such as “Visit feedback · APT-2026-000123”, and include the appointment or patient identifier in the breadcrumb.

Suggested command: $impeccable clarify

### [P2] Visit context is readable but not operable

Patient, appointment, and optometrist are plain text in VisitRatingInfolist.php. The encounter relationship exists but is not surfaced.

Why it matters: investigating a poor rating requires leaving the page and manually finding the related patient, appointment, or consultation.

Fix: link the patient and appointment to their existing admin pages; show the encounter/consultation when available, plus visit reason, appointment status, and human-readable services.

Suggested command: $impeccable layout

### [P2] The hierarchy is too flat, and the rating is not explicitly accessible

Everything sits in one unlabeled section. The rating is only repeated star characters in VisitRatingInfolist.php, without an explicit “4 of 5 stars” equivalent.

Why it matters: the most important signal is visually equal to metadata, and screen readers may announce symbols awkwardly or fail to convey the numeric score clearly.

Fix: use named groups such as “Visit context,” “Patient feedback,” and “Review state.” Keep the stars as decoration, but add a numeric/text representation.

Suggested commands: $impeccable layout and $impeccable audit

### [P2] The feedback list is underpowered for repeated review

The list has only a rating filter. It does not expose comment presence, visibility, submitted time, or moderation state, so staff must open records one at a time to triage them.

Why it matters: the detail page cannot compensate for a list that does not help staff decide what to open next.

Fix: add visibility status, comment snippet/presence, submitted timestamp, and filters for hidden/unhidden, date range, optometrist, and comments-only.

Suggested command: $impeccable layout

## Persona Red Flags

**Alex — power user**

- No next/previous navigation or batch moderation path.
- Cannot filter for unmoderated comments or recent feedback.
- Generic page titles make multiple open records indistinguishable.
- Plain-text relations force manual lookup.
- The page ends without a clear next action.

**Sam — accessibility-dependent user**

- Field labels are a good baseline.
- The anonymous section may provide weak heading navigation.
- Star glyphs are the only rating representation; a numeric accessible equivalent is missing.
- Hidden/visible status is not available as text or another non-color cue.
- 200% zoom, focus order, contrast, and long-comment wrapping need browser verification.

## Minor Observations

- Patient and appointment have no explicit placeholder if a relation is missing or soft-deleted.
- “No comment” is inconsistent with nearby “—” empty-value conventions.
- Visit Date shows only the date while Submitted shows a time; same-day visits could be clearer with the visit time.
- Long comments need verification for wrapping and readable line length.
- Mapping low ratings to the semantic danger color may make dissatisfaction feel like a system error.
- There is no Filament-specific Visit Feedback detail/moderation test, despite domain moderation tests already existing.

## Questions to Consider

- If staff opens a one-star comment, what is the intended next click—and why does the page currently end at “Comment”?
- What should distinguish three open feedback tabs: “View Visit Feedback,” or a stable patient/appointment identifier?
- Could the page make the decision sequence explicit: rating → comment → visit context → visibility and next action?
