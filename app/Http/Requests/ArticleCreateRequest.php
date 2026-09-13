<?php

namespace App\Http\Requests;

use App\Models\Articles;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class ArticleCreateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = parent::validationData();
        $additionalImages = $this->file('additional_images');

        if ($additionalImages instanceof UploadedFile) {
            $data['additional_images'] = [$additionalImages];
        }

        return $data;
    }

    public function articleSlug(): string
    {
        return Str::slug($this->string('title')->toString());
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('title')) {
                return;
            }

            if (Articles::query()->where('slug', $this->articleSlug())->exists()) {
                $validator->errors()->add('title', 'The title generates a URL slug that already exists.');
            }
        }];
    }

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
        $thumbnailRules = $this->hasFile('thumbnail')
            ? ['nullable', ...ImageUploadRequest::imageFileRules()]
            : ['nullable', 'string', 'max:255'];

        $additionalImageRules = $this->hasFile('additional_images')
            ? ['nullable', ...ImageUploadRequest::imageFileRules()]
            : ['nullable', 'string', 'max:255'];

        return [
            'title' => ['required', 'string', 'max:255', 'unique:articles,title'],
            'content' => ['required', 'string'],
            'category_id' => ['required', 'uuid', 'exists:categories,id'],
            'thumbnail' => $thumbnailRules,
            'additional_images' => ['nullable', 'array', 'list'],
            'additional_images.*' => $additionalImageRules,
            'slug' => ['prohibited'],
            'user_id' => ['required', 'uuid', 'exists:users,id'],
        ];
    }
}
