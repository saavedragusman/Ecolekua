<?php

namespace App\Support\Quotations\Calculation;

use App\Enums\BusinessLine;
use App\Enums\LineKind;
use App\Enums\PriceSchemeType;

/**
 * Counts the pieces that choose the tier of each line (COT-008, design Decision 15).
 *
 * Goods: the valid rows of product lines whose product is not a service and whose scheme is tiered, summed
 * by business line even across different products and schemes. Fixed-price lines, lines without a scheme
 * and combo lines do not count (DEC-COT-07). Services sold alone do not add to their business line: each
 * service counts apart, keyed by product id, so its tier is chosen by the total of that same service
 * (DEC-COT-14).
 */
final class PieceCounter
{
    /**
     * @param  list<LineFacts>  $lines
     * @return array{lines: array<string, int>, services: array<int, int>}
     */
    public static function count(array $lines): array
    {
        $byLine = [];

        foreach (BusinessLine::cases() as $businessLine) {
            $byLine[$businessLine->value] = 0;
        }

        $services = [];

        foreach ($lines as $line) {
            if ($line->kind !== LineKind::Product || $line->productId === null) {
                continue;
            }

            if ($line->isService) {
                $services[$line->productId] = ($services[$line->productId] ?? 0) + $line->validQuantity();

                continue;
            }

            if ($line->schemeType === PriceSchemeType::Tiered && $line->businessLine !== null) {
                $byLine[$line->businessLine->value] += $line->validQuantity();
            }
        }

        return ['lines' => $byLine, 'services' => $services];
    }
}
