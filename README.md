# Deal News — Docker deployment on Render

`render.yaml` defines separate web and queue worker services, each using the paid
`0.5c-512mb` plan in Singapore. The Blueprint creates **two billable services**;
MySQL and object storage are provisioned separately. Render Free sleeps after
15 minutes of inactivity, so it does not meet the requirement for continuous operation.

## Initial setup

1. Push the project to a Git repository, open Render → **New → Blueprint**, select
   the repository, and use `render.yaml`. If the application is in a subdirectory,
   update `rootDir` in the Blueprint for both services.
2. Set the required variables:
   - `APP_KEY`: run `php artisan key:generate --show`, keep the key unchanged across
     deployments, and use the same key for the web and worker services. Do not use
     Render's `generateValue` directly in place of a Laravel key prefixed with `base64:`.
   - `APP_URL`: the backend's custom HTTPS domain, such as `https://api.example.com`.
   - `CORS_ALLOWED_ORIGINS`: the frontend origin, such as `https://app.example.com`.
   - `SANCTUM_STATEFUL_DOMAINS`: `app.example.com,api.example.com` (without a scheme).
   - `SESSION_DOMAIN`: `.example.com` to share cookies between the frontend and backend.
   - `DB_URL`: `mysql://USER:PASSWORD@HOST:3306/DATABASE`; URL-encode special characters
     in the credentials. Use a production MySQL server accessible from Render;
     `localhost` does not refer to a database outside the container. Allow Render's
     outbound IP addresses through the database provider's firewall if required.
3. Sessions, cache, and queues use MySQL by default to survive restarts.
   Migrations run with `php artisan migrate --force --no-interaction` in the
   **web service's Pre-Deploy Command**. Only the web service runs migrations.
   Keep schema changes compatible with the version still serving requests, and
   deploy the web service successfully before deploying a worker that requires the new schema.
4. Add object storage credentials to the `deal-news-production` environment group
   before using file storage: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
   `AWS_DEFAULT_REGION`, and `AWS_BUCKET`. Add `AWS_ENDPOINT`, `AWS_URL`, and
   `AWS_USE_PATH_STYLE_ENDPOINT` if required by the provider. `FILESYSTEM_DISK=s3`;
   uploaded files are not stored durably on Render's ephemeral filesystem.
   `.env.production.example` lists the required settings. Do not include the local
   `.env` in the image or use local MinIO credentials in production.
5. If MySQL requires a custom CA, add a Render secret file and set
   `MYSQL_ATTR_SSL_CA=/etc/secrets/mysql-ca.pem` on both services. Configure a real
   mail provider when email delivery is needed; the current default is `MAIL_MAILER=log`.

When creating services manually, select the **Docker** runtime, use `./Dockerfile`,
leave the web service's Docker Command empty, set Health Check Path to `/up`, and
use the Pre-Deploy Command above. The worker uses the same image and environment
variables with this Docker Command:

```sh
php artisan queue:work --sleep=3 --tries=3 --backoff=5 --timeout=60 --memory=128 --max-jobs=1000 --max-time=3600 --no-interaction
```

## Runtime and recovery

- The Docker build installs Composer dependencies with `--no-dev`, optimizes
  autoloading, and builds Vite assets. The image excludes `.env`, local databases,
  development server markers, and local caches.
- Laravel Octane runs with `php artisan octane:frankenphp`, serves `/app/public`,
  and listens on all interfaces using Render's `PORT`. The worker file is copied
  from the Octane package during the build; startup does not download binaries or
  write to `public`. Render handles TLS. Use `TRUSTED_PROXIES=*` only behind Render's
  edge; local development does not trust forwarded headers by default. Only the
  scheme and client IP are trusted, not `X-Forwarded-Host` or `X-Forwarded-Port`.
- The container runs as `www-data`; `storage` and `bootstrap/cache` are writable.
  The entrypoint requires a valid key, the production environment, and debug mode
  disabled, then runs `artisan optimize` with the runtime environment. It does not
  run migrations or clear the application cache on every restart.
- `OCTANE_SERVER=frankenphp`, `OCTANE_WORKERS=1`, `OCTANE_MAX_REQUESTS=500`.
  Workers recycle after 500 requests. The total PHP thread count is the worker
  count plus one, defaulting to two to reserve one thread for PHP outside the worker.
  `OCTANE_HTTPS=true` generates HTTPS URLs behind Render's edge without enabling TLS
  inside the container. Caddy's admin endpoint listens only on `127.0.0.1:2019` for
  `octane:status`, `octane:reload`, and shutdown. PHP memory is limited to 128 MB per
  thread, and Go has a soft memory target of 128 MiB. These are not total RAM limits:
  Render still enforces a 512 MB limit for the entire service. Monitor Metrics and
  increase resources when the workload requires more capacity.
- The `/up` health check verifies that Laravel boots. It does not confirm that the
  database, object storage, or every application feature works. Render removes a
  failing instance from routing after approximately 15 seconds of consecutive
  failures and restarts it after approximately 60 seconds. A local Docker
  `HEALTHCHECK` only records health status; a separate restart policy restarts the
  container when its process exits.
- Octane receives `SIGTERM` directly and asks FrankenPHP to stop through the internal
  admin endpoint. Caddy has 30 seconds to drain requests; Render waits up to 60 seconds.
  The queue worker has a 60-second timeout, `retry_after=90`, and a 90-second shutdown
  period. Adjust these values together for longer jobs. Jobs with side effects must
  be idempotent because the queue can deliver a job again after a failure.
