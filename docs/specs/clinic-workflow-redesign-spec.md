# Spec: Clinic Workflow Redesign

## Status

Approved by the project owner on 2026-07-25. This document defines the target
behavior. Implementation still requires an approved technical plan and task
breakdown.

This specification supersedes workflow assumptions in earlier order, billing,
appointment, and patient-resource specifications where they conflict with this
document. Existing specifications remain useful as implementation history.
`docs/BACKEND_CONTEXT.md` continues to describe the currently implemented
system until this redesign is delivered.

## Confirmed Intent

Padilla Optical Clinic needs a clinic-first system rather than an online-store
workflow. The target patient journey is:

```text
Appointment or walk-in
    -> patient intake
    -> check-in
    -> clinical encounter
    -> final prescription
    -> quotation
    -> accepted job order
    -> physical Service Invoice and payment tracking
    -> dispensing
```

The system must use **patient**, never **customer**, in user-facing language.
Appointments and clinical encounters are separate records. A patient exists
independently of a login account. Optometrists are the primary web-panel users,
while a receptionist may perform non-clinical operational work.

## Confirmed Assumptions

1. Scheduled patients and walk-ins eventually enter the same clinical workflow.
2. An appointment is a booking or queue record; an encounter records care that
   actually occurred.
3. A patient record may exist without a mobile login account.
4. Initially, one patient account links to at most one patient. The data model
   must allow a future migration to family or dependent management.
5. The account roles remain `admin`, `staff`, and `patient`.
6. `is_optometrist` remains a capability for eligible admin/staff accounts; it
   is not a separate mutually exclusive role.
7. Non-optometrist staff may view patient-submitted intake to verify it during
   check-in, but may not author examination findings or prescriptions.
8. Patients choose an available clinic time, not a specific optometrist.
9. The mobile catalog displays frames only. It has no checkout.
10. Frame selection in the mobile app is for an in-clinic fitting reservation,
    not a purchase.
11. Quotations are normally discussed and accepted in person. Staff records
    acceptance; mobile approval is not required in this redesign.
12. A patient may decide on a quotation later, so quotations require a
    draft/presented/accepted/declined/expired lifecycle.
13. Raw autorefractor output is not stored in the initial redesign. The
    optometrist records only the finalized prescription and remarks.
14. The clinic continues using its pre-printed BIR Service Invoice booklet.
    The system records the official document number and transaction details but
    does not claim to replace or independently issue that official document.
15. The clinic's Patient Health Record and printed prescription are filed
    together in a physical patient folder. The redesigned prints may improve
    the legacy dimensions and layout.
16. A product complaint starts a new appointment/intake/encounter flow linked
    to the earlier job order; it never overwrites the original encounter.
17. The physical Service Invoice is recorded at dispensing.
18. Normal clinic hours are 9:00 AM to 5:00 PM every day. Administrators and
    optometrist-capable users manage clinic and provider schedules.
19. Existing application data is seed/demo data only. It may be discarded and
    fully replaced; no production-data backfill is required.
20. The physical patient folder contains the custom Patient Health Record, the
    finalized prescription, and the autorefractor machine's paper result.
21. The web panel presents the clinic's Patient Health Record as one cohesive
    workspace containing appointment type, referral source when applicable,
    patient information, complaints, and medical history. The workspace
    composes Appointment and Intake data without merging their persistence.
22. Preferred-optometrist requests are excluded from the initial redesign.
    Patients choose a clinic slot, and the clinic controls the actual
    optometrist assignment.
23. Optometrist-capable admin/staff users inherit the receptionist's normal
    operational capabilities in addition to their clinical capabilities.

## Objective

Rework the Laravel backend and Filament panel so the software mirrors the
clinic's real clinical and fulfillment process, helps the two optometrists run
the day, and supports a receptionist without granting clinical authorship.

The redesign should:

- make the patient and their longitudinal chart the center of the system;
- separate scheduling from actual care;
- preserve a trustworthy history of submitted intake, encounters, and
  optometrist-authored prescriptions;
- replace direct patient ordering with frame browsing and fitting reservations;
- introduce quotations and clinic-created job orders;
- track the clinic's physical Service Invoice and installment/down-payment
  balances accurately;
- support everyday opening hours, individual provider availability, and
  date-specific closures or early closing;
- support the clinic's existing physical filing workflow during transition;
- replace inaccurate demo data with a coherent seed dataset that demonstrates
  the approved clinic workflow.

## Users and Success Outcomes

### Patient

A patient can book or manage appointments, submit intake, browse and reserve
frames for fitting, view finalized records and operational statuses, receive
messages, and submit eligible feedback. A patient cannot purchase products,
author clinical records, or see internal notes.

### Receptionist / Non-optometrist Staff

A receptionist can register patients, schedule and check in visits, verify
submitted intake, manage reservations, prepare commercial records from
optometrist instructions, record invoice and payment information, coordinate
dispensing, and communicate with patients. A receptionist cannot finalize
clinical encounters or prescriptions.

### Optometrist

An optometrist can perform all normal clinic operations plus start and complete
encounters, review health history, record findings, finalize prescriptions,
direct quotation contents, handle complaints, and print clinical records.

### Administrator

An administrator controls accounts, clinic/provider schedules, catalog and
pricing configuration, discounts, official-document settings, audit access,
and destructive or corrective actions. An administrator who is also an
optometrist receives clinical capabilities through `is_optometrist`.

## Tech Stack

- PHP 8.5
- Laravel 13
- Filament 5
- Livewire 4
- Laravel Sanctum 4
- MySQL through Laravel Sail
- Pest 4 and PHPUnit 12
- Tailwind CSS 4 and Vite 8

No new package is required by this specification.

## Commands

All project commands must run through Sail.

```bash
vendor/bin/sail up -d
vendor/bin/sail artisan migrate
vendor/bin/sail artisan migrate:fresh --seed
vendor/bin/sail artisan route:list --except-vendor
vendor/bin/sail artisan test --compact tests/Feature/Patients
vendor/bin/sail artisan test --compact tests/Feature/Appointments
vendor/bin/sail artisan test --compact tests/Feature/Encounters
vendor/bin/sail artisan test --compact tests/Feature/Quotations
vendor/bin/sail artisan test --compact tests/Feature/JobOrders
vendor/bin/sail artisan test --compact tests/Feature/Invoices
vendor/bin/sail artisan test --compact tests/Feature/Api
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail npm run build
```

Test paths shown above are target suite locations and may be introduced
incrementally during implementation.

## Project Structure

The redesign should follow the existing Laravel and Filament structure.

