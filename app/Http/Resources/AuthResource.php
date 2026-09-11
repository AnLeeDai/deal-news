<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthResource extends JsonResource
{
    public static function login(User $userModal): JsonResponse
    {
        return response()->json([
            'user_code' => $userModal->user_code,
            'user_role' => $userModal->role,
            'message' => 'Đăng nhập thành công',
        ]);
    }

    public static function register(User $userModal): JsonResponse
    {
        return response()->json([
            'user_code' => $userModal->user_code,
            'user_role' => $userModal->role,
            'message' => 'Đăng ký thành công',
        ], 201);
    }

    public static function logout(User $userModal): JsonResponse
    {
        return response()->json([
            'user_code' => $userModal->user_code,
            'user_role' => $userModal->role,
            'message' => 'Đăng xuất thành công',
        ]);
    }
}
