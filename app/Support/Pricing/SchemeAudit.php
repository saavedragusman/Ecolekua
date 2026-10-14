<?php

namespace App\Support\Pricing;

use App\Models\PriceScheme;

/**
 * Snapshots of a price scheme for the audit trail (design "Audit payloads"): the tiers as the form
 * sends them, in quantity order, without internal ids.
 */
final class SchemeAudit
{
    /**
     * @return list<array{label: string, min: int, max: ?int, kind: string}>
     */
    public static function tiers(PriceScheme $scheme): array
    {
        $rows = [];

        foreach ($scheme->tiers as $tier) {
            $rows[] = [
                'label' => $tier->label,
                'min' => $tier->min_quantity,
                'max' => $tier->max_quantity,
                'kind' => $tier->kind->value,
            ];
        }

        return $rows;
    }
}
