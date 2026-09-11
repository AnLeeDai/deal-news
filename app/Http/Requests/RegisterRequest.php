<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends VerifyUserRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $rules = parent::rules();

        return [
            ...$rules,
            'email' => [...$rules['email'], 'unique:users,email'],
            'password' => [
                ...$rules['password'],
                'min:8',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'full_name' => ['required', 'string', 'max:255'],
            'role' => ['prohibited'],
            'user_code' => ['prohibited'],
            'id' => ['prohibited'],
        ];
    }
}
