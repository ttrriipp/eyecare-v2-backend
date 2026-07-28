# Eyecare Mobile API v1 — Authoritative Contract

> **Backend version:** Current repository state (2026-07-28)
> **Base URL:** `/api/v1`
> **Auth:** Laravel Sanctum bearer tokens
> **Timezone:** `Asia/Manila` (configurable via `app.timezone`)
> **Timestamps:** ISO 8601 (`2026-07-27T10:00:00+08:00`)
> **Dates:** `Y-m-d` format (`2026-07-27`)

---

## Table of Contents

1. [Authentication](#1-authentication)
2. [Profile (me)](#2-profile-me)
3. [Appointment Types](#3-appointment-types)
4. [Appointment Availability](#4-appointment-availability)
5. [Appointments](#5-appointments)
6. [Patient Intake](#6-patient-intake)
7. [Frames](#7-frames)
8. [Frame Reservations](#8-frame-reservations)
9. [Prescriptions](#9-prescriptions)
10. [Quotations](#10-quotations)
11. [Job Orders](#11-job-orders)
12. [Invoices](#12-invoices)
13. [Conversation](#13-conversation)
14. [Frame Ratings](#14-frame-ratings)
15. [Error Responses](#15-error-responses)
16. [Clarifications](#16-clarifications)
17. [Retired Features](#17-retired-features)

---

## 1. Authentication

### POST `/register`

Creates a patient account and returns a Sanctum token.

**Request:**
```json
{
  "name": "string (required, max:255)",
  "email": "string (required, email, max:255, unique:users,email)",
  "phone": "string (nullable, max:20)",
  "password": "string (required, confirmed, min:8)",
  "password_confirmation": "string (required)"
}
```

**Response (201):**
```json
{
  "data": {
    "token": "1|abc123...",
    "user": { /* PatientProfileResource */ }
  }
}
```

**Privacy note:** No `privacy_notice_version` or `privacy_acknowledged_at` fields are accepted during registration. Privacy acknowledgement is handled server-side or via a separate flow.

---

### POST `/login`

**Request:**
```json
{
  "email": "string (required)",
  "password": "string (required)"
}
```

**Response (200):**
```json
{
  "data": {
    "token": "1|abc123...",
    "user": { /* PatientProfileResource */ }
  }
}
```

**Rate limited:** `throttle:login` middleware (default: 5 attempts per minute).

---

### POST `/logout`

Revokes the current bearer token.

**Response (204):** Empty body.

---

## 2. Profile (me)

### GET `/me`

Returns the authenticated patient's profile.

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "patient_number": "PAT-01JABC123...",
    "name": "Ana Reyes",
    "email": "ana@example.com",
    "phone": "09171234567",
    "role": "patient",
    "full_name": "Ana Reyes",
    "date_of_birth": "1990-05-15",
    "occupation": "Teacher",
    "address": "123 Main St, Manila",
    "gender": "female",
    "contact_email": "ana@example.com"
  }
}
```

**Nullable fields:** `phone`, `date_of_birth`, `occupation`, `address`, `gender`, `contact_email`.

---

### PATCH `/me`

Updates account and/or patient fields. At least one field required.

**Request (all optional):**
```json
{
  "name": "string (max:255)",
  "email": "string (email, unique:users,email,ignore:current)",
  "phone": "string (nullable, max:20)",
  "address": "string (nullable, max:255)",
  "full_name": "string (max:255)",
  "date_of_birth": "date (before:today)",
  "occupation": "string (nullable, max:255)",
  "gender": "string (in:male,female,other)",
  "contact_email": "string (nullable, email)"
}
```

**Response (200):** Same as GET `/me`.

---

## 3. Appointment Types

### GET `/appointment-types`

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "New Patient",
      "duration_minutes": 30,
      "requires_referral": false
    },
    {
      "id": 4,
      "name": "Referral",
      "duration_minutes": 30,
      "requires_referral": true
    }
  ]
}
```

---

## 4. Appointment Availability

### GET `/appointment-availability`

Returns time slots for a given date and appointment type.

**Query parameters:**
| Param | Type | Required | Rules |
|---|---|---|---|
| `date` | string | yes | `date_format:Y-m-d`, `after_or_equal:today` |
| `appointment_type_id` | integer | yes | `exists:appointment_types,id` |
| `appointment_id` | integer | no | `exists:appointments,id` (own appointments only, for reschedule context) |
| `optometrist_id` | integer | no | `exists:users,id` |

**Response (200):**
```json
{
  "data": {
    "date": "2026-07-28",
    "timezone": "Asia/Manila",
    "interval_minutes": 30,
    "appointment_type_id": 1,
    "visit_duration_minutes": 30,
    "optometrist_id": null,
    "appointment_id": null,
    "day_status": "open",
    "generated_at": "2026-07-27T10:00:00+08:00",
    "slots": [
      {
        "starts_at": "2026-07-28T09:00:00+08:00",
        "ends_at": "2026-07-28T09:30:00+08:00",
        "available": true,
        "reason": null
      },
      {
        "starts_at": "2026-07-28T09:30:00+08:00",
        "ends_at": "2026-07-28T10:00:00+08:00",
        "available": false,
        "reason": "capacity_reached"
      }
    ]
  }
}
```

**Validation (with `appointment_id`):**
- Appointment must belong to the authenticated patient.
- Appointment must be in `scheduled` or `checked_in` status.
- `appointment_type_id` duration must match the existing appointment's duration.

---

## 5. Appointments

### GET `/appointments`

Paginated list of the patient's appointments.

**Query:** `per_page` (default: 15)

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "appointment_number": "APT-2026-000001",
      "appointment_type": "New Patient",
      "duration_minutes": 30,
      "referring_source": null,
      "status": "scheduled",
      "scheduled_at": "2026-07-28T10:00:00+08:00",
      "contact_notes": "Please call before arrival",
      "last_reschedule_reason": null,
      "source": "mobile",
      "assigned_optometrist": { "name": "Dr. Maria Santos" }
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 }
}
```

**Notes:**
- `staff_notes` is NOT exposed to patients.
- `assigned_optometrist` contains only `name` (no `id`).
- `status` values: `scheduled`, `checked_in`, `fulfilled`, `cancelled`, `no_show`.
- `source` values: `mobile`, `walk_in`, `manual`.
- `contact_notes` is nullable.

---

### POST `/appointments`

Creates a new appointment. Source is automatically set to `mobile`. Status starts as `scheduled`.

**Request:**
```json
{
  "appointment_type_id": "integer (required, exists:appointment_types,id)",
  "scheduled_at": "datetime (required, after:now)",
  "contact_notes": "string (nullable, max:1000)",
  "referring_source": "string (nullable, max:255) — REQUIRED if appointment_type requires_referral"
}
```

**Response (201):**
```json
{
  "data": { /* AppointmentResource */ }
}
```

**Validation:**
- `referring_source` is required when the selected `appointment_type` has `requires_referral = true`.
- `scheduled_at` must be in the future.

**Booking uses `appointment_type_id` only.** There is no `visit_reason_id` in the API. Visit reasons are a separate concept used internally.

---

### GET `/appointments/{id}`

Returns a single appointment (must belong to authenticated patient).

**Response (200):**
```json
{
  "data": { /* AppointmentResource */ }
}
```

---

### POST `/appointments/{id}/cancel`

Cancels an appointment. Only `scheduled` or `checked_in` appointments can be cancelled.

**Response (200):**
```json
{
  "data": { /* AppointmentResource with status: "cancelled" */ }
}
```

**Error (422):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "appointment": ["This appointment cannot be cancelled."]
  }
}
```

---

### POST `/appointments/{id}/reschedule`

Reschedules an appointment to a new time.

**Request:**
```json
{
  "scheduled_at": "datetime (required, after:now)"
}
```

**Response (200):**
```json
{
  "data": { /* AppointmentResource with new scheduled_at */ }
}
```

**Validation:** Appointment must belong to the patient and be in `scheduled` status.

---

## 6. Patient Intake

### GET `/appointments/{id}/intake`

Returns the intake for an appointment, or `null` if none exists.

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "patient_id": 1,
    "appointment_id": 1,
    "status": "draft",
    "appointment_type": "New Patient",
    "full_name": "Ana Reyes",
    "date_of_birth": "1990-05-15",
    "gender": "female",
    "occupation": "Teacher",
    "address": "123 Main St",
    "phone": "09171234567",
    "email": "ana@example.com",
    "chief_complaint": "Blurred vision",
    "past_ocular_history": null,
    "past_surgical_history": null,
    "past_medical_history": null,
    "allergies": null,
    "medications": null,
    "submitted_at": null,
    "verified_at": null,
    "created_at": "2026-07-27T10:00:00+08:00",
    "updated_at": "2026-07-27T10:00:00+08:00"
  }
}
```

**Status values:** `draft`, `submitted`, `verified`.

---

### PUT `/appointments/{id}/intake`

Creates or updates the intake for an appointment. Only `draft` intakes can be edited.

**Request (all optional/sometimes):**
```json
{
  "full_name": "string (max:255)",
  "date_of_birth": "date (before:today)",
  "gender": "string (in:male,female,other)",
  "occupation": "string (nullable, max:255)",
  "address": "string (nullable, max:255)",
  "phone": "string (nullable, max:20)",
  "email": "string (nullable, email, max:255)",
  "chief_complaint": "string (nullable)",
  "past_ocular_history": "string (nullable)",
  "past_surgical_history": "string (nullable)",
  "past_medical_history": "string (nullable)",
  "allergies": "string (nullable)",
  "medications": "string (nullable)"
}
```

**Response:** `201` (created) or `200` (updated).

**Editability:** Once submitted (`status: submitted`), the intake CANNOT be edited via PUT. Returns `422` with `"Only draft intakes can be edited."`.

---

### POST `/appointments/{id}/intake/submit`

Submits a draft intake. Sets `status` to `submitted`, records `submitted_by` and `submitted_at`.

**Response (200):**
```json
{
  "data": { /* PatientIntakeResource with status: "submitted" */ }
}
```

**Error (422):** `"Only draft intakes can be submitted."` if already submitted/verified.

---

## 7. Frames

### GET `/frames`

Paginated list of active AR-eligible frames.

**Query parameters:**
| Param | Type | Description |
|---|---|---|
| `per_page` | integer | Default 15 |
| `search` | string | Search by name or description |
| `brand` | integer | Filter by brand ID |
| `category` | integer | Filter by category ID |
| `sort` | string | `name` (default) or `newest` |

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Classic Rectangle",
      "slug": "classic-rectangle",
      "description": "Timeless frame design",
      "product_type": "frame",
      "brand": "Ray-Ban",
      "category": "Full Rim",
      "variants": [
        {
          "id": 1,
          "name": "Black / 52mm",
          "sku": "RB-CR-BLK-52",
          "price": 4500.00,
          "compare_at_price": null,
          "attributes": { "color": "black", "size": "52mm" },
          "ar_eligible": true,
          "ar_asset_reference": "rb-cr-blk-52.usdz",
          "images": []
        }
      ],
      "images": []
    }
  ],
  "links": { ... },
  "meta": { ... }
}
```

**Excluded fields:** `cost_price`, `stock_quantity`, `low_stock_threshold`.

---

### GET `/frames/{id}`

Single frame detail. Returns `404` for non-frame products or non-AR-eligible frames.

---

## 8. Frame Reservations

### GET `/frame-reservations`

Returns all reservations for the authenticated patient. **Not paginated** — returns full list via `->get()`.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "appointment_id": 1,
      "status": "requested",
      "expires_at": "2026-08-03T10:00:00+08:00",
      "created_at": "2026-07-27T10:00:00+08:00",
      "items": [
        {
          "id": 1,
          "product_variant_id": 42,
          "variant": {
            "id": 42,
            "name": "Black / 52mm",
            "sku": "RB-CR-BLK-52",
            "price": "4500.00",
            "compare_at_price": null,
            "attributes": { "color": "black", "size": "52mm" },
            "images": [],
            "product": {
              "id": 7,
              "name": "Classic Rectangle",
              "slug": "classic-rectangle",
              "description": "Timeless frame design",
              "product_type": "frame",
              "brand": "Ray-Ban",
              "category": "Full Rim"
            }
          }
        }
      ]
    }
  ]
}
```

**Notes:**
- Response is a plain array wrapper (`data: [...]`), no `links` or `meta` pagination envelope.
- Sanitized via `FrameReservationResource`. Excludes: `patient_id`, `staff_notes`, `deleted_at`, `updated_at`, `frame_reservation_id`.
- Variant excludes: `cost_price`, `stock_quantity`, `low_stock_threshold`, `target_stock_level`, `is_active`, `ar_eligible`, `ar_asset_reference`, `product_id`, `deleted_at`, timestamps.
- Product excludes: `brand_id`, `category_id`, `lens_category_id`, `is_active`, `images`, `deleted_at`, timestamps. `brand` and `category` are string names, not objects.
- `status` values: `requested`, `prepared`, `tried_on`, `converted`, `released`, `cancelled`.

---

### POST `/frame-reservations`

Creates a new frame reservation.

**Request:**
```json
{
  "appointment_id": "integer (nullable, exists:appointments,id — must belong to patient)",
  "items": [
    { "product_variant_id": "integer (required, exists:product_variants,id)" }
  ]
}
```

**Validation:**
- `items`: `required`, `array`, `min:1`, `max:5`.
- Each `product_variant_id` must reference an active frame variant (product_type = frame, is_active = true, variant is_active = true).

**Response (201):**
```json
{
  "data": {
    "id": 1,
    "appointment_id": null,
    "status": "requested",
    "expires_at": null,
    "created_at": "2026-07-27T10:00:00+08:00",
    "items": [
      {
        "id": 1,
        "product_variant_id": 42,
        "variant": {
          "id": 42,
          "name": "Black / 52mm",
          "sku": "RB-CR-BLK-52",
          "price": "4500.00",
          "compare_at_price": null,
          "attributes": { "color": "black", "size": "52mm" },
          "images": [],
          "product": {
            "id": 7,
            "name": "Classic Rectangle",
            "slug": "classic-rectangle",
            "description": "Timeless frame design",
            "product_type": "frame",
            "brand": "Ray-Ban",
            "category": "Full Rim"
          }
        }
      }
    ]
  }
}
```

Uses the same `FrameReservationResource` as GET — same field set, same exclusions.

---

### POST `/frame-reservations/{id}/cancel`

Cancels a reservation. Only `requested` or `prepared` reservations can be cancelled.

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "appointment_id": null,
    "status": "cancelled",
    "expires_at": null,
    "created_at": "2026-07-27T10:00:00+08:00",
    "items": [
      {
        "id": 1,
        "product_variant_id": 42,
        "variant": { "...same sanitized structure as GET..." }
      }
    ]
  }
}
```

Uses the same `FrameReservationResource` as GET — same field set, same exclusions.

**Error (422):** `"This reservation cannot be cancelled."` if status is beyond `prepared`.

---

## 9. Prescriptions

### GET `/prescriptions`

Paginated list of current prescription versions. Superseded versions are
excluded from the list but remain available by ID for historical access.
Read-only — patients cannot create prescriptions.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "appointment_id": 1,
      "previous_prescription_id": null,
      "is_current": true,
      "date": "2026-07-27",
      "measurements": {
        "main": {
          "od": {
            "value": null,
            "sphere": "-2.00",
            "cylinder": "-0.50"
          },
          "os": {
            "value": null,
            "sphere": "-1.75",
            "cylinder": "-0.25"
          }
        },
        "add": {
          "od": {
            "value": null,
            "sphere": null,
            "cylinder": null
          },
          "os": {
            "value": null,
            "sphere": null,
            "cylinder": null
          }
        }
      },
      "remarks": null
    }
  ],
  "links": { ... },
  "meta": { ... }
}
```

### GET `/prescriptions/{id}`

Single prescription, including historical superseded versions. The
`previous_prescription_id` and `is_current` fields identify its chain position.
Returns `404` if not patient's.

---

## 10. Quotations

### GET `/quotations`

Paginated list with latest revision.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "quotation_number": "QUO-01JABC...",
      "status": "presented",
      "valid_until": "2026-08-03",
      "notes": null,
      "revision": {
        "revision_number": 1,
        "subtotal": 8500.00,
        "discount_amount": 500.00,
        "total": 8000.00,
        "items": [
          {
            "description": "Classic Rectangle Frame",
            "quantity": 1,
            "unit_price": 4500.00,
            "amount": 4500.00
          },
          {
            "description": "Single Vision Lens",
            "quantity": 1,
            "unit_price": 4000.00,
            "amount": 4000.00
          }
        ]
      },
      "created_at": "2026-07-27T10:00:00+08:00"
    }
  ]
}
```

**Status values:** `draft`, `presented`, `accepted`, `declined`, `expired`.

**Read-only.** Patients cannot create, accept, or decline quotations via the API.

---

### GET `/quotations/{quotation}`

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "quotation_number": "QUO-01JABC123...",
    "status": "presented",
    "valid_until": "2026-08-03",
    "notes": "Please handle with care",
    "revision": {
      "revision_number": 1,
      "subtotal": 8500.00,
      "discount_amount": 500.00,
      "total": 8000.00,
      "items": [
        {
          "description": "Classic Rectangle Frame",
          "quantity": 1,
          "unit_price": 4500.00,
          "amount": 4500.00
        },
        {
          "description": "Single Vision Lens",
          "quantity": 1,
          "unit_price": 4000.00,
          "amount": 4000.00
        }
      ]
    },
    "created_at": "2026-07-27T10:00:00+08:00"
  }
}
```

**Notes:** `revision` is `null` if no revisions exist. Uses `QuotationResource`. Status values: `draft`, `presented`, `accepted`, `declined`, `expired`.

### GET `/job-orders`

Paginated list with items.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "job_order_number": "JO-2026-000001",
      "patient_id": 1,
      "status": "in_progress",
      "total_amount": 8000.00,
      "items": [
        {
          "id": 1,
          "description": "Classic Rectangle Frame",
          "quantity": 1,
          "unit_price": 4500.00,
          "amount": 4500.00
        }
      ]
    }
  ]
}
```

**Status values:** `queued`, `in_progress`, `ready_for_dispensing`, `dispensed`, `cancelled`.

### GET `/job-orders/{id}`

Returns a single job order with items. No API Resource — raw model serialization.

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "job_order_number": "JO-2026-000001",
    "patient_id": 1,
    "encounter_id": 1,
    "prescription_id": 1,
    "quotation_revision_id": 1,
    "status": "dispensed",
    "total_amount": 8000.00,
    "notes": null,
    "started_at": "2026-07-27T11:00:00+08:00",
    "ready_at": "2026-07-27T14:00:00+08:00",
    "dispensed_at": "2026-07-27T15:00:00+08:00",
    "cancelled_at": null,
    "created_at": "2026-07-27T10:00:00+08:00",
    "updated_at": "2026-07-27T15:00:00+08:00",
    "deleted_at": null,
    "items": [
      {
        "id": 1,
        "job_order_id": 1,
        "description": "Classic Rectangle Frame",
        "quantity": 1,
        "unit_price": 4500.00,
        "amount": 4500.00,
        "product_variant_id": 42,
        "lens_category_id": null,
        "created_at": "2026-07-27T10:00:00+08:00",
        "updated_at": "2026-07-27T10:00:00+08:00"
      }
    ]
  }
}
```

**Notes:** `encounter_id`, `prescription_id`, `quotation_revision_id`, `notes`, `started_at`, `ready_at`, `dispensed_at`, `cancelled_at` are all nullable.

### GET `/invoices`

Paginated list with items and posted payments.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "invoice_number": "INV-2026-000001",
      "patient_id": 1,
      "status": "partially_paid",
      "total": 8000.00,
      "amount_paid": 5000.00,
      "balance_due": 3000.00,
      "items": [ ... ],
      "payments": [
        {
          "id": 1,
          "amount": 5000.00,
          "payment_method": "gcash",
          "reference_number": "GC-12345",
          "status": "posted"
        }
      ]
    }
  ]
}
```

**Status values:** `draft`, `issued`, `partially_paid`, `paid`, `voided`.

**Only posted payments are included.** Voided payments are excluded.

### GET `/invoices/{id}`

Returns a single invoice with items and posted payments. No API Resource — raw model serialization.

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "invoice_number": "INV-2026-000001",
    "official_number": null,
    "patient_id": 1,
    "job_order_id": 1,
    "encounter_id": 1,
    "status": "partially_paid",
    "sale_type": "retail",
    "sold_to_name": "Ana Reyes",
    "registered_name": null,
    "tin": null,
    "business_address": null,
    "subtotal": 8500.00,
    "discount_amount": 500.00,
    "tax_amount": 0.00,
    "total": 8000.00,
    "amount_paid": 5000.00,
    "balance_due": 3000.00,
    "notes": null,
    "recorded_by": 1,
    "issued_at": "2026-07-27T15:00:00+08:00",
    "created_at": "2026-07-27T10:00:00+08:00",
    "updated_at": "2026-07-27T16:00:00+08:00",
    "deleted_at": null,
    "items": [
      {
        "id": 1,
        "invoice_id": 1,
        "type": "product",
        "description": "Classic Rectangle Frame",
        "quantity": 1,
        "unit_price": 4500.00,
        "amount": 4500.00,
        "job_order_item_id": 1,
        "created_at": "2026-07-27T10:00:00+08:00",
        "updated_at": "2026-07-27T10:00:00+08:00"
      }
    ],
    "payments": [
      {
        "id": 1,
        "invoice_id": 1,
        "amount": 5000.00,
        "payment_method": "gcash",
        "reference_number": "GC-12345",
        "recorded_by": 1,
        "recorded_at": "2026-07-27T16:00:00+08:00",
        "notes": null,
        "status": "posted",
        "created_at": "2026-07-27T16:00:00+08:00",
        "updated_at": "2026-07-27T16:00:00+08:00"
      }
    ]
  }
}
```

**Nullable fields:** `official_number`, `registered_name`, `tin`, `business_address`, `notes`, `job_order_id`, `encounter_id`, `recorded_by`, `issued_at`.

---

## 13. Conversation

### GET `/conversation`

Returns (or creates) the patient's single conversation.

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "patient_id": 1,
    "unread_count": 3,
    "created_at": "2026-07-27T10:00:00+08:00"
  }
}
```

