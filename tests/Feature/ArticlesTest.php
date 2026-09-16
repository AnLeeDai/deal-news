<?php

use App\Models\Articles;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('article detail returns 404 with a clear message for an unknown or overlong slug', function (string $slug) {
    config(['app.debug' => false]);

    $this->getJson(route('public.articles.show', ['slug' => $slug]))
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertExactJson(['data' => null, 'meta' => ['message' => 'Article not found']]);
})->with([
    'unknown slug' => 'unknown-article',
    'overlong slug' => str_repeat('a', 256),
]);

test('article detail returns the requested article for an existing slug', function () {
    $article = Articles::create(['title' => 'Requested Article', 'slug' => 'requested-article']);

    $this->getJson(route('public.articles.show', ['slug' => $article->slug]))
        ->assertOk()
        ->assertJsonPath('data.id', $article->id)
        ->assertJsonPath('data.attributes.title', 'Requested Article');
});

test('article detail does not allow a query parameter to override the route slug', function () {
    config(['app.debug' => false]);
    $article = Articles::create(['title' => 'Query Article', 'slug' => 'query-article']);

    $this->getJson(route('public.articles.show', ['slug' => 'unknown-article']).'?slug='.$article->slug)
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertExactJson(['data' => null, 'meta' => ['message' => 'Article not found']]);
});

test('article detail returns 200 without missing resource metadata after a previous 404 response', function () {
    $article = Articles::create(['title' => 'Existing Article', 'slug' => 'existing-article']);

    $this->getJson(route('public.articles.show', ['slug' => 'unknown-article']))
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertExactJson(['data' => null, 'meta' => ['message' => 'Article not found']]);

    $this->getJson(route('public.articles.show', ['slug' => $article->slug]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('data.id', $article->id)
        ->assertJsonPath('data.attributes.title', 'Existing Article')
        ->assertJsonMissingPath('meta');
});

test('article detail no longer resolves an article by its ID', function () {
    $article = Articles::create(['title' => 'Slug Only Article', 'slug' => 'slug-only-article']);

    $this->getJson('/api/public/article/'.$article->id.'/detail')
        ->assertNotFound()
        ->assertExactJson(['data' => null, 'meta' => ['message' => 'Article not found']]);
});

test('an article associates its category and user using UUIDs and eager loads both relationships', function () {
    $category = Category::createCategory(['name' => 'Movie News']);
    $user = User::registerUser([
        'full_name' => 'Article Author',
        'email' => 'author@example.com',
        'password' => 'TestPassword123!',
    ]);
    $article = new Articles(['title' => 'New Film', 'slug' => 'new-film']);

    $article->category()->associate($category);
    $article->user()->associate($user);
    $article->save();

    $this->assertDatabaseHas('articles', [
        'id' => $article->id,
        'category_id' => $category->id,
        'user_id' => $user->id,
    ]);
    $loaded = Articles::with(['category', 'user'])->findOrFail($article->id);
    expect($loaded->id)->toBeUuid();
    expect($loaded->relationLoaded('category'))->toBeTrue();
    expect($loaded->relationLoaded('user'))->toBeTrue();
    expect($loaded->category->is($category))->toBeTrue();
    expect($loaded->user->is($user))->toBeTrue();
});

test('parents create and retrieve only their own articles', function (string $parentType, string $foreignKey) {
    $parents = [];
    for ($index = 1; $index <= 2; $index++) {
        $parents[] = $parentType === Category::class
            ? Category::createCategory(['name' => 'Category '.$index])
            : User::registerUser([
                'full_name' => 'Author '.$index,
                'email' => 'author'.$index.'@example.com',
                'password' => 'TestPassword123!',
            ]);
    }
    [$parent, $otherParent] = $parents;
    $first = $parent->articles()->create(['title' => 'First Article', 'slug' => 'first-article']);
    $second = $parent->articles()->create(['title' => 'Second Article', 'slug' => 'second-article']);
    $other = $otherParent->articles()->create(['title' => 'Other Article', 'slug' => 'other-article']);

    $loadedParents = $parentType::with('articles')->get()->keyBy('id');

    expect($loadedParents[$parent->id]->articles->modelKeys())->toEqualCanonicalizing([$first->id, $second->id]);
    expect($loadedParents[$otherParent->id]->articles->modelKeys())->toBe([$other->id]);
    $this->assertDatabaseHas('articles', ['id' => $first->id, $foreignKey => $parent->id]);
    $this->assertDatabaseHas('articles', ['id' => $second->id, $foreignKey => $parent->id]);
})->with([
    'categories' => [Category::class, 'category_id'],
    'users' => [User::class, 'user_id'],
]);

test('an article without a category or user returns null relationships', function () {
    $article = Articles::create(['title' => 'Unassigned Article', 'slug' => 'unassigned-article']);

    $loaded = Articles::with(['category', 'user'])->findOrFail($article->id);

    expect($loaded->category)->toBeNull();
    expect($loaded->user)->toBeNull();
});
