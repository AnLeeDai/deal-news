<?php

use App\Http\Controllers\ArticleController;
use App\Http\Resources\ArticleResource;
use App\Models\Articles;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Route::post('/api/test/articles', [ArticleController::class, 'createArticle'])->middleware('auth:sanctum');
    Route::get('/api/test/articles/{article}', fn (Articles $article): ArticleResource => $article->toResource())->middleware('api');
    Route::get('/api/test/articles', fn (): ResourceCollection => Articles::all()->toResourceCollection());
});

test('article creation returns a JSON API resource with persisted image attributes', function () {
    $user = User::registerUser([
        'full_name' => 'Article Author',
        'email' => 'author@example.com',
        'password' => 'TestPassword123!',
    ]);
    $category = Category::createCategory(['name' => 'Movie News']);

    $response = $this->actingAs($user, 'web')->postJson('/api/test/articles', [
        'title' => 'New Film',
        'content' => 'The latest film news.',
        'category_id' => $category->id,
        'user_id' => $user->id,
        'thumbnail' => 'images/thumbnail.webp',
        'additional_images' => ['images/first.webp', 'images/second.webp'],
    ]);

    $response->assertCreated()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('data.type', 'articles')
        ->assertJsonPath('data.attributes.title', 'New Film')
        ->assertJsonPath('data.attributes.thumbnail', 'images/thumbnail.webp')
        ->assertJsonPath('data.attributes.additional_images', ['images/first.webp', 'images/second.webp'])
        ->assertJsonPath('meta.message', 'Article created successfully')
        ->assertExactJsonStructure([
            'data' => [
                'id', 'type', 'attributes' => [
                    'title', 'content', 'thumbnail', 'thumbnail_url', 'additional_images', 'additional_images_urls', 'slug', 'created_at', 'updated_at',
                ],
            ],
            'meta' => ['message'],
        ]);
    $this->assertDatabaseHas('articles', [
        'id' => $response->json('data.id'),
        'category_id' => $category->id,
        'user_id' => $user->id,
    ]);
    expect(Articles::findOrFail($response->json('data.id'))->additional_images)
        ->toBe(['images/first.webp', 'images/second.webp']);
});

