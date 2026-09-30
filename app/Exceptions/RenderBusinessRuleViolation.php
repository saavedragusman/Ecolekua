<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Render hook for domain rejections (design Decision 3): back to the previous page with an error
 * flash, or 422 for JSON. The Action already rolled its transaction back, so nothing was written.
 */
class RenderBusinessRuleViolation
{
    public function __invoke(BusinessRuleViolation $exception, Request $request): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $exception->getMessage()], 422);
        }

        Inertia::flash(['type' => 'error', 'message' => $exception->getMessage()]);

        return back();
    }
}
