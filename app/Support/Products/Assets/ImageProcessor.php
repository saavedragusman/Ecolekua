<?php

namespace App\Support\Products\Assets;

use GdImage;
use RuntimeException;

/**
 * Turns an uploaded image into the two stored variants (design Decision 18, DT-03). The type is
 * decided by decoding, never by the file name or the declared MIME type; the size and the
 * dimensions are checked before the pixels are decoded; and both variants are new WebP encodings,
 * so EXIF data and any bytes appended to the upload never reach the disk. The original is not kept.
 * Native GD only, no framework dependency.
 */
final class ImageProcessor
{
    public const DISPLAY = 'display';

    public const THUMB = 'thumb';

    /** Long side and WebP quality of each variant. */
    private const VARIANTS = [
        self::DISPLAY => ['side' => 1200, 'quality' => 82],
        self::THUMB => ['side' => 320, 'quality' => 80],
    ];

    private const ACCEPTED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * @return array{display: string, thumb: string} the WebP bytes of each variant
     *
     * @throws InvalidImageException when the upload is not an acceptable image
     */
    public function process(string $bytes): array
    {
        if (strlen($bytes) > AssetLimits::MAX_BYTES) {
            throw new InvalidImageException(InvalidImageException::SIZE, 'The upload is larger than the limit.');
        }

        $header = $bytes === '' ? false : @getimagesizefromstring($bytes);

        if ($header === false || ! in_array($header[2], self::ACCEPTED_TYPES, true)) {
            throw new InvalidImageException(InvalidImageException::FORMAT, 'The upload is not a JPG, PNG or WebP image.');
        }

        if (max($header[0], $header[1]) > AssetLimits::MAX_SIDE || min($header[0], $header[1]) < 1) {
            throw new InvalidImageException(InvalidImageException::DIMENSIONS, 'The image exceeds the size bound.');
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new InvalidImageException(InvalidImageException::FORMAT, 'The upload could not be decoded.');
        }

        $variants = [];

        foreach (self::VARIANTS as $name => $variant) {
            $variants[$name] = $this->encode($source, $variant['side'], $variant['quality']);
        }

        return ['display' => $variants[self::DISPLAY], 'thumb' => $variants[self::THUMB]];
    }

    /**
     * Scales the image down so its long side is at most `$side` (never up), keeping the aspect
     * ratio and the alpha channel, and encodes it as WebP.
     */
    private function encode(GdImage $source, int $side, int $quality): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $side / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $written = imagewebp($target, null, $quality);
        $encoded = (string) ob_get_clean();

        if (! $written || $encoded === '') {
            throw new RuntimeException('GD could not encode the WebP variant.');
        }

        return $encoded;
    }
}
