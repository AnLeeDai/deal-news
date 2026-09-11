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
        ->assertJsonStructure(['data', 'links', 'meta', 'message']);
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
        ->assertExactJson(['message' => 'Bạn không có quyền thực hiện thao tác này']);

    $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
})->with([RoleEnum::USER, RoleEnum::EDITOR]);

test('guest receives 401 instead of the role authorization error', function () {
    $this->getJson('/api/admin/users')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Không thể xác minh người dùng']);
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
        ->assertJsonPath('data.id', $admin->id);
});

test('guest receives 401 for the shared authenticated profile without an accept header', function () {
    $this->get('/api/me')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Không thể xác minh người dùng']);
});
