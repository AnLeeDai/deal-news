<?php

namespace App\Http\Requests;

use App\Http\Resources\ArticleResource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ArticaleParamQueryRequest extends FormRequest
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
            'article' => ['required', 'uuid'],
        ];
    }

    public function validationData(): array
    {
        return ['article' => $this->route('article')];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(ArticleResource::notFound()->response($this));
    }
}
