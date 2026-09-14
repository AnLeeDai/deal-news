<?php

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('public category detail returns only the category name for the route UUID', function (array $query) {
    $category = Category::create([
        'name' => 'Movie News',
        'slug' => 'movie-news',
        'description' => 'The latest movie news',
    ]);

    $response = $this->getJson(route('public.category.show', ['category' => $category->id, ...$query]));

    $response->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => $category->id,
                'type' => 'categories',
                'attributes' => ['name' => 'Movie News'],
            ],
        ]);
})->with([
    'without requested fields' => [[]],
    'other requested fields cannot change the response' => [['fields' => ['categories' => 'description']]],
]);

test('public category detail returns 404 for an invalid or unknown UUID', function (string $categoryId) {
    config(['app.debug' => false]);

    $response = $this->getJson(route('public.category.show', ['category' => $categoryId]));

    $response->assertNotFound()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertExactJson(['data' => null, 'meta' => ['message' => 'Category not found']]);
})->with([
    'invalid UUID' => 'not-a-uuid',
    'unknown UUID' => '01950000-0000-7000-8000-000000000001',
]);

test('public category detail returns 404 when a query parameter attempts to replace an invalid route UUID', function () {
    config(['app.debug' => false]);
    $category = Category::create(['name' => 'Movie News', 'slug' => 'movie-news']);

    $response = $this->getJson(route('public.category.show', ['category' => 'not-a-uuid']).'?'.http_build_query([
        'category' => $category->id,
    ]));

    $response->assertNotFound()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertExactJson(['data' => null, 'meta' => ['message' => 'Category not found']]);
});
