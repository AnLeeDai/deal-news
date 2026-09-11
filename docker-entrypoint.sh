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
exec "$@"
