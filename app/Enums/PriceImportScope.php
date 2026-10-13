<?php

namespace App\Enums;

/**
 * What a bulk CSV price update changes (COT-026..COT-029). The backed value is stored in
 * `price_imports.scope`.
 */
enum PriceImportScope: string
{
    case SchemePrices = 'scheme_prices';
    case ComboItemPrices = 'combo_item_prices';

    public function label(): string
    {
        return match ($this) {
            self::SchemePrices => 'Precios de combinaciones de un esquema',
            self::ComboItemPrices => 'Precios de ítems de combos',
        };
    }
}
