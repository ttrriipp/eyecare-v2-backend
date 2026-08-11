# EyeCare Mobile API v1 — Authoritative Contract

> **Backend version:** Current repository state (2026-08-11) — optical commerce and dispensing implementation complete, with resilient patient invitation linking and additive API rate-limit errors. Internal optical data (eyewear specifications, dispensing measurements, lot details, supplier references, approval/verification metadata, balance-override reasons) remains excluded from patient resources. Payment summary reflects strict overpayment rejection (balance no longer clamps to zero). Dispensing events now snapshot balance-override attribution for admin releases.
>
> **Previous version (2026-08-07):** Two-stage OTP-based patient registration, phone-primary patient authentication, contact management, patient linking, appointment requests, authenticated step-up for sensitive changes, and active-link route boundary. Quotation items now also expose `product_variant_id`, `lens_category_id`, and `service_id` catalog references. No route or response-shape changes since 2026-08-05; frame reservation `expires_at` semantics (§12) corrected to match actual behavior.
>
> **Drift audit closed 2026-08-07.** The 2026-08-07 audit found §14–§15 describing unbuilt behavior; every flagged item has since shipped. `?filter=` works on both quotations and optical-orders, optical-order items expose `product_variant_id`/`is_rateable`/`rating`, `payment_summary.is_overdue` is present, `payment_summary.status` returns the machine-readable enum value, and `POST /optical-order-items/{id}/rating` returns a sanitized `FrameRatingResource` with `product_variant_id` optional (derived from the route item when omitted) instead of leaking moderation fields. Any `⚠️` marker remaining below this line is stale — flag it for removal on sight rather than trusting it.
>
> **Shipped 2026-08-07:** patient-submitted visit feedback — `POST /appointments/{id}/rating` plus `is_rateable`/`rating` on `AppointmentResource`. See §10. Design rationale is in `docs/specs/mobile-visit-feedback-spec.md`, but that spec's own tasks checklist is stale (unchecked despite the work landing).
>
> **Also shipped:** `GET /frames` and `GET /frames/{id}` now return `average_rating`/`rating_count` per product (§11) — corrected here 2026-08-07 after this note wrongly called that surface still write-only. **Known bug:** the aggregate excludes hidden ratings' stars entirely rather than just their comments, contradicting the documented moderation model — see §11.
>
> **Shipped 2026-08-11:** patient invitation acceptance is bound to the
> authenticated account's verified invited contact and committed atomically
> under row locks. A successful acceptance leaves the original Sanctum token
> valid, so the same token can immediately call `/me` and receive
> `link_status: linked`. Retrying acceptance for that same account is
> idempotent, even when the original OTP challenge has already been consumed.
> Invitation and API rate-limit responses now include stable error codes and a
> `Retry-After` header; middleware-backed limits retain the standard
> `X-RateLimit-*` headers.
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
14. [Optical Orders](#15-optical-orders)
15. [Conversation](#16-conversation)
16. [Error Responses](#17-error-responses)
17. [Coordinated Breaking Changes](#18-coordinated-breaking-changes)
18. [Retired Features](#19-retired-features)
19. [Clarifications](#20-clarifications)

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

**Rate limit:** 300 requests per minute per authenticated account. A normal
mobile bootstrap burst may repeat this request; a limit response uses the
standard rate-limit error shape with `API_RATE_LIMIT_REACHED` and
`Retry-After`.

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

**Rate limit:** 120 requests per minute per authenticated account.

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

**Rate limit:** 5 requests per minute per authenticated account. A limit
response is `429 OTP_RATE_LIMIT_REACHED` and includes `Retry-After`.

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
- `429 OTP_RATE_LIMIT_REACHED`: Invitation OTP requests exceeded the account limit.

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
    "token": "1|abc123...",
    "user": { /* PatientAccountResource with link_status: linked */ },
    "status": "linked"
  }
}
```

**Behavior:**
- The authenticated account must own the verified contact targeted by the invitation.
- The invitation, OTP challenge, patient, contact, and account are rechecked under row locks in one transaction.
- The OTP challenge is bound to the authenticated account and the request IP is included in OTP verification limits.
- The patient link and invitation acceptance are committed atomically; lens, product, or other inventory movements are not created by this flow.
- Repeated acceptance from the same account is idempotent and returns `200` with a fresh mobile token and the existing link, even when the original challenge is already consumed.
- The original Sanctum token is not revoked and can immediately call `GET /me` to receive `link_status: linked`.
- A different account, an already-linked account, or an invitation for another verified contact is rejected without relinking the patient.

**Rate limit:** 120 requests per minute per authenticated account. A limit
response is `429 INVITATION_RATE_LIMIT_REACHED` and includes `Retry-After`.

---

## 8. Appointment Requests

### GET `/appointment-types`

Returns active, patient-visible appointment types.

**Auth:** Required (Sanctum token). No active patient link required.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "First eye examination",
      "description": "For your first examination at the clinic.",
      "duration_minutes": 45,
      "requires_referral": false
    }
  ]
}
```

**Notes:**
- Only active, patient-visible types are returned.
- `name` is the patient label (falls back to internal name if patient label is null).
- Inactive types and internal-only types are excluded.

---

### GET `/appointment-optometrists`

Returns active optometrists with stable ID and display name.

**Auth:** Required (Sanctum token). No active patient link required.

**Response (200):**
```json
{
  "data": [
    {
      "id": 8,
      "name": "Dr. Ana Santos"
    }
  ]
}
```

**Notes:**
- Only active optometrists are returned.
- Contact, role, schedule, and other private fields are excluded.
- Dual-role admin+optometrist accounts are included.

---

### GET `/appointment-request-availability`

Returns server-generated time slots for a given date and appointment type.

**Auth:** Required (Sanctum token). No active patient link required.

**Query parameters:**
| Param | Type | Required | Rules |
|---|---|---|---|
| `date` | string | yes | `date_format:Y-m-d`, `after_or_equal:today` |
| `appointment_type_id` | integer | yes | Must reference an active, patient-visible type |

**Response (200):**
```json
{
  "data": {
    "date": "2026-07-28",
    "timezone": "Asia/Manila",
    "interval_minutes": 15,
    "slot_duration_minutes": 45,
    "visit_duration_minutes": 45,
    "appointment_type_id": 1,
    "day_status": "open",
    "generated_at": "2026-07-27T10:00:00+08:00",
    "slots": [
      {
        "starts_at": "2026-07-28T09:00:00+08:00",
        "ends_at": "2026-07-28T09:45:00+08:00",
        "available": true,
        "reason": null
      }
    ]
  }
}
```

**Notes:**
- `interval_minutes` is the clinic slot cadence (15 minutes).
- `visit_duration_minutes` is the selected type's duration.
- Slots use the 15-minute grid; each slot's end uses the type's duration.
- Pending requests do NOT consume capacity (non-blocking).

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
  "appointment_type_id": 1,
  "scheduled_at": "2026-07-28T09:15:00+08:00",
  "alternative_scheduled_times": [
    "2026-07-28T10:30:00+08:00",
    "2026-07-29T09:00:00+08:00"
  ],
  "reason_for_visit": "Blurred vision in left eye",
  "referring_source": null,
  "identity": null
}
```

**Request fields:**
| Field | Type | Required | Rules |
|---|---|---|---|
| `appointment_type_id` | integer | yes | Must reference an active, patient-visible type |
| `scheduled_at` | datetime | yes | ISO 8601, must be future, grid-aligned, available |
| `alternative_scheduled_times` | array | no | Max 2 values, distinct, future, grid-aligned, available |
| `reason_for_visit` | string | yes | Max 1000 characters |
| `referring_source` | string | conditional | Required when type `requires_referral` is true |
| `identity` | object | no | For unlinked accounts only (see below) |

**Identity object rules:**
- `identity` is optional for unlinked accounts. When omitted, the server uses the account's current structured name, date of birth, phone, optional email, and address as fallback; unavailable demographic fields remain `null` in the staff-only snapshot.
- When `identity` is present, phone, first name, last_name, date of birth, gender, occupation, and home address are required. Middle name is nullable and email is optional.
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
    "appointment_type": {
      "id": 1,
      "name": "First eye examination",
      "duration_minutes": 45
    },
    "scheduled_at": "2026-07-28T09:15:00+08:00",
    "alternative_scheduled_times": [
      "2026-07-28T10:30:00+08:00",
      "2026-07-29T09:00:00+08:00"
    ],
    "provisional_duration_minutes": 45,
    "reason_for_visit": "Blurred vision in left eye",
    "referring_source": null,
    "expires_at": "2026-07-29T09:00:00+08:00",
    "time_preferences_are_reserved": false,
    "created_at": "2026-07-27T10:00:00+08:00",
    "appointment": null
  }
}
```

**Notes:**
- The response does not include identity, contact, or snapshot data.
- Identity and contact snapshots are stored encrypted and are staff-only.
- `time_preferences_are_reserved` is always `false` (pending requests never reserve capacity).

**Behavior:**
- All submitted time preferences are validated for current availability.
- Duration is snapshot from the selected appointment type.
- `expires_at` is the latest submitted preference time.
- Pending requests do NOT create capacity holds.
- For linked accounts, `patient_id` is copied from the active link.
- For unlinked accounts, `patient_id` remains `null`.
- For unlinked accounts, an encrypted identity snapshot is stored.
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
- `is_rateable` is `true` only when `status = fulfilled` and the appointment belongs to the authenticated patient.
- `rating` is `null` until submitted, then contains `{rating, comment, revision_number, created_at}`. Hidden comments return `comment: null` to non-authors.

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

### POST `/appointments/{appointment}/rating`

Creates or revises the patient's visit rating for a fulfilled appointment.
Upsert semantics: 201 on create, 200 on revise.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Request:**
```json
{
  "rating": "integer (required, 1-5)",
  "comment": "string (nullable, max:1000)"
}
```

**Response (201 Created — first rating):**
```json
{
  "data": {
    "id": 1,
    "rating": 5,
    "comment": "Dr. Santos explained everything clearly.",
    "revision_number": 1,
    "created_at": "2026-08-07T10:00:00+08:00"
  }
}
```

**Response (200 OK — revision):**
```json
{
  "data": {
    "id": 1,
    "rating": 4,
    "comment": "Updated comment",
    "revision_number": 2,
    "created_at": "2026-08-07T10:00:00+08:00"
  }
}
```

**Behavior:**
- Only fulfilled appointments can be rated.
- Appointment must belong to the authenticated patient.
- `optometrist_id` and `service_ids` are snapshotted at submission time.
- Hidden comments return `comment: null` to non-authors; authors always see their own.

**Errors:**
- `404`: Appointment not found or not owned by the patient.
- `422`: Appointment not fulfilled, or rating outside 1–5.

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
      "average_rating": 4.5,
      "rating_count": 12,
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

**Rating aggregates:** `average_rating` (float, 1 decimal, `null` when unrated)
and `rating_count` (integer) are computed across all of the product's frame
ratings, collected via `POST /optical-order-items/{id}/rating`.

> **Null means unrated, not zero.** `average_rating` is `?float` end-to-end
> (`FrameResource::computeAverageRating()` returns `null`, not `0.0`, when the
> product has no ratings) and reaches the response as JSON `null`. A client
> that coerces `null` to `0` will render every unrated frame as a 0-star
> product instead of an unrated one — do not collapse the two.

> ⚠️ **Known bug (2026-08-07):** both fields are computed from ratings
> filtered to `is_hidden = false` (`FrameController` / `FrameResource`), so a
> **hidden rating's star value is excluded from the aggregate entirely**, not
> just its comment. This contradicts the documented moderation model — hiding
> is meant to suppress the comment only, with the star still counting (see
> `ModerateFrameRating`'s own docblock and the visit-feedback spec's Task 0d
> acceptance criteria). As shipped, staff hiding an abusive 1-star comment
> also quietly erases that 1 star from the product's average. Not yet fixed.
>
> **No client-side fix exists.** The API never exposes individual hidden
> ratings to a client, patient or otherwise — only this pre-skewed aggregate —
> so a consuming client has no data to reconstruct the true average from and
> must display the number as received. This is a server-side-only fix; do not
> treat a client displaying a skewed average as a client bug.

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
      "expires_at": null,
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
- `expires_at` is `null` until the reservation reaches `prepared` (staff have pulled the frames and stock is allocated); it's then set to the appointment day's clinic close time, and an automatic sweep releases the reservation if it's still `prepared` past that time.
- An appointment can have at most one frame reservation, ever — the reservation exists only to hold stock before the visit; frames tried on in person flow directly into the eventual order without a reservation.

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

Draft Quotations are hidden from patients. Presented, Accepted, Declined, and
Expired Quotations are read-only to the linked patient.

### GET `/quotations`

Paginated list of the patient's non-draft quotations.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Query parameters:**

| Parameter | Required | Validation | Default |
|---|---|---|---|
| `filter` | No | `current` or `history` | `current` |
| `page` | No | Integer, minimum 1 | `1` |
| `per_page` | No | Integer, 1 through 50 | `15` |

**Filter behavior:** `current` returns `presented` quotations. `history`
returns `accepted`, `declined`, and `expired` quotations. Draft quotations are
never returned. Invalid `filter` values return `422`.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "quotation_number": "QUO-01K1ABC123",
      "status": "presented",
      "valid_until": "2026-08-15",
      "subtotal": "8500.00",
      "discount_amount": "500.00",
      "total": "8000.00",
      "notes": "Includes anti-reflective coating",
      "created_at": "2026-08-01T10:00:00+08:00",
      "presented_at": "2026-08-01T10:30:00+08:00",
      "confirmed_at": null,
      "optical_order": null,
      "items": [
        {
          "id": 1,
          "item_type": "product",
          "description": "Classic Rectangle Frame",
          "quantity": 1,
          "unit_price": "4500.00",
          "amount": "4500.00",
          "product_variant_id": 42,
          "lens_category_id": null,
          "service_id": null
        },
        {
          "id": 2,
          "item_type": "product",
          "description": "Progressive Lens with AR Coating",
          "quantity": 1,
          "unit_price": "3000.00",
          "amount": "3000.00",
          "product_variant_id": null,
          "lens_category_id": 7,
          "service_id": null
        },
        {
          "id": 3,
          "item_type": "service",
          "description": "Eye Examination",
          "quantity": 1,
          "unit_price": "1000.00",
          "amount": "1000.00",
          "product_variant_id": null,
          "lens_category_id": null,
          "service_id": 3
        }
      ]
    }
  ],
  "links": {
    "first": "/api/v1/quotations?page=1&filter=current",
    "last": "/api/v1/quotations?page=1&filter=current",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

**Status values:** `presented`, `accepted`, `declined`, `expired`.

**Notes:**
- Draft quotations are excluded.
- `item_type` is `product` or `service`.
- `optical_order` is populated only when `status` is `accepted` and an order was created.
- Items include both product and service lines because they are part of the proposal.
- Each item carries its catalog reference: `product_variant_id`, `lens_category_id`, and `service_id` are mutually exclusive — exactly one is non-null for a given item (or none, for legacy free-text lines), matching `item_type`.
- All monetary values are strings with two decimal places.

**Read-only.** Patients cannot create, accept, or decline quotations via the API.

---

### GET `/quotations/{quotation}`

Returns a single quotation with items.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "quotation_number": "QUO-01K1ABC123",
    "status": "presented",
    "valid_until": "2026-08-15",
    "subtotal": "8500.00",
    "discount_amount": "500.00",
    "total": "8000.00",
    "notes": "Includes anti-reflective coating",
    "created_at": "2026-08-01T10:00:00+08:00",
    "presented_at": "2026-08-01T10:30:00+08:00",
    "confirmed_at": null,
    "optical_order": null,
    "items": [ /* same as list */ ]
  }
}
```

**Errors:**
- `404`: Quotation not found, not owned by patient, or is a draft.

---

## 15. Optical Orders

**Active patient link required for all endpoints in this section.**

Optical Orders represent committed physical products that the clinic must
prepare, hand over, or otherwise fulfill. Each order is backed by a `JobOrder`
record. Service-only accepted quotations do not create Optical Orders.


### GET `/optical-orders`

Paginated list of the patient's confirmed optical orders.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Query parameters:**

| Parameter | Required | Validation | Default |
|---|---|---|---|
| `filter` | No | `current` or `history` | `current` |
| `page` | No | Integer, minimum 1 | `1` |
| `per_page` | No | Integer, 1 through 50 | `15` |

**Current filter** includes: `queued`, `in_progress`, `ready_for_dispensing`.
**History filter** includes: `dispensed`, `cancelled`. Invalid `filter` values
return `422`. Ordering is `created_at DESC, id DESC` (deterministic ties).

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "order_number": "ORD-2026-000001",
      "status": "in_progress",
      "fulfillment_mode": "prepared",
      "total_amount": "8000.00",
      "started_at": "2026-08-02T09:00:00+08:00",
      "ready_at": null,
      "dispensed_at": null,
      "cancelled_at": null,
      "created_at": "2026-08-01T11:00:00+08:00",
      "source_quotation": {
        "id": 1,
        "quotation_number": "QUO-01K1ABC123"
      },
      "items": [
        {
          "id": 1,
          "description": "Classic Rectangle Frame",
          "quantity": 1,
          "unit_price": "4500.00",
          "amount": "4500.00",
          "product_variant_id": 42,
          "is_rateable": false,
          "rating": null
        },
        {
          "id": 2,
          "description": "Progressive Lens with AR Coating",
          "quantity": 1,
          "unit_price": "3500.00",
          "amount": "3500.00",
          "product_variant_id": null,
          "is_rateable": false,
          "rating": null
        }
      ],
      "payment_summary": {
        "status": "partially_paid",
        "total_amount": "8000.00",
        "amount_paid": "3000.00",
        "balance_due": "5000.00",
        "payment_due_date": "2026-09-01",
        "is_overdue": false
      }
    }
  ],
  "links": {
    "first": "/api/v1/optical-orders?page=1&filter=current",
    "last": "/api/v1/optical-orders?page=1&filter=current",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

**Field definitions:**

| Field | Type | Nullable | Description |
|---|---|---|---|
| `id` | integer | no | Job Order ID |
| `order_number` | string | no | `ORD-YYYY-NNNNNN` format |
| `status` | string | no | Current fulfillment status |
| `fulfillment_mode` | string | no | `immediate` or `prepared` |
| `total_amount` | string | no | Order total, two decimal places |
| `started_at` | string | yes | ISO 8601 when production started |
| `ready_at` | string | yes | ISO 8601 when marked ready |
| `dispensed_at` | string | yes | ISO 8601 when dispensed |
| `cancelled_at` | string | yes | ISO 8601 when cancelled |
| `created_at` | string | no | ISO 8601 creation timestamp |
| `source_quotation` | object | yes | Source quotation reference; null for direct orders |
| `source_quotation.id` | integer | no | Quotation ID |
| `source_quotation.quotation_number` | string | no | Quotation number |
| `items` | array | no | Product items snapshot |
| `items[].id` | integer | no | Job Order Item ID |
| `items[].description` | string | no | Item description |
| `items[].quantity` | integer | no | Item quantity |
| `items[].unit_price` | string | no | Unit price, two decimal places |
| `items[].amount` | string | no | Line amount, two decimal places |
| `items[].product_variant_id` | integer | yes | Catalog variant ID; null for non-catalog or lens-category items |
| `items[].is_rateable` | boolean | no | Whether the patient may submit or revise a rating for this item now |
| `items[].rating` | object | yes | Current rating summary; null when not yet rated |
| `payment_summary` | object | yes | Active billing summary; omitted entirely if no billing record |
| `payment_summary.status` | string | no | Machine-readable: `unpaid`, `partially_paid`, `paid`, `voided` |
| `payment_summary.total_amount` | string | no | Billing total |
| `payment_summary.amount_paid` | string | no | Amount paid |
| `payment_summary.balance_due` | string | no | Remaining balance |
| `payment_summary.payment_due_date` | string | yes | Due date, `Y-m-d` format |
| `payment_summary.is_overdue` | boolean | no | Whether the unpaid balance is past its due date |

When `items[].rating` is not null, it contains `rating`, optional `comment`,
`created_at`, and `revision_number`. Hidden comments return `comment: null` to
non-authors; the author always sees their own comment.

**Rateable items:** `is_rateable` is `true` only for a dispensed order's item
with a non-null `product_variant_id`. Service items, custom products, and items
from non-dispensed orders have `is_rateable: false`.

**Status values:** `queued`, `in_progress`, `ready_for_dispensing`, `dispensed`, `cancelled`.

**Payment status values:** `unpaid`, `partially_paid`, `paid`, `voided`.

**Ordering:** `created_at DESC, id DESC` (deterministic ties).

**Notes:**
- Items contain only product lines. Service lines are never included.
- `supplier_invoice_number` and internal notes are excluded.
- `payment_summary` represents the overall checkout balance for combined bills.
- Monetary values are strings with two decimal places.

---

### GET `/optical-orders/{id}`

Returns a single optical order with items and payment summary.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "order_number": "ORD-2026-000001",
    "status": "ready_for_dispensing",
    "fulfillment_mode": "prepared",
    "total_amount": "8000.00",
    "started_at": "2026-08-02T09:00:00+08:00",
    "ready_at": "2026-08-03T14:00:00+08:00",
    "dispensed_at": null,
    "cancelled_at": null,
    "created_at": "2026-08-01T11:00:00+08:00",
    "source_quotation": {
      "id": 1,
      "quotation_number": "QUO-01K1ABC123"
    },
    "items": [ /* same as list */ ],
    "payment_summary": {
      "status": "unpaid",
      "total_amount": "8000.00",
      "amount_paid": "0.00",
      "balance_due": "8000.00",
      "payment_due_date": "2026-09-01",
      "is_overdue": false
    }
  }
}
```

