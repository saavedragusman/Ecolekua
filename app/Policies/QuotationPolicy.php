<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

/**
 * Permission-only abilities for quotations (COT-022, spec §4, design Decision 6), discovered for the
 * `Quotation` model. Abilities answer only the permission: the rules about the state of a quotation
 * (draft only, issued only) are business rules of the Actions, so a quotation in the wrong state is a
 * rejection, never a 403. Role names are never consulted and there is no superadmin.
 *
 * Abilities are called with a quotation or with the model class (lists, creation, the live calculation),
 * so they take an untyped subject that they ignore.
 */
class QuotationPolicy
{
    public function viewAny(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::QuotationsView);
    }

    public function view(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::QuotationsView);
    }

    public function print(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::QuotationsView);
    }

    public function create(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::QuotationsCreate);
    }

    public function update(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::QuotationsCreate);
    }

    public function issue(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::QuotationsCreate);
    }

    public function resend(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::QuotationsCreate);
    }

    public function duplicate(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::QuotationsCreate);
    }

    public function discard(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::QuotationsCreate);
    }

    /**
     * The live calculation and the selection options of the editor (COT-022).
     */
    public function calculate(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::QuotationsCreate);
    }

    public function negotiate(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::QuotationsNegotiate);
    }

    public function cancel(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::QuotationsCancel);
    }
}
