<?php

namespace App\Enums;

/**
 * Kind of a quotation line: a catalog product or a combo (COT-008). The backed value is stored in
 * `quotation_lines.kind`.
 */
enum LineKind: string
{
    case Product = 'product';
    case Combo = 'combo';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Producto',
            self::Combo => 'Combo',
        };
    }
}