```text
app/
  Actions/
    Appointments/        scheduling, availability, check-in
    Encounters/          start, complete, and protect clinical encounters
    Intakes/             submit, verify, and snapshot intake
    Prescriptions/       finalize and print prescriptions
    Reservations/        frame fitting reservations
    Quotations/          calculate, present, accept, decline, expire
    JobOrders/           create from accepted quotation and advance work
    Invoices/            issue system record, add payment, recalculate balance
    Complaints/          link complaint case to a fresh visit
  Filament/Resources/    operational resources grouped by clinic workflow
  Http/Controllers/Api/ patient-mobile endpoints only
  Http/Requests/Api/     boundary validation and authorization
  Http/Resources/        stable mobile response contracts
  Models/                domain records and relationships
  Policies/              record and clinical-capability authorization
database/
  factories/             valid domain test fixtures
  migrations/            canonical development schema for fresh rebuilds
  seeders/               statuses, appointment types, and demo data
resources/views/
  pdf/                   prescription and internal transaction copies
  print/                 clinic health-record and prescription templates
routes/
  api.php                current API and temporary migration boundary
  web.php                authenticated print/download routes
tests/Feature/
  Api/
  Appointments/
  Encounters/
  Patients/
  Quotations/
  JobOrders/
  Invoices/
  Filament/
docs/
  BACKEND_CONTEXT.md     implemented behavior only
  specs/                 reviewed target specifications
```

New top-level directories require approval. The exact number of action classes
is an implementation decision; actions must remain single-purpose.

## Domain Language

| Term | Meaning |
|---|---|
| Account | Authentication identity in `users`; may be clinic personnel or a patient login |
| Patient | Independent person receiving care, whether or not they have an account |
| Appointment | Booking or queue entry; may be cancelled or missed and does not prove care occurred |
| Intake | Patient-supplied demographics, complaint, and health history for a planned/current visit |
| Encounter | Clinical visit that begins at check-in and records care actually delivered |
| Prescription | Final optometrist-authored optical prescription linked to an encounter |
| Frame reservation | Request to prepare selected frames for in-clinic fitting; not a sale |
| Quotation | Price proposal discussed with the patient; no inventory or payment commitment |
| Job order | Clinic-created fulfillment/work record based on an accepted quotation |
| Invoice | System record of the clinic's physical Service Invoice and its financial state |
| Payment | Posted amount against an invoice, including down payments and later installments |
| Dispensing | Release of completed eyewear/items to the patient |
| Complaint | New case linked to an earlier dispensed job order and handled through a fresh visit |

The following user-facing terms are deprecated:

- `customer` -> `patient`
- patient-created `order` -> removed
- clinic fulfillment `order` -> `job order`
- `billing` -> `invoice`
- mobile `product catalog` -> `frame catalog`

## Roles and Authorization

### Account Model

The roles are:

- `admin`
- `staff`
- `patient`

`is_optometrist` may be true only for `admin` or `staff`. Clinical authorization
must check this capability rather than assuming every panel user is a clinician.

| Capability | Admin | Staff | Optometrist capability | Patient |
|---|---:|---:|---:|---:|
| Access Filament panel | Yes | Yes | Inherits account role | No |
| Manage appointments/walk-ins | Yes | Yes | Yes | Own via API |
| Verify submitted intake | Yes | Yes | Yes | Submit own |
| View submitted health history | Yes | Yes, operational need | Yes | Own submitted data |
| Start/complete encounter | Only if optometrist | No | Yes | No |
| Author/finalize prescription | Only if optometrist | No | Yes | No |
| Prepare quotation/job order | Yes | Yes | Yes | View only |
| Record invoice/payment | Yes | Yes | Yes | View own |
| Manage clinic/provider schedules | Yes | View only | Yes | View availability |
| Manage accounts/settings | Yes | No | No unless admin | Own account only |
| Full chart export | Yes | No | Yes | No direct full export |
| View audit logs | Yes | No | No unless admin | No |

Laravel model policies must protect records. Filament custom actions and API
endpoints require explicit authorization because built-in resource policy checks
do not automatically cover custom behavior.

Clinical reads, prints, downloads, exports, finalizations, and corrections must
create audit entries with actor, patient, record, action, and timestamp.

## Core Data Model

The names below express required domain boundaries. Exact migration filenames
and low-level column ordering belong in the implementation plan.

### Accounts and Patients

`users` stores login accounts only:

- name
- login email
- password
- role
- `is_optometrist`
- account/contact metadata needed for authentication and notifications

`patients` stores the independent clinical identity:

- clinic-generated patient number
- full name
- date of birth
- occupation
- address
- gender: `male`, `female`, `other`
- contact email
- phone
- optional linked patient-account user ID
- archive timestamps and audit timestamps

The initial account link is nullable and unique. Walk-ins can therefore have a
patient record without credentials. Future family management can replace the
unique link with an account-to-patient relationship without changing foreign
keys from appointments, encounters, prescriptions, job orders, or invoices.

All domain records that currently use `customer_id` must eventually reference
`patient_id`. Login authorization resolves the authenticated patient account to
its linked patient record.

### Appointment Types

The initial appointment types are:

- New Patient
- Follow-up
- Routine Check-up
- Referral

Each type has a configurable duration and active state. Appointment type is
scheduling vocabulary; it must not be inferred from whether the patient has a
prior system record.

### Appointments

An appointment stores:

- generated appointment number
- patient
- appointment type
- requested/scheduled start and calculated end
- source: mobile app, walk-in, phone call, Messenger, or staff-created
- optional assigned optometrist
- status
- check-in/completion timestamps
- operational contact and rescheduling notes
- created/check-in actors

Appointment lifecycle:

```text
pending -> confirmed -> arrived -> completed
   |          |           |
   +----------+-----------+-> cancelled
              +--------------> no_show
```

Check-in changes the appointment to `arrived` and creates the encounter
transactionally. Completing the encounter completes the appointment. A
cancelled or no-show appointment never creates an encounter.

The primary Filament entry point is `Appointments -> Today's Queue -> Check In`,
with the same action available in the Appointment detail header. It is shown
only for an eligible pending or confirmed appointment. Its confirmation modal
shows the patient, appointment type, verified-intake status, a link to the
combined Patient Health Record, and the clinic-controlled optometrist
assignment. A successful action moves the appointment to `arrived`, creates
exactly one waiting Encounter, and exposes `Open Encounter`. The generic
`Mark Arrived` status mutation must not remain as a bypass.

### Clinic and Provider Availability

Scheduling requires database-managed configuration for:

- normal opening and closing time for every weekday, initially 9:00 AM to
  5:00 PM on Monday through Sunday;
