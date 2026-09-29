<?php

namespace App\Http\Controllers\Audit;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\AuditLogIndexRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditRedactor;
use App\Support\Time\OperatingTime;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    /**
     * FND-025: filters by user, action and date range. Days are calendar days in the operating
     * timezone, queried as a half-open UTC interval (DEC-019, design Decision 16).
     */
    public function index(AuditLogIndexRequest $request): Response
    {
        $filters = $request->validated();
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $logs = AuditLog::query()
            ->with('actor:id,first_name,last_name')
            ->when($filters['user_id'] ?? null, fn ($query, $userId) => $query->where('actor_id', $userId))
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($from, fn ($query, $day) => $query->where('created_at', '>=', OperatingTime::dayStartUtc($day)->format('Y-m-d H:i:s.u')))
            ->when($to, fn ($query, $day) => $query->where('created_at', '<', OperatingTime::nextDayStartUtc($day)->format('Y-m-d H:i:s.u')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->through(fn (AuditLog $log): array => $this->summary($log))
            ->withQueryString();

        return Inertia::render('audit/Index', [
            'logs' => $logs,
            'filters' => [
                'user_id' => $filters['user_id'] ?? null,
                'action' => $filters['action'] ?? null,
                'from' => $from,
                'to' => $to,
            ],
            'users' => User::query()
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->orderBy('id')
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->first_name.' '.$user->last_name])
                ->all(),
            'actions' => array_map(
                fn (AuditAction $action): array => ['value' => $action->value, 'label' => $action->label()],
                AuditAction::cases(),
            ),
        ]);
    }

    /**
     * Read model of one audit row. `occurred_at` is already formatted in the operating timezone
     * and the payloads pass through the redactor again, as defense in depth (FND-023).
     *
     * @return array<string, mixed>
     */
    private function summary(AuditLog $log): array
    {
        $action = AuditAction::tryFrom($log->action);

        return [
            'id' => $log->id,
            'occurred_at' => OperatingTime::format($log->created_at),
            'actor' => $log->actor !== null
                ? $log->actor->first_name.' '.$log->actor->last_name
                : $log->actor_email,
            'action' => $log->action,
            'action_label' => $action?->label() ?? $log->action,
            'entity' => $log->entity_type !== null
                ? class_basename($log->entity_type).($log->entity_id !== null ? ' #'.$log->entity_id : '')
                : null,
            'ip_address' => $log->ip_address,
            'old_values' => $this->payload($log->old_values),
            'new_values' => $this->payload($log->new_values),
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function payload(mixed $values): array
    {
        return is_array($values) ? AuditRedactor::redact($values) : [];
    }
}
