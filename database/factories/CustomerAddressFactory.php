<?php

namespace Database\Factories;

use App\Enums\VenezuelanState;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAddress>
 */
class CustomerAddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'line' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->randomElement(VenezuelanState::cases()),
            'reference' => fake()->sentence(4),
        ];
    }
}
