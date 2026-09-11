<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class UserController
{
    public function __construct(
        private User $userModel
    ) {}

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function allUsers(): ResourceCollection
    {
        $users = $this->userModel->paginate(10)->toResourceCollection();

        return UserResource::allUsers($users);
    }
}
