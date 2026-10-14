<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\AttributeValue;
use App\Models\Combination;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active combination with a random axis signature and no code or axis values: tests attach them
 * with `withAxes()` and `CatalogCode::factory()`. All values are fictitious (AGENTS.md section 8).
 *
 * @extends Factory<Combination>
 */
class CombinationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'description' => null,
            'status' => CatalogStatus::Active,
            'axis_signature' => hash('sha256', fake()->unique()->uuid()),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => CatalogStatus::Inactive]);
    }

    /**
     * Stores the given values as the axis values of the combination and derives the axis signature
     * from them (sorted `attributeId:valueId` pairs, as the Actions of Phase 10 do).
     *
     * @param  list<AttributeValue>  $values
     */
    public function withAxes(array $values): static
    {
        $pairs = array_map(fn (AttributeValue $value): string => $value->catalog_attribute_id.':'.$value->id, $values);
        sort($pairs);

        return $this->state(['axis_signature' => hash('sha256', implode(',', $pairs))])
            ->afterCreating(function (Combination $combination) use ($values): void {
                foreach ($values as $value) {
                    $combination->values()->attach($value->id, ['catalog_attribute_id' => $value->catalog_attribute_id]);
                }
            });
    }
}
