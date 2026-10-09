<?php

namespace App\Support\Products\Selection;

/**
 * A combo with its components in display order (PRD-010), loaded by `CatalogSnapshotLoader` in a
 * constant number of queries. Only a combo with a registry code is loaded (as for combinations,
 * DEC-PRD-82).
 */
final readonly class ComboSnapshot
{
    /**
     * @param  list<ComponentSnapshot>  $components
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $code,
        public bool $active,
        public array $components,
    ) {}
}