**Unread count:** Messages from other senders where `read_at IS NULL`.

---

### GET `/conversation/messages`

Returns all messages in the conversation (oldest first).

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "conversation_id": 1,
      "sender_id": 5,
      "body": "Hello, I have a question about my prescription.",
      "read_at": null,
      "created_at": "2026-07-27T10:00:00+08:00",
      "attachments": [
        {
          "id": 1,
          "original_name": "prescription.pdf",
          "mime_type": "application/pdf",
          "file_size": 125000
        }
      ],
      "contexts": [
        { "type": "App\\Models\\Appointment", "id": 1 }
      ]
    }
  ]
}
```

**Note:** This endpoint is NOT paginated — returns all messages.

---

### POST `/conversation/messages`

Sends a message. Supports `multipart/form-data` for attachments.

**Request (`multipart/form-data`):**
```
Content-Type: multipart/form-data; boundary=----FormBoundary

------FormBoundary
Content-Disposition: form-data; name="body"

Hello, I have a question about my prescription.
------FormBoundary
Content-Disposition: form-data; name="contexts[0][type]"

appointment
------FormBoundary
Content-Disposition: form-data; name="contexts[0][id]"

1
------FormBoundary
Content-Disposition: form-data; name="contexts[1][type]"

product
------FormBoundary
Content-Disposition: form-data; name="contexts[1][id]"

