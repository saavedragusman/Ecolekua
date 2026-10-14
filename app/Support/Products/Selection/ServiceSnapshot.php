<?php

namespace App\Support\Products\Selection;

/**
 * A product of mode `service` offered or included as a customization (PRD-008).
 */
final readonly class ServiceSnapshot
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
    ) {}
}
