<?php

namespace App\Enums;

/**
 * Customer lifecycle status (CLI-009; constitution §21.1). The backed value is stored in `customers.status`.
 */
enum CustomerStatus: string
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
