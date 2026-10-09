<?php

namespace App\Support\Products\Selection;

use App\Enums\AttributePresentation;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;

/**
 * An attribute declared by a product, with its role and the value ids the product admits for it
 * (PRD-004). The color of a product that declares the fabric has no admitted values (DEC-PRD-35).
 */
final readonly class AttributeSnapshot
{
    /**
     * @param  list<int>  $allowed  value ids admitted by the product, any status
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
        public AttributeRole $role,
        public int $sortOrder,
        public AttributePresentation $presentation,
        public ?AttributeSpecialUse $specialUse,
        public array $allowed,
    ) {}

    public function isColor(): bool
    {
        return $this->presentation === AttributePresentation::Color;
    }

    public function isFabric(): bool
    {
        return $this->specialUse === AttributeSpecialUse::Fabric;
    }
}
