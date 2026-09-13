<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;

class UserController
{
    public function __construct(
        private User $userModel
    ) {}

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function allUsers(): AnonymousResourceCollection
    {
        return UserResource::collection($this->userModel->paginate(10))
            ->preserveQuery()
            ->additional(['meta' => ['message' => 'Get all users successfully']]);
    }
}