test('article creation accepts no images and persists nullable image fields', function () {
    $user = User::registerUser([
        'full_name' => 'Article Author',
        'email' => 'author@example.com',
        'password' => 'TestPassword123!',
    ]);
    $category = Category::createCategory(['name' => 'Movie News']);

    $response = $this->actingAs($user, 'web')->postJson('/api/test/articles', [
        'title' => 'New Film',
        'content' => 'The latest film news.',
        'category_id' => $category->id,
        'user_id' => $user->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.attributes.thumbnail', null)
        ->assertJsonPath('data.attributes.additional_images', null);
    $this->assertDatabaseHas('articles', [
        'id' => $response->json('data.id'),
        'thumbnail' => null,
    ]);
});

test('article creation returns 422 when a distinct title produces an existing slug', function () {
    $user = User::registerUser([
        'full_name' => 'Article Author',
        'email' => 'author@example.com',
        'password' => 'TestPassword123!',
    ]);
    $category = Category::createCategory(['name' => 'Movie News']);
    Articles::create([
        'title' => 'Công nghệ AI đang thay đổi thế giới như thế nào nhỉ',
        'slug' => 'cong-nghe-ai-dang-thay-doi-the-gioi-nhu-the-nao-nhi',
    ]);

    $this->actingAs($user, 'web')->postJson('/api/test/articles', [
        'title' => 'Công nghệ AI đang thay đổi thế giới như thế nào nhỉ ?',
        'content' => 'The latest artificial intelligence news.',
        'category_id' => $category->id,
        'user_id' => $user->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['title'])
        ->assertJsonPath('errors.title.0', 'The title generates a URL slug that already exists.');

    expect(Articles::query()->count())->toBe(1);
});

test('article creation processes uploaded thumbnail and additional images', function () {
    config(['images.disk' => 's3']);
    $disk = Storage::fake('s3');
    $user = User::registerUser([
        'full_name' => 'Article Author',
        'email' => 'author@example.com',
        'password' => 'TestPassword123!',
    ]);
    $category = Category::createCategory(['name' => 'Movie News']);

    $response = $this->actingAs($user, 'web')->post('/api/test/articles', [
        'title' => 'New Film',
        'content' => 'The latest film news.',
        'category_id' => $category->id,
        'user_id' => $user->id,
        'thumbnail' => UploadedFile::fake()->image('thumbnail.jpg'),
        'additional_images' => [
            UploadedFile::fake()->image('first.jpg'),
            UploadedFile::fake()->image('second.png'),
            UploadedFile::fake()->image('third.webp'),
        ],
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('data.attributes.title', 'New Film');
    $thumbnail = $response->json('data.attributes.thumbnail');
    $additionalImages = $response->json('data.attributes.additional_images');
    $additionalImageUrls = $response->json('data.attributes.additional_images_urls');

    expect($thumbnail)->toStartWith('images/'.$user->id.'/')->toEndWith('.webp');
    expect($additionalImages)->toHaveCount(3);
    expect($response->json('data.attributes.thumbnail_url'))->toBe($disk->url($thumbnail));
    expect($additionalImageUrls)->toBe(array_map($disk->url(...), $additionalImages));
    $disk->assertExists($thumbnail);
    $disk->assertExists($additionalImages);
});

test('article creation accepts a single uploaded additional image', function () {
    config(['images.disk' => 's3']);
    $disk = Storage::fake('s3');
    $user = User::registerUser([
        'full_name' => 'Article Author',
        'email' => 'author@example.com',
        'password' => 'TestPassword123!',
    ]);
    $category = Category::createCategory(['name' => 'Movie News']);

    $response = $this->actingAs($user, 'web')->post('/api/test/articles', [
        'title' => 'New Film',
        'content' => 'The latest film news.',
        'category_id' => $category->id,
        'user_id' => $user->id,
        'additional_images' => UploadedFile::fake()->image('first.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertCreated();
    $additionalImages = $response->json('data.attributes.additional_images');

    expect($additionalImages)->toHaveCount(1);
    $disk->assertExists($additionalImages);
});

test('public API returns paginated articles with requested relationships', function () {
    $user = User::registerUser([
        'full_name' => 'Article Author',
        'email' => 'author@example.com',
        'password' => 'TestPassword123!',
    ]);
    $category = Category::createCategory(['name' => 'Movie News']);
    $article = Articles::create([
        'title' => 'New Film',
        'slug' => 'new-film',
        'category_id' => $category->id,
        'user_id' => $user->id,
    ]);

    $this->getJson('/api/public/articles?'.http_build_query([
        'include' => 'category,user',
        'fields' => [
            'articles' => 'title,category,user',
            'categories' => 'name',
            'users' => 'user_code',
        ],
    ]))->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('data.0.id', $article->id)
        ->assertJsonPath('data.0.type', 'articles')
        ->assertJsonPath('data.0.attributes', ['title' => 'New Film'])
        ->assertJsonPath('data.0.relationships', [
            'category' => ['data' => ['id' => $category->id, 'type' => 'categories']],
            'user' => ['data' => ['id' => $user->id, 'type' => 'users']],
        ])
        ->assertJsonPath('included', [
            ['id' => $category->id, 'type' => 'categories', 'attributes' => ['name' => 'Movie News']],
            ['id' => $user->id, 'type' => 'users', 'attributes' => ['user_code' => $user->user_code]],
        ])
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('meta.message', 'Articles retrieved successfully');
});

test('article includes use explicit resources and sparse fields without exposing author secrets', function () {
    $user = User::registerUser([
        'full_name' => 'Article Author',
        'email' => 'author@example.com',
        'password' => 'TestPassword123!',
    ]);
    $category = Category::createCategory(['name' => 'Movie News']);
    $article = Articles::create([
        'title' => 'New Film', 'slug' => 'new-film',
        'category_id' => $category->id, 'user_id' => $user->id,
    ]);

    $this->getJson('/api/test/articles/'.$article->id.'?'.http_build_query([
        'include' => 'category,user',
        'fields' => [
            'articles' => 'title,category,user',
            'categories' => 'name',
            'users' => 'user_code,email,password,remember_token',
        ],
    ]))->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertExactJson([
            'data' => [
                'id' => $article->id,
                'type' => 'articles',
                'attributes' => ['title' => 'New Film'],
                'relationships' => [
                    'category' => ['data' => ['id' => $category->id, 'type' => 'categories']],
                    'user' => ['data' => ['id' => $user->id, 'type' => 'users']],
                ],
            ],
            'included' => [
                ['id' => $category->id, 'type' => 'categories', 'attributes' => ['name' => 'Movie News']],
                ['id' => $user->id, 'type' => 'users', 'attributes' => ['user_code' => $user->user_code]],
            ],
        ]);
});

test('article includes return null links for unassigned relationships and ignore unknown includes', function () {
    $article = Articles::create(['title' => 'Unassigned Article', 'slug' => 'unassigned-article']);

    $this->getJson('/api/test/articles/'.$article->id.'?include=category,user,tokens')
        ->assertOk()
        ->assertJsonPath('data.id', $article->id)
        ->assertJsonPath('data.relationships', [
            'category' => ['data' => null],
            'user' => ['data' => null],
        ])
        ->assertJsonMissingPath('included');
});

test('article collections discover the explicit resource mapping for the plural model name', function () {
    $article = Articles::create(['title' => 'New Film', 'slug' => 'new-film']);

    $this->getJson('/api/test/articles?fields[articles]=title')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertExactJson(['data' => [[
            'id' => $article->id,
            'type' => 'articles',
            'attributes' => ['title' => 'New Film'],
        ]]]);
});
