<?php

namespace App\Enums;

/**
 * Special use of a catalog attribute (DEC-PRD-32, DEC-PRD-49). At most one attribute holds each
 * use; a `null` column value means the attribute has no special use. The backed value is stored
 * in `catalog_attributes.special_use`.
 */
enum AttributeSpecialUse: string
{
    case Fabric = 'fabric';
    case Size = 'size';
    case Gender = 'gender';

    public function label(): string
    {
        return match ($this) {
            self::Fabric => 'Tela',
            self::Size => 'Talla',
            self::Gender => 'Género',
        };
    }
}
