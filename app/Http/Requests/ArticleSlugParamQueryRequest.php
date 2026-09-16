<?php

namespace App\Http\Requests;

use App\Http\Resources\ArticleResource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ArticleSlugParamQueryRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array{slug: mixed} */
    public function validationData(): array
    {
        return ['slug' => $this->route('slug')];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(ArticleResource::notFound()->response($this));
    }
}