7
------FormBoundary
Content-Disposition: form-data; name="attachment"; filename="prescription.pdf"
Content-Type: application/pdf

<binary file data>
------FormBoundary--
```

**Field encoding rules:**
- `body` — plain text field
- `attachment` — file field (optional)
- `contexts[N][type]` — indexed array, one field per context entry
- `contexts[N][id]` — indexed array, paired with the corresponding type
- Indexes must be sequential (`0`, `1`, `2`...) with no gaps
- `contexts` is optional, max 5 entries
- Context `type` must be one of: `appointment`, `order`, `product`

**Response (201):**
```json
{
  "data": { /* MessageResource */ }
}
```

---

### GET `/conversation/attachments/{id}`

Downloads a message attachment.

**Response:** Binary file download with `Content-Type` header matching the stored mime type and `Content-Disposition` using the original filename.

**Authorization:** Patient can only download attachments from their own conversation. Returns `404` for cross-patient access.

---

## 14. Frame Ratings

### POST `/job-order-items/{id}/rating`

Submits or revises a rating for a frame variant linked to a job order item.

**Request:**
```json
{
  "product_variant_id": "integer (required, exists:product_variants,id)",
  "rating": "integer (required, min:1, max:5)",
  "comment": "string (nullable, max:1000)",
  "dispensing_event_id": "integer (nullable, exists:dispensing_events,id)"
}
```

**Eligibility (server-enforced):**
- The job-order item (`{item}` in the URL) must belong to the authenticated patient.
- The job order must have `status = dispensed`.
- `product_variant_id` in the request body must match the job-order item's `product_variant_id`.
- If `dispensing_event_id` is supplied, it must belong to the same job order.
- One rating per patient per variant — subsequent calls append a revision.

**Response (201):**
```json
{
  "data": {
    "id": 1,
    "patient_id": 1,
    "product_variant_id": 42,
    "dispensing_event_id": 1,
    "rating": 5,
    "comment": "Perfect fit!",
    "current_revision_id": 1,
    "is_hidden": false,
    "moderation_reason": null,
    "moderated_by": null,
    "moderated_at": null,
    "created_at": "2026-07-27T10:00:00+08:00",
    "updated_at": "2026-07-27T10:00:00+08:00",
    "deleted_at": null,
    "revisions": [
      {
        "id": 1,
        "frame_rating_id": 1,
        "revision_number": 1,
        "rating": 5,
        "comment": "Perfect fit!",
        "revised_by": 5,
        "revised_at": "2026-07-27T10:00:00+08:00",
        "created_at": "2026-07-27T10:00:00+08:00",
        "updated_at": "2026-07-27T10:00:00+08:00"
      }
    ]
  }
}
```

**On revision (second call with same patient + variant):**
- `rating` and `comment` on the parent `FrameRating` are updated to the new values.
- A new `FrameRatingRevision` is appended with `revision_number` incremented.
- `current_revision_id` points to the new revision.
- `revisions` array contains all revisions (initial + all edits).

---

## 15. Error Responses

### 401 Unauthorized
```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden
```json
{
  "message": "This action is unauthorized."
}
```

