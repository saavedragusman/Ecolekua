<?php

namespace App\Support\Products\Selection;

use App\Enums\SupplyMode;

/**
 * Everything the selection engine needs to know about one product, loaded in a constant number of
 * queries by `CatalogSnapshotLoader` (design Decision 14, DT-02). It carries availability flags as
 * data and only the active combinations; `SelectionRules` decides over it without touching the
 * database. `attributes` follow the display order of the product.
 */
final readonly class ProductSnapshot
{
    /**
     * @param  list<AttributeSnapshot>  $attributes
     * @param  array<int, ValueSnapshot>  $values  by value id: admitted values and the ones combinations and fabrics use
     * @param  array<int, list<int>>  $fabricColors  fabric value id => active color value ids it offers
     * @param  list<CombinationSnapshot>  $combinations  active only
     * @param  list<LocationSnapshot>  $detailLocations  admitted and active
     * @param  list<ServiceSnapshot>  $customizations  admitted and active
     * @param  list<TemplateSnapshot>  $templates
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
        public bool $categoryActive,
        public SupplyMode $supplyMode,
        public bool $admitsCustomColor,
        public array $attributes,
        public array $values,
        public array $fabricColors,
        public array $combinations,
        public array $detailLocations,
        public array $customizations,
        public array $templates,
    ) {}
}