**Errors:**
- `404`: Order not found or not owned by the authenticated patient.

---

### POST `/optical-order-items/{id}/rating`

Creates or revises the patient's rating for a rateable item from a dispensed
Optical Order. This endpoint is an upsert: the first POST creates the rating;
later POSTs append a revision to the same rating. There is no separate PATCH
route.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Request:**
```json
{
  "product_variant_id": "integer (nullable, derived from route item when omitted)",
  "rating": "integer (required, 1-5)",
  "comment": "string (nullable, max:1000)",
  "dispensing_event_id": "integer (nullable, must belong to the same job order)"
}
```

`product_variant_id` is optional. When omitted, the server derives it from the
route's job-order item. When supplied, it must match the item's variant.

**Response:** `201 Created` on first rating, `200 OK` on revision.

The response is wrapped in a `FrameRatingResource` that exposes only
patient-safe fields:

```json
{
  "data": {
    "id": 1,
    "item_id": null,
    "product_variant_id": 42,
    "rating": 5,
    "comment": "Excellent frame quality",
    "revision_number": 1,
    "created_at": "2026-08-05T10:00:00+08:00"
  }
}
```

**Hidden comments:** When staff hide a comment, the author still sees their own
`comment` text. Other patients and aggregate surfaces see `comment: null`. The
star value always counts toward averages regardless of hiding.

