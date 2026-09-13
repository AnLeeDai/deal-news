<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImagePathRequest;
use App\Http\Requests\ImageUploadRequest;
use App\Http\Resources\ImageResource;
use App\ImageCompressor;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ImageCompressController
{
    public function __construct(private ImageCompressor $compressor) {}

    public function uploadSingle(ImageUploadRequest $request): ImageResource
    {
        return ImageResource::uploaded($this->uploadSingleImage(
            $request->validated('image'),
            (string) $request->user()->getAuthIdentifier(),
        ));
    }

    public function uploadMultiple(ImageUploadRequest $request): JsonResponse
    {
        return ImageResource::collection($this->uploadMultipleImages(
            $request->validated('images'),
            (string) $request->user()->getAuthIdentifier(),
            'images',
        ))->additional(['meta' => ['message' => 'Images uploaded successfully']])
            ->toResponse($request)
            ->setStatusCode(201);
    }

    /**
     * Upload one validated image through the shared image pipeline.
     *
     * @return array{path: string, url: string, size: int, width: int, height: int, expires_at: ?string}
     */
    public function uploadSingleImage(UploadedFile $image, string $ownerId, string $field = 'image'): array
    {
        return $this->storeImages([$image], $ownerId, $field, false)[0];
    }

    /**
     * Upload multiple validated images through the shared image pipeline.
     *
     * @param  list<UploadedFile>  $images
     * @return list<array{path: string, url: string, size: int, width: int, height: int, expires_at: ?string}>
     */
    public function uploadMultipleImages(array $images, string $ownerId, string $field = 'images'): array
    {
        return $this->storeImages($images, $ownerId, $field, true);
    }

    /**
     * Delete server-generated paths after authorization or during upload rollback.
     *
     * @param  list<string>  $paths
     */
    public function deleteStoredImages(array $paths): void
    {
        if (! $this->storage()->delete($paths)) {
            throw new RuntimeException('Unable to delete the stored images.');
        }
    }

    public function getImageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $path)) {
            return $path;
        }

        return $this->imageUrlDetails($this->storage(), $path)['url'];
    }

    public function showImage(ImagePathRequest $request): ImageResource|JsonResponse
    {
        $path = $this->ownedPath($request);

        try {
            $disk = $this->storage();
            $exists = $disk->exists($path);
            $contents = $exists ? $disk->get($path) : null;

            if ($exists && ! is_string($contents)) {
                throw new RuntimeException('Unable to read the stored image.');
            }

            $image = $exists ? $this->imageDetails($disk, $path, $contents) : null;
        } catch (Throwable $exception) {
            return $this->storageFailure($exception);
        }

        abort_if($image === null, 404, 'Image not found.');

        return new ImageResource($image);
    }

    public function deleteImage(ImagePathRequest $request): JsonResponse
    {
        $path = $this->ownedPath($request);

        try {
            $this->deleteStoredImages([$path]);
        } catch (Throwable $exception) {
            return $this->storageFailure($exception);
        }

        return response()->json(['message' => 'Image deleted successfully']);
    }

    /**
     * @param  list<UploadedFile>  $uploads
     * @return list<array{path: string, url: string, size: int, width: int, height: int, expires_at: ?string}>
     */
    private function storeImages(array $uploads, string $ownerId, string $field, bool $multiple): array
    {
        $attemptedPaths = [];
        $disk = null;

        try {
            $compressedImages = [];

            foreach ($uploads as $index => $upload) {
                $compressedImages[] = $this->compressor->compressImage($upload, $multiple ? $field.'.'.$index : $field);
            }

            $disk = $this->storage();
            $images = [];

            foreach ($compressedImages as $compressed) {
                $path = 'images/'.$ownerId.'/'.Str::uuid().'.webp';
                $attemptedPaths[] = $path;

                if (! $disk->put($path, $compressed['contents'], [
                    'ContentType' => 'image/webp',
                    'CacheControl' => 'public, max-age=31536000, immutable',
                ])) {
                    throw new RuntimeException('Unable to store the processed image.');
                }

                $images[] = $this->imageDetails($disk, $path, $compressed['contents']);
            }

            return $images;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($disk !== null && $attemptedPaths !== []) {
                try {
                    if (! $disk->delete($attemptedPaths)) {
                        throw new RuntimeException('Unable to clean up the failed image upload.');
                    }
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw new HttpResponseException($this->storageFailure($exception));
        }
    }

    private function ownedPath(ImagePathRequest $request): string
    {
        $path = $request->validated('path');

        abort_unless(explode('/', $path)[1] === (string) $request->user()->getAuthIdentifier(), 404, 'Image not found.');

        return $path;
    }

    private function storage(): FilesystemAdapter
    {
        return Storage::disk(config('images.disk'));
    }

    /**
     * @return array{path: string, url: string, size: int, width: int, height: int, expires_at: ?string}
     */
    private function imageDetails(FilesystemAdapter $disk, string $path, string $contents): array
    {
        $dimensions = @getimagesizefromstring($contents);

        if ($dimensions === false || $dimensions[2] !== IMAGETYPE_WEBP) {
            throw new RuntimeException('The stored image is not a valid WebP image.');
        }

        $urlDetails = $this->imageUrlDetails($disk, $path);

        return [
            'path' => $path,
            'url' => $urlDetails['url'],
            'size' => strlen($contents),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'expires_at' => $urlDetails['expires_at'],
        ];
    }

    /**
     * @return array{url: string, expires_at: ?string}
     */
    private function imageUrlDetails(FilesystemAdapter $disk, string $path): array
    {
        if (($disk->getConfig()['driver'] ?? null) === 's3' && empty($disk->getConfig()['url'])) {
            $expiresAt = now()->addHour();

            return [
                'url' => $disk->temporaryUrl($path, $expiresAt),
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        }

        return ['url' => $disk->url($path), 'expires_at' => null];
    }

    private function storageFailure(Throwable $exception): JsonResponse
    {
        report($exception);

        return response()->json(['message' => 'Image processing or storage is temporarily unavailable. Please try again.'], 503);
    }
}
