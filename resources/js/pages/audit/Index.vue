<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppInput from '@/components/AppInput.vue';
import AppPagination from '@/components/AppPagination.vue';
import AppSelect from '@/components/AppSelect.vue';
import type { SelectOption } from '@/components/AppSelect.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index } from '@/routes/audit';
import type {
    AuditActionOption,
    AuditFilters,
    AuditLogPage,
    AuditUserOption,
} from '@/types/audit';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    logs: AuditLogPage;
    filters: AuditFilters;
    users: AuditUserOption[];
    actions: AuditActionOption[];
}>();

const form = useForm({
    user_id: props.filters.user_id ?? '',
    action: props.filters.action ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const userOptions: SelectOption[] = props.users.map((user) => ({
    value: user.id,
    label: user.name,
}));

const actionOptions: SelectOption[] = props.actions.map((action) => ({
    value: action.value,
    label: action.label,
}));

const columns: DataTableColumn[] = [
    { key: 'occurred_at', label: 'Fecha y hora' },
    { key: 'actor', label: 'Usuario' },
    { key: 'action_label', label: 'Acción' },
    { key: 'entity', label: 'Entidad' },
    { key: 'ip_address', label: 'IP de origen' },
    { key: 'changes', label: 'Cambios' },
];

function search(): void {
    form.get(index().url, { preserveScroll: true });
}

function clear(): void {
    router.get(index().url);
}

function printable(value: unknown): string {
    return typeof value === 'string' ? value : JSON.stringify(value);
}

function hasChanges(values: Record<string, unknown>): boolean {
    return Object.keys(values).length > 0;
}
</script>

<template>
    <Head title="Auditoría" />

    <div class="flex flex-col gap-space-md">
        <h1 class="font-headline-md text-headline-md text-primary">
            Auditoría
        </h1>

        <AppCard>
            <form
                class="grid grid-cols-1 gap-space-md md:grid-cols-2 lg:grid-cols-4"
                @submit.prevent="search"
            >
                <AppSelect
                    v-model="form.user_id"
                    label="Usuario"
                    placeholder="Todos"
                    :options="userOptions"
                    :error="form.errors.user_id"
                />
                <AppSelect
                    v-model="form.action"
                    label="Acción"
                    placeholder="Todas"
                    :options="actionOptions"
                    :error="form.errors.action"
                />
                <AppInput
                    v-model="form.from"
                    type="date"
                    label="Desde"
                    :error="form.errors.from"
                />
                <AppInput
                    v-model="form.to"
                    type="date"
                    label="Hasta"
                    :error="form.errors.to"
                />
                <div
                    class="flex flex-col gap-space-sm md:col-span-2 md:flex-row lg:col-span-4"
                >
                    <AppButton type="submit" :disabled="form.processing">
                        Filtrar
                    </AppButton>
                    <AppButton type="button" variant="outlined" @click="clear">
                        Limpiar filtros
                    </AppButton>
                </div>
            </form>
        </AppCard>

        <DataTable
            :columns="columns"
            :rows="logs.data"
            row-key="id"
            empty-text="No hay registros de auditoría para los filtros indicados."
        >
            <template #cell-actor="{ row }">
                {{ row.actor ?? '—' }}
            </template>
            <template #cell-entity="{ row }">
                {{ row.entity ?? '—' }}
            </template>
            <template #cell-ip_address="{ row }">
                {{ row.ip_address ?? '—' }}
            </template>
            <template #cell-changes="{ row }">
                <details
                    v-if="
                        hasChanges(row.old_values) || hasChanges(row.new_values)
                    "
                >
                    <summary
                        class="min-h-11 cursor-pointer py-space-xs font-label-md text-label-md text-primary"
                    >
                        Ver cambios
                    </summary>
                    <div class="flex flex-col gap-space-sm">
                        <div v-if="hasChanges(row.old_values)">
                            <p
                                class="font-label-md text-label-md text-on-surface-variant"
                            >
                                Valores anteriores
                            </p>
                            <ul>
                                <li
                                    v-for="(value, key) in row.old_values"
                                    :key="key"
                                    class="break-words"
                                >
                                    {{ key }}: {{ printable(value) }}
                                </li>
                            </ul>
                        </div>
                        <div v-if="hasChanges(row.new_values)">
                            <p
                                class="font-label-md text-label-md text-on-surface-variant"
                            >
                                Valores nuevos
                            </p>
                            <ul>
                                <li
                                    v-for="(value, key) in row.new_values"
                                    :key="key"
                                    class="break-words"
                                >
                                    {{ key }}: {{ printable(value) }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </details>
                <span v-else>—</span>
            </template>
        </DataTable>

        <AppPagination
            v-if="logs.last_page > 1"
            :current-page="logs.current_page"
            :last-page="logs.last_page"
            :prev-url="logs.prev_page_url"
            :next-url="logs.next_page_url"
        />
    </div>
</template>
