<?php

namespace App\Enums;

/**
 * Business line of a product (DEC-PRD-26). The backed value is stored in `products.business_line`.
 */
enum BusinessLine: string
{
    case Uniforms = 'uniforms';
    case Diapers = 'diapers';

    public function label(): string
    {
        return match ($this) {
            self::Uniforms => 'Uniformes',
            self::Diapers => 'Pañales',
        };
    }
}
