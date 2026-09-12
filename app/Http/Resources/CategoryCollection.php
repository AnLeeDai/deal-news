<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;

class CategoryCollection extends AnonymousResourceCollection
{
    public function __construct(mixed $resource)
    {
        parent::__construct($resource, CategoryResource::class);

        $this->preserveQuery();
        $this->additional(['meta' => ['message' => 'Categories retrieved successfully']]);
    }
}