- During a release, Render replaces the web and worker containers to load new code
  and caches. Render monitors services and restarts them when their process exits.
  Within an existing container, `php artisan octane:reload` reloads workers; changing
  code or environment variables on Render requires deploying a new image. Logs are
  written to stdout and stderr.

No configuration guarantees 100% uptime. Check Events, runtime logs, RAM, the database,
and HTTP responses after an actual deployment, and enable Render failure notifications.
The Blueprint does not create a scheduler because `routes/console.php` currently has
no scheduled tasks.

## Checks before deployment

```sh
php artisan test --compact
docker build -t deal-news:production .
python3 tests/docker-smoke.py deal-news:production
```

The smoke test runs a separate container limited to 512 MB and 0.5 CPU with a temporary
SQLite database. It checks CSRF, session isolation, administrator authorization,
worker recycling, reloads, restarts, and SIGTERM. It does not use real databases or accounts.

Continue using the existing `docker-compose.yml` for MySQL, Redis, and MinIO during development.

## SPA authentication API for Next.js

The backend uses [Sanctum SPA Authentication](https://laravel.com/framework/docs/13.x/sanctum#spa-authentication):
a session cookie authenticates the user, while `XSRF-TOKEN` protects against CSRF and
is not an access token. The API does not return access tokens. The frontend does not
store tokens in localStorage or send an `Authorization: Bearer` header.

In production, configure custom domains sharing the same root domain for Vercel and
Render, such as `app.example.com` and `api.example.com`. The default `*.vercel.app`
and `*.onrender.com` domains do not meet this requirement. Replace the example domains
in `.env.production.example` with the real domains. Vercel previews on another domain
also need suitable domain configuration before using session authentication.

Use `localhost` consistently during local development: `http://localhost:3000` for
the frontend and `http://localhost:8000` for the backend. Do not mix `127.0.0.1` and `localhost`.

```dotenv
CORS_ALLOWED_ORIGINS=http://localhost:3000
SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:8000
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
```

Production sessions are stored in the database, with `HttpOnly`, `Secure`, and
`SameSite=Lax` cookies. Keep `APP_KEY` unchanged across deployments. After changing
environment variables, redeploy so the container rebuilds the configuration cache.

| Endpoint | Result |
| --- | --- |
| `GET /sanctum/csrf-cookie` | 204; initializes CSRF and session cookies |
| `POST /api/sign-up` | 201; creates an account and signs in; `message`: `Registered successfully` |
| `POST /api/sign-in` | 200; signs in and regenerates the session; `message`: `Logged in successfully` |
| `GET /api/me` | 200 with the current user's details; 401 when unauthenticated |
| `POST /api/sign-out` | 200; returns `Logged out successfully` and the user's code and role in `data`; invalidates the session |

Registration accepts `full_name`, `email`, `password`, and `password_confirmation`.
Passwords must contain at least eight characters, including uppercase and lowercase
letters, a number, and a special character. The backend assigns the UUID `id`,
`user_code`, and `role=user`; do not send these fields from the frontend.
Login accepts `email` and `password`. Successful registration, login, and logout
responses contain `data.user_code`, `data.user_role`, and a top-level `message`.
`GET /api/me` returns the user's details in the `data` object.

Axios example running **in the browser** in Next.js (install Axios when creating the frontend):

```js
import axios from 'axios';

const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL, // http://localhost:8000 or https://api.example.com
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
});

await api.get('/sanctum/csrf-cookie');
await api.post('/api/sign-in', { email, password });
// Or: await api.post('/api/sign-up', { full_name, email, password, password_confirmation });
const { data: { data: user } } = await api.get('/api/me');
await api.post('/api/sign-out');
```

Axios reads the `XSRF-TOKEN` cookie and sends the `X-XSRF-TOKEN` header; the browser
sends the session cookie automatically. With `fetch`, set `credentials: 'include'`
and send the URL-decoded `XSRF-TOKEN` cookie value in `X-XSRF-TOKEN` for POST, PUT,
PATCH, and DELETE requests. Requests from the Next.js server must forward the
appropriate cookies and headers; the Axios configuration above applies only to the browser.

API errors return JSON: 422 for invalid input or credentials, 401 when unauthenticated,
419 for invalid CSRF tokens, and 429 when rate limits are exceeded. Handle 401 and 419
by signing in again and obtaining a new CSRF cookie; do not retry indefinitely.
Login is limited to five requests per minute per email and IP combination, plus
30 requests per minute per IP. Registration is limited to five requests per minute
per IP. The former `GET /api/verify-user` endpoint has been replaced by `GET /api/me`;
user codes are assigned only after successful registration.

References: [Laravel deployment](https://laravel.com/framework/docs/13.x/deployment),
[Laravel Octane](https://laravel.com/framework/docs/13.x/octane#serving-your-application),
[FrankenPHP Docker](https://frankenphp.dev/docs/docker/),
[Render Docker](https://render.com/docs/docker),
[Render health checks](https://render.com/docs/health-checks),
[Render pre-deploy](https://render.com/docs/deploys#pre-deploy-command),
[Render Blueprint](https://render.com/docs/blueprint-spec),
[Render Free](https://render.com/docs/free).

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
