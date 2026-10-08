<?php

namespace Database\Factories;

use App\Enums\BusinessLine;
use App\Enums\CatalogStatus;
use App\Enums\SupplyMode;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * All values are fictitious (AGENTS.md section 8). The default is an active `on_demand` uniform
 * product; the mode states keep the supply mode rules valid (DEC-PRD-46, DEC-PRD-34): a minimum only
 * in `stock_with_minimum`, custom color only in `on_demand`.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Producto '.fake()->unique()->bothify('??-###'),
            'description' => null,
            'product_category_id' => ProductCategory::factory(),
            'business_line' => BusinessLine::Uniforms,
            'supply_mode' => SupplyMode::OnDemand,
            'min_stock_default' => null,
            'allows_custom_color' => true,
            'portal_visible' => true,
            'status' => CatalogStatus::Active,
        ];
    }

    public function onDemand(): static
    {
        return $this->state([
            'supply_mode' => SupplyMode::OnDemand,
            'min_stock_default' => null,
            'allows_custom_color' => true,
        ]);
    }

    public function stockWithMinimum(int $minimum = 6): static
    {
        return $this->state([
            'supply_mode' => SupplyMode::StockWithMinimum,
            'min_stock_default' => $minimum,
            'allows_custom_color' => false,
        ]);
    }

    public function stockDepletable(): static
    {
        return $this->state([
            'supply_mode' => SupplyMode::StockDepletable,
            'min_stock_default' => null,
            'allows_custom_color' => false,
        ]);
    }

    public function service(): static
    {
        return $this->state([
            'supply_mode' => SupplyMode::Service,
            'min_stock_default' => null,
            'allows_custom_color' => false,
        ]);
    }

    public function diapers(): static
    {
        return $this->state(['business_line' => BusinessLine::Diapers]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => CatalogStatus::Inactive]);
    }
}
