<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('registration returns 201 with a wrapped auth resource and authenticates the user', function () {
    $response = $this->postJson('/api/sign-up', [
        'full_name' => 'New User',
        'email' => 'new-user@example.com',
        'password' => 'TestPassword123!',
        'password_confirmation' => 'TestPassword123!',
    ]);

    $response->assertCreated()->assertExactJson([
        'data' => ['user_code' => 'user-000001', 'user_role' => 'user'],
        'message' => 'Registered successfully',
    ]);
    $this->assertDatabaseHas('users', [
        'email' => 'new-user@example.com',
        'user_code' => 'user-000001',
        'role' => 'user',
    ]);
    $this->assertAuthenticatedAs(User::where('email', 'new-user@example.com')->sole(), 'web');
});

test('login returns 200 with a wrapped auth resource and authenticates the user', function () {
    $user = User::registerUser([
        'full_name' => 'Existing User',
        'email' => 'existing@example.com',
        'password' => 'TestPassword123!',
    ]);

    $this->postJson('/api/sign-in', [
        'email' => 'existing@example.com',
        'password' => 'TestPassword123!',
    ])->assertOk()->assertExactJson([
        'data' => ['user_code' => $user->user_code, 'user_role' => 'user'],
        'message' => 'Logged in successfully',
    ]);

    $this->assertAuthenticatedAs($user, 'web');
});

test('logout returns 200 with a wrapped auth resource and ends the session', function () {
    $user = User::registerUser([
        'full_name' => 'Existing User',
        'email' => 'existing@example.com',
        'password' => 'TestPassword123!',
    ]);

    $this->actingAs($user, 'web')->postJson('/api/sign-out')
        ->assertOk()->assertExactJson([
            'data' => ['user_code' => $user->user_code, 'user_role' => 'user'],
            'message' => 'Logged out successfully',
        ]);

    $this->assertGuest('web');
});

test('invalid login returns 422 without an auth resource or authenticated session', function () {
    $this->postJson('/api/sign-in', [
        'email' => 'unknown@example.com',
        'password' => 'TestPassword123!',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email'])
        ->assertJsonMissingPath('data');

    $this->assertGuest('web');
});

test('registration with an administrator role returns 422 without creating a user', function () {
    $this->postJson('/api/sign-up', [
        'full_name' => 'New User',
        'email' => 'new-user@example.com',
        'password' => 'TestPassword123!',
        'password_confirmation' => 'TestPassword123!',
        'role' => 'administrator',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['role'])
        ->assertJsonMissingPath('data');

    $this->assertDatabaseEmpty('users');
    $this->assertGuest('web');
});

test('guest logout returns 401 without an auth resource', function () {
    $this->postJson('/api/sign-out')->assertUnauthorized()
        ->assertExactJson(['message' => 'Unable to authenticate user']);
});
