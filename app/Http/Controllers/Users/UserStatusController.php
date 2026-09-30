<?php

namespace App\Http\Controllers\Users;

use App\Actions\Users\ActivateUser;
use App\Actions\Users\DeactivateUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class UserStatusController extends Controller
{
    public function activate(Request $request, User $user, ActivateUser $activateUser): RedirectResponse
    {
        Gate::authorize('deactivate', $user);

        $activateUser->handle($user, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El usuario fue activado.']);

        return redirect()->route('users.show', $user);
    }

    public function deactivate(Request $request, User $user, DeactivateUser $deactivateUser): RedirectResponse
    {
        Gate::authorize('deactivate', $user);

        $deactivateUser->handle($user, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El usuario fue desactivado y sus sesiones abiertas se cerraron.']);

        return redirect()->route('users.show', $user);
    }
}
