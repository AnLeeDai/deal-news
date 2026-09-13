<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class AuthResource extends JsonApiResource
{
    private int $responseStatus = 200;

    public static function registered(User $user): self
    {
        $resource = new self($user);
        $resource->responseStatus = 201;

        return $resource->additional(['meta' => ['message' => 'Registered successfully']]);
    }

    public static function loggedIn(User $user): self
    {
        return (new self($user))->additional(['meta' => ['message' => 'Logged in successfully']]);
    }

    public static function loggedOut(User $user): self
    {
        return (new self($user))->additional(['meta' => ['message' => 'Logged out successfully']]);
    }

    public function toType(Request $request): string
    {
        return 'users';
    }

    /** @return array{user_code: string, user_role: string} */
    public function toAttributes(Request $request): array
    {
        return [
            'user_code' => $this->user_code,
            'user_role' => $this->role,
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        parent::withResponse($request, $response);

        $response->setStatusCode($this->responseStatus);
    }
}