- clinic date overrides: closed, changed opening, or early closing;
- recurring working hours for each optometrist;
- provider date overrides such as leave or shortened availability;
- slot generation interval;
- appointment-type duration.

Patients select a clinic slot only. Availability is true when at least one
optometrist can cover the entire appointment duration, even if no provider has
yet been assigned. Staff may assign a provider before or at check-in.

Administrators and optometrist-capable users may manage clinic hours, clinic
date overrides, and provider schedules. Non-optometrist staff may view the
configuration and resolve affected appointments but may not change provider
availability.

Creating a closure or early-closing override:

1. immediately removes affected times from new availability;
2. never cancels or moves existing appointments automatically;
3. lists every affected appointment prominently for staff;
4. requires an explicit reschedule or cancellation with reason;
5. records who created the override and resolved each conflict.

### Patient Intake / Health Record

An intake begins as a patient-editable draft associated with the patient and,
when available, the appointment. It contains:

- appointment type snapshot;
- patient demographic snapshot;
- chief complaint;
- past ocular history;
- past surgical history;
- past medical history;
- allergies;
- medications;
- submission and verification timestamps;
- submitting and verifying actors.

The mobile patient may edit the draft before check-in. Staff can help enter or
correct patient-supplied information and must preserve attribution.

At check-in, the verified intake is attached to the new encounter. Once the
encounter is completed, the intake snapshot becomes immutable. A later visit
prefills a new draft from the latest relevant information but never modifies
the historical snapshot.

Sensitive history fields must use encrypted storage columns. Because encrypted
values are not queryable, the system must not promise full-text search across
complaints or medical history.

#### Combined Patient Health Record Workspace

The Filament panel provides one Patient Health Record workspace matching the
clinic's familiar paper record. It composes:

- appointment type and the referring person/source when applicable from the
  Appointment;
- the patient demographic snapshot from the Intake;
- chief complaint, ocular and surgical history, medical history, allergies,
  and medications from the Intake.

This is a presentation boundary, not a denormalized database record.
Appointment, Intake, and Encounter remain separate domain models. The workspace
must not duplicate appointment type as independently editable free text.

Optometrist-capable users can view the complete Patient Health Record and
navigate to the related Encounter and Prescription. Non-optometrist staff may
access patient-supplied intake only when needed to collect or verify the form;
they cannot see or author optometrist-only findings, prescription values, or
amendments. Access and changes to sensitive intake data are audited.

### Encounters

An encounter stores:

- generated encounter number;
- patient;
- originating appointment when present;
- treating optometrist;
- verified intake snapshot;
- status: `waiting`, `in_progress`, `completed`, or `cancelled`;
- started and completed timestamps;
- optometrist-only findings and remarks;
- links to prescriptions, quotations, complaints, and print events.

Only an optometrist can start, clinically edit, complete, or correct an
encounter. A completed encounter is not silently editable. Corrections must be
recorded as an amendment with author, timestamp, reason, previous value, and new
value.

Raw autorefractor result fields and structured machine imports are explicitly
excluded.

### Prescriptions

A final prescription belongs to one patient, one encounter, and one prescribing
optometrist. It includes:

- patient name snapshot;
- prescribed date;
- OD and OS sphere;
- OD and OS cylinder;
- OD and OS axis;
- OD and OS add where applicable;
- PD and existing prism/base fields where clinically used;
- remarks;
- optional expiry date;
- finalization timestamp and author.

Only an optometrist can finalize or amend a prescription. A job order requiring
corrective lenses must reference a finalized prescription from the same patient.

Prescription values and remarks remain encrypted at rest. Finalized
prescriptions can be viewed by the linked patient account and printed by
authorized clinic users.

### Frame Catalog and Fitting Reservations

The mobile catalog exposes active frame products and the active variants the
clinic chooses to show. It may include:

- frame name, brand, category, images, color/size attributes;
- display price or price range when configured;
- AR asset for eligible variants;
- reservation availability indicator.

It does not expose optical lenses, contact lenses, accessories, supplier cost,
internal stock quantities, or checkout actions.

A fitting reservation stores:

- patient;
- appointment;
- selected frame variants;
- requested timestamp;
- status: `requested`, `prepared`, `tried_on`, `converted`, `released`, or
  `cancelled`;
- expiry/release timestamp;
- staff notes hidden from the patient.

A reservation is not a quotation, order, invoice, payment, or guarantee of a
final price.

Recommended inventory behavior:

1. `requested` records patient interest but does not hold stock.
2. Staff verifies availability and changes the reservation to `prepared`.
3. `prepared` creates a reversible allocation for one physical frame unit.
4. The allocation expires at clinic closing on the appointment date unless
   staff explicitly extends it.
5. Appointment cancellation, no-show, reservation cancellation, or expiry
   releases the allocation automatically.
6. Converting the reservation into a job order consumes the allocation in the
   same inventory transaction so the unit is not deducted twice.

This avoids allowing unattended mobile requests to lock scarce frames while
still preventing a staff-confirmed fitting frame from being promised twice.

### Quotations

A quotation belongs to a patient and encounter and may reference a finalized
prescription. It stores:

- generated quotation number;
- revision number;
- status: `draft`, `presented`, `accepted`, `declined`, or `expired`;
- validity date;
- line-item price snapshots;
- subtotal, discounts, and estimated total;
- patient-visible notes;
- internal notes;
- prepared/presented/accepted actors and timestamps;
- acceptance method and optional acknowledgement reference.

Quotation items can represent:

- selected frame variant;
- recommended lens category/type and options;
- contact lens or accessory selected in clinic;
- clinical or fitting service;
- a custom item with an explicit description.

Presenting a quotation freezes that revision's line items and prices. Changes
after presentation create a new revision. Acceptance freezes the accepted
revision. A quotation does not deduct inventory, create a payment, or itself
become an invoice.

Primary acceptance is recorded by clinic staff after in-person discussion. A
patient can leave without deciding, and staff can later mark the quotation
accepted, declined, or expired. Mobile approval is a future option, not an
initial requirement.

### Job Orders

A job order may be created only by an authorized clinic user from an accepted
quotation. It stores:

- generated job-order number;
- patient, encounter, prescription, and accepted quotation revision;
- immutable item and price snapshots;
- lens type/options and fulfillment notes;
- status;
- promised/estimated completion date when known;
- created, started, ready, dispensed, and cancelled timestamps;
- responsible actors;
- optional official invoice reference once recorded.

Job-order lifecycle:

```text
queued -> in_progress -> ready_for_dispensing -> dispensed
  |            |                 |
  +------------+-----------------+-> cancelled
```

