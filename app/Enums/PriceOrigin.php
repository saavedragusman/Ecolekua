<?php

namespace App\Enums;

/**
 * Where the unit price of a quotation line came from (spec 004 §5). The backed value is stored in
 * `quotation_lines.price_origin`.
 */
enum PriceOrigin: string
{
    case Tier = 'tier';
    case Fixed = 'fixed';
    case Combo = 'combo';
    case Negotiated = 'negotiated';

    public function label(): string
    {
        return match ($this) {
            self::Tier => 'Tramo',
            self::Fixed => 'Fijo',
            self::Combo => 'Combo',
            self::Negotiated => 'Negociado',
        };
    }
}
