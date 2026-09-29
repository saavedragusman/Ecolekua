<?php

namespace App\Actions\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Audit\AuditRedactor;
use Illuminate\Database\Eloquent\Model;

/**
 * Single way to record an audit event (design Decision 9). It never opens a transaction:
 * the caller's transaction makes the change and its audit row atomic.
 */
class RecordAuditEvent
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $context
     */
    public function handle(
        AuditAction $action,
        ?User $actor,
        ?Model $entity = null,
        array $oldValues = [],
        array $newValues = [],
        array $context = [],
        ?string $actorEmail = null,
        ?AuditOrigin $origin = null,
    ): AuditLog {
        $origin ??= AuditOrigin::fromRequest(request());

        return AuditLog::query()->create([
            'created_at' => now()->utc(),
            'actor_id' => $actor?->getKey(),
            'actor_email' => $actorEmail ?? $actor?->email,
            'action' => $action->value,
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity?->getKey(),
            'ip_address' => $origin->ipAddress(),
            'old_values' => AuditRedactor::redact($oldValues),
            'new_values' => AuditRedactor::redact($newValues),
            'context' => AuditRedactor::redact([...$context, ...$origin->context()]),
        ]);
    }
}
