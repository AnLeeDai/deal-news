<?php

use App\Http\Controllers\AuthController;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Redirector $redirect) {
    return $redirect->to('/up');
});

Route::prefix('api')->group(function () {
    Route::post('/sign-up', [AuthController::class, 'userRegister'])->middleware('throttle:registration')->name('register');
    Route::post('/sign-in', [AuthController::class, 'userLogin'])->middleware('throttle:login')->name('login');
    Route::post('/sign-out', [AuthController::class, 'userLogout'])->middleware('auth:web')->name('logout');
});
