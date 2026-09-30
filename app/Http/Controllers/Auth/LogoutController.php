<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\Logout;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    public function __invoke(Request $request, Logout $logout): RedirectResponse
    {
        $logout->handle($request);

        return redirect()->route('login');
    }
}
