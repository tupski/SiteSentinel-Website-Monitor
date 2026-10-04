#!/bin/sh
# SiteSentinel app entrypoint.
#
# Runs for php-fpm, scheduler and worker containers. Idempotent and safe to run
# concurrently. It does exactly three things:
#   1. Ensure storage/ and bootstrap/cache are writable by the runtime user.
#   2. Materialise a .env from the injected environment when none exists (fresh
#      app-storage volume), so php-fpm/queue workers resolve DB + Redis config.
#   3. exec the requested command.
#
# It NEVER runs `migrate` (separate one-shot `migrate` service) and NEVER prints
# secrets.

set -eu

APP_DIR="${APP_DIR:-/var/www/html}"

# 1. Writable framework directories (may be an empty named volume on first boot).
mkdir -p \
    "$APP_DIR/storage/app/public" \
    "$APP_DIR/storage/framework/cache/data" \
    "$APP_DIR/storage/framework/sessions" \
    "$APP_DIR/storage/framework/views" \
    "$APP_DIR/storage/logs" \
    "$APP_DIR/bootstrap/cache"
chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true

# 2. Materialise a minimal .env if none is present. Only variables already
#    present in the process environment are forwarded; nothing is defaulted to a
#    real secret here. Keep this list in sync with the prod environment.
if [ ! -f "$APP_DIR/.env" ]; then
    umask 077
    : > "$APP_DIR/.env"
    for var in \
        APP_NAME APP_ENV APP_KEY APP_DEBUG APP_URL APP_TIMEZONE \
        TRUSTED_PROXIES \
        DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_ROOT_PASSWORD \
        SESSION_DRIVER SESSION_LIFETIME SESSION_SECURE_COOKIE SESSION_HTTP_ONLY SESSION_SAME_SITE SESSION_DOMAIN \
        QUEUE_CONNECTION CACHE_STORE REDIS_CLIENT REDIS_HOST REDIS_PASSWORD REDIS_PORT \
        MAIL_MAILER MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_FROM_ADDRESS MAIL_FROM_NAME \
        TELEGRAM_BOT_TOKEN TELEGRAM_DEFAULT_CHAT_ID \
        SENTINEL_DEFAULT_INTERVAL_SECONDS SENTINEL_DEFAULT_TIMEOUT_SECONDS
    do
        eval "val=\${$var-}"
        [ -n "${val:-}" ] && printf '%s=%s\n' "$var" "$val" >> "$APP_DIR/.env" || true
    done
    echo "[entrypoint] materialised .env from environment"
fi

# 3. Hand off to the requested process.
exec "$@"
