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
   - `APP_URL`: URL HTTPS thực tế của web service hoặc custom domain.
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
- FrankenPHP phục vụ `/app/public`, nghe mọi interface trên `PORT` của Render.
  TLS được Render xử lý. `TRUSTED_PROXIES=*` chỉ dùng sau Render edge; local
  mặc định không tin forwarded headers. Chỉ scheme và client IP được tin cậy,
  không tin `X-Forwarded-Host`/`X-Forwarded-Port`.
- Container chạy bằng `www-data`; `storage` và `bootstrap/cache` có quyền ghi.
  Entrypoint yêu cầu key hợp lệ, production, debug tắt, rồi chạy `artisan optimize`
  với environment runtime. Không migrate hoặc xóa application cache mỗi lần restart.
- Web giới hạn 2 PHP threads, PHP memory 128 MB/request, Go soft memory target
  128 MiB. Đây không phải giới hạn tổng RAM: Render vẫn áp trần 512 MB cho toàn bộ
  service. Theo dõi Metrics và tăng tài nguyên khi workload thực tế cần thêm.
- Health check `/up` kiểm tra Laravel boot. Nó không xác nhận database, object
  storage hay mọi chức năng nghiệp vụ đều hoạt động. Render loại instance lỗi
  khỏi routing sau khoảng 15 giây lỗi liên tiếp và restart sau khoảng 60 giây.
  Docker `HEALTHCHECK` local chỉ đánh dấu trạng thái; restart policy riêng mới
  khởi động lại container khi tiến trình thoát.
- Web nhận `SIGTERM` trực tiếp và có 30 giây drain, Render chờ tối đa 60 giây.
  Worker có timeout 60 giây, `retry_after=90` và thời gian shutdown 90 giây.
  Job dài hơn phải điều chỉnh đồng bộ các giá trị này. Job có side effect cần
  idempotent vì queue vẫn có thể giao lại job sau sự cố.
- Khi release, Render thay container web/worker để nạp code và cache mới.
  `php artisan reload` có thể yêu cầu worker hiện tại thoát sau job đang chạy,
  nhưng không thay thế việc deploy image mới. Log được ghi ra stdout/stderr.

Không cấu hình nào bảo đảm uptime 100%. Kiểm tra Events, runtime logs, RAM, DB
và HTTP sau deploy thực tế; bật thông báo lỗi của Render. Blueprint không tạo
scheduler vì `routes/console.php` hiện chưa có scheduled task.

## Kiểm tra trước deploy

```sh
php artisan test --compact
docker build -t deal-news:production .
bash tests/docker-smoke.sh deal-news:production
```

Smoke test dùng container và database tạm riêng, không dùng `.env` local.
`docker-compose.yml` hiện có tiếp tục dùng cho MySQL/Redis/MinIO khi phát triển.

Tài liệu đối chiếu: [Laravel deployment](https://laravel.com/framework/docs/deployment),
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
