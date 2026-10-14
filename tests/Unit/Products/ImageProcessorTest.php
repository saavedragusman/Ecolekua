<?php

use App\Support\Products\Assets\AssetLimits;
use App\Support\Products\Assets\ImageProcessor;
use App\Support\Products\Assets\InvalidImageException;
use Tests\Support\FictitiousImage;

/**
 * @return array{int, int, string} width, height and MIME type of an encoded image
 */
function imageInfo(string $bytes): array
{
    $info = getimagesizefromstring($bytes);

    return [$info[0], $info[1], $info['mime']];
}

function rejectedReason(string $bytes): ?string
{
    try {
        (new ImageProcessor)->process($bytes);
    } catch (InvalidImageException $exception) {
        return $exception->reason;
    }

    return null;
}

it('PRD-020 accepts JPG, PNG and WebP by decoding them and returns the two WebP variants', function (string $bytes) {
    $variants = (new ImageProcessor)->process($bytes);

    expect(array_keys($variants))->toBe(['display', 'thumb'])
        ->and(imageInfo($variants['display']))->toBe([40, 30, 'image/webp'])
        ->and(imageInfo($variants['thumb']))->toBe([40, 30, 'image/webp']);
})->with([
    'jpg' => [fn () => FictitiousImage::jpeg()],
    'png' => [fn () => FictitiousImage::png()],
    'webp' => [fn () => FictitiousImage::webp()],
]);

it('PRD-020 rejects a PDF renamed to .jpg because it does not decode as an image', function () {
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";

    expect(rejectedReason($pdf))->toBe(InvalidImageException::FORMAT);
});

it('PRD-020 rejects a file with a valid image header and a corrupt body', function () {
    $corrupt = substr(FictitiousImage::png(200, 200), 0, 80);

    expect(rejectedReason($corrupt))->toBe(InvalidImageException::FORMAT)
        ->and(rejectedReason(''))->toBe(InvalidImageException::FORMAT);
});

it('PRD-020 rejects a file over 2 MB even when it starts as a valid image', function () {
    $oversize = FictitiousImage::png().str_repeat("\0", AssetLimits::MAX_BYTES);

    expect(strlen($oversize))->toBeGreaterThan(AssetLimits::MAX_BYTES)
        ->and(rejectedReason($oversize))->toBe(InvalidImageException::SIZE);
});

it('PRD-020 rejects a long side over 1920 px and accepts exactly 1920 px', function () {
    expect(rejectedReason(FictitiousImage::png(AssetLimits::MAX_SIDE + 1, 10)))->toBe(InvalidImageException::DIMENSIONS)
        ->and(rejectedReason(FictitiousImage::png(10, AssetLimits::MAX_SIDE + 1)))->toBe(InvalidImageException::DIMENSIONS)
        ->and(rejectedReason(FictitiousImage::png(AssetLimits::MAX_SIDE, 10)))->toBeNull();
});

it('DT-03 rejects an oversize image by its header, before any attempt to decode the pixels', function () {
    // The header declares 30000 x 30000 px and has no pixel data: only a bound checked before
    // decoding yields the DIMENSIONS reason (decoding would fail as FORMAT).
    expect(rejectedReason(FictitiousImage::pngHeaderOnly(30000, 30000)))->toBe(InvalidImageException::DIMENSIONS);
});

it('DT-03 stores a PNG with appended bytes only as the re-encoded WebP', function () {
    $payload = '<?php system($_GET["x"]); ?>';
    $png = FictitiousImage::png().$payload;

    $variants = (new ImageProcessor)->process($png);

    expect($png)->toContain($payload)
        ->and($variants['display'])->not->toContain($payload)
        ->and($variants['thumb'])->not->toContain($payload)
        ->and(substr($variants['display'], 0, 4))->toBe('RIFF')
        ->and(substr($variants['display'], 8, 4))->toBe('WEBP');
});

it('DT-03 drops the EXIF block of a JPEG', function () {
    $jpeg = FictitiousImage::jpegWithExif('GPS-LAT-12.345');

    $variants = (new ImageProcessor)->process($jpeg);

    expect($jpeg)->toContain('GPS-LAT-12.345')
        ->and($variants['display'])->not->toContain('GPS-LAT-12.345')
        ->and($variants['display'])->not->toContain('Exif')
        ->and($variants['thumb'])->not->toContain('GPS-LAT-12.345');
});

/**
 * Names the color of the pixel at (x, y): R, G, B or Y (the four quadrants of the fictitious JPEG).
 */
function quadrantColor(GdImage $image, int $x, int $y): string
{
    $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
    $palette = ['R' => [255, 0, 0], 'G' => [0, 200, 0], 'B' => [0, 0, 255], 'Y' => [255, 255, 0]];
    $best = 'R';
    $bestDistance = PHP_INT_MAX;

    foreach ($palette as $name => [$r, $g, $b]) {
        $distance = ($color['red'] - $r) ** 2 + ($color['green'] - $g) ** 2 + ($color['blue'] - $b) ** 2;

        if ($distance < $bestDistance) {
            [$best, $bestDistance] = [$name, $distance];
        }
    }

    return $best;
}

