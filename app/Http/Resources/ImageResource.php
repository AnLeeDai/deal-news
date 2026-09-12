<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'path' => $this->resource['path'],
            'url' => $this->resource['url'],
            'mime_type' => 'image/webp',
            'size' => $this->resource['size'],
            'width' => $this->resource['width'],
            'height' => $this->resource['height'],
            'expires_at' => $this->resource['expires_at'],
        ];
    }
}
