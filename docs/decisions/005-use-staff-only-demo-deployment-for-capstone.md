# ADR-005: Use a Staff-Only Demo Deployment for the Capstone

## Status

Accepted

## Date

2026-09-07

## Context

The capstone team decided not to expose the deployed environment to research
participants and not to collect participant or research data during this
one-month demonstration. The remaining purpose is to demonstrate the Filament
staff workflows, the selected AR experience, and the system's operational
behavior during the final defense.

The repository already contains an additive participant-code authentication
implementation. Enabling or provisioning it would add unnecessary Android,
credential, consent, retention, and operational work to a demo-only release.
Deleting the implementation would create avoidable churn and remove a tested
option for a separately approved future study.

## Decision

Deploy an explicit staff-only demo environment.

- Set `DEPLOYMENT_MODE=demo` and keep `CAPSTONE_PILOT_ENABLED=false`.
- Do not provision participant accounts or credentials.
- Keep participant-code authentication, phone authentication, OTP, recovery,
  and invitation paths unavailable in the deployed environment.
- Use only fictional/synthetic records and assets.
- Use the Filament panel and team-controlled demonstration workflows as the
  launch scope; participant Android mode is not required.
- Retain the participant-code implementation in the repository as dormant,
  additive code. Any future participant study requires a new scope, consent,
  privacy, retention, and device-review decision.

## Consequences

- The capstone can proceed without SMS-provider onboarding, participant
  credential distribution, or an Android participant release.
- The demo still needs a managed host, secure administrator access, monitoring,
  backups, rollback, synthetic-data teardown, and one selected AR model.
- Results from this deployment cannot be presented as participant-study data or
  evidence of participant usability.
- The preflight and route gates must verify that demo mode cannot accidentally
  expose participant or phone/SMS authentication.

## Supersedes

For this deployment only, the participant-use decision in
[ADR-004](004-use-temporary-participant-code-authentication-for-capstone-pilot.md)
is superseded. ADR-004 remains historical guidance for a future approved study.