Creating the job order is the inventory commitment point. It must validate
frame/lens availability and perform stock movements in one database
transaction. Cancelling a committed, undispensed job order reverses eligible
inventory movements with an audit trail.

Patients never create job orders. They may view patient-safe status and
estimated completion information.

### Invoices and Payments

The system invoice record represents a physical pre-printed Service Invoice.
It must distinguish:

- an internal immutable system reference;
- the manually entered official Service Invoice number from the booklet.

The official number must be unique once supplied. It must never be generated
from the application's normal billing-number sequence unless the clinic later
obtains written accounting/tax approval for that design.

A system invoice may exist in `draft` state before dispensing so the clinic can
track deposits and the expected balance. That draft is not the issued Service
Invoice and has no official booklet number. At dispensing, staff writes the
physical Service Invoice, enters its official number, and changes the system
record from `draft` to `issued` in the same workflow. Earlier recorded deposits
then appear in its payment history and remaining-balance calculation.

An invoice stores:

- patient and optional job order/encounter;
- official invoice number;
- issue date;
- sale type: cash sale or charge sale;
- sold-to name;
- optional registered name, TIN, and business address;
- item/service descriptions, quantity, unit price, and amount;
- VAT/tax summary fields needed to transcribe the physical invoice;
- eligible discount type, ID reference, and discount amount;
- subtotal, total, amount paid, and remaining balance;
- payment terms and notes;
- issuing/recording actor and timestamps;
- status: `draft`, `issued`, `partially_paid`, `paid`, or `voided`.

Payments are append-only financial events. Corrections void and replace a
payment rather than overwriting its amount. Down payments and installments are
supported; each posted payment recalculates amount paid and balance.

Clinic business name, proprietor, address, VAT/TIN, and current authority-to-
print metadata are configuration values with change history. Values printed on
an invoice copy must be snapshotted so later settings changes do not rewrite
history.

The system may print an internal transaction copy clearly identified as a
system copy. It must not label that output as the independently issued official
document while the pre-printed booklet remains authoritative.

### Dispensing

Dispensing requires:

- job order status `ready_for_dispensing`;
- physical Service Invoice issuance and official-number entry;
- dispensing actor and timestamp;
- recipient name when different from the patient;
- optional patient acknowledgement;
- invoice/payment visibility without requiring a zero balance, because the
  clinic permits agreed installment terms;
- notes about fit, release, or balance terms.

Dispensing issues the invoice record, changes the job order to `dispensed`, and
enables verified product-rating eligibility. The job order and invoice changes
must commit in one database transaction.

### Complaints and Rework

A complaint stores:

- patient;
- original dispensed job order and relevant item;
- complaint date and patient description;
- status and resolution notes;
- new follow-up appointment and encounter;
- any resulting quotation, job order, or invoice.

Creating a complaint must start or schedule a new visit. The new encounter uses
the normal intake, examination, prescription, quotation, and job-order rules.
The earlier prescription, encounter, job order, and invoice remain unchanged.

If the resolution is free rework, the new quotation/job order may total zero
but must still record inventory and fulfillment effects. If the clinic charges
for the resolution, it receives its own invoice relationship.

### Feedback and Product Ratings

Keep two concepts separate:

- clinic/visit feedback after a completed encounter;
- verified product rating after the related job-order item is dispensed.

A patient may submit at most one active rating per dispensed job-order item.
Ratings use 1–5 stars with an optional comment and are labeled as verified
because eligibility comes from a dispensed item.

Rating integrity rules:

- verified ratings publish automatically; there is no staff approval queue;
- staff and administrators cannot edit a star value or patient comment;
- a patient may amend their own rating, but every revision is retained and the
  public view indicates that it was edited;
- a comment may be hidden only for a documented privacy, abuse, or safety
  reason under a written moderation policy;
- hiding a comment requires a reason, actor, and timestamp and does not remove
  its star value from aggregate calculations;
- the public frame view shows total rating count and rating distribution so the
  clinic cannot selectively present only favorable results;
- deleted/hidden content and all moderation events remain available to
  authorized auditors.

Private clinic/visit feedback remains visible only to clinic users unless the
patient separately submits a public product rating.

## Workflow Requirements

### Scheduled Patient

1. Patient or staff creates an appointment.
2. Patient completes or updates the intake draft.
3. Staff confirms the booking.
4. Patient arrives; staff verifies intake and checks the patient in.
5. Check-in creates a waiting encounter.
6. An available optometrist is assigned and starts the encounter.
7. Optometrist reviews history, examines the patient, and finalizes any
   prescription.
8. Clinic discusses products/options and prepares a quotation.
9. Staff records the patient's acceptance, decline, or later decision.
10. Accepted quotation may create a job order.
11. Staff may record deposits against the draft financial record.
12. Staff advances the job order until it is ready.
13. At dispensing, staff writes the physical Service Invoice, records its
    official number, carries forward deposits, and releases the job order.

### Walk-in Patient

1. Staff finds or creates an independent patient record.
2. Staff creates a same-day walk-in appointment/queue record.
3. Patient completes intake with staff assistance if needed.
4. Staff verifies intake and checks the patient in.
5. The normal encounter-through-dispensing workflow applies.

### Complaint

1. Staff records a complaint against a dispensed job order/item.
2. Staff creates a follow-up appointment or same-day walk-in.
3. A fresh intake and encounter are created.
4. Optometrist re-examines and issues a new prescription only when clinically
   necessary.
5. Any rework uses a new quotation and job order linked to the complaint.
6. The original records remain available for comparison and audit.

## Web Panel Information Architecture

The panel should prioritize clinical operations for optometrists.

Recommended navigation:

```text
Today
  Today's Clinic
  Appointments
  Waiting / In Progress

Patients & Clinical
  Patients
  Encounters
  Prescriptions
  Complaints

Fulfillment & Finance
  Frame Reservations
  Quotations
  Job Orders
  Invoices

Catalog & Inventory
  Products
  Inventory History
  Brands / Categories / Lens Options / Services

Communication
  Conversations
  Feedback & Ratings
  SMS Log

Reports
Administration
Settings
```

The primary dashboard must answer:

- Who is expected today?
- Who is waiting?
- Which patient is currently being examined by each optometrist?
- Which quotations await a decision?
- Which job orders are due or ready for dispensing?
- Which appointments were affected by a closure or provider absence?
- Which invoices have an outstanding balance?

Sales and inventory metrics remain secondary to today's patient flow.

## Mobile Application Scope

Recommended primary areas:

- Home
- Appointments
- Frames
- Records
- Messages
- Account

