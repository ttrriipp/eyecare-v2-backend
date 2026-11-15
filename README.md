# EyeCare — Padilla Optical Clinic Management System

EyeCare is the clinic operations backend for Padilla Optical Clinic. Staff use its web admin panel to manage patient records, appointments, clinical encounters and prescriptions, optical orders, billing, inventory, and clinic communications. A versioned REST API also serves a separate Android patient app, including its appointment, catalog, order, messaging, and frame try-on workflows.

## What’s in this repository

- **Staff admin panel** built with Laravel and Filament at `/admin`.
- **Patient API** under `/api/v1`, consumed by the separate Android application.
- **Clinic workflows** for scheduling, patient care, optical retail, payment, and inventory.
- **Security and recordkeeping** such as role-based access, audit logging, and encrypted clinical and contact data.

## Technology

- PHP 8.3+
- Laravel 13
- Filament 5 and Livewire 4
- MySQL 8.4
- Vite and Tailwind CSS 4
- Pest 4
- Laravel Sail for the local Docker environment

## Getting started

For the complete local installation, environment configuration, demo account, troubleshooting, and production-data cautions, see the [installation guide](docs/INSTALLATION.md).

Quick setup:

1. Clone the repository and enter the project directory.
2. Copy `.env.example` to `.env` if you do not already have an environment file. For Sail, set `DB_HOST=mysql` and configure matching local database credentials. See the installation guide for the full environment setup.
3. Install Composer dependencies. This first command runs on the host because Sail is installed with the dependencies:

   ```bash
   composer install --no-interaction
   ```

4. Start Sail, prepare Laravel and the database, then build the frontend assets:

   ```bash
   vendor/bin/sail up -d
   vendor/bin/sail artisan key:generate --no-interaction
   vendor/bin/sail artisan migrate --seed --no-interaction
   vendor/bin/sail artisan storage:link --no-interaction
   vendor/bin/sail npm ci
   vendor/bin/sail npm run build
   ```

5. Open the staff panel at `http://localhost/admin/login`.

The seed data and credentials documented in the installation guide are for local development only. Do not load the full local demo seed into a production database.

## Common commands

Run these from the project root after installing dependencies and starting Docker:

| Task | Command |
| --- | --- |
| Start containers | `vendor/bin/sail up -d` |
| Check container status | `vendor/bin/sail ps` |
| Apply migrations | `vendor/bin/sail artisan migrate --no-interaction` |
| Run the test suite | `vendor/bin/sail artisan test --compact` |
| Watch frontend assets | `vendor/bin/sail npm run dev` |
| Build frontend assets | `vendor/bin/sail npm run build` |
| Stop containers | `vendor/bin/sail stop` |

## Project structure

| Path | Purpose |
| --- | --- |
| `app/Actions` | Clinic and commerce workflows |
| `app/Filament` | Staff panel resources, pages, and widgets |
| `app/Http` | API controllers, middleware, requests, and resources |
| `app/Models` | Eloquent models |
| `app/Services` | Integrations and shared domain services |
| `database/migrations` | Database schema changes |
| `database/factories` and `database/seeders` | Test factories and setup/demo data |
| `routes/api.php` | Versioned patient API routes |
| `routes/web.php` | Web routes and health/readiness endpoints |
| `tests` | Pest feature and unit tests |
| `docs` | Installation, API, deployment, workflow, and design documentation |

## Project documentation

- [Local installation](docs/INSTALLATION.md)
- [API contract](docs/API_CONTRACT.md)
- [Backend context](docs/BACKEND_CONTEXT.md)
- [Deployment guide](docs/DEPLOYMENT.md)
- [Clinic workflow audit](docs/clinic-workflow-audit.md)
- [Known gaps](docs/gap-analysis.md)
- [Architecture decisions](docs/decisions/)

The Android application is maintained in a separate repository. This project owns the backend and the API contract it consumes; coordinate API changes with that client.
