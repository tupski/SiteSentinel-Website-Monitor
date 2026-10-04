FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libxml2-dev \
    zip \
    unzip \
    oniguruma-dev \
    icu-dev \
    linux-headers

# Install PHP extensions required by Laravel, MySQL, Redis
RUN docker-php-ext-install pdo pdo_mysql mbstring bcmath pcntl intl \
    && pecl install redis \
    && docker-php-ext-enable redis

# Production php-fpm pool override: unprivileged www-data pool, /ping health
# endpoint, graceful process_control_timeout, expose_php off.
COPY docker/app/zz-prod.conf /usr/local/etc/php-fpm.d/zz-prod.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Container entrypoint: prepares storage permissions and materialises a .env from
# the runtime environment (on a fresh app-storage volume) before exec-ing the
# process. It NEVER runs migrations — migrations are a separate one-shot service.
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# Pre-create the writable storage layout owned by the runtime user, so a fresh
# named volume (app-storage) inherits the correct ownership on first mount and
# the non-root www-data process can write to it (SECURITY.md §11).
RUN mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

# Non-root default (SECURITY.md §11): the worker pool runs as www-data (see
# docker/app/zz-prod.conf). The fpm master must start as root to bind port 9000
# and drop privileges; in production the container additionally runs with
# `read_only: true`, `cap_drop: [ALL]`, `no-new-privileges` and tmpfs for
# mutable non-volume paths (docker-compose.prod.yml). The CI image-hardening
# follow-up can switch to a fully rootless pool once FPM supports it.
USER www-data

CMD ["php-fpm", "-F"]
