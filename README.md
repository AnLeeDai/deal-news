# Deal News — deploy Docker trên Render

`render.yaml` khai báo web và queue worker riêng, mỗi service dùng gói trả phí
`0.5c-512mb` tại Singapore. Blueprint tạo **hai service có tính phí**; MySQL và
object storage được cung cấp riêng. Render Free ngủ sau 15 phút không có truy cập,
nên không phù hợp với yêu cầu chạy liên tục.

## Cấu hình lần đầu

1. Đưa dự án lên Git repository, mở Render → **New → Blueprint**, chọn repository
   và dùng `render.yaml`. Nếu ứng dụng nằm trong thư mục con của repository, sửa
   `rootDir` trong Blueprint cho cả hai service.
2. Điền các biến được yêu cầu:
   - `APP_KEY`: chạy `php artisan key:generate --show`, giữ nguyên key qua các lần
     deploy và dùng cùng key cho web/worker. Không dùng `generateValue` của Render
     trực tiếp thay cho Laravel key có tiền tố `base64:`.
   - `APP_URL`: custom domain HTTPS của backend, ví dụ `https://api.example.com`.
   - `CORS_ALLOWED_ORIGINS`: origin frontend, ví dụ `https://app.example.com`.
   - `SANCTUM_STATEFUL_DOMAINS`: `app.example.com,api.example.com` (không có scheme).
   - `SESSION_DOMAIN`: `.example.com` để cookie dùng chung giữa frontend/backend.
   - `DB_URL`: `mysql://USER:PASSWORD@HOST:3306/DATABASE`, URL-encode ký tự đặc biệt
     trong tài khoản. Dùng MySQL production truy cập được từ Render; `localhost`
     không phải database bên ngoài container. Cho phép outbound IP của Render
     trong firewall của nhà cung cấp database nếu họ yêu cầu.
3. Session, cache và queue mặc định lưu trong MySQL để tồn tại sau restart.
   Migration chạy bằng `php artisan migrate --force --no-interaction` trong
   **Pre-Deploy Command của web**. Chỉ web sở hữu bước migration. Khi triển khai
   thay đổi schema, giữ tương thích với phiên bản cũ đang phục vụ và deploy web
   thành công trước khi deploy worker phụ thuộc schema mới.
4. Thêm thông tin object storage vào environment group `deal-news-production`
   trước khi dùng chức năng lưu file: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
   `AWS_DEFAULT_REGION`, `AWS_BUCKET`; thêm `AWS_ENDPOINT`, `AWS_URL` và
   `AWS_USE_PATH_STYLE_ENDPOINT` nếu nhà cung cấp yêu cầu. `FILESYSTEM_DISK=s3`;
   file upload không được lưu bền vững trên filesystem tạm của Render.
   `.env.production.example` liệt kê các giá trị cần cấu hình. Không upload `.env`
   local vào image hoặc dùng tài khoản MinIO local cho production.
5. Nếu MySQL yêu cầu CA riêng, thêm Render secret file và đặt
   `MYSQL_ATTR_SSL_CA=/etc/secrets/mysql-ca.pem` trên cả hai service. Đặt mail
   provider thực tế khi cần gửi email; mặc định hiện tại là `MAIL_MAILER=log`.

Nếu tạo service thủ công: chọn runtime **Docker**, Dockerfile `./Dockerfile`,
để trống Docker Command của web, Health Check Path `/up`, và Pre-Deploy Command
như trên. Worker dùng cùng image và các biến môi trường, Docker Command:

```sh
php artisan queue:work --sleep=3 --tries=3 --backoff=5 --timeout=60 --memory=128 --max-jobs=1000 --max-time=3600 --no-interaction
```

## Runtime và phục hồi

- Docker build cài Composer dependencies bằng `--no-dev`, tối ưu autoload và
  build Vite assets. Không đưa `.env`, database local, dev server marker hay
  cache local vào image.
