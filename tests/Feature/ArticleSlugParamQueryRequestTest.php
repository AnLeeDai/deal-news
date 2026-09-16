<?php

use App\Http\Controllers\ArticleController;
use App\Models\Articles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Route;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Route::get('/_test/articles/{slug?}', [ArticleController::class, 'findArticleBySlug']);
});

test('article slug lookup returns the requested article', function () {
    $article = Articles::create(['title' => 'Requested Article', 'slug' => 'requested-article']);

    $this->getJson('/_test/articles/requested-article')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('data.id', $article->id)
        ->assertJsonPath('data.attributes.slug', 'requested-article');
});

test('article slug lookup returns a resource 404 for a missing or unknown slug', function (string $slug) {
    $this->getJson('/_test/articles/'.$slug)
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertExactJson(['data' => null, 'meta' => ['message' => 'Article not found']]);
})->with([
    'missing slug' => '',
    'unknown slug' => 'unknown-article',
]);

test('article slug lookup rejects an existing slug longer than 255 characters', function () {
    $slug = str_repeat('a', 256);
    Articles::create(['title' => 'Long Slug Article', 'slug' => $slug]);

    $this->getJson('/_test/articles/'.$slug)
        ->assertNotFound()
        ->assertExactJson(['data' => null, 'meta' => ['message' => 'Article not found']]);
});

test('article slug lookup does not allow query parameters to override the route slug', function () {
    $article = Articles::create(['title' => 'Requested Article', 'slug' => 'requested-article']);
    Articles::create(['title' => 'Other Article', 'slug' => 'other-article']);

    $this->getJson('/_test/articles/requested-article?slug=other-article')
        ->assertOk()
        ->assertJsonPath('data.id', $article->id)
        ->assertJsonPath('data.attributes.slug', 'requested-article');
});
