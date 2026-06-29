# Deployment Guide

> Production deployment for the Padilla Optical Clinic Management System (Eyecare).

---

## Recommended: Laravel Cloud

The fastest path to production. [Laravel Cloud](https://cloud.laravel.com/) handles SSL, scaling, queue workers, and scheduled tasks automatically.

1. Push your repository to GitHub
2. Connect the repo in Laravel Cloud
3. Set environment variables (see below)
4. Deploy — Cloud handles nginx, PHP, queue workers, scheduler, and SSL

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

```env
APP_NAME=Eyecare
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
SEMAPHORE_SENDER_NAME=Eyecare

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
