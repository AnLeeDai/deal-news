<?php

namespace App\Http\Resources;

use App\Http\Controllers\ImageCompressController;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class CategoryResource extends JsonApiResource
{
    public static function created(Category $category): self
    {
        return (new self($category))->additional(['meta' => ['message' => 'Category created successfully']]);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'thumbnail' => $this->thumbnail,
            'thumbnail_url' => fn (): ?string => $this->thumbnailUrl(),
            'total_articles' => $this->resource->getAttribute('articles_count') ?? $this->total_articles,
            'description' => $this->description,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function thumbnailUrl(): ?string
    {
        return app(ImageCompressController::class)->getImageUrl($this->thumbnail);
    }
}
