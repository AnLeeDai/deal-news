<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryCreateRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\UploadedFile;
use Throwable;

class CategoryController
{
    public function __construct(
        private Category $categoryModel,
        private ImageCompressController $images,
    ) {}

    public function newCategory(CategoryCreateRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $thumbnail = $attributes['thumbnail'] ?? null;
        $uploadedPath = null;

        if ($thumbnail instanceof UploadedFile) {
            $uploaded = $this->images->storeImage($thumbnail, (string) $request->user()->getAuthIdentifier(), 'thumbnail');
            $uploadedPath = $uploaded['path'];
            $attributes['thumbnail'] = $uploadedPath;
        }

        try {
            $category = $this->categoryModel->createCategory($attributes);
        } catch (Throwable $exception) {
            if ($uploadedPath !== null) {
                try {
                    $this->images->deleteStoredImages([$uploadedPath]);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }

        return (new CategoryResource($category))
            ->additional(['message' => 'Category created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function allCategories(): ResourceCollection
    {
        return $this->categoryModel->paginate(10)->toResourceCollection()
            ->additional([
                'message' => 'Categories retrieved successfully',
            ]);
    }
}
