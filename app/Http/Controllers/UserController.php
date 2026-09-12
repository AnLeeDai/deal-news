<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserController
{
    public function __construct(
        private User $userModel
    ) {}

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function allUsers(): UserCollection
    {
        return new UserCollection($this->userModel->paginate(10));
    }
}
