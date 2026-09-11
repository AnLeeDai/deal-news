<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Http\Requests\VerifyUserRequest;
use App\Http\Resources\AuthResource;
use App\Models\User;
use App\RoleEnum;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function __construct(
        private User $userModel
    ) {}

    public function userLogin(VerifyUserRequest $request): JsonResponse
    {
        if (! Auth::guard('web')->attempt($request->validated())) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return AuthResource::login(Auth::guard('web')->user());
    }

    public function userRegister(RegisterRequest $request): JsonResponse
    {
        $user = $this->userModel->registerUser(
            $request->safe()->only([
                'full_name',
                'email',
                'password',
            ]),
            RoleEnum::USER
        );

        event(new Registered($user));
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return AuthResource::register($user);
    }

    public function userLogout(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return AuthResource::logout($user);
    }
}
