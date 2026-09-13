<?php

namespace App;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImageCompressor
{
    private const MAX_BYTES = 20 * 1024;

    /**
     * @return array{contents: string, width: int, height: int}
     */
    public function compressImage(UploadedFile $image, string $field): array
    {
        if (! function_exists('imagewebp') || ! function_exists('exif_read_data')) {
            throw new RuntimeException('Image processing requires GD with WebP support and EXIF.');
        }

        $source = match ($image->getMimeType()) {
            'image/jpeg' => @imagecreatefromjpeg($image->getPathname()),
            'image/png' => @imagecreatefrompng($image->getPathname()),
            'image/webp' => @imagecreatefromwebp($image->getPathname()),
            default => false,
        };

        if (! $source instanceof GdImage) {
            throw ValidationException::withMessages([$field => 'The image is damaged or cannot be decoded.']);
        }

        $source = $this->resize($source, min(1, 1600 / max(imagesx($source), imagesy($source))));
        $source = $this->orient($source, $image);

        for ($attempt = 0; $attempt < 16; $attempt++) {
            foreach ([82, 65, 50] as $quality) {
                $contents = $this->convertToWebp($source, $quality);

                if (strlen($contents) <= self::MAX_BYTES) {
                    return [
                        'contents' => $contents,
                        'width' => imagesx($source),
                        'height' => imagesy($source),
                    ];
                }
            }

            $source = $this->resize($source, 0.75);
        }

        throw ValidationException::withMessages([$field => 'The image cannot be compressed to 20 KB.']);
    }

    private function resize(GdImage $source, float $scale): GdImage
    {
        $width = max(1, (int) floor(imagesx($source) * $scale));
        $height = max(1, (int) floor(imagesy($source) * $scale));
        $resized = imagecreatetruecolor($width, $height);

        if (! $resized instanceof GdImage) {
            throw new RuntimeException('Unable to allocate the resized image.');
        }

        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        if (! imagecopyresampled($resized, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source))) {
            throw new RuntimeException('Unable to resize the image.');
        }

        return $resized;
    }

    private function orient(GdImage $source, UploadedFile $image): GdImage
    {
        if ($image->getMimeType() !== 'image/jpeg') {
            return $source;
        }

        $exif = @exif_read_data($image->getPathname(), 'IFD0');
        $orientation = (int) ($exif['Orientation'] ?? 1);

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($source, IMG_FLIP_HORIZONTAL);
        }

        $angle = match ($orientation) {
            3, 4 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $source;
        }

        $rotated = imagerotate($source, $angle, 0);

        if (! $rotated instanceof GdImage) {
            throw new RuntimeException('Unable to orient the image.');
        }

        return $rotated;
    }

    private function convertToWebp(GdImage $image, int $quality): string
    {
        ob_start();

        try {
            $encoded = imagewebp($image, null, $quality);
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        if (! $encoded || ! is_string($contents) || $contents === '') {
            throw new RuntimeException('Unable to encode the image as WebP.');
        }

        return $contents;
    }
}
