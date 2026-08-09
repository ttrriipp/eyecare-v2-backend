# Practical Clinical Encounter Workflow

## Problem Statement

How might we give the optometrist a fast, trustworthy way to record what was examined, concluded, and recommended during a visit without turning the clinic system into a hospital-grade electronic health record?

## Recommended Direction

Use one optometrist-owned encounter workflow for scheduled patients and walk-ins. Staff confirms the patient's identity and checks the patient in, which creates a planned encounter. The appointment's brief reason for visit may prefill the chief complaint. The assigned optometrist then starts the consultation and authors the clinical record directly through a four-step wizard: History, Examination, Assessment & Plan, and Review & Complete.

Patient intake is not part of the active encounter workflow. The encounter does not create, submit, verify, attach, or require a separate intake record. The mobile appointment request remains limited to scheduling and a brief reason for visit.

The clinical form remains device-neutral. For the MVP, the autorefractor's original paper output stays in the physical patient folder, while the optometrist records relevant information in a general optional supporting-tests field. The encounter will not require an autorefractor checkbox, reproduce every raw value, or integrate directly with the machine.

## Recommended Process

```text
Scheduled patient or walk-in
    -> staff confirms identity and checks patient in
    -> planned encounter is created
    -> assigned optometrist starts consultation
    -> History
    -> Examination
    -> Assessment & Plan
    -> Review & Complete
    -> completed clinical record becomes read-only
```

### Wizard Steps

1. **History**
   - Chief complaint or reason for visit
   - Relevant ocular, medical, and surgical history
   - Allergies and medications

2. **Examination**
   - Examination findings or summary
   - Optional supporting tests and relevant results

3. **Assessment & Plan**
   - Optometrist's clinical assessment
   - Advice and management plan
   - Optional referral or follow-up recommendation
   - Optional prescription

4. **Review & Complete**
   - Complete encounter summary
   - Treating optometrist confirmation
   - Explicit completion action

The wizard automatically saves when moving between steps, preserves Back navigation, and resumes from the last saved step after an interruption. Required clinical fields should be enforced when completing the encounter rather than while saving a draft.

## Completion Contract

A completed encounter must contain:

- an identified patient;
- the treating optometrist;
- a chief complaint or reason for visit;
- an examination summary;
- an assessment;
- a plan;
- the completion author and timestamp.

A prescription, referral, follow-up recommendation, or autorefractor result is not required for every encounter.

## Roles and Record Ownership

- **Staff** confirms identity, checks the patient in, and performs non-clinical operational support.
- **Optometrist** reviews or collects history, examines the patient, records the assessment and plan, optionally finalizes a prescription, and completes the encounter.
- **Admin** has no clinical authority unless the same account also holds the `optometrist` role.
- **Admin + optometrist** represents an owner-optometrist using one auditable account with both roles.

Only the assigned optometrist may normally complete the encounter. Before a consultation starts, staff may change the assignment as an ordinary scheduling action. After it starts, an explicit Transfer Encounter action must identify a new optometrist and require a reason. The current optometrist or an administrator may initiate the transfer, all existing draft information is preserved, and the audit trail records the previous optometrist, new optometrist, reason, actor, and timestamp. After transfer, only the new assigned optometrist may edit and complete the encounter. A plain administrator may coordinate the transfer but cannot author or complete clinical content.

## Record Finality

The authoring wizard is used only while an encounter is in progress. A completed encounter is displayed as a single read-only clinical summary. Corrections use a simple append-only addendum; the original completed record is not silently overwritten.

Each printed addendum contains the encounter number, original encounter date, a clear `Addendum — original record unchanged` label, sequence number, date and time, author and professional identity, reason, and corrected or additional clinical statement. Field-by-field before-and-after comparisons are not required. Prescription corrections continue through the separate prescription-amendment workflow.

The prescription remains a separate finalized record linked to the encounter. The appointment type is displayed as context but does not generate a different encounter schema or workflow.

## Key Assumptions to Validate

- [ ] The optometrist is willing to enter the clinical record during or immediately after each consultation; validate by observing several representative visits.
- [ ] Chief complaint, examination summary, optional supporting tests, assessment, and plan are sufficient core fields across the clinic's routine, follow-up, urgent, referral, and contact-lens visits; validate against the clinic's blank paper health-record form.
- [ ] Keeping the autorefractor printout in the physical folder remains operationally reliable; validate the folder and packet-check process with clinic staff.
- [ ] A four-step wizard is faster and clearer for the optometrist than the current unstructured record; validate through a short usability walkthrough.

## MVP Scope

- One encounter lifecycle for scheduled patients and walk-ins
- Planned, in-progress, completed, and pre-start cancelled states
- Optometrist-only clinical authoring and completion
- Four-step autosaving wizard
- History, examination summary, assessment, and plan
- Optional prescription, referral, and follow-up
- Optional device-neutral supporting tests and results while retaining the autorefractor paper output
- Read-only completed summary
- Simple append-only correction addendum
- Explicit, reasoned, and audited transfer of an in-progress encounter
- Server-side completion validation and audit attribution

## Not Doing (and Why)

- **Mobile pre-visit intake** — not part of the active workflow; reconsider only after observing that history collection materially slows consultations.
- **Intake inside the appointment request** — scheduling should collect only the information necessary to process the request.
- **Direct autorefractor integration or OCR** — dependent on machine capabilities and unnecessary while the clinic retains the paper output.
- **Autorefractor-specific form fields or required checkbox** — the clinical form should record relevant examinations and supporting results rather than revolve around one device.
- **Raw structured autorefractor fields** — the machine result supports, but does not replace, the optometrist's assessment and final prescription.
- **Large structured examination catalog** — the clinic has not yet confirmed which measurements it consistently records.
- **Diagnosis-code catalog** — premature for the clinic's current workflow; use a clear clinical assessment first.
- **Different forms for every appointment type** — one core workflow is easier to learn and maintain; appointment type remains contextual guidance.
- **Administrator-configurable clinical templates** — creates configuration and validation complexity without demonstrated need.
- **Automated clinical recommendations** — outside the MVP and inappropriate without much stronger clinical governance.
- **Field-by-field record versioning** — a simple append-only addendum provides understandable correction history with less complexity.

## Open Questions

- Which examination measurements appear on the clinic's current paper health-record form and are consistently used in practice?

Until that form and real workflow are reviewed, the MVP uses a required narrative examination summary plus optional supporting tests/results and does not introduce dedicated structured measurement fields.
