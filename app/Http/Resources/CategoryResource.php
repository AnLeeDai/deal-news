<?php

namespace App\Http\Resources;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'thumbnail' => $this->thumbnail,
            'thumbnail_url' => $this->thumbnailUrl(),
            'total_articles' => $this->total_articles,
            'description' => $this->description,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function thumbnailUrl(): ?string
    {
        if ($this->thumbnail === null || $this->thumbnail === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $this->thumbnail)) {
            return $this->thumbnail;
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(config('images.disk'));

        if (($disk->getConfig()['driver'] ?? null) === 's3' && empty($disk->getConfig()['url'])) {
            return $disk->temporaryUrl($this->thumbnail, now()->addHour());
        }

        return $disk->url($this->thumbnail);
    }
}
