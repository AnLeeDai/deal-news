<?php

use App\Models\User;
use App\RoleEnum;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToWriteFile;

uses(TestCase::class, RefreshDatabase::class);

function imageUploadUser(RoleEnum $role = RoleEnum::USER): User
{
    return User::registerUser([
        'full_name' => 'Image User',
        'email' => Str::uuid().'@example.com',
        'password' => 'TestPassword123!',
    ], $role);
}

function imageUploadContents(GdImage $image, string $format = 'png'): string
{
    ob_start();

    try {
        match ($format) {
            'jpeg' => imagejpeg($image, null, 95),
            'webp' => imagewebp($image, null, 90),
            default => imagepng($image),
        };

        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

test('authenticated roles upload images as WebP with server generated paths and metadata', function (RoleEnum $role, string $format) {
    $disk = Storage::fake('s3');
    $user = imageUploadUser($role);

    $response = $this->actingAs($user, 'web')->postJson('/api/images', [
        'image' => UploadedFile::fake()->image('photo.'.$format, 2400, 1200),
        'path' => 'attacker/override.webp',
        'disk' => 'local',
    ]);

    $response->assertCreated()->assertExactJsonStructure([
        'data' => ['path', 'url', 'mime_type', 'size', 'width', 'height', 'expires_at'],
        'message',
    ])->assertJsonPath('data.mime_type', 'image/webp')
        ->assertJsonPath('data.width', 1600)
        ->assertJsonPath('data.height', 800);
    $path = $response->json('data.path');
    expect($path)->toStartWith('images/'.$user->id.'/')->toEndWith('.webp');
    expect(pathinfo($path, PATHINFO_FILENAME))->toBeUuid();
    $disk->assertExists($path);
    $contents = $disk->get($path);
    expect(strlen($contents))->toBeGreaterThan(0)->toBeLessThanOrEqual(20480);
    expect(getimagesizefromstring($contents)['mime'])->toBe('image/webp');
    $response->assertJsonPath('data.size', strlen($contents));
})->with([
    [RoleEnum::USER, 'jpg'], [RoleEnum::EDITOR, 'png'], [RoleEnum::ADMIN, 'webp'],
]);

test('guest receives 401 on every image endpoint without storing files', function (string $method, string $uri) {
    $disk = Storage::fake('s3');

    $this->json($method, $uri)->assertUnauthorized()
        ->assertExactJson(['message' => 'Unable to authenticate user']);

    $disk->assertDirectoryEmpty('/');
})->with([
    ['POST', '/api/images'], ['POST', '/api/images/batch'],
    ['GET', '/api/images'], ['DELETE', '/api/images'],
]);

test('invalid uploads receive 422 without storing files', function (Closure $payload, string $field) {
    $disk = Storage::fake('s3');
    $user = imageUploadUser();

    $this->actingAs($user, 'web')->postJson('/api/images', $payload())
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);

    $disk->assertDirectoryEmpty('/');
})->with([
    'missing file' => [fn (): array => [], 'image'],
    'URL instead of upload' => [fn (): array => ['image' => 'https://example.com/image.jpg'], 'image'],
    'renamed text' => [fn (): array => ['image' => UploadedFile::fake()->createWithContent('fake.jpg', 'not an image')], 'image'],
    'SVG' => [fn (): array => ['image' => UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')], 'image'],
    'GIF' => [fn (): array => ['image' => UploadedFile::fake()->image('animated.gif')], 'image'],
    'oversized file' => [fn (): array => ['image' => UploadedFile::fake()->image('large.jpg')->size(30721)], 'image'],
    'oversized dimension' => [fn (): array => ['image' => UploadedFile::fake()->image('wide.jpg', 8193, 1)], 'image'],
    'both fields' => [fn (): array => ['image' => UploadedFile::fake()->image('single.jpg'), 'images' => [UploadedFile::fake()->image('batch.jpg')]], 'images'],
]);

test('an image over 16 megapixels receives 422 before decoding', function () {
    $disk = Storage::fake('s3');
    $user = imageUploadUser();
    $contents = UploadedFile::fake()->image('large.png')->get();
    $contents = substr_replace($contents, pack('NN', 4001, 4000), 16, 8);

    $this->actingAs($user, 'web')->postJson('/api/images', [
        'image' => UploadedFile::fake()->createWithContent('large.png', $contents),
    ])->assertUnprocessable()->assertJsonPath('errors.image.0', 'Each image must not exceed 16 megapixels.');

    $disk->assertDirectoryEmpty('/');
});

test('a detailed image is resized until its actual WebP bytes fit 20 KB', function () {
    $disk = Storage::fake('s3');
    $user = imageUploadUser();
    $image = imagecreatetruecolor(1000, 600);
    for ($y = 0; $y < 600; $y++) {
        for ($x = 0; $x < 1000; $x++) {
            imagesetpixel($image, $x, $y, (($x * 73856093) ^ ($y * 19349663)) & 0xFFFFFF);
        }
    }
    $contents = imageUploadContents($image);
    unset($image);
    expect(strlen($contents))->toBeGreaterThan(20480);

    $response = $this->actingAs($user, 'web')->postJson('/api/images', [
        'image' => UploadedFile::fake()->createWithContent('detailed.png', $contents),
    ])->assertCreated();

    $stored = $disk->get($response->json('data.path'));
    expect(strlen($stored))->toBeLessThanOrEqual(20480);
    $dimensions = getimagesizefromstring($stored);
    expect($dimensions[0])->toBeLessThan(1000);
    expect($dimensions[0] / $dimensions[1])->toBeBetween(1.64, 1.70);
});

test('transparent PNG pixels remain transparent after compression', function () {
    $disk = Storage::fake('s3');
    $user = imageUploadUser();
    $image = imagecreatetruecolor(100, 60);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 30, 20, 70, 40, imagecolorallocatealpha($image, 255, 0, 0, 0));

    $response = $this->actingAs($user, 'web')->postJson('/api/images', [
        'image' => UploadedFile::fake()->createWithContent('transparent.png', imageUploadContents($image)),
    ])->assertCreated()->assertJsonPath('data.width', 100)->assertJsonPath('data.height', 60);

    $stored = imagecreatefromstring($disk->get($response->json('data.path')));
    expect(imagecolorsforindex($stored, imagecolorat($stored, 0, 0))['alpha'])->toBe(127);
    expect(imagecolorsforindex($stored, imagecolorat($stored, 50, 30))['alpha'])->toBe(0);
});

test('JPEG orientation is applied and EXIF metadata is stripped', function (int $orientation, int $width, int $height, array $topLeft, array $topRight) {
    $disk = Storage::fake('s3');
    $user = imageUploadUser();
    $image = imagecreatetruecolor(120, 60);
    imagefilledrectangle($image, 0, 0, 59, 29, 0xFF0000);
    imagefilledrectangle($image, 60, 0, 119, 29, 0x00FF00);
    imagefilledrectangle($image, 0, 30, 59, 59, 0x0000FF);
    imagefilledrectangle($image, 60, 30, 119, 59, 0xFFFF00);
    $contents = imageUploadContents($image, 'jpeg');
    $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 0x0112, 3, 1, $orientation)."\0\0".pack('V', 0);
    $contents = substr($contents, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($contents, 2);

    $response = $this->actingAs($user, 'web')->postJson('/api/images', [
        'image' => UploadedFile::fake()->createWithContent('portrait.jpg', $contents),
    ])->assertCreated()->assertJsonPath('data.width', $width)->assertJsonPath('data.height', $height);

    $stored = $disk->get($response->json('data.path'));
    expect($stored)->not->toContain('Exif');
    $decoded = imagecreatefromstring($stored);
    foreach (['red', 'green', 'blue'] as $index => $channel) {
        expect(imagecolorsforindex($decoded, imagecolorat($decoded, 10, 10))[$channel])->toBeBetween(max(0, $topLeft[$index] - 20), min(255, $topLeft[$index] + 20));
        expect(imagecolorsforindex($decoded, imagecolorat($decoded, $width - 10, 10))[$channel])->toBeBetween(max(0, $topRight[$index] - 20), min(255, $topRight[$index] + 20));
    }
})->with([
    [1, 120, 60, [255, 0, 0], [0, 255, 0]],
    [2, 120, 60, [0, 255, 0], [255, 0, 0]],
    [3, 120, 60, [255, 255, 0], [0, 0, 255]],
    [4, 120, 60, [0, 0, 255], [255, 255, 0]],
    [5, 60, 120, [255, 0, 0], [0, 0, 255]],
    [6, 60, 120, [0, 0, 255], [255, 0, 0]],
    [7, 60, 120, [255, 255, 0], [0, 255, 0]],
    [8, 60, 120, [0, 255, 0], [255, 255, 0]],
]);

test('batch upload returns distinct processed images in input order', function () {
    $disk = Storage::fake('s3');
    $user = imageUploadUser();

    $response = $this->actingAs($user, 'web')->postJson('/api/images/batch', [
        'images' => [UploadedFile::fake()->image('same.jpg', 120, 60), UploadedFile::fake()->image('same.jpg', 80, 100)],
    ])->assertCreated()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.width', 120)->assertJsonPath('data.1.width', 80);

    $paths = array_column($response->json('data'), 'path');
    expect(array_unique($paths))->toHaveCount(2);
    $disk->assertExists($paths);
    $disk->assertCount('images/'.$user->id, 2);
});

test('invalid batch structure or size receives 422 without writing any images', function (Closure $payload, string $field) {
    $disk = Storage::fake('s3');
    $user = imageUploadUser();

    $this->actingAs($user, 'web')->postJson('/api/images/batch', $payload())
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);

    $disk->assertDirectoryEmpty('/');
})->with([
    'missing' => [fn (): array => [], 'images'],
    'empty' => [fn (): array => ['images' => []], 'images'],
    'not a list' => [fn (): array => ['images' => ['named' => UploadedFile::fake()->image('a.jpg')]], 'images'],
    'too many' => [fn (): array => ['images' => array_map(fn (): UploadedFile => UploadedFile::fake()->image('a.jpg'), range(1, 6))], 'images'],
    'total bytes' => [fn (): array => ['images' => [UploadedFile::fake()->image('a.jpg')->size(16000), UploadedFile::fake()->image('b.jpg')->size(16000)]], 'images'],
    'invalid member' => [fn (): array => ['images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->createWithContent('b.jpg', 'invalid')]], 'images.1'],
]);

test('a damaged image in a batch receives 422 with its index and leaves storage empty', function () {
    $disk = Storage::fake('s3');
    $user = imageUploadUser();
    $damaged = substr(UploadedFile::fake()->image('damaged.png')->get(), 0, 33);

    $this->actingAs($user, 'web')->postJson('/api/images/batch', [
        'images' => [UploadedFile::fake()->image('valid.jpg'), UploadedFile::fake()->createWithContent('damaged.png', $damaged)],
    ])->assertUnprocessable()->assertJson(['errors' => ['images.1' => ['The image is damaged or cannot be decoded.']]]);

    $disk->assertDirectoryEmpty('/');
});

test('a failed batch storage write returns 503 and removes every attempted image', function (bool $throw) {
    $disk = Storage::fake('s3');
    $user = imageUploadUser();
    Exceptions::fake();
    $writes = 0;
    $storage = Mockery::mock($disk);
    $storage->shouldReceive('put')->andReturnUsing(function (string $path, string $contents, array $options) use ($disk, &$writes, $throw): bool {
        $disk->put($path, $contents, $options);
        $writes++;
        if ($writes === 2) {
            if ($throw) {
                throw UnableToWriteFile::atLocation($path, 'Storage unavailable');
            }

            return false;
        }

        return true;
    });
    Storage::set('s3', $storage);

    $this->actingAs($user, 'web')->postJson('/api/images/batch', [
        'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
    ])->assertServiceUnavailable()->assertExactJson([
        'message' => 'Image processing or storage is temporarily unavailable. Please try again.',
    ]);

    $disk->assertDirectoryEmpty('/');
    Exceptions::assertReported($throw ? UnableToWriteFile::class : RuntimeException::class);
})->with([false, true]);

test('owner retrieves image metadata and can delete the same image repeatedly', function () {
    $disk = Storage::fake('s3');
    $user = imageUploadUser();
    $path = 'images/'.$user->id.'/'.Str::uuid().'.webp';
    $contents = imageUploadContents(imagecreatetruecolor(90, 40), 'webp');
    $disk->put($path, $contents);

    $this->actingAs($user, 'web')->getJson('/api/images?'.http_build_query(['path' => $path]))
        ->assertOk()->assertJsonPath('data.path', $path)->assertJsonPath('data.width', 90)
        ->assertJsonPath('data.size', strlen($contents));
    $this->deleteJson('/api/images', ['path' => $path])->assertOk();
    $this->deleteJson('/api/images', ['path' => $path])->assertOk();

    $disk->assertMissing($path);
});

test('other users receive 404 when retrieving or deleting an owned image', function (string $method) {
    $disk = Storage::fake('s3');
    $owner = imageUploadUser();
    $other = imageUploadUser();
    $path = 'images/'.$owner->id.'/'.Str::uuid().'.webp';
    $disk->put($path, imageUploadContents(imagecreatetruecolor(10, 10), 'webp'));

    $this->actingAs($other, 'web')->json($method, '/api/images', ['path' => $path])->assertNotFound();

    $disk->assertExists($path);
})->with(['GET', 'DELETE']);

test('unsafe paths receive 422 without touching storage', function (string $path) {
    $disk = Storage::fake('s3');
    $user = imageUploadUser();
    $disk->put('keep.webp', 'keep');

    $this->actingAs($user, 'web')->deleteJson('/api/images', ['path' => $path])
        ->assertUnprocessable()->assertJsonValidationErrors(['path']);

    $disk->assertExists('keep.webp');
})->with(['../keep.webp', 'https://example.com/keep.webp', '/keep.webp', 'images/../keep.webp']);

test('missing image receives 404 when its owner requests its URL', function () {
    $disk = Storage::fake('s3');
    $user = imageUploadUser();

    $this->actingAs($user, 'web')->getJson('/api/images?'.http_build_query([
        'path' => 'images/'.$user->id.'/'.Str::uuid().'.webp',
    ]))->assertNotFound();

    $disk->assertDirectoryEmpty('/');
});

test('upload throttling returns 429 and shares its limit across single and batch endpoints', function () {
    $disk = Storage::fake('s3');
    $user = imageUploadUser();
    $this->actingAs($user, 'web');
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $this->postJson('/api/images')->assertUnprocessable();
    }

    $this->postJson('/api/images/batch', ['images' => [UploadedFile::fake()->image('image.jpg')]])
        ->assertTooManyRequests()->assertHeader('Retry-After');

    $disk->assertDirectoryEmpty('/');
});

test('S3 compatible storage receives processed bytes and returns the correct image URL', function (string $endpoint, ?string $publicUrl, bool $pathStyle, string $region) {
    $user = imageUploadUser();
    $handler = new MockHandler([new Result(['ETag' => 'test-etag'])]);
    config(['filesystems.disks.s3' => [
        'driver' => 's3', 'key' => 'test-key', 'secret' => 'test-secret',
        'region' => $region, 'bucket' => 'image-bucket', 'endpoint' => $endpoint,
        'url' => $publicUrl, 'use_path_style_endpoint' => $pathStyle,
        'handler' => $handler, 'throw' => true,
    ]]);
    Storage::forgetDisk('s3');
    $this->freezeTime();

    $response = $this->actingAs($user, 'web')->postJson('/api/images', [
        'image' => UploadedFile::fake()->image('image.jpg'),
    ])->assertCreated();

    $command = $handler->getLastCommand();
    expect($command->getName())->toBe('PutObject');
    expect($command['Bucket'])->toBe('image-bucket');
    expect($command['Key'])->toBe($response->json('data.path'));
    expect($command['ContentType'])->toBe('image/webp');
    expect((string) $handler->getLastRequest()->getBody())->toStartWith('RIFF');
    if ($publicUrl !== null) {
        $response->assertJsonPath('data.url', $publicUrl.'/'.$response->json('data.path'))
            ->assertJsonPath('data.expires_at', null);
    } else {
        expect($response->json('data.url'))->toStartWith($endpoint.'/image-bucket/images/')->toContain('X-Amz-Signature=');
        $response->assertJsonPath('data.expires_at', now()->addHour()->toIso8601String());
    }
})->with([
    'MinIO' => ['http://127.0.0.1:9002', null, true, 'us-east-1'],
    'R2' => ['https://test-account.r2.cloudflarestorage.com', 'https://media.example.com', false, 'auto'],
]);

test('storage read and delete failures return 503 without exposing internal errors', function (string $method, string $storageMethod) {
    $disk = Storage::fake('s3');
    $user = imageUploadUser();
    $path = 'images/'.$user->id.'/'.Str::uuid().'.webp';
    $disk->put($path, imageUploadContents(imagecreatetruecolor(10, 10), 'webp'));
    Exceptions::fake();
    $storage = Mockery::mock($disk);
    $storage->shouldReceive($storageMethod)->andThrow(new RuntimeException('Internal credential or endpoint details'));
    Storage::set('s3', $storage);

    $this->actingAs($user, 'web')->json($method, '/api/images', ['path' => $path])
        ->assertServiceUnavailable()->assertExactJson([
            'message' => 'Image processing or storage is temporarily unavailable. Please try again.',
        ]);

    $disk->assertExists($path);
    Exceptions::assertReported(RuntimeException::class);
})->with([['GET', 'get'], ['DELETE', 'delete']]);
