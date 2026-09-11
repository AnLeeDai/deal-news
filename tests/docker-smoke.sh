#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
image="${1:-deal-news:production}"
prefix="deal-news-smoke-$$"
web="$prefix-web"
worker="$prefix-worker"
mysql="$prefix-mysql"
temp_dir="$(mktemp -d)"

cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        docker logs --tail 50 "$web" 2>&1 || true
        docker logs --tail 30 "$worker" 2>&1 || true
    fi
    docker rm -fv "$web" "$worker" "$mysql" > /dev/null 2>&1 || true
    docker network rm "$prefix" > /dev/null 2>&1 || true
    rm -rf "$temp_dir"
    exit "$status"
}
trap cleanup EXIT

docker image inspect "$image" > /dev/null
docker network create "$prefix" > /dev/null
docker run -d --name "$mysql" --network "$prefix" \
    -e MYSQL_DATABASE=smoke -e MYSQL_USER=smoke -e MYSQL_PASSWORD=smoke \
    -e MYSQL_ROOT_PASSWORD=smoke-root mysql:8.4 > /dev/null

cat > "$temp_dir/app.env" <<EOF
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=https://news.example.com
TRUSTED_PROXIES=*
PORT=10000
DB_CONNECTION=mysql
DB_HOST=$mysql
DB_PORT=3306
DB_DATABASE=smoke
DB_USERNAME=smoke
DB_PASSWORD=smoke
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
EOF

ready=false
for attempt in {1..90}; do
    if docker exec -e MYSQL_PWD=smoke "$mysql" mysql --protocol=TCP -h 127.0.0.1 -usmoke smoke -e 'SELECT 1' > /dev/null 2>&1; then
        ready=true
        break
    fi
    sleep 1
done
[ "$ready" = true ] || { echo 'MySQL did not become ready.' >&2; exit 1; }

echo 'Running the release migration against an isolated MySQL database...'
docker run --rm --network "$prefix" --env-file "$temp_dir/app.env" \
    "$image" php artisan migrate --force --no-interaction

docker run -d --name "$web" --network "$prefix" --env-file "$temp_dir/app.env" \
    --memory=512m --cpus=0.5 --restart=unless-stopped \
    -p 127.0.0.1::10000 "$image" > /dev/null
wait_for_web() {
    for attempt in {1..60}; do
        # Docker can assign a new ephemeral host port when a container restarts.
        base_url="http://$(docker port "$web" 10000/tcp)"
        if curl -fsS --max-time 3 "$base_url/up" > /dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    return 1
}
wait_for_web

echo 'Checking rendered HTML, built assets, protected paths and runtime permissions...'
curl -fsS --max-time 10 -H 'X-Forwarded-Proto: https' -H 'Host: news.example.com' "$base_url/" > "$temp_dir/page.html"
asset="$(docker exec "$web" php -r '$m = json_decode(file_get_contents("public/build/manifest.json"), true); echo $m["resources/css/app.css"]["file"];')"
curl -fsS --max-time 10 "$base_url/build/$asset" > /dev/null
grep -q "https://news.example.com/build/$asset" "$temp_dir/page.html"
for path in /.env /composer.json /vendor/autoload.php /storage/blocked.php; do
    [ "$(curl -sS --max-time 5 -o /dev/null -w '%{http_code}' "$base_url$path")" = 404 ]
done
docker exec "$web" sh -c 'test "$(id -u)" != 0 && test -w storage && test -w bootstrap/cache && test ! -e .env && test ! -e public/hot && test ! -d vendor/laravel/boost && test ! -e database/database.sqlite'
docker exec "$web" php -r '$c = require "bootstrap/cache/config.php"; exit($c["app"]["debug"] === false && $c["database"]["connections"]["mysql"]["host"] === getenv("DB_HOST") ? 0 : 1);'

echo 'Checking database-backed queue execution and graceful worker reload...'
docker run -d --name "$worker" --network "$prefix" --env-file "$temp_dir/app.env" \
    --memory=512m --restart=unless-stopped --no-healthcheck "$image" \
    php artisan queue:work --sleep=1 --tries=3 --backoff=5 --timeout=60 \
    --memory=128 --max-jobs=1000 --max-time=3600 --no-interaction > /dev/null
docker cp tests/Fixtures/DockerSmokeJob.php "$web:/app/app/DockerSmokeJob.php"
docker cp tests/Fixtures/DockerSmokeJob.php "$worker:/app/app/DockerSmokeJob.php"
bootstrap='require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
docker exec "$web" php -r "$bootstrap App\DockerSmokeJob::dispatch();"
completed=false
for attempt in {1..30}; do
    if docker exec "$web" php -r "$bootstrap exit(Illuminate\Support\Facades\Cache::get('docker-smoke-job-completed') ? 0 : 1);"; then
        completed=true
        break
    fi
    sleep 1
done
[ "$completed" = true ]

# Laravel's reload signal must be observed after the worker's startup timestamp.
sleep 2
docker exec "$web" php artisan reload --no-interaction
for attempt in {1..30}; do
    [ "$(docker inspect -f '{{.RestartCount}}' "$worker")" -gt 0 ] && break
    sleep 1
done
[ "$(docker inspect -f '{{.RestartCount}}' "$worker")" -gt 0 ]

echo 'Checking recovery after the web process exits...'
docker exec "$web" sh -c 'kill -TERM 1'
for attempt in {1..30}; do
    [ "$(docker inspect -f '{{.RestartCount}}' "$web")" -gt 0 ] && break
    sleep 1
done
[ "$(docker inspect -f '{{.RestartCount}}' "$web")" -gt 0 ]
wait_for_web

echo 'Checking concurrent HTTP requests within 0.5 CPU / 512 MB...'
export base_url
seq 1 120 | xargs -P 8 -I '{}' sh -c 'curl -fsS --max-time 15 "$base_url/" > /dev/null'
docker stats --no-stream --format '{{.Name}}: {{.MemUsage}}' "$web" "$worker"
[ "$(docker inspect -f '{{.State.OOMKilled}}' "$web")" = false ]

echo 'Checking startup refuses missing or malformed keys and debug mode...'
for setting in APP_KEY= APP_KEY=invalid APP_DEBUG=true; do
    if docker run --rm --network "$prefix" --env-file "$temp_dir/app.env" \
        -e "$setting" "$image" php artisan about > "$temp_dir/rejected.log" 2>&1; then
        echo "Startup incorrectly accepted $setting" >&2
        exit 1
    fi
done

echo 'Docker smoke checks passed.'
