<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthResource extends JsonResource
{
    /** @return array{user_code: string, user_role: string} */
    public function toArray(Request $request): array
    {
        return [
            'user_code' => $this->user_code,
            'user_role' => $this->role,
        ];
    }
}