/**
 * @return array{int, int, string} width, height and the quadrant colors read clockwise from the top-left
 */
function quadrantsOf(string $webp): array
{
    $image = imagecreatefromstring($webp);
    $width = imagesx($image);
    $height = imagesy($image);
    $left = intdiv($width, 4);
    $right = $width - 1 - $left;
    $top = intdiv($height, 4);
    $bottom = $height - 1 - $top;

    return [
        $width,
        $height,
        quadrantColor($image, $left, $top).quadrantColor($image, $right, $top)
            .quadrantColor($image, $right, $bottom).quadrantColor($image, $left, $bottom),
    ];
}

it('DT-03 applies the EXIF orientation before dropping it', function (?int $orientation, int $width, int $height, string $clockwise) {
    $variants = (new ImageProcessor)->process(FictitiousImage::jpegWithOrientation($orientation));

    // Stored quadrants clockwise from the top-left: R G Y B.
    expect(quadrantsOf($variants['display']))->toBe([$width, $height, $clockwise])
        ->and(quadrantsOf($variants['thumb'])[2])->toBe($clockwise)
        ->and($variants['display'])->not->toContain('Exif');
})->with([
    'no EXIF' => [null, 64, 48, 'RGYB'],
    'orientation 1 (normal)' => [1, 64, 48, 'RGYB'],
    'orientation 2 (mirror horizontal)' => [2, 64, 48, 'GRBY'],
    'orientation 3 (rotate 180)' => [3, 64, 48, 'YBRG'],
    'orientation 4 (mirror vertical)' => [4, 64, 48, 'BYGR'],
    'orientation 5 (transpose)' => [5, 48, 64, 'RBYG'],
    'orientation 6 (rotate 90 CW)' => [6, 48, 64, 'BRGY'],
    'orientation 7 (transverse)' => [7, 48, 64, 'YGRB'],
    'orientation 8 (rotate 90 CCW)' => [8, 48, 64, 'GYBR'],
]);

it('DT-03 treats an unknown EXIF orientation value as normal', function () {
    $variants = (new ImageProcessor)->process(FictitiousImage::jpegWithOrientation(9));

    expect(quadrantsOf($variants['display']))->toBe([64, 48, 'RGYB']);
});

it('DT-03 leaves PNG and WebP pixels as they are', function (string $bytes) {
    $variants = (new ImageProcessor)->process($bytes);

    expect(imageInfo($variants['display']))->toBe([40, 30, 'image/webp']);
})->with([
    'png' => [fn () => FictitiousImage::png()],
    'webp' => [fn () => FictitiousImage::webp()],
]);

it('DT-03 keeps the size bound when the orientation swaps the sides', function () {
    $variants = (new ImageProcessor)->process(FictitiousImage::jpegWithOrientation(6, 1600, 800));

    expect(imageInfo($variants['display']))->toBe([600, 1200, 'image/webp'])
        ->and(imageInfo($variants['thumb']))->toBe([160, 320, 'image/webp']);
});

it('Decision 18 scales display to a long side of 1200 px and thumb to 320 px, keeping the aspect ratio', function () {
    $variants = (new ImageProcessor)->process(FictitiousImage::png(1600, 800));

    expect(imageInfo($variants['display']))->toBe([1200, 600, 'image/webp'])
        ->and(imageInfo($variants['thumb']))->toBe([320, 160, 'image/webp']);
});

it('Decision 18 scales by the long side also for a portrait image', function () {
    $variants = (new ImageProcessor)->process(FictitiousImage::png(500, 1500));

    expect(imageInfo($variants['display']))->toBe([400, 1200, 'image/webp'])
        ->and(imageInfo($variants['thumb']))->toBe([107, 320, 'image/webp']);
});

it('Decision 18 never enlarges an image smaller than the variant bound', function () {
    $variants = (new ImageProcessor)->process(FictitiousImage::jpeg(900, 600));

    expect(imageInfo($variants['display']))->toBe([900, 600, 'image/webp'])
        ->and(imageInfo($variants['thumb']))->toBe([320, 213, 'image/webp']);
});

it('Decision 18 preserves the alpha channel in both variants', function () {
    $variants = (new ImageProcessor)->process(FictitiousImage::png(40, 30, transparentCorner: true));

    foreach (['display', 'thumb'] as $name) {
        $decoded = imagecreatefromstring($variants[$name]);
        $corner = imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0));
        $center = imagecolorsforindex($decoded, imagecolorat($decoded, 20, 15));

        expect($corner['alpha'])->toBeGreaterThan(100)
            ->and($center['alpha'])->toBe(0);
    }
});
