<?php

namespace App\Enums;

/**
 * Lifecycle status shared by categories, attributes, values, detail locations, products,
 * combinations and combos (PRD-013; design Decision 2). The backed value is stored in each `status` column.
 */
enum CatalogStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Inactive => 'Inactivo',
        };
    }
}