- Laravel Octane chạy bằng `php artisan octane:frankenphp`, phục vụ `/app/public`
  và nghe mọi interface trên `PORT` của Render. File worker được lấy từ package
  Octane khi build; không tải binary hoặc ghi vào `public` lúc khởi động.
  TLS được Render xử lý. `TRUSTED_PROXIES=*` chỉ dùng sau Render edge; local
  mặc định không tin forwarded headers. Chỉ scheme và client IP được tin cậy,
  không tin `X-Forwarded-Host`/`X-Forwarded-Port`.
- Container chạy bằng `www-data`; `storage` và `bootstrap/cache` có quyền ghi.
  Entrypoint yêu cầu key hợp lệ, production, debug tắt, rồi chạy `artisan optimize`
  với environment runtime. Không migrate hoặc xóa application cache mỗi lần restart.
- `OCTANE_SERVER=frankenphp`, `OCTANE_WORKERS=1`, `OCTANE_MAX_REQUESTS=500`.
  Worker tự tái khởi tạo sau 500 request. Tổng PHP threads bằng số worker cộng 1;
  mặc định là 2, để dành 1 thread cho xử lý PHP ngoài worker. `OCTANE_HTTPS=true`
  giúp URL được sinh ra dùng HTTPS sau Render edge; không bật TLS trong container.
  Caddy admin chỉ nghe `127.0.0.1:2019` cho `octane:status`, `octane:reload` và stop.
  PHP memory 128 MB/thread, Go soft memory target
  128 MiB. Đây không phải giới hạn tổng RAM: Render vẫn áp trần 512 MB cho toàn bộ
  service. Theo dõi Metrics và tăng tài nguyên khi workload thực tế cần thêm.
- Health check `/up` kiểm tra Laravel boot. Nó không xác nhận database, object
  storage hay mọi chức năng nghiệp vụ đều hoạt động. Render loại instance lỗi
  khỏi routing sau khoảng 15 giây lỗi liên tiếp và restart sau khoảng 60 giây.
  Docker `HEALTHCHECK` local chỉ đánh dấu trạng thái; restart policy riêng mới
  khởi động lại container khi tiến trình thoát.
- Tiến trình Octane nhận `SIGTERM` trực tiếp và yêu cầu FrankenPHP dừng qua
  cổng quản trị nội bộ. Caddy có 30 giây drain, Render chờ tối đa 60 giây.
  Worker có timeout 60 giây, `retry_after=90` và thời gian shutdown 90 giây.
  Job dài hơn phải điều chỉnh đồng bộ các giá trị này. Job có side effect cần
  idempotent vì queue vẫn có thể giao lại job sau sự cố.
- Khi release, Render thay container web/worker để nạp code và cache mới.
  Render giám sát và khởi động lại service khi tiến trình thoát. Trong container
  hiện tại, `php artisan octane:reload` nạp lại worker; thay code hoặc environment
  trên Render cần deploy image mới. Log được ghi ra stdout/stderr.

Không cấu hình nào bảo đảm uptime 100%. Kiểm tra Events, runtime logs, RAM, DB
và HTTP sau deploy thực tế; bật thông báo lỗi của Render. Blueprint không tạo
scheduler vì `routes/console.php` hiện chưa có scheduled task.

## Kiểm tra trước deploy

```sh
php artisan test --compact
docker build -t deal-news:production .
python3 tests/docker-smoke.py deal-news:production
```

Smoke test chạy container riêng với giới hạn 512 MB / 0.5 CPU và SQLite tạm,
kiểm tra CSRF, cách ly phiên đăng nhập, phân quyền admin, recycle, reload,
restart và SIGTERM. Không dùng database hoặc tài khoản thật.

`docker-compose.yml` hiện có tiếp tục dùng cho MySQL/Redis/MinIO khi phát triển.

## API xác thực SPA cho Next.js

