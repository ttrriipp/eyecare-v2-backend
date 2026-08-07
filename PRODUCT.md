# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary users of this repository's surface (the Filament admin panel at `/admin`) are Padilla Optical Clinic's staff:

- **Admin** — full access, including user management, audit/privacy records, and destructive archive/restore actions.
- **Staff** (receptionists, and staff who are also optometrists via the `is_optometrist` flag) — day-to-day clinic operations: appointments, encounters, quotations, orders, billing, inventory. Optometrist-only actions (starting/completing encounters, finalizing/amending prescriptions) require the flag.

Patients are a second audience but are served by a separate Android app (own codebase) that consumes this backend's `/api/v1` REST API — patients are not users of the Filament panel and are not a UI audience for work done in this repo.

## Product Purpose

POCMS ("EyeCare") is a management system for Padilla Optical Clinic covering the full operational loop: patient records, appointment scheduling, clinical encounters and prescriptions, optical retail (quotations → optical orders → dispensing), unified billing/payments, and inventory. It is the academic capstone/thesis project "Online Optical Management System with Augmented Reality for Padilla Optical Clinic," but is built and evaluated to real production standards rather than as a throwaway demo — the codebase includes a genuine deployment guide (Laravel Cloud / VPS), production-shaped data model (encryption, audit logging, soft deletes, idempotent financial actions), and a full Pest test suite.

Success for this repository specifically means: clinic staff can run a full day's operations (book/check in patients, run encounters, quote and fulfill optical orders, take payments, track inventory) through the admin panel without needing to leave it or work around missing functionality.

## Positioning

The differentiator (product-wide, not just this repo) is a prescription-aware optical retail flow tied to real clinical and inventory data, paired with AR frame try-on on the patient-facing Android app (CameraX + MediaPipe) — as opposed to generic clinic scheduling software or a spreadsheet-based shop, patients can virtually try on frames that are backed by the clinic's actual stock and their actual prescription, and staff manage that same order end-to-end (quotation → fulfillment → billing) in one system instead of stitching together separate booking, POS, and inventory tools.

## Operating Context

A physical optical clinic (reception desk, exam rooms, optical/dispensing counter). Staff use the Filament web panel — likely desktop/laptop at a front-desk or back-office workstation — to manage the walk-in and scheduled patient flow, run consultations, and process optical sales and payments. Patients interact separately and remotely via the Android app to book appointments, browse the frame catalog (with AR try-on), track prescriptions/orders/billing, and message staff.

The backend/admin repo and the Android app are separate codebases; this repo owns the Filament panel and the API contract both sides depend on.

## Capabilities and Constraints

- Three fixed roles (`admin`, `staff`, `patient`); no dynamic permission management.
- Full patient lifecycle: intake, clinical encounters, prescriptions (versioned, amendable, never destructively edited), quotations, optical orders (product-only fulfillment), unified billing with itemized charge provenance (optical order / quotation service / encounter / direct service).
- Encrypted clinical and contact fields; audit logging on sensitive actions; soft-delete + archive/restore instead of hard deletes on key models.
- Built to real Laravel/Filament conventions (Laravel 13, Filament 5, Livewire 4, Pest 4) rather than academic shortcuts — every feature is expected to be genuinely correct (domain-validated, tested), not merely demoable.
- Known, explicitly tracked gaps and mismatches between the original workflow spec and the current implementation live in `docs/gap-analysis.md` — treat that file as current ground truth for "known incomplete," not something to silently declare fixed without checking.
- A structured UI/UX audit already exists at `docs/ui-ux-audit.md` (screen-by-screen, with severity ratings) — read it before redesigning a screen it already covers, so new design work builds on that evaluation instead of repeating it.

## Brand Commitments

- App/product name: **EyeCare**. Clinic name: **Padilla Optical Clinic**.
- Primary color: `#4F8DD7` (shared with the Android app).
- Panel font: Instrument Sans (400/500/600).
- Logo: biconvex lens/eye mark + "EyeCare" wordmark, at `resources/views/filament/admin/logo.blade.php`.
- Favicon: `public/images/favicon.svg`.
- Default theme: light, with a dark mode toggle available.

## Evidence on Hand

- Seeded demo accounts for all three roles (`DemoUserSeeder`) — real seeded data to design and test against, not fabricated for this file.
- `docs/ui-ux-audit.md` — an existing screen-by-screen UX audit with severity ratings and concrete suggested improvements; treat as a durable input, not something to re-derive from scratch.
- `docs/gap-analysis.md` — a workflow-by-workflow checklist of what's implemented, partial, or missing against the original spec.
- `docs/screenshots/` — existing screenshots of implemented screens.
- No real clinic testimonials, press, case studies, or production usage data exist (academic project) — do not fabricate any.

## Product Principles

- Staff operational efficiency (speed through daily clinic tasks) outranks visual polish on this surface — it's an internal tool, not a marketing surface.
- Correctness of clinical and financial workflows is non-negotiable; a good-looking screen that lets staff record an impossible state (e.g. bypass a status transition rule) is a defect, not a design win.
- Treat this as production software: match Laravel/Filament idiom, keep tests passing, and do not take shortcuts on the theory that "it's just a school project."
- Known gaps are recorded, not hidden — `docs/gap-analysis.md` and `docs/ui-ux-audit.md` are living documents; update them rather than letting design work silently diverge from tracked reality.

## Accessibility & Inclusion

Target baseline: **WCAG 2.1 AA** (no external mandate — recommended as a reasonable, industry-standard baseline for a clinic staff tool; user confirmed no stricter standard applies).