The mobile app may:

- show clinic open/closed state and available slots;
- create, view, reschedule, and cancel the patient's appointments;
- submit intake for an upcoming appointment;
- browse frame-only catalog entries and use AR where supported;
- reserve frames for fitting against an appointment;
- view finalized prescriptions;
- view presented/accepted quotations without approving them;
- view job-order status;
- view invoice details, recorded payments, and balance;
- receive appointment, quotation, job-order, and balance notifications;
- message clinic users;
- submit eligible feedback and ratings.

The mobile app must not:

- create product orders or job orders;
- directly buy frames, lenses, contact lenses, or accessories;
- select or prescribe optical lenses;
- choose a specific optometrist during booking;
- edit a finalized intake snapshot or prescription;
- see optometrist-only findings, internal notes, supplier cost, or stock counts;
- record payments in the initial redesign.

## Mobile API Contract Direction

This redesign is intentionally incompatible with the current customer-ordering
contract. The system is not deployed, so no compatibility window or second
live API is required. Introduce `/api/v1` as the single stable patient API and
update the separate Android client in a later product-integration program
before release. The Android repository location is not yet known.

Target resource surface:

```text
POST   /api/v1/register
POST   /api/v1/login
GET    /api/v1/me
PATCH  /api/v1/me

GET    /api/v1/appointment-types
GET    /api/v1/appointment-availability
GET    /api/v1/appointments
POST   /api/v1/appointments
GET    /api/v1/appointments/{appointment}
POST   /api/v1/appointments/{appointment}/reschedule
POST   /api/v1/appointments/{appointment}/cancel

GET    /api/v1/appointments/{appointment}/intake
PUT    /api/v1/appointments/{appointment}/intake
POST   /api/v1/appointments/{appointment}/intake/submit

GET    /api/v1/frames
GET    /api/v1/frames/{frame}
GET    /api/v1/frame-reservations
POST   /api/v1/frame-reservations
POST   /api/v1/frame-reservations/{reservation}/cancel

GET    /api/v1/prescriptions
GET    /api/v1/prescriptions/{prescription}
GET    /api/v1/quotations
GET    /api/v1/quotations/{quotation}
GET    /api/v1/job-orders
GET    /api/v1/job-orders/{jobOrder}
GET    /api/v1/invoices
GET    /api/v1/invoices/{invoice}

GET    /api/v1/conversation
GET    /api/v1/conversation/messages
POST   /api/v1/conversation/messages

POST   /api/v1/feedback
POST   /api/v1/job-order-items/{item}/rating
```

All list endpoints are paginated. All resource access is scoped through the
authenticated account's patient link. Error responses must use one consistent
machine-readable code plus the normal Laravel validation details.

The existing unversioned customer-ordering routes are removed after their
replacement `/api/v1` frame-reservation and read-only workflow routes pass
contract tests. No usage-measurement or legacy compatibility period is required
because neither the backend nor Android client is deployed.

## Printing and Physical Records

### Patient Health Record

The system uses an improved A5 landscape layout, the closest standard size to
an A4 sheet cut in half crosswise, rather than copying the clinic's custom paper
dimensions exactly. It must support both a blank form for handwriting and a
populated encounter copy. The same template must also print cleanly on A4 when
the printer cannot feed A5 paper.

Layout:

1. Centered clinic header:
   - POC logo and Padilla Optical Clinic name;
   - configurable clinic address, phone numbers, and current operating hours;
   - bold centered `PATIENT HEALTH RECORD` title.
2. `Type of Appointment` section with visible selected/unselected options:
   - New Patient;
   - Follow-up;
   - Routine Check-up;
   - Referral, including the referring person/source.
3. `Patient Information` section:
   - full-width name;
   - date of birth and gender options;
   - occupation and email;
   - full-width address.
4. `Complaints` section with readable full-width areas for:
   - chief complaint;
   - past ocular history;
   - past surgical history;
   - past medical history;
   - allergies;
   - medications.
5. Footer with patient number, encounter number, treating optometrist,
   print timestamp, and an acknowledgement/signature area where operationally
   required.

Section headings may retain the clinic's rounded blue pill treatment, but the
print must prioritize contrast, readable type, writing space, and one-page
output over exact visual duplication.

Header contact information and operating hours come from versioned clinic
settings. The old form's Monday–Saturday text must not be hardcoded because the
approved schedule is 9:00 AM–5:00 PM every day.

The physical chart packet is expected to contain:

- the clinic-format Patient Health Record;
- the finalized printed prescription;
- the original paper output from the autorefractor machine.

The system records a simple packet checklist indicating whether each expected
paper is present. It does not digitize or structure the autorefractor result in
the initial redesign.

### Prescription

Authorized users can print the finalized prescription using an improved compact
A5 portrait layout, with printer scaling to A4 when needed.

Layout:

1. Centered clinic header using the same configurable logo, name, address,
   phones, and current operating hours as the Health Record.
2. Solid divider followed by patient name, prescription date, prescription
   number, and encounter reference.
3. Prominent bordered optical-measurement grid:
   - distance rows for O.D. and O.S.;
   - SPH and `CX` values for each eye;
   - separate `ADD` area with O.D./O.S. SPH and `CX` values;
   - PD, prism, base, or axis values only when the optometrist uses them.
4. Two full-width remarks lines.
5. Bottom-right practitioner signature, printed optometrist name, license
   number, and stamp area.

The clinic confirmed on 2026-07-26 that `CX` means cylinder. The prescription
print therefore binds the O.D. and O.S. `CX` cells to their corresponding
cylinder values. Axis remains a separate value when used and must not be
combined into `CX`.

### Invoice Copy

The system may print an internal copy containing the transcribed Service
Invoice data and payment/balance trail. It must clearly distinguish the system
copy from the physical official document.

Every clinical or financial print/download records an audit event.

### Physical Folder Access

Because the clinic retains paper patient folders, authorized users must record:

- patient folder checked out and returned;
- actor, date/time, purpose, and current location;
- whether any copy was made and why;
- missing packet items or damaged/misfiled records.

This log complements digital access auditing and does not replace the clinic's
physical controls such as locked storage and restricted work areas.

## Privacy and Data Protection Compliance Requirements

Health and medical records are sensitive personal information under the
Philippine Data Privacy Act. The software must support the clinic's compliance
with the Data Privacy Act of 2012, its Implementing Rules and Regulations, and
current National Privacy Commission issuances. Software controls alone do not
establish legal compliance; the clinic remains responsible for its policies,
lawful bases, personnel practices, contracts, and regulatory obligations.

Authoritative sources reviewed on 2026-07-25:

