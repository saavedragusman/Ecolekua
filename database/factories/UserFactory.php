<?php

namespace Database\Factories;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }

    /**
     * Gives the user one dedicated role holding exactly the given permissions. Permissions
     * missing from the catalog are created, so tests can also use ad-hoc names.
     */
    public function withPermissions(PermissionName|string ...$permissions): static
    {
        return $this->afterCreating(function (User $user) use ($permissions) {
            $role = Role::factory()->create();

            $ids = collect($permissions)
                ->map(fn (PermissionName|string $permission) => $permission instanceof PermissionName ? $permission->value : $permission)
                ->map(fn (string $name) => Permission::query()->firstOrCreate(
                    ['name' => $name],
                    ['description' => $name],
                )->id)
                ->all();

            $role->permissions()->attach($ids);
            $user->roles()->attach($role);
        });
    }
}
