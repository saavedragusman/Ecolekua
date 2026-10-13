<?php

namespace App\Support\Products\Selection;

/**
 * An SVG template of the product (PRD-020, Decision 18). Resolution does not read it; the options
 * of Phase 15 use it for the preview. The loader returns none until the templates table exists.
 */
final readonly class TemplateSnapshot
{
    /**
     * @param  list<string>  $layers
     */
    public function __construct(
        public int $id,
        public ?int $variantValueId,
        public array $layers,
    ) {}
}
