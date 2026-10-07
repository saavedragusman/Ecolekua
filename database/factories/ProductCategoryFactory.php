<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * All values are fictitious (AGENTS.md section 8).
 *
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Categoría '.fake()->unique()->bothify('??-###'),
            'sort_order' => fake()->unique()->numberBetween(1, 1_000_000),
            'status' => CatalogStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => CatalogStatus::Inactive]);
    }
}