- [Republic Act No. 10173 — Data Privacy Act of 2012](https://privacy.gov.ph/data-privacy-act/)
- [Implementing Rules and Regulations of the Data Privacy Act](https://privacy.gov.ph/implementing-rules-regulations-data-privacy-act-2012/)
- [NPC Circular No. 2023-06 — Security of Personal Data in the Government and Private Sector](https://privacy.gov.ph/wp-content/uploads/2024/03/NPC-Circular-Repeal-16-01-Signed.pdf)
- [NPC guidance on appointing a Data Protection Officer](https://privacy.gov.ph/appointing-a-data-protection-officer/)
- [NPC data-subject rights guidance](https://privacy.gov.ph/data-subject-rights/)
- [NPC breach-reporting guidance](https://privacy.gov.ph/pips-and-pics/breach-reporting/)
- [NPC guidance on protecting patient data](https://privacy.gov.ph/npc-phe-bulletin-no-10-protecting-patient-data-from-unauthorized-disclosure/)

### Governance Before Production

The clinic must:

- designate and register its Data Protection Officer as applicable;
- determine and complete any required registration of its data processing
  systems with the NPC;
- maintain an inventory/record of processing activities covering digital
  records, paper folders, backups, messages, SMS, and service providers;
- complete a Privacy Impact Assessment before production and update it for
  major features, new processors, or material processing changes;
- adopt a Privacy Management Program, access-control policy, acceptable-use
  policy, retention policy, incident-response plan, and business-continuity
  plan;
- train all administrators, optometrists, and staff on patient-data handling;
- bind hosting, SMS, backup, support, or other processors through contracts that
  require appropriate data-protection safeguards.

The production launch is blocked until a named clinic owner confirms these
organizational requirements. The development team must not represent the
application as “DPA compliant” based only on passing technical tests.

### Transparency, Purpose, and Data Minimization

- Present a clear privacy notice at registration and intake before collection.
- Record the notice version acknowledged by the patient.
- State the categories collected, specific purposes, lawful bases, recipients,
  retention periods, data-subject rights, and DPO contact channel.
- Record consent only for processing that actually relies on consent; do not
  use a single bundled checkbox as the basis for all healthcare processing.
- Collect only fields needed for care, scheduling, fulfillment, finance, or a
  documented legal obligation.
- Do not digitize raw autorefractor output merely because it exists on paper.
- Do not expose patient names or health information with public ratings.

### Least-privilege Access

- Mobile accounts can access only their linked patient's permitted records.
- Receptionists receive only the health-history access needed to verify intake;
  optometrist-only findings remain restricted.
- Clinical authorship requires `is_optometrist`.
- Policies authorize view, update, finalize, print, export, correct, and
  moderate separately.
- Custom Filament actions enforce authorization explicitly.
- Staff/admin online access to sensitive patient data requires multifactor
  authentication before production.
- Sessions use secure cookies/tokens, inactivity timeout, revocation, login
  throttling, and device/session visibility.
- Clinic workstations use automatic screen locking and should be positioned or
  shielded to prevent casual viewing.

### Confidentiality, Integrity, and Availability

- Health history, findings, prescription values, and sensitive clinical remarks
  use encrypted `TEXT` columns or larger.
- All production traffic uses HTTPS/TLS.
- Backups containing personal data are encrypted, access-controlled, and
  covered by tested restoration procedures.
- Encryption-key backup and rotation procedures must prevent historical health
  data from becoming unreadable.
- Clinical attachments, if later approved, use private storage, file-type and
  size validation, malware controls where available, and authorized download
  routes.
- Completed clinical snapshots, accepted quotations, committed job orders,
  issued invoices, and posted payments are not directly overwritten.
- Corrections use amendments, void/replacement events, or a new revision with
  actor, time, reason, and before/after values.
- Audit records identify the action without unnecessarily copying decrypted
  health content.
- Security, audit, and backup-restoration tests are part of release validation.

### Audit and Physical Records

Audit:

- digital record reads that expose clinical data;
- clinical and financial creation/finalization/correction;
- print, download, and export;
- role/capability and schedule changes;
- rating revisions and moderation;
- physical folder checkout, return, location, and copying.

Paper folders must remain in locked, access-controlled storage when not in use.
The clinic must regularly review the physical-file access/copy log.

### Retention and Data-subject Rights

- The clinic must approve category-specific retention periods before production;
  the system must not assume medical records are retained forever.
- Retention rules cover live data, archives, logs, backups, printed exports, and
  physical folders.
- Secure disposal must make reconstruction or further processing impracticable,
  including shredding authorized paper records and securely deleting digital
  copies after the approved period.
- Provide an authenticated request workflow for the rights to be informed,
  access, rectification, objection, erasure/blocking, portability, and complaint
  where applicable.
- Identity and authority are verified before releasing records, including
  parent/guardian or lawful-representative requests.
- Rectification adds a traceable correction; it does not silently rewrite
  finalized clinical history.
- Erasure/blocking requests are evaluated against applicable healthcare,
  accounting, tax, legal-hold, and other retention duties. The system records
  the decision and reason rather than automatically deleting a chart.

### Incident and Breach Response

- Maintain an incident register with discovery time, affected systems/data,
  containment, assessment, decisions, notifications, and resolution.
- Preserve relevant logs and evidence.
- Immediately notify the DPO and designated incident team.
- Support the clinic in meeting applicable NPC and data-subject notification
  duties, including the 72-hour period when mandatory-breach criteria are met.
- Never expose patient data, secrets, or stack traces in application logs or
  error responses.

## Reset and Replacement Requirements

The application is not deployed and contains seed/demo data only. The project
owner explicitly authorizes replacing that data. Implementation must not spend
time building compatibility backfills for fictional customer orders, billings,
or patient histories.

### Schema Changes

- Consolidate or replace undeployed development migrations when that produces a
  clearer canonical schema; no deployed migration may be edited.
- Remove superseded order/billing structures after replacement tests exist
  because no production records depend on them.
- Migrations remain reversible at the schema level where technically practical;
  rollback does not need to reconstruct discarded demo records.
- Use `vendor/bin/sail artisan migrate:fresh --seed` as the authoritative
  development reset after the new schema and seeders are ready.
- Do not run the reset until the implementation plan reaches its explicit
  database-reset checkpoint.

### Seed-data Replacement

Replace all old customer, direct-ordering, billing, and scheduling scenarios.
The new seed dataset must include:

- administrator who is also an optometrist;
- second optometrist with a staff account;
- receptionist with staff role and no optometrist capability;
- mobile patient with a linked account;
- walk-in patient without an account;
- 9:00 AM–5:00 PM operating hours for every weekday;
- provider availability and at least one provider absence;
- an early-closing date with an affected appointment;
- each appointment type and representative lifecycle state;
- submitted intake, waiting/completed encounters, and finalized prescription;
- requested/prepared frame fitting reservations;
- draft/presented/accepted quotation revisions;
- queued/in-progress/ready/dispensed job orders;
- draft financial record with deposit and an issued physical-invoice record at
  dispensing with remaining balance;
- complaint-linked repeat encounter/rework;
- verified rating with visible revision/moderation audit behavior;
- physical chart packet checklist and folder access/copy events.

Seeded names, contact details, health histories, prescription values, invoice
numbers, and messages must be clearly fictional and reserved for development.

### API Replacement

1. Define and test the `/api/v1` patient contract.
2. Update the separate Android client contract before product release.
3. Remove direct patient-order creation and cancellation routes.
4. Remove old order-request validation, notifications, tests, and documentation
   only after replacement reservation/job-order flows are covered.
5. Keep clinic-created job ordering; remove only patient-created ordering.

No live-client usage measurement, deprecation window, or legacy-data read path
is required.

## Code Style

Use typed, single-purpose actions with explicit authorization at the boundary.
Controllers and Filament pages orchestrate actions rather than implementing
state transitions inline.

```php
final class CheckInPatient
{
    public function __construct(
        private readonly VerifyPatientIntake $verifyPatientIntake,
    ) {}

    public function handle(
        Appointment $appointment,
        User $actor,
    ): Encounter {
        Gate::forUser($actor)->authorize('checkIn', $appointment);

        return DB::transaction(function () use ($appointment, $actor): Encounter {
            $intake = $this->verifyPatientIntake->handle($appointment, $actor);

            return Encounter::query()->create([
                'patient_id' => $appointment->patient_id,
                'appointment_id' => $appointment->id,
                'patient_intake_id' => $intake->id,
                'status' => EncounterStatus::Waiting,
            ]);
        });
    }
}
```

Conventions:

- Use explicit parameter and return types.
- Use constructor property promotion where appropriate.
- Use TitleCase enum case names.
- Use Form Requests for API validation and authorization.
- Use model policies and explicit custom-action authorization.
- Use named arguments when they improve workflow readability.
- Use database transactions for multi-record state changes.
- Use generated clinic-facing references; users do not type internal IDs.
- Use descriptive `patient`, `encounter`, `quotation`, `jobOrder`, and `invoice`
  names rather than preserving misleading legacy vocabulary.
- Follow existing Filament v5 namespaces and sibling-resource conventions.

## Testing Strategy

All behavior changes require Pest tests written before or alongside
implementation. Use factories and the minimum focused Sail command during each
slice, then run the broader affected suites.

### Domain Tests

- Patients can exist without accounts.
- A patient account cannot access another patient's data.
- Optometrist capability is restricted to admin/staff accounts.
- Non-optometrist staff cannot author findings or finalize prescriptions.
- Completed intake and encounter snapshots cannot be directly overwritten.
- Amendments preserve before/after values and actors.

### Appointment and Schedule Tests

- Clinic operates on all configured weekdays, including Saturday and Sunday.
- Weekly hours and date overrides affect availability consistently.
- Provider leave reduces capacity without requiring patient provider selection.
- Different available optometrists can cover overlapping appointments.
- One optometrist cannot be double-booked.
- Early closure prevents new affected bookings.
- Existing affected bookings remain unchanged and appear in the resolution list.
- Check-in creates exactly one encounter under concurrent requests.
- Cancelled/no-show appointments do not create encounters.

### Clinical Tests

- Patient intake can be submitted before check-in.
- Staff may verify intake but cannot write clinical findings.
- Only an optometrist can start/complete encounters and finalize prescriptions.
- Finalized prescription belongs to the encounter's patient.
- Prescription-required job orders reject missing/unfinalized prescriptions.
- Sensitive columns are encrypted in raw database values.
- Raw autorefractor fields do not exist in the initial schema/API.

### Quotation and Job-order Tests

- Presenting a quotation freezes a revision.
- Editing a presented quotation creates a new revision.
- Only an accepted quotation can create a job order.
- Patient APIs cannot create quotations or job orders.
- Job-order creation commits inventory atomically.
- Cancellation reverses eligible inventory once.
- A requested fitting reservation does not hold stock.
- Preparing a fitting reservation creates one allocation and expiry/cancellation
  releases it exactly once.
- Converting a prepared reservation consumes its allocation without a second
  deduction.
- Dispensing enables rating eligibility.
- Complaint rework creates new records and leaves originals unchanged.

### Invoice and Payment Tests

- Internal reference and official invoice number are distinct.
- Official invoice number is unique when present.
- Multiple payments recalculate amount paid and balance correctly.
- Down payment leaves an invoice partially paid.
- Dispensing requires official Service Invoice number entry, carries forward
  deposits, and may retain an explicitly recorded remaining-balance term.
- Invoice issue and job-order dispensing commit atomically.
- Posted payments are voided/replaced rather than edited.
- Business-detail snapshots do not change when settings later change.
- System printout is identified as an internal copy.

### API and Filament Tests

- Mobile frame endpoint excludes lenses, contact lenses, and accessories.
- Mobile API exposes no direct order/job-order creation route.
- Reservations require ownership of the linked appointment.
- Quotations, job orders, invoices, and prescriptions are read-only to patients.
- Filament resources and custom actions enforce capability policies.
- Receptionist and optometrist navigation/actions differ appropriately.
- List endpoints are paginated and return stable resource shapes.
- Old unversioned direct-order routes are absent after replacement.
- Staff/admin sensitive-data access requires multifactor authentication.
- Verified ratings publish without staff approval.
- Staff cannot alter star values or comments.
- Hidden comments retain their rating in aggregates and create moderation audit.
- Rating totals and distribution match all verified current ratings.

### Print Tests

- Health Record and prescription print routes require authorization.
- Patient identifiers and required clinical values render.
- Internal invoice copy contains its official-number transcription when present.
- Every print/download writes an audit event.
- Health Record renders as one readable A5 landscape page in blank and
  populated modes and prints cleanly on A4 as a fallback.
- Prescription renders in A5 portrait and scales cleanly to A4.
- Physical packet checklist can record Health Record, prescription, and machine
  result paper without storing raw autorefractor values.
- Folder checkout, return, relocation, and copying create reviewable audit rows.

### Privacy and Security Tests

- Patient APIs cannot access another patient's record by changing an ID.
- Sensitive database values and backups are not stored as plaintext.
- Privacy notice version and acknowledgement are retained.
- Clinical reads, prints, exports, corrections, and capability changes are
  audited without copying decrypted health data into logs.
- Retention/rights requests are identity-verified and record their disposition.
- Production configuration rejects insecure transport and public clinical-file
  visibility.
- Backup restoration and incident-log workflows are testable.

### Fresh Database and Seeder Tests

- `vendor/bin/sail artisan migrate:fresh --seed` completes successfully.
- Seeded roles are exactly admin, staff, and patient.
- Seeded clinic hours are 9:00 AM–5:00 PM Monday through Sunday.
- Seeded optometrist, receptionist, linked patient, and account-less patient
  permissions match this specification.
- Seed data covers the approved end-to-end clinic and complaint workflows.
- No customer-role or patient-created order seed data remains.
- Seeded health, contact, invoice, and message values are visibly fictional.

## Boundaries

### Always

- Use `patient` in all new user-facing text and contracts.
- Keep accounts separate from clinical patient identities.
- Create an encounter only for an attended/check-in visit.
- Require optometrist capability for clinical authorship.
- Preserve immutable clinical and financial history.
- Use policies and explicit authorization for custom actions.
- Encrypt sensitive health fields and audit clinical/financial access.
- Apply privacy-by-design, privacy-by-default, data minimization, and
  least-privilege access.
- Complete the clinic's privacy governance and PIA launch gate.
- Keep the undeployed migration history coherent and verify a fresh seeded
  rebuild; no production-data backfill is required.
- Run changes and tests through Sail.
- Search version-specific Laravel/Filament documentation before code changes.
- Update `docs/BACKEND_CONTEXT.md` only as implementation slices become true.

### Ask First

- Adding a dependency or permission package.
- Supporting family/dependent account management now.
- Allowing mobile quotation approval or payments.
- Changing the physical invoice from authoritative to system-issued.
- Changing the appointment, quotation, job-order, or invoice state machines.
- Adding structured autorefractor imports or clinical file attachments.
- Importing any real patient data into the development environment.
- Changing retention periods, lawful bases, privacy-notice purposes, or
  production data processors.

### Never

- Let a patient create a job order or bypass an examination.
- Treat an appointment as proof that care occurred.
- Let non-optometrist staff finalize clinical findings or prescriptions.
- Rewrite completed intake, prescriptions, accepted quotations, issued
  invoices, or posted payments in place.
- Automatically cancel appointments when clinic hours change.
- Present the internal invoice copy as a newly issued official BIR document.
- Expose internal health notes, costs, or inventory quantities in the mobile app.
- Store health records on a public filesystem.
- Log decrypted health data, credentials, tokens, or full sensitive request
  payloads.
- Let clinic users edit or selectively exclude unfavorable verified ratings.
- Retain personal data indefinitely without an approved purpose and policy.
- Delete a still-valid test merely to make a migration or redesign pass.
- Keep tests that assert behavior explicitly removed from the approved
  specification after replacement coverage exists.
- Edit vendor files or existing deployed migrations.

## Success Criteria

The specification is successfully implemented when all of the following are
programmatically demonstrated:

1. Every operational record references an independent patient, and a walk-in
   patient can complete the full workflow without a login account.
2. Existing patient accounts authenticate with role `patient`, and no
   user-facing API/panel label says `customer`.
3. Mobile and staff bookings use everyday configurable hours, provider
   availability, and date overrides without letting patients choose providers.
4. Check-in creates one encounter; cancelled and no-show bookings create none.
5. Receptionists can verify intake and operate the front desk but cannot author
   or finalize clinical records.
6. Optometrists can complete a visit and produce a finalized, encrypted,
   printable prescription.
7. The clinic can print the approved improved A5 landscape Patient Health
   Record in blank and populated modes, use A4 as a printer fallback, and audit
   the print.
8. The mobile catalog contains frames only and provides fitting reservations
   without checkout or direct ordering; only staff-prepared reservations
   allocate stock and all allocations expire or convert safely.
9. A job order can only originate from an accepted quotation created after an
   encounter, with the required finalized prescription when applicable.
10. Inventory commits at job-order creation and reverses safely on eligible
    cancellation.
11. At dispensing, the system records the physical Service Invoice number
    separately from its internal reference, carries forward deposits, and
    accurately tracks installments and balance.
12. A ready job order can be dispensed under recorded balance terms and becomes
    eligible for a transparent verified product rating that clinic users cannot
    manipulate.
13. A complaint creates a linked new visit and rework flow without modifying
    the original encounter, prescription, job order, or invoice.
14. Physical chart packets track the Health Record, prescription, and
    autorefractor paper result, and all folder access/copy activity is logged.
15. The clinic completes the documented privacy governance launch gate, and
    technical tests demonstrate least privilege, encryption, MFA, audit,
    retention/rights workflow, backup restoration, and incident recording.
16. `migrate:fresh --seed` replaces all inaccurate demo data with the approved
    end-to-end workflow and contains no customer-role or patient-created orders.
17. The Android client operates entirely on `/api/v1`, and old unversioned
    direct-order routes are removed.
18. Focused and full Pest suites pass, Pint reports no outstanding formatting
    changes, and frontend assets build successfully.

## Open Questions

These do not change the approved domain direction, but the relevant
implementation or production-launch slice cannot close until each is answered.

1. Who will serve as the clinic's Data Protection Officer and own privacy
   requests, incident decisions, and NPC coordination?
2. What retention periods and lawful bases will the clinic approve for each
   category of health, appointment, financial, communication, audit, backup,
   and physical-folder data?

Recommended interim handling:

- Do not create a separate application role for the DPO. The clinic should
  formally designate a qualified person with sufficient independence,
  knowledge, time, and authority. The system stores the DPO's published contact
  details and routes privacy/incident cases to that person.
- Do not invent retention periods or automatically purge records during
  development. Build category-specific policy configuration, legal holds,
  retention-review dates, and secure-disposal records. Enable automated disposal
  only after the clinic's DPO and appropriate accounting/legal advisers approve
  the schedule.

## Specification Approval Gate

Before moving to implementation planning:

- [x] The clinic-workflow objective is approved.
- [x] Roles and clinical authorization boundaries are approved.
- [x] Domain language and state lifecycles are approved.
- [x] Mobile scope and removal of direct ordering are approved.
- [x] Patient/account separation and fresh-seed direction are approved.
- [x] Invoice boundary with the physical booklet is approved.
- [x] Success criteria are considered testable and complete.
- [x] Remaining privacy-governance questions are assigned for clinic follow-up.
