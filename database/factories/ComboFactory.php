<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\Combo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active, portal-visible combo without components or code: tests attach them with
 * `ComboComponent::factory()` and `CatalogCode::factory()->forCombo()`. All values are fictitious
 * (AGENTS.md section 8).
 *
 * @extends Factory<Combo>
 */
class ComboFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Combo '.fake()->unique()->bothify('??-###'),
            'portal_visible' => true,
            'status' => CatalogStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => CatalogStatus::Inactive]);
    }
}
