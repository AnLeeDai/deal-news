<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class ImageResource extends JsonApiResource
{
    private bool $uploaded = false;

    /**
     * @param  array{path: string, url: string, size: int, width: int, height: int, expires_at: ?string}  $image
     */
    public static function uploaded(array $image): self
    {
        $resource = new self($image);
        $resource->uploaded = true;

        return $resource->additional(['meta' => ['message' => 'Images uploaded successfully']]);
    }

    public function toId(Request $request): string
    {
        return $this->resource['path'];
    }

    /**
     * Get the resource's attributes.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
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

    public function withResponse(Request $request, JsonResponse $response): void
    {
        parent::withResponse($request, $response);

        if ($this->uploaded) {
            $response->setStatusCode(201);
        }
    }
}
