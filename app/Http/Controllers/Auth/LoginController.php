<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AttemptLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Login');
    }

    public function store(LoginRequest $request, AttemptLogin $attemptLogin): RedirectResponse
    {
        $result = $attemptLogin->handle($request->validated('email'), $request->validated('password'));

        if (! $result->isSuccess()) {
            // Thrown after the action's transaction committed, so the audit rows persist.
            throw ValidationException::withMessages(['email' => $result->message()]);
        }

        return redirect()->intended(route('home'));
    }
}
