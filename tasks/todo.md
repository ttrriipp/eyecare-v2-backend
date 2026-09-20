# Active Checklist

**Mobile Accessory Order Requests** — decisions approved 2026-09-20;
implementation deferred

→ `tasks/mobile-accessory-order-requests-todo.md`

The approved 12-task plan adds an active-link-only accessory catalog, immutable
multi-item order requests, staff acceptance into the canonical Optical Order
and Billing workflow, a private 30-minute GCash proof lifecycle, verified
product-rating filters, and optional Care Accessories placement. Implementation
has not started. The remaining unchecked gate is production deployment
configuration for the clinic-owned GCash account and private proof storage.

The previous active checklist remains:

**Patient-Account Link Identity Safety** — plan proposed 2026-09-17

→ `tasks/patient-account-link-identity-safety-todo.md`

The proposed 14-task plan centralizes all link writes behind deterministic
identity verification, flags later drift without disrupting patient access,
and includes existing-link reconciliation. Implementation remains paused until
the project owner approves the spec, plan, and checklist.

The previous active checklist remains:

**Appointment-Request Rebooking** — implementation approved 2026-09-08

→ `tasks/appointment-request-rebooking-todo.md`

The short specification and three-task plan are approved. The implementation
keeps the current appointment active while a new linked appointment request is
reviewed and preserves the original booking request.

The earlier active checklist remains:

**Capstone Pilot Deployment Readiness** — plan proposed 2026-09-06

→ `tasks/capstone-pilot-deployment-readiness-todo.md`

The specification, temporary participant-code ADR, and 17-task
provider-neutral implementation plan are approved. Task 1 baseline evidence
is recorded; dependency remediation is next.
Hosting, the external Android change, study fields, owners, and retention stay
as explicit pre-launch gates.

The previous completed checklist remains:

**Reports Feature** — implemented and verified 2026-08-30

→ `tasks/reports-feature-todo.md`

The administrator-only access model, four report contracts, internal navigation,
and aggregate CSV scope are implemented and verified. See the feature checklist
for task and checkpoint commits.

The earlier completed checklist remains:

**Contact-Lens Expiry Tracking** — approved 2026-08-28; implementation pending

→ `tasks/contact-lens-expiry-tracking-todo.md`

The specification and dependency-ordered plan are approved. Implementation is
starting with the lot schema and test-first inventory invariants.

The earlier completed checklist remains:

**Patient Account Self-Service Profile Editing** — implemented and verified
2026-08-28

→ `tasks/patient-account-self-service-profile-todo.md`

The specification, dependency-ordered plan, and checklist were approved on
2026-08-28. Backend implementation and focused verification are complete;
deployment and Android changes remain separate approval gates.

The latest completed checklist remains:

**Replace Frame Reservations with Saved Frames** — remediation complete
2026-08-26

→ `tasks/saved-frames-replacement-todo.md`

The specification and implementation plan are approved. Remediation is
complete for the repository state dated 2026-08-26; affected tests pass. The
full suite retains unrelated pre-existing failures outside this feature.

The previous latest completed checklist remains:

→ `tasks/ar-asset-admin-studio-todo.md`

It includes the measured-width calibration follow-up implemented on 2026-08-24.
Live preview remains explicitly deferred because it is not needed for the
server-side publication contract.

The previous appointment plan remains preserved at:

→ `tasks/appointment-calendar-and-request-schedule-review-todo.md`

Five earlier projects are implemented:

- Consultation UI Terminology — 2026-08-21
- Minimal Frame Reservations — 2026-08-12
- Commerce Model Simplification — 2026-08-13
- Dead Code and Unreachable Feature Removal — 2026-08-14
- Direct Messaging Hardening — 2026-08-15

The direct messaging specification, plan, and 18-task checklist are shipped:

→ `tasks/direct-messaging-hardening-todo.md`

All seven phases are complete, including patient/staff read status, inbox
hardening, notifications, context retirement, stable cursor pagination, and
conversation-scoped FULLTEXT message search.

See `tasks/plan.md` for the full project index.
