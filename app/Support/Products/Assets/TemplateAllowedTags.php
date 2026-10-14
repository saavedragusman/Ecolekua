<?php

namespace App\Support\Products\Assets;

use enshrined\svgSanitize\data\AllowedTags;
use enshrined\svgSanitize\data\TagInterface;

/**
 * Elements a product template may keep (design Decision 18): the library allowlist minus anything
 * that can run code or embed foreign markup. The library list already lacks them; excluding them
 * here keeps that true if a later release changes its defaults.
 */
final class TemplateAllowedTags implements TagInterface
{
    /** Lowercase, as the library compares them. */
    private const FORBIDDEN = ['script', 'foreignobject'];

    /**
     * @return list<string>
     */
    public static function getTags(): array
    {
        return array_values(array_diff(AllowedTags::getTags(), self::FORBIDDEN));
    }
}
