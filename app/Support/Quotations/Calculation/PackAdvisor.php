<?php

namespace App\Support\Quotations\Calculation;

use App\Support\Pricing\Money;
use Brick\Math\BigDecimal;

/**
 * Chooses the pack notice of a product line (COT-009 step 11, design Decision 15).
 *
 * The candidates are single-component active combos already admitted for the line (DEC-PRD-42). A pack
 * applies when the line's quantity reaches the pack quantity and the pack's item price is below the line's
 * unit price. Only one notice is returned: the greatest estimated saving, and on a tie the pack with more
 * units (DEC-COT-47), and when the units also tie the lower combo code in alphabetical order (DEC-COT-61),
 * so the notice never depends on the order the candidates were loaded in. A line with more than one distinct unit price never gets a notice (DEC-COT-54).
 */
final class PackAdvisor
{
    /**
     * @param  list<array{code: string, name: string, quantity: int, item_price: BigDecimal}>  $packs
     */
    public static function best(PackLineFacts $line, array $packs): ?PackNotice
    {
        $unitPrice = $line->singleUnitPrice();

        if ($unitPrice === null) {
            return null;
        }

        $best = null;

        foreach ($packs as $pack) {
            if ($pack['quantity'] < 1 || $line->quantity < $pack['quantity'] || ! $pack['item_price']->isLessThan($unitPrice)) {
                continue;
            }

            $packsFit = intdiv($line->quantity, $pack['quantity']);
            $saving = Money::round(
                $unitPrice->minus($pack['item_price'])->multipliedBy($packsFit * $pack['quantity']),
            );

            $notice = new PackNotice($pack['code'], $pack['name'], $pack['quantity'], $packsFit, $saving);

            if ($best === null || self::beats($notice, $best)) {
                $best = $notice;
            }
        }

        return $best;
    }

    private static function beats(PackNotice $candidate, PackNotice $current): bool
    {
        $comparison = $candidate->estimatedSaving->compareTo($current->estimatedSaving);

        if ($comparison !== 0) {
            return $comparison > 0;
        }

        if ($candidate->packQuantity !== $current->packQuantity) {
            return $candidate->packQuantity > $current->packQuantity;
        }

        return strcmp($candidate->comboCode, $current->comboCode) < 0;
    }
}
