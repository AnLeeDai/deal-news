<?php

namespace App\Http\Requests;

use App\Http\Resources\CategoryResource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CategoryParamQueryRequest extends FormRequest
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
            'category' => ['required', 'uuid'],
        ];
    }

    /** @return array{category: mixed} */
    public function validationData(): array
    {
        return ['category' => $this->route('category')];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(CategoryResource::notFound()->response($this));
    }
}
