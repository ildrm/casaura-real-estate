<?php

namespace App\Domain\Media;

use App\Domain\Listings\ListingException;
use GdImage;
use RuntimeException;

final class ImageDerivativeGenerator
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /** @return array{mime_type: string, width: int, height: int} */
    public function inspect(string $path): array
    {
        $details = @getimagesize($path);
        if ($details === false || ! isset($details['mime'], $details[0], $details[1])) {
            throw new ListingException('MEDIA_INVALID', 'The uploaded file is not a decodable image.');
        }
        $mime = (string) $details['mime'];
        $width = (int) $details[0];
        $height = (int) $details[1];
        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw new ListingException('MEDIA_TYPE_UNSUPPORTED', 'Only JPEG, PNG, and WebP images are supported.');
        }
        if ($width <= 0 || $height <= 0 || $width * $height > 40_000_000) {
            throw new ListingException('MEDIA_DIMENSIONS_INVALID', 'The image dimensions exceed the safe processing limit.');
        }

        return ['mime_type' => $mime, 'width' => $width, 'height' => $height];
    }

    /** @return array{path: string, width: int, height: int, mime_type: string} */
    public function generateWebp(string $sourcePath, string $mime, int $maxWidth): array
    {
        $source = $this->decode($sourcePath, $mime);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $width = min($maxWidth, $sourceWidth);
        $height = max(1, (int) round($sourceHeight * ($width / $sourceWidth)));
        $target = imagecreatetruecolor($width, $height);
        if (! $target) {
            imagedestroy($source);
            throw new RuntimeException('Unable to allocate derivative canvas.');
        }
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $width, $height, $transparent);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        $path = tempnam(sys_get_temp_dir(), 'casaura-media-');
        if ($path === false || ! imagewebp($target, $path, 84)) {
            imagedestroy($source);
            imagedestroy($target);
            throw new RuntimeException('Unable to encode the WebP derivative.');
        }
        imagedestroy($source);
        imagedestroy($target);

        return ['path' => $path, 'width' => $width, 'height' => $height, 'mime_type' => 'image/webp'];
    }

    private function decode(string $path, string $mime): GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };
        if (! $image instanceof GdImage) {
            throw new ListingException('MEDIA_INVALID', 'The uploaded image could not be decoded safely.');
        }

        return $image;
    }
}