**Fields excluded from response:** `patient_id`, `is_hidden`, `moderation_reason`,
`moderated_by`, `moderated_at`, `current_revision_id`, `deleted_at`, `updated_at`,
`dispensing_event_id`.

**Errors:**
- `403`: Item belongs to another patient.
- `404`: Item not found.
- `422`: Order is not dispensed, `product_variant_id` mismatched, rating outside
  1–5, or comment over 1,000 characters.

---

## 16. Conversation

**Authenticated account-only (no active patient link required).**

The conversation is the account's single messaging thread with the clinic.
Linked and unlinked accounts can send text messages. Structured context
links (`contexts[]`) are retired and rejected with HTTP 422. Messages are
plain text only, maximum 5,000 characters.

Attachment uploads and downloads require an active patient link. Unlinked
accounts receive `can_upload_attachments: false` and upload attempts return
HTTP 422.

### GET `/conversation`

Returns (or creates) the account's single conversation.

**Auth:** Required (Sanctum token). No active patient link required.

**Response (200) — unlinked account:**
```json
{
  "data": {
    "id": 1,
    "patient_id": null,
    "access_level": "general_inquiry",
    "capabilities": {
      "can_upload_attachments": false,
      "can_create_context_links": false
    },
    "unread_count": 0,
    "created_at": "2026-08-11T10:00:00.000000Z"
  }
}
```

