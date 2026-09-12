<?php

namespace App\Http\Resources;

use App\Http\Controllers\ImageCompressController;
use App\Models\Articles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class ArticleResource extends JsonApiResource
{
    public static function created(Articles $article): self
    {
        return (new self($article))->additional(['meta' => ['message' => 'Article created successfully']]);
    }

    public function toAttributes(Request $request): array
    {
        return [
            'title' => $this->title,
            'content' => $this->content,
            'thumbnail' => $this->thumbnail,
            'thumbnail_url' => fn (): ?string => $this->imageUrl($this->thumbnail),
            'additional_images' => $this->additional_images,
            'additional_images_urls' => fn (): ?array => $this->additionalImageUrls(),
            'slug' => $this->slug,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    public function toRelationships(Request $request): array
    {
        return [
            'category' => CategoryResource::class,
            'user' => AuthResource::class,
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        return app(ImageCompressController::class)->getImageUrl($path);
    }

    private function additionalImageUrls(): ?array
    {
        if ($this->additional_images === null) {
            return null;
        }

        return array_map(fn (string $path): ?string => $this->imageUrl($path), $this->additional_images);
    }
}
