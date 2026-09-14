<?php

use App\Models\User;
use App\RoleEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => false]);
});

test('public user detail returns only the user code for the route UUID', function () {
    $user = User::registerUser([
        'full_name' => 'Requested User',
        'email' => 'requested@example.com',
        'password' => 'TestPassword123!',
    ]);

    $this->getJson(route('public.user.show', ['user' => $user->id]))
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => $user->id,
                'type' => 'users',
                'attributes' => ['user_code' => $user->user_code],
            ],
        ]);
});

test('public user detail returns 404 for an invalid or unknown UUID', function (string $userId) {
    $this->getJson(route('public.user.show', ['user' => $userId]))
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertExactJson(['data' => null, 'meta' => ['message' => 'User not found']]);
})->with([
    'invalid UUID' => 'not-a-uuid',
    'user code instead of UUID' => 'USER-000001',
    'unknown UUID' => '01950000-0000-7000-8000-000000000001',
]);

test('public user detail returns 404 when a query parameter attempts to replace an invalid route UUID', function () {
    $user = User::registerUser([
        'full_name' => 'Requested User',
        'email' => 'requested@example.com',
        'password' => 'TestPassword123!',
    ]);

    $this->getJson(route('public.user.show', ['user' => 'invalid']).'?user='.$user->id)
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertExactJson(['data' => null, 'meta' => ['message' => 'User not found']]);
});

test('administrator can access the paginated user list', function () {
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ]);
    $admin->role = RoleEnum::ADMIN->value;
    $admin->save();

    $this->actingAs($admin, 'web')->getJson('/api/admin/users')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $admin->id)
        ->assertJsonPath('data.0.type', 'users')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.message', 'Get all users successfully')
        ->assertExactJsonStructure([
            'data' => ['*' => [
                'id', 'type',
                'attributes' => [
                    'full_name', 'email', 'user_code', 'role',
                    'email_verified_at', 'created_at', 'updated_at',
                ],
            ]],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => [
                'current_page', 'from', 'last_page', 'links',
                'path', 'per_page', 'to', 'total', 'message',
            ],
        ]);
});

test('non administrator roles receive 403 even when claiming an administrator role in the request', function (RoleEnum $role) {
    $user = User::registerUser([
        'full_name' => 'Restricted User',
        'email' => 'restricted@example.com',
        'password' => 'TestPassword123!',
    ]);
    $user->role = $role->value;
    $user->save();

    $this->actingAs($user, 'web')->getJson(route('admin.users', [
        'role' => RoleEnum::ADMIN->value,
        'user_role' => RoleEnum::ADMIN->value,
    ]), ['X-Role' => RoleEnum::ADMIN->value])
        ->assertForbidden()
        ->assertExactJson(['message' => 'You do not have permission to perform this action']);

    $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
})->with([RoleEnum::USER, RoleEnum::EDITOR]);

test('guest receives 401 instead of the role authorization error', function () {
    $this->getJson('/api/admin/users')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unable to authenticate user']);
});

test('administrator can also access the shared authenticated profile', function () {
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ]);
    $admin->role = RoleEnum::ADMIN->value;
    $admin->save();

    $this->actingAs($admin, 'web')->getJson('/api/me')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('data.id', $admin->id)
        ->assertJsonPath('data.type', 'users')
        ->assertExactJsonStructure([
            'data' => [
                'id', 'type',
                'attributes' => [
                    'full_name', 'email', 'user_code', 'role',
                    'email_verified_at', 'created_at', 'updated_at',
                ],
            ],
        ]);
});

test('profile sparse fields return requested public attributes without exposing credentials', function () {
    $user = User::registerUser([
        'full_name' => 'Existing User',
        'email' => 'existing@example.com',
        'password' => 'TestPassword123!',
    ]);
    $user->remember_token = 'private-remember-token';
    $user->save();

    $this->actingAs($user, 'web')
        ->getJson('/api/me?fields[users]=email,password,remember_token')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertExactJson([
            'data' => [
                'id' => $user->id,
                'type' => 'users',
                'attributes' => ['email' => 'existing@example.com'],
            ],
        ]);
});

test('guest receives 401 for the shared authenticated profile without an accept header', function () {
    $this->get('/api/me')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unable to authenticate user']);
});

test('user pagination links preserve requested sparse fields', function () {
    $admin = User::registerUser([
        'full_name' => 'Administrator',
        'email' => 'administrator@example.com',
        'password' => 'TestPassword123!',
    ], RoleEnum::ADMIN);

    $response = $this->actingAs($admin, 'web')->getJson('/api/admin/users?fields[users]=full_name')
        ->assertOk()
        ->assertJsonPath('data.0.attributes', ['full_name' => 'Administrator']);

    parse_str(parse_url($response->json('links.first'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['fields' => ['users' => 'full_name'], 'page' => '1']);
});