Backend dùng [Sanctum SPA Authentication](https://laravel.com/framework/docs/13.x/sanctum#spa-authentication):
cookie session xác thực người dùng; `XSRF-TOKEN` chống CSRF, không phải access token.
API không trả access token. Frontend không lưu token trong localStorage và không
gửi `Authorization: Bearer`.

Ở production, gắn custom domain cùng domain gốc cho Vercel và Render, ví dụ
`app.example.com` và `api.example.com`. Hai domain mặc định `*.vercel.app` và
`*.onrender.com` không đáp ứng điều kiện này. Thay các domain ví dụ trong
`.env.production.example` bằng domain thực tế. Preview Vercel có domain khác
cũng cần cấu hình domain phù hợp trước khi dùng cookie đăng nhập.

Local dùng nhất quán `localhost`: frontend `http://localhost:3000`, backend
`http://localhost:8000`; không trộn `127.0.0.1` với `localhost`.

```dotenv
CORS_ALLOWED_ORIGINS=http://localhost:3000
SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:8000
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
```

Session production lưu trong database, cookie `HttpOnly`, `Secure`, `SameSite=Lax`.
Giữ `APP_KEY` cố định qua các lần deploy. Sau khi đổi biến môi trường, deploy lại
để container tạo lại config cache.

| Endpoint | Kết quả |
| --- | --- |
| `GET /sanctum/csrf-cookie` | 204, khởi tạo cookie CSRF và session |
| `POST /api/sign-up` | 201, tạo tài khoản và đăng nhập; `message`: `Đăng ký thành công` |
| `POST /api/sign-in` | 200, đăng nhập và đổi session; `message`: `Đăng nhập thành công` |
| `GET /api/me` | 200, thông tin người dùng hiện tại; 401 nếu chưa đăng nhập |
| `POST /api/sign-out` | 200, thông báo `Đăng xuất thành công` và `user_code` của user vừa đăng xuất; vô hiệu hóa session |

Đăng ký nhận `full_name`, `email`, `password`, `password_confirmation`. Mật khẩu
tối thiểu 8 ký tự, có chữ hoa, chữ thường, số và ký tự đặc biệt. `id` UUID,
`user_code` và `role=user` do backend cấp; không gửi các trường này từ frontend.
Đăng nhập nhận `email`, `password`. Kết quả thành công đăng ký, đăng nhập và
đăng xuất chỉ có hai trường `user_code` và `message` ở cấp ngoài cùng.
`GET /api/me` trả thông tin người dùng trong object `data`.

Ví dụ Axios chạy **trong trình duyệt** của Next.js (cài Axios khi tạo dự án FE):

```js
import axios from 'axios';

const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL, // http://localhost:8000 hoặc https://api.example.com
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
});

await api.get('/sanctum/csrf-cookie');
await api.post('/api/sign-in', { email, password });
// Hoặc: await api.post('/api/sign-up', { full_name, email, password, password_confirmation });
const { data: { data: user } } = await api.get('/api/me');
await api.post('/api/sign-out');
```

Axios đọc cookie `XSRF-TOKEN` và gửi header `X-XSRF-TOKEN`; trình duyệt tự gửi
cookie session. Nếu dùng `fetch`, đặt `credentials: 'include'` và tự gửi giá trị
cookie `XSRF-TOKEN` đã URL-decode vào `X-XSRF-TOKEN` cho POST/PUT/PATCH/DELETE.
Nếu gọi từ Next.js server, cần chuyển tiếp cookie/header phù hợp; cấu hình Axios
ở trên chỉ áp dụng cho trình duyệt.

Lỗi API trả JSON: 422 khi dữ liệu hoặc thông tin đăng nhập không hợp lệ, 401 khi
chưa đăng nhập, 419 khi CSRF không hợp lệ, 429 khi vượt giới hạn. Frontend xử lý
401/419 bằng luồng đăng nhập lại và lấy CSRF cookie mới; không tự lặp vô hạn.
Đăng nhập giới hạn 5 request/phút/email + IP và 30 request/phút/IP; đăng ký giới
hạn 5 request/phút/IP. Endpoint cũ `GET /api/verify-user` được thay bằng
`GET /api/me`; mã người dùng chỉ được cấp khi đăng ký thành công.

Tài liệu đối chiếu: [Laravel deployment](https://laravel.com/framework/docs/13.x/deployment),
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
