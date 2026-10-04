# SiteSentinel — Website Monitoring & Security Alerts

SiteSentinel is a self-hosted, server-rendered web application that continuously monitors a fleet of external websites you own or operate, along two independent dimensions: **Availability** (reachability, HTTP status, response time, TLS certificate validity) and **Security / Content Health** (content defacement, injected spam or keywords, unauthorized redirects, and SEO-structural regressions). A scheduled dispatcher fans due work out to a Redis-backed queue; a provider-independent detective engine turns probe results into **checks**, **detection signals**, and **incidents**, which are then delivered through Email, Telegram, and browser **push** providers. An admin console provides website CRUD, bulk actions, manual checks, per-page sizing, light/dark/system theming, and multi-page public status pages, backed by a redaction-first security model.

### Feature highlights

- **Monitoring engine** — configurable per-website interval (60s–3600s), timeout, SSRF-guarded probing, redirect-chain capture, response-body budget, SSL and redirect validation.
- **Detection rules** — the frozen `RULE-xx` catalogue scores signals into `info` / `warning` / `critical` severity with a correlation guard.
- **Incident lifecycle** — automatic open/acknowledge/resolve transitions with consecutive-failure and consecutive-success thresholds.
- **Notifications** — provider-independent dispatcher with dedup, per-website cooldown, circuit breaker, delivery logging, and per-channel **test** buttons; Email + Telegram + **browser push** (VAPID, opt-in from the admin UI, service worker at `/sw.js`).
- **Multiple status pages** — publish one or many public pages at `/status/{slug}` (HTML and `.json`), with private/password visibility modes, a projection cache, and staleness handling.
- **Admin UX** — bulk enable / disable / delete, manual per-website check, per-page row selector, Light/Dark/System theme switch, health widget.
- **Security-first** — no public registration, throttled login, session idle/absolute timeouts, audited admin provisioning, secret redaction, and least-privilege deployment defaults.

> **Frozen terminology** — this project uses **website**, **check**, **incident**, and **status page** consistently. Where you see those words, they mean the objects defined in [`DATABASE.md`](DATABASE.md).

---

## Table of contents

