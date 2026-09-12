<?php

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ImageCompressController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureUserHasRole;
use App\RoleEnum;
use Illuminate\Support\Facades\Route;

// Public API
Route::prefix('public')->name('public.')->group(function (): void {
    /** Add public API routes here. */

    // article routes
    Route::get('/articles', [ArticleController::class, 'allArticles'])
        ->name('articles.index');
});

// API routes requiring authentication
Route::middleware('auth:sanctum')->group(function (): void {

    // Shared by all authenticated users
    Route::get('/me', [UserController::class, 'me'])
        ->name('user.show');

    Route::prefix('images')->name('images.')->group(function (): void {
        Route::post('/', [ImageCompressController::class, 'uploadSingle'])
            ->middleware('throttle:image-uploads')->name('upload-single');
        Route::post('/batch', [ImageCompressController::class, 'uploadMultiple'])
            ->middleware('throttle:image-uploads')->name('upload-multiple');
        Route::get('/', [ImageCompressController::class, 'showImage'])->name('show');
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

            Route::post('/categories', [CategoryController::class, 'createCategory'])
                ->middleware('throttle:image-uploads')
                ->name('categories.create');

            // article routes
            Route::post('/articles', [ArticleController::class, 'createArticle'])
                ->middleware('throttle:image-uploads')
                ->name('articles.create');
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

            // article routes
            Route::post('/articles', [ArticleController::class, 'createArticle'])
                ->middleware('throttle:image-uploads')
                ->name('articles.create');
        });
});
