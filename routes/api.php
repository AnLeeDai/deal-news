<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ImageCompressController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureUserHasRole;
use App\RoleEnum;
use Illuminate\Support\Facades\Route;

// Public API
Route::prefix('public')->name('public.')->group(function (): void {
    /** Add public API routes here. */
});

// API routes requiring authentication
Route::middleware('auth:sanctum')->group(function (): void {

    // Shared by all authenticated users
    Route::get('/me', [UserController::class, 'me'])
        ->name('user.show');

    Route::prefix('images')->name('images.')->group(function (): void {
        Route::post('/', [ImageCompressController::class, 'uploadSingleImage'])
            ->middleware('throttle:image-uploads')->name('upload-single');
        Route::post('/batch', [ImageCompressController::class, 'uploadMultipleImages'])
            ->middleware('throttle:image-uploads')->name('upload-multiple');
        Route::get('/', [ImageCompressController::class, 'getImageUrl'])->name('show');
        Route::delete('/', [ImageCompressController::class, 'deleteImage'])->name('delete');
    });

    // Admin
    Route::prefix('admin')
        ->middleware(EnsureUserHasRole::class.':'.RoleEnum::ADMIN->value)
        ->name('admin.')
        ->group(function (): void {

            Route::get('/users', [UserController::class, 'allUsers'])
                ->name('users');

            // category routes
            Route::get('/categories', [CategoryController::class, 'allCategories'])
                ->name('categories.index');

            Route::post('/categories', [CategoryController::class, 'newCategory'])
                ->middleware('throttle:image-uploads')
                ->name('categories.create');
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
