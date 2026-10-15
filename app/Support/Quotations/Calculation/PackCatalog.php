<?php

namespace App\Support\Quotations\Calculation;

use App\Enums\CatalogStatus;
use App\Support\Products\Selection\CatalogSnapshotLoader;
use App\Support\Products\Selection\ComboSelectionRules;
use App\Support\Products\Selection\ComboSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * The candidate packs of a calculation (DEC-COT-08, design Decision 16): the active combos with a single
 * component whose product is one of the products of the lines, kept only when the 003 rule still offers them.
 * One query finds them; each candidate is then read through the memoized loader, whose product snapshots are
 * already primed, so a pack adds one query and the lines and rows add none.
 */
final class PackCatalog
{
    /**
     * @param  list<int>  $lineProductIds
     * @return list<ComboSnapshot>
     */
    public static function load(array $lineProductIds, CatalogSnapshotLoader $memoized): array
    {
        if ($lineProductIds === []) {
            return [];
        }

        $single = DB::table('combo_components')->select('combo_id')->groupBy('combo_id')->havingRaw('COUNT(*) = 1');

        $comboIds = DB::table('combo_components')
            ->join('combos', 'combos.id', '=', 'combo_components.combo_id')
            ->where('combos.status', CatalogStatus::Active->value)
            ->whereIn('combo_components.product_id', array_values(array_unique($lineProductIds)))
            ->whereIn('combo_components.combo_id', $single)
            ->orderBy('combo_components.combo_id')
            ->pluck('combo_components.combo_id')
            ->all();

        $packs = [];

        foreach ($comboIds as $comboId) {
            $combo = $memoized->forCombo((int) $comboId);

            if ($combo !== null && ComboSelectionRules::isOffered($combo)) {
                $packs[] = $combo;
            }
        }

        return $packs;
    }
}
