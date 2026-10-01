<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Render hook for the duplicate-phone warning (design Decision 10). It reads like a validation
 * error on `confirm_duplicate_phone`, the field the user must supply to continue, and carries the
 * matching customers: in the JSON body, or as an Inertia flash for the form to list them. The
 * Action wrote nothing before throwing.
 */
class RenderDuplicatePhoneWarning
{
    public function __invoke(DuplicatePhoneWarning $exception, Request $request): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => $exception->getMessage(),
                'errors' => ['confirm_duplicate_phone' => [$exception->getMessage()]],
                'duplicate_phone_matches' => $exception->matches,
            ], 422);
        }

        Inertia::flash('duplicatePhoneMatches', $exception->matches);

        return back()->withErrors(['confirm_duplicate_phone' => $exception->getMessage()]);
    }
}
