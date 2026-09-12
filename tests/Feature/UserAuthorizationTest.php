<?php

use App\Models\User;
use App\RoleEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => false]);
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
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $admin->id)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('message', 'Get all users successfully')
        ->assertExactJsonStructure([
            'data' => ['*' => [
                'id', 'full_name', 'email', 'user_code', 'role',
                'email_verified_at', 'created_at', 'updated_at',
            ]],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => [
                'current_page', 'from', 'last_page', 'links',
                'path', 'per_page', 'to', 'total',
            ],
            'message',
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
        ->assertJsonPath('data.id', $admin->id)
        ->assertExactJsonStructure([
            'data' => [
                'id', 'full_name', 'email', 'user_code', 'role',
                'email_verified_at', 'created_at', 'updated_at',
            ],
        ]);
});

test('guest receives 401 for the shared authenticated profile without an accept header', function () {
    $this->get('/api/me')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unable to authenticate user']);
});
