<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;

class ImageCollection extends AnonymousResourceCollection
{
    private bool $uploaded = false;

    /**
     * @param  list<array{path: string, url: string, size: int, width: int, height: int, expires_at: ?string}>  $images
     */
    public function __construct(array $images)
    {
        parent::__construct($images, ImageResource::class);
    }

    /**
     * @param  list<array{path: string, url: string, size: int, width: int, height: int, expires_at: ?string}>  $images
     */
    public static function uploaded(array $images): self
    {
        $resource = new self($images);
        $resource->uploaded = true;

        return $resource->additional(['meta' => ['message' => 'Images uploaded successfully']]);
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        parent::withResponse($request, $response);

        if ($this->uploaded) {
            $response->setStatusCode(201);
        }
    }
}
