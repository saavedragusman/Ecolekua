<?php

namespace Database\Factories;

use App\Models\CommercialParameters;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The seeded values of the single row (`id = 1`, created by the migration, so `create()` would
 * collide with it): use `make()` or change the existing row.
 *
 * @extends Factory<CommercialParameters>
 */
class CommercialParametersFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => 1,
            'advance_percentage' => '50.00',
            'validity_days' => 7,
            'updated_by' => null,
        ];
    }
}
