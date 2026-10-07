<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * All values are fictitious (AGENTS.md section 8).
 *
 * @extends Factory<AttributeValue>
 */
class AttributeValueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'catalog_attribute_id' => CatalogAttribute::factory(),
            'name' => 'Valor '.fake()->unique()->bothify('??-###'),
            'description' => null,
            'sort_order' => fake()->unique()->numberBetween(1, 1_000_000),
            'status' => CatalogStatus::Active,
            'tone' => null,
            'svg_layer' => null,
        ];
    }

    /**
     * A value of a color-presentation attribute needs a tone (E-36).
     */
    public function withTone(string $tone = '#7A9A3B'): static
    {
        return $this->state(['tone' => $tone]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => CatalogStatus::Inactive]);
    }
}