1. [Requirements](#1-requirements)
2. [Quick start — Docker Compose (production)](#2-quick-start--docker-compose-production)
3. [Quick start — native / manual install](#3-quick-start--native--manual-install)
4. [Admin bootstrap](#4-admin-bootstrap--no-public-registration)
5. [Environment configuration](#5-environment-configuration)
6. [Queue worker setup](#6-queue-worker-setup)
7. [Scheduler / cron setup](#7-scheduler--cron-setup)
8. [Web server / TLS](#8-web-server--tls)
9. [Post-install verification](#9-post-install-verification)
10. [Updating / maintenance](#10-updating--maintenance)
11. [Troubleshooting](#11-troubleshooting)
12. [Documentation index](#12-documentation-index)
13. [License & notes](#13-license--notes)

---

## 1. Requirements

SiteSentinel is a Laravel 13 application. The canonical production stack is **MySQL 8 + Redis + Nginx + PHP-FPM**, orchestrated with Docker Compose. Minimum versions are pinned by [`composer.json`](composer.json) and [`package.json`](package.json).

### Runtime

| Component | Minimum / required | Source of truth |
| --- | --- | --- |
| PHP | **8.3+** (`"php": "^8.3"`); the shipped image and the documented stack use **8.4** (`FROM php:8.4-fpm-alpine`) | [`composer.json`](composer.json), [`docker/app/Dockerfile`](docker/app/Dockerfile) |
| Laravel | **13.17+** (`laravel/framework: ^13.17`) | [`composer.json`](composer.json) |
| MySQL | **8.0** (`mysql:8.0`), **InnoDB**, **utf8mb4** / `utf8mb4_unicode_ci` | [`docker-compose.yml`](docker-compose.yml), [`config/database.php`](config/database.php) |
| Redis | **7** (`redis:7-alpine`) | [`docker-compose.yml`](docker-compose.yml) |
| Node.js | Vite **8** + Tailwind **4** toolchain — use Node **20 LTS or newer** | [`package.json`](package.json) |
| Web server | Nginx (any current stable; `nginx:alpine` in Compose) + PHP-FPM 8.4 | [`docker/nginx/prod.conf`](docker/nginx/prod.conf) |

### Required PHP extensions

The production image installs exactly this set ([`docker/app/Dockerfile`](docker/app/Dockerfile)) — match it on a native host:

```
pdo  pdo_mysql  mbstring  bcmath  pcntl  intl  redis  openssl  ctype  fileinfo  tokenizer  xml  curl
```

`redis` is the C extension used by the container; the application itself is configured for the pure-PHP **`predis`** client by default (`REDIS_CLIENT=predis`), so `predis/predis` works even without the extension. See [`.env.example`](.env.example).

### PHP Composer dependencies (production)

- `laravel/framework ^13.17`
- `laravel/tinker ^3.0`
- `minishlink/web-push ^11.0` — browser push (ADR-032)
- `predis/predis ^3.6` — Redis client

### Database note (ADR-022 — read this)

**MySQL 8 is the canonical production database.** SQLite exists **only** as a local-development convenience (`DB_CONNECTION=sqlite` + `touch database/database.sqlite`). **Never deploy with SQLite.** The production Compose files force MySQL and the `.env.example` default is `DB_CONNECTION=mysql`. See [`DECISIONS.md`](DECISIONS.md) ADR-022 and [`DATABASE.md`](DATABASE.md) §8.

---

## 2. Quick start — Docker Compose (production)

The base file [`docker-compose.yml`](docker-compose.yml) defines the topology; [`docker-compose.prod.yml`](docker-compose.prod.yml) is an **override** that hardens it. Always run production with **both** files. The resulting services are: `nginx`, `app`, `scheduler`, `worker`, `mysql`, `redis`, plus a one-shot `migrate` service.

### Step 1 — Clone and enter the project

```bash
git clone <your-fork-or-repo-url> sitesentinel
cd sitesentinel
```

### Step 2 — Create your environment file

```bash
cp .env.example .env
```

Edit `.env` and set, at minimum: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `NGINX_SERVER_NAME`, `APP_TIMEZONE`, the `DB_*` block, and a **non-empty** `REDIS_PASSWORD`.

> The production override is **fail-closed**: `docker compose` refuses to start if `REDIS_PASSWORD` is unset (`REDIS_PASSWORD must be set for production`). This is intentional — there is no accidental no-auth Redis.

### Step 3 — Generate the application key

```bash
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine \
  php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
# paste the printed value into APP_KEY= in .env
```

Do **not** print or commit a real key. Rotating `APP_KEY` requires re-encrypting `encrypted` casts; keep the old key in `APP_PREVIOUS_KEYS` during rotation (SECURITY.md §12.1.4).

### Step 4 — Build assets

```bash
npm ci
npm run build
```

This produces the Vite manifest in `public/build/` that the Nginx/`app` containers serve.

### Step 5 — Bring the stack up

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.yml -f docker-compose.prod.yml ps
```

Wait until `mysql` and `redis` report **healthy**; `app`, `scheduler`, and `worker` depend on them.

### Step 6 — Run migrations (one-shot service)

Migrations are **never** run automatically on boot. Execute the dedicated `migrate` service explicitly:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm migrate
```

### Step 7 — Seed reference data

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  exec app php artisan db:seed --force
```

The seeder calls `DetectionRuleSeeder` (the `RULE-xx` catalogue) and `StatusPageSeeder` (creates the default `/status/{slug}` page). It deliberately **does not** create an admin — see [§4](#4-admin-bootstrap--no-public-registration).

### Step 8 — Create the admin account

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  exec app php artisan sentinel:install-admin
```

You will be prompted (hidden input) for email, name, and a password of at least 12 characters. See [§4](#4-admin-bootstrap--no-public-registration).

### Step 9 — Set up TLS in front

Only Nginx is published to the host (port 80). Terminate TLS with an operator-managed reverse proxy / load balancer, then see [§8](#8-web-server--tls). HSTS must only be sent over HTTPS, which is why it belongs at the terminator.

### What each service does

| Service | Command (from the repo) | Role |
| --- | --- | --- |
| `nginx` | `nginx:alpine`, envsubst-rendered [`docker/nginx/prod.conf`](docker/nginx/prod.conf) | Serves `/public`, proxies to `app:9000` |
| `app` | `php-fpm --nodaemonize` via [`docker/app/entrypoint.sh`](docker/app/entrypoint.sh) | PHP-FPM request handling |
| `scheduler` | `php artisan schedule:work` | Runs the Laravel scheduler in-process |
| `worker` | `php artisan queue:work redis --queue=monitoring,notifications,maintenance,default --sleep=3 --tries=3 --max-time=3600` | Drains the queues |
| `mysql` | `mysql:8.0` | Canonical database |
| `redis` | `redis:7-alpine` with `--requirepass` | Cache + queue |
| `migrate` | `php artisan migrate --force` (`profiles: ["migrate"]`) | One-shot migrations |

---

## 3. Quick start — native / manual install

For a host without Docker: PHP 8.3+/8.4, Composer, MySQL 8, Redis, Node 20+, and a web server (Nginx recommended).

### Step 1 — Install dependencies and scaffold

```bash
git clone <your-fork-or-repo-url> sitesentinel
cd sitesentinel
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

### Step 2 — Generate the application key

```bash
php artisan key:generate
```

This writes a fresh `base64:` key into `APP_KEY`. (Alternatively `php artisan key:generate --show` prints one to copy into a secret manager; never commit it.) Never print a real key in logs or documentation.

### Step 3 — Create the MySQL database and least-privilege user

Use MySQL 8 with **utf8mb4** (the app expects `utf8mb4` / `utf8mb4_unicode_ci`, per [`config/database.php`](config/database.php)):

```sql
CREATE DATABASE sitesentinel
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'sitesentinel'@'localhost' IDENTIFIED BY 'CHANGE_ME_DB_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON sitesentinel.* TO 'sitesentinel'@'localhost';
FLUSH PRIVILEGES;
```

> **Never use the MySQL `root` account for the application.** Grant DML + schema-evolution privileges on the app schema only (SECURITY.md §11 L20). `DB_ROOT_PASSWORD` in `.env` is used only by the Compose bootstrap/healthcheck, never at runtime.

Set the connection in `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sitesentinel
DB_USERNAME=sitesentinel
DB_PASSWORD=CHANGE_ME_DB_PASSWORD
```

For **local development only** you may instead use SQLite (ADR-022):

```bash
touch database/database.sqlite
# .env: DB_CONNECTION=sqlite  and  DB_DATABASE=database/database.sqlite
```

### Step 4 — Configure Redis

Native installs point Redis at localhost:

```
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=   # set a real password if your Redis requires auth
REDIS_DB=0
REDIS_CACHE_DB=1
```

### Step 5 — Migrate and seed

```bash
php artisan migrate --force
php artisan db:seed --force
```

### Step 6 — Build front-end assets

```bash
npm ci
npm run build
```

(`npm ci` installs from `package-lock.json`; use `npm install` only if you intend to change the lockfile.)

### Step 7 — Set storage permissions

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

### Step 8 — Create the admin account

```bash
php artisan sentinel:install-admin
```

See [§4](#4-admin-bootstrap--no-public-registration).

### Step 9A — Run it locally with the dev server

```bash
php artisan serve
```

Then open `http://127.0.0.1:8000` (login lives at `/`). For the full developer experience use `composer dev`, which runs the dev server, queue listener, log tailer, and Vite together.

### Step 9B — Run it under Nginx + PHP-FPM

Point the server root at the `public/` directory and mirror [`docker/nginx/prod.conf`](docker/nginx/prod.conf):

```nginx
upstream php-fpm {
    server unix:/run/php/php8.4-fpm.sock;
}

server {
    listen 80;
    server_name monitor.example.com;
    root /var/www/sitesentinel/public;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "0" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;

    index index.php;
    charset utf-8;
    client_max_body_size 8m;
    server_tokens off;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass php-fpm;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_param HTTP_X_FORWARDED_PROTO $http_x_forwarded_proto;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

Because the application sits behind a proxy, set `TRUSTED_PROXIES` to the exact proxy address(es)/CIDR(s) (never `*`) so IP-keyed throttling works — see [`.env.example`](.env.example) and `bootstrap/app.php`.

---

## 4. Admin bootstrap — no public registration

**There is no public registration route and no default-password seeder** (SECURITY.md §2.3, PRD.md §15.3, ADR-023). The first — and every subsequent — admin is provisioned out of band with the `sentinel:install-admin` command ([`app/Console/Commands/InstallAdminCommand.php`](app/Console/Commands/InstallAdminCommand.php)).

### Interactive (recommended)

```bash
php artisan sentinel:install-admin
```

It prompts for:

- **Admin email** (validated, lower-cased)
- **Admin display name**
- **Admin password** — hidden input, minimum **12 characters** (`SENTINEL_MIN_PASSWORD_LENGTH`)

The account is created with `role = admin` and `is_active = true`, and the provisioning event (`auth.admin_provisioned`) is written to the audit log.

### Non-interactive / scripted

```bash
php artisan sentinel:install-admin \
  --email="admin@example.com" \
  --name="Site Administrator" \
  --password="$ADMIN_PASSWORD_FROM_SECRET_STORE"
```

> Prefer the interactive prompt. When scripting, pass `--password` from a secret manager — **never** hard-code it or leave it in shell history (ADR-023).

### Logging in

1. Browse to `https://monitor.example.com/` — **`/` is the login page** (PRD.md §22).
2. Authenticate with the admin email + password.
3. You land on the admin dashboard at `/admin`.

All `/admin` routes require `auth` + `admin` + session-timeout middleware. Guest routes are limited to login and password reset; a password-reset link is mailed using your `MAIL_*` configuration.

Compose equivalents, if you run containers:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan sentinel:install-admin
```

---

## 5. Environment configuration

All variables live in [`.env.example`](.env.example); copy it to `.env`. The table below lists the variables that matter most for a working deployment. **Never commit real secrets** — use a secret manager or host-level environment injection.

### Application

| Variable | Default | Notes |
| --- | --- | --- |
| `APP_NAME` | `SiteSentinel` | Shown in UI and mail |
| `APP_ENV` | `production` | Keep `production` on live hosts |
| `APP_KEY` | `CHANGE_ME_…` | Generate with `php artisan key:generate`; rotate via `APP_PREVIOUS_KEYS` |
| `APP_DEBUG` | `false` | Must be `false` in production (SECURITY.md §11) |
| `APP_URL` | `https://monitor.example.com` | Used for absolute URL generation |
| `APP_TIMEZONE` | `UTC` | Scheduler + display timezone |
| `NGINX_SERVER_NAME` | `monitor.example.com` | Substituted into the Nginx template |
| `TRUSTED_PROXIES` | *(empty)* | Exact proxy IPs/CIDRs; never `*` |
| `BCRYPT_ROUNDS` | `12` | Password hashing cost |

### Database (MySQL 8 canonical — ADR-022)

| Variable | Example | Notes |
| --- | --- | --- |
| `DB_CONNECTION` | `mysql` | `sqlite` for local dev only |
| `DB_HOST` | `mysql` (Compose) / `127.0.0.1` (native) | |
| `DB_PORT` | `3306` | |
| `DB_DATABASE` | `sitesentinel` | |
| `DB_USERNAME` | `sitesentinel` | Least-privilege app account |
| `DB_PASSWORD` | `CHANGE_ME_DB_PASSWORD` | Secret |
| `DB_ROOT_PASSWORD` | `CHANGE_ME_DB_ROOT_PASSWORD` | Bootstrap/healthcheck only; not used at runtime |

### Session (HTTPS-aware)

| Variable | Default | Notes |
| --- | --- | --- |
| `SESSION_DRIVER` | `database` | |
| `SESSION_LIFETIME` | `120` | Minutes |
| `SESSION_SECURE_COOKIE` | `true` | Requires HTTPS |
| `SESSION_HTTP_ONLY` | `true` | |
| `SESSION_SAME_SITE` | `lax` | |
| `SESSION_DOMAIN` | `null` | Set to your host in production |

### Cache & queue (Redis)

| Variable | Default | Notes |
| --- | --- | --- |
| `QUEUE_CONNECTION` | `redis` | |
| `CACHE_STORE` | `redis` | |
| `REDIS_CLIENT` | `predis` | `predis` (pure PHP) or `phpredis` |
| `REDIS_HOST` | `redis` (Compose) / `127.0.0.1` (native) | |
| `REDIS_PORT` | `6379` | |
| `REDIS_PASSWORD` | `CHANGE_ME_REDIS_PASSWORD` | **Required** in production (fail-closed) |
| `REDIS_DB` / `REDIS_CACHE_DB` | `0` / `1` | Separate logical DBs for queue vs cache |

### Mail & Telegram

| Variable | Example | Notes |
| --- | --- | --- |
| `MAIL_MAILER` | `smtp` | `log` for local dev |
| `MAIL_HOST` / `MAIL_PORT` | `smtp.example.com` / `587` | |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | *(secret)* | |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | `alerts@example.com` / `${APP_NAME}` | |
| `TELEGRAM_BOT_TOKEN` | *(empty)* | Secret; set via secret store |
| `TELEGRAM_DEFAULT_CHAT_ID` | *(empty)* | Default Telegram target |

### Browser push (VAPID — ADR-032)

| Variable | Example | Notes |
| --- | --- | --- |
| `VAPID_PUBLIC_KEY` | *(generated)* | Served to the browser opt-in JS |
| `VAPID_PRIVATE_KEY` | *(generated)* | **Secret** — never logged, rendered, or placed in a payload |
| `VAPID_SUBJECT` | `mailto:admin@example.com` | Contact identifier sent to the push service |
| `VAPID_TTL_SECONDS` | `3600` | Push message TTL |

These map to `config/sentinel.php` → `push.*` ([`config/sentinel.php`](config/sentinel.php)).

**Generating VAPID keys.** The project does **not** ship a `webpush:vapid` artisan command; `.env.example` and NOTIFICATIONS.md §7.3 document generation with the standard `web-push` CLI, which is already available transitively through `minishlink/web-push`:

```bash
npx web-push generate-vapid-keys
```

Copy the emitted **Public Key** into `VAPID_PUBLIC_KEY` and the **Private Key** into `VAPID_PRIVATE_KEY`. Treat the private key exactly like `APP_KEY`: generate once per deployment, store in the secret manager, and never print it. When both keys are present the push opt-in appears in the admin notification UI; when they are empty, push is simply disabled.

### Monitoring, scoring, retention, auth, status page

These tune the engine and are safe to leave at their documented defaults. Representative variables (full list in [`.env.example`](.env.example) and [`config/sentinel.php`](config/sentinel.php)):

| Group | Example variables |
| --- | --- |
| Monitoring | `SENTINEL_DEFAULT_INTERVAL_SECONDS` (300), `SENTINEL_DEFAULT_TIMEOUT_SECONDS` (10), `SENTINEL_CONSECUTIVE_FAILURES_THRESHOLD` (2), `SENTINEL_CONSECUTIVE_SUCCESSES_THRESHOLD` (2), `SENTINEL_MAX_CONCURRENT_CHECKS` (10) |
| Probe limits | `SENTINEL_CONNECT_TIMEOUT_SECONDS` (5), `SENTINEL_TOTAL_REQUEST_TIMEOUT_SECONDS` (15), `SENTINEL_JOB_TIMEOUT_SECONDS` (30), `SENTINEL_DNS_TIMEOUT_SECONDS` (3), `SENTINEL_MAX_REDIRECT_HOPS` (5) |
| Scoring | `SENTINEL_SCORING_THRESHOLD_INFO` (1), `SENTINEL_SCORING_THRESHOLD_WARNING` (8), `SENTINEL_SCORING_THRESHOLD_CRITICAL` (15), `SENTINEL_CORRELATION_GUARD_MIN_CATEGORIES` (2), `SENTINEL_CATEGORY_CAP` (12) |
| Retention | `SENTINEL_RETENTION_CHECKS_DAYS` (30), `SENTINEL_RETENTION_INCIDENTS_DAYS` (365), `SENTINEL_RETENTION_NOTIFICATION_LOGS_DAYS` (90), `SENTINEL_RETENTION_SNAPSHOTS_DAYS` (14) |
| Auth / throttle | `SENTINEL_MAX_LOGIN_ATTEMPTS` (5), `SENTINEL_MIN_PASSWORD_LENGTH` (12), `SENTINEL_IDLE_TIMEOUT_MINUTES` (30), `SENTINEL_ABSOLUTE_TIMEOUT_MINUTES` (480) |
| Status page | `SENTINEL_STATUS_TTL_FLOOR` (60), `SENTINEL_STATUS_STALE_MULTIPLIER` (2), `SENTINEL_STATUS_STALE_FLOOR` (300), `SENTINEL_STATUS_UNLOCK_MAX` (5), `SENTINEL_STATUS_HISTORY_ENABLED` (false) |

---

## 6. Queue worker setup

SiteSentinel dispatches three logical queues plus a default: **`monitoring`**, **`notifications`**, **`maintenance`**, and **`default`**. The command below is exactly what the Compose `worker` service runs ([`docker-compose.yml`](docker-compose.yml)):

```bash
php artisan queue:work redis \
  --queue=monitoring,notifications,maintenance,default \
  --sleep=3 \
  --tries=3 \
  --max-time=3600
```

- `--queue=...` — priority order; monitoring checks are drained before notifications.
- `--sleep=3` — poll interval when idle.
- `--tries=3` — attempts before a job is marked failed (notification retry/backoff is otherwise fixed in code: 3 tries, backoff 60/300/900s — see [`config/sentinel.php`](config/sentinel.php) `notifications`).
- `--max-time=3600` — recycle the worker hourly to bound memory; the process manager restarts it.

### The worker must run continuously

A queue worker is a long-lived process. If it stops, checks stop being probed and **notifications stop being delivered** — the app will look alive while silently dropping alerts. Run at least one worker at all times.

- **Docker:** the `worker` service is `restart: unless-stopped`; no extra action needed.
- **Bare-metal / native:** supervise it with systemd or Supervisor. The example below uses **Supervisor**; the `numprocs` setting lets you scale horizontally (keep total workers ≤ `SENTINEL_MAX_CONCURRENT_CHECKS`, default 10).

### Supervisor configuration

Install Supervisor (`apt install supervisor` / `dnf install supervisor`), then create `/etc/supervisor/conf.d/sitesentinel-worker.conf`:

```ini
[program:sitesentinel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/sitesentinel/artisan queue:work redis --queue=monitoring,notifications,maintenance,default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/sitesentinel/storage/logs/worker.log
stopwaitsecs=3600
```

Apply it:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start sitesentinel-worker:*
sudo supervisorctl status
```

After any code deployment, tell running workers to reload: `php artisan queue:restart` (see [§10](#10-updating--maintenance)).

---

## 7. Scheduler / cron setup

The Laravel scheduler drives the whole monitoring plane. Its wiring lives in [`routes/console.php`](routes/console.php):

- `Schedule::command('model:prune')->daily();` — retention pruning.
- A **named, non-overlapping** task `sitesentinel:dispatch-due-checks` runs **every minute**, selects active websites whose `next_check_at` is due, and dispatches `RunWebsiteCheck` jobs onto the monitoring queue. This task is asserted by the test suite (`tests/Feature/Security/ReferenceWorkloadInvariantsTest.php`), so if it is not scheduled, nothing gets checked.

**The core requirement:** something must invoke Laravel's scheduler **once per minute, continuously**. You have two idiomatic ways to do that:

| Approach | Entry point | When to use |
| --- | --- | --- |
| **Cron / platform scheduler** | `php artisan schedule:run` every 60 seconds | Bare-metal Linux, macOS, Windows, cPanel, Plesk, DirectAdmin, aaPanel, FlyEnv |
| **Long-running process** | `php artisan schedule:work` | A container/service that stays up (Docker `scheduler` service, systemd unit) |

Platform-specific walkthroughs follow: Linux [§7.2](#72-linux--bare-metal--systemd-host), macOS [§7.3](#73-macos--launchd-or-crontab), Windows Task Scheduler [§7.4](#74-windows--task-scheduler), cPanel [§7.5](#75-cpanel--cron-jobs-ui), Plesk [§7.6](#76-plesk--scheduled-tasks), DirectAdmin [§7.7](#77-directadmin--cron-jobs), Docker [§7.8](#78-docker--scheduler-as-a-compose-service), **aaPanel [§7.10](#710-aapanel--cron-linux-panel)**, **Windows FlyEnv [§7.11](#711-windows--flyenv-native-php)**, and **manual checks [§7.12](#712-running-a-check-manually-no-scheduler)**.

`schedule:run` fires every task that is due at that moment and exits — it depends on an external clock. `schedule:work` runs the scheduler in-process on a one-minute loop and needs no cron. **Never run both** against the same deployment.

### 7.1 The canonical cron entry

On any POSIX host, add this single line. Substitute the absolute path to `artisan` and the PHP binary:

```cron
* * * * * cd /var/www/sitesentinel && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

> **Common path bug:** cron runs with a minimal environment. Always `cd` into the project root first and use **absolute** paths for both `php` and `artisan`. The `>> /dev/null 2>&1` silences the per-minute mail; use `storage/logs/scheduler.log` instead if you want a trail.

### 7.2 Linux — bare-metal / systemd host

**Option A — crontab (simplest):**

```bash
crontab -e
```

```cron
* * * * * cd /var/www/sitesentinel && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

**Option B — systemd timer (preferred for observability):**

Keep the schedule running in-process and let systemd supervise it.

`/etc/systemd/system/sitesentinel-scheduler.service`:

```ini
[Unit]
Description=SiteSentinel scheduler (php artisan schedule:work)
After=network.target mysql.service redis.service

[Service]
Type=simple
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/sitesentinel
ExecStart=/usr/bin/php /var/www/sitesentinel/artisan schedule:work

[Install]
WantedBy=multi-user.target
```

Enable it:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now sitesentinel-scheduler.service
sudo systemctl status sitesentinel-scheduler.service
```

If you prefer systemd to invoke `schedule:run` on a clock instead of running `schedule:work`, pair the service above (with `Type=oneshot`) with a timer:

`/etc/systemd/system/sitesentinel-scheduler.timer`:

```ini
[Unit]
Description=Run the SiteSentinel scheduler every minute

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Persistent=true
Unit=sitesentinel-scheduler.service

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now sitesentinel-scheduler.timer
systemctl list-timers | grep sitesentinel
```

### 7.3 macOS — launchd (or crontab)

**Option A — crontab** (works the same as Linux; run `crontab -e`):

```cron
* * * * * cd /Users/you/sitesentinel && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

**Option B — launchd plist** (recommended on macOS; note `StartInterval` is in **seconds**, so 60 ≈ once per minute):

`~/Library/LaunchAgents/com.sitesentinel.scheduler.plist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.sitesentinel.scheduler</string>
    <key>ProgramArguments</key>
    <array>
        <string>/usr/bin/php</string>
        <string>/Users/you/sitesentinel/artisan</string>
        <string>schedule:work</string>
    </array>
    <key>WorkingDirectory</key>
    <string>/Users/you/sitesentinel</string>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>/Users/you/sitesentinel/storage/logs/scheduler.log</string>
    <key>StandardErrorPath</key>
    <string>/Users/you/sitesentinel/storage/logs/scheduler.log</string>
</dict>
</plist>
```

Load it:

```bash
launchctl load -w ~/Library/LaunchAgents/com.sitesentinel.scheduler.plist
launchctl list | grep sitesentinel
```

### 7.4 Windows — Task Scheduler

1. Open **Task Scheduler** → **Create Task…** (not *Basic Task*, so you can set repetition precisely).
2. **General** tab: name it `SiteSentinel Scheduler`; select **Run whether user is logged on or not**; check **Run with highest privileges** if the task user needs it.
3. **Triggers** tab → **New…**:
   - Begin the task: **On a schedule**
   - Settings: **Daily**
   - **Repeat task every: 1 minute** for a duration of **Indefinitely** (this is the key setting).
4. **Actions** tab → **New…**:
   - Action: **Start a program**
   - Program/script: `C:\php\php.exe`
   - Add arguments: `artisan schedule:run`
   - Start in: `C:\inetpub\sitesentinel` (the project root)
5. **Settings** tab: enable **Run task as soon as possible after a scheduled start is missed**; uncheck **Stop the task if it runs longer than…** (or set it just under 1 minute).
6. Save. Verify it appears under the Task Scheduler Library and that **Last Run Result** is `0x0`.

Alternatively, run the persistent loop instead of a per-minute task by making the argument `artisan schedule:work` and trigger it **At startup**, removing the repetition.

### 7.5 cPanel — Cron Jobs UI

1. Log in to cPanel → **Advanced** → **Cron Jobs**.
2. Under **Add New Cron Job**, set:
   - **Common Settings:** *Once Per Minute (\* \* \* \* \*)*
   - **Command:**
     ```bash
     cd /home/USER/sitesentinel && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
     ```
     (Use the absolute `php` path shown by cPanel's *PHP Selector*, or pin a version such as `/usr/local/bin/php84`.)
3. Click **Add New Cron Job**. Confirm it appears in the **Current Cron Jobs** list with the minute interval.

> If cPanel's cron silently does nothing, the PHP path or the project path is usually wrong. Verify with `crontab -l` over SSH and run the command by hand once.

### 7.6 Plesk — Scheduled Tasks

1. In Plesk, go to **Websites & Domains** → your domain → **Scheduled Tasks**.
2. Click **Add Task**.
3. **Task type:** *Run a command*.
4. **Command:**
   ```bash
   cd /var/www/vhosts/example.com/sitesentinel && /opt/plesk/php/8.4/bin/php artisan schedule:run
   ```
5. **Run:** *Cron style*, then set **Minute:** `*`, **Hour:** `*`, **Day of month:** `*`, **Month:** `*`, **Day of week:** `*` (every minute).
6. Click **OK**. Confirm the task is listed and, after a minute, check its output for errors.

### 7.7 DirectAdmin — Cron Jobs

1. Log in to the DirectAdmin user panel → **Advanced Features** → **Cron Jobs**.
2. Click **Create Cron Job**.
3. Set the five time fields to `*` `*` `*` `*` `*` (every minute).
4. **Command:**
   ```bash
   cd /home/USER/domains/example.com/sitesentinel && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
   ```
5. Save. Verify the job appears in the list; use **Edit** to adjust the PHP binary path if the host uses a versioned binary (for example `/usr/local/php84/bin/php`).

### 7.8 Docker — scheduler as a Compose service

The Compose stack already ships a dedicated **`scheduler`** service that runs `php artisan schedule:work` in-process ([`docker-compose.yml`](docker-compose.yml)). There is **no cron inside the containers** and none is needed. Confirm it is up:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml ps scheduler
docker compose -f docker-compose.yml -f docker-compose.prod.yml logs -f scheduler
```

The service inherits `restart: unless-stopped` and a healthcheck from the production override, so it self-heals if the container dies. **Do not** add a `schedule:run` cron to the `app` container — the Compose file explicitly warns against it (it would race the dedicated scheduler).

### 7.9 Single-container option — `schedule:work`

If you run SiteSentinel as a single long-lived process (a systemd unit, a Supervisord program, a Kubernetes Deployment, or a plain `docker run`), use:

```bash
php artisan schedule:work
```

It blocks and runs the scheduler on an internal one-minute loop — no external cron required. Choose it when there is exactly **one** instance of the app; choose `schedule:run` + cron (or the Compose `scheduler` service) when the process does not stay resident. **Never run `schedule:work` on more than one replica**, or tasks (notably the dispatch sweep) will run concurrently despite `withoutOverlapping`.

### 7.10 aaPanel — Cron (Linux panel)

aaPanel is a Linux control panel; its **Cron** module drives the standard `schedule:run` entry once per minute.

1. Open the aaPanel web UI → **Cron** in the left menu.
2. Click **Add Task**.
3. Set:
   - **Task Type:** *Shell Script*
   - **Task Name:** `SiteSentinel Scheduler`
   - **Execution Cycle:** *N Minutes* → **1** minute
   - **Script Content:**
     ```bash
     cd /www/wwwroot/sitesentinel && /www/server/php/84/bin/php artisan schedule:run >> /dev/null 2>&1
     ```
     Substitute the real project path and the **absolute PHP 8.4 binary** shown by aaPanel's **App Store → PHP** (commonly `/www/server/php/84/bin/php`).
4. Save. The task appears in the list; the **Log** button on its row shows the captured output.

> **Run as the site owner.** aaPanel cron runs as `root` by default. A `storage`/`bootstrap/cache` permission error means the scheduler wrote files as the wrong user. Either set the task's run user to the PHP-FPM pool user (`www`), or `chown -R www:www storage bootstrap/cache` and re-run. Queue workers must run under the **same** user as the web server so they can read the queue and write logs.

> **Path bug (aaPanel).** The panel uses its own bundled PHP, not the system `php`. Always paste the full path to the 8.4 binary; a bare `php artisan …` may resolve to a different version or fail outright.

### 7.11 Windows — FlyEnv (native PHP)

[FlyEnv](https://flyenv.com/) (formerly PhpWebStudy) runs PHP-FPM, MySQL, and Redis natively on Windows and includes a **Cron** tool that can invoke the scheduler. Two supported approaches:

**Option A — FlyEnv Cron tool (per-minute task):**

1. Open FlyEnv → **Cron** in the left sidebar → **Add**.
2. Set:
   - **Name:** `SiteSentinel Scheduler`
   - **Cron expression:** `* * * * *` (every minute)
   - **Shell / Command:**
     ```bat
     cd /d C:\inetpub\sitesentinel && php artisan schedule:run
     ```
     Point the command at the FlyEnv-managed PHP 8.4 binary if `php` is not on `PATH` (e.g. `"C:\Users\you\AppData\Roaming\FlyEnv\php\php-8.4\php.exe" artisan schedule:run`).
3. Save and enable. FlyEnv streams the command output into its Cron log panel.

**Option B — keep FlyEnv running the services, use Task Scheduler for the clock:** follow [§7.4 Windows — Task Scheduler](#74-windows--task-scheduler), pointing **Program/script** at the FlyEnv PHP binary and **Start in** at the project root.

> **Persistent loop instead of cron (FlyEnv).** If the FlyEnv app stays resident, add a second FlyEnv **Service/Command** that runs `php artisan schedule:work` and keep it started. **Never run both** `schedule:run` (cron) and `schedule:work` against the same deployment — the dispatch sweep would run twice.

> **Queue worker on Windows.** FlyEnv's PHP-FPM serves requests; the monitoring plane still needs a separate `php artisan queue:work` process ([§6](#6-queue-worker-setup)). Keep it alive with a FlyEnv Service or a Task Scheduler entry, and run `php artisan queue:restart` after every code change.

### 7.12 Running a check manually (no scheduler)

To verify the monitoring pipeline without waiting for the scheduler, trigger a check on demand. There are two paths; both only **queue** a `RunWebsiteCheck` job (AGENTS.md §9 — never an inline probe), so a worker must be running to drain it.

**Option A — from the admin UI (URL):**

1. Log in, open **Websites** (`GET /admin/websites`).
2. Click the **Run check** action on the website's row. That button submits a CSRF-protected form:
   ```text
   POST /admin/websites/{website}/check
   ```
   It is rate-limited to **30 requests/minute** and requires `auth` + `admin`. A raw `curl` must therefore carry a valid session cookie and the CSRF token — prefer the button in the UI.
3. The job lands on the `monitoring` queue. Watch it drain:
   ```bash
   php artisan queue:work redis --queue=monitoring --once
   ```

**Option B — from the CLI (bypasses the scheduler):**

```bash
php artisan sentinel:check-website {website}
```

`{website}` is the **website id** (the `websites.id` primary key). The command dispatches the same `RunWebsiteCheck` job and prints `Dispatched RunWebsiteCheck for website {id}`.

> **Verify end-to-end:** after dispatching, a new `checks` row appears for the website; if a detection rule fires, an `incidents` row is created and the notification dispatcher runs ([§9](#9-post-install-verification) step 3). If no row appears, the worker is not draining the queue ([§11](#11-troubleshooting)) or `QUEUE_CONNECTION` is wrong.

---

## 8. Web server / TLS

The production Nginx virtual host mirrors [`docker/nginx/prod.conf`](docker/nginx/prod.conf): document root at `public/`, `try_files` front-controller pattern, hardened `location ~ \.php$`, dotfile denial with an `/.well-known` exception, and static security headers (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`).

### HTTPS requirement

Web Push **requires a secure context**: browsers only allow service-worker registration and `PushManager.subscribe()` over **HTTPS** (localhost is the sole exception). Production sessions also use `SESSION_SECURE_COOKIE=true`, which means cookies are sent only over HTTPS. **Terminate TLS before you attempt to enable browser push.**

### Let's Encrypt with certbot

Install certbot and obtain a certificate for your host (`monitor.example.com`):

```bash
sudo apt install certbot python3-certbot-nginx     # Debian/Ubuntu
sudo certbot --nginx -d monitor.example.com --redirect --agree-tos -m admin@example.com
```

`--redirect` installs an HTTP→HTTPS 301. certbot also sets up automatic renewal; confirm with:

```bash
sudo certbot renew --dry-run
```

If you terminate TLS in front of the Compose Nginx container (load balancer / edge proxy), obtain the certificate on that terminator and forward plain HTTP to the container's published port 80, setting `TRUSTED_PROXIES` to the terminator's address and `SESSION_SECURE_COOKIE=true`. Emit **HSTS** (`Strict-Transport-Security`) only at the TLS terminator, and only once HTTPS is confirmed working.

A minimal TLS server block for a native Nginx install:

```nginx
server {
    listen 443 ssl http2;
    server_name monitor.example.com;
    root /var/www/sitesentinel/public;

    ssl_certificate     /etc/letsencrypt/live/monitor.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/monitor.example.com/privkey.pem;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # ...the location blocks from §3 Step 9B...
}

server {
    listen 80;
    server_name monitor.example.com;
    return 301 https://$host$request_uri;
}
```

---

## 9. Post-install verification

Work through this list once after a fresh deployment.

1. **Health endpoint.** `GET /health` returns JSON `{"status":"ok","checks":{...}}` reporting `database`, `redis`, and `queue` (with `pending_jobs`).
   ```bash
   curl -s https://monitor.example.com/health | jq
   ```
   Every component should report `"status":"ok"`. A `"fail"` pinpoints which dependency to fix first.

2. **Create a website.** Log in at `/`, open **Websites → Create**, and add one with a reachable URL, the default 300s interval, and a timeout within the allowed 3–30s range.

3. **Run a manual check.** On the website row use the **Run check** action (or bulk actions). This only *queues* a `RunWebsiteCheck` job — watch the worker drain it:
   ```bash
   php artisan queue:work redis --queue=monitoring --once
   ```
   A new **check** row and, if configured, an **incident** should appear shortly. From the CLI you can instead dispatch by website id with `php artisan sentinel:check-website {id}` — see [§7.12](#712-running-a-check-manually-no-scheduler) for both the UI URL (`POST /admin/websites/{website}/check`) and the command.

4. **Confirm a notification.** Add a channel under **Notifications** (Email or Telegram), use its **Test** button to verify the provider, then **Test-send** to deliver a labelled message through the queue. Inspect **Notification Logs** for the delivery record.

5. **Open the status page.** Browse to `/status/{slug}` (the seeded default page), and its machine-readable twin `/status/{slug}.json`. The slugless `/status` redirects to the default page. Toggle visibility/password in **Status Pages** and confirm the gate behaves.

6. **Enable browser push.** With VAPID keys set and HTTPS active, open the push opt-in in the notification UI and grant the browser permission. The subscription is registered via `POST /admin/push/subscribe`; use the **Send test push** button (`POST /admin/push/test`) to confirm a notification arrives.

7. **Switch theme.** Use the Light / Dark / System control in the header. The choice persists (localStorage + cookie) and "System" follows the OS; there should be no flash of the wrong theme on reload.

8. **Verify the scheduler.** Wait one minute past your cron/timer, then check that due checks were dispatched:
   ```bash
   php artisan schedule:list
   ```
   A scheduled run creates fresh checks as `next_check_at` lapses. If nothing happens, revisit [§7](#7-scheduler--cron-setup) and [§11](#11-troubleshooting).

---

## 10. Updating / maintenance

Standard deployment sequence:

```bash
# 1. Put the app into maintenance mode (optional but tidy)
php artisan down

# 2. Fetch code + dependencies
git pull
composer install --no-dev --optimize-autoloader

# 3. Rebuild front-end assets
npm ci
npm run build

# 4. Apply schema changes (ALWAYS --force in production)
php artisan migrate --force

# 5. Rebuild caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. Recycle long-running workers so they pick up new code
php artisan queue:restart

# 7. Back out of maintenance mode
php artisan up
```

Notes:

- **`migrate --force`** is required because Laravel prompts for confirmation in production; the Compose `migrate` service already passes `--force`.
- **`queue:restart`** is essential — workers hold old code in memory. Nothing else recycles them.
- After config changes, clear first (`php artisan config:clear`) if a cache is stale, then re-cache.
- Compose operators: rebuild and re-create with
  ```bash
  docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
  docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm migrate
  docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan queue:restart
  ```
- Retention pruning runs daily via the scheduler's `model:prune` task; windows are controlled by the `SENTINEL_RETENTION_*` variables ([§5](#5-environment-configuration)).

---

## 11. Troubleshooting

### The queue is not draining (checks or alerts stall)

- **Symptom:** websites stay "pending", notification logs stop growing, `/health` reports a growing `queue.pending_jobs`.
- **Check:** `php artisan queue:work redis --queue=monitoring --once` in the foreground — it prints the job it processed (or the exception). Confirm `QUEUE_CONNECTION=redis` and that `REDIS_PASSWORD` matches your Redis instance.
- **Fix:** ensure a supervised worker is actually running ([§6](#6-queue-worker-setup)). After deploying code, always `php artisan queue:restart`.

### Permission errors (`storage`, `bootstrap/cache`)

- **Symptom:** HTTP 500 with "failed to open stream: Permission denied", or the log file is unwritable.
- **Fix (native):**
  ```bash
  sudo chown -R www-data:www-data storage bootstrap/cache
  sudo chmod -R ug+rwX storage bootstrap/cache
  ```
- **Docker:** the entrypoint sets these on boot; if you mounted a host directory, match its ownership to the container's `www-data` user.

### MySQL connection failures

- **Symptom:** `SQLSTATE[HY000] [2002]` (no socket) or `[1045]` (access denied).
- **Check:** verify `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. From a native host, `mysql -u sitesentinel -p sitesentinel -e 'select 1'`. From Compose, use the service hostname `mysql`, not `127.0.0.1`.
- **Confirm the database exists with the right charset:** `SHOW CREATE DATABASE sitesentinel;` should report `utf8mb4` / `utf8mb4_unicode_ci`. Remember **SQLite is local-dev only** (ADR-022) — if `DB_CONNECTION` is `sqlite` on a live host, that is the bug.

### The scheduler is not firing

- **Symptom:** no new checks ever appear despite active websites.
- **The classic cron path bug:** cron has a minimal environment. Your entry must `cd` into the project root and use **absolute** paths:
  ```
  * * * * * cd /var/www/sitesentinel && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
  ```
  A relative `php artisan` or a wrong `php` binary silently no-ops.
- **Verify the task is registered:** `php artisan schedule:list` should show `sitesentinel:dispatch-due-checks` running every minute.
- **Prove it manually:** `php artisan schedule:run` and watch for output; then run the dispatcher logic by hand with `php artisan tinker` or by queueing a manual check from the UI.
- **Docker:** confirm the `scheduler` service is up and check its logs. Do **not** add a second cron that races it.
- **Windows/Plesk/cPanel/DirectAdmin/aaPanel/FlyEnv:** re-check the task's PHP path and project path, and that the schedule is genuinely *every minute* ([§7.10 aaPanel](#710-aapanel--cron-linux-panel), [§7.11 FlyEnv](#711-windows--flyenv-native-php)).

### Web push fails or never arrives

- **Permission:** the browser must have granted notification permission **over HTTPS**. A self-signed or plain-HTTP origin silently blocks `PushManager.subscribe()`. Re-check the certificate and `SESSION_SECURE_COOKIE`.
- **VAPID:** `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` must be a **matching pair** generated together. Mismatched keys cause signature rejections at the push service. Regenerate both with `npx web-push generate-vapid-keys` and re-subscribe (existing subscriptions are keyed to the old pair).
- **`VAPID_SUBJECT`:** must be a valid `mailto:` or `https:` URI; some push services reject an empty or malformed subject.
- **Stale subscriptions:** browsers expire endpoints. Unsubscribe and re-subscribe from the opt-in, then use **Send test push**.
- **Secrets:** never log the VAPID private key or subscription material (`endpoint`/`p256dh`/`auth`) — see AGENTS.md §8 (notification rules) and SECURITY.md §4.

### Wrong times / off-by-hours

- Set `APP_TIMEZONE` (default `UTC`) and restart the scheduler. Rebuild config caches after changing it (`php artisan config:clear && php artisan config:cache`).
- The cron/timer clock is the host's; make sure the host timezone matches your expectations for scheduled runs.

---

## 12. Documentation index

The authoritative specifications — read the relevant one before changing behaviour (AGENTS.md §1 prescribes the order):

| Document | Contents |
| --- | --- |
| [`PRD.md`](PRD.md) | Product requirements (`FR-*`), non-functional requirements (`NFR-*`), acceptance criteria (`AC-*`), MVP boundary |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Components, planes, queues, and deployment shape |
| [`DATABASE.md`](DATABASE.md) | Frozen table and column names, indexes, retention |
| [`DETECTION-RULES.md`](DETECTION-RULES.md) | `RULE-xx` catalogue, categories, weights, scoring |
| [`SECURITY.md`](SECURITY.md) | SSRF pipeline, auth, secrets, hardening |
| [`NOTIFICATIONS.md`](NOTIFICATIONS.md) | Dispatcher, providers, templates, dedup, delivery logging |
| [`STATUS-PAGE.md`](STATUS-PAGE.md) | Visibility modes, redaction boundary, public labels |
| [`PLAN.md`](PLAN.md) | Phase roadmap |
| [`DECISIONS.md`](DECISIONS.md) | Architecture Decision Records (ADRs), incl. ADR-022 MySQL-canonical, ADR-031 multiple status pages, ADR-032 browser push, ADR-033 theme |
| [`AGENTS.md`](AGENTS.md) | Coding-agent rulebook and invariants |
| [`CHANGELOG.md`](CHANGELOG.md) | Recorded changes |

---

## 13. License & notes

- **License:** MIT (see [`composer.json`](composer.json)).
- **Database:** MySQL 8 (InnoDB, utf8mb4) is canonical in production; SQLite is a local-development convenience only (ADR-022). Never deploy SQLite.
- **Secrets:** never commit a real `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `TELEGRAM_BOT_TOKEN`, or `VAPID_PRIVATE_KEY`. Generate on the host or inject from a secret manager; rotate `APP_KEY` via `APP_PREVIOUS_KEYS`.
- **Process model:** the queue worker and the scheduler must run continuously. On Docker, Compose handles both; on bare metal, use Supervisor ([§6](#6-queue-worker-setup)) and cron or a systemd timer ([§7](#7-scheduler--cron-setup)).
- **Self-hosted by design:** SiteSentinel performs outbound monitoring probes through an SSRF-guarded pipeline and emits no telemetry to third parties (see SECURITY.md and `tests/Feature/Security/NoTelemetryTest.php`).
