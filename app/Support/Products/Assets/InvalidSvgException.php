<?php

namespace App\Support\Products\Assets;

use RuntimeException;

/**
 * An uploaded file that cannot be stored as an SVG template (E-39). `reason` tells the caller which
 * message to show; the exception message is technical and never shown to users.
 */
final class InvalidSvgException extends RuntimeException
{
    /** Not UTF-8, not well-formed XML, or the root element is not `svg`. */
    public const FORMAT = 'format';

    /** Larger than `AssetLimits::MAX_BYTES`. */
    public const SIZE = 'size';

    /** Declares a DOCTYPE or an entity (XXE and entity expansion are rejected, not cleaned). */
    public const DOCTYPE = 'doctype';

    /**
     * @param  self::FORMAT|self::SIZE|self::DOCTYPE  $reason
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
