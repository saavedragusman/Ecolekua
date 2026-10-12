<?php

namespace App\Enums;

/**
 * Role of an attribute inside a product (DEC-PRD-03): an axis defines the commercial combinations,
 * an order attribute is chosen when ordering. The backed value is stored in `product_attributes.role`.
 */
enum AttributeRole: string
{
    case Axis = 'axis';
    case Order = 'order';

    public function label(): string
    {
        return match ($this) {
            self::Axis => 'Eje',
            self::Order => 'De pedido',
        };
    }
}
