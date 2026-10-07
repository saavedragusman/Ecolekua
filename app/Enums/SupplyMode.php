<?php

namespace App\Enums;

/**
 * How a product is supplied (DEC-PRD-09). The backed value is stored in `products.supply_mode`.
 */
enum SupplyMode: string
{
    case OnDemand = 'on_demand';
    case StockWithMinimum = 'stock_with_minimum';
    case StockDepletable = 'stock_depletable';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::OnDemand => 'Bajo pedido',
            self::StockWithMinimum => 'Stock con mínimo',
            self::StockDepletable => 'Stock agotable',
            self::Service => 'Servicio',
        };
    }
}
