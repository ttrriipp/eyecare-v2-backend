# Deployment Guide

> Production deployment for the Padilla Optical Clinic Management System (EyeCare).

---

## Recommended: Laravel Cloud

The fastest path to production. [Laravel Cloud](https://cloud.laravel.com/) handles SSL, scaling, queue workers, and scheduled tasks automatically.

1. Push your repository to GitHub
2. Connect the repo in Laravel Cloud
3. Set environment variables (see below)
4. Deploy — Cloud handles nginx, PHP, queue workers, scheduler, and SSL

## Capstone Demo Profile: Laravel Cloud Starter

This profile is the approved deployment path for the one-month capstone
demonstration. It supersedes the generic production values below for this
environment. The deployment is staff-only, uses synthetic data, collects no
participant or clinical data, and must be taken offline no later than **October
7, 2026**.

The accepted provider decision is recorded in
[`ADR-006`](decisions/006-select-laravel-cloud-for-capstone-demo.md). Use the
Starter plan in Asia Pacific (Singapore when available), configure a **US$30
billing alert**, and remember that the alert is not a hard spending cap.

### Resource map

| Application need | Cloud resource or setting | Required boundary |
|---|---|---|
| Web application | One Cloud application/environment from the immutable release revision | Keep the generated HTTPS hostname private until preflight passes |
| Database | Managed MySQL | Run migrations with `--force`; never import a legacy clinic database |
| Cache, queue, sessions | Existing database-backed drivers for the single-replica demo | Keep these persistent and shared; do not use array, cookie, or sync drivers |
| Queue worker | One Cloud worker process | Confirm it is supervised and can process a test job |
| Scheduler | Cloud scheduled task invoking Laravel’s scheduler once per minute | Confirm the required expiry/cleanup events are visible |
| Catalog files | S3-compatible object storage, logical disk `public` | Public visibility; use a catalog-specific root and URL |
| Published AR model | S3-compatible object storage, logical disk `ar_published` | Public visibility; publish only the selected synthetic model |
| AR quarantine | S3-compatible object storage, logical disk `ar_quarantine` | Private visibility; never expose a direct URL |
| Message attachments | S3-compatible object storage, logical disk `message_attachments` | Private visibility; authorization remains application-controlled |
| HTTPS and monitoring | Cloud HTTPS hostname, uptime check, application logs, billing alert | Record exact URLs/resource IDs and the owner before launch |

### Provisioning and release sequence

1. Create the Cloud application from the reviewed commit and select the
   Singapore region when available. Add managed MySQL and object storage. Do
   not add a custom domain or distribute the hostname yet.
2. Configure Cloud secrets and environment values from `.env.example`. At
   minimum, set `APP_ENV=production`, `APP_DEBUG=false`, a new `APP_KEY`, the
   Cloud `APP_URL`, `DEPLOYMENT_MODE=demo`, `CAPSTONE_PILOT_ENABLED=false`, and
   the exact trusted-host/proxy/CORS values. Keep `SEMAPHORE_ENABLED` and
   `TEXTBEE_ENABLED` false and do not run participant provisioning.
3. Map object storage without changing application code. A single bucket may
   use distinct roots, or separate buckets may be used when the provider
   supports them:

   ```env
   CATALOG_DISK=public
   CATALOG_DRIVER=s3
   CATALOG_ROOT=catalog
   CATALOG_URL=https://<public-catalog-base>

   MESSAGE_ATTACHMENTS_DISK=message_attachments
   MESSAGE_ATTACHMENTS_DRIVER=s3
   MESSAGE_ATTACHMENTS_ROOT=message-attachments

   AR_QUARANTINE_DISK=ar_quarantine
   AR_QUARANTINE_DRIVER=s3
   AR_QUARANTINE_ROOT=ar/quarantine

   AR_PUBLISHED_DISK=ar_published
   AR_PUBLISHED_DRIVER=s3
   AR_PUBLISHED_ROOT=ar
   AR_PUBLISHED_URL=https://<public-object-base>
   AR_ASSET_BASE_URL=https://<public-object-base>
   ```

   The public base values must point at the object-storage/CDN origin root;
   the configured roots and `ar/variants` prefix supply the remaining path.

   Use the provider-injected `AWS_*` credentials only through Cloud secret
   storage. If separate buckets or credentials are required, use the matching
   `CATALOG_*`, `MESSAGE_ATTACHMENTS_*`, `AR_QUARANTINE_*`, and
   `AR_PUBLISHED_*` overrides. Do not run `storage:link` for object-storage
   disks.
4. Use the Cloud build/deploy settings to run the locked installation and
   frontend build:

   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci
   npm run build
   ```

5. Run release commands in this order, stopping on the first failure:

   ```bash
   php artisan migrate --force
   php artisan optimize
   php artisan pilot:preflight
   php artisan db:seed --class=CapstonePilotSeeder --force
   php artisan pilot:preflight
   ```

   `CapstonePilotSeeder` is the only approved bootstrap for this demo. Never
   run the broad `DatabaseSeeder` and never run
   `pilot:provision-participants`.
6. Provision the named administrator through the Cloud command console using
   an owner-only password input, then enroll and verify Filament MFA. Do not
   pass the password as a command-line argument or place it in a build log.
7. Verify the generated HTTPS hostname before any DNS or staff distribution:
   `/up` returns only liveness, the protected `/internal/readiness` check
   returns `ready` with the dedicated header token, and `pilot:preflight`
   reports no failures. Capture the immutable release ID and Cloud resource
   identifiers in the launch record.

### Demo smoke and recovery checklist

- Sign in to the Filament panel with MFA and exercise the approved synthetic
  catalog, patient-record, appointment, prescription, billing, and messaging
  demonstration paths.
- Confirm SKU `FRM-ANTHOS-MB1399A-C4` serves the published
  `frame-002-tortoise-rectangle-v2.glb` asset over HTTPS. Confirm another
  product uses the non-AR fallback and that missing/disabled AR never blocks
  the catalog.
- Verify catalog and published AR objects are public, while AR quarantine and
  message attachments remain private and authorization-protected.
- Confirm the participant-login, registration, phone-login, OTP, recovery, and
  invitation endpoints return `404`; confirm no participant accounts exist and
  no SMS adapter is enabled.
- Enqueue a harmless test job, observe worker completion, and confirm the
  once-per-minute scheduler is running. Check uptime and application-error
  notifications without exposing secrets or record contents.
- Enable/verify encrypted database backups and the selected object-storage
  backup/versioning policy. Restore one backup into an isolated environment,
  compare the migration/release and synthetic records, and record the result.
- Record the last known-good Cloud revision. For a release failure, stop
  promotion and roll back to that revision only when the schema is compatible;
  otherwise restore the verified backup in an isolated recovery environment.

### Required launch record

Complete these fields before go-live; placeholders are intentionally not
launch evidence:

```text
Cloud application/environment:
Region:
Generated HTTPS hostname:
Managed MySQL resource ID:
Object-storage resource/bucket IDs:
Immutable release ID:
Technical owner name and contact:
US$30 billing-alert destination:
Backup/restore evidence:
Rollback evidence:
Teardown operator and scheduled date (hard stop: 2026-10-07):
```

### Teardown

After the final defense plus seven days, and never later than October 7, 2026,
export only approved synthetic records if needed, revoke staff sessions and
tokens, stop the worker and scheduler, delete the database/object-storage
objects and backups, remove the Cloud application/domain, and confirm billing
has stopped. Record provider deletion confirmations and do not retain local
copies of credentials or generated manifests.

---

## Alternative: VPS (Ubuntu 24.04 + Forge-style)

### Prerequisites

- Ubuntu 24.04 LTS server (DigitalOcean, Vultr, Linode, etc.)
- Domain pointing to server IP
- SSH access

### Stack

| Component | Version |
|---|---|
| PHP | 8.5 with extensions: mbstring, xml, curl, mysql, zip, bcmath, gd |
| MySQL | 8.0+ |
| Nginx | Latest |
| Node.js | 22 LTS (for asset building) |
| Supervisor | For queue workers |
| Certbot | For SSL (Let's Encrypt) |

### Setup Steps

```bash
# 1. Clone and install
cd /var/www
git clone <repo-url> eyecare
cd eyecare
composer install --optimize-autoloader --no-dev
npm ci && npm run build

# 2. Environment
cp .env.example .env
php artisan key:generate
# Edit .env — see Environment Variables section below

# 3. Database
mysql -u root -e "CREATE DATABASE eyecare_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate --force
php artisan db:seed --class=AppointmentStatusSeeder --force
php artisan db:seed --class=VisitReasonSeeder --force
# Seed other lookup tables as needed

# 4. Storage & permissions
php artisan storage:link
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# 5. Optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan icons:cache
```

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name eyecare.example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name eyecare.example.com;
    root /var/www/eyecare/public;

    ssl_certificate /etc/letsencrypt/live/eyecare.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/eyecare.example.com/privkey.pem;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    client_max_body_size 20M;
}
```

### Queue Worker (Supervisor)

```ini
; /etc/supervisor/conf.d/eyecare-worker.conf
[program:eyecare-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/eyecare/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/eyecare/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start eyecare-worker:*
```

### Scheduler (Cron)

```bash
# /etc/cron.d/eyecare
* * * * * www-data cd /var/www/eyecare && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs these commands automatically:
- `prescriptions:check-expiry` — daily at 8:00 AM
- `clinic:daily-summary` — daily at 9:00 PM
- `appointments:send-reminders` — daily at 6:00 PM

### SSL (Let's Encrypt)

```bash
sudo certbot --nginx -d eyecare.example.com
# Auto-renewal is configured by default
```

---

## Environment Variables (Production)

The following is the generic non-demo template. For the capstone environment,
use the Cloud profile above, keep SMS disabled, and run `pilot:preflight` after
every environment change.

```env
APP_NAME=EyeCare
APP_ENV=production
APP_KEY=            # Generated via php artisan key:generate
APP_DEBUG=false
APP_TIMEZONE=Asia/Manila
APP_URL=https://eyecare.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=eyecare_production
DB_USERNAME=eyecare
DB_PASSWORD=        # Strong password

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=file

SEMAPHORE_ENABLED=true
SEMAPHORE_API_KEY=  # From semaphore.co dashboard
SEMAPHORE_SENDER_NAME=EyeCare

FILAMENT_TIMEZONE=Asia/Manila
```

---

## Backup Strategy

Daily automated MySQL backup with 7-day retention:

```bash
# /etc/cron.d/eyecare-backup
0 2 * * * www-data mysqldump -u eyecare -p'PASSWORD' eyecare_production | gzip > /var/backups/eyecare/db-$(date +\%Y\%m\%d).sql.gz
0 3 * * * www-data find /var/backups/eyecare -mtime +7 -name "*.sql.gz" -delete
```

For offsite storage, sync to S3:
```bash
0 4 * * * www-data aws s3 sync /var/backups/eyecare s3://eyecare-backups/db/ --delete
```

Also back up uploaded files:
```bash
0 4 * * * www-data aws s3 sync /var/www/eyecare/storage/app s3://eyecare-backups/storage/
```

---

## Monitoring

### Health Check

`GET /health` returns 200 when the application and database are healthy:

```json
{"status": "ok", "database": "connected"}
```

Use this with uptime monitors (UptimeRobot, Pingdom, or AWS Route53 health checks).

### Application Logs

Logs are in `storage/logs/laravel.log`. For centralized logging, configure `LOG_CHANNEL=stack` with a Papertrail/Sentry driver.

---

## Deployment Checklist

- [ ] `APP_DEBUG=false`
- [ ] `APP_ENV=production`
- [ ] Strong `APP_KEY` (never reuse from dev)
- [ ] Database credentials rotated from dev
- [ ] `SEMAPHORE_ENABLED=true` with valid API key
- [ ] SSL certificate active
- [ ] Queue worker running (check `sudo supervisorctl status`)
- [ ] Scheduler cron installed (check `crontab -l -u www-data`)
- [ ] Backup cron installed and tested
- [ ] `php artisan config:cache` after env changes
- [ ] Storage symlink created (`php artisan storage:link`)
- [ ] File permissions set (www-data owns storage/)
