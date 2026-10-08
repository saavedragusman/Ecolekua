<?php

namespace App\Support\Products\Selection;

/**
 * A detail location the product admits (PRD-007). The loader only keeps the active ones.
 */
final readonly class LocationSnapshot
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $layer,
    ) {}
}
