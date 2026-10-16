<?php

namespace App\Enums;

/**
 * Whether a tier carries a registered price or is settled by negotiation (COT-001). The backed value is
 * stored in `price_tiers.kind`.
 */
enum PriceTierKind: string
{
    case Priced = 'priced';
    case Negotiation = 'negotiation';

    public function label(): string
    {
        return match ($this) {
            self::Priced => 'Con precio',
            self::Negotiation => 'Negociación',
        };
    }
}
