<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArticleCreateRequest;
use App\Http\Requests\ArticleSlugParamQueryRequest;
use App\Http\Resources\ArticleResource;
use App\Models\Articles;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Throwable;

class ArticleController
{
    public function __construct(
        private Articles $articlesModel,
        private ImageCompressController $imageCompressController,
    ) {}

    public function createArticle(ArticleCreateRequest $request): ArticleResource
    {
        $attributes = $request->validated();
        $thumbnail = $attributes['thumbnail'] ?? null;
        $additionalImages = $attributes['additional_images'] ?? [];
        $attributes['slug'] = $request->articleSlug();

        $uploadedThumbnailPath = null;
        $uploadedAdditionalImagesPaths = [];
        $ownerId = (string) $request->user()->getAuthIdentifier();

        try {
            if ($thumbnail instanceof UploadedFile) {
                $uploaded = $this->imageCompressController->uploadSingleImage($thumbnail, $ownerId, 'thumbnail');
                $uploadedThumbnailPath = $uploaded['path'];
                $attributes['thumbnail'] = $uploadedThumbnailPath;
            }

            if ($additionalImages !== [] && $additionalImages[0] instanceof UploadedFile) {
                $uploadedAdditionalImages = $this->imageCompressController->uploadMultipleImages($additionalImages, $ownerId, 'additional_images');
                $uploadedAdditionalImagesPaths = array_column($uploadedAdditionalImages, 'path');
                $attributes['additional_images'] = $uploadedAdditionalImagesPaths;
            }

            $article = $this->articlesModel->create($attributes);
        } catch (Throwable $exception) {
            if ($uploadedThumbnailPath !== null) {
                try {
                    $this->imageCompressController->deleteStoredImages([$uploadedThumbnailPath]);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            if ($uploadedAdditionalImagesPaths !== []) {
                try {
                    $this->imageCompressController->deleteStoredImages($uploadedAdditionalImagesPaths);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }

        return ArticleResource::created($article);
    }

    public function allArticles(): AnonymousResourceCollection
    {
        return ArticleResource::collection(
            $this->articlesModel
                ->with([
                    'category' => fn ($query) => $query->withCount('articles'),
                    'user',
                ])
                ->orderByDesc('created_at')
                ->paginate(10)
        )
            ->preserveQuery()
            ->additional([
                'meta' => [
                    'message' => 'Articles retrieved successfully',
                ],
            ]);
    }

    public function findArticleBySlug(ArticleSlugParamQueryRequest $request): ArticleResource
    {
        $slug = $request->validated()['slug'];
        $result = $this->articlesModel->where('slug', $slug)->first();

        if (! $result) {
            return ArticleResource::notFound();
        }

        return new ArticleResource($result);
    }
}
