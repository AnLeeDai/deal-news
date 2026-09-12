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

    public function uploadSingleImage(ImageUploadRequest $request): JsonResponse
    {
        return $this->uploadImages($request, [$request->validated('image')], false);
    }

    public function uploadMultipleImages(ImageUploadRequest $request): JsonResponse
    {
        return $this->uploadImages($request, $request->validated('images'), true);
    }

    /**
     * Store a validated upload through the same pipeline as the image API.
     *
     * @return array{path: string, url: string, size: int, width: int, height: int, expires_at: ?string}
     */
    public function storeImage(UploadedFile $image, string $ownerId, string $field = 'image'): array
    {
        return $this->storeImages([$image], $ownerId, $field, false)[0];
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

    public function getImageUrl(ImagePathRequest $request): JsonResponse
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

        return (new ImageResource($image))->response();
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
     */
    private function uploadImages(ImageUploadRequest $request, array $uploads, bool $multiple): JsonResponse
    {
        $images = $this->storeImages($uploads, (string) $request->user()->getAuthIdentifier(), $multiple ? 'images' : 'image', $multiple);
        $resource = $multiple ? ImageResource::collection($images) : new ImageResource($images[0]);

        return $resource->additional(['message' => 'Images uploaded successfully'])
            ->response()->setStatusCode(201);
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

        $expiresAt = null;

        if (($disk->getConfig()['driver'] ?? null) === 's3' && empty($disk->getConfig()['url'])) {
            $expiresAt = now()->addHour();
            $url = $disk->temporaryUrl($path, $expiresAt);
        } else {
            $url = $disk->url($path);
        }

        return [
            'path' => $path,
            'url' => $url,
            'size' => strlen($contents),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }

    private function storageFailure(Throwable $exception): JsonResponse
    {
        report($exception);

        return response()->json(['message' => 'Image processing or storage is temporarily unavailable. Please try again.'], 503);
    }
}
