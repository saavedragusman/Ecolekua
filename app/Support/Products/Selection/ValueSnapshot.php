<?php

namespace App\Support\Products\Selection;

/**
 * A catalog value as the selection engine sees it (design Decision 14). Image URLs are not part of
 * it yet: the assets arrive with Phase 21 and the options with Phase 15.
 */
final readonly class ValueSnapshot
{
    public function __construct(
        public int $id,
        public int $attributeId,
        public string $name,
        public ?string $description,
        public int $sortOrder,
        public bool $active,
        public ?string $tone,
        public ?string $layer,
    ) {}
}
