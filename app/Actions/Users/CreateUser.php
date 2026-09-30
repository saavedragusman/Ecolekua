<?php

namespace App\Actions\Users;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates a user (FND-010): always active, with a temporary password that must be changed at
 * first login (FND-014), and with at least one role (FND-018). The actor is nullable and the
 * origin optional so the first-administrator console command can reuse it (design Decision 17).
 */
class CreateUser
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  first_name, last_name, email, password and roles (list of role ids)
     */
    public function handle(array $data, ?User $actor, ?AuditOrigin $origin = null): User
    {
        return DB::transaction(function () use ($data, $actor, $origin): User {
            $roles = Role::query()->whereIn('id', Arr::wrap($data['roles'] ?? []))->orderBy('id')->get();

            if ($roles->isEmpty()) {
                throw new BusinessRuleViolation('Un usuario debe tener al menos un rol.');
            }

            // The model's `hashed` cast hashes the password; the plain text is never stored.
            $user = User::query()->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $user->roles()->attach($roles->modelKeys());

            $this->audit->handle(
                AuditAction::UserCreated,
                $actor,
                $user,
                newValues: [
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'is_active' => $user->is_active,
                    'roles' => $roles->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name])->all(),
                ],
                origin: $origin,
            );

            return $user;
        });
    }
}
