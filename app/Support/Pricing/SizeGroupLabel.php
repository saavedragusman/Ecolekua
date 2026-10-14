<?php

namespace App\Support\Pricing;

use InvalidArgumentException;

/**
 * The label of a size group, derived from its sizes and never stored (COT-004, design Decision 10).
 * Sizes are taken in the order of the palette (the sizes the combination admits, in size-value order):
 * neighbours in the palette form a run written "28–36", a lone size is written on its own, and runs are
 * separated by ", " ("28–32, 36"). The label is what the screens, the audits and the price CSV show.
 */
final class SizeGroupLabel
{
    /** Joins the first and the last size of a run (an en dash, as the CSV `grupo_talla` writes it). */
    public const RANGE_SEPARATOR = '–';

    public const RUN_SEPARATOR = ', ';

    /**
     * @param  list<int>  $sizeIds  sizes of the group, in any order; a repeated id counts once
     * @param  array<int, string>  $palette  size value id => name, in size-value order
     *
     * @throws InvalidArgumentException when the group is empty or holds a size that is not in the palette
     */
    public static function of(array $sizeIds, array $palette): string
    {
        $members = array_flip($sizeIds);

        if ($members === []) {
            throw new InvalidArgumentException('A size group has at least one size.');
        }

        $unknown = array_diff_key($members, $palette);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Size '.array_key_first($unknown).' is not in the palette.');
        }

        $runs = [];
        $current = [];

        foreach ($palette as $id => $name) {
            if (isset($members[$id])) {
                $current[] = $name;

                continue;
            }

            if ($current !== []) {
                $runs[] = self::run($current);
                $current = [];
            }
        }

        if ($current !== []) {
            $runs[] = self::run($current);
        }

        return implode(self::RUN_SEPARATOR, $runs);
    }

    /**
     * @param  non-empty-list<string>  $names  consecutive sizes of one run
     */
    private static function run(array $names): string
    {
        $first = $names[0];
        $last = $names[array_key_last($names)];

        return $first === $last ? $first : $first.self::RANGE_SEPARATOR.$last;
    }
}
