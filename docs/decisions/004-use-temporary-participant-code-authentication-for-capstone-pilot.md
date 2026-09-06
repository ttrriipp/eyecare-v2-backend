# ADR-004: Use Temporary Participant-Code Authentication for the Capstone Pilot

## Status

Accepted

## Date

2026-09-06

## Context

The Android patient application currently authenticates with a verified phone
number and password. A new installation also requires an SMS OTP. Registration
and password recovery are phone-based, while an optional email address cannot
currently be used for patient login or recovery.

The system will be internet-accessible for a one-month capstone pilot with 75
invited research participants. It is intended for data gathering,
demonstrations, and final defense rather than clinical use. Collecting 75 phone
numbers and depending on an SMS provider adds personal data, cost, carrier
failure modes, and implementation risk that are unnecessary for the study.

A shared account, universal OTP, or hard-coded access token would make
participant-level results unreliable and create an unacceptable public
authentication bypass.

## Decision

Add a temporary participant-code authentication path for the capstone pilot.

- Provision exactly 75 pseudonymous pilot accounts with unique participant
  identifiers and independent cryptographically random passwords.
- Do not place names, phone numbers, email addresses, birthdates, or clinical
  facts in participant identifiers or authentication records.
- Add a dedicated Android participant-login mode and backend login operation.
  Do not weaken or reinterpret the existing phone/password/OTP contract.
- Restrict the operation to explicitly marked pilot accounts and the patient
  role. It must never authenticate staff or ordinary patient accounts.
- Guard the operation with an explicit pilot configuration value that defaults
  to disabled. The operation fails closed after the approved pilot expiry.
- Rate-limit and audit login attempts without logging credentials or tokens.
- Return an ordinary scoped Sanctum token after successful authentication so
  all existing authorization policies continue to apply.
- Disable participant-facing registration, OTP, and automated password
  recovery. An authorized research administrator handles auditable credential
  resets during the pilot.
- Revoke all pilot tokens and disable or delete the participant accounts at
  teardown. Remove the temporary Android mode and backend operation, or keep
  them permanently disabled, before any later clinical deployment.

The participant code is an identifier, not a secret. Account security depends
on the unique random password, rate limiting, restricted account eligibility,
the pilot expiry, and normal token controls.

## Alternatives Considered

### Use real SMS for all participants

Rejected for the pilot because it requires collecting phone numbers and makes
access dependent on provider onboarding, queue delivery, carrier reliability,
and SMS troubleshooting. It remains the intended basis of the ordinary patient
authentication flow outside this temporary pilot.

### Add verified email authentication

Rejected because email is not currently a patient login or recovery identifier.
It would require coordinated backend and Android contract changes while still
collecting a personal contact address.

### Give every participant one shared demonstration account

Rejected because actions could not be attributed to one research participant,
concurrent sessions could interfere with each other, and one disclosed password
would compromise the entire study.

### Use fake phone numbers, a universal OTP, or pre-issued tokens

Rejected because these approaches either conflict with the current Android
flow or introduce a reusable authentication bypass that could affect ordinary
accounts.

## Consequences

- Both the backend and Android application require a small, coordinated additive
  authentication change before the pilot begins.
- Participant access does not measure the usability or reliability of the real
  phone-registration and OTP flow.
- Password recovery is an administrator-assisted study procedure.
- Each participant remains independently attributable through a pseudonymous
  research identifier without collecting a contact address.
- The capstone team must distribute credentials privately and maintain a secure
  participant-to-code correspondence only if the approved research process
  requires one.
- Pilot expiry and teardown are security controls, not optional cleanup tasks.
- Any later clinical deployment must keep this authentication path disabled or
  remove it and complete the normal SMS authentication work.
