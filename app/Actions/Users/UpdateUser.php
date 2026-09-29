<?php

namespace App\Actions\Users;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Modifies a user's first name, last name and email (FND-010). There is no self-action guard:
 * an administrator may edit their own data (DEC-020). The audit row carries only the fields
 * that actually changed (E-27); when nothing changed nothing is written.
 */
class UpdateUser
{
    private const IDENTITY_FIELDS = ['first_name', 'last_name', 'email'];

    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data, ?User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor): User {
            // Fill through the model so the email mutator normalizes before the comparison.
            $user->fill(Arr::only($data, self::IDENTITY_FIELDS));

            $changed = array_keys($user->getDirty());

            if ($changed === []) {
                return $user;
            }

            $old = Arr::only($user->getOriginal(), $changed);
            $new = Arr::only($user->getAttributes(), $changed);

            $user->save();

            $this->audit->handle(AuditAction::UserUpdated, $actor, $user, oldValues: $old, newValues: $new);

            return $user;
        });
    }
}
