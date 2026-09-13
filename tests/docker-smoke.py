#!/usr/bin/env python3
"""Exercise the production image with a disposable SQLite database."""

import base64
import http.cookiejar
import json
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


def docker(*args, check=True):
    return subprocess.run(
        ["docker", *args], capture_output=True, text=True, check=check, timeout=60
    )


class Client:
    def __init__(self, base):
        self.base = base
        self.cookies = http.cookiejar.CookieJar()
        self.http = urllib.request.build_opener(
            urllib.request.ProxyHandler({}),
            urllib.request.HTTPCookieProcessor(self.cookies),
        )

    def request(self, path, expected, payload=None, csrf=True, method=None, files=None):
        headers = {"Accept": "application/json", "Origin": "http://localhost:3000"}
        data = None
        if payload is not None:
            data = json.dumps(payload).encode()
            headers["Content-Type"] = "application/json"
        if files is not None:
            boundary = "image-smoke-" + secrets.token_hex(8)
            parts = []
            for field, filename, contents in files:
                parts.append((
                    f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; '
                    f'filename="{filename}"\r\nContent-Type: image/png\r\n\r\n'
                ).encode() + contents + b"\r\n")
            data = b"".join(parts) + f"--{boundary}--\r\n".encode()
            headers["Content-Type"] = "multipart/form-data; boundary=" + boundary
        if data is not None and csrf:
            token = next(c.value for c in self.cookies if c.name == "XSRF-TOKEN")
            headers["X-XSRF-TOKEN"] = urllib.parse.unquote(token)
        request = urllib.request.Request(self.base + path, data=data, headers=headers, method=method)
        try:
            response = self.http.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read()
        assert response.status == expected, (path, response.status, body[:500])
        if "application/json" in response.headers.get("Content-Type", ""):
            return json.loads(body)
        return body


image = sys.argv[1] if len(sys.argv) > 1 else "deal-news:production"
name = "deal-news-smoke-" + secrets.token_hex(4)
key = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode()
password = secrets.token_urlsafe(20) + "Aa1!"
environment = {
    "APP_KEY": key,
    "APP_URL": "http://localhost:10000",
    "PORT": "10000",
    "DB_CONNECTION": "sqlite",
    "DB_DATABASE": "/tmp/octane-smoke.sqlite",
    "DB_URL": "",
    "CACHE_STORE": "database",
    "SESSION_DRIVER": "database",
    "SESSION_SECURE_COOKIE": "false",
    "QUEUE_CONNECTION": "database",
    "OCTANE_HTTPS": "true",
    "OCTANE_MAX_REQUESTS": "5",
    "SANCTUM_STATEFUL_DOMAINS": "localhost:3000",
    "CORS_ALLOWED_ORIGINS": "http://localhost:3000",
    "ADMIN_EMAIL": "admin@example.com",
    "ADMIN_NAME": "Smoke Admin",
    "ADMIN_PASSWORD": password,
    "BCRYPT_ROUNDS": "4",
    "IMAGE_DISK": "public",
}
env_args = [item for k, v in environment.items() for item in ("-e", f"{k}={v}")]

