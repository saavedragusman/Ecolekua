<?php

namespace Tests\Support;

use GdImage;

/**
 * Generates fictitious images for the upload tests (PRD-020). Nothing here comes from a real
 * product, customer or photograph: flat colors drawn with GD.
 */
final class FictitiousImage
{
    /**
     * @return GdImage a truecolor canvas filled with a flat color; `$transparentCorner` clears its top-left pixel
     */
    private static function canvas(int $width, int $height, bool $transparentCorner = false): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 30, 120, 200, 0));

        if ($transparentCorner) {
            imagesetpixel($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        }

        return $image;
    }

    public static function jpeg(int $width = 40, int $height = 30): string
    {
        $image = self::canvas($width, $height);

        return self::capture(fn () => imagejpeg($image, null, 90));
    }

    public static function png(int $width = 40, int $height = 30, bool $transparentCorner = false): string
    {
        $image = self::canvas($width, $height, $transparentCorner);

        return self::capture(fn () => imagepng($image));
    }

    public static function webp(int $width = 40, int $height = 30): string
    {
        $image = self::canvas($width, $height);

        return self::capture(fn () => imagewebp($image, null, 80));
    }

    /**
     * A JPEG that carries an EXIF block (APP1) with a recognizable marker string.
     */
    public static function jpegWithExif(string $marker): string
    {
        $jpeg = self::jpeg();
        $payload = "Exif\0\0".$marker;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    /**
     * A JPEG of four flat quadrants in the stored orientation (top-left red, top-right green,
     * bottom-left blue, bottom-right yellow) that carries an EXIF Orientation tag. Passing null
     * omits the EXIF block entirely.
     */
    public static function jpegWithOrientation(?int $orientation, int $width = 64, int $height = 48): string
    {
        $image = imagecreatetruecolor($width, $height);
        $halfWidth = intdiv($width, 2);
        $halfHeight = intdiv($height, 2);

        imagefilledrectangle($image, 0, 0, $halfWidth - 1, $halfHeight - 1, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, $halfWidth, 0, $width - 1, $halfHeight - 1, (int) imagecolorallocate($image, 0, 200, 0));
        imagefilledrectangle($image, 0, $halfHeight, $halfWidth - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 255));
        imagefilledrectangle($image, $halfWidth, $halfHeight, $width - 1, $height - 1, (int) imagecolorallocate($image, 255, 255, 0));

        $jpeg = self::capture(fn () => imagejpeg($image, null, 95));

        if ($orientation === null) {
            return $jpeg;
        }

        // Little-endian TIFF header, one IFD entry: tag 0x0112 (Orientation), type SHORT, count 1.
        $tiff = "II*\0".pack('V', 8).pack('v', 1).pack('vvVv', 0x0112, 3, 1, $orientation)."\0\0".pack('V', 0);
        $payload = "Exif\0\0".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    /**
     * A PNG header (signature plus IHDR chunk) that declares the given size and carries no pixel
     * data: it cannot be decoded, so only a check done before decoding can reject it by its size.
     */
    public static function pngHeaderOnly(int $width, int $height): string
    {
        $ihdr = pack('N2', $width, $height)."\x08\x06\x00\x00\x00";

        return "\x89PNG\r\n\x1A\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    }

    private static function capture(callable $write): string
    {
        ob_start();
        $write();

        return (string) ob_get_clean();
    }
}
