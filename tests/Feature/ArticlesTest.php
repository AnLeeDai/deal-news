<?php

use App\Models\Articles;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class, RefreshDatabase::class);

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
