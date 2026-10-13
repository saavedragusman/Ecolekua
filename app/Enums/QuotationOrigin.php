<?php

namespace App\Enums;

/**
 * Channel that created a quotation (spec 004 §5). The backed value is stored in `quotations.origin`;
 * `Web` is written by spec `005`.
 */
enum QuotationOrigin: string
{
    case Advisor = 'advisor';
    case Web = 'web';

    public function label(): string
    {
        return match ($this) {
            self::Advisor => 'Asesora',
            self::Web => 'Web',
        };
    }
}