**Response (200) — linked account:**
```json
{
  "data": {
    "id": 2,
    "patient_id": 1,
    "access_level": "linked_patient",
    "capabilities": {
      "can_upload_attachments": true,
      "can_create_context_links": false
    },
    "unread_count": 3,
    "created_at": "2026-07-27T10:00:00.000000Z"
  }
}
```

### GET `/conversation/messages`

Returns all messages in the conversation (oldest first). NOT paginated.

**Auth:** Required (Sanctum token). No active patient link required.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "sender_id": 5,
      "sender_type": "staff",
      "body": "Your frame is ready for pickup.",
      "read_at": "2026-08-01T10:00:00+08:00",
      "created_at": "2026-08-01T09:00:00+08:00",
      "attachments": [
        {
          "id": 1,
          "original_name": "receipt.pdf",
          "mime_type": "application/pdf",
          "file_size": 45678,
          "download_url": "/api/v1/conversation/attachments/1"
        }
      ]
    }
  ]
}
```

**Field definitions:**

| Field | Type | Nullable | Description |
|---|---|---|---|
| `id` | integer | no | Message ID |
| `sender_id` | integer | no | User ID of sender |
| `sender_type` | string | no | `patient` or `staff` |
| `body` | string | no | Message text, maximum 5,000 characters |
| `read_at` | string | yes | ISO 8601 when read by patient |
| `created_at` | string | no | ISO 8601 creation timestamp |
| `attachments` | array | no | Attached files (empty array for unlinked accounts) |
| `attachments[].id` | integer | no | Attachment ID |
| `attachments[].original_name` | string | no | Original filename |
| `attachments[].mime_type` | string | no | MIME type |
| `attachments[].file_size` | integer | no | File size in bytes |
| `attachments[].download_url` | string | no | Authenticated attachment download route |

### POST `/conversation/messages`

Sends a plain text message. Structured context input is rejected.

**Auth:** Required (Sanctum token). No active patient link required.

**Request (JSON):**
```json
{
  "body": "Do you have the Vista Classic frame available?"
}
```

**Validation:**
- `body`: required, string, maximum 5,000 characters
- `contexts`: prohibited (returns 422)

**Rate limit:** 10 requests per minute per account. Throttled requests return
HTTP 429 without creating a partial message.

### GET `/conversation/attachments/{attachment}`

Downloads a message attachment. Requires an active patient link.

**Auth:** Required (Sanctum token). **Active patient link required.**

Returns 404 for unlinked accounts, missing files, or attachments from
other conversations (non-disclosing).

**Request (multipart/form-data):**
```
body: "string (required, max:5000)"
attachment: File (optional, max 10MB, allowed: pdf,png,jpg,jpeg,doc,docx)
contexts[0][type]: "optical_order"
contexts[0][id]: "1"
```

**Notes:**
- `contexts` is optional. Each entry must include `type` and `id`.
- Valid `type` values: `optical_order`, `quotation`.
- The referenced resource must exist and belong to the authenticated patient.
- A message accepts at most one attachment through the singular `attachment`
  field. To send multiple files, send separate messages.

**Response (201):**
```json
{
  "data": {
    "id": 2,
    "sender_id": 1,
    "sender_type": "patient",
    "body": "Thank you!",
    "read_at": null,
    "created_at": "2026-08-05T11:00:00+08:00",
    "contexts": [],
    "attachments": []
  }
}
```

### GET `/conversation/attachments/{id}`

Downloads a message attachment. Patient can only download from their own conversation.

**Auth:** Required (Sanctum token). **Active patient link required.**

**Response:** Binary file download with appropriate `Content-Type` and `Content-Disposition` headers.

**Errors:**
- `404`: Attachment not found or not owned by patient's conversation.

---

## 17. Error Responses

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
| `OTP_RATE_LIMIT_REACHED` | 429 | Invitation OTP requests or OTP verification attempts exceeded the applicable account, destination, or IP limit |
| `INVITATION_RATE_LIMIT_REACHED` | 429 | Invitation acceptance requests exceeded the authenticated account limit |
| `API_RATE_LIMIT_REACHED` | 429 | A general authenticated API route limit was exceeded |
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

## 18. Coordinated Breaking Changes

The following routes are **removed** in the coordinated Android cutover:

| Removed Route | Replacement |
|---|---|
| `POST /register` | Two-stage: `POST /auth/registration/otp` → `POST /auth/registration/verify` → `POST /auth/register` |
| `POST /login` | `POST /auth/login` → `POST /auth/login/verify` |
| `POST /appointments` | `POST /appointment-requests` |
| `GET /appointments/{id}/intake` | Retired; no replacement |
| `PUT /appointments/{id}/intake` | Retired; no replacement |
| `POST /appointments/{id}/intake/submit` | Retired; no replacement |
| `GET /job-orders` | Replaced by `GET /optical-orders` |
| `GET /job-orders/{id}` | Replaced by `GET /optical-orders/{id}` |
| `GET /billing-records` | Removed from patient API; staff-only |
| `GET /billing-records/{id}` | Removed from patient API; staff-only |
| `GET /eyewear` | Replaced by separate `GET /quotations` and `GET /optical-orders` |
| `GET /eyewear/{key}` | Replaced by `GET /quotations/{id}` or `GET /optical-orders/{id}` |
| `POST /job-order-items/{id}/rating` | Replaced by `POST /optical-order-items/{id}/rating` |

### Coordinated response and behavior changes

| Area | Breaking change |
|---|---|
| Eyewear navigation | The unified `/eyewear` aggregate is replaced by separately paginated Estimates (`/quotations`) and Orders (`/optical-orders`). Clients must not join the lists. |
| Optical Order items | Product items expose nullable `product_variant_id`, explicit `is_rateable`, and a nullable current `rating` summary. |
| Rating revisions | `POST /optical-order-items/{id}/rating` is an upsert. A later POST revises the rating; no PATCH route or duplicate-rating conflict response exists. |
| Payment summary | `payment_summary.status` is machine-readable (`unpaid`, `partially_paid`, `paid`, `voided`); `is_overdue` is a separate boolean. |
| Message attachments | A message accepts one optional `attachment` field. Responses return zero or one attachment; multiple files require separate messages. |

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
| `GET /optical-orders` | List patient optical orders (product fulfillment) |
| `GET /optical-orders/{id}` | Get optical order detail |
| `POST /optical-order-items/{id}/rating` | Rate a dispensed product item |

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

## 19. Retired Features

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

## 20. Clarifications

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
Challenges expire after 10 minutes, allow 5 verification attempts, and are
consumed on successful verification. Resend invalidates earlier pending
challenges for the same purpose/destination. OTP verification is limited to
10 attempts per 15 minutes per destination and 20 attempts per 15 minutes per
IP when an IP is available. Invitation OTP issuance has a separate limit of 5
requests per minute per authenticated account.

### Sanctum token lifecycle
Tokens are device-labelled, expire after 30 days, and are limited to 5 per patient account. Same-installation replacement is supported. A non-expired token for an installation allows password login without another OTP. Password recovery and primary-contact replacement revoke other patient tokens.

### Contact normalization
Email addresses are trimmed and lowercased. Phone numbers are normalized to canonical E.164 (`+63...`) before uniqueness checks and blind-index computation.

### Estimates and Orders UX

The mobile app uses two separate paginated APIs for the patient-facing
**Eyewear** destination:

- **Estimates**: `GET /api/v1/quotations` — presented quotations in the current
  view, and accepted, declined, or expired quotations in history. Drafts are
  hidden.
- **Orders**: `GET /api/v1/optical-orders` — current product fulfillment in the
  current view, and dispensed or cancelled orders in history.

The app may present these as tabs or sections within one Eyewear destination,
but the APIs are not combined and must not be client-side joined. Each endpoint
has its own pagination and filters. Service-only accepted quotations appear in
Estimates only because no Optical Order is created.

### Rateable items and rating revisions

Optical Order items expose `is_rateable`, nullable `product_variant_id`, and the
current rating summary. Only dispensed Product items with a linked variant have
`is_rateable: true`; Service items, custom products, and items from
non-dispensed orders have `is_rateable: false`.

`POST /api/v1/optical-order-items/{id}/rating` is an upsert. The first POST
creates the rating (`201`); subsequent POSTs revise the current rating and
append a moderation-history revision (`200`). There is no PATCH route and no
duplicate-rating conflict response.

### Machine-readable payment status

`payment_summary.status` is the machine-readable enum `unpaid`,
`partially_paid`, `paid`, or `voided`. Clients localize these values for
display. `payment_summary.is_overdue` is a separate boolean and must not be
inferred from the status string alone.

### Messaging and attachments

Messages include `sender_id`, `sender_type` (`patient` or `staff`), `body`,
`read_at`, `created_at`, normalized `contexts`, and an `attachments` array that
contains zero or one attachment. A message accepts one optional multipart
`attachment` field; multiple files require separate messages.

Each attachment returns `id`, `original_name`, `mime_type`, `file_size`, and an
authenticated `download_url`. Allowed file types are PDF, PNG, JPG/JPEG, DOC,
and DOCX, with a 10 MB maximum. Valid context types are `optical_order` and
`quotation`; each referenced record must belong to the authenticated patient.

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
GET    /api/v1/appointment-types              List patient-visible appointment types
GET    /api/v1/appointment-optometrists       List active optometrists
GET    /api/v1/appointment-request-availability Get request availability
GET    /api/v1/appointment-requests            List own requests
POST   /api/v1/appointment-requests            Create request
GET    /api/v1/appointment-requests/{id}       Get request detail
POST   /api/v1/appointment-requests/{id}/cancel  Cancel request
GET    /api/v1/frames                         List frames
GET    /api/v1/frames/{id}                    Get frame detail
```

