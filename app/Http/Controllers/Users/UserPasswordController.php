<?php

namespace App\Http\Controllers\Users;

use App\Actions\Users\ResetUserPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class UserPasswordController extends Controller
{
    public function update(ResetPasswordRequest $request, User $user, ResetUserPassword $resetUserPassword): RedirectResponse
    {
        $actor = $request->user();

        $resetUserPassword->handle($user, $request->validated('password'), $actor);

        // A self-reset (DEC-020) deleted the actor's own sessions: end this one explicitly.
        if ($actor->is($user)) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            Inertia::flash(['type' => 'success', 'message' => 'Su contraseña fue restablecida. Inicie sesión con la contraseña temporal.']);

            return redirect()->route('login');
        }

        Inertia::flash(['type' => 'success', 'message' => 'La contraseña fue restablecida. El usuario deberá cambiarla al iniciar sesión.']);

        return redirect()->route('users.show', $user);
    }
}
