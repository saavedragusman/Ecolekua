<?php

namespace Database\Factories;

use App\Enums\AttributeRole;
use App\Models\CatalogAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An attribute declared by a product. The factory does not enforce the role rules of DEC-PRD-50
 * (those belong to `SyncProductAttributes`); tests choose the role explicitly.
 *
 * @extends Factory<ProductAttribute>
 */
class ProductAttributeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'catalog_attribute_id' => CatalogAttribute::factory(),
            'role' => AttributeRole::Order,
            'sort_order' => fake()->unique()->numberBetween(1, 1_000_000),
        ];
    }

    public function axis(): static
    {
        return $this->state(['role' => AttributeRole::Axis]);
    }

    public function order(): static
    {
        return $this->state(['role' => AttributeRole::Order]);
    }
}
