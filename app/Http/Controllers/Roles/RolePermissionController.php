<?php

namespace App\Http\Controllers\Roles;

use App\Actions\Roles\SyncRolePermissions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\SyncRolePermissionsRequest;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class RolePermissionController extends Controller
{
    public function update(SyncRolePermissionsRequest $request, Role $role, SyncRolePermissions $syncRolePermissions): RedirectResponse
    {
        $syncRolePermissions->handle($role, $request->validated('permissions'), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'Los permisos del rol fueron actualizados.']);

        return redirect()->route('roles.show', $role);
    }
}
