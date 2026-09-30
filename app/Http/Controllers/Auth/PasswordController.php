<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ChangeOwnPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('auth/ChangePassword');
    }

    public function update(UpdatePasswordRequest $request, ChangeOwnPassword $changeOwnPassword): RedirectResponse
    {
        $changeOwnPassword->handle($request->user(), $request->validated('password'));

        Inertia::flash(['type' => 'success', 'message' => 'Su contraseña fue actualizada.']);

        return redirect()->route('home');
    }
}
