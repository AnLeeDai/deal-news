<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryCreateRequest;
use App\Http\Requests\CategoryParamQueryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

class CategoryController
{
    public function __construct(
        private Category $categoryModel,
        private ImageCompressController $imageCompressController,
    ) {}

    public function createCategory(CategoryCreateRequest $request): CategoryResource
    {
        $attributes = $request->validated();
        $attributes['slug'] = Str::slug($attributes['name']);
        $thumbnail = $attributes['thumbnail'] ?? null;
        $uploadedPath = null;

        if ($thumbnail instanceof UploadedFile) {
            $uploaded = $this->imageCompressController->uploadSingleImage($thumbnail, (string) $request->user()->getAuthIdentifier(), 'thumbnail');
            $uploadedPath = $uploaded['path'];
            $attributes['thumbnail'] = $uploadedPath;
        }

        try {
            $category = $this->categoryModel->create($attributes)->refresh();
        } catch (Throwable $exception) {
            if ($uploadedPath !== null) {
                try {
                    $this->imageCompressController->deleteStoredImages([$uploadedPath]);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }

        return CategoryResource::created($category);
    }

    public function allCategories(): AnonymousResourceCollection
    {
        return CategoryResource::collection($this->categoryModel->withCount('articles')->paginate(10))
            ->preserveQuery()
            ->additional(['meta' => ['message' => 'Categories retrieved successfully']]);
    }

    public function findCategoryById(CategoryParamQueryRequest $request): array|CategoryResource
    {
        $category = $request->validated()['category'];
        $result = $this->categoryModel->find($category);

        if (! $result) {
            return CategoryResource::notFound();
        }

        return CategoryResource::toCategoryNameAttributes($result);
    }
}