### Authenticated API rate limits

Authenticated limits are isolated by route group and keyed to the account, so
duplicate mobile bootstrap requests do not consume the `/me` and clinical
budgets together:

| Route group | Limit |
|---|---:|
| `GET /me` | 300 requests/minute/account |
| Other account-only routes | 120 requests/minute/account |
| Active patient-link routes | 120 requests/minute/account |
| `POST /patient-invitations/acceptance/otp` | 5 requests/minute/account |
| `POST /patient-invitations/accept` | 120 requests/minute/account |

Rate-limited responses include `Retry-After` in seconds. Middleware-backed
limits also include `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and
`X-RateLimit-Reset`.

### Active Patient Link Required (token + active link)

```
GET    /api/v1/appointment-availability        Reschedule availability
GET    /api/v1/appointments                   List confirmed appointments
GET    /api/v1/appointments/{id}              Get appointment detail
POST   /api/v1/appointments/{id}/cancel       Cancel appointment
POST   /api/v1/appointments/{id}/reschedule   Reschedule appointment
POST   /api/v1/appointments/{id}/rating       Submit visit rating

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
POST   /api/v1/job-order-items/{id}/rating     Legacy alias of the line above
```

**Route count:** 8 public + 24 account-only + 21 active-link = **53 routes total.**

> **Corrected 2026-08-07 (was 51).** `POST /api/v1/job-order-items/{id}/rating` is a
> **legacy alias** of `POST /api/v1/optical-order-items/{id}/rating` — same controller,
> same behavior — kept for Android builds predating the Optical Order rename. It was
> undocumented, which is why the count was one short. **New clients should use
> `optical-order-items`;** the alias is retained but will not gain new behavior.
