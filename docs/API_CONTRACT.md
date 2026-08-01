# Eyecare Mobile API v1 — Authoritative Contract

> **Backend version:** Current repository state (2026-07-31) — introduces OTP-based patient registration, hybrid login, contact management, patient linking, appointment requests, and active-link route boundary.
> **Base URL:** `/api/v1`
> **Auth:** Laravel Sanctum bearer tokens
> **Timezone:** `Asia/Manila` (configurable via `app.timezone`)
> **Timestamps:** ISO 8601 (`2026-07-27T10:00:00+08:00`)
> **Dates:** `Y-m-d` format (`2026-07-27`)

---

## Table of Contents

1. [Authentication](#1-authentication)
2. [Profile (me)](#2-profile-me)
3. [Contact Management](#3-contact-management)
4. [Patient Linking](#4-patient-linking)
5. [Patient Invitations](#5-patient-invitations)
6. [Appointment Requests](#6-appointment-requests)
7. [Appointment Availability](#7-appointment-availability)
8. [Confirmed Appointments](#8-confirmed-appointments)
9. [Frames](#9-frames)
10. [Frame Reservations](#10-frame-reservations)
11. [Prescriptions](#11-prescriptions)
12. [Quotations](#12-quotations)
13. [Job Orders](#13-job-orders)
14. [Eyewear](#14-eyewear)
15. [Billing Records](#15-billing-records)
16. [Conversation](#16-conversation)
17. [Frame Ratings](#17-frame-ratings)
18. [Error Responses](#18-error-responses)
19. [Coordinated Breaking Changes](#19-coordinated-breaking-changes)
20. [Retired Features](#20-retired-features)
21. [Clarifications](#21-clarifications)

---

## 1. Authentication

### POST `/auth/registration/otp`

Requests a registration OTP for the given contact. Returns a generic response regardless of whether the contact is already owned.

**Request:**
```json
{
  "contact_type": "email | phone (required)",
  "contact_value": "string (required)"
}
```

**Validation:**
- `contact_type`: required, `in:email,phone`
- `contact_value`: required; if `email`, validated as email format; if `phone`, normalized to canonical E.164

**Response (200):**
```json
{
  "data": {
    "challenge_id": "opaque-uuid",
    "expires_at": "2026-07-27T10:10:00+08:00"
  }
}
```

**Behavior:**
- If the contact is already owned by an existing account, a login/recovery challenge is issued instead. The response shape is identical.
- Rate limited: 3 per 15 minutes per destination, 10 per 15 minutes per IP, 10 per destination per day.
- Resend invalidates all earlier pending challenges for the same purpose/destination.

---

### POST `/auth/registration/verify`

Verifies the OTP and creates the patient mobile account. Does **not** create a Patient record.

**Request:**
```json
{
  "challenge_id": "string (required)",
  "code": "string (required, 6 digits)",
  "first_name": "string (required, max:255)",
  "middle_name": "string (nullable, max:255)",
  "last_name": "string (required, max:255)",
  "date_of_birth": "date (required, before:today, Y-m-d)",
  "phone": "string (required, max:20)",
  "password": "string (required, confirmed, min:12)",
  "password_confirmation": "string (required)",
  "privacy_policy_accepted": "boolean (required, must be true)",
  "terms_accepted": "boolean (required, must be true)",
  "invitation_code": "string (nullable, optional)",
  "device_name": "string (nullable, max:255)",
  "installation_id": "string (nullable, max:255)"
}
```

**Response (201):**
```json
{
  "data": {
    "token": "1|abc123...",
    "user": { /* PatientAccountResource */ }
  }
}
```

**Behavior:**
- If the contact is already owned, the challenge is consumed and the existing account is authenticated (idempotent). No duplicate account is created.
- The response never reveals whether a new account was created or an existing one was returned.
- 5 maximum verification attempts per challenge.
- Creates only the `User` with `patient` role and its verified primary contact method.
- Device label and installation ID are stored on the Sanctum token.

---

### POST `/auth/login`

Authenticates a patient with password. Does **not** issue a token immediately — returns a step-up challenge.

**Request:**
```json
{
  "contact_value": "string (required)",
  "password": "string (required)",
  "device_name": "string (nullable, max:255)",
  "installation_id": "string (nullable, max:255)"
}
```

**Response (200):**
```json
{
  "data": {
    "step_up_required": true,
    "challenge_id": "opaque-uuid",
    "expires_at": "2026-07-27T10:10:00+08:00"
  }
}
```

**Behavior:**
- Response is identical for wrong password and unknown contact (enumeration-safe).
- An existing trusted device token (same `installation_id`, not expired, not revoked) may skip step-up. In that case `step_up_required` is `false` and the token is returned directly.
- Rate limited: `throttle:login` (5 per minute).

---

### POST `/auth/login/verify`

Verifies the login OTP step-up and issues a device-labelled Sanctum token.

**Request:**
```json
{
  "challenge_id": "string (required)",
  "code": "string (required, 6 digits)",
  "installation_id": "string (nullable, max:255)"
}
```

**Response (200):**
```json
{
  "data": {
    "token": "1|abc123...",
    "user": { /* PatientAccountResource */ }
  }
}
```

**Behavior:**
- Replaces any existing token for the same `installation_id`.
- Maximum 5 active patient tokens; issues beyond the limit revoke the oldest.
- Token expires after 30 days.

---

### POST `/auth/password-recovery/otp`

Requests a recovery OTP for the given contact.

**Request:**
```json
{
  "contact_value": "string (required)"
}
```

**Response (200):**
```json
{
  "data": {
    "challenge_id": "opaque-uuid",
    "expires_at": "2026-07-27T10:10:00+08:00"
  }
}
```

**Behavior:**
- Enumeration-safe: returns identical response for known and unknown contacts.
- Only verified contacts on patient accounts are eligible.

---

### POST `/auth/password-recovery/verify`

Verifies the recovery OTP and resets the password. Revokes all other patient device tokens.

**Request:**
```json
{
  "challenge_id": "string (required)",
  "code": "string (required, 6 digits)",
  "password": "string (required, confirmed, min:12)",
  "password_confirmation": "string (required)"
}
```

**Response (200):**
```json
{
  "data": {
    "token": "1|abc123...",
    "user": { /* PatientAccountResource */ }
  }
}
```

**Behavior:**
- Revokes all other patient device tokens on successful recovery.
- Issues one new token for the current device.

---

### POST `/logout`

Revokes the current bearer token.

**Auth:** Required (Sanctum token).

**Response (204):** Empty body.

---

### POST `/logout-all`

Revokes all patient device tokens for the authenticated account.

**Auth:** Required (Sanctum token).

**Response (204):** Empty body.

---

## 2. Profile (me)

### GET `/me`

Returns the authenticated account's profile and link state.

**Auth:** Required (Sanctum token).

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "first_name": "Ana",
    "last_name": "Reyes",
    "name": "Ana Reyes",
    "email": "ana@example.com",
    "phone": "09171234567",
    "role": "patient",
    "date_of_birth": "1990-05-15",
    "link_status": "linked | pending_review | unlinked",
    "patient_number": "PAT-01JABC123...",
    "full_name": "Ana Reyes",
    "occupation": "Teacher",
    "address": "123 Main St, Manila",
    "gender": "female",
    "contact_email": "ana@example.com",
    "privacy_notice_version": "2026-07",
    "privacy_acknowledged_at": "2026-07-27T10:00:00+08:00"
  }
}
```

**Notes:**
- `link_status`: `linked` (active `patients.user_id`), `pending_review` (active link request), `unlinked` (no link or request).
- `patient_number`, `full_name`, `occupation`, `address`, `gender`, `contact_email` are only present when `link_status` is `linked`.
- `email` and `phone` are from verified contact methods, not `users.email`/`users.phone`.

### PATCH `/me`

Updates account fields. At least one field required.

**Auth:** Required (Sanctum token).

**Request (all optional):**
```json
{
  "first_name": "string (max:255)",
  "last_name": "string (max:255)"
}
```

**Response (200):** Same as GET `/me`.

**Notes:**
- Only `first_name` and `last_name` are editable via this endpoint. Contact changes go through the Contact Management endpoints.
- Clinic Patient demographics (`full_name`, `date_of_birth`, `occupation`, `address`, `gender`, `contact_email`) are never updated through this endpoint.

---

## 3. Contact Management

### GET `/account/contacts`

Lists verified and pending contacts for the authenticated account.

**Auth:** Required (Sanctum token).

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "type": "email",
      "masked_value": "a***@example.com",
      "is_primary": true,
      "verified_at": "2026-07-27T10:00:00+08:00"
    },
    {
      "id": 2,
      "type": "phone",
      "masked_value": "0917***4567",
      "is_primary": false,
      "verified_at": null
    }
  ]
}
```

**Notes:**
- `masked_value` is always returned; raw contact values are never exposed.
- Unverified contacts (`verified_at: null`) cannot be used for login.

---

### POST `/account/contacts/otp`

Requests an OTP to verify a new contact method.

**Auth:** Required (Sanctum token).

**Request:**
```json
{
  "contact_type": "email | phone (required)",
  "contact_value": "string (required)"
}
```

**Response (200):**
```json
{
  "data": {
    "challenge_id": "opaque-uuid",
    "expires_at": "2026-07-27T10:10:00+08:00"
  }
}
```

**Errors:**
- `422 CONTACT_ALREADY_OWNED`: The contact is already verified by another account.
- `422 CONTACT_ALREADY_VERIFIED`: This account already owns this contact.

---

### POST `/account/contacts/verify`

Verifies a pending contact OTP.

**Auth:** Required (Sanctum token).

**Request:**
```json
{
  "challenge_id": "string (required)",
  "code": "string (required, 6 digits)"
}
```

**Response (200):**
```json
{
  "data": { /* same contact structure as GET /account/contacts, with verified_at set */ }
}
```

---

### PATCH `/account/contacts/{contact}/primary`

Sets a verified contact as the primary login/notification contact.

**Auth:** Required (Sanctum token).

**Response (200):**
```json
{
  "data": [ /* updated contacts list */ ]
}
```

**Errors:**
- `404`: Contact not found or not owned by this account.
- `422 CONTACT_NOT_VERIFIED`: Cannot set an unverified contact as primary.

---

### DELETE `/account/contacts/{contact}`

Removes a contact method.

**Auth:** Required (Sanctum token).

**Response (204):** Empty body.

**Errors:**
- `404`: Contact not found or not owned by this account.
- `422 LAST_CONTACT_REMAINING`: Cannot remove the last verified login contact.

---

## 4. Patient Linking

### GET `/account/link`

Returns the current link state and request status for the authenticated account.

**Auth:** Required (Sanctum token).

**Response (200) — linked:**
```json
{
  "data": {
    "status": "linked",
    "linked_at": "2026-07-28T10:00:00+08:00"
  }
}
```

**Response (200) — pending review:**
```json
{
  "data": {
    "status": "pending_review",
    "request_submitted_at": "2026-07-28T10:00:00+08:00"
  }
}
```

**Response (200) — unlinked:**
```json
{
  "data": {
    "status": "unlinked"
  }
}
```

**Notes:**
- Never exposes candidate patient names, numbers, contact values, match scores, or whether a specific clinic record exists.

---

### POST `/patient-link-requests`

Submits a link request using the account's registration identity (name, date of birth) and contact.

**Auth:** Required (Sanctum token).

**Request:** No body required. Uses the account's stored identity.

**Response (201):**
```json
{
  "data": {
    "request_number": "PLR-2026-000001",
    "status": "pending",
    "submitted_at": "2026-07-28T10:00:00+08:00"
  }
}
```

**Errors:**
- `422 ACCOUNT_ALREADY_LINKED`: The account already has an active patient link.
- `422 LINK_REQUEST_ALREADY_PENDING`: An active link request already exists for this account.

---

### GET `/patient-link-requests/current`

Returns the current active link request for the authenticated account.

**Auth:** Required (Sanctum token).

**Response (200):**
```json
{
  "data": {
    "request_number": "PLR-2026-000001",
    "status": "pending",
    "submitted_at": "2026-07-28T10:00:00+08:00",
    "reviewed_at": null
  }
}
```

**Response (204):** No active request exists.

---

## 5. Patient Invitations

### POST `/patient-invitations/acceptance/otp`

Requests an OTP for accepting a patient invitation. The invitation token identifies the target contact.

**Auth:** Required (Sanctum token).

**Request:**
```json
{
  "invitation_token": "string (required)"
}
```

**Response (200):**
```json
{
  "data": {
    "challenge_id": "opaque-uuid",
    "expires_at": "2026-07-27T10:10:00+08:00"
  }
}
```

**Errors:**
- `422 INVITATION_INVALID`: Token is invalid, expired, revoked, or already consumed.
- `422 ACCOUNT_ALREADY_LINKED`: The account already has an active patient link.

---

### POST `/patient-invitations/accept`

Verifies the OTP and activates the patient link.

**Auth:** Required (Sanctum token).

**Request:**
```json
{
  "invitation_token": "string (required)",
  "challenge_id": "string (required)",
  "code": "string (required, 6 digits)"
}
```

**Response (200):**
```json
{
  "data": {
    "status": "linked",
    "linked_at": "2026-07-28T10:00:00+08:00"
  }
}
```

**Behavior:**
- Rechecks invitation, account, and patient eligibility under locks.
- If the account doesn't already own the invited contact, that contact is added and verified atomically.
- Repeated acceptance is idempotent and returns the same link.
- Already-linked conflicts fail closed without revealing another account.

---

## 6. Appointment Requests

### GET `/appointment-request-availability`

Returns server-generated time slots for a given date, accounting for clinic hours, provider hours, existing appointments, and unexpired pending request holds.

**Auth:** Required (Sanctum token).

**Query parameters:**
| Param | Type | Required | Rules |
|---|---|---|---|
| `date` | string | yes | `date_format:Y-m-d`, `after_or_equal:today` |

**Response (200):**
```json
{
  "data": {
    "date": "2026-07-28",
    "timezone": "Asia/Manila",
    "interval_minutes": 30,
    "slot_duration_minutes": 30,
    "day_status": "open",
    "generated_at": "2026-07-27T10:00:00+08:00",
    "slots": [
      {
        "starts_at": "2026-07-28T09:00:00+08:00",
        "ends_at": "2026-07-28T09:30:00+08:00",
        "available": true
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

**Notes:**
- Uses a server-owned provisional duration (30 minutes) for availability calculation.
- Unexpired pending request holds are included in capacity calculations.
- Patient does not select appointment type; the type is resolved by staff at acceptance.

---

### GET `/appointment-requests`

Paginated list of the authenticated account's appointment requests.

**Auth:** Required (Sanctum token).

**Query:** `per_page` (default: 15)

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "request_number": "APR-2026-000001",
      "status": "pending",
      "patient_id": null,
      "scheduled_at": "2026-07-28T10:00:00+08:00",
      "reason_for_visit": "Blurred vision in left eye",
      "expires_at": "2026-07-29T10:00:00+08:00",
      "created_at": "2026-07-27T10:00:00+08:00",
      "appointment": null
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 }
}
```

**Status values:** `pending`, `accepted`, `rejected`, `cancelled`, `expired`.

**Notes:**
- `patient_id` is `null` for unlinked accounts.
- `appointment` is populated only when `status` is `accepted`.

---

### POST `/appointment-requests`

Creates a new appointment request.

**Auth:** Required (Sanctum token).

**Request:**
```json
{
  "scheduled_at": "datetime (required, must match a returned available slot)",
  "reason_for_visit": "string (required, max:1000)"
}
```

**Response (201):**
```json
{
  "data": {
    "id": 1,
    "request_number": "APR-2026-000001",
    "status": "pending",
    "patient_id": null,
    "scheduled_at": "2026-07-28T10:00:00+08:00",
    "reason_for_visit": "Blurred vision in left eye",
    "expires_at": "2026-07-29T10:00:00+08:00",
    "created_at": "2026-07-27T10:00:00+08:00",
    "appointment": null
  }
}
```

**Behavior:**
- Creates a 24-hour capacity hold on the requested slot.
- For linked accounts, `patient_id` is copied from the active link.
- For unlinked accounts, `patient_id` remains `null`.
- Maximum 2 active pending requests per account.
- Rate limited per account and per IP.
- Does **not** create a Patient or an Appointment.

**Errors:**
- `422 SLOT_UNAVAILABLE`: The requested slot is no longer available.
- `422 ACTIVE_REQUEST_LIMIT_REACHED`: Maximum active pending requests reached.

---

### GET `/appointment-requests/{appointmentRequest}`

Returns a single appointment request (must belong to authenticated account).

**Auth:** Required (Sanctum token).

**Response (200):**
```json
{
  "data": { /* same structure as list item */ }
}
```

---

### POST `/appointment-requests/{appointmentRequest}/cancel`

Cancels a pending appointment request.

**Auth:** Required (Sanctum token).

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "request_number": "APR-2026-000001",
    "status": "cancelled",
    "scheduled_at": "2026-07-28T10:00:00+08:00",
    "reason_for_visit": "Blurred vision in left eye",
    "expires_at": "2026-07-29T10:00:00+08:00",
    "cancelled_at": "2026-07-27T11:00:00+08:00",
    "created_at": "2026-07-27T10:00:00+08:00",
    "appointment": null
  }
}
```

**Errors:**
- `404`: Request not found or not owned by this account.
- `422 REQUEST_NOT_CANCELLABLE`: Only pending requests can be cancelled.

---

## 7. Appointment Availability

### GET `/appointment-availability`

Returns time slots for a given date. Used for confirmed appointment rescheduling.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Query parameters:**
| Param | Type | Required | Rules |
|---|---|---|---|
| `date` | string | yes | `date_format:Y-m-d`, `after_or_equal:today` |
| `appointment_id` | integer | no | `exists:appointments,id` (own appointments only, for reschedule context) |

**Response (200):**
```json
{
  "data": {
    "date": "2026-07-28",
    "timezone": "Asia/Manila",
    "interval_minutes": 30,
    "appointment_type_id": 1,
    "visit_duration_minutes": 30,
    "day_status": "open",
    "generated_at": "2026-07-27T10:00:00+08:00",
    "slots": [
      {
        "starts_at": "2026-07-28T09:00:00+08:00",
        "ends_at": "2026-07-28T09:30:00+08:00",
        "available": true,
        "reason": null
      }
    ]
  }
}
```

**Validation (with `appointment_id`):**
- Appointment must belong to the authenticated patient.
- Appointment must be in `scheduled` or `checked_in` status.
- Duration and type are derived from the existing appointment.

**Notes:**
- `appointment_type_id` and `visit_duration_minutes` are derived from the existing appointment when rescheduling, not submitted by the patient.
- Unexpired pending request holds are included in capacity calculations.

---

## 8. Confirmed Appointments

**Active patient link required for all endpoints in this section.**

### GET `/appointments`

Paginated list of the patient's confirmed appointments.

**Auth:** Required (Sanctum token). **Active patient link required.**

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
      "reason_for_visit": "Blurred vision in left eye",
      "contact_notes": "Please call before arrival",
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
- `reason_for_visit` is the accepted request's reason, nullable for staff-created appointments.
- `contact_notes` is nullable.

---

### GET `/appointments/{appointment}`

Returns a single confirmed appointment (must belong to authenticated patient).

**Auth:** Required (Sanctum token). **Active patient link required.**

**Response (200):**
```json
{
  "data": { /* AppointmentResource */ }
}
```

---

### POST `/appointments/{appointment}/cancel`

Cancels an appointment. Only `scheduled` or `checked_in` appointments can be cancelled.

**Auth:** Required (Sanctum token). **Active patient link required.**

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

### POST `/appointments/{appointment}/reschedule`

Reschedules an appointment to a new time.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Request:**
```json
{
  "scheduled_at": "datetime (required, after:now, must be an available slot)"
}
```

**Response (200):**
```json
{
  "data": { /* AppointmentResource with new scheduled_at */ }
}
```

**Validation:** Appointment must belong to the patient and be in `scheduled` status. Duration and type are derived from the existing appointment.

---

## 9. Frames

### GET `/frames`

Paginated list of active AR-eligible frames.

**Auth:** Required (Sanctum token). **Active patient link required.**

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

**Auth:** Required (Sanctum token). **Active patient link required.**

---

## 10. Frame Reservations

**Active patient link required for all endpoints in this section.**

### GET `/frame-reservations`

Returns all reservations for the authenticated patient. **Not paginated** — returns full list via `->get()`.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "appointment_id": 42,
      "status": "requested",
      "expires_at": "2026-08-03T10:00:00+08:00",
      "created_at": "2026-07-27T10:00:00+08:00",
      "appointment": {
        "id": 42,
        "appointment_number": "APT-2026-000042",
        "status": "scheduled",
        "scheduled_at": "2026-07-30T09:00:00+08:00",
        "duration_minutes": 30
      },
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

Creates a new frame reservation. Requires an active patient link and a confirmed eligible appointment.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Request:**
```json
{
  "appointment_id": "integer (required, exists:appointments,id — must belong to patient)",
  "items": [
    { "product_variant_id": "integer (required, exists:product_variants,id)" }
  ]
}
```

**Validation:**
- `appointment_id`: required; must belong to the authenticated patient's Patient record; must be `scheduled` and not past end time.
- `items`: `required`, `array`, `min:1`, `max:5`.
- Each `product_variant_id` must reference an active frame variant (product_type = frame, is_active = true, variant is_active = true).
- Duplicate variants within a reservation are rejected.
- An active reservation (requested, prepared, tried_on) for the same appointment is rejected.

**Response (201):**
```json
{
  "data": {
    "id": 1,
    "appointment_id": 42,
    "status": "requested",
    "expires_at": null,
    "created_at": "2026-07-27T10:00:00+08:00",
    "appointment": { "id": 42, "appointment_number": "APT-2026-000042", "status": "scheduled", "scheduled_at": "2026-07-30T09:00:00+08:00", "duration_minutes": 30 },
    "items": [ /* same structure as GET */ ]
  }
}
```

---

### POST `/frame-reservations/{reservation}/cancel`

Cancels a reservation. Only `requested` or `prepared` reservations can be cancelled.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Response (200):**
```json
{
  "data": { /* FrameReservationResource with status: "cancelled" */ }
}
```

**Error (422):** `"This reservation cannot be cancelled."` if status is beyond `prepared`.

---

## 11. Prescriptions

**Active patient link required for all endpoints in this section.**

### GET `/prescriptions`

Paginated list of current prescription versions. Superseded versions are excluded.

**Auth:** Required (Sanctum token). **Active patient link required.**

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
          "od": { "value": null, "sphere": "-2.00", "cylinder": "-0.50" },
          "os": { "value": null, "sphere": "-1.75", "cylinder": "-0.25" }
        },
        "add": {
          "od": { "value": null, "sphere": null, "cylinder": null },
          "os": { "value": null, "sphere": null, "cylinder": null }
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

Single prescription, including historical superseded versions. Returns `404` if not patient's.

---

## 12. Quotations

**Active patient link required for all endpoints in this section.**

### GET `/quotations`

Paginated list with latest revision.

**Auth:** Required (Sanctum token). **Active patient link required.**

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
          { "description": "Classic Rectangle Frame", "quantity": 1, "unit_price": 4500.00, "amount": 4500.00 },
          { "description": "Single Vision Lens", "quantity": 1, "unit_price": 4000.00, "amount": 4000.00 }
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
    "revision": { /* same as list */ },
    "created_at": "2026-07-27T10:00:00+08:00"
  }
}
```

---

## 13. Job Orders

**Active patient link required for all endpoints in this section.**

### GET `/job-orders`

Paginated list with items.

**Auth:** Required (Sanctum token). **Active patient link required.**

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
        { "id": 1, "description": "Classic Rectangle Frame", "quantity": 1, "unit_price": 4500.00, "amount": 4500.00 }
      ]
    }
  ]
}
```

**Status values:** `queued`, `in_progress`, `ready_for_dispensing`, `dispensed`, `cancelled`.

**Internal supplier reference:** `supplier_invoice_number` is excluded from patient serialization.

### GET `/job-orders/{id}`

Returns a single job order with items.

---

## 14. Eyewear

**Active patient link required for all endpoints in this section.**

### GET `/eyewear`

Returns the patient's eyewear aggregates with deterministic ordering.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Query parameters:**

| Parameter | Required | Validation | Default |
|---|---|---|---|
| `filter` | No | `current` or `history` | `current` |
| `page` | No | Integer, minimum 1 | `1` |
| `per_page` | No | Integer, 1 through 50 | `15` |

**Current filter** includes: `estimate_available`, `in_preparation`, `ready_for_pickup`.  
**History filter** includes: `dispensed`, `estimate_declined`, `estimate_expired`, `cancelled`.

**Response (200):**
```json
{
  "data": [
    {
      "key": "eyw_01K1D7H4R1V87GJ7D2GCB9QT4X",
      "description": "Classic Rectangle Frame + 1 more",
      "consultation_at": "2026-07-27T09:00:00+08:00",
      "created_at": "2026-07-27T10:00:00+08:00",
      "progress": "in_preparation",
      "payment_status": null,
      "total_amount": "8000.00",
      "balance_due": null,
      "activity_at": "2026-07-27T11:00:00+08:00"
    }
  ],
  "links": { ... },
  "meta": { ... }
}
```

**Progress mapping:**

| Source state | Aggregate progress | Filter |
|---|---|---|
| Presented/accepted Quotation without Job Order | `estimate_available` | Current |
| Queued/in-progress Job Order | `in_preparation` | Current |
| Ready-for-dispensing Job Order | `ready_for_pickup` | Current |
| Dispensed Job Order | `dispensed` | History |
| Declined Quotation without Job Order | `estimate_declined` | History |
| Expired Quotation without Job Order | `estimate_expired` | History |
| Cancelled Job Order | `cancelled` | History |
| Draft Quotation without Job Order | Excluded | Neither |

**Payment status:**

| Active Billing Record state | `payment_status` |
|---|---|
| `unpaid` or `partially_paid` | `balance_due` |
| `paid` | `paid` |
| No active Billing Record | null |

**Ordering:** `activity_at DESC, key ASC`.

---

### GET `/eyewear/{key}`

Returns a single eyewear aggregate by canonical key (`eyw_...`) or migration alias (`jo_{job_order_id}`).

**Auth:** Required (Sanctum token). **Active patient link required.**

**Response (200) — complete linked:** Same as existing Eyewear detail contract with `estimate`, `preparation`, `dispensing`, and `payment_summary` sections.

---

## 15. Billing Records

**Active patient link required for all endpoints in this section.**

### GET `/billing-records`

Paginated list with posted payments.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Query:** `per_page` (default: 15)

**Response (200):** Same as existing Billing Record contract.

**Status values:** `unpaid`, `partially_paid`, `paid`, `voided`.

### GET `/billing-records/{id}`

Returns a single billing record with posted payments.

---

## 16. Conversation

**Active patient link required for all endpoints in this section.**

### GET `/conversation`

Returns (or creates) the patient's single conversation.

**Auth:** Required (Sanctum token). **Active patient link required.**

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

### GET `/conversation/messages`

Returns all messages in the conversation (oldest first). NOT paginated.

**Response (200):** Same as existing message contract.

### POST `/conversation/messages`

Sends a message. Supports `multipart/form-data` for attachments.

**Request:** Same as existing multipart contract with `body`, `attachment`, and `contexts[]`.

### GET `/conversation/attachments/{id}`

Downloads a message attachment. Patient can only download from their own conversation.

---

## 17. Frame Ratings

**Active patient link required for all endpoints in this section.**

### POST `/job-order-items/{id}/rating`

Submits or revises a rating for a frame variant linked to a job order item.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Request and Response:** Same as existing Frame Rating contract.

---

## 18. Error Responses

All API errors use one consistent JSON shape:

```json
{
  "error": {
    "code": "MACHINE_READABLE_CODE",
    "message": "Patient-safe message",
    "details": {}
  }
}
```

### Machine-Readable Error Codes

| Code | HTTP Status | When |
|---|---|---|
| `INVALID_OTP` | 422 | Wrong, expired, or consumed OTP code |
| `OTP_ATTEMPT_LIMIT_REACHED` | 422 | Too many verification attempts on a challenge |
| `OTP_RATE_LIMIT_REACHED` | 429 | Too many OTP requests for this destination/IP |
| `CONTACT_ALREADY_OWNED` | 422 | Contact is already verified by another account |
| `INVITATION_INVALID` | 422 | Invitation token is invalid, expired, revoked, or consumed |
| `ACCOUNT_ALREADY_LINKED` | 422 | Account already has an active patient link |
| `PATIENT_ALREADY_LINKED` | 422 | Patient is already linked to another account |
| `LINK_REQUEST_PENDING` | 422 | An active link request already exists |
| `REQUEST_NOT_OWNED` | 404 | Appointment request does not belong to this account |
| `REQUEST_TERMINAL` | 422 | Request is already accepted/rejected/cancelled/expired |
| `PATIENT_RESOLUTION_REQUIRED` | 422 | Unlinked request must be resolved to a patient first |
| `SLOT_UNAVAILABLE` | 422 | Requested appointment slot is no longer available |
| `ACTIVE_PATIENT_LINK_REQUIRED` | 403 | Route requires an active patient link |
| `ACTIVE_REQUEST_LIMIT_REACHED` | 422 | Maximum active pending requests reached |
| `LAST_CONTACT_REMAINING` | 422 | Cannot remove the last verified login contact |
| `CONTACT_NOT_VERIFIED` | 422 | Cannot set an unverified contact as primary |
| `REQUEST_NOT_CANCELLABLE` | 422 | Only pending requests can be cancelled |

### Standard HTTP Status Codes

| Status | When |
|---|---|
| `401` | Missing or invalid Sanctum token |
| `403` | Authenticated but not authorized (when existence disclosure is safe) |
| `404` | Resource not found or not owned (preferred for enumeration-safe misses) |
| `422` | Validation or state error |
| `429` | Rate limit exceeded |

---

## 19. Coordinated Breaking Changes

The following routes are **removed** in the coordinated Android cutover:

| Removed Route | Replacement |
|---|---|
| `POST /register` | `POST /auth/registration/otp` + `POST /auth/registration/verify` |
| `POST /login` | `POST /auth/login` + `POST /auth/login/verify` |
| `GET /appointment-types` | Internal only; no patient-facing replacement |
| `POST /appointments` | `POST /appointment-requests` |
| `GET /appointments/{id}/intake` | Retired; no replacement |
| `PUT /appointments/{id}/intake` | Retired; no replacement |
| `POST /appointments/{id}/intake/submit` | Retired; no replacement |

### New Routes Added

| New Route | Purpose |
|---|---|
| `POST /auth/registration/otp` | Request registration OTP |
| `POST /auth/registration/verify` | Verify OTP and create account |
| `POST /auth/login` | Password login (returns step-up) |
| `POST /auth/login/verify` | Verify login OTP, issue token |
| `POST /auth/password-recovery/otp` | Request recovery OTP |
| `POST /auth/password-recovery/verify` | Verify recovery OTP, reset password |
| `POST /logout-all` | Revoke all patient device tokens |
| `GET /account/contacts` | List contacts |
| `POST /account/contacts/otp` | Request contact verification OTP |
| `POST /account/contacts/verify` | Verify contact OTP |
| `PATCH /account/contacts/{id}/primary` | Set primary contact |
| `DELETE /account/contacts/{id}` | Remove contact |
| `GET /account/link` | Get link state |
| `POST /patient-link-requests` | Submit link request |
| `GET /patient-link-requests/current` | Get current link request |
| `POST /patient-invitations/acceptance/otp` | Request invitation OTP |
| `POST /patient-invitations/accept` | Accept invitation and activate link |
| `GET /appointment-request-availability` | Get available slots for requests |
| `GET /appointment-requests` | List own requests |
| `POST /appointment-requests` | Create request |
| `GET /appointment-requests/{id}` | Get request detail |
| `POST /appointment-requests/{id}/cancel` | Cancel request |

### Modified Routes

| Route | Change |
|---|---|
| `GET /me` | Now returns `link_status`, `first_name`, `last_name`; clinical fields only when linked |
| `PATCH /me` | Only `first_name` and `last_name` editable; contact changes use dedicated endpoints |
| `GET /appointment-availability` | Now includes request holds in capacity; removes `appointment_type_id` requirement |
| `GET /appointments` | Now requires active patient link |
| `GET /appointments/{id}` | Now requires active patient link |
| `POST /appointments/{id}/cancel` | Now requires active patient link |
| `POST /appointments/{id}/reschedule` | Duration derived from appointment; no `appointment_type_id` |

---

## 20. Retired Features

The following old mobile features/routes are **intentionally retired**:

| Feature | Status |
|---|---|
| Direct `POST /appointments` | Retired. All mobile bookings use appointment requests. |
| Patient-selectable `GET /appointment-types` | Retired. Type is internal, resolved by staff at acceptance. |
| Patient intake routes (`/appointments/{id}/intake`) | Retired. Clinical data moves to Encounter. |
| Patient-completed intake forms | Retired. Only free-text reason for visit at booking. |
| Accessories and orders (`/orders`, `/accessories`) | Retired. |
| Billing PDF | Retired. |
| Clinic feedback (`/feedback`) | Retired. |
| Appointment contact-note editing | Retired. |
| Explicit message mark-read | Retired. |
| `/api/user` (unversioned) | Absent. |
| `/api/v1/patient/profile` | Absent. Profile is `/api/v1/me`. |
| Notification endpoints | Retired from mobile API. |

---

## 21. Clarifications

### Registration creates no Patient
Registration OTP verification creates only a `User` with the `patient` role and its verified primary contact method. No clinical `Patient` record is created. Patients are created through staff duplicate review or invitation acceptance.

### Active patient link boundary
Routes in sections 7-17 require an active patient link (`patients.user_id`). Unlinked accounts can only access account management (sections 1-6). The `link_status` field on `/me` reflects the current state.

### Appointment requests vs confirmed appointments
Every mobile booking creates an `AppointmentRequest`, not an `Appointment`. Staff accept requests to create confirmed `Appointment` records. Only confirmed appointments appear in the confirmed appointments list and calendar.

### No appointment type selection by patients
The mobile API does not expose `appointment_type_id` for booking. The internal Appointment Type is resolved by staff when accepting the request. The system may prefill `New Patient` when the resolved patient has no fulfilled clinical visit.

### Reason for visit
Appointment requests require a free-text `reason_for_visit` (max 1000 characters). This is copied to the confirmed Appointment and prefills the Encounter chief complaint at check-in. It remains clinician-editable.

### Identity verification
The `/me` endpoint returns `link_status` and, when linked, clinical demographics from the authoritative Patient record. Account profile edits (`first_name`, `last_name`) never silently update the clinic Patient record.

### OTP challenge lifecycle
Challenges expire after 10 minutes, allow 5 verification attempts, and are consumed on successful verification. Resend invalidates earlier pending challenges for the same purpose/destination. Rate limits: 3 per 15 minutes per destination, 10 per 15 minutes per IP, 10 per destination per day.

### Sanctum token lifecycle
Tokens are device-labelled, expire after 30 days, and are limited to 5 per patient account. Same-installation replacement is supported. Password recovery and primary-contact replacement revoke other patient tokens.

### Contact normalization
Email addresses are trimmed and lowercased. Phone numbers are normalized to canonical E.164 (`+63...`) before uniqueness checks and blind-index computation.

---

## Appendix: Complete Route List

### Public Authentication (no token required)

```
POST   /api/v1/auth/registration/otp
POST   /api/v1/auth/registration/verify
POST   /api/v1/auth/register
POST   /api/v1/auth/login
POST   /api/v1/auth/login/verify
POST   /api/v1/auth/password-recovery/otp
POST   /api/v1/auth/password-recovery/verify
POST   /api/v1/register                       (legacy backward compat)
POST   /api/v1/login                          (legacy backward compat)
```

### Authenticated Account-Only (token required, no active link needed)

```
POST   /api/v1/logout
POST   /api/v1/logout-all
GET    /api/v1/me
PATCH  /api/v1/me
GET    /api/v1/account/contacts
POST   /api/v1/account/contacts/otp
POST   /api/v1/account/contacts/verify
PATCH  /api/v1/account/contacts/{contact}/primary
DELETE /api/v1/account/contacts/{contact}
GET    /api/v1/account/link
POST   /api/v1/patient-link-requests
GET    /api/v1/patient-link-requests/current
POST   /api/v1/patient-invitations/acceptance/otp
POST   /api/v1/patient-invitations/accept
GET    /api/v1/appointment-request-availability
GET    /api/v1/appointment-requests
POST   /api/v1/appointment-requests
GET    /api/v1/appointment-requests/{appointmentRequest}
POST   /api/v1/appointment-requests/{appointmentRequest}/cancel
```

### Active Patient Link Required (token + active link)

```
GET    /api/v1/appointment-availability
GET    /api/v1/appointments
GET    /api/v1/appointments/{appointment}
POST   /api/v1/appointments/{appointment}/cancel
POST   /api/v1/appointments/{appointment}/reschedule

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
GET    /api/v1/billing-records
GET    /api/v1/billing-records/{billingRecord}

GET    /api/v1/eyewear
GET    /api/v1/eyewear/{key}

GET    /api/v1/conversation
GET    /api/v1/conversation/messages
POST   /api/v1/conversation/messages
GET    /api/v1/conversation/attachments/{attachment}

POST   /api/v1/job-order-items/{item}/rating
```

**Route count:** 9 public + 12 account-only + 25 active-link = **46 routes total.**
