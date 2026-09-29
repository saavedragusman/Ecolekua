<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Rol de prueba '.fake()->unique()->numerify('####'),
            'description' => fake()->sentence(),
            'is_protected' => false,
        ];
    }

    public function protected(): static
    {
        return $this->state(fn () => ['is_protected' => true]);
    }
}
