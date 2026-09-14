<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait HasNotFoundResponse
{
    public function resolveResourceData(Request $request): ?array
    {
        return $this->resource === null ? null : parent::resolveResourceData($request);
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        parent::withResponse($request, $response);

        if ($this->resource === null) {
            $response->setStatusCode(404);
        }
    }
}
