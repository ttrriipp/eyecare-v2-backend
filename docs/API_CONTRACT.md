# Eyecare Mobile API v1 — Authoritative Contract

> **Backend version:** Current repository state (2026-08-03) — introduces two-stage OTP-based patient registration, phone-primary patient authentication, contact management, patient linking, appointment requests, authenticated step-up for sensitive changes, and active-link route boundary.
> **Base URL:** `/api/v1`
> **Auth:** Laravel Sanctum bearer tokens
> **Timezone:** `Asia/Manila` (configurable via `app.timezone`)
> **Timestamps:** ISO 8601 (`2026-07-27T10:00:00+08:00`)
> **Dates:** `Y-m-d` format (`2026-07-27`)

---

## Table of Contents

1. [Authentication](#1-authentication)
   - [Registration Flow](#registration-flow-two-stage)
2. [Profile (me)](#3-profile-me)
3. [Sensitive Changes (Step-up)](#4-sensitive-changes-step-up)
4. [Contact Management](#5-contact-management)
5. [Patient Linking](#6-patient-linking)
6. [Patient Invitations](#7-patient-invitations)
7. [Appointment Requests](#8-appointment-requests)
8. [Appointment Availability](#9-appointment-availability)
9. [Confirmed Appointments](#10-confirmed-appointments)
10. [Frames](#11-frames)
11. [Frame Reservations](#12-frame-reservations)
12. [Prescriptions](#13-prescriptions)
13. [Quotations](#14-quotations)
14. [Job Orders](#15-job-orders)
15. [Eyewear](#16-eyewear)
16. [Billing Records](#17-billing-records)
17. [Conversation](#18-conversation)
18. [Frame Ratings](#19-frame-ratings)
19. [Error Responses](#20-error-responses)
20. [Coordinated Breaking Changes](#21-coordinated-breaking-changes)
21. [Retired Features](#22-retired-features)
22. [Clarifications](#23-clarifications)

---

## 1. Authentication

### Registration Flow (Two-Stage)

**Stage 1: Verify contact ownership**

`POST /auth/registration/otp` → `POST /auth/registration/verify` → returns `registration_token`

**Stage 2: Complete account**

`POST /auth/register` with `registration_token` + profile data → returns `token` + `user`

---

### POST `/auth/registration/otp`

Requests a registration OTP for an available phone number. An already-owned
phone is rejected before an OTP is sent, so the client does not proceed to the
registration form.

**Request:**
```json
{
  "contact_type": "phone (required)",
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

**Response (422) — phone already registered:**
```json
{
  "error": {
    "code": "CONTACT_ALREADY_OWNED",
    "message": "This phone number is already registered."
  }
}
```

**Behavior:**
- An owned phone returns `422 CONTACT_ALREADY_OWNED` and no OTP challenge is created.
- Phone OTP delivery is queued. In `local`/`testing`, the delivery job logs the
  OTP code for development testing; other environments log only the masked
  phone until an SMS provider is configured.

---

### POST `/auth/registration/verify`

Verifies the OTP and returns a short-lived `registration_token` (30-minute expiry). Does **not** create any account.

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
  "data": {
    "registration_token": "opaque-hex-string",
    "expires_at": "2026-07-27T10:30:00+08:00",
    "contact_type": "phone"
  }
}
```

**Behavior:**
- 5 maximum verification attempts per challenge.
- The `registration_token` proves contact ownership and is consumed on use.

---

### POST `/auth/register`

Completes registration using the proof token. Creates the account and returns a Sanctum token. The verified phone from the challenge becomes the primary login contact. An email address may be supplied as an optional, initially unverified contact. Does **not** create a Patient record.

**Request:**
```json
{
  "registration_token": "string (required, from /auth/registration/verify)",
  "first_name": "string (required, max:255)",
  "middle_name": "string (nullable, max:255)",
  "last_name": "string (required, max:255)",
  "date_of_birth": "date (required, before:today, Y-m-d)",
  "email": "string (nullable, valid email, max:255)",
  "password": "string (required, confirmed, min:12)",
  "password_confirmation": "string (required)",
  "privacy_policy_version": "string (required)",
  "terms_version": "string (required)",
  "invitation_code": "string (nullable)",
  "device_name": "string (nullable, max:255)",
  "installation_id": "string (nullable, max:255)"
}
```

**Response (201):**
```json
{
  "data": {
    "token": "1|abc123...",
    "user": { /* PatientAccountResource */ },
    "email_verification_required": false
  }
}
```

**Behavior:**
- If `invitation_code` is provided and valid, the account is linked to the patient immediately.
- If the contact is already owned, returns `422 CONTACT_ALREADY_OWNED` without
  creating an account, consuming the registration token, or issuing a token.
- The registration proof must be for a verified phone number; there is no phone field in the registration form.
- `email` is optional. When supplied, it is stored as a pending, non-primary contact and `email_verification_required` is `true`.
- The optional email is verified after authentication through `/account/contacts/otp` and `/account/contacts/verify`; it is never a login identifier.
- If the optional email is already owned, returns `422 CONTACT_ALREADY_OWNED` without creating an account, consuming the registration token, or issuing a token.
- `privacy_policy_version` and `terms_version` are validated against server configuration (`config('app.privacy_policy_version')` and `config('app.terms_version')`). The authoritative URLs are recorded from server config, not client submission.
- Android discovers current versions/URLs via `GET /auth/policies` before presenting checkboxes.
- Creates only the `User` with `patient` role, its verified primary phone contact, and any optional pending email contact.

---

### POST `/auth/login`

Authenticates a patient with phone number and password. May return a step-up challenge or a token directly. Email addresses are not accepted as login identifiers.

**Request:**
```json
{
  "contact_value": "phone number (required)",
  "password": "string (required)",
  "device_name": "string (nullable, max:255)",
  "installation_id": "string (nullable, max:255)"
}
```

**Response (200) — step-up required:**
```json
{
  "data": {
    "step_up_required": true,
    "challenge_id": "opaque-uuid",
    "expires_at": "2026-07-27T10:10:00+08:00"
  }
}
```

**Response (200) — trusted device (no step-up):**
```json
{
  "data": {
    "step_up_required": false,
    "token": "1|abc123...",
    "user": { /* PatientAccountResource */ }
  }
}
```

**Behavior:**
- Response is identical for wrong password and unknown contact (enumeration-safe).
- Only a verified phone contact can authenticate. An email value is rejected.
- A trusted device (same non-empty `installation_id`, not expired) may skip step-up; otherwise a login OTP is required.
- Rate limited: `throttle:login` (5 per minute).

---

### POST `/auth/login/verify`

Verifies the login OTP step-up and issues a device-labelled Sanctum token.

**Request:**
```json
{
  "challenge_id": "string (required)",
  "code": "string (required, 6 digits)",
  "device_name": "string (nullable, max:255)",
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

Requests a recovery OTP for a phone number.

**Request:**
```json
{
  "contact_value": "phone number (required)"
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
- Only verified phone contacts on patient accounts are eligible. Email addresses are not accepted for password recovery.

---

### POST `/auth/password-recovery/verify`

Verifies the recovery OTP and resets the password. Revokes all other patient device tokens.

**Request:**
```json
{
  "challenge_id": "string (required)",
  "code": "string (required, 6 digits)",
  "password": "string (required, confirmed, min:12)",
  "password_confirmation": "string (required)",
  "device_name": "string (nullable, max:255)",
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
- Revokes all other patient device tokens on successful recovery.
- Issues one new token for the current device (labelled with `device_name`/`installation_id`).

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

### GET `/auth/policies`

Returns the current Terms of Service and Privacy Policy metadata. Android uses this to discover the current versions and URLs before presenting checkboxes.

**Auth:** None (public endpoint).

**Response (200):**
```json
{
  "data": {
    "privacy_policy": {
      "version": "2026-08",
      "url": "https://eyecare.example.com/privacy",
      "effective_date": "2026-08-01"
    },
    "terms_of_service": {
      "version": "2026-08",
      "url": "https://eyecare.example.com/terms",
      "effective_date": "2026-08-01"
    }
  }
}
```

**Behavior:**
- Values are server-authoritative from `config('app.*')`.
- Registration validates that submitted `privacy_policy_version` and `terms_version` match these values.

---

## 3. Profile (me)

### GET `/me`

Returns the authenticated account's profile, link state, and (when linked) read-only clinical demographics from the authoritative Patient record. The response schema is identical to `PatientAccountResource` used in all auth responses.

**Auth:** Required (Sanctum token).

**Response (200) — linked account:**
```json
{
  "data": {
    "id": 1,
    "name": "Ana Reyes",
    "first_name": "Ana",
    "middle_name": null,
    "last_name": "Reyes",
    "email": "ana@example.com",
    "phone": "09171234567",
    "role": "patient",
    "date_of_birth": "1990-05-15",
    "link_status": "linked",
    "privacy_policy_version": "2026-08",
    "privacy_accepted_at": "2026-07-27T10:00:00+08:00",
    "linked_patient": {
      "patient_number": "PAT-2026-000001",
      "full_name": "Ana Reyes",
      "date_of_birth": "1990-05-15",
      "gender": "female",
      "occupation": "Teacher",
      "address": "123 Main St, Manila",
      "phone": "09171234567",
      "contact_email": "ana@example.com"
    }
  }
}
```

**Response (200) — unlinked account:**
```json
{
  "data": {
    "id": 1,
    "name": "Ana Reyes",
    "first_name": "Ana",
    "middle_name": null,
    "last_name": "Reyes",
    "email": "ana@example.com",
    "phone": "09171234567",
    "role": "patient",
    "date_of_birth": "1990-05-15",
    "link_status": "unlinked",
    "privacy_policy_version": "2026-08",
    "privacy_accepted_at": "2026-07-27T10:00:00+08:00",
    "linked_patient": null
  }
}
```

### PatientAccountResource schema

**Account fields (always present):**

| Field | Type | Nullable | Editable via PATCH /me | Notes |
|-------|------|----------|----------------------|-------|
| `id` | integer | no | no | User ID |
| `name` | string | no | no | Response-only compatibility value derived from first + middle + last; not stored as `users.name` |
| `first_name` | string | yes | yes | Account first name |
| `middle_name` | string | yes | no | Account middle name |
| `last_name` | string | yes | yes | Account last name |
| `email` | string | yes | no | Primary verified email contact, if one is configured; not a login identifier |
| `phone` | string | yes | no | Primary verified phone contact and patient login identifier |
| `role` | string | no | no | Always `patient` |
| `date_of_birth` | string | yes | no | Account DOB, `Y-m-d` format |
| `link_status` | string | no | no | `linked`, `pending_review`, or `unlinked` |
| `privacy_policy_version` | string | yes | no | Accepted privacy policy version |
| `privacy_accepted_at` | string | yes | no | ISO 8601 timestamp |
| `linked_patient` | object \| null | yes | no | Read-only clinical demographics; `null` when unlinked |

**`linked_patient` fields (read-only, present only when `link_status` is `linked`):**

| Field | Type | Nullable | Notes |
|-------|------|----------|-------|
| `patient_number` | string | no | `PAT-YYYY-NNNNNN` |
| `full_name` | string | no | Derived from Patient's structured names |
| `date_of_birth` | string | yes | `Y-m-d` format |
| `gender` | string | yes | `male`, `female`, `other`, or null |
| `occupation` | string | yes | |
| `address` | string | yes | |
| `phone` | string | yes | Clinic patient phone (may differ from account phone) |
| `contact_email` | string | yes | Clinic patient email (may differ from account email) |

**Important:** `linked_patient` fields are read-only clinical demographics from the authoritative Patient record. They are never editable via the mobile API. PATCH /me only updates account `first_name` and `last_name`. Contact changes use the dedicated Contact Management endpoints.

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
- Only `first_name` and `last_name` are editable via this endpoint.
- Contact changes use `/account/contacts/*` endpoints.
- Clinical Patient demographics are read-only and never editable via the mobile API.

---

## 4. Sensitive Changes (Step-up)

Certain security-sensitive operations require an authenticated step-up OTP to prove the current session owner authorized the change.

**Step-up token delivery:** After verifying the OTP, the client receives a `step_up_token`. For mutations that require step-up, supply it via the `X-Step-Up-Token` HTTP header. This works cleanly with `DELETE` and `PATCH` methods.

**Token properties:**
- **User-bound:** Only valid for the user who requested it
- **Purpose-bound:** Issued for `sensitive_change` purpose
- **Short-lived:** 15-minute expiry
- **Single-use:** Consumed on first successful validation

**Endpoints requiring step-up:**
| Endpoint | Method | Header |
|----------|--------|--------|
| `/account/contacts/otp` | POST | `X-Step-Up-Token` |
| `/account/contacts/{id}/primary` | PATCH | `X-Step-Up-Token` |
| `/account/contacts/{id}` | DELETE | `X-Step-Up-Token` |
| `/auth/password` | POST | `X-Step-Up-Token` |

---

### POST `/auth/step-up/otp`

Requests a step-up OTP sent to the user's primary verified contact.

**Auth:** Required (Sanctum token).

**Request:**
```json
{
  "purpose": "sensitive_change"
}
```

**Response (200):**
```json
{
  "data": {
    "challenge_id": "opaque-uuid",
    "expires_at": "2026-07-27T10:15:00+08:00",
    "contact_type": "email",
    "masked_contact": "a***@example.com"
  }
}
```

---

### POST `/auth/step-up/verify`

Verifies the step-up OTP and returns a short-lived `step_up_token` (15-minute expiry).

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
  "data": {
    "step_up_token": "opaque-hex-string",
    "expires_in": 900
  }
}
```

---

### POST `/auth/password`

Changes the authenticated user's password. Requires a valid `X-Step-Up-Token` header.

**Auth:** Required (Sanctum token). **Step-up required** (via `X-Step-Up-Token` header).

**Request:**
```json
{
  "current_password": "string (required)",
  "password": "string (required, confirmed, min:12)",
  "password_confirmation": "string (required)"
}
```

**Response (200):**
```json
{
  "data": {
    "message": "Password changed successfully."
  }
}
```

**Behavior:**
- Revokes all other patient device tokens after password change.
- The `X-Step-Up-Token` header must contain a valid token from a recent `/auth/step-up/verify` call (15-minute expiry).

---

## 5. Contact Management

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
- A registration email remains pending until it is verified through the contact endpoints; even after verification, email is not a login identifier.

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

For the optional email collected during phone registration, this endpoint is the
authenticated verification step. It does not change the phone-only login rule.

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

Sets a verified contact as the primary notification contact. This does not
change phone-only login: email can never be used to authenticate.

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

## 6. Patient Linking

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

## 7. Patient Invitations

### POST `/patient-invitations/acceptance/otp`

Requests an OTP for accepting a patient invitation. The invitation code identifies the target contact.

**Auth:** Required (Sanctum token).

**Request:**
```json
{
  "invitation_code": "string (required)"
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
  "invitation_code": "string (required)",
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

## 8. Appointment Requests

### GET `/appointment-request-availability`

Returns server-generated time slots for a given date, accounting for clinic hours, provider hours, existing appointments, and unexpired pending request holds.

**Auth:** Required (Sanctum token). No active patient link required; linked and unlinked patient accounts may use this endpoint.

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
- Identity snapshots and contact details are excluded from list responses.

---

### POST `/appointment-requests`

Creates a new appointment request.

**Auth:** Required (Sanctum token).

**Request:**
```json
{
  "scheduled_at": "datetime (required, must match a returned available slot)",
  "reason_for_visit": "string (required, max:1000)",
  "identity": {
    "phone": "string (required when identity is supplied; must match the account's verified phone)",
    "email": "string (optional, nullable, valid email, max:255)",
    "first_name": "string (required when identity is supplied, max:255)",
    "middle_name": "string (nullable, max:255)",
    "last_name": "string (required when identity is supplied, max:255)",
    "date_of_birth": "date (required when identity is supplied, before:today, Y-m-d)",
    "gender": "male | female | other (required when identity is supplied)",
    "occupation": "string (required when identity is supplied, max:255)",
    "address": "string (required when identity is supplied, max:255; home address)"
  }
}
```

**Identity object rules:**
- `identity` is optional for unlinked accounts. When omitted, the server uses the account's current structured name, date of birth, phone, optional email, and address as fallback; unavailable demographic fields remain `null` in the staff-only snapshot.
- When `identity` is present, phone, first name, last name, date of birth, gender, occupation, and home address are required. Middle name is nullable and email is optional.
- `identity` is **prohibited** when the account is already linked to a patient. Linked requests use the authoritative Patient record.
- Unknown keys inside `identity` fail validation.
- Client-supplied `patient_id` or verification fields are not accepted.
- The server derives the canonical phone from the account's verified `PatientAccountContact` record. If `phone` is supplied, it is checked against that server-side value; the client cannot change the verified contact.
- An optional email is captured in the encrypted request snapshot but is not treated as a verified contact claim.

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

**Notes:**
- The response does not include identity, contact, or snapshot data.
- Identity and contact snapshots are stored encrypted and are staff-only.

**Behavior:**
- Creates a 24-hour capacity hold on the requested slot.
- For linked accounts, `patient_id` is copied from the active link.
- For unlinked accounts, `patient_id` remains `null`.
- For unlinked accounts, an encrypted identity snapshot is stored containing phone, optional email, all structured name fields, date of birth, gender, occupation, home address, and server-derived verified-contact metadata.
- Maximum 2 active pending requests per account.
- Rate limited per account and per IP.
- Does **not** create a Patient or an Appointment.

**Errors:**
- `422 SLOT_UNAVAILABLE`: The requested slot is no longer available.
- `422 ACTIVE_REQUEST_LIMIT_REACHED`: Maximum active pending requests reached.
- `422 IDENTITY_NOT_ALLOWED`: Identity object provided for a linked account.
- `422 INVALID_IDENTITY`: Missing required identity fields or invalid date of birth.
- `422 NO_VERIFIED_CONTACT`: No unique verified primary contact found on the account.
- `422 NO_VERIFIED_PHONE` or phone validation error: The account has no verified phone or the supplied phone does not match it.

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

## 9. Appointment Availability

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

## 10. Confirmed Appointments

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

## 11. Frames

### GET `/frames`

Paginated list of active AR-eligible frames. Frame browsing is available to any authenticated account, including an account that has not been linked to a clinic Patient record.

**Auth:** Required (Sanctum token). No active patient link required.

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

**Auth:** Required (Sanctum token). No active patient link required.

Browsing the catalog does not grant access to frame reservations. Reservation
endpoints remain restricted to accounts with an active patient link.

---

## 12. Frame Reservations

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

## 13. Prescriptions

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

## 14. Quotations

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

## 15. Job Orders

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

## 16. Eyewear

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

## 17. Billing Records

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

## 18. Conversation

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

## 19. Frame Ratings

**Active patient link required for all endpoints in this section.**

### POST `/job-order-items/{id}/rating`

Submits or revises a rating for a frame variant linked to a job order item.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Request and Response:** Same as existing Frame Rating contract.

---

## 20. Error Responses

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

## 21. Coordinated Breaking Changes

The following routes are **removed** in the coordinated Android cutover:

| Removed Route | Replacement |
|---|---|
| `POST /register` | Two-stage: `POST /auth/registration/otp` → `POST /auth/registration/verify` → `POST /auth/register` |
| `POST /login` | `POST /auth/login` → `POST /auth/login/verify` |
| `GET /appointment-types` | Internal only; no patient-facing replacement |
| `POST /appointments` | `POST /appointment-requests` |
| `GET /appointments/{id}/intake` | Retired; no replacement |
| `PUT /appointments/{id}/intake` | Retired; no replacement |
| `POST /appointments/{id}/intake/submit` | Retired; no replacement |

### New Routes Added

| New Route | Purpose |
|---|---|
| `POST /auth/registration/otp` | Request phone registration OTP |
| `POST /auth/registration/verify` | Verify OTP, return `registration_token` (does not create account) |
| `POST /auth/register` | Complete registration with `registration_token` and profile data |
| `POST /auth/login` | Password login (returns step-up challenge or token) |
| `POST /auth/login/verify` | Verify login OTP, issue device token |
| `POST /auth/password-recovery/otp` | Request recovery OTP |
| `POST /auth/password-recovery/verify` | Verify recovery OTP, reset password, issue token |
| `GET /auth/policies` | Get current Terms/Privacy versions and URLs |
| `POST /auth/step-up/otp` | Request sensitive-change OTP |
| `POST /auth/step-up/verify` | Verify step-up OTP, get `step_up_token` |
| `POST /auth/password` | Change password (requires `X-Step-Up-Token` header) |
| `POST /logout-all` | Revoke all patient device tokens |
| `GET /account/contacts` | List contacts |
| `POST /account/contacts/otp` | Request contact verification OTP (requires `X-Step-Up-Token`) |
| `POST /account/contacts/verify` | Verify contact OTP |
| `PATCH /account/contacts/{id}/primary` | Set primary contact (requires `X-Step-Up-Token`) |
| `DELETE /account/contacts/{id}` | Remove contact (requires `X-Step-Up-Token`) |
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
| `GET /me` | Returns `PatientAccountResource` schema; `link_status`, structured names |
| `PATCH /me` | Only `first_name` and `last_name` editable |
| `GET /appointment-availability` | Now includes request holds in capacity |
| `GET /appointments` | Requires active patient link |
| `GET /appointments/{id}` | Requires active patient link |
| `POST /appointments/{id}/cancel` | Requires active patient link |
| `POST /appointments/{id}/reschedule` | Duration derived from appointment |

---

## 22. Retired Features

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

## 23. Clarifications

### Registration is two-stage
`POST /auth/registration/verify` verifies the phone OTP and returns a
`registration_token` (30-minute expiry). It does **not** create any account.
`POST /auth/register` takes the `registration_token` plus profile data and
creates the User with the `patient` role, a verified primary phone, and an
optional pending email contact. No clinical `Patient` record is created.

Once the phone proof is submitted to `/auth/register`, an already-owned phone
or optional email is rejected with `CONTACT_ALREADY_OWNED`; the final phone
check protects against a race after OTP issuance. The existing account is
never signed in by the registration endpoint.

### Active patient link boundary
Patient-specific clinical resources, confirmed appointments, and all frame
reservation endpoints require an active patient link (`patients.user_id`).
Appointment requests and frame catalog browsing are account-only routes, so an
authenticated unlinked account may browse frames but cannot reserve one. The
`link_status` field on `/me` reflects the current state.

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
Tokens are device-labelled, expire after 30 days, and are limited to 5 per patient account. Same-installation replacement is supported. A non-expired token for an installation allows password login without another OTP. Password recovery and primary-contact replacement revoke other patient tokens.

### Contact normalization
Email addresses are trimmed and lowercased. Phone numbers are normalized to canonical E.164 (`+63...`) before uniqueness checks and blind-index computation.

---

## Appendix: Complete Route List

### Public Authentication (no token required)

```
POST   /api/v1/auth/registration/otp          Request phone registration OTP
POST   /api/v1/auth/registration/verify       Verify OTP, get registration_token
POST   /api/v1/auth/register                  Complete registration with profile
POST   /api/v1/auth/login                     Phone/password login (step-up or token)
POST   /api/v1/auth/login/verify              Verify login OTP, issue token
POST   /api/v1/auth/password-recovery/otp     Request phone recovery OTP
POST   /api/v1/auth/password-recovery/verify  Reset password, issue token
GET    /api/v1/auth/policies                  Get Terms/Privacy versions and URLs
```

### Authenticated Account-Only (token required, no active link needed)

```
POST   /api/v1/logout                         Revoke current token
POST   /api/v1/logout-all                     Revoke all device tokens
GET    /api/v1/me                             Account profile (PatientAccountResource)
PATCH  /api/v1/me                             Update first/last name
POST   /api/v1/auth/step-up/otp               Request sensitive-change OTP
POST   /api/v1/auth/step-up/verify            Get step_up_token (15min)
POST   /api/v1/auth/password                  Change password (X-Step-Up-Token header)
GET    /api/v1/account/contacts               List contacts
POST   /api/v1/account/contacts/otp           Request contact OTP (X-Step-Up-Token header)
POST   /api/v1/account/contacts/verify        Verify contact OTP
PATCH  /api/v1/account/contacts/{id}/primary  Set primary (X-Step-Up-Token header)
DELETE /api/v1/account/contacts/{id}          Remove contact (X-Step-Up-Token header)
GET    /api/v1/account/link                   Get link state
POST   /api/v1/patient-link-requests          Submit link request
GET    /api/v1/patient-link-requests/current   Get current link request
POST   /api/v1/patient-invitations/acceptance/otp  Request invitation OTP
POST   /api/v1/patient-invitations/accept     Accept invitation and link
GET    /api/v1/appointment-request-availability Get request availability
GET    /api/v1/appointment-requests            List own requests
POST   /api/v1/appointment-requests            Create request
GET    /api/v1/appointment-requests/{id}       Get request detail
POST   /api/v1/appointment-requests/{id}/cancel  Cancel request
GET    /api/v1/frames                         List frames
GET    /api/v1/frames/{id}                    Get frame detail
```

### Active Patient Link Required (token + active link)

```
GET    /api/v1/appointment-availability        Reschedule availability
GET    /api/v1/appointments                   List confirmed appointments
GET    /api/v1/appointments/{id}              Get appointment detail
POST   /api/v1/appointments/{id}/cancel       Cancel appointment
POST   /api/v1/appointments/{id}/reschedule   Reschedule appointment

GET    /api/v1/frame-reservations             List reservations
POST   /api/v1/frame-reservations             Create reservation
POST   /api/v1/frame-reservations/{id}/cancel Cancel reservation

GET    /api/v1/prescriptions                  List prescriptions
GET    /api/v1/prescriptions/{id}             Get prescription
GET    /api/v1/quotations                     List quotations
GET    /api/v1/quotations/{id}                Get quotation
GET    /api/v1/optical-orders                 List optical orders
GET    /api/v1/optical-orders/{id}            Get optical order

GET    /api/v1/conversation                   Get conversation
GET    /api/v1/conversation/messages          List messages
POST   /api/v1/conversation/messages          Send message
GET    /api/v1/conversation/attachments/{id}  Download attachment

POST   /api/v1/optical-order-items/{id}/rating Submit frame rating
POST   /api/v1/job-order-items/{id}/rating    Submit frame rating (legacy)
```

**Route count:** 8 public + 24 account-only + 20 active-link = **52 routes total.**
