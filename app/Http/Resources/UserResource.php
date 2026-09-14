<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class UserResource extends JsonApiResource
{
    use HasNotFoundResponse;

    public function toAttributes(Request $request): array
    {
        return [
            'full_name' => $this->full_name,
            'email' => $this->email,
            'user_code' => $this->user_code,
            'role' => $this->role,
            'email_verified_at' => $this->email_verified_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    public static function notFound(): self
    {
        return (new self(null))->additional(['meta' => ['message' => 'User not found']]);
    }

    public static function toUserCodeAttributes(User $user): array
    {
        return (new self($user))->resolve(new Request([
            'fields' => ['users' => 'user_code'],
        ]));
    }
}
