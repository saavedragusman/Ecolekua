<?php

namespace App\Actions\Users;

use App\Exceptions\BusinessRuleViolation;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the first administrator (DEC-021, design Decision 17). It runs only while no active user
 * holds the protected role (FND-020), locks that role row so two concurrent runs cannot both pass
 * the precondition, and delegates to CreateUser so there is a single creation path (FND-014, FND-022).
 * The protected role is identified by `is_protected`, never by its name.
 */
class CreateFirstAdministrator
{
    public function __construct(private readonly CreateUser $createUser) {}

    /**
     * @return array{user: User, password: string} The temporary password is returned once and never persisted in plain text.
     *
     * @throws BusinessRuleViolation
     */
    public function handle(string $firstName, string $lastName, string $email, AuditOrigin $origin): array
    {
        return DB::transaction(function () use ($firstName, $lastName, $email, $origin): array {
            $role = Role::query()->where('is_protected', true)->lockForUpdate()->first();

            if ($role === null) {
                throw new BusinessRuleViolation('No existe el rol protegido de administrador. Ejecute primero FoundationSeeder (sail artisan db:seed --class=FoundationSeeder).');
            }

            $hasActiveAdministrator = User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn ($query) => $query->where('roles.is_protected', true))
                ->exists();

            if ($hasActiveAdministrator) {
                throw new BusinessRuleViolation('Ya existe un administrador activo. Este comando solo crea el primer administrador.');
            }

            $password = Str::random(20);

            $user = $this->createUser->handle([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'password' => $password,
                'roles' => [$role->id],
            ], actor: null, origin: $origin);

            return ['user' => $user, 'password' => $password];
        });
    }
}
