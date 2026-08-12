---
target: appointment requests
total_score: 24
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 2
p2_count: 1
p3_count: 1
timestamp: 2026-08-11T23-57-00Z
slug: app-filament-resources-appointmentrequests
---
# Design Critique: Appointment Request Flow

**Target:** `app/Filament/Resources/AppointmentRequests`
**Mode:** Operate (task-completion interface for clinic staff)
**Date:** 2026-08-12

---

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Status badges and timestamps present, but no urgency indicators for aging requests |
| 2 | Match System / Real World | 2 | "Linked/Unlinked" is internal jargon; no optical-domain language |
| 3 | User Control and Freedom | 3 | Reject has confirmation, but no undo path after rejection |
| 4 | Consistency and Standards | 3 | Two different "Link to Patient" flows with different schemas |
| 5 | Error Prevention | 2 | No guard against accepting expired requests; no confirmation on accept |
| 6 | Recognition Rather Than Recall | 3 | Candidate matching good; appointment type descriptions missing |
| 7 | Flexibility and Efficiency of Use | 2 | No keyboard shortcuts, no bulk actions, empty BulkActionGroup |
| 8 | Aesthetic and Minimalist Design | 3 | Clean but flat; no visual hierarchy within sections |
| 9 | Error Recovery | 2 | Generic notification messages lose field-level context |
| 10 | Help and Documentation | 1 | Zero inline help except contact note helper text |
| **Total** | | **24/40** | **Functional but unconsidered** |

---

## Design Specificity Verdict

**Category-Interchangeable.** This is a competent Filament scaffold with good domain modeling, but reads as "generic CRUD for any appointment system." Nothing in the visual language, copy, information hierarchy, or interaction design is specific to an optical clinic. The `PatientCandidateMatchCard` is the one component showing domain awareness. The "Reason for Visit" field — arguably the most clinically important context — is a disabled textarea buried at the bottom of a 7-field section.

---

## Overall Impression

The candidate matching system is genuinely intelligent and product-specific. The conditional logic (contact note when time deviates, referral for referral-required types) shows domain awareness in business logic. But the UI treats every field with equal weight, the accept flow hides the context staff need while scheduling, and the ending is a void — no link to the created appointment, no confirmation the patient's time was honored.

---

## What's Working

1. **Intelligent candidate matching.** `RankPatientCandidates` → `PatientCandidateMatchCard` with strength labels reduces cognitive effort for patient linking. This is product-specific intelligence.

2. **Smart conditional logic.** Contact note requirement when time deviates from preferences, referral source for referral-required types — domain awareness in the business logic.

3. **Navigation badge and filtered tabs.** Pending count badge gives staff a clear "what needs my attention" entry point.

---

## Priority Issues

### P0 — Accept Modal Has No Confirmation
Reject requires confirmation. Accept — the action that creates an appointment and allocates optometrist time — does not. A misclick creates a real appointment. The higher-consequence action has less protection.

### P1 — Preferred Times Invisible During Scheduling
The Accept modal asks staff to pick a DateTimePicker, but patient's preferred times are on the view page behind the modal. Staff must memorize or toggle between modal and page. This is the single biggest friction point in the daily workflow.

### P1 — Inconsistent Link Flows
Table row action only supports existing patients (Select). View page supports existing + new patients via ToggleButtons. Staff linking from the table never see the "New Patient" path. Feature parity bug.

### P2 — Reason for Visit Buried
For an optical clinic, the patient's stated reason is the primary clinical context for triaging. It's a disabled textarea at the bottom of a 7-field, 3-column section. Should be prominent.

### P3 — No Empty State Guidance
When zero pending requests exist, the table shows nothing. No signal whether system is working or nothing to do.

---

## Persona Red Flags

**Alex (Power User):**
- No bulk accept/reject (BulkActionGroup is explicitly empty)
- No keyboard navigation through accept modal
- 6 tabs instead of a default "actionable" view
- No quick-accept from table row

**Jordan (First-Timer):**
- Zero onboarding copy explaining "Linked" vs "Unlinked"
- Potential Matches section shows cards with no explanation of why suggested
- Appointment Type dropdown has no descriptions
- No field-level help anywhere

**Sam (Accessibility):**
- Color-only status indicator on expires_at column (WCAG failure)
- Badge colors are only differentiator for status — color-blind users struggle
- Disabled textarea is focusable but not editable

---

## Minor Observations

- `expires_at` labeled "Latest Requested Time" — misleading; it's the expiry deadline
- Two sections titled "Patient Information" (linked vs snapshot) — differentiating names would be clearer
- `linkToPatient` candidate-building code is copy-pasted across table and view page — drift risk
- Navigation group "Today" for future-oriented requests feels like a catch-all
- Navigation badge counts non-expired pending, but Pending tab includes expired — counts disagree

---

## Questions to Consider

1. Why does the accept modal exist at all? Why not inline editing on the view page?
2. What happens when two staff open the same request? No locking or "being reviewed by" indicator.
3. Is "Reject" the right framing? In healthcare, "decline with reason" may better reflect the relationship.
4. Why are Cancelled and Expired separate tabs? Both are terminal. Could be a single "Closed" tab.
5. What's the lifecycle after acceptance? No link to the created appointment.
