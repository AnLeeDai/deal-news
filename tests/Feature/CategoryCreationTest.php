<?php

use App\Models\Category;
use App\Models\User;
use App\RoleEnum;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

uses(TestCase::class, RefreshDatabase::class);

test('administrator creates a category with a UUID and receives the category resource', function (array $optionalAttributes) {
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ], RoleEnum::ADMIN);

    $response = $this->actingAs($admin, 'web')->postJson('/api/admin/categories', [
        'name' => 'Movie News',
        ...$optionalAttributes,
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Category created successfully')
        ->assertExactJsonStructure([
            'data' => [
                'id', 'name', 'slug', 'thumbnail', 'total_articles',
                'description', 'created_at', 'updated_at',
            ],
            'message',
        ])
        ->assertJsonPath('data.name', 'Movie News')
        ->assertJsonPath('data.slug', 'movie-news')
        ->assertJsonPath('data.total_articles', 0)
        ->assertJsonPath('data.thumbnail', $optionalAttributes['thumbnail'] ?? null)
        ->assertJsonPath('data.description', $optionalAttributes['description'] ?? null);
    expect($response->json('data.id'))->toBeUuid();
    $this->assertDatabaseHas('categories', [
        'id' => $response->json('data.id'),
        'name' => 'Movie News',
        'slug' => 'movie-news',
        'total_articles' => 0,
        'thumbnail' => $optionalAttributes['thumbnail'] ?? null,
        'description' => $optionalAttributes['description'] ?? null,
    ]);
})->with([
    'only required fields' => [[]],
    'null thumbnail' => [['thumbnail' => null]],
    'optional fields' => [[
        'thumbnail' => 'categories/films.webp',
        'description' => 'The latest movie news',
    ]],
]);

test('guest receives 401 when creating a category', function () {
    $this->postJson('/api/admin/categories', ['name' => 'Movie News'])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unable to authenticate user']);

    $this->assertDatabaseEmpty('categories');
});

test('non administrator receives 403 when creating a category', function (RoleEnum $role) {
    $user = User::registerUser([
        'full_name' => 'Restricted User',
        'email' => 'restricted@example.com',
        'password' => 'TestPassword123!',
    ], $role);

    $this->actingAs($user, 'web')->postJson('/api/admin/categories', ['name' => 'Movie News'])
        ->assertForbidden();

    $this->assertDatabaseEmpty('categories');
})->with([RoleEnum::USER, RoleEnum::EDITOR]);

test('invalid category input returns 422 without creating a category', function () {
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ], RoleEnum::ADMIN);

    $this->actingAs($admin, 'web')->postJson('/api/admin/categories', ['slug' => 'custom-slug'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'slug']);

    $this->assertDatabaseEmpty('categories');
});

test('administrator receives paginated category resources including an empty collection', function (int $categoryCount) {
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ], RoleEnum::ADMIN);
    for ($index = 1; $index <= $categoryCount; $index++) {
        Category::create(['name' => 'Category '.$index, 'slug' => 'category-'.$index]);
    }

    $response = $this->actingAs($admin, 'web')->getJson('/api/admin/categories');

    $response->assertOk()
        ->assertJsonCount(min($categoryCount, 10), 'data')
        ->assertJsonPath('meta.total', $categoryCount)
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('message', 'Categories retrieved successfully')
        ->assertExactJsonStructure([
            'data' => ['*' => [
                'id', 'name', 'slug', 'thumbnail', 'total_articles',
                'description', 'created_at', 'updated_at',
            ]],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => [
                'current_page', 'from', 'last_page', 'links',
                'path', 'per_page', 'to', 'total',
            ],
            'message',
        ]);
})->with(['empty' => 0, 'multiple pages' => 11]);

test('guest receives 401 when listing categories', function () {
    $this->getJson('/api/admin/categories')->assertUnauthorized();
});

test('non administrator receives 403 when listing categories', function () {
    $user = User::registerUser([
        'full_name' => 'Restricted User',
        'email' => 'restricted@example.com',
        'password' => 'TestPassword123!',
    ]);

    $this->actingAs($user, 'web')->getJson('/api/admin/categories')->assertForbidden();
});

test('administrator creates a category with an uploaded thumbnail processed as WebP', function () {
    $disk = Storage::fake('s3');
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ], RoleEnum::ADMIN);

    $response = $this->actingAs($admin, 'web')->post('/api/admin/categories', [
        'name' => 'Movie News',
        'thumbnail' => UploadedFile::fake()->image('thumbnail.jpg', 2000, 1000),
        'description' => 'The latest movie news',
    ], ['Accept' => 'application/json']);

    $response->assertCreated()->assertJsonPath('data.name', 'Movie News')
        ->assertJsonPath('data.slug', 'movie-news');
    $path = $response->json('data.thumbnail');
    expect($path)->toStartWith('images/'.$admin->id.'/')->toEndWith('.webp');
    $disk->assertExists($path);
    $contents = $disk->get($path);
    expect(strlen($contents))->toBeGreaterThan(0)->toBeLessThanOrEqual(20480);
    expect(getimagesizefromstring($contents))->toMatchArray([
        0 => 1600, 1 => 800, 'mime' => 'image/webp',
    ]);
    $this->assertDatabaseHas('categories', [
        'id' => $response->json('data.id'),
        'thumbnail' => $path,
        'description' => 'The latest movie news',
    ]);
    $this->getJson('/api/admin/categories')->assertJsonPath('data.0.thumbnail', $path);
});

