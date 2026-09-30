<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppPagination from '@/components/AppPagination.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import SegmentedTabs from '@/components/SegmentedTabs.vue';
import type { SegmentedTab } from '@/components/SegmentedTabs.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { create, index, show } from '@/routes/users';
import type {
    Paginated,
    UserStatusCounts,
    UserStatusFilter,
    UserSummary,
} from '@/types/users';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    users: Paginated<UserSummary>;
    status: UserStatusFilter;
    counts: UserStatusCounts;
}>();

const { can } = usePermissions();

const tabs = computed<SegmentedTab[]>(() => [
    {
        key: 'active',
        label: 'Activos',
        count: props.counts.active,
        href: index().url,
    },
    {
        key: 'inactive',
        label: 'Inactivos',
        count: props.counts.inactive,
        href: index({ query: { status: 'inactive' } }).url,
    },
    {
        key: 'all',
        label: 'Todos',
        count: props.counts.all,
        href: index({ query: { status: 'all' } }).url,
    },
]);

const EMPTY_TEXT: Record<UserStatusFilter, string> = {
    active: 'No hay usuarios activos.',
    inactive: 'No hay usuarios inactivos.',
    all: 'No hay usuarios registrados.',
};

const columns: DataTableColumn[] = [
    { key: 'name', label: 'Nombre' },
    { key: 'email', label: 'Correo electrónico' },
    { key: 'roles', label: 'Roles' },
    { key: 'status', label: 'Estado' },
    { key: 'actions', label: 'Acciones' },
];
</script>

<template>
    <Head title="Usuarios" />

    <div class="flex flex-col gap-space-md">
        <div
            class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
        >
            <h1 class="font-headline-md text-headline-md text-primary">
                Usuarios
            </h1>
            <AppButton v-if="can('users.create')" :href="create().url">
                Nuevo usuario
            </AppButton>
        </div>

        <SegmentedTabs
            :tabs="tabs"
            :current="status"
            label="Filtrar usuarios por estado"
        />

        <DataTable
            :columns="columns"
            :rows="users.data"
            row-key="id"
            :empty-text="EMPTY_TEXT[status]"
        >
            <template #cell-name="{ row }">
                {{ row.first_name }} {{ row.last_name }}
            </template>
            <template #cell-roles="{ row }">
                {{
                    row.roles.map((role) => role.name).join(', ') || 'Sin roles'
                }}
            </template>
            <template #cell-status="{ row }">
                <StatusBadge :is-active="row.is_active" />
            </template>
            <template #cell-actions="{ row }">
                <AppButton variant="outlined" :href="show(row.id).url">
                    Ver detalle
                </AppButton>
            </template>
        </DataTable>

        <AppPagination
            v-if="users.last_page > 1"
            :current-page="users.current_page"
            :last-page="users.last_page"
            :prev-url="users.prev_page_url"
            :next-url="users.next_page_url"
        />
    </div>
</template>
