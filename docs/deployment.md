# Deployment

PixelPilot needs four things running in production:

1. **Web**: PHP-FPM behind Nginx with HTTPS (Pixelforge only delivers webhooks to public URLs).
2. **PostgreSQL**: 14 or newer. The schema uses `NUMERIC`, `jsonb`, partial unique indexes, triggers and `SKIP LOCKED`.
3. **Queue worker(s)**: webhook processing, submission retries, polling and image copies run as queued jobs.
4. **Scheduler**: `schedule:run` every minute (polling fallback, pending-webhook sweeper, usage sync).

Redis is optional. Without it, the database driver handles sessions, cache and queues.

The `deploy/` folder has working configs for a plain Ubuntu VPS. Laravel Forge provisions the same pieces; use `deploy/deploy.sh` as the Forge deploy script.

## Server requirements

- Ubuntu 22.04/24.04 (or similar)
- PHP 8.3+ with `pdo_pgsql`, `bcmath`, `mbstring`, `intl`, `xml`, `curl`, `zip` (and `redis` if you use it)
- Composer 2, Node 20+ (build only)
- PostgreSQL 14+
- Nginx, Supervisor, certbot

## First deploy on a VPS

```bash
# 1. Database
sudo -u postgres createuser --pwprompt pixelpilot
sudo -u postgres createdb --owner=pixelpilot pixelpilot

# 2. Code
sudo mkdir -p /var/www/pixelpilot && sudo chown $USER:www-data /var/www/pixelpilot
git clone https://github.com/ftayebee/pixelpilot.git /var/www/pixelpilot
cd /var/www/pixelpilot
cp .env.example .env          # then edit it, see below
composer install --no-dev --optimize-autoloader
php artisan key:generate
npm ci && npm run build
php artisan migrate --force
sudo chown -R www-data:www-data storage bootstrap/cache

# 3. Web server + TLS
sudo cp deploy/nginx/pixelpilot.conf /etc/nginx/sites-available/pixelpilot
sudo ln -s /etc/nginx/sites-available/pixelpilot /etc/nginx/sites-enabled/
# edit server_name / PHP-FPM socket, then:
sudo certbot --nginx -d your-domain.com
sudo nginx -t && sudo systemctl reload nginx

# 4. Queue workers
sudo cp deploy/supervisor/pixelpilot-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update

# 5. Scheduler
sudo crontab -u www-data deploy/crontab
```

Subsequent deploys: `APP_DIR=/var/www/pixelpilot bash deploy/deploy.sh`. It runs `migrate --force` (never `migrate:fresh`), rebuilds caches and restarts the workers.

## Production `.env`

```dotenv
APP_NAME=PixelPilot
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...                     # php artisan key:generate
APP_URL=https://your-domain.com

LOG_CHANNEL=daily
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=pixelpilot
DB_USERNAME=pixelpilot
DB_PASSWORD=...

SESSION_DRIVER=database                # or redis
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database              # or redis
CACHE_STORE=database                   # or redis

MAIL_MAILER=smtp                       # password reset emails
MAIL_HOST=...
MAIL_FROM_ADDRESS=no-reply@your-domain.com

# A Pixelforge API key created for THIS deployment only.
PIXELFORGE_API_KEY=...
PIXELFORGE_WEBHOOK_SECRET=whsec_...

# Optional: keep images after Pixelforge's 24h links expire
PIXELPILOT_STORE_IMAGES=true
PIXELPILOT_IMAGE_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=...
AWS_BUCKET=...
```

`APP_ENV=production` also forces HTTPS URLs and turns off Eloquent strict mode. The database session timezone is pinned to UTC in `config/database.php`.

If you set `PIXELPILOT_IMAGE_DISK=s3`, install the S3 driver first: `composer require league/flysystem-aws-s3-v3`.

## Pixelforge configuration

1. In the Pixelforge dashboard, create a **new API key for production**. Never reuse the local development key: each key has its own webhook URL, signing secret and usage report, and the admin reconciliation compares against the key in use.
2. Set the key's webhook URL to `https://your-domain.com/webhooks/pixelforge`.
3. Copy the key's signing secret (`whsec_…`) into `PIXELFORGE_WEBHOOK_SECRET`.
4. Press **Send test event** in the dashboard. Expect a `204`, and a row in `webhook_events` with status `ignored` (it's a `ping`).

## Admin user

There is no default admin and no seeded password in production. Register through the UI, then:

```bash
php artisan pixelpilot:make-admin you@example.com
```

## Smoke test after deploying

```bash
curl -s https://your-domain.com/health        # {"status":"ok","checks":{"database":true,"pixelforge_configured":true}}
php artisan pixelforge:smoke you@example.com  # runs [succeed] [fail] [blocked] [overloaded] [duplicate] for real
php artisan pixelforge:sync-usage --days=2    # then open /admin/analytics → reconciliation should say "Matches Pixelforge"
```

The smoke test leaves real succeeded and failed generations on the admin's account, which is handy for anyone reviewing the deployment. It needs credits on that account: buy some on the Credits page first.

## Operations

| Task | Command |
|---|---|
| Watch workers | `sudo supervisorctl status`, `tail -f storage/logs/worker.log` |
| Failed jobs | `php artisan queue:failed`, `php artisan queue:retry all` |
| Re-queue stuck webhook events | `php artisan pixelforge:process-pending-webhooks --older-than=0` |
| Force a status check | `php artisan pixelforge:poll-generations` |
| Re-sync usage | `php artisan pixelforge:sync-usage --days=30` |
| Health | `GET /health` (200 or 503, no configuration values exposed) |

## Notes on Laravel Cloud

Laravel Cloud would also work: it provides PostgreSQL, a queue worker cluster and the scheduler. Set the same environment variables, add `php artisan migrate --force` as a deploy command, and enable the scheduler and a worker for the default queue.
