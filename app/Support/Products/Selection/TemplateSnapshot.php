<?php

namespace App\Support\Products\Selection;

/**
 * An SVG template of the product (PRD-020, Decision 18): the general one (`variantValueId` null) or
 * the one of a gender-use value (DEC-PRD-49). Resolution does not read it; the options use it for
 * the preview. `version` is the stored file name, used to bust the browser cache on replacement.
 */
final readonly class TemplateSnapshot
{
    /**
     * @param  list<string>  $layers
     */
    public function __construct(
        public int $id,
        public int $productId,
        public ?int $variantValueId,
        public array $layers,
        public string $version,
    ) {}
}
