<?php

namespace App\Http\Controllers\Users;

use App\Actions\Users\SyncUserRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\SyncUserRolesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class UserRoleController extends Controller
{
    public function update(SyncUserRolesRequest $request, User $user, SyncUserRoles $syncUserRoles): RedirectResponse
    {
        $syncUserRoles->handle($user, $request->validated('roles'), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'Los roles del usuario fueron actualizados.']);

        return redirect()->route('users.show', $user);
    }
}
