<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class SyncUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assignRoles', $this->route('user'));
    }

    /**
     * `roles` must be present but may be empty: whether an empty list is acceptable depends on
     * the user's status and is decided by SyncUserRoles (FND-018).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array'],
            'roles.*' => ['integer', 'distinct', 'exists:roles,id'],
        ];
    }
}
