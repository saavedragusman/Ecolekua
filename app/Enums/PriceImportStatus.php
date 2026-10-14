<?php

namespace App\Enums;

/**
 * Lifecycle status of a bulk price update (COT-027..COT-029). The backed value is stored in
 * `price_imports.status`.
 */
enum PriceImportStatus: string
{
    case Validated = 'validated';
    case Applied = 'applied';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Validated => 'Validada',
            self::Applied => 'Aplicada',
            self::Discarded => 'Descartada',
        };
    }

    /**
     * Category of the `StatusBadge` component. A validated update still waits to be applied or discarded.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Validated => 'pending',
            self::Applied => 'done',
            self::Discarded => 'neutral',
        };
    }
}
