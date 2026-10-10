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
