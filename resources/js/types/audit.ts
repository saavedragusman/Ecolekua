import type { Paginated } from '@/types/users';

// Shapes sent by `App\Http\Controllers\Audit\AuditLogController` (read-only DTOs).
export type AuditLogEntry = {
    id: number;
    // Already formatted by the backend in the operating timezone (`d/m/Y H:i:s`).
    occurred_at: string;
    actor: string | null;
    action: string;
    action_label: string;
    entity: string | null;
    ip_address: string | null;
    old_values: Record<string, unknown>;
    new_values: Record<string, unknown>;
};

export type AuditLogPage = Paginated<AuditLogEntry>;

export type AuditFilters = {
    user_id: string | null;
    action: string | null;
    // `Y-m-d` calendar days in the operating timezone.
    from: string | null;
    to: string | null;
};

export type AuditUserOption = {
    id: number;
    name: string;
};

export type AuditActionOption = {
    value: string;
    label: string;
};
