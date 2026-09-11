<?php

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Hash;

uses(TestCase::class);

test('migrations create a verified administrator after the code sequence table exists', function () {
    config([
        'adminconfig.admin-email' => 'bootstrap-admin@example.com',
        'adminconfig.admin-name' => 'Bootstrap Admin',
        'adminconfig.admin-password' => 'TestPassword123!',
        'adminconfig.admin-role' => 'admin',
    ]);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $admin = User::where('email', 'bootstrap-admin@example.com')->sole();

    expect($admin->id)->toBeUuid();
    expect($admin->role)->toBe('administrator');
    expect($admin->user_code)->toBe('administrator-000001');
    expect($admin->email_verified_at)->not->toBeNull();
    expect(Hash::check('TestPassword123!', $admin->password))->toBeTrue();

    $this->assertDatabaseHas('user_code_sequences', [
        'role' => 'administrator',
        'last_number' => 1,
    ]);
});
