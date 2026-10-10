<?php

namespace App\Support\Products\Assets;

use RuntimeException;

/**
 * An uploaded file that cannot be stored as an image (E-35). `reason` tells the caller which
 * message to show; the exception message is technical and never shown to users.
 */
final class InvalidImageException extends RuntimeException
{
    /** Not a JPG, PNG or WebP that decodes. */
    public const FORMAT = 'format';

    /** Larger than `AssetLimits::MAX_BYTES`. */
    public const SIZE = 'size';

    /** Long side above `AssetLimits::MAX_SIDE`. */
    public const DIMENSIONS = 'dimensions';

    /**
     * @param  self::FORMAT|self::SIZE|self::DIMENSIONS  $reason
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
