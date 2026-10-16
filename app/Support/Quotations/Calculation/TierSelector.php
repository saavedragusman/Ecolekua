<?php

namespace App\Support\Quotations\Calculation;

/**
 * Chooses the tier of a scheme for a counted quantity (COT-008, design Decision 15): the tier whose
 * inclusive limits contain it. A fixed scheme's single tier contains every quantity from 1. No tier is
 * invented when none contains the quantity.
 */
final class TierSelector
{
    /**
     * @param  list<TierFacts>  $tiers
     */
    public static function for(array $tiers, int $quantity): ?TierFacts
    {
        foreach ($tiers as $tier) {
            if ($tier->contains($quantity)) {
                return $tier;
            }
        }

        return null;
    }
}
