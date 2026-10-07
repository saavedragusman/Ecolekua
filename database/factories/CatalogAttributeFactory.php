<?php

namespace Database\Factories;

use App\Enums\AttributePresentation;
use App\Enums\AttributeSpecialUse;
use App\Enums\CatalogStatus;
use App\Models\CatalogAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * All values are fictitious (AGENTS.md section 8). The named states set the presentation and the
 * special use together; each special use and the color presentation exist once in the catalog
 * (DEC-PRD-38, DEC-PRD-49), so a test creates each state at most once.
 *
 * @extends Factory<CatalogAttribute>
 */
class CatalogAttributeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Atributo '.fake()->unique()->bothify('??-###'),
            'presentation' => AttributePresentation::Text,
            'special_use' => null,
            'sort_order' => fake()->unique()->numberBetween(1, 1_000_000),
            'status' => CatalogStatus::Active,
        ];
    }

    public function fabric(): static
    {
        return $this->state(['name' => 'Tela', 'special_use' => AttributeSpecialUse::Fabric]);
    }

    public function color(): static
    {
        return $this->state(['name' => 'Color', 'presentation' => AttributePresentation::Color]);
    }

    public function size(): static
    {
        return $this->state(['name' => 'Talla', 'special_use' => AttributeSpecialUse::Size]);
    }

    public function gender(): static
    {
        return $this->state(['name' => 'Género', 'special_use' => AttributeSpecialUse::Gender]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => CatalogStatus::Inactive]);
    }
}
