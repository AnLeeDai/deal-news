<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ImageUploadRequest extends FormRequest
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
        $imageRules = ['required', ...self::imageFileRules()];

        if ($this->routeIs('images.upload-multiple')) {
            return [
                'image' => ['prohibited'],
                'images' => ['required', 'array', 'list', 'min:1', 'max:5'],
                'images.*' => $imageRules,
            ];
        }

        return ['image' => $imageRules, 'images' => ['prohibited']];
    }

    /**
     * @return list<string|Closure>
     */
    public static function imageFileRules(): array
    {
        return [
            'bail',
            'file',
            'max:30720',
            'mimes:jpg,jpeg,png,webp',
            'dimensions:max_width=8192,max_height=8192',
            static function (string $attribute, mixed $value, Closure $fail): void {
                $dimensions = getimagesize($value->getPathname());

                if ($dimensions[0] * $dimensions[1] > 16_000_000) {
                    $fail('Each image must not exceed 16 megapixels.');
                }
            },
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $multiple = $this->routeIs('images.upload-multiple');
            $images = $multiple ? $this->file('images') : [$this->file('image')];
            $totalBytes = 0;

            foreach ($images as $image) {
                $totalBytes += $image->getSize();
            }

            if ($totalBytes > 30 * 1024 * 1024) {
                $validator->errors()->add('images', 'The total upload size must not exceed 30 MB.');
            }
        }];
    }
}
