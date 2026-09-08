# ADR-006: Use Laravel Cloud Starter for the Capstone Demo

## Status

Accepted

## Date

2026-09-08

## Context

The capstone deployment is a staff-only demonstration for approximately one
month. It uses synthetic records, has no participant accounts, and will not
collect research or clinical data. The backend is a Laravel 13 application
that requires PHP 8.5, MySQL, scheduled tasks, asynchronous jobs, HTTPS,
persistent assets, logs, and a reversible teardown.

The team needs to launch quickly and has limited capacity for server
administration. A self-managed VPS or shared hosting plan would move worker
supervision, deployment safety, backup verification, and security maintenance
into the capstone team's critical path.

Laravel Cloud currently lists MySQL, object storage, queue workers, task
scheduling, HTTPS, automatic deployments, and a pay-as-you-go Starter plan.
Its published regions include Asia Pacific (Singapore), which is the preferred
region for the Philippines-based team. See the provider's [pricing](https://marketing.cloud.laravel.com/pricing)
and [compute](https://marketing.cloud.laravel.com/compute) documentation.

## Decision

Use **Laravel Cloud Starter** for the isolated capstone demo environment.

- Create one Cloud application and one demo environment from the immutable
  release revision.
- Select Asia Pacific (Singapore) when it is available in the account.
- Attach a managed Laravel MySQL database.
- Use Cloud object storage for catalog images, the published AR model, AR
  quarantine files, and private message attachments. Keep the application's
  logical disk names and visibility boundaries separate.
- Keep the existing database-backed cache, queue, and session drivers for the
  single-replica demo unless the operator's deployment rehearsal demonstrates
  a need for a managed Valkey resource.
- Use the Cloud-provided HTTPS hostname initially. A custom domain is optional
  and must not delay launch.
- Configure a billing alert at **US$30** (or the PHP equivalent). Laravel Cloud
  does not enforce a hard spending cap, so the technical owner must monitor the
  alert and stop the environment at teardown.
- Keep the environment in `DEPLOYMENT_MODE=demo` with
  `CAPSTONE_PILOT_ENABLED=false`; do not configure SMS or participant access.

The exact Cloud environment hostname, resource identifiers, and technical
owner's name are operational inputs and must be recorded in the runbook before
go-live.

## Alternatives Considered

### Railway Hobby

Railway's official Laravel guide supports a split application, worker,
scheduler, and database layout, and its usage-based pricing can be cheaper for
light traffic. It requires more provider-specific service configuration and
verification than this team should absorb for a next-day demo. See the
[Laravel guide](https://docs.railway.com/guides/laravel) and
[pricing](https://railway.com/pricing).

### Hostinger shared hosting or VPS

Hostinger documents PHP, MySQL, Git, and cron capabilities, but the shared
hosting path does not provide the same clearly managed worker, release,
restore, and teardown workflow required here. A Hostinger VPS is possible, but
would make the team responsible for Supervisor, OS updates, TLS, backups, and
rollback. See the [Hostinger guide](https://assets.hostinger.com/content/tutorials/pdf/Guide-To-Using-Hostinger.pdf).

### Render

Render provides worker and cron service types, but the application's tested
database is MySQL while the usual managed database path is PostgreSQL. Using
Render would therefore require an external MySQL service or a database
migration, adding unnecessary launch risk. See Render's [service types](https://render.com/docs/service-types).

## Consequences

- The team gets managed deployment, TLS, logs, scheduler, and background-job
  controls with less server administration.
- Cloud object storage and the managed database are additional billable
  resources; the billing alert is advisory rather than a technical limit.
- The deployment remains portable because domain code uses logical disks and
  provider-neutral queue/cache/session contracts.
- The environment must be tested using the Cloud-generated hostname before any
  custom DNS is added.
- This decision applies only to the time-limited capstone demo. A clinical or
  participant deployment requires a new review.

## Related Decisions

- [ADR-005: Use a Staff-Only Demo Deployment](005-use-staff-only-demo-deployment-for-capstone.md)