test('an invalid thumbnail receives 422 without creating a category or storing files', function () {
    $disk = Storage::fake('s3');
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ], RoleEnum::ADMIN);

    $this->actingAs($admin, 'web')->postJson('/api/admin/categories', [
        'name' => 'Movie News',
        'thumbnail' => UploadedFile::fake()->createWithContent('fake.jpg', 'not an image'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['thumbnail']);

    $this->assertDatabaseEmpty('categories');
    $disk->assertDirectoryEmpty('/');
});

test('a damaged thumbnail receives 422 under the thumbnail field', function () {
    $disk = Storage::fake('s3');
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ], RoleEnum::ADMIN);
    $damaged = substr(UploadedFile::fake()->image('damaged.png')->get(), 0, 33);

    $this->actingAs($admin, 'web')->postJson('/api/admin/categories', [
        'name' => 'Movie News',
        'thumbnail' => UploadedFile::fake()->createWithContent('damaged.png', $damaged),
    ])->assertUnprocessable()->assertJsonPath('errors.thumbnail.0', 'The image is damaged or cannot be decoded.');

    $this->assertDatabaseEmpty('categories');
    $disk->assertDirectoryEmpty('/');
});

test('invalid category fields prevent a valid thumbnail from being uploaded', function () {
    $disk = Storage::fake('s3');
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ], RoleEnum::ADMIN);

    $this->actingAs($admin, 'web')->postJson('/api/admin/categories', [
        'thumbnail' => UploadedFile::fake()->image('thumbnail.jpg'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['name']);

    $this->assertDatabaseEmpty('categories');
    $disk->assertDirectoryEmpty('/');
});

test('thumbnail storage failure returns 503 without creating a category', function () {
    $disk = Storage::fake('s3');
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ], RoleEnum::ADMIN);
    Exceptions::fake();
    $storage = Mockery::mock($disk);
    $storage->shouldReceive('put')->andReturnFalse();
    Storage::set('s3', $storage);

    $this->actingAs($admin, 'web')->postJson('/api/admin/categories', [
        'name' => 'Movie News',
        'thumbnail' => UploadedFile::fake()->image('thumbnail.jpg'),
    ])->assertServiceUnavailable()->assertExactJson([
        'message' => 'Image processing or storage is temporarily unavailable. Please try again.',
    ]);

    $this->assertDatabaseEmpty('categories');
    $disk->assertDirectoryEmpty('/');
    Exceptions::assertReported(RuntimeException::class);
});

test('a database failure removes only the newly uploaded thumbnail', function () {
    $disk = Storage::fake('s3');
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ], RoleEnum::ADMIN);
    $existing = Category::create(['name' => 'Movie News', 'slug' => 'movie-news', 'thumbnail' => 'categories/existing.webp']);
    $disk->put('categories/existing.webp', 'existing image');
    Exceptions::fake();

    $this->actingAs($admin, 'web')->postJson('/api/admin/categories', [
        'name' => 'Movie-News',
        'thumbnail' => UploadedFile::fake()->image('thumbnail.jpg'),
    ])->assertInternalServerError();

    $this->assertDatabaseCount('categories', 1);
    $this->assertModelExists($existing);
    $disk->assertExists('categories/existing.webp', 'existing image');
    $disk->assertCount('/', 1, true);
    Exceptions::assertReported(UniqueConstraintViolationException::class);
});

test('a database failure preserves a thumbnail supplied as an existing path', function () {
    $disk = Storage::fake('s3');
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ], RoleEnum::ADMIN);
    Category::create(['name' => 'Movie News', 'slug' => 'movie-news']);
    $disk->put('categories/existing.webp', 'existing image');
    Exceptions::fake();

    $this->actingAs($admin, 'web')->postJson('/api/admin/categories', [
        'name' => 'Movie-News',
        'thumbnail' => 'categories/existing.webp',
    ])->assertInternalServerError();

    $this->assertDatabaseCount('categories', 1);
    $disk->assertExists('categories/existing.webp', 'existing image');
    Exceptions::assertReported(UniqueConstraintViolationException::class);
});

test('category thumbnail uploads share the image API rate limit', function () {
    $disk = Storage::fake('s3');
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ], RoleEnum::ADMIN);
    $this->actingAs($admin, 'web');
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $this->postJson('/api/images')->assertUnprocessable();
    }

    $this->postJson('/api/admin/categories', [
        'name' => 'Movie News',
        'thumbnail' => UploadedFile::fake()->image('thumbnail.jpg'),
    ])->assertTooManyRequests()->assertHeader('Retry-After');

    $this->assertDatabaseEmpty('categories');
    $disk->assertDirectoryEmpty('/');
});

test('a failure after inserting a category rolls back the record and removes its uploaded thumbnail', function () {
    $disk = Storage::fake('s3');
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ], RoleEnum::ADMIN);
    Exceptions::fake();
    Event::listen('eloquent.created: '.Category::class, function (Category $category): void {
        throw new RuntimeException('Category creation failed after insertion.');
    });

    $this->actingAs($admin, 'web')->postJson('/api/admin/categories', [
        'name' => 'Movie News',
        'thumbnail' => UploadedFile::fake()->image('thumbnail.jpg'),
    ])->assertInternalServerError();

    $this->assertDatabaseEmpty('categories');
    $disk->assertDirectoryEmpty('/');
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Category creation failed after insertion.');
});
