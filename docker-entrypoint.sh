#!/bin/sh
set -eu

cd /app

if [ "${APP_ENV:-}" != production ]; then
    echo 'The deployment image requires APP_ENV=production.' >&2
    exit 1
fi

case "${APP_DEBUG:-false}" in
    false|0) ;;
    *) echo 'The deployment image requires APP_DEBUG=false.' >&2; exit 1 ;;
esac

if [ -z "${APP_KEY:-}" ]; then
    echo 'APP_KEY is required. Generate it once and keep the same key across deployments.' >&2
    exit 1
fi

mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# Cache only after the deployment environment has been injected. Do not clear
# the shared application cache or run migrations on each process restart.
php artisan optimize --no-interaction

# A malformed key must fail startup instead of passing /up and failing sessions.
php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $app->make("encrypter");'

# The server / queue worker receives Docker's stop signal directly.
if [ "${1:-}" = php ] && [ "${2:-}" = artisan ] && [ "${3:-}" = octane:frankenphp ]; then
    for value in "${OCTANE_WORKERS:-1}" "${OCTANE_MAX_REQUESTS:-500}"; do
        case "$value" in
            ''|*[!0-9]*|0*) echo 'OCTANE_WORKERS and OCTANE_MAX_REQUESTS must be positive integers without leading zeros.' >&2; exit 1 ;;
        esac
    done

    octane_workers=${OCTANE_WORKERS:-1}
    export OCTANE_THREADS=$((octane_workers + 1))

    set -- "$@" \
        --host=0.0.0.0 \
        --port="${PORT:-8080}" \
        --admin-host=127.0.0.1 \
        --admin-port=2019 \
        --workers="${OCTANE_WORKERS:-1}" \
        --max-requests="${OCTANE_MAX_REQUESTS:-500}" \
        --caddyfile=/etc/frankenphp/Caddyfile \
        --log-level=info \
        --no-interaction
fi

exec "$@"
