<?php

namespace App\Support\Products\Assets;

use Illuminate\Database\Eloquent\Model;

/**
 * URLs of the stored image variants of an owner, for the pages. The raw storage paths are never
 * exposed. The file name of each variant (a random ULID, new on every upload) is appended as the
 * `v` query parameter so a replaced image is not served from the browser cache for up to a day.
 * The URLs are relative so they work from any host the ERP is reached on.
 */
final class ImageUrls
{
    private function __construct() {}

    /**
     * @param  string  $route  name of the serving route (`products.image.show`, ...)
     * @param  string  $parameter  name of the route parameter that holds the owner id
     * @return array{display: string, thumb: string}|null null when the owner has no image
     */
    public static function for(Model $owner, string $route, string $parameter): ?array
    {
        $display = $owner->getAttribute('image_display_path');
        $thumb = $owner->getAttribute('image_thumb_path');

        if (! is_string($display) || ! is_string($thumb)) {
            return null;
        }

        $url = fn (string $variant, string $path): string => route($route, [
            $parameter => $owner->getKey(),
            'variant' => $variant,
            'v' => pathinfo($path, PATHINFO_FILENAME),
        ], absolute: false);

        return [
            ImageProcessor::DISPLAY => $url(ImageProcessor::DISPLAY, $display),
            ImageProcessor::THUMB => $url(ImageProcessor::THUMB, $thumb),
        ];
    }
}
