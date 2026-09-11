# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1.12.7-php8.5-trixie AS php-base

RUN install-php-extensions pdo_mysql redis pcntl \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /app

FROM php-base AS dependencies

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./

RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

COPY . .

RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && composer check-platform-reqs --no-dev \
    && cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/frankenphp-worker.php

FROM node:24-bookworm-slim AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=dependencies /app /app
RUN npm run build

FROM php-base AS production

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning \
    PORT=8080 \
    OCTANE_SERVER=frankenphp \
    OCTANE_WORKERS=1 \
    OCTANE_MAX_REQUESTS=500 \
    GOMEMLIMIT=128MiB \
    XDG_CONFIG_HOME=/tmp/caddy/config \
    XDG_DATA_HOME=/tmp/caddy/data

COPY --from=dependencies /app /app
COPY --from=assets /app/public/build /app/public/build
COPY Caddyfile /etc/frankenphp/Caddyfile
COPY php-production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY --chmod=755 docker-entrypoint.sh /usr/local/bin/app-entrypoint

RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && ln -s /app/storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8080
STOPSIGNAL SIGTERM

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl --fail --silent --show-error --max-time 4 "http://127.0.0.1:${PORT}/up" > /dev/null || exit 1

ENTRYPOINT ["app-entrypoint"]
CMD ["php", "artisan", "octane:frankenphp"]
