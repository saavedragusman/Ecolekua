<?php

namespace App\Enums;

/**
 * How a price scheme prices its products (spec 004 §5, DEC-COT-51): by quantity tiers or with a single
 * fixed price. The backed value is stored in `price_schemes.type` and is set when the scheme is created.
 */
enum PriceSchemeType: string
{
    case Tiered = 'tiered';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Tiered => 'Por tramos',
            self::Fixed => 'Precio fijo',
        };
    }
}
