<?php

use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureUserHasRole;
use App\RoleEnum;
use Illuminate\Support\Facades\Route;

// API công khai
Route::prefix('public')->name('public.')->group(function (): void {
    /** Thêm API công khai tại đây. */
});

// API yêu cầu đăng nhập
Route::middleware('auth:sanctum')->group(function (): void {

    // Chung cho mọi user đã đăng nhập
    Route::get('/me', [UserController::class, 'me'])
        ->name('user.show');

    // Admin
    Route::prefix('admin')
        ->middleware(EnsureUserHasRole::class.':'.RoleEnum::ADMIN->value)
        ->name('admin.')
        ->group(function (): void {

            Route::get('/users', [UserController::class, 'allUsers'])
                ->name('users');
        });

    // User
    Route::prefix('user')
        ->middleware(EnsureUserHasRole::class.':'.RoleEnum::USER->value)
        ->name('user.')
        ->group(function (): void {

            // User routes
        });

    // Editor
    Route::prefix('editor')
        ->middleware(EnsureUserHasRole::class.':'.RoleEnum::EDITOR->value)
        ->name('editor.')
        ->group(function (): void {

            // Editor routes
        });
});
