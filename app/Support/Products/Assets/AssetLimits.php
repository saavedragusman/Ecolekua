<?php

namespace App\Support\Products\Assets;

/**
 * Upload bounds for product assets (design Decision 18, confirmed by the owner on 2026-10-07):
 * 2 MB per file and a long side of at most 1920 px. Images above the side bound are rejected
 * before decoding, which bounds the memory a crafted file can make the decoder allocate.
 */
final class AssetLimits
{
    /** Largest accepted upload, in bytes (2 MB). */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Largest accepted long side of an uploaded image, in pixels. */
    public const MAX_SIDE = 1920;

    /** The same bound in kilobytes, as the `max` validation rule expects it. */
    public const MAX_KILOBYTES = self::MAX_BYTES / 1024;

    private function __construct() {}
}
