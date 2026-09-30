<?php

namespace App\Exceptions;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Render hook for every authorization denial (FND-019, design Decision 10): audits
 * `authorization.denied` with the actor and the route, then answers 403 (Inertia page or JSON).
 * Laravel converts AuthorizationException into AccessDeniedHttpException before render hooks run.
 */
class RenderAccessDenied
{
    public const MESSAGE = 'No tiene permiso para realizar esta operación.';

    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function __invoke(AccessDeniedHttpException $exception, Request $request): Response
    {
        $this->audit->handle(AuditAction::AuthorizationDenied, $request->user(), context: [
            'route' => $request->route()?->getName(),
            'method' => $request->method(),
            'parameters' => $this->routeParameterIds($request),
        ]);

        if ($request->expectsJson()) {
            return new JsonResponse(['message' => self::MESSAGE], 403);
        }

        return Inertia::render('errors/Forbidden')->toResponse($request)->setStatusCode(403);
    }

    /**
     * Route parameters reduced to scalar ids: bound models contribute their key, never their attributes.
     *
     * @return array<string, mixed>
     */
    private function routeParameterIds(Request $request): array
    {
        $ids = [];

        foreach ($request->route()?->parameters() ?? [] as $name => $value) {
            $ids[$name] = $value instanceof Model ? $value->getKey() : $value;
        }

        return $ids;
    }
}
