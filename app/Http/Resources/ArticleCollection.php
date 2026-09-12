<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;

class ArticleCollection extends AnonymousResourceCollection
{
    public function __construct(mixed $resource)
    {
        parent::__construct($resource, ArticleResource::class);

        $this->preserveQuery();
        $this->additional(['meta' => ['message' => 'Articles retrieved successfully']]);
    }
}
