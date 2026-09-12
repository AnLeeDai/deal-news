<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CategoryCreateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:255',
                'unique:categories,name',
            ],

            'slug' => [
                'prohibited',
            ],

            'thumbnail' => $this->hasFile('thumbnail')
                ? ['nullable', ...ImageUploadRequest::imageFileRules()]
                : ['nullable', 'string', 'max:255'],

            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}