### 404 Not Found
```json
{
  "message": "No query results for model [App\\Models\\Appointment] 999"
}
```

### 422 Validation Error
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email has already been taken."],
    "scheduled_at": ["The scheduled at must be a date after now."]
  }
}
```

### 429 Too Many Requests
```json
{
  "message": "Too Many Attempts."
}
```

**Rate limits:**
- Login: `throttle:login` (5/min)
- All authenticated routes: `throttle:60,1` (60/min)

### 409 Conflict
Not currently used by the API. Appointment scheduling conflicts return `422` with a descriptive error message.

---

## 16. Clarifications

### Booking uses `appointment_type_id` only
There is no `visit_reason_id` in the mobile API. The `appointment_type_id` determines the visit type and duration. `visit_reason_id` exists in the database schema but is not used by the mobile booking flow.

### `/appointment-availability` parameters
Requires `date` (Y-m-d) and `appointment_type_id`. Optional `optometrist_id` to filter by provider. Optional `appointment_id` for reschedule context (validates ownership and status).

### Registration and `/me` fields
Registration accepts `name`, `email`, `phone`, `password`. No privacy acknowledgement fields in the API. The `/me` endpoint returns both account fields (`name`, `email`, `phone`) and patient fields (`full_name`, `date_of_birth`, `occupation`, `address`, `gender`, `contact_email`, `patient_number`).

### Intake editability
Draft intakes can be edited freely via PUT. Once submitted (`status: submitted`), they become immutable — PUT returns `422`. Verified intakes are also immutable.

### Frame reservation creation and cancellation
Creation requires `items[].product_variant_id` (1-5 items). Each must be an active frame variant. Optional `appointment_id` links to a specific appointment. Cancellation is allowed only for `requested` or `prepared` statuses.

### Conversation unread/read behavior
`unread_count` on the conversation resource counts messages from other senders where `read_at IS NULL`. There is no explicit mark-read endpoint in the mobile API — read status is managed by the admin panel.

### Attachment format
Attachments are uploaded as `multipart/form-data` with field name `attachment`. Accepted types: jpg, jpeg, png, gif, pdf, doc, docx. Max 10MB. Download returns the original filename and mime type in headers.

### Job-order-item rating eligibility
Ratings are submitted via `POST /job-order-items/{item}/rating`. Server-enforced authorization: the job-order item must belong to the authenticated patient, the job order must be `dispensed`, the `product_variant_id` must match the item, and `dispensing_event_id` (if supplied) must belong to the same job order. One rating per patient per variant — subsequent calls append a revision. Moderated (hidden) ratings are preserved in DB.

---

## 17. Retired Features

The following old mobile features/routes are **intentionally retired** and do not exist in the v1 API:

| Feature | Status |
|---|---|
| Accessories and orders (`/orders`, `/accessories`) | Retired. No order/accessory endpoints. Products are frames only. |
| Billing PDF | Retired. No PDF generation endpoints. |
| Clinic feedback (`/feedback`) | Retired. Complaints remain a separate clinic remediation workflow; verified frame ratings remain available after dispensing. |
| Appointment contact-note editing (`PATCH /appointments/{id}/contact-note`) | The `updateContactNote` method exists in the controller but has **no registered route**. Retired. |
| Explicit message mark-read | No mark-read endpoint. Unread count is read-only context. |
| Message context cards | Contexts are embedded in the message response as `contexts[]` with `type` and `id`. No separate card/detail endpoint. |
| `/api/user` (unversioned) | Absent. All auth uses `/api/v1/` prefix. |
| `/api/v1/patient/profile` | Absent. Profile is accessed via `/api/v1/me`. |
| Notification endpoints | `NotificationController` exists but has no registered routes. Retired from mobile API. |

---

## Appendix: Complete Route List (33 routes)

```
POST   /api/v1/register
POST   /api/v1/login
POST   /api/v1/logout
GET    /api/v1/me
PATCH  /api/v1/me

GET    /api/v1/appointment-types
GET    /api/v1/appointment-availability
GET    /api/v1/appointments
POST   /api/v1/appointments
GET    /api/v1/appointments/{appointment}
POST   /api/v1/appointments/{appointment}/cancel
POST   /api/v1/appointments/{appointment}/reschedule

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
GET    /api/v1/conversation/attachments/{attachment}

POST   /api/v1/job-order-items/{item}/rating
```

**Total: 33 routes** (matches BACKEND_CONTEXT.md). The `appointment-types` endpoint is included in the count.
