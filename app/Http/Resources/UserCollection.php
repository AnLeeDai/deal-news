<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;

class UserCollection extends AnonymousResourceCollection
{
    public function __construct(mixed $resource)
    {
        parent::__construct($resource, UserResource::class);

        $this->preserveQuery();
        $this->additional(['meta' => ['message' => 'Get all users successfully']]);
    }
}