try:
    docker(
        "run", "-d", "--name", name, "--memory=512m", "--cpus=0.5",
        "-p", "127.0.0.1::10000", *env_args, image,
    )
    port = docker("port", name, "10000/tcp").stdout.strip().rsplit(":", 1)[1]
    base = "http://127.0.0.1:" + port
    guest = Client(base)

    def wait_ready():
        for _ in range(60):
            try:
                guest.request("/up", 200)
                return
            except (urllib.error.URLError, TimeoutError, ConnectionError):
                time.sleep(0.5)
        raise AssertionError("Octane did not become ready")

    wait_ready()
    docker("exec", name, "php", "-r", 'touch("/tmp/octane-smoke.sqlite");')
    docker("exec", name, "php", "artisan", "migrate", "--force", "--no-interaction")
    docker("exec", name, "php", "artisan", "queue:work", "--once", "--no-interaction")
    state = json.loads(docker("exec", name, "cat", "storage/logs/octane-server-state.json").stdout)
    assert int(state["state"]["workers"]) == 1
    assert int(state["state"]["maxRequests"]) == 5
    admin_config = json.loads(docker(
        "exec", name, "curl", "-fsS", "http://127.0.0.1:2019/config/apps/frankenphp"
    ).stdout)
    assert admin_config["workers"][0]["file_name"] == "/app/public/frankenphp-worker.php"
    assert admin_config["num_threads"] == 2
    assert json.loads(docker(
        "exec", name, "curl", "-fsS", "http://127.0.0.1:2019/config/admin/listen"
    ).stdout) == "127.0.0.1:2019"
    print("PASS: Octane worker, runtime PORT, cached startup and migrations", flush=True)

    for path in ("/.env", "/composer.json", "/frankenphp-worker.php", "/other.php"):
        guest.request(path, 404)
    guest.request("/build/manifest.json", 200)
    guest.request("/api/me", 401)
    guest.request("/api/sign-up", 419, {}, csrf=False)

    user = Client(base)
    user.request("/sanctum/csrf-cookie", 204)
    result = user.request("/api/sign-up", 201, {
        "full_name": "Smoke User", "email": "user@example.com",
        "password": password, "password_confirmation": password,
    })
    assert result["data"]["user_role"] == "user"
    user.request("/api/admin/users", 403)
    admin = Client(base)
    admin.request("/sanctum/csrf-cookie", 204)
    admin.request("/api/sign-in", 200, {"email": "admin@example.com", "password": password})
    users = admin.request("/api/admin/users", 200)
    assert users["meta"]["total"] == 2
    assert users["links"]["first"].startswith("https://")
    for _ in range(4):
        assert user.request("/api/me", 200)["data"]["email"] == "user@example.com"
        guest.request("/api/me", 401)
        assert admin.request("/api/me", 200)["data"]["email"] == "admin@example.com"
    print("PASS: CSRF, admin authorization, session isolation across worker recycling", flush=True)

    png = base64.b64decode(docker(
        "exec", name, "php", "-r",
        '$image = imagecreatetruecolor(1200, 600); ob_start(); imagepng($image); '
        'echo base64_encode(ob_get_clean());',
    ).stdout)
    guest.request("/sanctum/csrf-cookie", 204)
    guest.request("/api/images", 401, files=[("image", "photo.png", png)])
    user.request("/api/images", 419, files=[("image", "photo.png", png)], csrf=False)
    uploaded = user.request("/api/images", 201, files=[("image", "photo.png", png)])["data"]
    assert uploaded["mime_type"] == "image/webp" and 0 < uploaded["size"] <= 20480
    assert (uploaded["width"], uploaded["height"]) == (1200, 600)
    stored = guest.request("/storage/" + uploaded["path"], 200)
    assert stored[:4] == b"RIFF" and stored[8:12] == b"WEBP" and len(stored) == uploaded["size"]
    image_query = "/api/images?" + urllib.parse.urlencode({"path": uploaded["path"]})
    user.request(image_query, 200)
    admin.request(image_query, 404)
    admin.request("/api/images", 404, {"path": uploaded["path"]}, method="DELETE")
    batch = user.request("/api/images/batch", 201, files=[
        ("images[]", "first.png", png), ("images[]", "second.png", png),
    ])["data"]
    assert len(batch) == 2 and batch[0]["path"] != batch[1]["path"]
    for item in [uploaded, *batch]:
        user.request("/api/images", 200, {"path": item["path"]}, method="DELETE")
        guest.request("/storage/" + item["path"], 404)
    user.request("/api/images", 200, {"path": uploaded["path"]}, method="DELETE")
    print("PASS: multipart uploads, GD WebP compression, CSRF, image ownership and deletion", flush=True)

    docker("exec", name, "php", "artisan", "octane:reload", "--no-interaction")
    assert user.request("/api/me", 200)["data"]["email"] == "user@example.com"
    docker("restart", "--time=45", name)
    port = docker("port", name, "10000/tcp").stdout.strip().rsplit(":", 1)[1]
    for client in (guest, user, admin):
        client.base = "http://127.0.0.1:" + port
    wait_ready()
    assert admin.request("/api/me", 200)["data"]["email"] == "admin@example.com"
    assert user.request("/api/sign-out", 200, {})["message"] == "Logged out successfully"
    user.request("/api/me", 401)
    admin.request("/api/me", 200)
    print("PASS: reload, restart, durable sessions and logout", flush=True)

    print(docker("stats", "--no-stream", "--format", "RAM: {{.MemUsage}}", name).stdout.strip(), flush=True)
    docker("stop", "--time=45", name)
    status = json.loads(docker("inspect", "--format", "{{json .State}}", name).stdout)
    assert status["ExitCode"] == 0 and not status["OOMKilled"], status
    print("PASS: SIGTERM exits cleanly without OOM", flush=True)
except Exception:
    print(docker("logs", "--tail=40", name, check=False).stderr, file=sys.stderr)
    raise
finally:
    docker("rm", "-f", name, check=False)
