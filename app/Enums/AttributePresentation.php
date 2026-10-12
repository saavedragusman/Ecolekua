<?php

namespace App\Enums;

/**
 * How the values of a catalog attribute are shown (DEC-PRD-30). At most one attribute uses
 * `color` (DEC-PRD-38). The backed value is stored in `catalog_attributes.presentation`.
 */
enum AttributePresentation: string
{
    case Text = 'text';
    case Image = 'image';
    case Color = 'color';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texto',
            self::Image => 'Imagen',
            self::Color => 'Color',
        };
    }
}
